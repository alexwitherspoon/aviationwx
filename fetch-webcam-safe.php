<?php
/**
 * Safe Webcam Image Fetcher
 * Supports MJPEG streams, RTSP streams, and static images
 */

/**
 * Detect webcam source type from URL
 */
function detectWebcamSourceType($url) {
    if (stripos($url, 'rtsp://') === 0 || stripos($url, 'rtsps://') === 0) {
        return 'rtsp';
    }
    
    // Check if URL points to a static image
    if (preg_match('/\.(jpg|jpeg)$/i', $url)) {
        return 'static_jpeg';
    }
    
    if (preg_match('/\.(png)$/i', $url)) {
        return 'static_png';
    }
    
    // MJPEG stream (default)
    return 'mjpeg';
}

/**
 * Fetch a static image (JPEG or PNG)
 */
function fetchStaticImage($url, $cacheFile) {
    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL => $url,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 10,
        CURLOPT_CONNECTTIMEOUT => 5,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_MAXREDIRS => 3,
        CURLOPT_USERAGENT => 'AviationWX Webcam Bot',
        CURLOPT_MAXFILESIZE => 5242880, // Max 5MB
    ]);
    
    $data = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error = curl_error($ch);
    curl_close($ch);
    
    if ($httpCode == 200 && $data && strlen($data) > 100) {
        // Verify it's actually an image
        if (strpos($data, "\xff\xd8") === 0) {
            // JPEG
            file_put_contents($cacheFile, $data);
            return true;
        } elseif (strpos($data, "\x89PNG") === 0) {
            // PNG - convert to JPEG using GD library
            $img = imagecreatefromstring($data);
            if ($img) {
                imagejpeg($img, $cacheFile, 85);
                imagedestroy($img);
                return true;
            }
        }
    }
    return false;
}

/**
 * Fetch frame from RTSP stream
 * 
 * NOTE: RTSP support requires ffmpeg, which may not be available on some hosting environments.
 * 
 * Options for RTSP cameras without ffmpeg support:
 * 1. Use the camera's HTTP snapshot URL instead (most cameras support this)
 * 2. Run a local conversion server that converts RTSP -> MJPEG
 * 3. Use a cloud service to proxy RTSP to HTTP
 * 4. Configure the camera to stream directly as MJPEG
 */
/**
 * Classify RTSP error from ffmpeg output/exit code
 * @param int $exitCode
 * @param string $errorOutput
 * @return string Error code: 'timeout', 'auth', 'tls', 'dns', 'connection', 'unknown'
 */
function classifyRTSPError($exitCode, $errorOutput) {
    $output = strtolower($errorOutput);
    
    // Timeout errors
    if (stripos($output, 'timeout') !== false || $exitCode === 124) {
        return 'timeout';
    }
    
    // Authentication errors
    if (stripos($output, 'unauthorized') !== false || 
        stripos($output, '401') !== false ||
        stripos($output, '403') !== false ||
        stripos($output, 'authentication') !== false) {
        return 'auth';
    }
    
    // TLS/SSL errors
    if (stripos($output, 'ssl') !== false || 
        stripos($output, 'tls') !== false ||
        stripos($output, 'certificate') !== false ||
        stripos($output, 'handshake') !== false) {
        return 'tls';
    }
    
    // DNS/resolution errors
    if (stripos($output, 'name or service not known') !== false ||
        stripos($output, 'could not resolve') !== false ||
        stripos($output, 'getaddrinfo') !== false ||
        stripos($output, 'dns') !== false) {
        return 'dns';
    }
    
    // Connection refused/network errors
    if (stripos($output, 'connection refused') !== false ||
        stripos($output, 'connection reset') !== false ||
        stripos($output, 'network is unreachable') !== false ||
        stripos($output, 'no route to host') !== false) {
        return 'connection';
    }
    
    return 'unknown';
}

/**
 * Map RTSP error code to severity for backoff policy
 * @param string $code
 * @return string 'transient' or 'permanent'
 */
function mapErrorSeverity($code) {
    switch ($code) {
        case 'timeout':
        case 'connection':
        case 'dns':
            return 'transient';
        case 'auth':
        case 'tls':
            return 'permanent';
        default:
            return 'transient';
    }
}

function fetchRTSPFrame($url, $cacheFile, $transport = 'tcp', $timeoutSeconds = 10, $retries = 2, $maxRuntime = 6) {
    $transport = strtolower($transport) === 'udp' ? 'udp' : 'tcp';
    $timeoutUs = max(1, intval($timeoutSeconds)) * 1000000; // microseconds (for -timeout option in ffmpeg 5.0+)
    $attempt = 0;
    $jpegTmp = $cacheFile . '.tmp.jpg';
    @unlink($jpegTmp);
    
    // Check if this is RTSPS (secure RTSP)
    $isRtsps = stripos($url, 'rtsps://') === 0;
    
    // Detect if we're in web context for better output formatting
    $isWeb = !empty($_SERVER['REQUEST_METHOD']);
    
    while ($attempt <= $retries) {
        $attempt++;
        if ($isWeb) {
            echo "<span class='attempt'>Attempt {$attempt}/" . ($retries + 1) . " using {$transport}, timeout {$timeoutSeconds}s</span><br>\n";
        } else {
            echo "    Attempt {$attempt}/" . ($retries + 1) . " using {$transport}, timeout {$timeoutSeconds}s\n";
        }
        
        // Build ffmpeg command with appropriate options
        // For RTSPS, force TCP and add specific flags
        if ($isRtsps) {
            // RTSPS requires TCP transport
            $transport = 'tcp';
        }
        
        // Build ffmpeg command properly
        // Note: ffmpeg 5.0+ uses -timeout instead of -stimeout
        // The timeout is specified in microseconds
        // Build command array for proper escaping
        $cmdArray = [
            'ffmpeg',
            '-hide_banner',
            '-loglevel', 'warning',
            '-rtsp_transport', $transport,
            '-timeout', (string)$timeoutUs
        ];
        
        // Add RTSPS-specific flags if needed
        if ($isRtsps) {
            $cmdArray[] = '-rtsp_flags';
            $cmdArray[] = 'prefer_tcp';
            $cmdArray[] = '-fflags';
            $cmdArray[] = 'nobuffer';
        }
        
        // Add input and output
        $cmdArray[] = '-i';
        $cmdArray[] = $url;
        // Limit capture runtime to avoid hanging streams
        $cmdArray[] = '-t';
        $cmdArray[] = (string)max(1, (int)$maxRuntime);
        $cmdArray[] = '-frames:v';
        $cmdArray[] = '1';
        $cmdArray[] = '-q:v';
        $cmdArray[] = '2';
        $cmdArray[] = '-y';
        $cmdArray[] = $jpegTmp;
        
        // Escape each argument properly
        $cmdEscaped = array_map('escapeshellarg', $cmdArray);
        $cmd = implode(' ', $cmdEscaped) . ' 2>&1';
        
        // Capture both stdout and stderr
        exec($cmd, $output, $code);
        $errorOutput = implode("\n", $output);
        
        if ($code === 0 && file_exists($jpegTmp) && filesize($jpegTmp) > 1000) {
            rename($jpegTmp, $cacheFile);
            if ($isWeb) {
                echo "<span class='success'>✓ Captured frame via ffmpeg</span><br>\n";
            } else {
                echo "    ✓ Captured frame via ffmpeg\n";
            }
            return true;
        }
        
        // Classify error for observability
        $errorCode = classifyRTSPError($code, $errorOutput);
        
        // Store error code in a metadata file for diagnostics
        $errorFile = $cacheFile . '.error.json';
        @file_put_contents($errorFile, json_encode([
            'code' => $errorCode,
            'timestamp' => time(),
            'exit_code' => $code,
            'attempt' => $attempt
        ]), LOCK_EX);
        
        // Show detailed error for debugging (with sanitization)
        if ($isWeb) {
            echo "<span class='error'>✗ ffmpeg failed (code {$code}, type: {$errorCode})</span><br>\n";
        } else {
            echo "    ✗ ffmpeg failed (code {$code}, type: {$errorCode})\n";
        }
        
        if (!empty($errorOutput)) {
            // Sanitize error output: remove sensitive info (URLs with credentials, IPs, file paths)
            $sanitizeError = function($line) {
                // Remove URLs (may contain credentials)
                $line = preg_replace('/https?:\/\/[^\s]+/', '[URL_REDACTED]', $line);
                $line = preg_replace('/rtsp[s]?:\/\/[^\s]+/', '[RTSP_URL_REDACTED]', $line);
                // Remove file paths
                $line = preg_replace('/\/[^\s:]+/', '[PATH_REDACTED]', $line);
                // Remove IP addresses
                $line = preg_replace('/\b\d{1,3}\.\d{1,3}\.\d{1,3}\.\d{1,3}\b/', '[IP_REDACTED]', $line);
                // Remove email-like patterns
                $line = preg_replace('/\b[A-Za-z0-9._%+-]+@[A-Za-z0-9.-]+\.[A-Z|a-z]{2,}\b/', '[EMAIL_REDACTED]', $line);
                return $line;
            };
            
            // Extract meaningful error messages (avoid verbose output)
            $errorLines = array_filter($output, function($line) {
                $line = trim($line);
                return !empty($line) && (
                    stripos($line, 'error') !== false || 
                    stripos($line, 'failed') !== false ||
                    stripos($line, 'timeout') !== false ||
                    stripos($line, 'connection') !== false ||
                    stripos($line, 'refused') !== false ||
                    stripos($line, 'unrecognized') !== false
                );
            });
            if (!empty($errorLines)) {
                $shownErrors = array_slice($errorLines, 0, 2); // Show max 2 error lines
                foreach ($shownErrors as $errLine) {
                    $sanitized = $sanitizeError($errLine);
                    $cleanErr = htmlspecialchars(trim($sanitized), ENT_QUOTES | ENT_HTML5, 'UTF-8');
                    if ($isWeb) {
                        echo "<span class='error' style='margin-left: 20px; font-size: 0.9em;'>" . $cleanErr . "</span><br>\n";
                    } else {
                        echo "      " . trim($sanitized) . "\n";
                    }
                }
            }
        }
        
        @unlink($jpegTmp);
        
        // If it's RTSPS and TCP failed, we could try different options on retry
        if ($isRtsps && $attempt < ($retries + 1)) {
            // RTSPS should use TCP, but we might need different flags
            usleep(500000); // Wait 0.5s between retries
        }
    }
    return false;
}

/**
 * Fetch first JPEG frame from MJPEG stream (original implementation)
 */
function fetchMJPEGStream($url, $cacheFile) {
    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL => $url,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 10,
        CURLOPT_CONNECTTIMEOUT => 5,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_MAXREDIRS => 3,
        CURLOPT_USERAGENT => 'AviationWX Webcam Bot',
        CURLOPT_HTTPHEADER => ['Connection: close'],
    ]);
    
    // Set up to stop after we get one JPEG frame
    $startTime = time();
    $data = '';
    $outputHandler = function($ch, $data_chunk) use (&$data, $startTime) {
        $data .= $data_chunk;
        
        // Look for JPEG end marker
        if (strpos($data, "\xff\xd9") !== false) {
            return 0; // Stop receiving data
        }
        
        // Safety: stop if data gets too large (max 2MB)
        if (strlen($data) > 2097152) {
            return 0;
        }
        
        // Safety: stop if taking too long
        if (time() - $startTime > 8) {
            return 0;
        }
        
        return strlen($data_chunk);
    };
    
    curl_setopt($ch, CURLOPT_WRITEFUNCTION, $outputHandler);
    
    $result = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error = curl_error($ch);
    curl_close($ch);
    
    if ($httpCode == 200 && $data && strlen($data) > 1000) {
        // Extract JPEG from data (handle multipart MJPEG if needed)
        $jpegStart = strpos($data, "\xff\xd8"); // JPEG start marker
        $jpegEnd = strpos($data, "\xff\xd9"); // JPEG end marker
        
        if ($jpegStart !== false && $jpegEnd !== false) {
            $jpegData = substr($data, $jpegStart, $jpegEnd - $jpegStart + 2);
            file_put_contents($cacheFile, $jpegData);
            return true;
        }
    }
    return false;
}

require_once __DIR__ . '/config-utils.php';
require_once __DIR__ . '/logger.php';

/**
 * Circuit breaker: check if camera should be skipped due to backoff
 * @param string $airportId
 * @param int $camIndex
 * @return array ['skip' => bool, 'reason' => string, 'backoff_remaining' => int]
 */
function checkCircuitBreaker($airportId, $camIndex) {
    $backoffFile = __DIR__ . '/cache/backoff.json';
    $key = $airportId . '_' . $camIndex;
    $now = time();
    
    if (!file_exists($backoffFile)) {
        return ['skip' => false, 'reason' => '', 'backoff_remaining' => 0];
    }
    
    $backoffData = @json_decode(file_get_contents($backoffFile), true) ?: [];
    
    if (!isset($backoffData[$key])) {
        return ['skip' => false, 'reason' => '', 'backoff_remaining' => 0];
    }
    
    $state = $backoffData[$key];
    $nextAllowed = (int)($state['next_allowed_time'] ?? 0);
    
    if ($nextAllowed > $now) {
        $remaining = $nextAllowed - $now;
        return [
            'skip' => true,
            'reason' => 'circuit_open',
            'backoff_remaining' => $remaining,
            'failures' => (int)($state['failures'] ?? 0)
        ];
    }
    
    return ['skip' => false, 'reason' => '', 'backoff_remaining' => 0];
}

/**
 * Record a failure and update backoff state
 * @param string $airportId
 * @param int $camIndex
 */
function recordFailure($airportId, $camIndex, $severity = 'transient') {
    $backoffFile = __DIR__ . '/cache/backoff.json';
    $key = $airportId . '_' . $camIndex;
    $now = time();
    
    $backoffData = [];
    if (file_exists($backoffFile)) {
        $backoffData = @json_decode(file_get_contents($backoffFile), true) ?: [];
    }
    
    if (!isset($backoffData[$key])) {
        $backoffData[$key] = ['failures' => 0, 'next_allowed_time' => 0, 'last_attempt' => 0, 'backoff_seconds' => 0];
    }
    
    $state = &$backoffData[$key];
    $state['failures'] = ((int)($state['failures'] ?? 0)) + 1;
    $state['last_attempt'] = $now;
    
    // Exponential backoff with severity scaling
    // Base: min(60, 2^failures * 60) seconds, capped at 3600s (1 hour)
    $failures = $state['failures'];
    $base = max(60, pow(2, min($failures - 1, 5)) * 60);
    $multiplier = ($severity === 'permanent') ? 2.0 : 1.0;
    $cap = ($severity === 'permanent') ? 7200 : 3600; // cap 2h for permanent
    $backoffSeconds = min($cap, (int)round($base * $multiplier));
    $state['backoff_seconds'] = $backoffSeconds;
    $state['next_allowed_time'] = $now + $backoffSeconds;
    
    @file_put_contents($backoffFile, json_encode($backoffData, JSON_PRETTY_PRINT), LOCK_EX);
}

/**
 * Record a success and reset backoff state
 * @param string $airportId
 * @param int $camIndex
 */
function recordSuccess($airportId, $camIndex) {
    $backoffFile = __DIR__ . '/cache/backoff.json';
    $key = $airportId . '_' . $camIndex;
    $now = time();
    
    $backoffData = [];
    if (file_exists($backoffFile)) {
        $backoffData = @json_decode(file_get_contents($backoffFile), true) ?: [];
    }
    
    if (!isset($backoffData[$key])) {
        return; // No previous state to reset
    }
    
    // Reset on success
    $backoffData[$key] = [
        'failures' => 0,
        'next_allowed_time' => 0,
        'last_attempt' => $now,
        'backoff_seconds' => 0
    ];
    
    @file_put_contents($backoffFile, json_encode($backoffData, JSON_PRETTY_PRINT), LOCK_EX);
}

// Guard: Only execute main code if not included by another script
// When included, __FILE__ is the included file, when executed directly, it's the script
// We check if this is the main script execution vs an include
if (basename(__FILE__) === basename($_SERVER['SCRIPT_NAME'] ?? '')) {
// Load config (support CONFIG_PATH env override, no cache for CLI script)
$config = loadConfig(false);

if ($config === null || !is_array($config)) {
    die("Error: Could not load configuration\n");
}

// Check if we're in a web context (add HTML) or CLI (plain text)
$isWeb = !empty($_SERVER['REQUEST_METHOD']);

// Start script timing
$scriptStartTime = microtime(true);
$scriptStartTimestamp = time();

// Log script start
aviationwx_log('info', 'webcam fetch script started', [
    'trigger' => $isWeb ? 'web' : 'cron',
    'airports_count' => count($config['airports'] ?? [])
], 'app');

$cacheDir = __DIR__ . '/cache/webcams';
if (!is_dir($cacheDir)) {
    mkdir($cacheDir, 0755, true);
}

// Ensure backoff cache file directory exists
$backoffFile = __DIR__ . '/cache/backoff.json';
$backoffDir = dirname($backoffFile);
if (!is_dir($backoffDir)) {
    @mkdir($backoffDir, 0755, true);
}

if ($isWeb) {
    header('Content-Type: text/html; charset=utf-8');
    echo "<!DOCTYPE html><html><head><title>AviationWX Webcam Fetcher</title>";
    echo "<style>
        body { font-family: monospace; padding: 20px; background: #f5f5f5; }
        .header { background: #333; color: #fff; padding: 10px; margin: -20px -20px 20px -20px; }
        .airport { background: #fff; padding: 15px; margin-bottom: 15px; border-left: 4px solid #007bff; }
        .webcam { margin: 10px 0; padding: 10px; background: #f9f9f9; border-radius: 4px; }
        .success { color: #28a745; font-weight: bold; }
        .error { color: #dc3545; font-weight: bold; }
        .info { color: #666; }
        .attempt { color: #856404; margin-left: 20px; font-size: 0.9em; }
        .url { word-break: break-all; color: #0066cc; }
        pre { margin: 5px 0; white-space: pre-wrap; }
    </style></head><body>";
    echo '<div class="header"><h2>🔌 AviationWX Webcam Fetcher</h2></div>';
} else {
    echo "AviationWX Webcam Fetcher\n";
    echo "==========================\n\n";
}

foreach ($config['airports'] as $airportId => $airport) {
    $airportStartTime = microtime(true);
    
    if (!isset($airport['webcams'])) {
        if ($isWeb) {
            echo "<div class='info'>No webcams configured for {$airportId}</div>\n";
        } else {
            echo "No webcams configured for {$airportId}\n";
        }
        continue;
    }
    
    $webcamCount = count($airport['webcams']);
    aviationwx_log('info', 'airport processing started', [
        'airport' => $airportId,
        'webcams_count' => $webcamCount
    ], 'app');
    
    if ($isWeb) {
        echo "<div class='airport'><h3>✈️ Airport: {$airportId} ({$airport['name']})</h3>\n";
    } else {
        echo "Airport: {$airportId} ({$airport['name']})\n";
    }
    $defaultWebcamRefresh = getenv('WEBCAM_REFRESH_DEFAULT') !== false ? intval(getenv('WEBCAM_REFRESH_DEFAULT')) : 60;
    $airportWebcamRefresh = isset($airport['webcam_refresh_seconds']) ? intval($airport['webcam_refresh_seconds']) : $defaultWebcamRefresh;
    
    $airportStats = [
        'skipped_fresh' => 0,
        'skipped_circuit' => 0,
        'fetched' => 0,
        'failed' => 0
    ];
    
    foreach ($airport['webcams'] as $index => $cam) {
        $camStartTime = microtime(true);
        $cacheFileBase = $cacheDir . '/' . $airportId . '_' . $index;
        $cacheFile = $cacheFileBase . '.jpg';
        $cacheWebp = $cacheFileBase . '.webp';
        $camName = $cam['name'] ?? "Cam {$index}";
        $url = $cam['url'];
        $transport = isset($cam['rtsp_transport']) ? strtolower($cam['rtsp_transport']) : 'tcp';
        $perCamRefresh = isset($cam['refresh_seconds']) ? intval($cam['refresh_seconds']) : $airportWebcamRefresh;
        
        if ($isWeb) {
            echo "<div class='webcam'>";
            echo "<strong>{$camName}</strong><br>";
            echo "<span class='info'>URL: <span class='url'>{$url}</span></span><br>";
            echo "<span class='info'>Refresh threshold: {$perCamRefresh}s</span><br>";
        } else {
            echo "\n  Fetching: {$camName}...\n";
            echo "    URL: {$url}\n";
            echo "    Refresh threshold: {$perCamRefresh}s\n";
        }
        
        // Check cache age first
        $cacheAge = null;
        $cacheExists = file_exists($cacheFile);
        if ($cacheExists) {
            $cacheAge = time() - filemtime($cacheFile);
        }
        
        // Skip fetch if cache is fresh
        if ($cacheExists && $cacheAge < $perCamRefresh) {
            $airportStats['skipped_fresh']++;
            aviationwx_log('info', 'webcam skipped - fresh cache', [
                'airport' => $airportId,
                'cam' => $index,
                'cache_age' => $cacheAge,
                'refresh_threshold' => $perCamRefresh
            ], 'app');
            if ($isWeb) {
                echo "<span class='success'>✓ Skipped (fresh cache, age {$cacheAge}s)</span></div>\n";
            } else {
                echo "    ✓ Skipped (fresh cache, age {$cacheAge}s)\n";
            }
            continue;
        }
        
        // Check circuit breaker: skip if in backoff period
        $circuit = checkCircuitBreaker($airportId, $index);
        if ($circuit['skip']) {
            $remaining = $circuit['backoff_remaining'];
            $failures = $circuit['failures'] ?? 0;
            $airportStats['skipped_circuit']++;
            aviationwx_log('info', 'webcam skipped - circuit breaker open', [
                'airport' => $airportId,
                'cam' => $index,
                'failures' => $failures,
                'backoff_remaining' => $remaining
            ], 'app');
            $mins = floor($remaining / 60);
            $secs = $remaining % 60;
            if ($isWeb) {
                echo "<span class='info'>⏸️ Skipped (circuit open: {$failures} failure(s), backoff {$mins}m {$secs}s remaining)</span></div>\n";
            } else {
                echo "    ⏸️ Skipped (circuit open: {$failures} failure(s), backoff {$mins}m {$secs}s remaining)\n";
            }
            continue;
        }
        
        // Log fetch attempt start
        aviationwx_log('info', 'webcam fetch attempt', [
            'airport' => $airportId,
            'cam' => $index,
            'cache_age' => $cacheAge,
            'refresh_threshold' => $perCamRefresh
        ], 'app');
        
        // Determine source type and handle accordingly
        // Allow explicit type override per camera in config
        $sourceType = isset($cam['type']) ? strtolower(trim($cam['type'])) : detectWebcamSourceType($url);
        if ($isWeb) {
            echo "<span class='info'>Type: {$sourceType}</span><br>";
        } else {
            echo "    Type: {$sourceType}\n";
        }
        
        $fetchStartTime = microtime(true);
        $success = false;
        switch ($sourceType) {
            case 'rtsp':
                // RTSP stream - use ffmpeg to capture a frame with per-camera settings
                $fetchTimeout = isset($cam['rtsp_fetch_timeout']) ? intval($cam['rtsp_fetch_timeout']) : intval(getenv('RTSP_TIMEOUT') ?: 10);
                $maxRuntime = isset($cam['rtsp_max_runtime']) ? intval($cam['rtsp_max_runtime']) : 6;
                $success = fetchRTSPFrame($url, $cacheFile, $transport, $fetchTimeout, 2, $maxRuntime);
                break;
                
            case 'static_jpeg':
            case 'static_png':
                // Static image - simple download
                $success = fetchStaticImage($url, $cacheFile);
                break;
                
            case 'mjpeg':
            default:
                // MJPEG stream - extract first JPEG frame
                $success = fetchMJPEGStream($url, $cacheFile);
                break;
        }
        $fetchDuration = round((microtime(true) - $fetchStartTime) * 1000, 2); // milliseconds
        
        if ($success && file_exists($cacheFile) && filesize($cacheFile) > 0) {
            // Success: reset circuit breaker
            recordSuccess($airportId, $index);
            $size = filesize($cacheFile);
            $airportStats['fetched']++;
            
            aviationwx_log('info', 'webcam fetch success', [
                'airport' => $airportId,
                'cam' => $index,
                'type' => $sourceType,
                'bytes' => $size,
                'fetch_duration_ms' => $fetchDuration
            ], 'app');
            
            if ($isWeb) {
                echo "<span class='success'>✓ Saved " . number_format($size) . " bytes</span><br>\n";
            } else {
                echo "    ✓ Saved {$size} bytes\n";
            }
            // Generate WEBP in background
            if ($isWeb) {
                echo "<span class='info'>Generating WEBP in background...</span><br>\n";
            } else {
                echo "    Generating WEBP in background...\n";
            }
            
            $DEFAULT_TRANSCODE_TIMEOUT = 8; // seconds
            $transcodeTimeout = isset($cam['transcode_timeout']) ? max(2, intval($cam['transcode_timeout'])) : $DEFAULT_TRANSCODE_TIMEOUT;
            $transcodeStartTime = microtime(true);
            $cmdWebp = sprintf("ffmpeg -hide_banner -loglevel error -y -i %s -frames:v 1 -q:v 30 -compression_level 6 -preset default %s", escapeshellarg($cacheFile), escapeshellarg($cacheWebp));
            
            // Start both processes in background
            $pipesWebp = [];
            $processes = [];
            
            // Start WEBP generation
            $processWebp = proc_open($cmdWebp . ' 2>&1', [
                0 => ['pipe', 'r'],
                1 => ['pipe', 'w'],
                2 => ['pipe', 'w']
            ], $pipesWebp);
            
            if (is_resource($processWebp)) {
                fclose($pipesWebp[0]); // Close stdin
                $processes[] = ['proc' => $processWebp, 'type' => 'webp', 'pipes' => $pipesWebp, 'cache' => $cacheWebp];
            }
            
            
            // Wait for all processes to complete
            $results = [];
            $startTs = microtime(true);
            while (!empty($processes)) {
                foreach ($processes as $key => $procData) {
                    $status = proc_get_status($procData['proc']);
                    
                    if (!$status['running']) {
                        // Process finished
                        proc_close($procData['proc']);
                        
                        // Check if output file exists
                        $success = file_exists($procData['cache']) && filesize($procData['cache']) > 0;
                        $results[$procData['type']] = $success;
                        
                        if ($success) {
                            if ($isWeb) {
                                echo "<span class='success'>✓ Generated " . strtoupper($procData['type']) . "</span><br>\n";
                            } else {
                                echo "    ✓ Generated " . strtoupper($procData['type']) . "\n";
                            }
                        } else {
                            if ($isWeb) {
                                echo "<span class='error'>✗ WEBP generation failed</span><br>\n";
                            } else {
                                echo "    ✗ WEBP generation failed\n";
                            }
                        }
                        
                        unset($processes[$key]);
                    }
                }
                
                // Small sleep to avoid busy-waiting
                if (!empty($processes)) {
                    // Enforce overall transcode timeout
                    if ((microtime(true) - $startTs) > $transcodeTimeout) {
                        // Kill remaining processes
                        foreach ($processes as $pk => $pd) {
                            @proc_terminate($pd['proc']);
                        }
                        // Let the loop detect completion next iteration
                    }
                    usleep(50000); // 50ms
                }
            }
            
            $transcodeDuration = round((microtime(true) - $transcodeStartTime) * 1000, 2);
            $webpSuccess = isset($results['webp']) && $results['webp'];
            aviationwx_log('info', 'webcam transcode completed', [
                'airport' => $airportId,
                'cam' => $index,
                'webp_success' => $webpSuccess,
                'transcode_duration_ms' => $transcodeDuration
            ], 'app');
            
            $camDuration = round((microtime(true) - $camStartTime) * 1000, 2);
            aviationwx_log('info', 'webcam processing completed', [
                'airport' => $airportId,
                'cam' => $index,
                'total_duration_ms' => $camDuration,
                'fetch_duration_ms' => $fetchDuration,
                'transcode_duration_ms' => $transcodeDuration
            ], 'app');
        } else {
            // Failure: record and update backoff with severity mapping
            // Try to read last RTSP error code for severity (if exists)
            $lastErr = @json_decode(@file_get_contents($cacheFile . '.error.json'), true);
            $sev = mapErrorSeverity($lastErr['code'] ?? 'unknown');
            recordFailure($airportId, $index, $sev);
            $airportStats['failed']++;
            $circuit = checkCircuitBreaker($airportId, $index);
            $backoffSecs = $circuit['backoff_remaining'];
            $failures = $circuit['failures'] ?? 0;
            
            aviationwx_log('error', 'webcam fetch failure', [
                'airport' => $airportId,
                'cam' => $index,
                'type' => $sourceType,
                'severity' => $sev,
                'fetch_duration_ms' => $fetchDuration,
                'failures' => $failures,
                'backoff_remaining' => $backoffSecs,
                'error_code' => $lastErr['code'] ?? null
            ], 'app');
            
            $mins = floor($backoffSecs / 60);
            $secs = $backoffSecs % 60;
            
            if ($isWeb) {
                echo "<span class='error'>✗ Failed to cache image</span><br>\n";
                echo "<span class='info'>Circuit breaker: next attempt in {$mins}m {$secs}s</span><br>\n";
            } else {
                echo "    ✗ Failed to cache image\n";
                echo "    Circuit breaker: next attempt in {$mins}m {$secs}s\n";
            }
        }
        
        if ($isWeb) {
            echo "</div>"; // Close webcam div
        }
    }
    
    if ($isWeb) {
        echo "</div>"; // Close airport div
    }
    
    $airportDuration = round((microtime(true) - $airportStartTime) * 1000, 2);
    aviationwx_log('info', 'airport processing completed', [
        'airport' => $airportId,
        'duration_ms' => $airportDuration,
        'stats' => $airportStats
    ], 'app');
}

if ($isWeb) {
    echo "<div style='margin-top: 20px; padding: 15px; background: #fff; border-left: 4px solid #28a745;'>";
    echo "<strong>✓ Done!</strong> Webcam images cached.<br>";
} else {
    echo "\n\nDone! Webcam images cached.\n";
}

// Log script completion
$scriptDuration = round((microtime(true) - $scriptStartTime) * 1000, 2);
$totalAirports = count($config['airports'] ?? []);

aviationwx_log('info', 'webcam fetch script completed', [
    'duration_ms' => $scriptDuration,
    'airports_processed' => $totalAirports,
    'trigger' => $isWeb ? 'web' : 'cron'
], 'app');

aviationwx_maybe_log_alert();

// Build dynamic URL based on environment
$protocol = 'https';
if (isset($_SERVER['HTTP_X_FORWARDED_PROTO'])) {
    $protocol = strtolower($_SERVER['HTTP_X_FORWARDED_PROTO']);
} elseif (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') {
    $protocol = 'https';
} elseif (getenv('DOMAIN')) {
    $protocol = 'https'; // Default to HTTPS for production
}

$domain = getenv('DOMAIN') ?: 'aviationwx.org';
if (isset($_SERVER['HTTP_HOST'])) {
    $hostParts = explode('.', $_SERVER['HTTP_HOST']);
    // Use base domain if accessed via subdomain
    if (count($hostParts) >= 3) {
        $domain = implode('.', array_slice($hostParts, -2)); // Get last 2 parts (domain.tld)
    } else {
        $domain = $_SERVER['HTTP_HOST'];
    }
}

// Show URLs for all airports that were processed
if (isset($config['airports']) && is_array($config['airports'])) {
    foreach ($config['airports'] as $airportId => $airport) {
        if (isset($airport['webcams']) && is_array($airport['webcams'])) {
            $airportName = $airport['name'] ?? $airportId;
            $subdomainUrl = "{$protocol}://{$airportId}.{$domain}";
            $queryUrl = "{$protocol}://{$domain}/?airport={$airportId}";
            if ($isWeb) {
                echo "<span class='info'>View {$airportName} at: <a href=\"{$subdomainUrl}\" target='_blank'>{$subdomainUrl}</a> or <a href=\"{$queryUrl}\" target='_blank'>{$queryUrl}</a></span><br>\n";
            } else {
                echo "View {$airportName} at: {$subdomainUrl} or {$queryUrl}\n";
            }
        }
    }
} else {
    if ($isWeb) {
        echo "<span class='info'>View at: <a href=\"{$protocol}://{$domain}/?airport=<airport-id>\">{$protocol}://{$domain}/?airport=&lt;airport-id&gt;</a></span><br>\n";
    } else {
        echo "View at: {$protocol}://{$domain}/?airport=<airport-id>\n";
    }
}

if ($isWeb) {
    echo "</div></body></html>";
}

} // End of guard: only execute if script is run directly (not included)

