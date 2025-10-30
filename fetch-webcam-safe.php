<?php
/**
 * Safe Webcam Image Fetcher
 * Supports MJPEG streams, RTSP streams, and static images
 */

/**
 * Detect webcam source type from URL
 */
function detectWebcamSourceType($url) {
    if (stripos($url, 'rtsp://') === 0) {
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
    $stimeoutUs = max(1, intval($timeoutSeconds)) * 1000000; // microseconds
    $attempt = 0;
    $jpegTmp = $cacheFile . '.tmp.jpg';
    @unlink($jpegTmp);
    
    while ($attempt <= $retries) {
        $attempt++;
        echo "    Attempt {$attempt}/" . ($retries + 1) . " using {$transport}, timeout {$timeoutSeconds}s\n";
        $cmd = sprintf(
            "ffmpeg -hide_banner -loglevel error -rtsp_transport %s -stimeout %d -i %s -frames:v 1 -q:v 2 -y %s",
            escapeshellarg($transport),
            $stimeoutUs,
            escapeshellarg($url),
            escapeshellarg($jpegTmp)
        );
        exec($cmd, $out, $code);
        if ($code === 0 && file_exists($jpegTmp) && filesize($jpegTmp) > 1000) {
            rename($jpegTmp, $cacheFile);
            echo "    ✓ Captured frame via ffmpeg\n";
            return true;
        }
        echo "    ✗ ffmpeg failed (code {$code})\n";
        @unlink($jpegTmp);
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

// Load config (support CONFIG_PATH env override)
$envConfigPath = getenv('CONFIG_PATH');
$configFile = ($envConfigPath && file_exists($envConfigPath)) ? $envConfigPath : (__DIR__ . '/airports.json');
$config = json_decode(file_get_contents($configFile), true);

if (!$config) {
    die("Error: Could not load airports.json\n");
}

$cacheDir = __DIR__ . '/cache/webcams';
if (!is_dir($cacheDir)) {
    mkdir($cacheDir, 0755, true);
}

echo "AviationWX Webcam Fetcher\n";
echo "==========================\n\n";

foreach ($config['airports'] as $airportId => $airport) {
    if (!isset($airport['webcams'])) {
        echo "No webcams configured for {$airportId}\n";
        continue;
    }
    
    echo "Airport: {$airportId} ({$airport['name']})\n";
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
        
        echo "\n  Fetching: {$camName}...\n";
        echo "    URL: {$url}\n";
        echo "    Refresh threshold: {$perCamRefresh}s\n";
        
        // Skip fetch if cache is fresh
        if (file_exists($cacheFile)) {
            $age = time() - filemtime($cacheFile);
            if ($age < $perCamRefresh) {
                echo "    ✓ Skipped (fresh cache, age {$age}s)\n";
                continue;
            }
        }
        
        // Determine source type and handle accordingly
        $sourceType = detectWebcamSourceType($url);
        echo "    Type: {$sourceType}\n";
        
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
            echo "    ✓ Saved {$size} bytes\n";
            // Derive WEBP
            $cmdWebp = sprintf("ffmpeg -hide_banner -loglevel error -y -i %s -frames:v 1 -q:v 30 %s", escapeshellarg($cacheFile), escapeshellarg($cacheWebp));
            exec($cmdWebp, $outW, $codeW);
            if ($codeW === 0 && file_exists($cacheWebp)) {
                echo "    ✓ Generated WEBP\n";
            } else {
                echo "    ✗ WEBP generation failed\n";
            }
            // Derive AVIF (best-effort)
            $cmdAvif = sprintf("ffmpeg -hide_banner -loglevel error -y -i %s -frames:v 1 -crf 28 -pix_fmt yuv420p %s", escapeshellarg($cacheFile), escapeshellarg($cacheAvif));
            exec($cmdAvif, $outA, $codeA);
            if ($codeA === 0 && file_exists($cacheAvif)) {
                echo "    ✓ Generated AVIF\n";
            } else {
                echo "    ✗ AVIF generation failed (ignored)\n";
            }
        } else {
            echo "    ✗ Failed to cache image\n";
        }
    }
}

echo "\n\nDone! Webcam images cached.\n";
echo "View them at: http://localhost:8000/?airport=kspb\n";

