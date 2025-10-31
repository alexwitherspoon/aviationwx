<?php
/**
 * Webcam Image Fetcher
 * Fetches and caches webcam images from MJPEG streams
 */

require_once __DIR__ . '/config-utils.php';
require_once __DIR__ . '/rate-limit.php';

/**
 * Serve placeholder image
 */
function servePlaceholder() {
    if (file_exists(__DIR__ . '/placeholder.jpg')) {
        header('Content-Type: image/jpeg');
        header('Cache-Control: public, max-age=3600'); // Cache placeholder for 1 hour
        readfile(__DIR__ . '/placeholder.jpg');
    } else {
        header('Content-Type: image/png');
        header('Cache-Control: public, max-age=3600');
        echo base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNk+M9QDwADhgGAWjR9awAAAABJRU5ErkJggg==');
    }
    exit;
}

// Get and validate parameters
$rawAirportId = $_GET['id'] ?? '';
$camIndex = isset($_GET['cam']) ? intval($_GET['cam']) : 0;

if (empty($rawAirportId) || !validateAirportId($rawAirportId)) {
    servePlaceholder();
}

$airportId = strtolower(trim($rawAirportId));

// Validate cam index is non-negative
if ($camIndex < 0) {
    $camIndex = 0;
}

// Load config (with caching)
$config = loadConfig();
if ($config === null || !isset($config['airports'][$airportId]['webcams'][$camIndex])) {
    servePlaceholder();
}

$cam = $config['airports'][$airportId]['webcams'][$camIndex];
$cacheDir = __DIR__ . '/cache/webcams';
$base = $cacheDir . '/' . $airportId . '_' . $camIndex;
$cacheJpg = $base . '.jpg';
$cacheWebp = $base . '.webp';
$cacheAvif = $base . '.avif';

// Create cache directory if it doesn't exist
if (!is_dir($cacheDir)) {
    @mkdir($cacheDir, 0755, true);
}

// Check if requesting timestamp only (for frontend to get latest mtime)
// Exempt timestamp requests from rate limiting (they're lightweight and frequent)
if (isset($_GET['mtime']) && $_GET['mtime'] === '1') {
    header('Content-Type: application/json');
    header('Cache-Control: no-cache, no-store, must-revalidate'); // Don't cache timestamp responses
    header('Pragma: no-cache');
    header('Expires: 0');
    // Rate limit headers (for observability; mtime endpoint is not limited)
    if (function_exists('getRateLimitRemaining')) {
        $rl = getRateLimitRemaining('webcam_api', 100, 60);
        header('X-RateLimit-Limit: 100');
        header('X-RateLimit-Remaining: ' . (int)$rl['remaining']);
        header('X-RateLimit-Reset: ' . (int)$rl['reset']);
    }
    $existsJpg = file_exists($cacheJpg);
    $existsWebp = file_exists($cacheWebp);
    $existsAvif = file_exists($cacheAvif);
    $mtime = 0;
    $size = 0;
    if ($existsJpg) { $mtime = max($mtime, (int)@filemtime($cacheJpg)); $size = max($size, (int)@filesize($cacheJpg)); }
    if ($existsWebp) { $mtime = max($mtime, (int)@filemtime($cacheWebp)); $size = max($size, (int)@filesize($cacheWebp)); }
    if ($existsAvif) { $mtime = max($mtime, (int)@filemtime($cacheAvif)); $size = max($size, (int)@filesize($cacheAvif)); }
    echo json_encode([
        'success' => $mtime > 0,
        'timestamp' => $mtime,
        'size' => $size,
        'formatReady' => [
            'jpg' => $existsJpg,
            'webp' => $existsWebp,
            'avif' => $existsAvif,
        ]
    ]);
    exit;
}

// Defer rate limiting decision until after we know what we can serve
$isRateLimited = !checkRateLimit('webcam_api', 100, 60);
// Rate limit headers for image responses
if (function_exists('getRateLimitRemaining')) {
    $rl = getRateLimitRemaining('webcam_api', 100, 60);
    if (is_array($rl)) {
        header('X-RateLimit-Limit: 100');
        header('X-RateLimit-Remaining: ' . (int)$rl['remaining']);
        header('X-RateLimit-Reset: ' . (int)$rl['reset']);
    }
}

// Optional format parameter: jpg (default), webp, avif
$fmt = isset($_GET['fmt']) ? strtolower(trim($_GET['fmt'])) : 'jpg';
if (!in_array($fmt, ['jpg', 'jpeg', 'webp', 'avif'])) { 
    $fmt = 'jpg'; 
}

// Determine refresh threshold
$defaultWebcamRefresh = getenv('WEBCAM_REFRESH_DEFAULT') !== false ? intval(getenv('WEBCAM_REFRESH_DEFAULT')) : 60;
$airportWebcamRefresh = isset($config['airports'][$airportId]['webcam_refresh_seconds']) ? intval($config['airports'][$airportId]['webcam_refresh_seconds']) : $defaultWebcamRefresh;
$perCamRefresh = isset($cam['refresh_seconds']) ? intval($cam['refresh_seconds']) : $airportWebcamRefresh;

// Pick target file by requested format with fallback to jpg
$targetFile = $fmt === 'avif' ? $cacheAvif : ($fmt === 'webp' ? $cacheWebp : $cacheJpg);
if (!file_exists($targetFile)) { 
    $targetFile = $cacheJpg; 
}

// Determine content type
$ctype = (substr($targetFile, -5) === '.avif') ? 'image/avif' : ((substr($targetFile, -5) === '.webp') ? 'image/webp' : 'image/jpeg');

// If no cache exists, serve placeholder
if (!file_exists($cacheJpg)) {
    servePlaceholder();
}

// Generate immutable cache-friendly hash (for CDN compatibility)
// Use file mtime + size to create stable hash that changes only when file updates
$fileMtime = file_exists($targetFile) ? filemtime($targetFile) : 0;
$fileSize = file_exists($targetFile) ? filesize($targetFile) : 0;
$immutableHash = substr(md5($airportId . '_' . $camIndex . '_' . $fmt . '_' . $fileMtime . '_' . $fileSize), 0, 8);

// If rate limited, prefer to serve an existing cached image (even if stale) with 200
if ($isRateLimited) {
    $fallback = file_exists($targetFile) ? $targetFile : (file_exists($cacheJpg) ? $cacheJpg : null);
    if ($fallback !== null) {
        $mtime = @filemtime($fallback) ?: time();
        header('Content-Type: ' . $ctype);
        header('Cache-Control: public, max-age=0, must-revalidate');
        header('Last-Modified: ' . gmdate('D, d M Y H:i:s', $mtime) . ' GMT');
        header('X-Cache-Status: RL-SERVE'); // Served under rate limit
        header('X-RateLimit: exceeded');
        readfile($fallback);
        exit;
    }
    // As a last resort, serve placeholder with 200
    // Do NOT set 429 to avoid <img> onerror in browsers
    servePlaceholder();
}

// Common ETag builder
$etag = function(string $file): string {
    $mt = (int)@filemtime($file);
    $sz = (int)@filesize($file);
    return 'W/"' . sha1($file . '|' . $mt . '|' . $sz) . '"';
};

// Serve cached file if fresh
if (file_exists($targetFile) && (time() - filemtime($targetFile)) < $perCamRefresh) {
    $age = time() - filemtime($targetFile);
    $remainingTime = $perCamRefresh - $age;
    $mtime = filemtime($targetFile);
    $etagVal = $etag($targetFile);
    
    // Conditional requests
    $ifModSince = $_SERVER['HTTP_IF_MODIFIED_SINCE'] ?? '';
    $ifNoneMatch = $_SERVER['HTTP_IF_NONE_MATCH'] ?? '';
    if ($ifNoneMatch === $etagVal || strtotime($ifModSince ?: '1970-01-01') >= (int)$mtime) {
        header('Cache-Control: public, max-age=' . $remainingTime);
        header('ETag: ' . $etagVal);
        header('Last-Modified: ' . gmdate('D, d M Y H:i:s', $mtime) . ' GMT');
        http_response_code(304);
        exit;
    }
    
    header('Content-Type: ' . $ctype);
    header('Cache-Control: public, max-age=' . $remainingTime);
    header('Expires: ' . gmdate('D, d M Y H:i:s', time() + $remainingTime) . ' GMT');
    header('ETag: ' . $etagVal);
    header('Last-Modified: ' . gmdate('D, d M Y H:i:s', $mtime) . ' GMT');
    header('X-Cache-Status: HIT');
    header('X-Image-Timestamp: ' . $mtime); // Custom header for timestamp
    
    readfile($targetFile);
    exit;
}

// Cache expired or file not found - serve stale cache if available, otherwise placeholder
if (file_exists($targetFile)) {
    $mtime = filemtime($targetFile);
    $etagVal = $etag($targetFile);
    
    // Conditional requests for stale file
    $ifModSince = $_SERVER['HTTP_IF_MODIFIED_SINCE'] ?? '';
    $ifNoneMatch = $_SERVER['HTTP_IF_NONE_MATCH'] ?? '';
    if ($ifNoneMatch === $etagVal || strtotime($ifModSince ?: '1970-01-01') >= (int)$mtime) {
        header('Cache-Control: public, max-age=0, must-revalidate');
        header('ETag: ' . $etagVal);
        header('Last-Modified: ' . gmdate('D, d M Y H:i:s', $mtime) . ' GMT');
        http_response_code(304);
        exit;
    }
    header('Content-Type: ' . $ctype);
    header('Cache-Control: public, max-age=0, must-revalidate'); // Stale, revalidate
    header('ETag: ' . $etagVal);
    header('Last-Modified: ' . gmdate('D, d M Y H:i:s', $mtime) . ' GMT');
    header('X-Cache-Status: STALE');
    header('X-Image-Timestamp: ' . $mtime); // Custom header for timestamp
    readfile($targetFile);
} else {
    servePlaceholder();
}

