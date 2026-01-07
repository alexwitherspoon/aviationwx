<?php
/**
 * Webcam Image Server with Background Refresh
 * 
 * Implements stale-while-revalidate pattern for optimal performance:
 * 1. If cache is fresh: serve immediately
 * 2. If cache is stale: serve stale cache immediately, trigger background refresh
 * 3. Background refresh uses file locking to prevent concurrent refreshes
 * 
 * This ensures fast response times while keeping data fresh. Similar pattern
 * to weather.php's stale-while-revalidate implementation.
 */

// Required libraries - always load these for function definitions
require_once __DIR__ . '/../lib/config.php';
require_once __DIR__ . '/../lib/rate-limit.php';
require_once __DIR__ . '/../lib/logger.php';
require_once __DIR__ . '/../lib/constants.php';
require_once __DIR__ . '/../lib/circuit-breaker.php';
require_once __DIR__ . '/../lib/webcam-format-generation.php';
require_once __DIR__ . '/../lib/webcam-metadata.php';
require_once __DIR__ . '/../lib/metrics.php';
require_once __DIR__ . '/../lib/cache-headers.php';
require_once __DIR__ . '/../scripts/fetch-webcam.php';

// Check early if we're being included for testing vs. direct API access
// Tests include this file to get the helper functions but shouldn't run API logic
$_webcamApiDirectAccess = (
    php_sapi_name() !== 'cli' ||
    (isset($_SERVER['SCRIPT_FILENAME']) && realpath($_SERVER['SCRIPT_FILENAME']) === realpath(__FILE__))
);

// Only do output buffering and headers when running as API endpoint
if ($_webcamApiDirectAccess) {
    // Start output buffering IMMEDIATELY to catch any output from included files
    if (!ob_get_level()) {
        ob_start();
    } else {
        ob_clean();
    }

    // Clear any output that may have been captured from included files
    if (ob_get_level() > 0 && !headers_sent()) {
        ob_clean();
    }

    // Determine if we're serving JSON (mtime=1) or image early
    $isJsonRequest = isset($_GET['mtime']) && $_GET['mtime'] === '1';

    // Set Content-Type IMMEDIATELY based on request type
    if (!headers_sent()) {
        if ($isJsonRequest) {
            header('Content-Type: application/json', true);
        } else {
            header('Content-Type: image/jpeg', true);
        }
    }
}

/**
 * Serve JSON error response for mtime=1 requests, or placeholder image otherwise
 * 
 * This ensures mtime=1 requests always receive JSON responses, even for errors.
 * Non-mtime requests receive the standard placeholder image.
 * 
 * @param string $errorCode Error code for the response
 * @param string $message Human-readable error message
 * @return void
 */
function serveJsonErrorOrPlaceholder(string $errorCode, string $message = '') {
    if (isset($_GET['mtime']) && $_GET['mtime'] === '1') {
        // Clear any existing output buffer
        if (ob_get_level() > 0 && !headers_sent()) {
            ob_clean();
        }
        header('Content-Type: application/json');
        header('Cache-Control: no-cache, no-store, must-revalidate');
        echo json_encode([
            'success' => false,
            'error' => $errorCode,
            'message' => $message ?: $errorCode,
            'timestamp' => 0,
            'size' => 0,
            'formatReady' => new stdClass()
        ]);
        exit;
    }
    servePlaceholder();
}

/**
 * Serve placeholder image
 * 
 * Serves an optimized placeholder image when webcam is unavailable or invalid.
 * Selects format based on browser Accept header (WebP > JPEG).
 * Falls back to JPEG if optimized formats unavailable, or 1x1 transparent PNG if all fail.
 * Sets appropriate cache headers for placeholder images.
 * 
 * @return void
 */
function servePlaceholder() {
    // Clean output buffer if active and headers not sent
    if (ob_get_level() > 0 && !headers_sent()) {
        @ob_end_clean();
    }
    
    // Only set headers if they haven't been sent yet
    if (!headers_sent()) {
        $imagesDir = __DIR__ . '/../public/images';
        
        // Determine preferred format based on Accept header (same logic as webcam images)
        $acceptHeader = $_SERVER['HTTP_ACCEPT'] ?? '';
        $preferredFormat = 'jpg'; // Default fallback
        if (stripos($acceptHeader, 'image/webp') !== false) {
            $preferredFormat = 'webp';
        }
        
        // Try to serve preferred format, fallback to JPEG if not available
        $placeholderPath = null;
        $contentType = 'image/jpeg';
        
        if ($preferredFormat === 'webp') {
            $webpPath = $imagesDir . '/placeholder.webp';
            if (file_exists($webpPath) && filesize($webpPath) > 0) {
                $placeholderPath = $webpPath;
                $contentType = 'image/webp';
            }
        }
        
        // Fallback to JPEG (always available)
        if ($placeholderPath === null) {
            $jpegPath = $imagesDir . '/placeholder.jpg';
            if (file_exists($jpegPath) && filesize($jpegPath) > 0) {
                $placeholderPath = $jpegPath;
                $contentType = 'image/jpeg';
            }
        }
        
        // Serve placeholder if found
        if ($placeholderPath !== null && file_exists($placeholderPath)) {
            $size = @filesize($placeholderPath);
            if ($size > 0) {
                header('Content-Type: ' . $contentType);
                header('Cache-Control: public, max-age=' . PLACEHOLDER_CACHE_TTL);
                header('X-Cache-Status: MISS'); // Placeholder served - no cache
                header('Content-Length: ' . $size);
                $result = @readfile($placeholderPath);
                if ($result !== false) {
                    exit;
                }
            }
        }
        
        // Fallback to base64 1x1 transparent PNG if all else fails
        header('Content-Type: image/png');
        header('Cache-Control: public, max-age=' . PLACEHOLDER_CACHE_TTL);
        header('X-Cache-Status: MISS');
        header('Content-Length: 95');
        echo base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNk+M9QDwADhgGAWjR9awAAAABJRU5ErkJggg==');
    } else {
        // Headers already sent - log warning in non-CLI mode
        if (php_sapi_name() !== 'cli') {
            error_log('Warning: Headers already sent, cannot serve placeholder image in webcam.php');
        }
        // Still try to output placeholder image data if possible
        $imagesDir = __DIR__ . '/../public/images';
        $jpegPath = $imagesDir . '/placeholder.jpg';
        if (file_exists($jpegPath)) {
            @readfile($jpegPath);
        } else {
            echo base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNk+M9QDwADhgGAWjR9awAAAABJRU5ErkJggg==');
        }
    }
    exit;
}

/**
 * Safely serve a file with error handling
 * @param string $filePath Path to file to serve
 * @param string $contentType Content-Type header value
 * @return bool True if file was served successfully, false otherwise
 */
function serveFile($filePath, $contentType) {
    // Open file handle to prevent race conditions
    $fp = @fopen($filePath, 'rb');
    if ($fp === false) {
        return false;
    }
    
    // Get file size from open handle (atomic operation)
    $stat = @fstat($fp);
    $size = ($stat !== false && isset($stat['size'])) ? $stat['size'] : false;
    if ($size === false) {
        // Fallback to filesize if fstat fails
        $size = @filesize($filePath);
    }
    if ($size === false || $size === 0) {
        @fclose($fp);
        return false;
    }
    
    // Set headers
    header('Content-Type: ' . $contentType, true);
    header('Content-Length: ' . $size);
    
    // Clear output buffer and send file
    if (ob_get_level() > 0) {
        @ob_end_clean();
    }
    
    // Stream file in chunks to handle large files efficiently
    $chunkSize = 8192; // 8KB chunks
    $bytesSent = 0;
    while (!feof($fp) && $bytesSent < $size) {
        $chunk = @fread($fp, $chunkSize);
        if ($chunk === false) {
            @fclose($fp);
            return false;
        }
        echo $chunk;
        $bytesSent += strlen($chunk);
        
        // Flush output periodically for large files
        if ($bytesSent % (1024 * 1024) === 0) { // Every 1MB
            @flush();
        }
    }
    
    @fclose($fp);
    @flush();
    return true;
}

// =============================================================================
// API EXECUTION - Only runs when accessed directly, not when included for tests
// =============================================================================
if (!$_webcamApiDirectAccess) {
    return; // Stop execution here when included for testing
}

// Get and validate parameters
$reqId = aviationwx_get_request_id();

// Set request ID after Content-Type to ensure it's not overridden
header('X-Request-ID: ' . $reqId);

// Support both 'id' and 'airport' parameters for backward compatibility
$rawIdentifier = $_GET['id'] ?? $_GET['airport'] ?? '';
// Support both 'cam' and 'index' parameters for backward compatibility
$camIndex = isset($_GET['cam']) ? intval($_GET['cam']) : (isset($_GET['index']) ? intval($_GET['index']) : 0);
// Support timestamp parameter for timestamp-based URLs (immutable cache busting)
$requestedTimestamp = isset($_GET['ts']) ? intval($_GET['ts']) : null;
// Support size parameter for variant selection (height in pixels or "original")
$requestedSize = isset($_GET['size']) ? trim($_GET['size']) : 'original';
// Validate size parameter: "original" or numeric height (1-5000)
if ($requestedSize === 'original') {
    // Valid - keep as is
} elseif (is_numeric($requestedSize)) {
    $requestedSize = (int)$requestedSize;
    if ($requestedSize < 1 || $requestedSize > 5000) {
        // Invalid height - default to original
        $requestedSize = 'original';
    }
} else {
    // Invalid - default to original
    $requestedSize = 'original';
}

if (empty($rawIdentifier)) {
    aviationwx_log('error', 'webcam missing airport identifier', [], 'user');
    serveJsonErrorOrPlaceholder('missing_airport', 'Airport identifier is required');
}

// Find airport by any identifier type (ICAO, IATA, FAA, or airport ID)
$result = findAirportByIdentifier($rawIdentifier);
if ($result === null || !isset($result['airport']) || !isset($result['airportId'])) {
    aviationwx_log('error', 'webcam airport not found', ['identifier' => $rawIdentifier], 'user');
    serveJsonErrorOrPlaceholder('airport_not_found', 'Airport not found: ' . $rawIdentifier);
}

$airport = $result['airport'];
$airportId = $result['airportId'];
    
// Check if airport is enabled (opt-in model: must have enabled: true)
if (!isAirportEnabled($airport)) {
    aviationwx_log('error', 'webcam airport not enabled', ['identifier' => $rawIdentifier, 'airport_id' => $airportId], 'user');
    serveJsonErrorOrPlaceholder('airport_not_enabled', 'Airport is not enabled: ' . $rawIdentifier);
}

// Load config for webcam access
$config = loadConfig();
if ($config === null) {
    aviationwx_log('error', 'webcam config load failed', [], 'app');
    serveJsonErrorOrPlaceholder('config_error', 'Failed to load configuration');
}

// Validate cam index is non-negative
if ($camIndex < 0) {
    aviationwx_log('error', 'webcam invalid camera index', [
        'identifier' => $rawIdentifier, 
        'cam' => $_GET['cam'] ?? $_GET['index'] ?? 'not set'
    ], 'user');
    serveJsonErrorOrPlaceholder('invalid_camera_index', 'Camera index must be a non-negative integer');
}
// Upper bound will be validated after config is loaded

// Validate cam index is within bounds
$maxCamIndex = isset($config['airports'][$airportId]['webcams']) 
    ? max(0, count($config['airports'][$airportId]['webcams']) - 1) 
    : -1;
if ($camIndex > $maxCamIndex || !isset($config['airports'][$airportId]['webcams'][$camIndex])) {
    aviationwx_log('warning', 'webcam cam index out of bounds', [
        'airport' => $airportId,
        'cam' => $camIndex,
        'max' => $maxCamIndex
    ], 'user');
    serveJsonErrorOrPlaceholder('camera_not_found', 'Camera index ' . $camIndex . ' not found for airport ' . $airportId);
}

$cam = $config['airports'][$airportId]['webcams'][$camIndex];

// Track webcam API request (all requests, before cache/serve decisions)
metrics_track_webcam_request($airportId, $camIndex);

$cacheDir = CACHE_WEBCAMS_DIR;
$cacheJpg = getCacheFile($airportId, $camIndex, 'jpg', 'original');
$cacheWebp = getCacheFile($airportId, $camIndex, 'webp', 'original');

// Create cache directory if it doesn't exist
// Check parent directory first, then create with proper error handling
$parentDir = CACHE_BASE_DIR;
$currentUser = function_exists('posix_geteuid') ? posix_geteuid() : null;
$currentUserInfo = $currentUser !== null && function_exists('posix_getpwuid') ? posix_getpwuid($currentUser) : null;

if (!is_dir($parentDir)) {
    // Try to create parent directory first
    $parentCreated = @mkdir($parentDir, 0755, true);
    if (!$parentCreated) {
        $error = error_get_last();
        aviationwx_log('error', 'webcam cache parent directory creation failed', [
            'parent_dir' => $parentDir,
            'cache_dir' => $cacheDir,
            'current_user' => $currentUserInfo['name'] ?? 'unknown',
            'current_uid' => $currentUser,
            'parent_exists' => is_dir($parentDir),
            'parent_writable' => is_dir($parentDir) ? is_writable($parentDir) : false,
            'error' => $error['message'] ?? 'unknown',
            'error_file' => $error['file'] ?? null,
            'error_line' => $error['line'] ?? null
        ], 'app');
    }
}

if (!is_dir($cacheDir)) {
    $created = @mkdir($cacheDir, 0755, true);
    if (!$created) {
        $error = error_get_last();
        $parentExists = is_dir($parentDir);
        $parentWritable = $parentExists ? is_writable($parentDir) : false;
        $parentPerms = $parentExists ? substr(sprintf('%o', @fileperms($parentDir)), -4) : 'N/A';
        
        aviationwx_log('error', 'webcam cache directory creation failed', [
            'cache_dir' => $cacheDir,
            'parent_dir' => $parentDir,
            'parent_exists' => $parentExists,
            'parent_writable' => $parentWritable,
            'parent_perms' => $parentPerms,
            'current_user' => $currentUserInfo['name'] ?? 'unknown',
            'current_uid' => $currentUser,
            'current_gid' => function_exists('posix_getegid') ? posix_getegid() : null,
            'error' => $error['message'] ?? 'unknown',
            'error_file' => $error['file'] ?? null,
            'error_line' => $error['line'] ?? null,
            'tmp_writable' => strpos($cacheDir, '/tmp') === 0 ? is_writable('/tmp') : null
        ], 'app');
        // Continue anyway - servePlaceholder will handle missing cache
    } else {
        // Verify directory was created and is writable
        if (!is_writable($cacheDir)) {
            $dirPerms = substr(sprintf('%o', @fileperms($cacheDir)), -4);
            $dirOwner = function_exists('posix_getpwuid') ? posix_getpwuid(@fileowner($cacheDir)) : null;
            aviationwx_log('warning', 'webcam cache directory created but not writable', [
                'cache_dir' => $cacheDir,
                'perms' => $dirPerms,
                'owner' => $dirOwner['name'] ?? 'unknown',
                'owner_uid' => @fileowner($cacheDir),
                'current_uid' => $currentUser
            ], 'app');
        }
    }
}

// Check if requesting variant discovery
if (isset($_GET['variants']) && $_GET['variants'] === '1') {
    $timestamp = getLatestImageTimestamp($airportId, $camIndex);
    if ($timestamp === 0) {
        http_response_code(404);
        header('Content-Type: application/json');
        echo json_encode([
            'error' => 'no_image',
            'message' => 'No image available for this camera',
            'variants' => []
        ]);
        exit;
    }
    
    $variants = getAvailableVariants($airportId, $camIndex, $timestamp);
    
    header('Content-Type: application/json');
    header('Cache-Control: public, max-age=60'); // Cache for 1 minute
    echo json_encode([
        'timestamp' => $timestamp,
        'variants' => $variants
    ]);
    exit;
}

// Check if requesting timestamp only (for frontend to get latest mtime)
// Exempt timestamp requests from rate limiting (they're lightweight and frequent)
if (isset($_GET['mtime']) && $_GET['mtime'] === '1') {
    // Content-Type already set earlier for JSON requests
    
    // Get webcam refresh interval (from camera config, airport config, or default)
    // Use ACTUAL refresh interval (not clamped to 60s) for cache calculation
    // The 60s minimum is for cron scheduling, but cache headers should match actual refresh rate
    $webcamRefresh = getDefaultWebcamRefresh();
    if (isset($airport['webcam_refresh_seconds'])) {
        $webcamRefresh = intval($airport['webcam_refresh_seconds']);
    }
    if (isset($cam['refresh_seconds'])) {
        $webcamRefresh = intval($cam['refresh_seconds']);
    }
    
    // Browser cache disabled (max-age=0) since timestamp checks need fresh data
    // CDN cache uses half refresh interval (min 5s) to support fast refreshes
    $headers = generateCacheHeaders($webcamRefresh, null);
    foreach ($headers as $name => $value) {
        header($name . ': ' . $value);
    }
    // Rate limit headers (for observability; mtime endpoint is not limited)
    $rl = getRateLimitRemaining('webcam_api', RATE_LIMIT_WEBCAM_MAX, RATE_LIMIT_WEBCAM_WINDOW);
    if ($rl !== null) {
        header('X-RateLimit-Limit: ' . RATE_LIMIT_WEBCAM_MAX);
        header('X-RateLimit-Remaining: ' . (int)$rl['remaining']);
        header('X-RateLimit-Reset: ' . (int)$rl['reset']);
    }
    // Get latest timestamp
    $timestamp = getLatestImageTimestamp($airportId, $camIndex);
    
    if ($timestamp === 0) {
        echo json_encode([
            'success' => false,
            'timestamp' => 0,
            'size' => 0,
            'formatReady' => [],
            'enabledFormats' => getEnabledWebcamFormats(),
            'variants' => []
        ]);
        exit;
    }
    
    // Get available variants
    $variants = getAvailableVariants($airportId, $camIndex, $timestamp);
    
    // Extract variant heights for easier JavaScript access
    $variantHeights = [];
    foreach ($variants as $variant => $formats) {
        if ($variant !== 'original' && is_numeric($variant)) {
            $variantHeights[] = (int)$variant;
        }
    }
    rsort($variantHeights); // Descending order
    
    // Check format availability for requested size
    $enabledFormats = getEnabledWebcamFormats();
    $formatReady = [];
    $size = 0;
    
    if ($requestedSize === 'original') {
        foreach ($enabledFormats as $format) {
            $path = getImagePathForSize($airportId, $camIndex, $timestamp, 'original', $format);
            $formatReady[$format] = $path !== null;
            if ($path !== null) {
                $fileSize = @filesize($path);
                if ($fileSize !== false) {
                    $size = max($size, $fileSize);
                }
            }
        }
    } else {
        foreach ($enabledFormats as $format) {
            $path = getImagePathForSize($airportId, $camIndex, $timestamp, $requestedSize, $format);
            $formatReady[$format] = $path !== null;
            if ($path !== null) {
                $fileSize = @filesize($path);
                if ($fileSize !== false) {
                    $size = max($size, $fileSize);
                }
            }
        }
    }
    
    echo json_encode([
        'success' => $timestamp > 0,
        'timestamp' => $timestamp,
        'size' => $size,
        'formatReady' => $formatReady,
        'enabledFormats' => $enabledFormats,
        'variants' => $variants,
        'variantHeights' => $variantHeights
    ]);
    exit;
}

// Defer rate limiting decision until after we know what we can serve
$isRateLimited = !checkRateLimit('webcam_api', RATE_LIMIT_WEBCAM_MAX, RATE_LIMIT_WEBCAM_WINDOW);
// Rate limit headers for image responses
$rl = getRateLimitRemaining('webcam_api', RATE_LIMIT_WEBCAM_MAX, RATE_LIMIT_WEBCAM_WINDOW);
if ($rl !== null) {
    header('X-RateLimit-Limit: ' . RATE_LIMIT_WEBCAM_MAX);
    header('X-RateLimit-Remaining: ' . (int)$rl['remaining']);
    header('X-RateLimit-Reset: ' . (int)$rl['reset']);
}


// Parse format parameter (if specified)
$fmt = isset($_GET['fmt']) ? strtolower(trim($_GET['fmt'])) : null;
if ($fmt !== null && !in_array($fmt, ['jpg', 'jpeg', 'webp'])) {
    $fmt = null; // Invalid fmt, treat as unspecified
}

// Check Accept header for format support if fmt not specified
$acceptsWebp = false;
if ($fmt === null) {
    $acceptHeader = $_SERVER['HTTP_ACCEPT'] ?? '';
    $acceptsWebp = (stripos($acceptHeader, 'image/webp') !== false);
}

// Determine preferred format based on priority:
// 1. Explicit fmt parameter (if file exists)
// 2. Accept header (WebP > JPEG)
// 3. Fallback to JPG
$preferredFormat = null;
if ($fmt === 'webp') {
    $preferredFormat = 'webp';
} elseif ($fmt === 'jpg' || $fmt === 'jpeg') {
    $preferredFormat = 'jpg';
} elseif ($acceptsWebp) {
    $preferredFormat = 'webp';
} else {
    $preferredFormat = 'jpg'; // Default fallback
}

// Determine refresh threshold
$defaultWebcamRefresh = getDefaultWebcamRefresh();
$airportWebcamRefresh = isset($config['airports'][$airportId]['webcam_refresh_seconds']) ? intval($config['airports'][$airportId]['webcam_refresh_seconds']) : $defaultWebcamRefresh;
$perCamRefresh = isset($cam['refresh_seconds']) ? intval($cam['refresh_seconds']) : $airportWebcamRefresh;
$perCamRefresh = max(60, $perCamRefresh); // Enforce minimum 60 seconds (cron constraint)

/**
 * Extract actual image capture timestamp from EXIF data
 * 
 * Attempts to read EXIF DateTimeOriginal from JPEG images.
 * Falls back to file modification time if EXIF is not available.
 * 
 * @param string $filePath Path to image file
 * @return int Unix timestamp, or 0 if unable to determine
 */
function getImageCaptureTime($filePath) {
    // Try to read EXIF data from JPEG files
    if (function_exists('exif_read_data') && file_exists($filePath)) {
        // Use @ to suppress errors for non-critical EXIF operations
        // We handle failures explicitly with fallback to filemtime below
        $exif = @exif_read_data($filePath, 'EXIF', true);
        if ($exif !== false && isset($exif['EXIF']['DateTimeOriginal'])) {
            $dateTime = $exif['EXIF']['DateTimeOriginal'];
            // Parse EXIF date format: "YYYY:MM:DD HH:MM:SS"
            // Our pipeline writes EXIF in UTC, so parse as UTC
            $timestamp = @strtotime(str_replace(':', '-', substr($dateTime, 0, 10)) . ' ' . substr($dateTime, 11) . ' UTC');
            if ($timestamp !== false && $timestamp > 0) {
                return (int)$timestamp;
            }
        }
        // Also check main EXIF array (some cameras store it there)
        if (isset($exif['DateTimeOriginal'])) {
            $dateTime = $exif['DateTimeOriginal'];
            // Our pipeline writes EXIF in UTC, so parse as UTC
            $timestamp = @strtotime(str_replace(':', '-', substr($dateTime, 0, 10)) . ' ' . substr($dateTime, 11) . ' UTC');
            if ($timestamp !== false && $timestamp > 0) {
                return (int)$timestamp;
            }
        }
    }
    
    // Fallback to file modification time
    // Use @ to suppress errors for non-critical file operations
    // We handle failures explicitly by returning 0
    $mtime = @filemtime($filePath);
    return $mtime !== false ? (int)$mtime : 0;
}

/**
 * Build webcam URL with hash
 * 
 * @param string $airportId Airport ID
 * @param int $camIndex Camera index
 * @param string $format Format: 'jpg' or 'webp'
 * @param int $timestamp Image timestamp
 * @return string Webcam URL
 */
function buildWebcamUrl(string $airportId, int $camIndex, string $format, int $timestamp): string {
    $protocol = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || 
                (isset($_SERVER['HTTP_X_FORWARDED_PROTO']) && $_SERVER['HTTP_X_FORWARDED_PROTO'] === 'https') 
                ? 'https' : 'http';
    $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
    $hash = substr(md5($airportId . '_' . $camIndex . '_' . $format . '_' . $timestamp), 0, 8);
    return "{$protocol}://{$host}/webcam.php?id=" . urlencode($airportId) . "&cam={$camIndex}&fmt={$format}&v={$hash}";
}

/**
 * Get refresh interval for camera
 * 
 * @param string $airportId Airport ID
 * @param array $config Config array
 * @param array $cam Camera config array
 * @return int Refresh interval in seconds
 */
function getRefreshIntervalForCamera(string $airportId, array $config, array $cam): int {
    $defaultWebcamRefresh = getDefaultWebcamRefresh();
    $airportWebcamRefresh = isset($config['airports'][$airportId]['webcam_refresh_seconds']) 
        ? intval($config['airports'][$airportId]['webcam_refresh_seconds']) 
        : $defaultWebcamRefresh;
    $perCamRefresh = isset($cam['refresh_seconds']) ? intval($cam['refresh_seconds']) : $airportWebcamRefresh;
    return max(60, $perCamRefresh); // Enforce minimum 60 seconds (cron constraint)
}

/**
 * Get MIME type for format
 * 
 * @param string $format Format: 'jpg' or 'webp'
 * @return string MIME type
 */
function getMimeTypeForFormat(string $format): string {
    switch ($format) {
        case 'webp':
            return 'image/webp';
        case 'jpg':
        case 'jpeg':
        default:
            return 'image/jpeg';
    }
}

// getLatestImageTimestamp() moved to lib/webcam-metadata.php

/**
 * Check if JPEG timestamp is from current refresh cycle
 * 
 * Uses refresh interval to determine if image is from current cycle.
 * Cron runs at most once per minute, so refresh intervals are typically 60+ seconds.
 * 
 * @param int $jpegMtime JPEG capture timestamp (EXIF or filemtime)
 * @param int $refreshInterval Refresh interval in seconds (from config)
 * @return bool True if from current cycle, false if stale
 */
function isFromCurrentRefreshCycle(int $jpegMtime, int $refreshInterval): bool {
    $cacheAge = time() - $jpegMtime;
    return $cacheAge < $refreshInterval;
}

/**
 * Check if all formats are from the same stale cycle
 * 
 * Used to detect when webcam source has failed and all formats are stale.
 * In this case, serve most efficient format available (not 202).
 * 
 * @param array $formatStatus Status array from getFormatStatus()
 * @param int $jpegMtime JPEG capture timestamp
 * @param int $refreshInterval Refresh interval in seconds
 * @return bool True if all formats from same stale cycle
 */
function areAllFormatsFromSameCycle(array $formatStatus, int $jpegMtime, int $refreshInterval): bool {
    // JPEG must be stale (not current cycle)
    if (isFromCurrentRefreshCycle($jpegMtime, $refreshInterval)) {
        return false;
    }
    
    // Check if other formats exist and are from same cycle
    $jpegAge = time() - $jpegMtime;
    $tolerance = min($refreshInterval * 0.1, 60); // 10% of interval, max 60s
    
    if ($formatStatus['webp']['exists'] && $formatStatus['webp']['mtime'] > 0) {
        $webpAge = time() - $formatStatus['webp']['mtime'];
        if (abs($jpegAge - $webpAge) > $tolerance) {
            return false; // Different cycles
        }
    }
    
    return true; // All from same stale cycle
}

/**
 * Get format status with optimized file I/O
 * 
 * Uses single stat() call per file instead of multiple file_exists/filesize/filemtime calls.
 * Reduces file system operations from 11+ to 2.
 * 
 * @param string $airportId Airport ID
 * @param int $camIndex Camera index
 * @return array Status array with keys: jpg, webp (each with exists, size, mtime, valid)
 */
function getFormatStatus(string $airportId, int $camIndex, string|int $variant = 'original'): array {
    $cacheJpg = getCacheFile($airportId, $camIndex, 'jpg', $variant);
    $cacheWebp = getCacheFile($airportId, $camIndex, 'webp', $variant);
    
    // Single stat() call per file (returns size, mtime, etc.)
    $jpgStat = @stat($cacheJpg);
    $webpStat = @stat($cacheWebp);
    
    // Helper to validate JPEG
    $isValidJpeg = function($filePath) {
        if (!file_exists($filePath)) return false;
        $fp = @fopen($filePath, 'rb');
        if (!$fp) return false;
        $header = @fread($fp, 3);
        @fclose($fp);
        return substr($header, 0, 3) === "\xFF\xD8\xFF";
    };
    
    // Helper to validate WebP
    $isValidWebp = function($filePath) {
        if (!file_exists($filePath)) return false;
        $fp = @fopen($filePath, 'rb');
        if (!$fp) return false;
        $header = @fread($fp, 12);
        @fclose($fp);
        return substr($header, 0, 4) === 'RIFF' && strpos($header, 'WEBP') !== false;
    };
    
    $webpValidResult = $webpStat && $webpStat['size'] > 0 ? $isValidWebp($cacheWebp) : false;
    
    return [
        'jpg' => [
            'file' => $cacheJpg,
            'exists' => $jpgStat !== false,
            'size' => $jpgStat ? $jpgStat['size'] : 0,
            'mtime' => $jpgStat ? $jpgStat['mtime'] : 0,
            'valid' => $jpgStat && $jpgStat['size'] > 0 && $isValidJpeg($cacheJpg)
        ],
        'webp' => [
            'file' => $cacheWebp,
            'exists' => $webpStat !== false,
            'size' => $webpStat ? $webpStat['size'] : 0,
            'mtime' => $webpStat ? $webpStat['mtime'] : 0,
            'valid' => $webpValidResult
        ]
    ];
}

/**
 * Check if format is currently generating
 * 
 * Format is generating if:
 * - JPEG is from current cycle (source exists)
 * - Format file doesn't exist or is incomplete
 * - Format file mtime < JPEG mtime (if format exists)
 * 
 * Also logs generation result when format becomes available or fails.
 * 
 * @param string $format Format to check: 'webp'
 * @param array $formatStatus Status array from getFormatStatus()
 * @param int $jpegMtime JPEG capture timestamp
 * @param int $refreshInterval Refresh interval in seconds
 * @param string $airportId Airport ID (for logging)
 * @param int $camIndex Camera index (for logging)
 * @return bool True if format is generating, false otherwise
 */
function isFormatGenerating(string $format, array $formatStatus, int $jpegMtime, int $refreshInterval, string $airportId, int $camIndex): bool {
    // Must be from current cycle
    if (!isFromCurrentRefreshCycle($jpegMtime, $refreshInterval)) {
        // Old cycle - not generating, likely failed
        // Log generation failure if format is missing from old cycle
        $formatData = $formatStatus[$format];
        if (!$formatData['valid']) {
            aviationwx_log('warning', 'webcam format generation job result - failed (old cycle)', [
                'airport' => $airportId,
                'cam' => $camIndex,
                'format' => $format,
                'jpeg_timestamp' => $jpegMtime,
                'cache_age' => time() - $jpegMtime,
                'refresh_interval' => $refreshInterval,
                'result' => 'failed',
                'reason' => 'format_missing_from_old_cycle',
                'troubleshooting' => 'Format generation likely failed. Check ffmpeg availability, disk space, and file permissions.'
            ], 'app');
        }
        return false;
    }
    
    $formatData = $formatStatus[$format];
    
    // Format doesn't exist or is incomplete
    if (!$formatData['exists'] || $formatData['size'] === 0) {
        return true; // Generating
    }
    
    // Format exists but is older than JPEG (shouldn't happen with mtime sync, but check)
    if ($formatData['mtime'] > 0 && $formatData['mtime'] < $jpegMtime) {
        return true; // Still generating
    }
    
    // Format is ready - log success (only log once per cycle to avoid spam)
    if ($formatData['valid']) {
        // Check if we've already logged success for this cycle (avoid duplicate logs)
        static $loggedSuccess = [];
        $logKey = "{$airportId}_{$camIndex}_{$format}_{$jpegMtime}";
        if (!isset($loggedSuccess[$logKey])) {
            aviationwx_log('info', 'webcam format generation job result - success', [
                'airport' => $airportId,
                'cam' => $camIndex,
                'format' => $format,
                'file_size' => $formatData['size'],
                'mtime' => $formatData['mtime'],
                'result' => 'success'
            ], 'app');
            $loggedSuccess[$logKey] = true;
        }
    }
    
    return false; // Format ready
}

/**
 * Find most efficient format available
 * 
 * Priority: WebP > JPEG (most efficient first)
 * Only considers formats that are enabled in config.
 * 
 * @param array $formatStatus Status array from getFormatStatus()
 * @param string $airportId Airport ID
 * @param int $camIndex Camera index
 * @return array|null Format data array with keys: file, type, priority, or null if none available
 */
function findMostEfficientFormat(array $formatStatus, string $airportId, int $camIndex, string $variant = 'primary'): ?array {
    $enabledFormats = getEnabledWebcamFormats();
    $candidates = [];
    
    // Priority order: WebP (1) > JPEG (2)
    if (in_array('webp', $enabledFormats) && $formatStatus['webp']['valid']) {
        $candidates[] = [
            'file' => getCacheFile($airportId, $camIndex, 'webp', $variant),
            'type' => 'image/webp',
            'priority' => 1
        ];
    }
    
    if (in_array('jpg', $enabledFormats) && $formatStatus['jpg']['valid']) {
        $candidates[] = [
            'file' => getCacheFile($airportId, $camIndex, 'jpg', $variant),
            'type' => 'image/jpeg',
            'priority' => 2
        ];
    }
    
    if (empty($candidates)) {
        return null;
    }
    
    // Sort by priority (lower = better)
    usort($candidates, function($a, $b) {
        return $a['priority'] - $b['priority'];
    });
    
    return $candidates[0];
}

/**
 * Send HTTP 202 response for format generating
 * 
 * Returns 202 Accepted with JSON body indicating format is generating.
 * Client can wait briefly or use fallback immediately.
 * 
 * @param string $preferredFormat Format that's generating: 'webp'
 * @param int $jpegMtime JPEG capture timestamp
 * @param int $refreshInterval Refresh interval in seconds
 * @param string $airportId Airport ID
 * @param int $camIndex Camera index
 * @return void
 */
function serve202Response(string $preferredFormat, int $jpegMtime, int $refreshInterval, string $airportId, int $camIndex): void {
    // Fixed 5 second estimate (format generation typically takes 2-10 seconds)
    $estimatedReady = 5;
    
    $fallbackUrl = buildWebcamUrl($airportId, $camIndex, 'jpg', $jpegMtime);
    $preferredUrl = buildWebcamUrl($airportId, $camIndex, $preferredFormat, $jpegMtime);
    
    http_response_code(202);
    header('Content-Type: application/json');
    header('Retry-After: ' . $estimatedReady);
    header('X-Format-Generating: ' . $preferredFormat);
    header('X-Fallback-URL: ' . $fallbackUrl);
    header('X-Preferred-Format-URL: ' . $preferredUrl);
    header('Cache-Control: no-cache, no-store, must-revalidate, max-age=0');
    header('Pragma: no-cache');
    header('Expires: 0');
    header('Vary: Accept'); // CDN should vary cache by Accept header
    
    echo json_encode([
        'status' => 'generating',
        'format' => $preferredFormat,
        'estimated_ready_seconds' => $estimatedReady,
        'fallback_url' => $fallbackUrl,
        'preferred_url' => $preferredUrl,
        'jpeg_timestamp' => $jpegMtime,
        'refresh_interval' => $refreshInterval
    ]);
    exit;
}

/**
 * Serve 200 OK response with cache headers
 * 
 * @param string $filePath Path to image file
 * @param string $contentType Content-Type header
 * @param int|null $mtime Optional file modification time (for cache headers)
 * @param int|null $refreshInterval Optional refresh interval (for cache headers)
 * @param bool $exitAfterServe Whether to exit after serving (default: true)
 * @return void
 */
function serve200Response(string $filePath, string $contentType, ?int $mtime = null, ?int $refreshInterval = null, bool $exitAfterServe = true): void {
    // Access global variables for logging and metrics
    global $airportId, $camIndex, $requestedSize, $latestTimestamp;
    
    // Track webcam metrics (only once per request)
    static $metricsTracked = false;
    if (!$metricsTracked && !empty($airportId)) {
        $metricsTracked = true;
        
        // Determine format from content type
        $format = 'jpg';
        if ($contentType === 'image/webp') {
            $format = 'webp';
        }
        
        // Track webcam serve
        $size = $requestedSize ?? 'primary';
        metrics_track_webcam_serve($airportId, $camIndex, $format, $size);
        
        // Track browser format support (based on Accept header)
        $acceptHeader = $_SERVER['HTTP_ACCEPT'] ?? '';
        $supportsWebp = (stripos($acceptHeader, 'image/webp') !== false);
        metrics_track_format_support(false, $supportsWebp);
    }
    
    // Check rate limiting (re-check since we can't easily pass the variable)
    $isRateLimited = !checkRateLimit('webcam_api', RATE_LIMIT_WEBCAM_MAX, RATE_LIMIT_WEBCAM_WINDOW);
    
    // Get file metadata if not provided
    if ($mtime === null) {
        $mtime = @filemtime($filePath);
        if ($mtime === false) {
            $mtime = time();
        }
    }
    
    // Build filename matching server naming convention: {timestamp}_{variant}.{ext}
    $timestamp = $mtime;
    if (isset($latestTimestamp) && $latestTimestamp > 0) {
        $timestamp = $latestTimestamp;
    }
    $variant = is_string($requestedSize) ? $requestedSize : (is_numeric($requestedSize) ? (int)$requestedSize : 'original');
    $ext = ($contentType === 'image/webp') ? 'webp' : 'jpg';
    $filename = $timestamp . '_' . $variant . '.' . $ext;
    header('Content-Disposition: inline; filename="' . $filename . '"');
    
    // Set basic cache headers
    if ($refreshInterval !== null && $mtime > 0) {
        $age = time() - $mtime;
        // If mtime is in the future (negative age), treat as fresh (age = 0)
        if ($age < 0) {
            $age = 0;
        }
        $remainingTime = max(0, $refreshInterval - $age);
        
        // Rate limited - use shorter cache
        if ($isRateLimited) {
            header('Cache-Control: public, max-age=0, must-revalidate');
            header('X-Cache-Status: RL-SERVE');
            header('X-RateLimit: exceeded');
        } else {
            // Normal cache headers
            $isStale = $age >= $refreshInterval;
            $hasHash = isset($_GET['v']) && preg_match('/^[a-f0-9]{6,}$/i', $_GET['v']);
            
            if ($isStale) {
                // Stale cache: include stale-while-revalidate
                $cc = $hasHash 
                    ? 'public, max-age=0, s-maxage=0, must-revalidate, stale-while-revalidate=' . STALE_WHILE_REVALIDATE_SECONDS
                    : 'public, max-age=0, must-revalidate, stale-while-revalidate=' . STALE_WHILE_REVALIDATE_SECONDS;
                header('Cache-Control: ' . $cc);
                if ($hasHash) {
                    header('Surrogate-Control: max-age=0, stale-while-revalidate=' . STALE_WHILE_REVALIDATE_SECONDS);
                    header('CDN-Cache-Control: max-age=0, stale-while-revalidate=' . STALE_WHILE_REVALIDATE_SECONDS);
                }
                header('X-Cache-Status: STALE');
            } else {
                // Fresh cache: normal headers
                $cc = $hasHash 
                    ? 'public, max-age=' . $remainingTime . ', s-maxage=' . $remainingTime . ', immutable' 
                    : 'public, max-age=' . $remainingTime;
                header('Cache-Control: ' . $cc);
                if ($hasHash) {
                    header('Surrogate-Control: max-age=' . $remainingTime . ', stale-while-revalidate=' . (STALE_WHILE_REVALIDATE_SECONDS / 5));
                    header('CDN-Cache-Control: max-age=' . $remainingTime . ', stale-while-revalidate=' . (STALE_WHILE_REVALIDATE_SECONDS / 5));
                }
                header('X-Cache-Status: HIT');
            }
        }
        
        header('Last-Modified: ' . gmdate('D, d M Y H:i:s', $mtime) . ' GMT');
        header('X-Image-Timestamp: ' . $mtime);
        
        // Check conditional requests
        $etag = 'W/"' . sha1($filePath . '|' . $mtime . '|' . filesize($filePath)) . '"';
        $ifModSince = $_SERVER['HTTP_IF_MODIFIED_SINCE'] ?? '';
        $ifNoneMatch = $_SERVER['HTTP_IF_NONE_MATCH'] ?? '';
        if ($ifNoneMatch === $etag || strtotime($ifModSince ?: '1970-01-01') >= (int)$mtime) {
            header('ETag: ' . $etag);
            http_response_code(304);
            if ($exitAfterServe) {
                exit;
            }
            return; // Don't exit if caller wants to continue
        }
        header('ETag: ' . $etag);
    } else {
        // No cache info - basic headers
        header('Cache-Control: public, max-age=0, must-revalidate');
    }
    
    // Use existing serveFile() function
    if (!serveFile($filePath, $contentType)) {
        servePlaceholder();
        if ($exitAfterServe) {
            exit;
        }
        return; // Don't exit if caller wants to continue
    }
    
    // Exit after serving if requested (default behavior)
    if ($exitAfterServe) {
        exit;
    }
}

/**
 * Find the latest valid image file (JPG or WEBP)
 * 
 * Scans JPG and WEBP cache files, validates they are actual image files,
 * and returns the preferred or most recent valid image. Validates file headers
 * to ensure files are not corrupted. Uses EXIF capture time when available,
 * otherwise falls back to file modification time.
 * 
 * @param string $cacheJpg Path to JPG cache file
 * @param string $cacheWebp Path to WEBP cache file
 * @param string|null $unused Unused parameter (kept for backward compatibility)
 * @param string|null $preferredFormat Preferred format: 'webp', 'jpg', or null for auto-select
 * @return array|null Array with keys: 'file' (string path), 'mtime' (int timestamp),
 *   'size' (int bytes), 'type' (string MIME type), or null if no valid image found
 */
function findLatestValidImage($cacheJpg, $cacheWebp, $unused = null, $preferredFormat = null) {
    $candidates = [];
    
    // Check JPG file
    if (file_exists($cacheJpg) && @filesize($cacheJpg) > 0) {
        $fp = @fopen($cacheJpg, 'rb');
        if ($fp) {
            $header = @fread($fp, 3);
            @fclose($fp);
            // Validate JPEG header (FF D8 FF)
            if (substr($header, 0, 3) === "\xFF\xD8\xFF") {
                $captureTime = getImageCaptureTime($cacheJpg);
                $size = @filesize($cacheJpg);
                // Validate timestamp and size
                if ($captureTime > 0 && $size !== false && $size > 0) {
                    $candidates[] = [
                        'file' => $cacheJpg,
                        'mtime' => $captureTime,
                        'size' => (int)$size,
                        'type' => 'image/jpeg'
                    ];
                }
            }
        }
    }
    
    // Check WEBP file
    if (file_exists($cacheWebp) && @filesize($cacheWebp) > 0) {
        $fp = @fopen($cacheWebp, 'rb');
        if ($fp) {
            $header = @fread($fp, 12);
            @fclose($fp);
            // Validate WEBP header (RIFF...WEBP)
            if (substr($header, 0, 4) === 'RIFF' && strpos($header, 'WEBP') !== false) {
                $mtime = @filemtime($cacheWebp);
                $size = @filesize($cacheWebp);
                // Validate mtime and size
                if ($mtime !== false && $size !== false && $size > 0) {
                    $candidates[] = [
                        'file' => $cacheWebp,
                        'mtime' => (int)$mtime,
                        'size' => (int)$size,
                        'type' => 'image/webp'
                    ];
                }
            }
        }
    }
    
    if (empty($candidates)) {
        return null;
    }
    
    // If preferred format is specified, return it if available
    if ($preferredFormat === 'webp') {
        foreach ($candidates as $candidate) {
            if ($candidate['type'] === 'image/webp') {
                return $candidate;
            }
        }
    } elseif ($preferredFormat === 'jpg' || $preferredFormat === 'jpeg') {
        foreach ($candidates as $candidate) {
            if ($candidate['type'] === 'image/jpeg') {
                return $candidate;
            }
        }
    }
    
    // No preferred format or preferred format not available: prefer JPG for EXIF capture time
    // If JPG not available, use WebP > most recent
    $jpgCandidate = null;
    $webpCandidate = null;
    foreach ($candidates as $candidate) {
        if ($candidate['type'] === 'image/jpeg') {
            $jpgCandidate = $candidate;
            break;
        } elseif ($candidate['type'] === 'image/webp' && $webpCandidate === null) {
            $webpCandidate = $candidate;
        }
    }
    
    if ($jpgCandidate !== null) {
        return $jpgCandidate;
    }
    
    if ($webpCandidate !== null) {
        return $webpCandidate;
    }
    
    // Fallback: return most recent
    usort($candidates, function($a, $b) {
        return $b['mtime'] - $a['mtime'];
    });
    return $candidates[0];
}

// Check cache directory accessibility (server error if not accessible)
if (!is_dir($cacheDir) || !is_readable($cacheDir)) {
    aviationwx_log('error', 'webcam cache directory not accessible', [
        'airport' => $airportId,
        'cam' => $camIndex,
        'cache_dir' => $cacheDir,
        'exists' => is_dir($cacheDir),
        'readable' => is_dir($cacheDir) ? is_readable($cacheDir) : false
    ], 'app');
    
    http_response_code(503);
    header('Content-Type: application/json');
    echo json_encode([
        'error' => 'service_unavailable',
        'message' => 'Cache directory not accessible',
        'troubleshooting' => 'Check file system permissions and disk space'
    ]);
    exit;
}

// Get latest timestamp
$latestTimestamp = getLatestImageTimestamp($airportId, $camIndex);
$refreshInterval = getRefreshIntervalForCamera($airportId, $config, $cam);
$enabledFormats = getEnabledWebcamFormats();

// If no image exists, serve placeholder
if ($latestTimestamp === 0) {
    servePlaceholder();
    exit;
}

// Handle timestamp-based URL request (immutable cache busting)
if ($requestedTimestamp !== null && $requestedTimestamp > 0) {
    // Determine requested format
    $requestedFormat = isset($_GET['fmt']) ? strtolower(trim($_GET['fmt'])) : 'jpg';
    if (!in_array($requestedFormat, ['jpg', 'webp'])) {
        $requestedFormat = 'jpg';
    }
    
    $timestampFile = getImagePathForSize($airportId, $camIndex, $requestedTimestamp, $requestedSize, $requestedFormat);
    
    // Try requested format first, fall back to JPG
    if ($timestampFile === null && $requestedFormat !== 'jpg') {
        $timestampFile = getImagePathForSize($airportId, $camIndex, $requestedTimestamp, $requestedSize, 'jpg');
        $requestedFormat = 'jpg';
    }
    
    if ($timestampFile !== null && file_exists($timestampFile)) {
        // Validate file is within cache directory (security check)
        $realPath = realpath($timestampFile);
        $cacheDir = getWebcamCameraDir($airportId, $camIndex);
        $realCacheDir = realpath($cacheDir);
        if ($realPath !== false && $realCacheDir !== false && strpos($realPath, $realCacheDir) === 0) {
            // Serve timestamp-based file with immutable cache headers
            $mimeType = getMimeTypeForFormat($requestedFormat);
            $mtime = @filemtime($timestampFile);
            
            // Build filename matching server naming convention: {timestamp}_{variant}.{ext}
            $variant = ($requestedSize === 'original') ? 'original' : (int)$requestedSize;
            $filename = $requestedTimestamp . '_' . $variant . '.' . $requestedFormat;
            
            header('Content-Type: ' . $mimeType);
            header('Content-Disposition: inline; filename="' . $filename . '"');
            header('Cache-Control: public, max-age=31536000, immutable');
            header('Content-Length: ' . filesize($timestampFile));
            header('X-Content-Type-Options: nosniff');
            if ($mtime !== false) {
                header('Last-Modified: ' . gmdate('D, d M Y H:i:s', $mtime) . ' GMT');
            }
            
            readfile($timestampFile);
            exit;
        }
    }
    
    // Timestamp file doesn't exist - return 404 (no dynamic generation)
    $currentTimestamp = getLatestImageTimestamp($airportId, $camIndex);
    http_response_code(404);
    header('Content-Type: application/json');
    echo json_encode([
        'error' => 'variant_not_found',
        'message' => "Image variant not found for timestamp {$requestedTimestamp}, size {$requestedSize}, format {$requestedFormat}",
        'requested_timestamp' => $requestedTimestamp,
        'requested_size' => $requestedSize,
        'requested_format' => $requestedFormat,
        'current_timestamp' => $currentTimestamp,
        'troubleshooting' => 'Variant may not exist for this timestamp. Use ?variants=1 to discover available variants.'
    ]);
    exit;
}

$imagePath = getImagePathForSize($airportId, $camIndex, $latestTimestamp, $requestedSize, $preferredFormat);

// Try preferred format first, then fallback to other enabled formats
if ($imagePath === null) {
    foreach ($enabledFormats as $format) {
        if ($format === $preferredFormat) {
            continue; // Already tried
        }
        $imagePath = getImagePathForSize($airportId, $camIndex, $latestTimestamp, $requestedSize, $format);
        if ($imagePath !== null) {
            $preferredFormat = $format;
            break;
        }
    }
}

// If no image found, return error (no dynamic generation)
if ($imagePath === null) {
    http_response_code(404);
    header('Content-Type: application/json');
    $variants = getAvailableVariants($airportId, $camIndex, $latestTimestamp);
    echo json_encode([
        'error' => 'variant_not_found',
        'message' => "Image variant not found for size '{$requestedSize}', format '{$preferredFormat}'",
        'requested_size' => $requestedSize,
        'requested_format' => $preferredFormat,
        'timestamp' => $latestTimestamp,
        'available_variants' => $variants,
        'troubleshooting' => 'Requested variant does not exist. Use available variants from the variants list.'
    ]);
    exit;
}

// Get file metadata
$mtime = @filemtime($imagePath);
if ($mtime === false) {
    $mtime = $latestTimestamp;
}
$isCurrentCycle = isFromCurrentRefreshCycle($mtime, $refreshInterval);

// Determine if this is an explicit format request (fmt=webp)
$isExplicitFormatRequest = isset($_GET['fmt']) && 
                          in_array(strtolower(trim($_GET['fmt'])), ['webp']);

// Re-determine preferred format (may have changed based on enabled formats)
$requestedFormatOriginal = null;
if (isset($_GET['fmt'])) {
    // Explicit format request
    $fmt = strtolower(trim($_GET['fmt']));
    if (in_array($fmt, ['webp', 'jpg', 'jpeg'])) {
        $preferredFormat = ($fmt === 'jpeg') ? 'jpg' : $fmt;
        $requestedFormatOriginal = $preferredFormat; // Store original for disabled check
    } else {
        $preferredFormat = 'jpg'; // Invalid fmt, fallback to JPEG
    }
} else {
    // No explicit format - use Accept header for preference, but serve immediately
    $acceptHeader = $_SERVER['HTTP_ACCEPT'] ?? '';
    if (stripos($acceptHeader, 'image/webp') !== false) {
        $preferredFormat = 'webp';
    } else {
        $preferredFormat = 'jpg';
    }
}

// Check for format disabled but explicitly requested
if ($isExplicitFormatRequest && $requestedFormatOriginal !== null && !in_array($requestedFormatOriginal, $enabledFormats)) {
    // Format disabled but explicitly requested → 400 Bad Request
    aviationwx_log('warning', 'webcam format disabled but explicitly requested', [
        'airport' => $airportId,
        'cam' => $camIndex,
        'requested_format' => $requestedFormatOriginal,
        'enabled_formats' => $enabledFormats
    ], 'user');
    
    http_response_code(400);
    header('Content-Type: application/json');
    echo json_encode([
        'error' => 'format_disabled',
        'message' => "Format '{$requestedFormatOriginal}' is disabled in configuration",
        'enabled_formats' => $enabledFormats,
        'troubleshooting' => 'Enable format in airports.json config.webcam_generate_' . $requestedFormatOriginal
    ]);
    exit;
}

// Adjust preferred format if disabled
if (!in_array($preferredFormat, $enabledFormats)) {
    // Find next best enabled format
    if ($preferredFormat === 'webp' && in_array('jpg', $enabledFormats)) {
        $preferredFormat = 'jpg';
    } else {
        $preferredFormat = 'jpg'; // Always enabled
    }
}

// Check rate limiting (before format logic)
// If rate limited, serve best available format immediately
if ($isRateLimited) {
    $mostEfficient = findMostEfficientFormat($formatStatus, $airportId, $camIndex, $requestedSize);
    if ($mostEfficient) {
        // Get mtime from format status based on file extension
        $filePath = $mostEfficient['file'];
        if (substr($filePath, -5) === '.webp') {
            $mtime = $formatStatus['webp']['mtime'];
        } else {
            $mtime = $formatStatus['jpg']['mtime'];
        }
        aviationwx_log('warning', 'webcam rate-limited, serving cached', [
            'airport' => $airportId,
            'cam' => $camIndex,
            'fmt' => $fmt ?? 'auto',
            'preferred' => $preferredFormat
        ], 'app');
        serve200Response($mostEfficient['file'], $mostEfficient['type'], $mtime, $refreshInterval);
        exit;
    }
    // As last resort, serve placeholder
    servePlaceholder();
    exit;
}

// Serve the resolved image file
// No dynamic generation - file must exist or we return 404 above
serve200Response($imagePath, getMimeTypeForFormat($preferredFormat), $mtime, $refreshInterval);
exit;

// Not explicit request OR old cycle OR generation failed → serve best available immediately
$mostEfficient = findMostEfficientFormat($formatStatus, $airportId, $camIndex, $requestedSize);
if ($mostEfficient) {
    aviationwx_log('info', 'webcam serving fallback format', [
        'airport' => $airportId,
        'cam' => $camIndex,
        'preferred_format' => $preferredFormat,
        'served_format' => substr($mostEfficient['file'], -4) === 'webp' ? 'webp' : 'jpg',
        'fmt_param' => $fmt ?? 'auto',
        'reason' => $isExplicitFormatRequest ? 'explicit_request_not_ready' : 'auto_fallback'
    ], 'user');
    // Get mtime from format status based on file extension
    $filePath = $mostEfficient['file'];
    if (substr($filePath, -5) === '.webp') {
        $mtime = $formatStatus['webp']['mtime'];
    } else {
        $mtime = $formatStatus['jpg']['mtime'];
    }
    
    // If cache is stale, serve file without exiting so we can trigger background refresh
    if (!$isCurrentCycle) {
        serve200Response($mostEfficient['file'], $mostEfficient['type'], $mtime, $refreshInterval, false);
        triggerWebcamBackgroundRefresh($airportId, $camIndex, $cam, $cacheDir, $cacheJpg, $cacheWebp, $refreshInterval, $mtime, $reqId);
        exit;
    }
    
    serve200Response($mostEfficient['file'], $mostEfficient['type'], $mtime, $refreshInterval);
    exit;
}

// Fallback to JPEG - use file mtime (not EXIF) for cache status
$jpegMtime = $formatStatus['jpg']['mtime'];

// If cache is stale, serve file without exiting so we can trigger background refresh
if (!$isCurrentCycle) {
    serve200Response(getCacheFile($airportId, $camIndex, 'jpg', $requestedSize), 'image/jpeg', $jpegMtime, $refreshInterval, false);
    triggerWebcamBackgroundRefresh($airportId, $camIndex, $cam, $cacheDir, $cacheJpg, $cacheWebp, $refreshInterval, $jpegMtime, $reqId);
    exit;
}

serve200Response(getCacheFile($airportId, $camIndex, 'jpg', $requestedSize), 'image/jpeg', $jpegMtime, $refreshInterval);
exit;

/**
 * Trigger webcam background refresh (extracted from stale cache path)
 * 
 * Handles background refresh logic: circuit breaker check, locking, and fetch.
 * This function is called from multiple code paths that serve stale cache.
 * 
 * @param string $airportId Airport ID
 * @param int $camIndex Camera index
 * @param array $cam Camera configuration
 * @param string $cacheDir Cache directory
 * @param string $cacheJpg JPEG cache file path
 * @param string $cacheWebp WEBP cache file path
 * @param int $refreshInterval Refresh interval in seconds
 * @param int $mtime Current cache file mtime
 * @param string $reqId Request ID for logging
 * @return void
 */
function triggerWebcamBackgroundRefresh($airportId, $camIndex, $cam, $cacheDir, $cacheJpg, $cacheWebp, $refreshInterval, $mtime, $reqId) {// Check circuit breaker early before starting background refresh
    $circuit = checkCircuitBreaker($airportId, $camIndex);
    $cacheAge = time() - $mtime;
    
    // If cache is very stale (>= 3 hours), bypass circuit breaker to attempt refresh
    // This ensures we periodically try to refresh even if previous attempts failed
    $veryStaleThreshold = 3 * 3600; // 3 hours
    $bypassCircuitBreaker = ($cacheAge >= $veryStaleThreshold);
    $shouldRefresh = !$circuit['skip'] || $bypassCircuitBreaker;if (!$shouldRefresh) {
        aviationwx_log('info', 'webcam background refresh skipped - circuit breaker open', [
            'airport' => $airportId,
            'cam' => $camIndex,
            'failures' => $circuit['failures'] ?? 0,
            'backoff_remaining' => $circuit['backoff_remaining']
        ], 'app');
        return; // Skip refresh if circuit breaker is open and cache is not very stale
    }
    
    if ($bypassCircuitBreaker) {
        aviationwx_log('info', 'webcam background refresh - bypassing circuit breaker for very stale cache', [
            'airport' => $airportId,
            'cam' => $camIndex,
            'cache_age' => $cacheAge,
            'failures' => $circuit['failures'] ?? 0
        ], 'app');
    }
    
    // Use file-based locking to prevent concurrent refreshes
    $lockFile = $cacheDir . '/refresh_' . $airportId . '_' . $camIndex . '.lock';
    
    // Clean up stale locks (older than 5 minutes) - use atomic check-and-delete
    if (file_exists($lockFile)) {
        $lockMtime = @filemtime($lockFile);
        if ($lockMtime !== false && (time() - $lockMtime) > FILE_LOCK_STALE_SECONDS) {
            // Try to delete only if still old (race condition protection)
            $currentMtime = @filemtime($lockFile);
            if ($currentMtime !== false && (time() - $currentMtime) > FILE_LOCK_STALE_SECONDS) {
                @unlink($lockFile);
            }
        }
    }
    
    $lockFp = @fopen($lockFile, 'c+');
    $lockAcquired = false;
    $lockCleanedUp = false;
    
    if ($lockFp !== false) {
        // Try to acquire exclusive lock (non-blocking)
        if (@flock($lockFp, LOCK_EX | LOCK_NB)) {
            $lockAcquired = true;
            // Write PID and timestamp to lock file for debugging
            @fwrite($lockFp, json_encode([
                'pid' => getmypid(),
                'started' => time(),
                'request_id' => $reqId
            ]));
            @fflush($lockFp);
            
            // Register shutdown function to clean up lock on script exit
            register_shutdown_function(function() use ($lockFp, $lockFile, &$lockCleanedUp) {
                if ($lockCleanedUp) {
                    return;
                }
                if (is_resource($lockFp)) {
                    @flock($lockFp, LOCK_UN);
                    @fclose($lockFp);
                }
                if (file_exists($lockFile)) {
                    @unlink($lockFile);
                }
                $lockCleanedUp = true;
            });
        } else {
            // Another refresh is already in progress
            @fclose($lockFp);
            aviationwx_log('info', 'webcam background refresh skipped - already in progress', [
                'airport' => $airportId,
                'cam' => $camIndex
            ], 'app');
            return; // Skip - another process is handling the refresh
        }
    } else {
        // Couldn't create lock file, but continue anyway (non-critical)
        aviationwx_log('warning', 'webcam background refresh lock file creation failed', [
            'airport' => $airportId,
            'cam' => $camIndex
        ], 'app');
    }
    
    // Flush output to client immediately, then refresh in background
    // Ensure output is flushed before background refresh
    if (ob_get_level() > 0) {
        @ob_end_flush();
    }
    @flush();
    
    if (function_exists('fastcgi_finish_request')) {
        // FastCGI - finish request but keep script running
        fastcgi_finish_request();
    }
    
    // Set time limit for background refresh
    set_time_limit(45);
    $cacheAge = time() - $mtime;
    
    aviationwx_log('info', 'webcam background refresh started', [
        'airport' => $airportId,
        'cam' => $camIndex,
        'cache_age' => $cacheAge,
        'refresh_interval' => $refreshInterval
    ], 'app');
    
    try {
        // Check connection status periodically during background refresh
        $refreshStartTime = time();
        $maxRefreshTime = BACKGROUND_REFRESH_MAX_TIME;
        
        // Fetch fresh image
        $freshCacheFile = $cacheJpg; // Always fetch JPG first
        $success = fetchWebcamImageBackground($airportId, $camIndex, $cam, $freshCacheFile, $cacheWebp);// Check if we're running out of time
        if ((time() - $refreshStartTime) > $maxRefreshTime) {
            aviationwx_log('warning', 'webcam background refresh approaching timeout, stopping', [
                'airport' => $airportId,
                'cam' => $camIndex,
                'elapsed' => time() - $refreshStartTime
            ], 'app');
            // Lock will be cleaned up by shutdown function
            return;
        }
        
        if ($success) {
            aviationwx_log('info', 'webcam background refresh completed successfully', [
                'airport' => $airportId,
                'cam' => $camIndex
            ], 'app');} else {
            aviationwx_log('warning', 'webcam background refresh failed', [
                'airport' => $airportId,
                'cam' => $camIndex
            ], 'app');}
    } finally {
        // Always release lock
        if ($lockAcquired && $lockFp !== false && !$lockCleanedUp) {
            @flock($lockFp, LOCK_UN);
            @fclose($lockFp);
            if (file_exists($lockFile)) {
                @unlink($lockFile);
            }
            $lockCleanedUp = true;
        }
    }
}

/**
 * Fetch a single webcam image in background (for background refresh)
 * 
 * Fetches a webcam image and updates cache files. Supports RTSP, MJPEG, and static images.
 * Generates WEBP version in background. Updates circuit breaker on success/failure.
 * 
 * @param string $airportId Airport ID (e.g., 'kspb')
 * @param int $camIndex Camera index (0-based)
 * @param array $cam Camera configuration array
 * @param string $cacheFile Target JPG cache file path
 * @param string $cacheWebp Target WEBP cache file path
 * @return bool True on success, false on failure or skip (circuit breaker open)
 */
function fetchWebcamImageBackground($airportId, $camIndex, $cam, $cacheFile, $cacheWebp) {
    $url = $cam['url'];
    $transport = isset($cam['rtsp_transport']) ? strtolower($cam['rtsp_transport']) : 'tcp';
    
    // Circuit breaker check handled by caller
    // This allows bypassing circuit breaker for very stale cache
    
    // Determine source type
    $sourceType = isset($cam['type']) ? strtolower(trim($cam['type'])) : detectWebcamSourceType($url);
    
    $fetchStartTime = microtime(true);
    $success = false;
    
    switch ($sourceType) {
        case 'rtsp':
            $fetchTimeout = isset($cam['rtsp_fetch_timeout']) ? intval($cam['rtsp_fetch_timeout']) : intval(getenv('RTSP_TIMEOUT') ?: RTSP_DEFAULT_TIMEOUT);
            $maxRuntime = isset($cam['rtsp_max_runtime']) ? intval($cam['rtsp_max_runtime']) : 6;
            $success = fetchRTSPFrame($url, $cacheFile, $transport, $fetchTimeout, 2, $maxRuntime);
            break;
            
        case 'static_jpeg':
        case 'static_png':
            $success = fetchStaticImage($url, $cacheFile);
            break;
            
        case 'mjpeg':
        default:
            $success = fetchMJPEGStream($url, $cacheFile);
            break;
    }
    
    $fetchDuration = round((microtime(true) - $fetchStartTime) * 1000, 2);
    
    if ($success && file_exists($cacheFile) && filesize($cacheFile) > 0) {
        // Success: reset circuit breaker
        recordSuccess($airportId, $camIndex);
        $size = filesize($cacheFile);
        
        aviationwx_log('info', 'webcam background refresh success', [
            'airport' => $airportId,
            'cam' => $camIndex,
            'type' => $sourceType,
            'bytes' => $size,
            'fetch_duration_ms' => $fetchDuration
        ], 'app');
        
        // Generate all enabled formats synchronously in parallel
        require_once __DIR__ . '/../lib/webcam-format-generation.php';
        
        // Get timestamp from source file
        $timestamp = getImageCaptureTime($cacheFile);
        if ($timestamp <= 0) {
            $timestamp = $mtime;
        }
        
        // Generate all enabled formats in parallel (synchronous wait)
        $formatResults = generateFormatsSync($cacheFile, $airportId, $camIndex, 'jpg');
        
        // Promote all successful formats to timestamp-based files with symlinks
        $promotedFormats = promoteFormats($airportId, $camIndex, $formatResults, 'jpg', $timestamp);
        
        return true;
    } else {
        // Failure: record and update backoff
        $lastErr = @json_decode(@file_get_contents($cacheFile . '.error.json'), true);
        $sev = mapErrorSeverity($lastErr['code'] ?? 'unknown');
        $failureReason = $lastErr['reason'] ?? ($lastErr['code'] ?? 'unknown');
        $httpCode = isset($lastErr['http_code']) ? (int)$lastErr['http_code'] : null;
        recordFailure($airportId, $camIndex, $sev, $httpCode, $failureReason);
        
        aviationwx_log('error', 'webcam background refresh failure', [
            'airport' => $airportId,
            'cam' => $camIndex,
            'type' => $sourceType,
            'severity' => $sev,
            'fetch_duration_ms' => $fetchDuration,
            'error_code' => $lastErr['code'] ?? null
        ], 'app');
        
        return false;
    }
}

// Cache expired - serve stale cache (we already validated it's a valid image above)
// Always serve the latest valid image, even if it's old
$mtime = $fileMtime;
$cacheAge = time() - $mtime;
$etagVal = $etag($targetFile);// Conditional requests for stale file
$ifModSince = $_SERVER['HTTP_IF_MODIFIED_SINCE'] ?? '';
$ifNoneMatch = $_SERVER['HTTP_IF_NONE_MATCH'] ?? '';
if ($ifNoneMatch === $etagVal || strtotime($ifModSince ?: '1970-01-01') >= (int)$mtime) {
    header('Cache-Control: public, max-age=0, must-revalidate');
    header('ETag: ' . $etagVal);
    header('Last-Modified: ' . gmdate('D, d M Y H:i:s', $mtime) . ' GMT');
    header('X-Cache-Status: STALE'); // Include cache status in 304 responses
    http_response_code(304);
    exit;
}

// Update Content-Type if serving WEBP (already set to image/jpeg earlier)
if ($ctype !== 'image/jpeg') {
    header('Content-Type: ' . $ctype, true);
}
$hasHash = isset($_GET['v']) && preg_match('/^[a-f0-9]{6,}$/i', $_GET['v']);
$cc = $hasHash ? 'public, max-age=0, s-maxage=0, must-revalidate, stale-while-revalidate=' . STALE_WHILE_REVALIDATE_SECONDS : 'public, max-age=0, must-revalidate, stale-while-revalidate=' . STALE_WHILE_REVALIDATE_SECONDS;
header('Cache-Control: ' . $cc); // Stale, revalidate
if ($hasHash) {
    header('Surrogate-Control: max-age=0, stale-while-revalidate=' . STALE_WHILE_REVALIDATE_SECONDS);
    header('CDN-Cache-Control: max-age=0, stale-while-revalidate=' . STALE_WHILE_REVALIDATE_SECONDS);
}
header('ETag: ' . $etagVal);
header('Last-Modified: ' . gmdate('D, d M Y H:i:s', $mtime) . ' GMT');
header('X-Cache-Status: STALE');
header('X-Image-Timestamp: ' . $mtime); // Custom header for timestamp

aviationwx_log('info', 'webcam serve stale', [
    'airport' => $airportId,
    'cam' => $camIndex,
    'fmt' => $fmt ?? 'auto',
    'preferred' => $preferredFormat,
    'cache_age' => $cacheAge
], 'user');

// Serve stale cache immediately (already validated as valid image above)
if (ob_get_level() > 0) {
    @ob_end_clean(); // Clear buffer before sending file (consistent with fresh cache path)
}

if (!serveFile($targetFile, $ctype)) {
    aviationwx_log('error', 'webcam failed to serve stale file', ['airport' => $airportId, 'cam' => $camIndex, 'file' => $targetFile], 'app');
    servePlaceholder();
    exit;
}

// Trigger background refresh after serving stale cache
triggerWebcamBackgroundRefresh($airportId, $camIndex, $cam, $cacheDir, $cacheJpg, $cacheWebp, $perCamRefresh, $mtime, $reqId);
exit;
