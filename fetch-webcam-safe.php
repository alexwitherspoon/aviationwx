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
function fetchRTSPFrame($url, $cacheFile, $transport = 'tcp', $timeoutSeconds = 10, $retries = 2) {
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
        
        // Show detailed error for debugging
        if ($isWeb) {
            echo "<span class='error'>✗ ffmpeg failed (code {$code})</span><br>\n";
        } else {
            echo "    ✗ ffmpeg failed (code {$code})\n";
        }
        
        if (!empty($errorOutput)) {
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
                    $cleanErr = htmlspecialchars(trim($errLine), ENT_QUOTES | ENT_HTML5, 'UTF-8');
                    if ($isWeb) {
                        echo "<span class='error' style='margin-left: 20px; font-size: 0.9em;'>" . $cleanErr . "</span><br>\n";
                    } else {
                        echo "      " . trim($errLine) . "\n";
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

// Load config (support CONFIG_PATH env override, no cache for CLI script)
$config = loadConfig(false);

if ($config === null || !is_array($config)) {
    die("Error: Could not load configuration\n");
}

$cacheDir = __DIR__ . '/cache/webcams';
if (!is_dir($cacheDir)) {
    mkdir($cacheDir, 0755, true);
}

// Check if we're in a web context (add HTML) or CLI (plain text)
$isWeb = !empty($_SERVER['REQUEST_METHOD']);

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
    if (!isset($airport['webcams'])) {
        if ($isWeb) {
            echo "<div class='info'>No webcams configured for {$airportId}</div>\n";
        } else {
            echo "No webcams configured for {$airportId}\n";
        }
        continue;
    }
    
    if ($isWeb) {
        echo "<div class='airport'><h3>✈️ Airport: {$airportId} ({$airport['name']})</h3>\n";
    } else {
        echo "Airport: {$airportId} ({$airport['name']})\n";
    }
    $defaultWebcamRefresh = getenv('WEBCAM_REFRESH_DEFAULT') !== false ? intval(getenv('WEBCAM_REFRESH_DEFAULT')) : 60;
    $airportWebcamRefresh = isset($airport['webcam_refresh_seconds']) ? intval($airport['webcam_refresh_seconds']) : $defaultWebcamRefresh;
    
    foreach ($airport['webcams'] as $index => $cam) {
        $cacheFileBase = $cacheDir . '/' . $airportId . '_' . $index;
        $cacheFile = $cacheFileBase . '.jpg';
        $cacheWebp = $cacheFileBase . '.webp';
        $cacheAvif = $cacheFileBase . '.avif';
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
        
        // Skip fetch if cache is fresh
        if (file_exists($cacheFile)) {
            $age = time() - filemtime($cacheFile);
            if ($age < $perCamRefresh) {
                if ($isWeb) {
                    echo "<span class='success'>✓ Skipped (fresh cache, age {$age}s)</span></div>\n";
                } else {
                    echo "    ✓ Skipped (fresh cache, age {$age}s)\n";
                }
                continue;
            }
        }
        
        // Determine source type and handle accordingly
        // Allow explicit type override per camera in config
        $sourceType = isset($cam['type']) ? strtolower(trim($cam['type'])) : detectWebcamSourceType($url);
        if ($isWeb) {
            echo "<span class='info'>Type: {$sourceType}</span><br>";
        } else {
            echo "    Type: {$sourceType}\n";
        }
        
        $success = false;
        switch ($sourceType) {
            case 'rtsp':
                // RTSP stream - use ffmpeg to capture a frame
                $success = fetchRTSPFrame($url, $cacheFile, $transport, intval(getenv('RTSP_TIMEOUT') ?: 10), 2);
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
        
        if ($success && file_exists($cacheFile) && filesize($cacheFile) > 0) {
            $size = filesize($cacheFile);
            if ($isWeb) {
                echo "<span class='success'>✓ Saved " . number_format($size) . " bytes</span><br>\n";
            } else {
                echo "    ✓ Saved {$size} bytes\n";
            }
            // Derive WEBP
            $cmdWebp = sprintf("ffmpeg -hide_banner -loglevel error -y -i %s -frames:v 1 -q:v 30 %s", escapeshellarg($cacheFile), escapeshellarg($cacheWebp));
            exec($cmdWebp, $outW, $codeW);
            if ($codeW === 0 && file_exists($cacheWebp)) {
                if ($isWeb) {
                    echo "<span class='success'>✓ Generated WEBP</span><br>\n";
                } else {
                    echo "    ✓ Generated WEBP\n";
                }
            } else {
                if ($isWeb) {
                    echo "<span class='error'>✗ WEBP generation failed</span><br>\n";
                } else {
                    echo "    ✗ WEBP generation failed\n";
                }
            }
            // Derive AVIF (best-effort)
            $cmdAvif = sprintf("ffmpeg -hide_banner -loglevel error -y -i %s -frames:v 1 -crf 28 -pix_fmt yuv420p %s", escapeshellarg($cacheFile), escapeshellarg($cacheAvif));
            exec($cmdAvif, $outA, $codeA);
            if ($codeA === 0 && file_exists($cacheAvif)) {
                if ($isWeb) {
                    echo "<span class='success'>✓ Generated AVIF</span><br>\n";
                } else {
                    echo "    ✓ Generated AVIF\n";
                }
            } else {
                if ($isWeb) {
                    echo "<span class='info'>✗ AVIF generation failed (ignored)</span><br>\n";
                } else {
                    echo "    ✗ AVIF generation failed (ignored)\n";
                }
            }
        } else {
            if ($isWeb) {
                echo "<span class='error'>✗ Failed to cache image</span><br>\n";
            } else {
                echo "    ✗ Failed to cache image\n";
            }
        }
        
        if ($isWeb) {
            echo "</div>"; // Close webcam div
        }
    }
    
    if ($isWeb) {
        echo "</div>"; // Close airport div
    }
}

if ($isWeb) {
    echo "<div style='margin-top: 20px; padding: 15px; background: #fff; border-left: 4px solid #28a745;'>";
    echo "<strong>✓ Done!</strong> Webcam images cached.<br>";
} else {
    echo "\n\nDone! Webcam images cached.\n";
}

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

