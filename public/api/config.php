<?php
// Loads local config (gitignored) and exposes constants used by the rest of the app.

$localConfigPath = __DIR__ . '/config.local.php';

if (!file_exists($localConfigPath)) {
    http_response_code(500);
    header('Content-Type: application/json');
    echo json_encode([
        'error' => 'Missing config.local.php. Copy config.example.php to config.local.php and add your TMDB token.',
    ]);
    exit;
}

$config = require $localConfigPath;

define('TMDB_TOKEN', $config['tmdb_token'] ?? '');
define('DB_PATH', __DIR__ . '/../../data/tvtrkr.sqlite');

define('GOOGLE_CLIENT_ID', $config['google_client_id'] ?? '');
define('GOOGLE_CLIENT_SECRET', $config['google_client_secret'] ?? '');
define('APP_BASE_URL', rtrim($config['app_base_url'] ?? '', '/'));
define('ADMIN_EMAIL', strtolower(trim($config['admin_email'] ?? '')));

if (TMDB_TOKEN === '' || TMDB_TOKEN === 'PASTE_YOUR_TMDB_V4_READ_ACCESS_TOKEN_HERE') {
    http_response_code(500);
    header('Content-Type: application/json');
    echo json_encode([
        'error' => 'TMDB token not configured. Edit public/api/config.local.php.',
    ]);
    exit;
}
