<?php
/**
 * Webcam Image Fetcher
 * Serves cached webcam images with background refresh support
 * 
 * When cache is stale, serves stale cache immediately and triggers background refresh
 * Similar to weather.php's stale-while-revalidate pattern
 */

// Start output buffering IMMEDIATELY to catch any output from included files
ob_start();

require_once __DIR__ . '/config-utils.php';
require_once __DIR__ . '/rate-limit.php';
require_once __DIR__ . '/logger.php';

// Include webcam fetch functions for background refresh
require_once __DIR__ . '/fetch-webcam-safe.php';

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

// Set request ID after Content-Type to ensure it's not overridden
header('X-Request-ID: ' . $reqId);

$rawAirportId = $_GET['id'] ?? '';
$camIndex = isset($_GET['cam']) ? intval($_GET['cam']) : 0;

if (empty($rawAirportId) || !validateAirportId($rawAirportId)) {
    aviationwx_log('error', 'webcam invalid airport id', ['id' => $rawAirportId], 'user');
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
    aviationwx_log('error', 'webcam config missing or cam index invalid', ['airport' => $airportId, 'cam' => $camIndex], 'app');
    servePlaceholder();
}

$cam = $config['airports'][$airportId]['webcams'][$camIndex];
$cacheDir = __DIR__ . '/cache/webcams';
$base = $cacheDir . '/' . $airportId . '_' . $camIndex;
$cacheJpg = $base . '.jpg';
$cacheWebp = $base . '.webp';

// Create cache directory if it doesn't exist
if (!is_dir($cacheDir)) {
    @mkdir($cacheDir, 0755, true);
}

// Check if requesting timestamp only (for frontend to get latest mtime)
// Exempt timestamp requests from rate limiting (they're lightweight and frequent)
if (isset($_GET['mtime']) && $_GET['mtime'] === '1') {
    // Content-Type already set earlier for JSON requests
    header('Cache-Control: no-cache, no-store, must-revalidate'); // Don't cache timestamp responses
    header('Pragma: no-cache');
    header('Expires: 0');
    // Rate limit headers (for observability; mtime endpoint is not limited)
    if (function_exists('getRateLimitRemaining')) {
        $rl = getRateLimitRemaining('webcam_api', 100, 60);
        if (is_array($rl)) {
            header('X-RateLimit-Limit: 100');
            header('X-RateLimit-Remaining: ' . (int)$rl['remaining']);
            header('X-RateLimit-Reset: ' . (int)$rl['reset']);
        }
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
        aviationwx_log('warning', 'webcam rate-limited, serving cached', ['airport' => $airportId, 'cam' => $camIndex, 'fmt' => $fmt], 'app');
        $mtime = @filemtime($fallback) ?: time();
        aviationwx_maybe_log_alert();
        // Update Content-Type if serving WEBP
        if ($ctype !== 'image/jpeg') {
            header('Content-Type: ' . $ctype);
        }
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

// Update Content-Type if serving WEBP (already set to image/jpeg at top)
if ($ctype !== 'image/jpeg') {
    header('Content-Type: ' . $ctype, true);
}
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
    
    aviationwx_log('info', 'webcam serve fresh', ['airport' => $airportId, 'cam' => $camIndex, 'fmt' => $fmt, 'age' => $age], 'user');
    aviationwx_maybe_log_alert();
    ob_end_clean(); // Clear any buffer before sending image (end and clean in one call)
    readfile($targetFile);
    exit;
}

/**
 * Fetch a single webcam image in background (for background refresh)
 * @param string $airportId
 * @param int $camIndex
 * @param array $cam Camera config
 * @param string $cacheFile Target cache file path
 * @param string $cacheWebp Target WEBP cache file path
 * @return bool Success status
 */
function fetchWebcamImageBackground($airportId, $camIndex, $cam, $cacheFile, $cacheWebp) {
    $url = $cam['url'];
    $transport = isset($cam['rtsp_transport']) ? strtolower($cam['rtsp_transport']) : 'tcp';
    
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
            $fetchTimeout = isset($cam['rtsp_fetch_timeout']) ? intval($cam['rtsp_fetch_timeout']) : intval(getenv('RTSP_TIMEOUT') ?: 10);
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
        $DEFAULT_TRANSCODE_TIMEOUT = 8;
        $transcodeTimeout = isset($cam['transcode_timeout']) ? max(2, intval($cam['transcode_timeout'])) : $DEFAULT_TRANSCODE_TIMEOUT;
        
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

// Cache expired or file not found - serve stale cache if available, otherwise placeholder
if (file_exists($targetFile)) {
    $mtime = filemtime($targetFile);
    $etagVal = $etag($targetFile);
    $cacheAge = time() - $mtime;
    
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
    
    // Update Content-Type if serving WEBP (already set to image/jpeg earlier)
    if ($ctype !== 'image/jpeg') {
        header('Content-Type: ' . $ctype);
    }
    $hasHash = isset($_GET['v']) && preg_match('/^[a-f0-9]{6,}$/i', $_GET['v']);
    $cc = $hasHash ? 'public, max-age=0, s-maxage=0, must-revalidate, stale-while-revalidate=300' : 'public, max-age=0, must-revalidate, stale-while-revalidate=300';
    header('Cache-Control: ' . $cc); // Stale, revalidate
    if ($hasHash) {
        header('Surrogate-Control: max-age=0, stale-while-revalidate=300');
        header('CDN-Cache-Control: max-age=0, stale-while-revalidate=300');
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
    
    // Serve stale cache immediately
    ob_end_flush(); // Flush buffer before sending file
    readfile($targetFile);
    flush(); // Ensure file is sent
    
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
    
    // Clean up stale locks (older than 5 minutes)
    if (file_exists($lockFile)) {
        $lockAge = time() - filemtime($lockFile);
        if ($lockAge > 300) { // 5 minutes
            @unlink($lockFile);
        }
    }
    
    $lockFp = @fopen($lockFile, 'c+');
    $lockAcquired = false;
    
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
        // Fetch fresh image
        $freshCacheFile = $cacheJpg; // Always fetch JPG first
        $freshCacheWebp = $cacheWebp;
        $success = fetchWebcamImageBackground($airportId, $camIndex, $cam, $freshCacheFile, $freshCacheWebp);
        
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
        // Always release lock
        if ($lockAcquired && $lockFp !== false) {
            @flock($lockFp, LOCK_UN);
            @fclose($lockFp);
            @unlink($lockFile); // Clean up lock file
        }
    }
    
    // Exit silently after background refresh
    exit;
} else {
    aviationwx_log('error', 'webcam no cache, serving placeholder', ['airport' => $airportId, 'cam' => $camIndex, 'fmt' => $fmt], 'user');
    servePlaceholder();
}

