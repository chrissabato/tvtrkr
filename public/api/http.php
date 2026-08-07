<?php
// Small JSON request/response helpers shared by index.php and auth.php.

function json_body(): array
{
    $raw = file_get_contents('php://input');
    if ($raw === false || $raw === '') {
        return [];
    }
    $decoded = json_decode($raw, true);
    return is_array($decoded) ? $decoded : [];
}

function respond($data, int $status = 200): void
{
    http_response_code($status);
    header('Content-Type: application/json');
    echo json_encode($data);
    exit;
}

function respond_error(string $message, int $status = 400): void
{
    respond(['error' => $message], $status);
}
