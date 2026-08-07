<?php
// Router script for PHP's built-in dev server:
//   php -S localhost:8000 public/router.php
//
// - Real static files (css/js/icons/manifest) are served as-is.
// - /api/* requests are dispatched to api/index.php.
// - Everything else falls back to index.html (the app uses hash-based routing,
//   so this mainly matters if someone deep-links a path directly).

$uri = urldecode(parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH));

if ($uri !== '/' && file_exists(__DIR__ . $uri) && !is_dir(__DIR__ . $uri)) {
    return false;
}

if (str_starts_with($uri, '/api/')) {
    require __DIR__ . '/api/index.php';
    return true;
}

require __DIR__ . '/index.html';
