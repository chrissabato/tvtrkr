<?php
// Thin server-side proxy around the TMDB v3 REST API using a v4 bearer token.
// Keeps the token out of the browser and gives us one place to handle errors.

require_once __DIR__ . '/config.php';

define('TMDB_BASE', 'https://api.themoviedb.org/3');

/**
 * @param string $path e.g. '/search/tv'
 * @param array<string,string> $query
 * @return array decoded JSON response
 * @throws RuntimeException on transport or non-2xx errors
 */
function tmdb_get(string $path, array $query = []): array
{
    $query['language'] = $query['language'] ?? 'en-US';
    $url = TMDB_BASE . $path . '?' . http_build_query($query);

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => [
            'Authorization: Bearer ' . TMDB_TOKEN,
            'Accept: application/json',
        ],
        CURLOPT_TIMEOUT => 10,
    ]);

    $body = curl_exec($ch);
    $errno = curl_errno($ch);
    $error = curl_error($ch);
    $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($errno !== 0) {
        throw new RuntimeException("TMDB request failed: $error", 502);
    }

    $decoded = json_decode($body, true);

    if ($status < 200 || $status >= 300) {
        $message = $decoded['status_message'] ?? "TMDB returned HTTP $status";
        throw new RuntimeException($message, $status);
    }

    return $decoded ?? [];
}
