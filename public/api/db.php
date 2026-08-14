<?php
// PDO SQLite bootstrap + schema. Safe to include multiple times per request.

require_once __DIR__ . '/config.php';

function db(): PDO
{
    static $pdo = null;

    if ($pdo !== null) {
        return $pdo;
    }

    $dataDir = dirname(DB_PATH);
    if (!is_dir($dataDir)) {
        mkdir($dataDir, 0775, true);
    }

    $pdo = new PDO('sqlite:' . DB_PATH);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->exec('PRAGMA foreign_keys = ON');
    // WAL lets readers proceed alongside a writer instead of locking the
    // whole file; busy_timeout makes concurrent writers (e.g. two watch-with
    // members marking episodes at once) queue briefly instead of throwing
    // SQLITE_BUSY immediately.
    $pdo->exec('PRAGMA journal_mode = WAL');
    $pdo->exec('PRAGMA busy_timeout = 5000');

    migrate_legacy_single_user_schema($pdo);

    $pdo->exec('
        CREATE TABLE IF NOT EXISTS users (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            google_sub TEXT UNIQUE NOT NULL,
            email TEXT UNIQUE NOT NULL,
            name TEXT,
            picture_url TEXT,
            is_admin INTEGER NOT NULL DEFAULT 0,
            created_at TEXT NOT NULL DEFAULT (datetime(\'now\'))
        )
    ');

    $pdo->exec('
        CREATE TABLE IF NOT EXISTS allowed_emails (
            email TEXT PRIMARY KEY,
            added_by INTEGER REFERENCES users(id),
            added_at TEXT NOT NULL DEFAULT (datetime(\'now\'))
        )
    ');

    $pdo->exec('
        CREATE TABLE IF NOT EXISTS shows (
            user_id INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
            tmdb_id INTEGER NOT NULL,
            name TEXT NOT NULL,
            poster_path TEXT,
            backdrop_path TEXT,
            overview TEXT,
            first_air_date TEXT,
            status TEXT,
            added_at TEXT NOT NULL DEFAULT (datetime(\'now\')),
            PRIMARY KEY (user_id, tmdb_id)
        )
    ');

    $pdo->exec('
        CREATE TABLE IF NOT EXISTS watched_episodes (
            user_id INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
            show_id INTEGER NOT NULL,
            season_number INTEGER NOT NULL,
            episode_number INTEGER NOT NULL,
            watched_at TEXT NOT NULL DEFAULT (datetime(\'now\')),
            PRIMARY KEY (user_id, show_id, season_number, episode_number),
            FOREIGN KEY (user_id, show_id) REFERENCES shows(user_id, tmdb_id) ON DELETE CASCADE
        )
    ');

    $pdo->exec('
        CREATE TABLE IF NOT EXISTS episodes (
            show_id INTEGER NOT NULL,
            season_number INTEGER NOT NULL,
            episode_number INTEGER NOT NULL,
            name TEXT,
            air_date TEXT NOT NULL,
            PRIMARY KEY (show_id, season_number, episode_number)
        )
    ');
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_episodes_air_date ON episodes (air_date)');

    // Tracks when a show's full episode list was last pulled from TMDB
    // (one season-by-season crawl per show), so the calendar's infinite
    // scroll can page through the local cache instead of hitting TMDB
    // on every scroll event.
    $pdo->exec('
        CREATE TABLE IF NOT EXISTS show_episode_sync (
            show_id INTEGER PRIMARY KEY,
            synced_at TEXT NOT NULL
        )
    ');

    // "Watch with" — links two or more users' watched_episodes for one show
    // so marking an episode watched by any accepted member fans out to all
    // of them. A group is scoped to a single show; a user may belong to at
    // most one group per show (enforced in application code).
    $pdo->exec('
        CREATE TABLE IF NOT EXISTS watch_groups (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            tmdb_id INTEGER NOT NULL,
            created_by INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
            created_at TEXT NOT NULL DEFAULT (datetime(\'now\'))
        )
    ');

    $pdo->exec('
        CREATE TABLE IF NOT EXISTS watch_group_members (
            group_id INTEGER NOT NULL REFERENCES watch_groups(id) ON DELETE CASCADE,
            user_id INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
            status TEXT NOT NULL DEFAULT \'pending\',
            invited_by INTEGER NOT NULL REFERENCES users(id),
            joined_at TEXT NOT NULL DEFAULT (datetime(\'now\')),
            PRIMARY KEY (group_id, user_id)
        )
    ');
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_watch_groups_tmdb_id ON watch_groups (tmdb_id)');
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_watch_group_members_user ON watch_group_members (user_id)');

    seed_admin_allowlist($pdo);

    return $pdo;
}

// Pre-auth versions of this app kept one shared library with no user_id.
// If that old schema is still present, rename it out of the way so the new
// per-user tables can be created; import_legacy_data_for_user() then copies
// it into the first admin account on their first login.
function migrate_legacy_single_user_schema(PDO $pdo): void
{
    $hasUserId = $pdo->query("PRAGMA table_info(shows)")->fetchAll(PDO::FETCH_COLUMN, 1);
    $showsExists = $pdo->query("SELECT name FROM sqlite_master WHERE type='table' AND name='shows'")->fetchColumn();

    if (!$showsExists || in_array('user_id', $hasUserId, true)) {
        return; // fresh install, or already migrated
    }

    $pdo->exec('ALTER TABLE shows RENAME TO shows_legacy');
    $pdo->exec('ALTER TABLE watched_episodes RENAME TO watched_episodes_legacy');
}

// One-time seed: whoever logs in with ADMIN_EMAIL is auto-allowed and made admin,
// so there's a bootstrap path into a brand new install with an empty allowed_emails table.
function seed_admin_allowlist(PDO $pdo): void
{
    if (ADMIN_EMAIL === '') {
        return;
    }
    $stmt = $pdo->prepare('INSERT OR IGNORE INTO allowed_emails (email) VALUES (?)');
    $stmt->execute([ADMIN_EMAIL]);
}

// Called right after a user's first login. If pre-auth legacy data exists,
// it's assigned to that user (there's no way to know who it belonged to,
// so it goes to whoever logs in first) and the legacy tables are dropped.
function import_legacy_data_for_user(PDO $pdo, int $userId): void
{
    $legacyExists = $pdo->query("SELECT name FROM sqlite_master WHERE type='table' AND name='shows_legacy'")->fetchColumn();
    if (!$legacyExists) {
        return;
    }

    $pdo->beginTransaction();

    $pdo->exec("
        INSERT INTO shows (user_id, tmdb_id, name, poster_path, backdrop_path, overview, first_air_date, status, added_at)
        SELECT $userId, tmdb_id, name, poster_path, backdrop_path, overview, first_air_date, status, added_at
        FROM shows_legacy
    ");
    $pdo->exec("
        INSERT INTO watched_episodes (user_id, show_id, season_number, episode_number, watched_at)
        SELECT $userId, show_id, season_number, episode_number, watched_at
        FROM watched_episodes_legacy
    ");

    $pdo->exec('DROP TABLE watched_episodes_legacy');
    $pdo->exec('DROP TABLE shows_legacy');

    $pdo->commit();
}
