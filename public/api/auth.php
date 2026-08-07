<?php
// Session handling + Google OAuth (authorization code flow, no external deps).

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/http.php';

define('GOOGLE_AUTH_URL', 'https://accounts.google.com/o/oauth2/v2/auth');
define('GOOGLE_TOKEN_URL', 'https://oauth2.googleapis.com/token');
define('GOOGLE_USERINFO_URL', 'https://www.googleapis.com/oauth2/v3/userinfo');

function start_session(): void
{
    if (session_status() === PHP_SESSION_ACTIVE) {
        return;
    }

    $isHttps = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https';

    session_set_cookie_params([
        'lifetime' => 0,
        'path' => '/',
        'secure' => $isHttps,
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    session_name('tvtrkr_session');
    session_start();
}

function current_user(): ?array
{
    start_session();

    if (empty($_SESSION['user_id'])) {
        return null;
    }

    static $cached = null;
    if ($cached !== null) {
        return $cached;
    }

    $stmt = db()->prepare('SELECT id, email, name, picture_url, is_admin FROM users WHERE id = ?');
    $stmt->execute([$_SESSION['user_id']]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$user) {
        // Session points at a user that no longer exists.
        $_SESSION = [];
        session_destroy();
        return null;
    }

    $user['is_admin'] = (bool) $user['is_admin'];
    $cached = $user;
    return $cached;
}

function require_login(): array
{
    $user = current_user();
    if (!$user) {
        respond_error('Not authenticated', 401);
    }
    return $user;
}

function require_admin(): array
{
    $user = require_login();
    if (!$user['is_admin']) {
        respond_error('Admin access required', 403);
    }
    return $user;
}

function google_redirect_uri(): string
{
    return APP_BASE_URL . '/api/auth/google/callback';
}

function google_login_url(string $state): string
{
    $params = [
        'client_id' => GOOGLE_CLIENT_ID,
        'redirect_uri' => google_redirect_uri(),
        'response_type' => 'code',
        'scope' => 'openid email profile',
        'state' => $state,
        'access_type' => 'online',
        'prompt' => 'select_account',
    ];
    return GOOGLE_AUTH_URL . '?' . http_build_query($params);
}

function google_exchange_code(string $code): array
{
    return google_post(GOOGLE_TOKEN_URL, [
        'code' => $code,
        'client_id' => GOOGLE_CLIENT_ID,
        'client_secret' => GOOGLE_CLIENT_SECRET,
        'redirect_uri' => google_redirect_uri(),
        'grant_type' => 'authorization_code',
    ]);
}

function google_fetch_userinfo(string $accessToken): array
{
    $ch = curl_init(GOOGLE_USERINFO_URL);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . $accessToken],
        CURLOPT_TIMEOUT => 10,
    ]);
    $body = curl_exec($ch);
    $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $errno = curl_errno($ch);
    curl_close($ch);

    if ($errno !== 0 || $status < 200 || $status >= 300) {
        throw new RuntimeException('Failed to fetch Google user info', 502);
    }
    return json_decode($body, true) ?? [];
}

function google_post(string $url, array $fields): array
{
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => http_build_query($fields),
        CURLOPT_TIMEOUT => 10,
    ]);
    $body = curl_exec($ch);
    $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $errno = curl_errno($ch);
    $error = curl_error($ch);
    curl_close($ch);

    if ($errno !== 0) {
        throw new RuntimeException("Google request failed: $error", 502);
    }

    $decoded = json_decode($body, true) ?? [];
    if ($status < 200 || $status >= 300) {
        throw new RuntimeException($decoded['error_description'] ?? 'Google request failed', 502);
    }
    return $decoded;
}

// Finds or creates the local user row for a verified Google identity,
// gated by the allowed_emails table. Returns null if the email isn't allowed.
function find_or_create_user(string $googleSub, string $email, ?string $name, ?string $picture): ?array
{
    $email = strtolower(trim($email));
    $pdo = db();

    $allowed = $pdo->prepare('SELECT 1 FROM allowed_emails WHERE email = ?');
    $allowed->execute([$email]);
    $isAllowed = (bool) $allowed->fetch();
    $allowed->closeCursor();
    if (!$isAllowed) {
        return null;
    }

    $isFirstUser = (int) $pdo->query('SELECT COUNT(*) FROM users')->fetchColumn() === 0;
    $isAdmin = $isFirstUser || $email === ADMIN_EMAIL;

    $stmt = $pdo->prepare('SELECT id FROM users WHERE google_sub = ?');
    $stmt->execute([$googleSub]);
    $existing = $stmt->fetch(PDO::FETCH_ASSOC);
    $stmt->closeCursor();

    if ($existing) {
        $update = $pdo->prepare('UPDATE users SET email = ?, name = ?, picture_url = ? WHERE id = ?');
        $update->execute([$email, $name, $picture, $existing['id']]);
        $userId = (int) $existing['id'];
    } else {
        $insert = $pdo->prepare('
            INSERT INTO users (google_sub, email, name, picture_url, is_admin)
            VALUES (?, ?, ?, ?, ?)
        ');
        $insert->execute([$googleSub, $email, $name, $picture, $isAdmin ? 1 : 0]);
        $userId = (int) $pdo->lastInsertId();
        import_legacy_data_for_user($pdo, $userId);
    }

    $stmt = $pdo->prepare('SELECT id, email, name, picture_url, is_admin FROM users WHERE id = ?');
    $stmt->execute([$userId]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);
    $user['is_admin'] = (bool) $user['is_admin'];
    return $user;
}
