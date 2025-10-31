<?php
/**
 * Webcam Image Fetcher
 * Fetches and caches webcam images from MJPEG streams
 */

require_once __DIR__ . '/config-utils.php';
require_once __DIR__ . '/rate-limit.php';
require_once __DIR__ . '/logger.php';

// Start output buffering to prevent any stray output from corrupting image headers
ob_start();

/**
 * Serve placeholder image
 */
function servePlaceholder() {
    ob_end_clean(); // Ensure no output before headers (end and clean in one call)
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
$reqId = aviationwx_get_request_id();
header('X-Request-ID: ' . $reqId);
$rawAirportId = $_GET['id'] ?? '';
$camIndex = isset($_GET['cam']) ? intval($_GET['cam']) : 0;

if (empty($rawAirportId) || !validateAirportId($rawAirportId)) {
    aviationwx_log('error', 'webcam invalid airport id', ['id' => $rawAirportId]);
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
    aviationwx_log('error', 'webcam config missing or cam index invalid', ['airport' => $airportId, 'cam' => $camIndex]);
    servePlaceholder();
}

$cam = $config['airports'][$airportId]['webcams'][$camIndex];
$cacheDir = __DIR__ . '/cache/webcams';
$base = $cacheDir . '/' . $airportId . '_' . $camIndex;
$cacheJpg = $base . '.jpg';
$cacheWebp = $base . '.webp';
// AVIF support removed

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
    $mtime = 0;
    $size = 0;
    if ($existsJpg) { $mtime = max($mtime, (int)@filemtime($cacheJpg)); $size = max($size, (int)@filesize($cacheJpg)); }
    if ($existsWebp) { $mtime = max($mtime, (int)@filemtime($cacheWebp)); $size = max($size, (int)@filesize($cacheWebp)); }
    echo json_encode([
        'success' => $mtime > 0,
        'timestamp' => $mtime,
        'size' => $size,
        'formatReady' => [
            'jpg' => $existsJpg,
            'webp' => $existsWebp
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

// Optional format parameter: jpg (default), webp
$fmt = isset($_GET['fmt']) ? strtolower(trim($_GET['fmt'])) : 'jpg';
if (!in_array($fmt, ['jpg', 'jpeg', 'webp'])) { 
    $fmt = 'jpg'; 
}

// Determine refresh threshold
$defaultWebcamRefresh = getenv('WEBCAM_REFRESH_DEFAULT') !== false ? intval(getenv('WEBCAM_REFRESH_DEFAULT')) : 60;
$airportWebcamRefresh = isset($config['airports'][$airportId]['webcam_refresh_seconds']) ? intval($config['airports'][$airportId]['webcam_refresh_seconds']) : $defaultWebcamRefresh;
$perCamRefresh = isset($cam['refresh_seconds']) ? intval($cam['refresh_seconds']) : $airportWebcamRefresh;

// Pick target file by requested format with fallback to jpg
$targetFile = $fmt === 'webp' ? $cacheWebp : $cacheJpg;
if (!file_exists($targetFile)) { 
    $targetFile = $cacheJpg; 
}

// Determine content type
$ctype = (substr($targetFile, -5) === '.webp') ? 'image/webp' : 'image/jpeg';

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
        aviationwx_log('warning', 'webcam rate-limited, serving cached', ['airport' => $airportId, 'cam' => $camIndex, 'fmt' => $fmt]);
        $mtime = @filemtime($fallback) ?: time();
        aviationwx_maybe_log_alert();
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
    // For URLs with immutable hash (v=), allow immutable and s-maxage for CDNs
    $hasHash = isset($_GET['v']) && preg_match('/^[a-f0-9]{6,}$/i', $_GET['v']);
    $cc = $hasHash ? 'public, max-age=' . $remainingTime . ', s-maxage=' . $remainingTime . ', immutable' : 'public, max-age=' . $remainingTime;
    header('Cache-Control: ' . $cc);
    if ($hasHash) {
        header('Surrogate-Control: max-age=' . $remainingTime . ', stale-while-revalidate=60');
        header('CDN-Cache-Control: max-age=' . $remainingTime . ', stale-while-revalidate=60');
    }
    header('Expires: ' . gmdate('D, d M Y H:i:s', time() + $remainingTime) . ' GMT');
    header('ETag: ' . $etagVal);
    header('Last-Modified: ' . gmdate('D, d M Y H:i:s', $mtime) . ' GMT');
    header('X-Cache-Status: HIT');
    header('X-Image-Timestamp: ' . $mtime); // Custom header for timestamp
    
    aviationwx_log('info', 'webcam serve fresh', ['airport' => $airportId, 'cam' => $camIndex, 'fmt' => $fmt, 'age' => $age]);
    aviationwx_maybe_log_alert();
    ob_end_clean(); // Clear any buffer before sending image (end and clean in one call)
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
    $hasHash = isset($_GET['v']) && preg_match('/^[a-f0-9]{6,}$/i', $_GET['v']);
    $cc = $hasHash ? 'public, max-age=0, s-maxage=0, must-revalidate' : 'public, max-age=0, must-revalidate';
    header('Cache-Control: ' . $cc); // Stale, revalidate
    if ($hasHash) {
        header('Surrogate-Control: max-age=0');
        header('CDN-Cache-Control: max-age=0');
    }
    header('ETag: ' . $etagVal);
    header('Last-Modified: ' . gmdate('D, d M Y H:i:s', $mtime) . ' GMT');
    header('X-Cache-Status: STALE');
    header('X-Image-Timestamp: ' . $mtime); // Custom header for timestamp
    aviationwx_log('info', 'webcam serve stale', ['airport' => $airportId, 'cam' => $camIndex, 'fmt' => $fmt]);
    aviationwx_maybe_log_alert();
    ob_end_clean(); // Clear any buffer before sending image (end and clean in one call)
    readfile($targetFile);
} else {
    aviationwx_log('error', 'webcam no cache, serving placeholder', ['airport' => $airportId, 'cam' => $camIndex, 'fmt' => $fmt]);
    servePlaceholder();
}

