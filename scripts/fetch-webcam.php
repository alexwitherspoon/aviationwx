<?php
/**
 * Safe Webcam Image Fetcher
 * Supports MJPEG streams, RTSP streams, and static images
 */

// Load test mocking if available
if (file_exists(__DIR__ . '/../lib/test-mocks.php')) {
    require_once __DIR__ . '/../lib/test-mocks.php';
}

// Load mock webcam generator for development mode
if (file_exists(__DIR__ . '/../lib/mock-webcam.php')) {
    require_once __DIR__ . '/../lib/mock-webcam.php';
}

// Load error detector early (needed by fetch functions)
require_once __DIR__ . '/../lib/constants.php';
require_once __DIR__ . '/../lib/webcam-error-detector.php';
require_once __DIR__ . '/../lib/webcam-format-generation.php';
require_once __DIR__ . '/../lib/webcam-history.php';
require_once __DIR__ . '/../lib/exif-utils.php';

// Verify exiftool is available at startup (required for EXIF handling)
try {
    requireExiftool();
} catch (RuntimeException $e) {
    $errorMsg = "FATAL: " . $e->getMessage();
    if (php_sapi_name() === 'cli') {
        fwrite(STDERR, $errorMsg . "\n");
    } else {
        echo "<p style='color:red;'>$errorMsg</p>";
    }
    exit(1);
}

/**
 * Detect webcam source type from URL
 * 
 * Analyzes URL to determine webcam source type. Checks for RTSP protocol,
 * file extensions, and defaults to MJPEG stream.
 * 
 * @param string $url Webcam URL
 * @return string Source type: 'rtsp', 'static_jpeg', 'static_png', or 'mjpeg'
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
 * 
 * Downloads a static image from URL and saves to cache. Validates image format
 * and converts PNG to JPEG if needed. Uses atomic file operations to prevent
 * corruption during write.
 * 
 * @param string $url Image URL
 * @param string $cacheFile Target cache file path (JPG format)
 * @return bool True on success, false on failure
 */
function fetchStaticImage($url, $cacheFile) {
    // Check for mock response in test mode
    if (function_exists('getMockHttpResponse')) {
        $mockResponse = getMockHttpResponse($url);
        if ($mockResponse !== null) {
            // Use mock response
            $data = $mockResponse;
            $httpCode = 200;
            $error = '';
        } else {
            // Proceed with real request
            $ch = curl_init();
            curl_setopt_array($ch, [
                CURLOPT_URL => $url,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT => CURL_TIMEOUT,
                CURLOPT_CONNECTTIMEOUT => CURL_CONNECT_TIMEOUT,
                CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_MAXREDIRS => 3,
                CURLOPT_USERAGENT => 'AviationWX Webcam Bot',
                CURLOPT_MAXFILESIZE => CACHE_FILE_MAX_SIZE,
            ]);
            
            $data = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $error = curl_error($ch);
            curl_close($ch);
        }
    } else {
        // No mocking available, proceed with real request
        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => CURL_TIMEOUT,
            CURLOPT_CONNECTTIMEOUT => CURL_CONNECT_TIMEOUT,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS => 3,
            CURLOPT_USERAGENT => 'AviationWX Webcam Bot',
            CURLOPT_MAXFILESIZE => CACHE_FILE_MAX_SIZE,
        ]);
        
        $data = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);
    }
    
    if ($error) {
        // Log curl error
        return false;
    }
    
    if ($httpCode == 200 && $data && strlen($data) > 100) {
        // Verify it's actually an image
        if (strpos($data, "\xff\xd8") === 0) {
            // JPEG - write atomically to prevent corruption
            $tmpFile = getUniqueTmpFile($cacheFile);
            if (@file_put_contents($tmpFile, $data) !== false) {
                // Validate image is not an error frame
                $errorCheck = detectErrorFrame($tmpFile);
                if ($errorCheck['is_error']) {
                    aviationwx_log('warning', 'webcam error frame detected, rejecting', [
                        'confidence' => $errorCheck['confidence'],
                        'reasons' => $errorCheck['reasons']
                    ], 'app');
                    @unlink($tmpFile);
                    return false;
                }
                
                // Ensure EXIF timestamp exists (add from mtime if needed)
                if (!ensureExifTimestamp($tmpFile)) {
                    aviationwx_log('warning', 'webcam EXIF timestamp could not be added', [
                        'file' => basename($tmpFile)
                    ], 'app');
                    // Don't fail - timestamp is best-effort for static images
                }
                
                // Validate EXIF timestamp is reasonable
                $exifCheck = validateExifTimestamp($tmpFile);
                if (!$exifCheck['valid']) {
                    aviationwx_log('warning', 'webcam EXIF timestamp invalid, rejecting', [
                        'reason' => $exifCheck['reason'],
                        'timestamp' => $exifCheck['timestamp'] > 0 ? date('Y-m-d H:i:s', $exifCheck['timestamp']) : 'none'
                    ], 'app');
                    @unlink($tmpFile);
                    return false;
                }
                
                // Atomic rename
                if (@rename($tmpFile, $cacheFile)) {
                    return true;
                } else {
                    @unlink($tmpFile);
                }
            }
            return false;
        } elseif (strpos($data, "\x89PNG") === 0) {
            // PNG - convert to JPEG using GD library
            $img = imagecreatefromstring($data);
            if ($img) {
                $tmpFile = getUniqueTmpFile($cacheFile);
                if (@imagejpeg($img, $tmpFile, 85)) {
                    imagedestroy($img);
                    
                    // Validate image is not an error frame
                    $errorCheck = detectErrorFrame($tmpFile);
                    if ($errorCheck['is_error']) {
                        aviationwx_log('warning', 'webcam error frame detected, rejecting', [
                            'confidence' => $errorCheck['confidence'],
                            'reasons' => $errorCheck['reasons']
                        ], 'app');
                        @unlink($tmpFile);
                        return false;
                    }
                    
                    // Ensure EXIF timestamp exists (add from mtime if needed)
                    // PNG converted to JPEG loses original PNG metadata, so add fresh timestamp
                    if (!ensureExifTimestamp($tmpFile)) {
                        aviationwx_log('warning', 'webcam EXIF timestamp could not be added to converted PNG', [
                            'file' => basename($tmpFile)
                        ], 'app');
                    }
                    
                    // Validate EXIF timestamp is reasonable
                    $exifCheck = validateExifTimestamp($tmpFile);
                    if (!$exifCheck['valid']) {
                        aviationwx_log('warning', 'webcam EXIF timestamp invalid, rejecting', [
                            'reason' => $exifCheck['reason'],
                            'timestamp' => $exifCheck['timestamp'] > 0 ? date('Y-m-d H:i:s', $exifCheck['timestamp']) : 'none'
                        ], 'app');
                        @unlink($tmpFile);
                        return false;
                    }
                    
                    // Atomic rename
                    if (@rename($tmpFile, $cacheFile)) {
                        return true;
                    } else {
                        @unlink($tmpFile);
                    }
                } else {
                    imagedestroy($img);
                }
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
 * 
 * Analyzes ffmpeg exit code and error output to categorize RTSP stream errors.
 * Used to determine appropriate circuit breaker severity (transient vs permanent).
 * 
 * @param int $exitCode ffmpeg exit code (124 = timeout)
 * @param string $errorOutput ffmpeg stderr output
 * @return string Error code: 'timeout', 'auth', 'tls', 'dns', 'connection', or 'unknown'
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
 * 
 * Maps classified RTSP errors to severity levels for circuit breaker backoff.
 * Transient errors (timeout, connection, DNS) use normal backoff.
 * Permanent errors (auth, TLS) use 2x multiplier backoff.
 * 
 * @param string $code Error code from classifyRTSPError()
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

/**
 * Check if ffmpeg is available
 * 
 * Checks if ffmpeg binary is available in the system PATH.
 * Uses static caching to avoid repeated system calls.
 * Required for RTSP/RTSPS stream processing.
 * 
 * @return bool True if ffmpeg is available, false otherwise
 */
function isFfmpegAvailable() {
    static $available = null;
    if ($available === null) {
        $output = [];
        $return = 0;
        @exec('ffmpeg -version 2>&1', $output, $return);
        $available = ($return === 0);
    }
    return $available;
}

/**
 * Fetch frame from RTSP stream using ffmpeg
 * 
 * Captures a single frame from RTSP/RTSPS stream using ffmpeg. Uses exponential
 * backoff (1s, 5s, 10s) before each attempt to handle unreliable sources like
 * Blue Iris servers. Error frames are detected and rejected, triggering retry.
 * RTSPS streams are forced to use TCP transport.
 * 
 * @param string $url RTSP/RTSPS stream URL
 * @param string $cacheFile Target cache file path (JPG format)
 * @param string $transport Transport protocol: 'tcp' or 'udp' (default: 'tcp')
 * @param int $timeoutSeconds Connection timeout in seconds (default: RTSP_DEFAULT_TIMEOUT)
 * @param int $retries Number of retry attempts (default: RTSP_DEFAULT_RETRIES, gives 3 total attempts)
 * @param int $maxRuntime Maximum runtime for ffmpeg in seconds (default: RTSP_MAX_RUNTIME)
 * @return bool True on success, false on failure
 */
function fetchRTSPFrame($url, $cacheFile, $transport = 'tcp', $timeoutSeconds = RTSP_DEFAULT_TIMEOUT, $retries = RTSP_DEFAULT_RETRIES, $maxRuntime = RTSP_MAX_RUNTIME) {
    // Check if ffmpeg is available
    if (!isFfmpegAvailable()) {
        aviationwx_log('error', 'ffmpeg not available for RTSP capture', [
            'url' => preg_replace('/:[^:@]*@/', ':****@', $url) // Sanitize URL
        ], 'app');
        return false;
    }
    
    $transport = strtolower($transport) === 'udp' ? 'udp' : 'tcp';
    $timeoutUs = max(1, intval($timeoutSeconds)) * 1000000; // microseconds (for -timeout option in ffmpeg 5.0+)
    $attempt = 0;
    
    // Check if this is RTSPS (secure RTSP)
    $isRtsps = stripos($url, 'rtsps://') === 0;
    
    // Detect if we're in web context for better output formatting
    $isWeb = !empty($_SERVER['REQUEST_METHOD']);
    
    $backoffDelays = defined('RTSP_BACKOFF_DELAYS') ? RTSP_BACKOFF_DELAYS : [1, 5, 10];
    
    while ($attempt <= $retries) {
        $attempt++;
        
        // Backoff gives unreliable sources (e.g., Blue Iris) time to recover
        $backoffIndex = $attempt - 1;
        if (isset($backoffDelays[$backoffIndex])) {
            $backoffSeconds = $backoffDelays[$backoffIndex];
            if ($isWeb) {
                echo "<span class='backoff'>Waiting {$backoffSeconds}s before attempt {$attempt}...</span><br>\n";
            } else {
                echo "    Waiting {$backoffSeconds}s before attempt {$attempt}...\n";
            }
            sleep($backoffSeconds);
        }
        
        $jpegTmp = getUniqueTmpFile($cacheFile) . '.jpg';
        
        if ($isWeb) {
            echo "<span class='attempt'>Attempt {$attempt}/" . ($retries + 1) . " using {$transport}, timeout {$timeoutSeconds}s</span><br>\n";
        } else {
            echo "    Attempt {$attempt}/" . ($retries + 1) . " using {$transport}, timeout {$timeoutSeconds}s\n";
        }
        
        // RTSPS requires TCP transport
        if ($isRtsps) {
            $transport = 'tcp';
        }
        
        // Build ffmpeg command (ffmpeg 5.0+ uses -timeout in microseconds)
        $cmdArray = [
            'ffmpeg',
            '-hide_banner',
            '-loglevel', 'warning',
            '-rtsp_transport', $transport,
            '-timeout', (string)$timeoutUs
        ];
        
        if ($isRtsps) {
            $cmdArray[] = '-rtsp_flags';
            $cmdArray[] = 'prefer_tcp';
            $cmdArray[] = '-fflags';
            $cmdArray[] = 'nobuffer';
        }
        
        $cmdArray[] = '-i';
        $cmdArray[] = $url;
        $cmdArray[] = '-t';
        $cmdArray[] = (string)max(1, (int)$maxRuntime);
        $cmdArray[] = '-frames:v';
        $cmdArray[] = '1';
        $cmdArray[] = '-q:v';
        $cmdArray[] = '2';
        $cmdArray[] = '-y';
        $cmdArray[] = $jpegTmp;
        
        $cmdEscaped = array_map('escapeshellarg', $cmdArray);
        $cmd = implode(' ', $cmdEscaped) . ' 2>&1';
        exec($cmd, $output, $code);
        $errorOutput = implode("\n", $output);
        
        if ($code === 0 && file_exists($jpegTmp) && filesize($jpegTmp) > 1000) {
            // Validate image is not an error frame
            $errorCheck = detectErrorFrame($jpegTmp);
            if ($errorCheck['is_error']) {
                if ($isWeb) {
                    echo "<span class='error'>✗ Error frame detected (confidence: " . round($errorCheck['confidence'] * 100) . "%)</span><br>\n";
                } else {
                    echo "    ✗ Error frame detected (confidence: " . round($errorCheck['confidence'] * 100) . "%)\n";
                }
                aviationwx_log('warning', 'webcam error frame detected from RTSP, rejecting', [
                    'confidence' => $errorCheck['confidence'],
                    'reasons' => $errorCheck['reasons'],
                    'url' => preg_replace('/:[^:@]*@/', ':****@', $url)
                ], 'app');
                @unlink($jpegTmp);
                // Continue to retry
            } else {
                // Ensure EXIF timestamp exists (add from mtime - ffmpeg just created the file)
                if (!ensureExifTimestamp($jpegTmp)) {
                    aviationwx_log('warning', 'webcam EXIF timestamp could not be added to RTSP frame', [
                        'file' => basename($jpegTmp)
                    ], 'app');
                    // Don't fail - continue to validation
                }
                
                // Validate EXIF timestamp is reasonable
                $exifCheck = validateExifTimestamp($jpegTmp);
                if (!$exifCheck['valid']) {
                    if ($isWeb) {
                        echo "<span class='error'>✗ EXIF timestamp invalid: " . htmlspecialchars($exifCheck['reason']) . "</span><br>\n";
                    } else {
                        echo "    ✗ EXIF timestamp invalid: " . $exifCheck['reason'] . "\n";
                    }
                    aviationwx_log('warning', 'webcam EXIF timestamp invalid for RTSP, rejecting', [
                        'reason' => $exifCheck['reason'],
                        'url' => preg_replace('/:[^:@]*@/', ':****@', $url)
                    ], 'app');
                    @unlink($jpegTmp);
                    // Continue to retry
                } elseif (@rename($jpegTmp, $cacheFile)) {
                    if ($isWeb) {
                        echo "<span class='success'>✓ Captured frame via ffmpeg</span><br>\n";
                    } else {
                        echo "    ✓ Captured frame via ffmpeg\n";
                    }
                    return true;
                } else {
                    @unlink($jpegTmp);
                }
            }
        }
        
        $errorCode = classifyRTSPError($code, $errorOutput);
        $errorFile = $cacheFile . '.error.json';
        @file_put_contents($errorFile, json_encode([
            'code' => $errorCode,
            'timestamp' => time(),
            'exit_code' => $code,
            'attempt' => $attempt
        ]), LOCK_EX);
        
        if ($isWeb) {
            echo "<span class='error'>✗ ffmpeg failed (code {$code}, type: {$errorCode})</span><br>\n";
        } else {
            echo "    ✗ ffmpeg failed (code {$code}, type: {$errorCode})\n";
        }
        
        if (!empty($errorOutput)) {
            $sanitizeError = function($line) {
                $line = preg_replace('/https?:\/\/[^\s]+/', '[URL_REDACTED]', $line);
                $line = preg_replace('/rtsp[s]?:\/\/[^\s]+/', '[RTSP_URL_REDACTED]', $line);
                $line = preg_replace('/\/[^\s:]+/', '[PATH_REDACTED]', $line);
                $line = preg_replace('/\b\d{1,3}\.\d{1,3}\.\d{1,3}\.\d{1,3}\b/', '[IP_REDACTED]', $line);
                return $line;
            };
            
            $errorLines = array_filter($output, function($line) {
                $line = trim($line);
                return !empty($line) && (
                    stripos($line, 'error') !== false || 
                    stripos($line, 'failed') !== false ||
                    stripos($line, 'timeout') !== false ||
                    stripos($line, 'connection') !== false
                );
            });
            if (!empty($errorLines)) {
                $shownErrors = array_slice($errorLines, 0, 2);
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
    }
    return false;
}

/**
 * Fetch first JPEG frame from MJPEG stream
 * 
 * Downloads from MJPEG stream and extracts the first complete JPEG frame.
 * Handles both raw MJPEG streams and multipart MJPEG streams with boundaries.
 * Stops after receiving one complete frame (detected by JPEG end marker).
 * 
 * @param string $url MJPEG stream URL
 * @param string $cacheFile Target cache file path (JPG format)
 * @return bool True on success, false on failure
 */
function fetchMJPEGStream($url, $cacheFile) {
    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL => $url,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => CURL_TIMEOUT,
        CURLOPT_CONNECTTIMEOUT => CURL_CONNECT_TIMEOUT,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_MAXREDIRS => 3,
        CURLOPT_USERAGENT => 'AviationWX Webcam Bot',
        CURLOPT_HTTPHEADER => ['Connection: close'],
    ]);
    
    // Set up to stop after we get one complete JPEG frame
    $startTime = time();
    $data = '';
    $foundJpegEnd = false;
    
    $outputHandler = function($ch, $data_chunk) use (&$data, &$foundJpegEnd, $startTime) {
        $data .= $data_chunk;
        
        // Look for JPEG end marker (0xFF 0xD9) - indicates complete JPEG frame
        if (strpos($data, "\xff\xd9") !== false) {
            $foundJpegEnd = true;
            return 0; // Signal curl to stop receiving data
        }
        
        // Safety: stop if data gets too large
        if (strlen($data) > MJPEG_MAX_SIZE) {
            return 0;
        }
        
        // Safety: stop if taking too long
        if (time() - $startTime > MJPEG_MAX_TIME) {
            return 0;
        }
        
        return strlen($data_chunk);
    };
    
    curl_setopt($ch, CURLOPT_WRITEFUNCTION, $outputHandler);
    
    $result = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error = curl_error($ch);
    curl_close($ch);
    
    // Validate we got HTTP 200 and have data
    if ($httpCode !== 200 || empty($data) || strlen($data) < 1000) {
        return false;
    }
    
    // Extract JPEG from data
    // Handles both raw JPEG streams and multipart MJPEG (with boundaries like "--==STILLIMAGEBOUNDARY==")
    $jpegStart = strpos($data, "\xff\xd8"); // JPEG start marker (0xFF 0xD8)
    $jpegEnd = strpos($data, "\xff\xd9");   // JPEG end marker (0xFF 0xD9)
    
    if ($jpegStart === false || $jpegEnd === false || $jpegEnd <= $jpegStart) {
        return false; // No valid JPEG found
    }
    
    // Extract complete JPEG (include end marker)
    $jpegData = substr($data, $jpegStart, $jpegEnd - $jpegStart + 2);
    
    // Validate JPEG is reasonable size (at least 1KB, max CACHE_FILE_MAX_SIZE)
    $jpegSize = strlen($jpegData);
    if ($jpegSize < 1024 || $jpegSize > CACHE_FILE_MAX_SIZE) {
        return false;
    }
    
    // Validate JPEG is actually valid (quick check: verify it can be parsed)
    if (!function_exists('imagecreatefromstring')) {
        // GD library not available - skip validation
    } else {
        $testImg = @imagecreatefromstring($jpegData);
        if ($testImg === false) {
            return false; // Invalid JPEG data
        }
        imagedestroy($testImg);
    }
    
    $tmpFile = getUniqueTmpFile($cacheFile);
    if (@file_put_contents($tmpFile, $jpegData) === false) {
        return false;
    }
    
    // Validate image is not an error frame
    $errorCheck = detectErrorFrame($tmpFile);
    if ($errorCheck['is_error']) {
        aviationwx_log('warning', 'webcam error frame detected from MJPEG, rejecting', [
            'confidence' => $errorCheck['confidence'],
            'reasons' => $errorCheck['reasons']
        ], 'app');
        @unlink($tmpFile);
        return false;
    }
    
    // Ensure EXIF timestamp exists (add from mtime if needed)
    if (!ensureExifTimestamp($tmpFile)) {
        aviationwx_log('warning', 'webcam EXIF timestamp could not be added to MJPEG frame', [
            'file' => basename($tmpFile)
        ], 'app');
        // Don't fail - continue to validation
    }
    
    // Validate EXIF timestamp is reasonable
    $exifCheck = validateExifTimestamp($tmpFile);
    if (!$exifCheck['valid']) {
        aviationwx_log('warning', 'webcam EXIF timestamp invalid for MJPEG, rejecting', [
            'reason' => $exifCheck['reason']
        ], 'app');
        @unlink($tmpFile);
        return false;
    }
    
    if (@rename($tmpFile, $cacheFile)) {
        return true;
    }
    @unlink($tmpFile);
    return false;
}

require_once __DIR__ . '/../lib/config.php';
require_once __DIR__ . '/../lib/logger.php';
require_once __DIR__ . '/../lib/circuit-breaker.php';

// Circuit breaker wrapper functions for backward compatibility
/**
 * Check circuit breaker for webcam (wrapper for backward compatibility)
 * 
 * @param string $airportId Airport ID (e.g., 'kspb')
 * @param int $camIndex Camera index (0-based)
 * @return array Circuit breaker status array
 */
function checkCircuitBreaker($airportId, $camIndex) {
    return checkWebcamCircuitBreaker($airportId, $camIndex);
}

/**
 * Record webcam failure (wrapper for backward compatibility)
 * 
 * @param string $airportId Airport ID (e.g., 'kspb')
 * @param int $camIndex Camera index (0-based)
 * @param string $severity 'transient' or 'permanent' (default: 'transient')
 * @return void
 */
function recordFailure($airportId, $camIndex, $severity = 'transient') {
    recordWebcamFailure($airportId, $camIndex, $severity);
}

/**
 * Record webcam success (wrapper for backward compatibility)
 * 
 * @param string $airportId Airport ID (e.g., 'kspb')
 * @param int $camIndex Camera index (0-based)
 * @return void
 */
function recordSuccess($airportId, $camIndex) {
    recordWebcamSuccess($airportId, $camIndex);
}

// Check for worker mode
$isWorkerMode = false;
$workerAirportId = null;
$workerCamIndex = null;

if (php_sapi_name() === 'cli' && isset($argv) && is_array($argv)) {
    // $argv[0] is script name, $argv[1] is first argument
    if (isset($argv[1]) && $argv[1] === '--worker' && isset($argv[2]) && isset($argv[3])) {
        $isWorkerMode = true;
        $workerAirportId = $argv[2];
        $workerCamIndex = $argv[3];
    }
}

/**
 * Acquire lock for camera to prevent concurrent processing
 * 
 * Creates a file-based lock to prevent multiple processes from processing
 * the same camera simultaneously. Automatically cleans up stale locks.
 * 
 * @param string $airportId Airport ID (e.g., 'kspb')
 * @param int $camIndex Camera index (0-based)
 * @param int $timeout Lock acquisition timeout in seconds (default: 5)
 * @return resource|false File handle if lock acquired, false on timeout/failure
 */
function acquireCameraLock($airportId, $camIndex, $timeout = 5) {
    $lockFile = "/tmp/webcam_lock_{$airportId}_{$camIndex}.lock";
    
    if (file_exists($lockFile)) {
        $lockAge = time() - filemtime($lockFile);
        if ($lockAge > getWorkerTimeout() + 10) {
            @unlink($lockFile);
        }
    }
    
    $fp = @fopen($lockFile, 'c+');
    if (!$fp) {
        return false;
    }
    
    $start = time();
    while (!@flock($fp, LOCK_EX | LOCK_NB)) {
        if (time() - $start > $timeout) {
            @fclose($fp);
            return false;
        }
        usleep(100000);
    }
    
    @ftruncate($fp, 0);
    @fwrite($fp, json_encode([
        'pid' => getmypid(),
        'timestamp' => time(),
        'airport' => $airportId,
        'cam' => $camIndex
    ]));
    @fflush($fp);
    
    return $fp;
}

/**
 * Release camera lock
 * Lock file cleanup handled by caller on success or stale cleanup
 * 
 * @param resource|false $fp Lock file handle
 * @return void
 */
function releaseCameraLock($fp) {
    if ($fp && is_resource($fp)) {
        @flock($fp, LOCK_UN);
        @fclose($fp);
    }
}

/**
 * Generate unique tmp filename to prevent collisions
 * 
 * Creates a unique temporary filename by appending PID, timestamp, and random number.
 * Prevents race conditions when multiple processes write to the same cache file.
 * 
 * @param string $cacheFile Base cache file path
 * @return string Unique temporary file path
 */
function getUniqueTmpFile($cacheFile) {
    return $cacheFile . '.tmp.' . getmypid() . '.' . time() . '.' . mt_rand(1000, 9999);
}

/**
 * Process a single webcam
 * 
 * @param string $airportId Airport ID
 * @param int $camIndex Camera index
 * @param array $cam Camera configuration
 * @param array $airport Airport configuration
 * @param string $cacheDir Cache directory
 * @param string $invocationId Invocation ID for logging
 * @param string $triggerType Trigger type for logging
 * @param bool $isWeb Whether in web context (for output formatting)
 * @return bool True on success, false on failure/skip
 */
function processWebcam($airportId, $camIndex, $cam, $airport, $cacheDir, $invocationId, $triggerType, $isWeb = false) {
    $lockFp = acquireCameraLock($airportId, $camIndex, 5);
    if ($lockFp === false) {
        aviationwx_log('warning', 'webcam processing skipped - could not acquire lock', [
            'invocation_id' => $invocationId,
            'trigger' => $triggerType,
            'airport' => $airportId,
            'cam' => $camIndex
        ], 'app');
        return false;
    }
    
    register_shutdown_function(function() use ($lockFp) {
        releaseCameraLock($lockFp);
    });
    
    $isPush = (isset($cam['type']) && $cam['type'] === 'push') || isset($cam['push_config']);
    if ($isPush) {
        releaseCameraLock($lockFp);
        return false;
    }
    
    // Cleanup any stale staging files from crashed workers
    cleanupStagingFiles($airportId, $camIndex);
    
    $camStartTime = microtime(true);
    $cacheFileBase = $cacheDir . '/' . $airportId . '_' . $camIndex;
    $cacheFile = $cacheFileBase . '.jpg';
    $stagingFile = getStagingFilePath($airportId, $camIndex, 'jpg');
    $camName = $cam['name'] ?? "Cam {$camIndex}";
    $url = $cam['url'] ?? '';
    
    // Mock mode: generate placeholder images for development
    // Activates when shouldMockExternalServices() returns true (test keys, example.com URLs)
    if (function_exists('shouldMockExternalServices') && shouldMockExternalServices()) {
        if (function_exists('generateMockWebcamImage')) {
            $mockData = generateMockWebcamImage($airportId, $camIndex);
            
            // Write to staging file
            if (@file_put_contents($stagingFile, $mockData) !== false) {
                // Get timestamp from source file
                $timestamp = getSourceCaptureTime($stagingFile);
                if ($timestamp <= 0) {
                    $timestamp = time();
                }
                
                // Get input dimensions for variant generation
                $inputDimensions = getImageDimensions($stagingFile);
                if ($inputDimensions === null) {
                    // Fallback to format-only generation
                    $formatResults = generateFormatsSync($stagingFile, $airportId, $camIndex, 'jpg');
                    $promotedFormats = promoteFormats($airportId, $camIndex, $formatResults, 'jpg', $timestamp);
                } else {
                    // Generate all variants and formats in parallel (synchronous wait)
                    $variantResult = generateVariantsSync($stagingFile, $airportId, $camIndex, 'jpg', $inputDimensions);
                    
                    // Promote all successful variants
                    $promotedVariants = promoteVariants(
                        $airportId,
                        $camIndex,
                        $variantResult['results'],
                        'jpg',
                        $timestamp,
                        $variantResult['delete_original'],
                        $variantResult['delete_original'] ? $stagingFile : null
                    );
                    
                    // Convert for logging
                    $promotedFormats = [];
                    foreach ($promotedVariants as $variant => $formats) {
                        $promotedFormats = array_merge($promotedFormats, $formats);
                    }
                    $promotedFormats = array_unique($promotedFormats);
                }
                
                aviationwx_log('info', 'webcam mock generated', [
                    'invocation_id' => $invocationId,
                    'trigger' => $triggerType,
                    'airport' => $airportId,
                    'cam' => $camIndex,
                    'mock_mode' => true,
                    'formats_promoted' => $promotedFormats
                ], 'app');
                
                releaseCameraLock($lockFp);
                return !empty($promotedFormats);
            }
        }
    }
    $transport = isset($cam['rtsp_transport']) ? strtolower($cam['rtsp_transport']) : 'tcp';
    
    $defaultWebcamRefresh = getDefaultWebcamRefresh();
    $airportWebcamRefresh = isset($airport['webcam_refresh_seconds']) ? intval($airport['webcam_refresh_seconds']) : $defaultWebcamRefresh;
    $perCamRefresh = isset($cam['refresh_seconds']) ? intval($cam['refresh_seconds']) : $airportWebcamRefresh;
    
    $cacheAge = null;
    $cacheExists = file_exists($cacheFile);
    if ($cacheExists) {
        $cacheAge = time() - filemtime($cacheFile);
    }
    
    if ($cacheExists && $cacheAge < $perCamRefresh) {
        aviationwx_log('info', 'webcam skipped - fresh cache', [
            'invocation_id' => $invocationId,
            'trigger' => $triggerType,
            'airport' => $airportId,
            'cam' => $camIndex,
            'cache_age' => $cacheAge,
            'refresh_threshold' => $perCamRefresh
        ], 'app');
        releaseCameraLock($lockFp);
        return false;
    }
    
    $circuit = checkCircuitBreaker($airportId, $camIndex);
    if ($circuit['skip']) {
        $remaining = $circuit['backoff_remaining'];
        $failures = $circuit['failures'] ?? 0;
        aviationwx_log('info', 'webcam skipped - circuit breaker open', [
            'invocation_id' => $invocationId,
            'trigger' => $triggerType,
            'airport' => $airportId,
            'cam' => $camIndex,
            'failures' => $failures,
            'backoff_remaining' => $remaining
        ], 'app');
        releaseCameraLock($lockFp);
        return false;
    }
    
    aviationwx_log('info', 'webcam fetch attempt', [
        'invocation_id' => $invocationId,
        'trigger' => $triggerType,
        'airport' => $airportId,
        'cam' => $camIndex,
        'cache_age' => $cacheAge,
        'refresh_threshold' => $perCamRefresh
    ], 'app');
    
    $sourceType = isset($cam['type']) ? strtolower(trim($cam['type'])) : detectWebcamSourceType($url);
    
    // Fetch to staging file (.tmp) instead of directly to cache
    $fetchStartTime = microtime(true);
    $success = false;
    switch ($sourceType) {
        case 'rtsp':
            $fetchTimeout = isset($cam['rtsp_fetch_timeout']) ? intval($cam['rtsp_fetch_timeout']) : intval(getenv('RTSP_TIMEOUT') ?: RTSP_DEFAULT_TIMEOUT);
            $maxRuntime = isset($cam['rtsp_max_runtime']) ? intval($cam['rtsp_max_runtime']) : RTSP_MAX_RUNTIME;
            $success = fetchRTSPFrame($url, $stagingFile, $transport, $fetchTimeout, RTSP_DEFAULT_RETRIES, $maxRuntime);
            break;
            
        case 'static_jpeg':
        case 'static_png':
            $success = fetchStaticImage($url, $stagingFile);
            break;
            
        case 'mjpeg':
        default:
            $success = fetchMJPEGStream($url, $stagingFile);
            break;
    }
    $fetchDuration = round((microtime(true) - $fetchStartTime) * 1000, 2);
    
    if ($success && file_exists($stagingFile) && filesize($stagingFile) > 0) {
        recordSuccess($airportId, $camIndex);
        $size = filesize($stagingFile);
        
        // Add AviationWX.org attribution metadata (IPTC/XMP) to staging file
        $airportName = $airport['name'] ?? null;
        addAviationWxMetadata($stagingFile, $airportId, $camIndex, $airportName);
        
        aviationwx_log('info', 'webcam fetch success', [
            'invocation_id' => $invocationId,
            'trigger' => $triggerType,
            'airport' => $airportId,
            'cam' => $camIndex,
            'type' => $sourceType,
            'bytes' => $size,
            'fetch_duration_ms' => $fetchDuration
        ], 'app');
        
        // Get timestamp from source file (for timestamp-based filenames)
        $timestamp = getSourceCaptureTime($stagingFile);
        if ($timestamp <= 0) {
            $timestamp = time();
        }
        
        $variantResult = generateVariantsFromOriginal($stagingFile, $airportId, $camIndex, $timestamp);
        
        if ($variantResult['original'] === null || empty($variantResult['variants'])) {
            aviationwx_log('error', 'webcam fetch: variant generation failed', [
                'airport' => $airportId,
                'cam' => $camIndex,
                'has_original' => $variantResult['original'] !== null,
                'variant_count' => count($variantResult['variants'])
            ], 'app');
            cleanupStagingFiles($airportId, $camIndex);
            releaseCameraLock($lockFp);
            return false;
        }
        
        $promotedFormats = [];
        foreach ($variantResult['variants'] as $height => $formats) {
            $promotedFormats = array_merge($promotedFormats, array_keys($formats));
        }
        $promotedFormats = array_unique($promotedFormats);
        
        // Cleanup old timestamp files (uses webcam_history_max_frames config for retention)
        cleanupOldTimestampFiles($airportId, $camIndex);
        
        // Log final result
        $allRequestedFormats = ['jpg'];
        if (isWebpGenerationEnabled()) $allRequestedFormats[] = 'webp';
        if (isAvifGenerationEnabled()) $allRequestedFormats[] = 'avif';
        $failedFormats = array_diff($allRequestedFormats, $promotedFormats);
        
        if (empty($failedFormats)) {
            aviationwx_log('info', 'webcam processing complete', [
                'invocation_id' => $invocationId,
                'trigger' => $triggerType,
                'airport' => $airportId,
                'cam' => $camIndex,
                'formats_promoted' => $promotedFormats
            ], 'app');
        } else {
            aviationwx_log('warning', 'webcam partial format generation', [
                'invocation_id' => $invocationId,
                'trigger' => $triggerType,
                'airport' => $airportId,
                'cam' => $camIndex,
                'formats_promoted' => $promotedFormats,
                'formats_failed' => array_values($failedFormats)
            ], 'app');
        }
        
        $lockFile = "/tmp/webcam_lock_{$airportId}_{$camIndex}.lock";
        if (file_exists($lockFile)) {
            @unlink($lockFile);
        }
        releaseCameraLock($lockFp);
        return !empty($promotedFormats);
    } else {
        // Cleanup any staging files on failure
        cleanupStagingFiles($airportId, $camIndex);
        
        $lastErr = @json_decode(@file_get_contents($stagingFile . '.error.json'), true);
        $sev = mapErrorSeverity($lastErr['code'] ?? 'unknown');
        recordFailure($airportId, $camIndex, $sev);
        
        aviationwx_log('error', 'webcam fetch failure', [
            'invocation_id' => $invocationId,
            'trigger' => $triggerType,
            'airport' => $airportId,
            'cam' => $camIndex,
            'type' => $sourceType,
            'severity' => $sev,
            'fetch_duration_ms' => $fetchDuration,
            'error_code' => $lastErr['code'] ?? null
        ], 'app');
        
        releaseCameraLock($lockFp);
        return false;
    }
}

// Guard: Only execute main code if not included by another script
// When included, __FILE__ is the included file, when executed directly, it's the script
// We check if this is the main script execution vs an include
// Also allow execution when run via proc_open (SCRIPT_NAME may not be set, but argv[0] will be script name)
$isDirectExecution = false;
if (php_sapi_name() === 'cli') {
    // CLI mode: check if script name matches or if we're in worker mode (which means direct execution)
    $scriptName = $_SERVER['SCRIPT_NAME'] ?? ($argv[0] ?? '');
    $isDirectExecution = (basename(__FILE__) === basename($scriptName)) || $isWorkerMode;
} else {
    // Web mode: check SCRIPT_NAME
    $isDirectExecution = (basename(__FILE__) === basename($_SERVER['SCRIPT_NAME'] ?? ''));
}

if ($isDirectExecution) {
// Load config (support CONFIG_PATH env override, no cache for CLI script)
$config = loadConfig(false);

if ($config === null || !is_array($config)) {
    die("Error: Could not load configuration\n");
}

// Ensure cache directories exist
$cacheDir = CACHE_WEBCAMS_DIR;
ensureCacheDir($cacheDir);
ensureCacheDir(CACHE_BASE_DIR);

// Worker mode: process single camera and exit
if ($isWorkerMode) {
    // Initialize self-timeout to prevent zombie workers
    // Worker will terminate itself before ProcessPool's hard kill
    require_once __DIR__ . '/../lib/worker-timeout.php';
    initWorkerTimeout(null, "webcam_{$workerAirportId}_{$workerCamIndex}");
    
    if (!$workerAirportId || !validateAirportId($workerAirportId)) {
        aviationwx_log('error', 'worker mode: invalid airport ID', [
            'airport' => $workerAirportId
        ], 'app');
        exit(1);
    }
    
    if (!isset($config['airports'][$workerAirportId])) {
        aviationwx_log('error', 'worker mode: airport not found', [
            'airport' => $workerAirportId
        ], 'app');
        exit(1);
    }
    
    $airport = $config['airports'][$workerAirportId];
    if (!isset($airport['webcams'][$workerCamIndex])) {
        aviationwx_log('error', 'worker mode: camera not found', [
            'airport' => $workerAirportId,
            'cam' => $workerCamIndex
        ], 'app');
        exit(1);
    }
    
    $cam = $airport['webcams'][$workerCamIndex];
    $invocationId = aviationwx_get_invocation_id();
    $triggerInfo = aviationwx_detect_trigger_type();
    $triggerType = $triggerInfo['trigger'];
    
    $success = processWebcam($workerAirportId, $workerCamIndex, $cam, $airport, $cacheDir, $invocationId, $triggerType, false);
    
    // Flush variant health counters to cache file before exiting
    // Worker runs as separate CLI process with isolated APCu
    // Must flush here before process exit, otherwise counters are lost
    require_once __DIR__ . '/../lib/variant-health.php';
    variant_health_flush();
    
    exit($success ? 0 : 1);
}

// Normal mode: use process pool
// Check if we're in a web context (add HTML) or CLI (plain text)
$isWeb = !empty($_SERVER['REQUEST_METHOD']);

// Start script timing
$scriptStartTime = microtime(true);
$scriptStartTimestamp = time();

// Get invocation ID and trigger type for this run
$invocationId = aviationwx_get_invocation_id();
$triggerInfo = aviationwx_detect_trigger_type();
$triggerType = $triggerInfo['trigger'];
$triggerContext = $triggerInfo['context'];

$poolSize = getWebcamWorkerPoolSize();
$workerTimeout = getWorkerTimeout();

aviationwx_log('info', 'webcam fetch script started', [
    'invocation_id' => $invocationId,
    'trigger' => $triggerType,
    'trigger_context' => $triggerContext,
    'airports_count' => count($config['airports'] ?? []),
    'pool_size' => $poolSize,
    'worker_timeout' => $workerTimeout
], 'app');

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
    // Write progress to stderr for CLI/cron visibility
    @fwrite(STDERR, "AviationWX Webcam Fetcher\n");
    @fwrite(STDERR, "==========================\n\n");
}

require_once __DIR__ . '/../lib/process-pool.php';
$pool = new ProcessPool($poolSize, $workerTimeout, basename(__FILE__), $invocationId);
register_shutdown_function(function() use ($pool) {
    $pool->cleanup();
});

if ($isWeb) {
    echo "<p>Processing webcams with {$poolSize} workers...</p>";
} else {
    // Write progress to stderr for CLI/cron visibility
    @fwrite(STDERR, "Processing webcams with {$poolSize} workers...\n\n");
}

$skipped = 0;
foreach ($config['airports'] as $airportId => $airport) {
    // Only process enabled airports
    if (!isAirportEnabled($airport)) {
        continue;
    }
    
    if (!isset($airport['webcams']) || !is_array($airport['webcams'])) {
        continue;
    }
    
    foreach ($airport['webcams'] as $index => $cam) {
        $isPush = (isset($cam['type']) && $cam['type'] === 'push') || isset($cam['push_config']);
        if ($isPush) {
            continue;
        }
        
        if (!$pool->addJob([$airportId, $index])) {
            $skipped++;
        }
    }
}

$stats = $pool->waitForAll();

if ($isWeb) {
    echo "<div style='margin-top: 20px; padding: 15px; background: #fff; border-left: 4px solid #28a745;'>";
    echo "<strong>✓ Done!</strong> Webcam images cached.<br>";
    echo "Completed: {$stats['completed']}, Failed: {$stats['failed']}, Timed out: {$stats['timed_out']}";
    if ($skipped > 0) {
        echo ", Skipped (already running): {$skipped}";
    }
    echo "</div>";
} else {
    // Write progress to stderr for CLI/cron visibility
    @fwrite(STDERR, "\n\nDone! Webcam images cached.\n");
    $statsLine = "Completed: {$stats['completed']}, Failed: {$stats['failed']}, Timed out: {$stats['timed_out']}";
    if ($skipped > 0) {
        $statsLine .= ", Skipped (already running): {$skipped}";
    }
    @fwrite(STDERR, $statsLine . "\n");
}

// Log script completion
$scriptDuration = round((microtime(true) - $scriptStartTime) * 1000, 2);
$totalAirports = count($config['airports'] ?? []);

aviationwx_log('info', 'webcam fetch script completed', [
    'invocation_id' => $invocationId,
    'trigger' => $triggerType,
    'trigger_context' => $triggerContext,
    'duration_ms' => $scriptDuration,
    'airports_processed' => $totalAirports
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
        // Only process enabled airports
        if (!isAirportEnabled($airport)) {
            continue;
        }
        if (isset($airport['webcams']) && is_array($airport['webcams'])) {
            $airportName = $airport['name'] ?? $airportId;
            $subdomainUrl = "{$protocol}://{$airportId}.{$domain}";
            $queryUrl = "{$protocol}://{$domain}/?airport={$airportId}";
            if ($isWeb) {
                echo "<span class='info'>View {$airportName} at: <a href=\"{$subdomainUrl}\" target='_blank'>{$subdomainUrl}</a> or <a href=\"{$queryUrl}\" target='_blank'>{$queryUrl}</a></span><br>\n";
            } else {
                @fwrite(STDERR, "View {$airportName} at: {$subdomainUrl} or {$queryUrl}\n");
            }
        }
    }
} else {
    if ($isWeb) {
        echo "<span class='info'>View at: <a href=\"{$protocol}://{$domain}/?airport=<airport-id>\">{$protocol}://{$domain}/?airport=&lt;airport-id&gt;</a></span><br>\n";
    } else {
        @fwrite(STDERR, "View at: {$protocol}://{$domain}/?airport=<airport-id>\n");
    }
}

if ($isWeb) {
    echo "</div></body></html>";
}

} // End of guard: only execute if script is run directly (not included)

