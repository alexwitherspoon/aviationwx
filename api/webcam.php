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

// Start output buffering IMMEDIATELY to catch any output from included files
ob_start();

require_once __DIR__ . '/../lib/config.php';
require_once __DIR__ . '/../lib/rate-limit.php';
require_once __DIR__ . '/../lib/logger.php';
require_once __DIR__ . '/../lib/constants.php';

// VPN routing is optional - only required if VPN features are used
$vpnRoutingFile = __DIR__ . '/../lib/vpn-routing.php';
if (file_exists($vpnRoutingFile)) {
    require_once $vpnRoutingFile;
} else {
    // Define stub function if VPN routing file doesn't exist
    if (!function_exists('verifyVpnForCamera')) {
        function verifyVpnForCamera($airportId, $cam) {
            // VPN routing not available - assume VPN not required
            return true;
        }
    }
}

// Include circuit breaker functions
require_once __DIR__ . '/../lib/circuit-breaker.php';
// Include webcam fetch functions for background refresh
require_once __DIR__ . '/../scripts/fetch-webcam.php';

// Clear any output that may have been captured from included files
ob_clean();

// Determine if we're serving JSON (mtime=1) or image early - check BEFORE any other processing
$isJsonRequest = isset($_GET['mtime']) && $_GET['mtime'] === '1';

// Set Content-Type IMMEDIATELY based on request type to prevent Nginx/Cloudflare override
if ($isJsonRequest) {
    header('Content-Type: application/json', true);
} else {
    // Set image/jpeg immediately - will be adjusted for WEBP later if needed
    header('Content-Type: image/jpeg', true);
}

/**
 * Serve placeholder image
 * 
 * Serves a placeholder image when webcam is unavailable or invalid.
 * Attempts to serve placeholder.jpg if available, otherwise serves a 1x1 transparent PNG.
 * Sets appropriate cache headers for placeholder images.
 * 
 * @return void
 */
function servePlaceholder() {
    ob_end_clean(); // Ensure no output before headers (end and clean in one call)
    if (file_exists(__DIR__ . '/../public/images/placeholder.jpg')) {
        header('Content-Type: image/jpeg');
        header('Cache-Control: public, max-age=' . PLACEHOLDER_CACHE_TTL);
        header('X-Cache-Status: MISS'); // Placeholder served - no cache
        $placeholderPath = __DIR__ . '/../public/images/placeholder.jpg';
        $size = @filesize($placeholderPath);
        if ($size > 0) {
            header('Content-Length: ' . $size);
        }
        $result = @readfile($placeholderPath);
        if ($result === false) {
            // Fallback to base64 if readfile fails
            header('Content-Type: image/png');
            header('Content-Length: 95');
            echo base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNk+M9QDwADhgGAWjR9awAAAABJRU5ErkJggg==');
        }
    } else {
        header('Content-Type: image/png');
        header('Cache-Control: public, max-age=' . PLACEHOLDER_CACHE_TTL);
        header('X-Cache-Status: MISS'); // Placeholder served - no cache
        header('Content-Length: 95');
        echo base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNk+M9QDwADhgGAWjR9awAAAABJRU5ErkJggg==');
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
    ob_end_clean();
    
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

// Get and validate parameters
$reqId = aviationwx_get_request_id();

// Set request ID after Content-Type to ensure it's not overridden
header('X-Request-ID: ' . $reqId);

// Support both 'id' and 'airport' parameters for backward compatibility
$rawIdentifier = $_GET['id'] ?? $_GET['airport'] ?? '';
// Support both 'cam' and 'index' parameters for backward compatibility
$camIndex = isset($_GET['cam']) ? intval($_GET['cam']) : (isset($_GET['index']) ? intval($_GET['index']) : 0);

if (empty($rawIdentifier)) {
    aviationwx_log('error', 'webcam missing airport identifier', [], 'user');
    servePlaceholder();
}

// Find airport by any identifier type (ICAO, IATA, FAA, or airport ID)
$result = findAirportByIdentifier($rawIdentifier);
if ($result === null || !isset($result['airport']) || !isset($result['airportId'])) {
    aviationwx_log('error', 'webcam airport not found', ['identifier' => $rawIdentifier], 'user');
    servePlaceholder();
}

$airport = $result['airport'];
$airportId = $result['airportId'];
    
// Check if airport is enabled (opt-in model: must have enabled: true)
if (!isAirportEnabled($airport)) {
    aviationwx_log('error', 'webcam airport not enabled', ['identifier' => $rawIdentifier, 'airport_id' => $airportId], 'user');
    servePlaceholder();
}

// Load config for webcam access
$config = loadConfig();
if ($config === null) {
    aviationwx_log('error', 'webcam config load failed', [], 'app');
    servePlaceholder();
}

// Validate cam index is non-negative and within bounds
if ($camIndex < 0) {
    $camIndex = 0;
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
    servePlaceholder();
}

$cam = $config['airports'][$airportId]['webcams'][$camIndex];
$cacheDir = __DIR__ . '/../cache/webcams';
$base = $cacheDir . '/' . $airportId . '_' . $camIndex;
$cacheJpg = $base . '.jpg';
$cacheWebp = $base . '.webp';

// Create cache directory if it doesn't exist
// Check parent directory first, then create with proper error handling
$parentDir = dirname($cacheDir);
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

// Check if requesting timestamp only (for frontend to get latest mtime)
// Exempt timestamp requests from rate limiting (they're lightweight and frequent)
if (isset($_GET['mtime']) && $_GET['mtime'] === '1') {
    // Content-Type already set earlier for JSON requests
    header('Cache-Control: no-cache, no-store, must-revalidate'); // Don't cache timestamp responses
    header('Pragma: no-cache');
    header('Expires: 0');
    // Rate limit headers (for observability; mtime endpoint is not limited)
    $rl = getRateLimitRemaining('webcam_api', RATE_LIMIT_WEBCAM_MAX, RATE_LIMIT_WEBCAM_WINDOW);
    if ($rl !== null) {
        header('X-RateLimit-Limit: ' . RATE_LIMIT_WEBCAM_MAX);
        header('X-RateLimit-Remaining: ' . (int)$rl['remaining']);
        header('X-RateLimit-Reset: ' . (int)$rl['reset']);
    }
    $existsJpg = file_exists($cacheJpg);
    $existsWebp = file_exists($cacheWebp);
    $mtime = 0;
    $size = 0;
    if ($existsJpg) { 
        $jpgMtime = @filemtime($cacheJpg);
        $jpgSize = @filesize($cacheJpg);
        if ($jpgMtime !== false) { $mtime = max($mtime, (int)$jpgMtime); }
        if ($jpgSize !== false) { $size = max($size, (int)$jpgSize); }
    }
    if ($existsWebp) { 
        $webpMtime = @filemtime($cacheWebp);
        $webpSize = @filesize($cacheWebp);
        if ($webpMtime !== false) { $mtime = max($mtime, (int)$webpMtime); }
        if ($webpSize !== false) { $size = max($size, (int)$webpSize); }
    }
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
$isRateLimited = !checkRateLimit('webcam_api', RATE_LIMIT_WEBCAM_MAX, RATE_LIMIT_WEBCAM_WINDOW);
// Rate limit headers for image responses
$rl = getRateLimitRemaining('webcam_api', RATE_LIMIT_WEBCAM_MAX, RATE_LIMIT_WEBCAM_WINDOW);
if ($rl !== null) {
    header('X-RateLimit-Limit: ' . RATE_LIMIT_WEBCAM_MAX);
    header('X-RateLimit-Remaining: ' . (int)$rl['remaining']);
    header('X-RateLimit-Reset: ' . (int)$rl['reset']);
}

// Optional format parameter: jpg (default), webp
$fmt = isset($_GET['fmt']) ? strtolower(trim($_GET['fmt'])) : 'jpg';
if (!in_array($fmt, ['jpg', 'jpeg', 'webp'])) { 
    $fmt = 'jpg'; 
}

// Determine refresh threshold
$defaultWebcamRefresh = getDefaultWebcamRefresh();
$airportWebcamRefresh = isset($config['airports'][$airportId]['webcam_refresh_seconds']) ? intval($config['airports'][$airportId]['webcam_refresh_seconds']) : $defaultWebcamRefresh;
$perCamRefresh = isset($cam['refresh_seconds']) ? intval($cam['refresh_seconds']) : $airportWebcamRefresh;

/**
 * Find the latest valid image file (JPG or WEBP)
 * 
 * Scans both JPG and WEBP cache files, validates they are actual image files,
 * and returns the most recent valid image. Validates file headers to ensure
 * files are not corrupted.
 * 
 * @param string $cacheJpg Path to JPG cache file
 * @param string $cacheWebp Path to WEBP cache file
 * @return array|null Array with keys: 'file' (string path), 'mtime' (int timestamp),
 *   'size' (int bytes), 'type' (string MIME type), or null if no valid image found
 */
function findLatestValidImage($cacheJpg, $cacheWebp) {
    $candidates = [];
    
    // Check JPG file
    if (file_exists($cacheJpg) && @filesize($cacheJpg) > 0) {
        $fp = @fopen($cacheJpg, 'rb');
        if ($fp) {
            $header = @fread($fp, 3);
            @fclose($fp);
            // Validate JPEG header (FF D8 FF)
            if (substr($header, 0, 3) === "\xFF\xD8\xFF") {
                $mtime = @filemtime($cacheJpg);
                $size = @filesize($cacheJpg);
                // Validate mtime and size
                if ($mtime !== false && $size !== false && $size > 0) {
                    $candidates[] = [
                        'file' => $cacheJpg,
                        'mtime' => (int)$mtime,
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
    
    // Return the most recent valid image
    if (!empty($candidates)) {
        usort($candidates, function($a, $b) {
            return $b['mtime'] - $a['mtime']; // Most recent first
        });
        return $candidates[0];
    }
    
    return null;
}

// Find latest valid image (even if old)
// Only try to find images if cache directory exists and is accessible
$validImage = null;
if (is_dir($cacheDir) && is_readable($cacheDir)) {
    $validImage = findLatestValidImage($cacheJpg, $cacheWebp);
} else {
    // Cache directory doesn't exist or isn't accessible
    aviationwx_log('warning', 'webcam cache directory not accessible', [
        'airport' => $airportId,
        'cam' => $camIndex,
        'cache_dir' => $cacheDir,
        'exists' => is_dir($cacheDir),
        'readable' => is_dir($cacheDir) ? is_readable($cacheDir) : false,
        'writable' => is_dir($cacheDir) ? is_writable($cacheDir) : false
    ], 'app');
}

// If no valid image exists, clean up empty/invalid files and serve placeholder
if ($validImage === null) {
    // Only try to clean up if directory is accessible
    if (is_dir($cacheDir) && is_writable($cacheDir)) {
        // Clean up empty or invalid files
        if (file_exists($cacheJpg) && @filesize($cacheJpg) === 0) {
            @unlink($cacheJpg);
            aviationwx_log('warning', 'webcam cache file is empty, deleted', ['airport' => $airportId, 'cam' => $camIndex], 'app');
        }
        if (file_exists($cacheWebp) && @filesize($cacheWebp) === 0) {
            @unlink($cacheWebp);
        }
    }
    servePlaceholder();
}

// Update targetFile and ctype based on the valid image found
$targetFile = $validImage['file'];
$ctype = $validImage['type'];

// Validate mtime (should already be validated in findLatestValidImage, but double-check)
$fileMtime = $validImage['mtime'];
$fileSize = $validImage['size'];
if ($fileMtime <= 0 || $fileSize <= 0) {
    aviationwx_log('error', 'webcam invalid file metadata', ['airport' => $airportId, 'cam' => $camIndex, 'mtime' => $fileMtime, 'size' => $fileSize], 'app');
    servePlaceholder();
}

// Determine format for hash based on actual file type
$actualFmt = (substr($targetFile, -5) === '.webp') ? 'webp' : 'jpg';
$immutableHash = substr(md5($airportId . '_' . $camIndex . '_' . $actualFmt . '_' . $fileMtime . '_' . $fileSize), 0, 8);

// If rate limited, prefer to serve an existing cached image (even if stale) with 200
if ($isRateLimited) {
    // Use findLatestValidImage to ensure we serve a valid image
    // Only check if cache directory is accessible
    $rateLimitImage = null;
    if (is_dir($cacheDir) && is_readable($cacheDir)) {
        $rateLimitImage = findLatestValidImage($cacheJpg, $cacheWebp);
    }
    
    if ($rateLimitImage !== null) {
        aviationwx_log('warning', 'webcam rate-limited, serving cached', ['airport' => $airportId, 'cam' => $camIndex, 'fmt' => $fmt], 'app');
        $mtime = $rateLimitImage['mtime'];
        $rateLimitCtype = $rateLimitImage['type'];
        $rateLimitFile = $rateLimitImage['file'];
        
        aviationwx_maybe_log_alert();
        header('Content-Type: ' . $rateLimitCtype, true);
        header('Cache-Control: public, max-age=0, must-revalidate');
        header('Last-Modified: ' . gmdate('D, d M Y H:i:s', $mtime) . ' GMT');
        header('X-Cache-Status: RL-SERVE'); // Served under rate limit
        header('X-RateLimit: exceeded');
        
        if (!serveFile($rateLimitFile, $rateLimitCtype)) {
            servePlaceholder();
        }
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

// Serve cached file if fresh (we already validated it's a valid image above)
if ((time() - $fileMtime) < $perCamRefresh) {
    $age = time() - $fileMtime;
    $remainingTime = $perCamRefresh - $age;
    $mtime = $fileMtime;
    
    $etagVal = $etag($targetFile);
    
    // Conditional requests
    $ifModSince = $_SERVER['HTTP_IF_MODIFIED_SINCE'] ?? '';
    $ifNoneMatch = $_SERVER['HTTP_IF_NONE_MATCH'] ?? '';
    if ($ifNoneMatch === $etagVal || strtotime($ifModSince ?: '1970-01-01') >= (int)$mtime) {
        header('Cache-Control: public, max-age=' . $remainingTime);
        header('ETag: ' . $etagVal);
        header('Last-Modified: ' . gmdate('D, d M Y H:i:s', $mtime) . ' GMT');
        header('X-Cache-Status: HIT'); // Include cache status in 304 responses
        http_response_code(304);
        exit;
    }

    // Update Content-Type if serving WEBP (already set to image/jpeg at top)
    if ($ctype !== 'image/jpeg') {
        header('Content-Type: ' . $ctype, true);
    }
    // For URLs with immutable hash (v=), allow immutable and s-maxage for CDNs
    $hasHash = isset($_GET['v']) && preg_match('/^[a-f0-9]{6,}$/i', $_GET['v']);
    $cc = $hasHash ? 'public, max-age=' . $remainingTime . ', s-maxage=' . $remainingTime . ', immutable' : 'public, max-age=' . $remainingTime;
    header('Cache-Control: ' . $cc);
    if ($hasHash) {
        header('Surrogate-Control: max-age=' . $remainingTime . ', stale-while-revalidate=' . (STALE_WHILE_REVALIDATE_SECONDS / 5));
        header('CDN-Cache-Control: max-age=' . $remainingTime . ', stale-while-revalidate=' . (STALE_WHILE_REVALIDATE_SECONDS / 5));
    }
    header('Expires: ' . gmdate('D, d M Y H:i:s', time() + $remainingTime) . ' GMT');
    header('ETag: ' . $etagVal);
    header('Last-Modified: ' . gmdate('D, d M Y H:i:s', $mtime) . ' GMT');
    header('X-Cache-Status: HIT');
    header('X-Image-Timestamp: ' . $mtime); // Custom header for timestamp
    
    // No need to re-validate - we already validated in findLatestValidImage()
    
    aviationwx_log('info', 'webcam serve fresh', ['airport' => $airportId, 'cam' => $camIndex, 'fmt' => $fmt, 'age' => $age], 'user');
    aviationwx_maybe_log_alert();
    
    if (!serveFile($targetFile, $ctype)) {
        aviationwx_log('error', 'webcam failed to serve file', ['airport' => $airportId, 'cam' => $camIndex, 'file' => $targetFile], 'app');
        servePlaceholder();
    }
    exit;
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
 * @return bool True on success, false on failure or skip (circuit breaker, VPN down)
 */
function fetchWebcamImageBackground($airportId, $camIndex, $cam, $cacheFile, $cacheWebp) {
    $url = $cam['url'];
    $transport = isset($cam['rtsp_transport']) ? strtolower($cam['rtsp_transport']) : 'tcp';
    
    // Check VPN connection if required
    if (!verifyVpnForCamera($airportId, $cam)) {
        aviationwx_log('warning', 'webcam fetch skipped - VPN connection down', [
            'airport' => $airportId,
            'cam' => $camIndex
        ], 'app');
        return false; // VPN required but not up, skip fetch
    }
    
    // Check circuit breaker: skip if in backoff period
    $circuit = checkCircuitBreaker($airportId, $camIndex);
    if ($circuit['skip']) {
        aviationwx_log('info', 'webcam background refresh skipped - circuit breaker open', [
            'airport' => $airportId,
            'cam' => $camIndex,
            'failures' => $circuit['failures'] ?? 0,
            'backoff_remaining' => $circuit['backoff_remaining']
        ], 'app');
        return false;
    }
    
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
        
        // Generate WEBP in background (non-blocking)
        // Use constant for transcode timeout
        $transcodeTimeout = isset($cam['transcode_timeout']) ? max(2, intval($cam['transcode_timeout'])) : DEFAULT_TRANSCODE_TIMEOUT;
        
        // Build ffmpeg command without 2>&1 in the base command (we'll handle redirects separately)
        $cmdWebp = sprintf("ffmpeg -hide_banner -loglevel error -y -i %s -frames:v 1 -q:v 30 -compression_level 6 -preset default %s", escapeshellarg($cacheFile), escapeshellarg($cacheWebp));
        
        // Run WEBP generation in background (non-blocking)
        if (function_exists('exec')) {
            // Use exec with background process via shell
            // Redirect both stdout and stderr to /dev/null and run in background
            $cmd = $cmdWebp . ' > /dev/null 2>&1 &';
            @exec($cmd);
            // Note: exec() returns immediately when using &, so we can't check success here
            // The background process will complete asynchronously
        } else {
            // Fallback: synchronous generation with timeout
            $transcodeStartTime = microtime(true);
            $processWebp = @proc_open($cmdWebp . ' 2>&1', [
                0 => ['pipe', 'r'],
                1 => ['pipe', 'w'],
                2 => ['pipe', 'w']
            ], $pipesWebp);
            
            if (is_resource($processWebp)) {
                fclose($pipesWebp[0]);
                $startTs = microtime(true);
                $timedOut = false;
                while (true) {
                    $status = proc_get_status($processWebp);
                    if (!$status['running']) {
                        $exitCode = $status['exitcode'];
                        proc_close($processWebp);
                        // Verify WEBP was created successfully
                        if ($exitCode !== 0 || !file_exists($cacheWebp) || filesize($cacheWebp) === 0) {
                            aviationwx_log('warning', 'webcam WEBP generation failed', [
                                'airport' => $airportId,
                                'cam' => $camIndex,
                                'exit_code' => $exitCode,
                                'file_exists' => file_exists($cacheWebp)
                            ], 'app');
                        }
                        break;
                    }
                    if ((microtime(true) - $startTs) > $transcodeTimeout) {
                        $timedOut = true;
                        @proc_terminate($processWebp);
                        @proc_close($processWebp);
                        aviationwx_log('warning', 'webcam WEBP generation timed out', [
                            'airport' => $airportId,
                            'cam' => $camIndex,
                            'timeout' => $transcodeTimeout
                        ], 'app');
                        break;
                    }
                    usleep(50000); // 50ms
                }
            } else {
                aviationwx_log('warning', 'webcam WEBP generation proc_open failed', [
                    'airport' => $airportId,
                    'cam' => $camIndex
                ], 'app');
            }
        }
        
        return true;
    } else {
        // Failure: record and update backoff
        $lastErr = @json_decode(@file_get_contents($cacheFile . '.error.json'), true);
        $sev = mapErrorSeverity($lastErr['code'] ?? 'unknown');
        recordFailure($airportId, $camIndex, $sev);
        
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
$etagVal = $etag($targetFile);

// Conditional requests for stale file
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
    'fmt' => $fmt,
    'cache_age' => $cacheAge
], 'user');

// Check circuit breaker early before starting background refresh
$circuit = checkCircuitBreaker($airportId, $camIndex);
$shouldRefresh = !$circuit['skip'];

// Serve stale cache immediately (already validated as valid image above)
ob_end_clean(); // Clear buffer before sending file (consistent with fresh cache path)

if (!serveFile($targetFile, $ctype)) {
    aviationwx_log('error', 'webcam failed to serve stale file', ['airport' => $airportId, 'cam' => $camIndex, 'file' => $targetFile], 'app');
    servePlaceholder();
    exit;
}

// Only start background refresh if circuit breaker allows
if (!$shouldRefresh) {
    aviationwx_log('info', 'webcam background refresh skipped - circuit breaker open', [
        'airport' => $airportId,
        'cam' => $camIndex,
        'failures' => $circuit['failures'] ?? 0,
        'backoff_remaining' => $circuit['backoff_remaining']
    ], 'app');
    exit; // Exit early if circuit breaker is open
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
$lockCleanedUp = false; // Track if lock has been cleaned up to prevent double cleanup

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
                return; // Already cleaned up
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
        exit; // Exit silently - another process is handling the refresh
    }
} else {
    // Couldn't create lock file, but continue anyway (non-critical)
    aviationwx_log('warning', 'webcam background refresh lock file creation failed', [
        'airport' => $airportId,
        'cam' => $camIndex
    ], 'app');
}

// Flush output to client immediately, then refresh in background
if (function_exists('fastcgi_finish_request')) {
    // FastCGI - finish request but keep script running
    fastcgi_finish_request();
    // Set time limit for background refresh
    set_time_limit(45);
    aviationwx_log('info', 'webcam background refresh started', [
        'airport' => $airportId,
        'cam' => $camIndex,
        'cache_age' => $cacheAge,
        'refresh_interval' => $perCamRefresh
    ], 'app');
} else {
    // Regular PHP - output already flushed above
    // Set time limit for background refresh
    set_time_limit(45);
    aviationwx_log('info', 'webcam background refresh started', [
        'airport' => $airportId,
        'cam' => $camIndex,
        'cache_age' => $cacheAge,
        'refresh_interval' => $perCamRefresh
    ], 'app');
}

try {
    // Check connection status periodically during background refresh
    $refreshStartTime = time();
    $maxRefreshTime = BACKGROUND_REFRESH_MAX_TIME;
    
    // Fetch fresh image
    $freshCacheFile = $cacheJpg; // Always fetch JPG first
    $freshCacheWebp = $cacheWebp;
    $success = fetchWebcamImageBackground($airportId, $camIndex, $cam, $freshCacheFile, $freshCacheWebp);
    
    // Check if client disconnected (only if not using fastcgi_finish_request)
    if (!function_exists('fastcgi_finish_request') && function_exists('connection_status')) {
        if (connection_status() !== CONNECTION_NORMAL) {
            aviationwx_log('info', 'webcam background refresh aborted - client disconnected', [
                'airport' => $airportId,
                'cam' => $camIndex
            ], 'app');
            // Lock will be cleaned up by shutdown function
            exit;
        }
    }
    
    // Check if we're running out of time
    if ((time() - $refreshStartTime) > $maxRefreshTime) {
        aviationwx_log('warning', 'webcam background refresh approaching timeout, stopping', [
            'airport' => $airportId,
            'cam' => $camIndex,
            'elapsed' => time() - $refreshStartTime
        ], 'app');
        // Lock will be cleaned up by shutdown function
        exit;
    }
    
    if ($success) {
        aviationwx_log('info', 'webcam background refresh completed successfully', [
            'airport' => $airportId,
            'cam' => $camIndex
        ], 'app');
    } else {
        // Only log as warning if it was an actual failure (not skipped due to circuit breaker)
        // fetchWebcamImageBackground already logs circuit breaker skips
        aviationwx_log('warning', 'webcam background refresh failed', [
            'airport' => $airportId,
            'cam' => $camIndex
        ], 'app');
    }
} finally {
    // Always release lock (prefer explicit cleanup over shutdown function)
    if ($lockAcquired && $lockFp !== false && !$lockCleanedUp) {
        @flock($lockFp, LOCK_UN);
        @fclose($lockFp);
        if (file_exists($lockFile)) {
            @unlink($lockFile);
        }
        $lockCleanedUp = true;
    }
}

// Exit silently after background refresh
exit;
