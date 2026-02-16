<?php
/**
 * Server Time API Endpoint
 *
 * Returns current server UTC timestamp for client clock skew detection.
 * Used by the airport page to periodically re-check if the client clock
 * differs from server time by more than 5 minutes.
 *
 * Response: {"time": <unix_timestamp>}
 */

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-cache, no-store, must-revalidate');
header('Pragma: no-cache');
header('Expires: 0');

$requestMethod = $_SERVER['REQUEST_METHOD'] ?? 'GET';
if ($requestMethod !== 'GET') {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed']);
    exit;
}

echo json_encode(['time' => time()]);
