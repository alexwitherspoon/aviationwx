<?php
/**
 * RainViewer Weather Maps Proxy
 *
 * Proxies https://api.rainviewer.com/public/weather-maps.json server-side.
 * Avoids CORS issues when the browser cannot fetch RainViewer directly.
 *
 * Benefits:
 * - Same-origin request from browser (no CORS)
 * - Server-side caching (5 min TTL) reduces external API calls
 * - Graceful degradation if RainViewer unavailable
 *
 * @see pages/airports.php - Radar layer uses this for timestamp lookup
 * @see api/map-tiles.php - Tile proxy uses timestamp for radar tiles
 */

require_once __DIR__ . '/../lib/config.php';
require_once __DIR__ . '/../lib/rate-limit.php';
require_once __DIR__ . '/../lib/logger.php';

// Rate limit: 60 requests/minute per IP (radar refreshes every 10 min)
if (!checkRateLimit('rainviewer_weather_maps', 60, 60)) {
    http_response_code(429);
    header('Content-Type: application/json');
    header('Retry-After: 60');
    echo json_encode(['error' => 'Rate limit exceeded', 'retry_after' => 60]);
    exit;
}

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: public, max-age=300, stale-while-revalidate=600');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);
    header('Allow: GET, OPTIONS');
    exit;
}

$cacheDir = dirname(__DIR__) . '/cache/rainviewer';
$cacheFile = $cacheDir . '/weather-maps.json';
$cacheTtl = 300; // 5 minutes; RainViewer updates every 10 min

if (is_dir($cacheDir) && file_exists($cacheFile)) {
    $age = time() - filemtime($cacheFile);
    if ($age < $cacheTtl) {
        header('X-Cache: HIT');
        header('Cache-Control: public, max-age=' . ($cacheTtl - $age) . ', stale-while-revalidate=600');
        readfile($cacheFile);
        exit;
    }
}

$url = 'https://api.rainviewer.com/public/weather-maps.json';
$ch = curl_init();
curl_setopt_array($ch, [
    CURLOPT_URL => $url,
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_FOLLOWLOCATION => true,
    CURLOPT_TIMEOUT => 10,
    CURLOPT_CONNECTTIMEOUT => 5,
    CURLOPT_SSL_VERIFYPEER => true,
    CURLOPT_USERAGENT => 'AviationWX/1.0 (Weather Maps Proxy)',
]);

$body = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$error = curl_error($ch);
curl_close($ch);

if ($httpCode !== 200) {
    aviationwx_log('warning', 'RainViewer weather-maps fetch failed', [
        'http_code' => $httpCode,
        'error' => $error,
    ], 'api');
    http_response_code(502);
    header('X-Cache: MISS');
    echo json_encode(['error' => 'Upstream unavailable', 'code' => $httpCode]);
    exit;
}

// Validate JSON
$data = json_decode($body, true);
if ($data === null && json_last_error() !== JSON_ERROR_NONE) {
    aviationwx_log('warning', 'RainViewer weather-maps invalid JSON', [], 'api');
    http_response_code(502);
    header('X-Cache: MISS');
    echo json_encode(['error' => 'Invalid upstream response']);
    exit;
}

// Cache on success
if (!is_dir($cacheDir)) {
    @mkdir($cacheDir, 0755, true);
}
if (is_dir($cacheDir) && is_writable($cacheDir)) {
    @file_put_contents($cacheFile, $body);
}

header('X-Cache: MISS');
echo $body;
