<?php
// Front controller / router for the tvtrkr API.
// Routes are matched by method + regex against the path under /api/.

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/http.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/tmdb.php';

// ---- Path resolution -------------------------------------------------

$uri = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
// Strip a leading /api so routes below can be written relative to it,
// regardless of whether the server exposes this script at /api/... or /api/index.php/...
$path = preg_replace('#^/api#', '', $uri);
$path = '/' . ltrim($path, '/');
$method = $_SERVER['REQUEST_METHOD'];

// ---- Route table -------------------------------------------------
// Each entry: [method, regex, handler]

$routes = [];

function route(array &$routes, string $method, string $pattern, callable $handler): void
{
    $routes[] = [$method, $pattern, $handler];
}

// -- Auth --

route($routes, 'GET', '#^/auth/google/login$#', function () {
    if (GOOGLE_CLIENT_ID === '' || GOOGLE_CLIENT_SECRET === '') {
        respond_error('Google login is not configured yet (missing client id/secret in config.local.php).', 500);
    }
    start_session();
    $state = bin2hex(random_bytes(16));
    $_SESSION['oauth_state'] = $state;
    header('Location: ' . google_login_url($state));
    exit;
});

route($routes, 'GET', '#^/auth/google/callback$#', function () {
    start_session();

    $state = $_GET['state'] ?? '';
    $expectedState = $_SESSION['oauth_state'] ?? null;
    unset($_SESSION['oauth_state']);

    if ($expectedState === null || !hash_equals($expectedState, $state)) {
        render_auth_page('Login failed', "Your login attempt expired or wasn't recognized. Please try again.");
    }

    $code = $_GET['code'] ?? '';
    if ($code === '') {
        render_auth_page('Login failed', $_GET['error'] ?? 'Google did not return an authorization code.');
    }

    try {
        $tokens = google_exchange_code($code);
        $profile = google_fetch_userinfo($tokens['access_token']);
    } catch (RuntimeException $e) {
        render_auth_page('Login failed', $e->getMessage());
    }

    $email = strtolower(trim($profile['email'] ?? ''));
    $sub = $profile['sub'] ?? '';

    if ($email === '' || $sub === '') {
        render_auth_page('Login failed', 'Google did not return a usable account profile.');
    }

    $user = find_or_create_user($sub, $email, $profile['name'] ?? null, $profile['picture'] ?? null);

    if (!$user) {
        render_auth_page(
            'Not authorized',
            "$email isn't on the tvtrkr allowlist yet. Ask an admin to add you, then try signing in again."
        );
    }

    session_regenerate_id(true);
    $_SESSION['user_id'] = $user['id'];

    header('Location: ' . APP_BASE_URL . '/');
    exit;
});

route($routes, 'POST', '#^/auth/logout$#', function () {
    start_session();
    $_SESSION = [];
    session_destroy();
    respond(['ok' => true]);
});

route($routes, 'GET', '#^/auth/me$#', function () {
    $user = current_user();
    if (!$user) {
        respond_error('Not authenticated', 401);
    }
    respond($user);
});

// -- Admin: manage the allowed-email list --

route($routes, 'GET', '#^/admin/users$#', function () {
    require_admin();
    $rows = db()->query('
        SELECT
            allowed_emails.email,
            allowed_emails.added_at,
            users.name,
            users.picture_url,
            users.is_admin,
            (users.id IS NOT NULL) AS has_signed_in
        FROM allowed_emails
        LEFT JOIN users ON users.email = allowed_emails.email
        ORDER BY allowed_emails.added_at ASC
    ')->fetchAll(PDO::FETCH_ASSOC);

    foreach ($rows as &$row) {
        $row['is_admin'] = (bool) $row['is_admin'];
        $row['has_signed_in'] = (bool) $row['has_signed_in'];
    }

    respond($rows);
});

route($routes, 'POST', '#^/admin/users$#', function () {
    $admin = require_admin();
    $email = strtolower(trim(json_body()['email'] ?? ''));

    if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        respond_error('A valid email is required');
    }

    $stmt = db()->prepare('INSERT OR IGNORE INTO allowed_emails (email, added_by) VALUES (?, ?)');
    $stmt->execute([$email, $admin['id']]);
    respond(['ok' => true], 201);
});

route($routes, 'DELETE', '#^/admin/users/([^/]+)$#', function ($encodedEmail) {
    $admin = require_admin();
    $email = strtolower(trim(rawurldecode($encodedEmail)));

    if ($email === $admin['email']) {
        respond_error("You can't remove your own access.");
    }

    db()->prepare('DELETE FROM allowed_emails WHERE email = ?')->execute([$email]);
    respond(['ok' => true]);
});

// -- TMDB read-through proxy routes --

route($routes, 'GET', '#^/tmdb/search$#', function () {
    require_login();
    $q = $_GET['query'] ?? '';
    if ($q === '') {
        respond_error('query is required');
    }
    respond(tmdb_get('/search/tv', ['query' => $q, 'page' => $_GET['page'] ?? '1']));
});

route($routes, 'GET', '#^/tmdb/discover$#', function () {
    require_login();
    respond(tmdb_get('/discover/tv', [
        'sort_by' => 'popularity.desc',
        'page' => $_GET['page'] ?? '1',
    ]));
});

route($routes, 'GET', '#^/tmdb/show/(\d+)$#', function ($id) {
    require_login();
    respond(tmdb_get("/tv/$id", [
        'append_to_response' => 'credits,external_ids',
    ]));
});

route($routes, 'GET', '#^/tmdb/show/(\d+)/season/(\d+)$#', function ($id, $season) {
    require_login();
    respond(tmdb_get("/tv/$id/season/$season"));
});

// -- Watchlist (local library) --

route($routes, 'GET', '#^/shows$#', function () {
    $user = require_login();
    $pdo = db();

    $stmt = $pdo->prepare('SELECT * FROM shows WHERE user_id = ? ORDER BY added_at DESC');
    $stmt->execute([$user['id']]);
    $shows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $countStmt = $pdo->prepare('
        SELECT show_id, COUNT(*) AS watched_count
        FROM watched_episodes
        WHERE user_id = ?
        GROUP BY show_id
    ');
    $countStmt->execute([$user['id']]);
    $counts = $countStmt->fetchAll(PDO::FETCH_KEY_PAIR);

    foreach ($shows as &$show) {
        unset($show['user_id']);
        $show['watched_count'] = (int) ($counts[$show['tmdb_id']] ?? 0);
    }

    respond($shows);
});

route($routes, 'POST', '#^/shows$#', function () {
    $user = require_login();
    $body = json_body();
    $tmdbId = (int) ($body['tmdb_id'] ?? 0);
    $name = trim($body['name'] ?? '');

    if ($tmdbId <= 0 || $name === '') {
        respond_error('tmdb_id and name are required');
    }

    $stmt = db()->prepare('
        INSERT INTO shows (user_id, tmdb_id, name, poster_path, backdrop_path, overview, first_air_date, status)
        VALUES (:user_id, :tmdb_id, :name, :poster_path, :backdrop_path, :overview, :first_air_date, :status)
        ON CONFLICT(user_id, tmdb_id) DO NOTHING
    ');
    $stmt->execute([
        'user_id' => $user['id'],
        'tmdb_id' => $tmdbId,
        'name' => $name,
        'poster_path' => $body['poster_path'] ?? null,
        'backdrop_path' => $body['backdrop_path'] ?? null,
        'overview' => $body['overview'] ?? null,
        'first_air_date' => $body['first_air_date'] ?? null,
        'status' => $body['status'] ?? null,
    ]);

    respond(['ok' => true], 201);
});

route($routes, 'DELETE', '#^/shows/(\d+)$#', function ($id) {
    $user = require_login();
    db()->prepare('DELETE FROM shows WHERE user_id = ? AND tmdb_id = ?')->execute([$user['id'], $id]);
    respond(['ok' => true]);
});

// -- Watched episodes --

route($routes, 'GET', '#^/shows/(\d+)/watched$#', function ($id) {
    $user = require_login();
    $stmt = db()->prepare('SELECT season_number, episode_number FROM watched_episodes WHERE user_id = ? AND show_id = ?');
    $stmt->execute([$user['id'], $id]);
    respond($stmt->fetchAll(PDO::FETCH_ASSOC));
});

route($routes, 'POST', '#^/shows/(\d+)/watched$#', function ($id) {
    $user = require_login();
    $body = json_body();
    $season = (int) ($body['season'] ?? -1);
    $episode = (int) ($body['episode'] ?? -1);

    if ($season < 0 || $episode < 0) {
        respond_error('season and episode are required');
    }

    ensure_show_exists($user['id'], $id);

    $stmt = db()->prepare('
        INSERT INTO watched_episodes (user_id, show_id, season_number, episode_number)
        VALUES (?, ?, ?, ?)
        ON CONFLICT(user_id, show_id, season_number, episode_number) DO NOTHING
    ');
    $stmt->execute([$user['id'], $id, $season, $episode]);
    respond(['ok' => true], 201);
});

route($routes, 'DELETE', '#^/shows/(\d+)/watched$#', function ($id) {
    $user = require_login();
    $season = $_GET['season'] ?? null;
    $episode = $_GET['episode'] ?? null;

    if ($season === null || $episode === null) {
        respond_error('season and episode query params are required');
    }

    db()->prepare('
        DELETE FROM watched_episodes WHERE user_id = ? AND show_id = ? AND season_number = ? AND episode_number = ?
    ')->execute([$user['id'], $id, $season, $episode]);
    respond(['ok' => true]);
});

route($routes, 'POST', '#^/shows/(\d+)/watched/season/(\d+)$#', function ($id, $season) {
    // Bulk-mark a season watched. Episode count comes from the client
    // (it already has the season payload from /tmdb/show/{id}/season/{n}).
    $user = require_login();
    $body = json_body();
    $episodeCount = (int) ($body['episode_count'] ?? 0);

    if ($episodeCount <= 0) {
        respond_error('episode_count is required');
    }

    ensure_show_exists($user['id'], $id);

    $pdo = db();
    $stmt = $pdo->prepare('
        INSERT INTO watched_episodes (user_id, show_id, season_number, episode_number)
        VALUES (?, ?, ?, ?)
        ON CONFLICT(user_id, show_id, season_number, episode_number) DO NOTHING
    ');
    $pdo->beginTransaction();
    for ($ep = 1; $ep <= $episodeCount; $ep++) {
        $stmt->execute([$user['id'], $id, $season, $ep]);
    }
    $pdo->commit();

    respond(['ok' => true], 201);
});

route($routes, 'DELETE', '#^/shows/(\d+)/watched/season/(\d+)$#', function ($id, $season) {
    $user = require_login();
    db()->prepare('DELETE FROM watched_episodes WHERE user_id = ? AND show_id = ? AND season_number = ?')
        ->execute([$user['id'], $id, $season]);
    respond(['ok' => true]);
});

// -- Calendar: recently aired + upcoming episodes for tracked shows --

route($routes, 'GET', '#^/calendar$#', function () {
    $user = require_login();
    $pdo = db();
    $stmt = $pdo->prepare('SELECT tmdb_id, name, poster_path FROM shows WHERE user_id = ?');
    $stmt->execute([$user['id']]);
    $shows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $watchedStmt = $pdo->prepare('SELECT show_id, season_number, episode_number FROM watched_episodes WHERE user_id = ?');
    $watchedStmt->execute([$user['id']]);
    $watched = [];
    foreach ($watchedStmt->fetchAll(PDO::FETCH_ASSOC) as $w) {
        $watched[$w['show_id'] . ':' . $w['season_number'] . '-' . $w['episode_number']] = true;
    }

    $today = date('Y-m-d');
    $recentCutoff = date('Y-m-d', strtotime('-14 days'));

    $episodes = [];
    foreach ($shows as $show) {
        try {
            $details = tmdb_get('/tv/' . $show['tmdb_id']);
        } catch (RuntimeException $e) {
            continue; // skip shows TMDB can't currently resolve
        }

        foreach (['last_episode_to_air', 'next_episode_to_air'] as $key) {
            $ep = $details[$key] ?? null;
            if (!$ep || empty($ep['air_date'])) {
                continue;
            }
            // Only surface recently-aired episodes within the cutoff window,
            // not a show's entire watch history.
            if ($key === 'last_episode_to_air' && $ep['air_date'] < $recentCutoff) {
                continue;
            }

            $episodes[] = [
                'show_id' => $show['tmdb_id'],
                'show_name' => $show['name'],
                'poster_path' => $show['poster_path'],
                'air_date' => $ep['air_date'],
                'season_number' => $ep['season_number'],
                'episode_number' => $ep['episode_number'],
                'episode_name' => $ep['name'],
                'aired' => $ep['air_date'] <= $today,
                'watched' => isset($watched[$show['tmdb_id'] . ':' . $ep['season_number'] . '-' . $ep['episode_number']]),
            ];
        }
    }

    usort($episodes, fn($a, $b) => strcmp($a['air_date'], $b['air_date']));

    respond($episodes);
});

function ensure_show_exists(int $userId, $tmdbId): void
{
    // Guard against orphaned watched rows if a show was never added to the library.
    $stmt = db()->prepare('SELECT 1 FROM shows WHERE user_id = ? AND tmdb_id = ?');
    $stmt->execute([$userId, $tmdbId]);
    if (!$stmt->fetch()) {
        respond_error('Show is not in your library yet', 404);
    }
}

// Minimal standalone HTML page for the OAuth callback's browser-facing
// error states (this endpoint is a top-level navigation, not a fetch call).
function render_auth_page(string $title, string $message): void
{
    http_response_code(200);
    header('Content-Type: text/html; charset=utf-8');
    $safeTitle = htmlspecialchars($title, ENT_QUOTES);
    $safeMessage = htmlspecialchars($message, ENT_QUOTES);
    echo <<<HTML
    <!doctype html>
    <html lang="en">
    <head>
      <meta charset="utf-8">
      <title>{$safeTitle} · tvtrkr</title>
      <style>
        body { background:#0b0f19; color:#e6e9f0; font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,sans-serif; display:flex; align-items:center; justify-content:center; min-height:100vh; margin:0; padding:24px; }
        .box { max-width:400px; text-align:center; }
        h1 { color:#f87171; font-size:1.25rem; }
        p { color:#8b93a7; line-height:1.5; }
        a { color:#22d3ee; text-decoration:none; font-weight:600; }
      </style>
    </head>
    <body>
      <div class="box">
        <h1>{$safeTitle}</h1>
        <p>{$safeMessage}</p>
        <a href="/">Back to tvtrkr</a>
      </div>
    </body>
    </html>
    HTML;
    exit;
}

// ---- Dispatch -------------------------------------------------

foreach ($routes as [$routeMethod, $pattern, $handler]) {
    if ($routeMethod !== $method) {
        continue;
    }
    if (preg_match($pattern, $path, $matches)) {
        array_shift($matches);
        try {
            $handler(...$matches);
        } catch (RuntimeException $e) {
            respond_error($e->getMessage(), $e->getCode() >= 400 && $e->getCode() < 600 ? $e->getCode() : 502);
        }
        exit;
    }
}

respond_error('Not found', 404);
