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
 * NOTE: RTSP support requires ffmpeg, which is not available on shared hosting like Bluehost.
 * 
 * Options for RTSP cameras on shared hosting:
 * 1. Use the camera's HTTP snapshot URL instead (most cameras support this)
 * 2. Run a local conversion server that converts RTSP -> MJPEG
 * 3. Use a cloud service to proxy RTSP to HTTP
 * 4. Configure the camera to stream directly as MJPEG
 */
function fetchRTSPFrame($url, $cacheFile) {
    echo "    ⚠️  RTSP streams not supported on shared hosting\n";
    echo "    💡 Use the camera's HTTP snapshot URL instead\n";
    echo "    💡 Example: http://camera-ip/cgi-bin/snapshot.cgi\n";
    return false;
    
    // Alternative: Try using external service (if available)
    // $proxyUrl = "https://rtsp-to-jpeg-proxy.example.com/convert?url=" . urlencode($url);
    // return fetchStaticImage($proxyUrl, $cacheFile);
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

// Load config
$configFile = __DIR__ . '/airports.json';
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
    
    foreach ($airport['webcams'] as $index => $cam) {
        $cacheFile = $cacheDir . '/' . $airportId . '_' . $index . '.jpg';
        $camName = $cam['name'] ?? "Cam {$index}";
        $url = $cam['url'];
        
        echo "\n  Fetching: {$camName}...\n";
        echo "    URL: {$url}\n";
        
        // Determine source type and handle accordingly
        $sourceType = detectWebcamSourceType($url);
        echo "    Type: {$sourceType}\n";
        
        $success = false;
        switch ($sourceType) {
            case 'rtsp':
                // RTSP stream - use ffmpeg to capture a frame
                $success = fetchRTSPFrame($url, $cacheFile);
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
        } else {
            echo "    ✗ Failed to cache image\n";
        }
    }
}

echo "\n\nDone! Webcam images cached.\n";
echo "View them at: http://localhost:8000/?airport=kspb\n";

