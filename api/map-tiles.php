<?php
/**
 * Map Tile Proxy
 * 
 * Proxies and caches map tiles from OpenWeatherMap and RainViewer.
 * 
 * Benefits:
 * - Server-side caching reduces external API calls
 * - All users share the same cached tiles
 * - Centralized logging and metrics for tile usage
 * - Consistent CORS and security headers
 * - Graceful degradation if services unavailable
 * 
 * URL Formats:
 * - OpenWeatherMap: /api/map-tiles.php?layer=clouds_new&z=5&x=10&y=12
 * - RainViewer: /api/map-tiles.php?layer=rainviewer&z=5&x=10&y=12&timestamp=1234567890
 * 
 * Rate Limiting:
 * - Our proxy: 300 requests/minute per client IP (prevents abuse)
 * - OpenWeatherMap free tier: 60 calls/min, 1M/month
 * - RainViewer (as of Jan 2026): 100 requests/min per server IP, zoom ≤7 only
 * 
 * RainViewer API Changes (January 2026):
 * - Tiled API limited to zoom level 7 maximum
 * - Rate limit reduced to 100 requests/IP/minute
 * - Server-side caching (15min TTL) makes this limit manageable
 * - At zoom 7, there are ~16,384 possible tiles globally, so cache hits are high
 */

require_once __DIR__ . '/../lib/config.php';
require_once __DIR__ . '/../lib/cache-paths.php';
require_once __DIR__ . '/../lib/rate-limit.php';
require_once __DIR__ . '/../lib/logger.php';
require_once __DIR__ . '/../lib/metrics.php';

// ============================================================================
// RATE LIMITING - Permissive (prevents abuse, not normal usage)
// ============================================================================

// Very permissive rate limit: 300 requests per minute per IP
// This is 5x the OpenWeatherMap API limit, so legitimate users won't hit it
// Only catches obvious abuse (bots, scrapers, etc.)
if (!checkRateLimit('map_tiles', 300, 60)) {
    http_response_code(429);
    header('Content-Type: application/json');
    header('Retry-After: 60');
    echo json_encode([
        'error' => 'Rate limit exceeded',
        'limit' => '300 requests per minute',
        'retry_after' => 60
    ]);
    aviationwx_log('warning', 'map tiles rate limit exceeded', [
        'ip' => $_SERVER['HTTP_X_FORWARDED_FOR'] ?? $_SERVER['REMOTE_ADDR'] ?? 'unknown',
        'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? 'unknown'
    ], 'api');
    exit;
}

// CORS headers for map tiles
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

// Handle preflight requests
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

// Only allow GET requests
if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);
    header('Allow: GET, OPTIONS');
    exit;
}

// Parse and validate parameters
$layer = $_GET['layer'] ?? '';
$z = isset($_GET['z']) ? (int)$_GET['z'] : null;
$x = isset($_GET['x']) ? (int)$_GET['x'] : null;
$y = isset($_GET['y']) ? (int)$_GET['y'] : null;
$timestamp = isset($_GET['timestamp']) ? (int)$_GET['timestamp'] : null; // For RainViewer

// Validate required parameters
if (empty($layer) || $z === null || $x === null || $y === null) {
    http_response_code(400);
    header('Content-Type: application/json');
    echo json_encode(['error' => 'Missing required parameters: layer, z, x, y']);
    exit;
}

// Determine tile provider based on layer
$isRainViewer = ($layer === 'rainviewer');
$isOpenWeatherMap = !$isRainViewer;

// Validate layer name
$allowedOWMLayers = [
    'clouds_new',        // Cloud cover
    'precipitation_new', // Precipitation/rain
    'temp_new',         // Temperature
    'wind_new',         // Wind speed
    'pressure_new',     // Atmospheric pressure
];

if ($isRainViewer) {
    // RainViewer requires timestamp parameter
    if ($timestamp === null) {
        http_response_code(400);
        header('Content-Type: application/json');
        echo json_encode(['error' => 'RainViewer layer requires timestamp parameter']);
        exit;
    }
} elseif (!in_array($layer, $allowedOWMLayers)) {
    http_response_code(400);
    header('Content-Type: application/json');
    echo json_encode([
        'error' => 'Invalid layer',
        'allowed' => array_merge($allowedOWMLayers, ['rainviewer']),
        'hint' => 'Use rainviewer for radar, clouds_new for cloud cover'
    ]);
    exit;
}

// Validate zoom level
// RainViewer: Limited to zoom 0-7 as of January 2026
// OpenWeatherMap: Supports zoom 0-19
$maxZoom = $isRainViewer ? 7 : 19;
if ($z < 0 || $z > $maxZoom) {
    http_response_code(400);
    header('Content-Type: application/json');
    $hint = $isRainViewer ? ' (RainViewer API limited to zoom 7 as of Jan 2026)' : '';
    echo json_encode(['error' => "Invalid zoom level (0-{$maxZoom}){$hint}"]);
    exit;
}

// Validate tile coordinates (reasonable bounds)
$maxTileCoord = pow(2, $z);
if ($x < 0 || $x >= $maxTileCoord || $y < 0 || $y >= $maxTileCoord) {
    http_response_code(400);
    header('Content-Type: application/json');
    echo json_encode(['error' => 'Invalid tile coordinates for zoom level']);
    exit;
}

// Load config (only needed for OpenWeatherMap)
$apiKey = '';
if ($isOpenWeatherMap) {
    $config = loadConfig();
    $apiKey = $config['config']['openweathermap_api_key'] ?? '';
    
    // Return error if API key not configured
    if (empty($apiKey)) {
        http_response_code(503);
        header('Content-Type: application/json');
        echo json_encode(['error' => 'OpenWeatherMap API key not configured']);
        exit;
    }
}

// Get cache path for this tile
// For RainViewer, include timestamp in layer name for cache isolation
$cacheLayer = $isRainViewer ? "rainviewer_{$timestamp}" : $layer;
$cachePath = getMapTileCachePath($cacheLayer, $z, $x, $y);
$cacheDir = dirname($cachePath);

// Define cache TTL based on layer type
if ($isRainViewer) {
    // RainViewer radar updates every 10 minutes, cache for 15 minutes
    define('TILE_CACHE_TTL', 900);
} else {
    // OpenWeatherMap data changes slowly, cache for 1 hour
    define('TILE_CACHE_TTL', 3600);
}

// Check if cached tile exists and is fresh
$useCachedTile = false;
if (file_exists($cachePath)) {
    $age = time() - filemtime($cachePath);
    if ($age < TILE_CACHE_TTL) {
        $useCachedTile = true;
    }
}

// Serve cached tile if available
if ($useCachedTile) {
    // Track tile serve
    $source = $isRainViewer ? 'rainviewer' : 'openweathermap';
    metrics_track_tile_serve($source);
    
    // Set appropriate headers for cached content
    header('Content-Type: image/png');
    header('Cache-Control: public, max-age=' . (TILE_CACHE_TTL - $age));
    header('X-Cache: HIT');
    
    readfile($cachePath);
    exit;
}

// Tile not cached or stale - fetch from upstream
if ($isRainViewer) {
    // RainViewer tile URL format: size/smoothing_quality/color_scheme
    $tileUrl = "https://tilecache.rainviewer.com/v2/radar/{$timestamp}/256/{$z}/{$x}/{$y}/6/1_1.png";
} else {
    // OpenWeatherMap tile URL
    $tileUrl = "https://tile.openweathermap.org/map/{$layer}/{$z}/{$x}/{$y}.png?appid={$apiKey}";
}

// Use cURL for fetching with timeout
$ch = curl_init();
curl_setopt($ch, CURLOPT_URL, $tileUrl);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
curl_setopt($ch, CURLOPT_TIMEOUT, 10);
curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 5);
curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
curl_setopt($ch, CURLOPT_USERAGENT, 'AviationWX/1.0 (Tile Proxy)');

$tileData = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$error = curl_error($ch);

// Handle upstream errors
if ($httpCode !== 200) {
    http_response_code($httpCode);
    header('Content-Type: application/json');
    header('X-Cache: MISS');
    
    // Provide helpful error messages based on provider and status
    if ($httpCode === 401) {
        echo json_encode(['error' => 'Invalid OpenWeatherMap API key']);
    } elseif ($httpCode === 429) {
        $provider = $isRainViewer ? 'RainViewer' : 'OpenWeatherMap';
        echo json_encode(['error' => "{$provider} rate limit exceeded", 'retry_after' => 60]);
    } elseif ($httpCode === 404) {
        echo json_encode(['error' => 'Tile not found']);
    } else {
        $provider = $isRainViewer ? 'RainViewer' : 'OpenWeatherMap';
        echo json_encode(['error' => 'Upstream error', 'provider' => $provider, 'code' => $httpCode, 'message' => $error]);
    }
    exit;
}

// Validate tile data (should be PNG image)
if (strlen($tileData) < 100 || substr($tileData, 0, 4) !== "\x89PNG") {
    http_response_code(502);
    header('Content-Type: application/json');
    echo json_encode(['error' => 'Invalid tile data received from upstream']);
    exit;
}

// Ensure cache directory exists
if (!is_dir($cacheDir)) {
    @mkdir($cacheDir, 0755, true);
}

// Save tile to cache (best effort - don't fail if cache write fails)
if (is_dir($cacheDir) && is_writable($cacheDir)) {
    @file_put_contents($cachePath, $tileData);
}

// Track tile serve
$source = $isRainViewer ? 'rainviewer' : 'openweathermap';
metrics_track_tile_serve($source);

// Serve the tile
header('Content-Type: image/png');
header('Content-Length: ' . strlen($tileData));
header('Cache-Control: public, max-age=' . TILE_CACHE_TTL);
header('X-Cache: MISS');

echo $tileData;
