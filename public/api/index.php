<?php
// Front controller / router for the tvtrkr API.
// Routes are matched by method + regex against the path under /api/.

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/http.php';
require_once __DIR__ . '/mailer.php';
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

    if ($stmt->rowCount() > 0) {
        send_email(
            $email,
            "You're invited to tvtrkr",
            email_layout(
                '<p style="margin:0 0 4px;font-size:17px;font-weight:600;">You\'re invited to tvtrkr</p>'
                . '<p style="margin:0;color:#555b68;">' . htmlspecialchars($admin['name'] ?? $admin['email']) . ' has invited you to track shows together on tvtrkr.</p>'
                . '<p style="margin:16px 0 0;color:#555b68;">Sign in with Google using this email address to get started:<br><strong>' . htmlspecialchars($email) . '</strong></p>',
                'Sign in to tvtrkr',
                APP_BASE_URL
            )
        );
    }

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

route($routes, 'GET', '#^/admin/mail-usage$#', function () {
    require_admin();
    $current = get_monthly_email_count();
    respond([
        'current' => $current,
        'limit' => MAIL_MONTHLY_LIMIT,
    ]);
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

    $watchWithByShow = watch_with_by_show($pdo, $user['id']);

    foreach ($shows as &$show) {
        unset($show['user_id']);
        $show['watched_count'] = (int) ($counts[$show['tmdb_id']] ?? 0);
        $show['watch_with'] = $watchWithByShow[$show['tmdb_id']] ?? [];
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
    $pdo = db();
    $pdo->beginTransaction();
    // Leave any watch-with group first — otherwise a peer's future watched
    // write would violate the watched_episodes -> shows foreign key once
    // this user's shows row is gone.
    leave_watch_group($pdo, $user['id'], $id);
    $pdo->prepare('DELETE FROM shows WHERE user_id = ? AND tmdb_id = ?')->execute([$user['id'], $id]);
    $pdo->commit();
    respond(['ok' => true]);
});

// The server's own clock (UTC in production) can disagree with the user's
// local calendar day near midnight in either direction, which would mark an
// episode "aired" a day early or late. Prefer the date the client's browser
// reports, falling back to the server clock if it's missing or malformed.
function resolve_today(): string
{
    $today = $_GET['today'] ?? '';
    if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $today)) {
        return $today;
    }
    return date('Y-m-d');
}

// -- What to Watch: next unwatched (already-aired) episode per show --
//
// A show with nothing aired-and-unwatched is only worth surfacing here if
// it has something airing soon; otherwise it's just noise (finished shows,
// shows on long hiatus).
const WHATTOWATCH_SOON_DAYS = 14;

// Builds the per-show watch-status row (available count, next episode,
// upcoming episode) for every show a user tracks, sorted with
// ready-to-watch shows first. Shared by /whattowatch (which filters out
// boring shows below) and the /people profile routes (which don't).
function compute_show_watch_rows(PDO $pdo, int $userId): array
{
    $today = resolve_today();

    $availableStmt = $pdo->prepare('
        SELECT e.show_id, e.season_number, e.episode_number, e.name AS episode_name, e.air_date
        FROM episodes e
        JOIN shows s ON s.tmdb_id = e.show_id AND s.user_id = :user_id
        LEFT JOIN watched_episodes w
            ON w.user_id = :user_id AND w.show_id = e.show_id
            AND w.season_number = e.season_number AND w.episode_number = e.episode_number
        WHERE e.air_date <= :today AND w.show_id IS NULL
        ORDER BY e.show_id, e.season_number, e.episode_number
    ');
    $availableStmt->execute(['user_id' => $userId, 'today' => $today]);

    // First row per show (in season/episode order) is the next one up; the
    // total row count per show is how many aired episodes are available.
    $nextByShow = [];
    $availableCounts = [];
    foreach ($availableStmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $showId = (int) $row['show_id'];
        $availableCounts[$showId] = ($availableCounts[$showId] ?? 0) + 1;
        if (!isset($nextByShow[$showId])) {
            $nextByShow[$showId] = $row;
        }
    }

    $upcomingStmt = $pdo->prepare('
        SELECT e.show_id, e.season_number, e.episode_number, e.name AS episode_name, e.air_date
        FROM episodes e
        JOIN shows s ON s.tmdb_id = e.show_id AND s.user_id = :user_id
        WHERE e.air_date > :today
        ORDER BY e.show_id, e.air_date, e.season_number, e.episode_number
    ');
    $upcomingStmt->execute(['user_id' => $userId, 'today' => $today]);

    // First row per show is the soonest upcoming (not-yet-aired) episode.
    $upcomingByShow = [];
    foreach ($upcomingStmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $showId = (int) $row['show_id'];
        if (!isset($upcomingByShow[$showId])) {
            $upcomingByShow[$showId] = $row;
        }
    }

    $showStmt = $pdo->prepare('SELECT tmdb_id, name, poster_path FROM shows WHERE user_id = ?');
    $showStmt->execute([$userId]);
    $allShows = $showStmt->fetchAll(PDO::FETCH_ASSOC);

    $watchWithByShow = watch_with_by_show($pdo, $userId);

    $result = [];
    foreach ($allShows as $show) {
        $showId = (int) $show['tmdb_id'];
        $next = $nextByShow[$showId] ?? null;
        $upcoming = $upcomingByShow[$showId] ?? null;
        $availableCount = $availableCounts[$showId] ?? 0;

        $result[] = [
            'tmdb_id' => $showId,
            'name' => $show['name'],
            'poster_path' => $show['poster_path'],
            'available_count' => $availableCount,
            'watch_with' => $watchWithByShow[$showId] ?? [],
            'next_episode' => $next ? [
                'season_number' => (int) $next['season_number'],
                'episode_number' => (int) $next['episode_number'],
                'name' => $next['episode_name'],
                'air_date' => $next['air_date'],
            ] : null,
            'upcoming_episode' => $upcoming ? [
                'season_number' => (int) $upcoming['season_number'],
                'episode_number' => (int) $upcoming['episode_number'],
                'name' => $upcoming['episode_name'],
                'air_date' => $upcoming['air_date'],
            ] : null,
        ];
    }

    // Shows with something ready to watch first (soonest next episode first),
    // then shows with something airing soon (soonest first), alphabetically
    // within each group.
    usort($result, function ($a, $b) {
        if (($a['available_count'] > 0) !== ($b['available_count'] > 0)) {
            return $a['available_count'] > 0 ? -1 : 1;
        }
        $aDate = $a['next_episode']['air_date'] ?? $a['upcoming_episode']['air_date'] ?? null;
        $bDate = $b['next_episode']['air_date'] ?? $b['upcoming_episode']['air_date'] ?? null;
        if ($aDate !== $bDate) {
            return strcmp((string) $aDate, (string) $bDate);
        }
        return strcasecmp($a['name'], $b['name']);
    });

    return ['all_shows' => $allShows, 'rows' => $result];
}

route($routes, 'GET', '#^/whattowatch$#', function () {
    $user = require_login();
    $pdo = db();

    sync_tracked_show_episodes($pdo, $user['id']);

    ['all_shows' => $allShows, 'rows' => $rows] = compute_show_watch_rows($pdo, $user['id']);

    $today = resolve_today();
    $soonCutoff = date('Y-m-d', strtotime("$today +" . WHATTOWATCH_SOON_DAYS . ' days'));

    // Caught up with nothing airing soon — not worth surfacing here (finished
    // shows, shows on long hiatus); the /people profile routes show these too.
    $result = array_values(array_filter($rows, function ($show) use ($soonCutoff) {
        $upcoming = $show['upcoming_episode'];
        return $show['available_count'] > 0 || ($upcoming && $upcoming['air_date'] <= $soonCutoff);
    }));

    respond([
        'total' => count($allShows),
        'shows' => $result,
    ]);
});

// -- Public profiles: any logged-in user can view another user's followed
// shows and watch status (unfiltered — includes shows with nothing
// available, unlike /whattowatch). --

route($routes, 'GET', '#^/people$#', function () {
    require_login();
    $rows = db()->query('SELECT id, name, picture_url FROM users ORDER BY name COLLATE NOCASE ASC')
        ->fetchAll(PDO::FETCH_ASSOC);
    respond($rows);
});

route($routes, 'GET', '#^/people/(\d+)$#', function ($id) {
    require_login();
    $pdo = db();

    $stmt = $pdo->prepare('SELECT id, name, picture_url FROM users WHERE id = ?');
    $stmt->execute([$id]);
    $person = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$person) {
        respond_error('User not found', 404);
    }

    sync_tracked_show_episodes($pdo, (int) $id);
    ['all_shows' => $allShows, 'rows' => $rows] = compute_show_watch_rows($pdo, (int) $id);

    respond($person + ['total' => count($allShows), 'shows' => $rows]);
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

    $pdo = db();
    $stmt = $pdo->prepare('
        INSERT INTO watched_episodes (user_id, show_id, season_number, episode_number)
        VALUES (?, ?, ?, ?)
        ON CONFLICT(user_id, show_id, season_number, episode_number) DO NOTHING
    ');
    $pdo->beginTransaction();
    foreach (get_watch_group_peers($pdo, $user['id'], $id) as $peerId) {
        $stmt->execute([$peerId, $id, $season, $episode]);
    }
    $pdo->commit();
    respond(['ok' => true], 201);
});

route($routes, 'DELETE', '#^/shows/(\d+)/watched$#', function ($id) {
    $user = require_login();
    $season = $_GET['season'] ?? null;
    $episode = $_GET['episode'] ?? null;

    if ($season === null || $episode === null) {
        respond_error('season and episode query params are required');
    }

    $pdo = db();
    $stmt = $pdo->prepare('
        DELETE FROM watched_episodes WHERE user_id = ? AND show_id = ? AND season_number = ? AND episode_number = ?
    ');
    $pdo->beginTransaction();
    foreach (get_watch_group_peers($pdo, $user['id'], $id) as $peerId) {
        $stmt->execute([$peerId, $id, $season, $episode]);
    }
    $pdo->commit();
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
    $peers = get_watch_group_peers($pdo, $user['id'], $id);
    $stmt = $pdo->prepare('
        INSERT INTO watched_episodes (user_id, show_id, season_number, episode_number)
        VALUES (?, ?, ?, ?)
        ON CONFLICT(user_id, show_id, season_number, episode_number) DO NOTHING
    ');
    $pdo->beginTransaction();
    foreach ($peers as $peerId) {
        for ($ep = 1; $ep <= $episodeCount; $ep++) {
            $stmt->execute([$peerId, $id, $season, $ep]);
        }
    }
    $pdo->commit();

    respond(['ok' => true], 201);
});

route($routes, 'DELETE', '#^/shows/(\d+)/watched/season/(\d+)$#', function ($id, $season) {
    $user = require_login();
    $pdo = db();
    $stmt = $pdo->prepare('DELETE FROM watched_episodes WHERE user_id = ? AND show_id = ? AND season_number = ?');
    $pdo->beginTransaction();
    foreach (get_watch_group_peers($pdo, $user['id'], $id) as $peerId) {
        $stmt->execute([$peerId, $id, $season]);
    }
    $pdo->commit();
    respond(['ok' => true]);
});

// -- Watch with: link two or more users' watched_episodes for a show so
// marking an episode watched by any accepted member fans out to everyone
// (see get_watch_group_peers(), used by the watched-episode routes above).

route($routes, 'GET', '#^/shows/(\d+)/watch-with$#', function ($tmdbId) {
    $user = require_login();
    $pdo = db();

    $groupId = find_watch_group_id($pdo, $user['id'], $tmdbId);
    if ($groupId === null) {
        respond(['members' => []]);
    }

    $stmt = $pdo->prepare('
        SELECT u.id, u.name, u.picture_url, m.status
        FROM watch_group_members m
        JOIN users u ON u.id = m.user_id
        WHERE m.group_id = ?
        ORDER BY m.joined_at ASC
    ');
    $stmt->execute([$groupId]);
    respond(['members' => $stmt->fetchAll(PDO::FETCH_ASSOC)]);
});

route($routes, 'POST', '#^/shows/(\d+)/watch-with$#', function ($tmdbId) {
    $user = require_login();
    $body = json_body();
    $inviteeId = (int) ($body['user_id'] ?? 0);

    if ($inviteeId <= 0) {
        respond_error('user_id is required');
    }
    if ($inviteeId === $user['id']) {
        respond_error("Can't invite yourself");
    }

    ensure_show_exists($user['id'], $tmdbId);

    $pdo = db();

    if (find_watch_group_id($pdo, $inviteeId, $tmdbId) !== null) {
        respond_error('That person is already watching this show with someone', 409);
    }

    $pdo->beginTransaction();

    $groupId = find_watch_group_id($pdo, $user['id'], $tmdbId);
    if ($groupId === null) {
        $pdo->prepare('INSERT INTO watch_groups (tmdb_id, created_by) VALUES (?, ?)')
            ->execute([$tmdbId, $user['id']]);
        $groupId = (int) $pdo->lastInsertId();
        $pdo->prepare('
            INSERT INTO watch_group_members (group_id, user_id, status, invited_by)
            VALUES (?, ?, \'accepted\', ?)
        ')->execute([$groupId, $user['id'], $user['id']]);
    }

    $pdo->prepare('
        INSERT INTO watch_group_members (group_id, user_id, status, invited_by)
        VALUES (?, ?, \'pending\', ?)
    ')->execute([$groupId, $inviteeId, $user['id']]);

    $pdo->commit();

    $inviteeStmt = $pdo->prepare('SELECT email, name FROM users WHERE id = ?');
    $inviteeStmt->execute([$inviteeId]);
    $invitee = $inviteeStmt->fetch(PDO::FETCH_ASSOC);

    $showStmt = $pdo->prepare('SELECT name FROM shows WHERE tmdb_id = ? AND user_id = ?');
    $showStmt->execute([$tmdbId, $user['id']]);
    $showName = $showStmt->fetchColumn() ?: 'a show';

    if ($invitee) {
        send_email(
            $invitee['email'],
            ($user['name'] ?? $user['email']) . " wants to watch $showName with you",
            email_layout(
                '<p style="margin:0 0 4px;font-size:17px;font-weight:600;">Watch together?</p>'
                . '<p style="margin:0;color:#555b68;">' . htmlspecialchars($user['name'] ?? $user['email']) . ' invited you to watch <strong>' . htmlspecialchars($showName) . '</strong> together on tvtrkr.</p>',
                'Open tvtrkr',
                APP_BASE_URL
            )
        );
    }

    respond(['ok' => true], 201);
});

route($routes, 'DELETE', '#^/shows/(\d+)/watch-with$#', function ($tmdbId) {
    $user = require_login();
    $pdo = db();
    $pdo->beginTransaction();
    leave_watch_group($pdo, $user['id'], $tmdbId);
    $pdo->commit();
    respond(['ok' => true]);
});

route($routes, 'GET', '#^/watch-invites$#', function () {
    $user = require_login();

    $stmt = db()->prepare('
        SELECT
            g.id AS group_id, g.tmdb_id,
            s.name AS show_name, s.poster_path,
            inviter.name AS inviter_name, inviter.picture_url AS inviter_picture_url
        FROM watch_group_members m
        JOIN watch_groups g ON g.id = m.group_id
        JOIN users inviter ON inviter.id = m.invited_by
        LEFT JOIN shows s ON s.tmdb_id = g.tmdb_id AND s.user_id = g.created_by
        WHERE m.user_id = ? AND m.status = \'pending\'
        ORDER BY m.joined_at DESC
    ');
    $stmt->execute([$user['id']]);
    respond($stmt->fetchAll(PDO::FETCH_ASSOC));
});

route($routes, 'POST', '#^/watch-invites/(\d+)/accept$#', function ($groupId) {
    $user = require_login();
    $pdo = db();

    $tmdbId = require_pending_invite($pdo, $user['id'], $groupId);

    $pdo->beginTransaction();
    $pdo->prepare("UPDATE watch_group_members SET status = 'accepted' WHERE group_id = ? AND user_id = ?")
        ->execute([$groupId, $user['id']]);

    // Copy an existing member's show metadata into the invitee's own
    // library row rather than re-fetching from TMDB.
    $source = $pdo->prepare('SELECT * FROM shows WHERE tmdb_id = ? LIMIT 1');
    $source->execute([$tmdbId]);
    $show = $source->fetch(PDO::FETCH_ASSOC);
    if ($show) {
        $pdo->prepare('
            INSERT INTO shows (user_id, tmdb_id, name, poster_path, backdrop_path, overview, first_air_date, status)
            VALUES (:user_id, :tmdb_id, :name, :poster_path, :backdrop_path, :overview, :first_air_date, :status)
            ON CONFLICT(user_id, tmdb_id) DO NOTHING
        ')->execute([
            'user_id' => $user['id'],
            'tmdb_id' => $tmdbId,
            'name' => $show['name'],
            'poster_path' => $show['poster_path'],
            'backdrop_path' => $show['backdrop_path'],
            'overview' => $show['overview'],
            'first_air_date' => $show['first_air_date'],
            'status' => $show['status'],
        ]);
    }
    $pdo->commit();

    respond(['ok' => true]);
});

route($routes, 'POST', '#^/watch-invites/(\d+)/decline$#', function ($groupId) {
    $user = require_login();
    $pdo = db();

    require_pending_invite($pdo, $user['id'], $groupId);

    $pdo->prepare("DELETE FROM watch_group_members WHERE group_id = ? AND user_id = ? AND status = 'pending'")
        ->execute([$groupId, $user['id']]);

    respond(['ok' => true]);
});

// -- Calendar: infinite-scroll history + future for tracked shows --
//
// Episodes are crawled season-by-season from TMDB into a local `episodes`
// cache (see sync_show_episodes()) so the calendar can page through a full
// timeline via cheap SQLite cursor queries instead of hitting TMDB on every
// scroll event.

const CALENDAR_PAGE_SIZE = 20;
const CALENDAR_PAGE_SIZE_MAX = 50;

route($routes, 'GET', '#^/calendar$#', function () {
    $user = require_login();
    $pdo = db();

    sync_tracked_show_episodes($pdo, $user['id']);

    // A synthetic boundary cursor: everything with air_date <= today sorts
    // "before" it, everything with air_date > today sorts "after" it, and
    // since real show/season/episode ids can never reach PHP_INT_MAX this
    // never accidentally swallows or duplicates today's own episodes.
    $boundary = [resolve_today(), PHP_INT_MAX, PHP_INT_MAX, PHP_INT_MAX];

    respond([
        'recent' => fetch_calendar_page($pdo, $user['id'], 'before', $boundary, CALENDAR_PAGE_SIZE),
        'upcoming' => fetch_calendar_page($pdo, $user['id'], 'after', $boundary, CALENDAR_PAGE_SIZE),
    ]);
});

route($routes, 'GET', '#^/calendar/more$#', function () {
    $user = require_login();
    $pdo = db();

    $dir = $_GET['dir'] ?? '';
    if (!in_array($dir, ['before', 'after'], true)) {
        respond_error('dir must be "before" or "after"');
    }

    $airDate = $_GET['air_date'] ?? '';
    if ($airDate === '') {
        respond_error('air_date cursor is required');
    }

    $cursor = [$airDate, (int) ($_GET['show_id'] ?? 0), (int) ($_GET['season'] ?? 0), (int) ($_GET['episode'] ?? 0)];
    $limit = min(CALENDAR_PAGE_SIZE_MAX, max(1, (int) ($_GET['limit'] ?? CALENDAR_PAGE_SIZE)));

    respond(['episodes' => fetch_calendar_page($pdo, $user['id'], $dir, $cursor, $limit)]);
});

// Crawls every tracked show's full season list into the local `episodes`
// cache, skipping shows synced within the TTL and seasons that are already
// fully cached (a finished season's episode list never changes).
function sync_tracked_show_episodes(PDO $pdo, int $userId): void
{
    $stmt = $pdo->prepare('SELECT tmdb_id FROM shows WHERE user_id = ?');
    $stmt->execute([$userId]);
    foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $showId) {
        sync_show_episodes($pdo, (int) $showId);
    }
}

function sync_show_episodes(PDO $pdo, int $showId): void
{
    $syncTtlSeconds = 6 * 3600;

    $stmt = $pdo->prepare('SELECT synced_at FROM show_episode_sync WHERE show_id = ?');
    $stmt->execute([$showId]);
    $lastSync = $stmt->fetchColumn();
    if ($lastSync !== false && (time() - strtotime($lastSync . ' UTC')) < $syncTtlSeconds) {
        return;
    }

    try {
        $details = tmdb_get('/tv/' . $showId);
    } catch (RuntimeException $e) {
        return; // TMDB unreachable or show gone; leave whatever's cached in place
    }

    $countStmt = $pdo->prepare('SELECT COUNT(*) FROM episodes WHERE show_id = ? AND season_number = ?');
    $upsert = $pdo->prepare('
        INSERT INTO episodes (show_id, season_number, episode_number, name, air_date)
        VALUES (?, ?, ?, ?, ?)
        ON CONFLICT(show_id, season_number, episode_number) DO UPDATE SET
            name = excluded.name, air_date = excluded.air_date
    ');

    foreach ($details['seasons'] ?? [] as $season) {
        $seasonNumber = (int) $season['season_number'];
        $episodeCount = (int) ($season['episode_count'] ?? 0);
        if ($seasonNumber === 0 && $episodeCount === 0) {
            continue; // no specials
        }

        // Already have every episode TMDB currently knows about for this
        // season (true for any season that's fully aired) — skip refetching.
        $countStmt->execute([$showId, $seasonNumber]);
        if ($episodeCount > 0 && (int) $countStmt->fetchColumn() >= $episodeCount) {
            continue;
        }

        try {
            $seasonData = tmdb_get("/tv/$showId/season/$seasonNumber");
        } catch (RuntimeException $e) {
            continue;
        }

        foreach ($seasonData['episodes'] ?? [] as $ep) {
            if (empty($ep['air_date'])) {
                continue;
            }
            $upsert->execute([$showId, $seasonNumber, $ep['episode_number'], $ep['name'], $ep['air_date']]);
        }
    }

    $pdo->prepare('
        INSERT INTO show_episode_sync (show_id, synced_at) VALUES (?, datetime("now"))
        ON CONFLICT(show_id) DO UPDATE SET synced_at = excluded.synced_at
    ')->execute([$showId]);
}

// Cursor-paginates the merged timeline of the user's tracked shows' episodes.
// $cursor is [air_date, show_id, season_number, episode_number]; results are
// always returned in ascending (oldest-to-newest) order, ready to prepend
// ('before' pages, which are queried DESC then flipped) or append ('after').
function fetch_calendar_page(PDO $pdo, int $userId, string $direction, array $cursor, int $limit): array
{
    [$cAirDate, $cShowId, $cSeason, $cEpisode] = $cursor;
    $before = $direction === 'before';
    $cmp = $before ? '<' : '>';
    $order = $before ? 'DESC' : 'ASC';

    $stmt = $pdo->prepare("
        SELECT
            e.show_id, e.season_number, e.episode_number, e.name AS episode_name, e.air_date,
            s.name AS show_name, s.poster_path,
            CASE WHEN e.air_date <= :today THEN 1 ELSE 0 END AS aired,
            CASE WHEN w.show_id IS NOT NULL THEN 1 ELSE 0 END AS watched
        FROM episodes e
        JOIN shows s ON s.tmdb_id = e.show_id AND s.user_id = :user_id
        LEFT JOIN watched_episodes w
            ON w.user_id = :user_id AND w.show_id = e.show_id
            AND w.season_number = e.season_number AND w.episode_number = e.episode_number
        WHERE (e.air_date, e.show_id, e.season_number, e.episode_number) $cmp (:c_air_date, :c_show_id, :c_season, :c_episode)
        ORDER BY e.air_date $order, e.show_id $order, e.season_number $order, e.episode_number $order
        LIMIT :limit
    ");
    $stmt->bindValue(':today', resolve_today());
    $stmt->bindValue(':user_id', $userId, PDO::PARAM_INT);
    $stmt->bindValue(':c_air_date', $cAirDate);
    $stmt->bindValue(':c_show_id', $cShowId, PDO::PARAM_INT);
    $stmt->bindValue(':c_season', $cSeason, PDO::PARAM_INT);
    $stmt->bindValue(':c_episode', $cEpisode, PDO::PARAM_INT);
    $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
    $stmt->execute();
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    if ($before) {
        $rows = array_reverse($rows);
    }

    return array_map(fn($row) => [
        'show_id' => (int) $row['show_id'],
        'show_name' => $row['show_name'],
        'poster_path' => $row['poster_path'],
        'air_date' => $row['air_date'],
        'season_number' => (int) $row['season_number'],
        'episode_number' => (int) $row['episode_number'],
        'episode_name' => $row['episode_name'],
        'aired' => (bool) $row['aired'],
        'watched' => (bool) $row['watched'],
    ], $rows);
}

function ensure_show_exists(int $userId, $tmdbId): void
{
    // Guard against orphaned watched rows if a show was never added to the library.
    $stmt = db()->prepare('SELECT 1 FROM shows WHERE user_id = ? AND tmdb_id = ?');
    $stmt->execute([$userId, $tmdbId]);
    if (!$stmt->fetch()) {
        respond_error('Show is not in your library yet', 404);
    }
}

// Who each of $userId's shows is being watched with, if anyone: a map of
// tmdb_id => list of other members (with invite status), for every watch
// group $userId belongs to. Lets a show-list view render avatars without a
// request per row (mirrors GET /shows/:id/watch-with, but for every show
// at once).
function watch_with_by_show(PDO $pdo, int $userId): array
{
    $stmt = $pdo->prepare('
        SELECT g.tmdb_id, u.id, u.name, u.picture_url, m2.status
        FROM watch_group_members m1
        JOIN watch_groups g ON g.id = m1.group_id
        JOIN watch_group_members m2 ON m2.group_id = g.id AND m2.user_id != m1.user_id
        JOIN users u ON u.id = m2.user_id
        WHERE m1.user_id = ?
        ORDER BY g.tmdb_id, m2.joined_at ASC
    ');
    $stmt->execute([$userId]);

    $byShow = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $byShow[$row['tmdb_id']][] = [
            'id' => (int) $row['id'],
            'name' => $row['name'],
            'picture_url' => $row['picture_url'],
            'status' => $row['status'],
        ];
    }
    return $byShow;
}

// Returns the accepted-member user_ids sharing a watch group with $userId
// for this show (including $userId itself), or just [$userId] if $userId
// isn't in one. Used to fan out watched-episode writes to everyone
// watching a show together.
function get_watch_group_peers(PDO $pdo, int $userId, $tmdbId): array
{
    $groupId = find_watch_group_id($pdo, $userId, $tmdbId);
    if ($groupId === null) {
        return [$userId];
    }

    $stmt = $pdo->prepare("SELECT user_id FROM watch_group_members WHERE group_id = ? AND status = 'accepted'");
    $stmt->execute([$groupId]);
    return array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
}

// The watch_groups.id that $userId belongs to (pending or accepted) for
// this show, or null if they're not linked with anyone on it.
function find_watch_group_id(PDO $pdo, int $userId, $tmdbId): ?int
{
    $stmt = $pdo->prepare('
        SELECT g.id
        FROM watch_group_members m
        JOIN watch_groups g ON g.id = m.group_id
        WHERE m.user_id = ? AND g.tmdb_id = ?
    ');
    $stmt->execute([$userId, $tmdbId]);
    $id = $stmt->fetchColumn();
    return $id === false ? null : (int) $id;
}

// Removes $userId from their watch group for this show, if any. If fewer
// than 2 members remain afterward, the group itself is deleted (past
// watched data is untouched either way). No-op if not in a group.
function leave_watch_group(PDO $pdo, int $userId, $tmdbId): void
{
    $groupId = find_watch_group_id($pdo, $userId, $tmdbId);
    if ($groupId === null) {
        return;
    }

    $pdo->prepare('DELETE FROM watch_group_members WHERE group_id = ? AND user_id = ?')
        ->execute([$groupId, $userId]);

    $stmt = $pdo->prepare('SELECT COUNT(*) FROM watch_group_members WHERE group_id = ?');
    $stmt->execute([$groupId]);
    if ((int) $stmt->fetchColumn() < 2) {
        $pdo->prepare('DELETE FROM watch_groups WHERE id = ?')->execute([$groupId]);
    }
}

// Validates that $groupId is a real, still-pending invite for $userId and
// returns the group's tmdb_id; 404s otherwise.
function require_pending_invite(PDO $pdo, int $userId, $groupId): int
{
    $stmt = $pdo->prepare("
        SELECT g.tmdb_id
        FROM watch_group_members m
        JOIN watch_groups g ON g.id = m.group_id
        WHERE m.group_id = ? AND m.user_id = ? AND m.status = 'pending'
    ");
    $stmt->execute([$groupId, $userId]);
    $tmdbId = $stmt->fetchColumn();
    if ($tmdbId === false) {
        respond_error('Invite not found', 404);
    }
    return (int) $tmdbId;
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
