<?php
/**
 * Public API - Get Webcam Image Endpoint
 * 
 * GET /v1/airports/{id}/webcams/{cam}/image
 * 
 * Returns the current webcam image.
 * 
 * Query parameters:
 * - fmt: Image format ('jpg', 'webp', 'avif') - default is jpg
 */

require_once __DIR__ . '/../../lib/public-api/middleware.php';
require_once __DIR__ . '/../../lib/public-api/response.php';
require_once __DIR__ . '/../../lib/config.php';
require_once __DIR__ . '/../../lib/cache-headers.php';

/**
 * Handle GET /v1/airports/{id}/webcams/{cam}/image request
 * 
 * @param array $params Path parameters [0 => airport_id, 1 => cam_index]
 * @param array $context Request context from middleware
 */
function handleGetWebcamImage(array $params, array $context): void
{
    $airportId = validatePublicApiAirportId($params[0] ?? '');
    $camIndex = isset($params[1]) ? (int)$params[1] : -1;
    
    if ($airportId === null) {
        sendPublicApiError(
            PUBLIC_API_ERROR_INVALID_REQUEST,
            'Invalid airport ID format',
            400
        );
        return;
    }
    
    if ($camIndex < 0) {
        sendPublicApiError(
            PUBLIC_API_ERROR_INVALID_REQUEST,
            'Invalid camera index',
            400
        );
        return;
    }
    
    $airport = getPublicApiAirport($airportId);
    
    if ($airport === null) {
        sendPublicApiError(
            PUBLIC_API_ERROR_AIRPORT_NOT_FOUND,
            'Airport not found: ' . $params[0],
            404
        );
        return;
    }
    
    // Check if webcam exists
    $webcams = $airport['webcams'] ?? [];
    if (!isset($webcams[$camIndex])) {
        sendPublicApiError(
            PUBLIC_API_ERROR_WEBCAM_NOT_FOUND,
            'Webcam not found: index ' . $camIndex,
            404
        );
        return;
    }
    
    // Get requested format
    $format = $_GET['fmt'] ?? 'jpg';
    if (!in_array($format, ['jpg', 'webp', 'avif'])) {
        $format = 'jpg';
    }
    
    // Get requested size (variant) - supports height-based variants or 'original'
    $size = $_GET['size'] ?? 'original';
    
    // Validate size: numeric height or 'original'
    if ($size !== 'original' && (!is_numeric($size) || (int)$size < 1 || (int)$size > 5000)) {
        $size = 'original';
    }
    
    // Get latest timestamp and build path using new variant system
    require_once __DIR__ . '/../../lib/webcam-metadata.php';
    $timestamp = getLatestImageTimestamp($airportId, $camIndex);
    
    if ($timestamp === 0) {
        sendPublicApiError(
            PUBLIC_API_ERROR_SERVICE_UNAVAILABLE,
            'Webcam image temporarily unavailable',
            503
        );
        return;
    }
    
    // Get image path for requested size
    $cacheFile = getImagePathForSize($airportId, $camIndex, $timestamp, $size, $format);
    
    // Fall back to original if variant doesn't exist
    if ($cacheFile === null && $size !== 'original') {
        $cacheFile = getImagePathForSize($airportId, $camIndex, $timestamp, 'original', $format);
        $size = 'original';
    }
    
    // Fall back to JPG if requested format doesn't exist
    if ($cacheFile === null && $format !== 'jpg') {
        $cacheFile = getImagePathForSize($airportId, $camIndex, $timestamp, $size, 'jpg');
        if ($cacheFile === null && $size !== 'original') {
            $cacheFile = getImagePathForSize($airportId, $camIndex, $timestamp, 'original', 'jpg');
        }
        $format = 'jpg';
    }
    
    if (!file_exists($cacheFile)) {
        // Return placeholder or error
        sendPublicApiError(
            PUBLIC_API_ERROR_SERVICE_UNAVAILABLE,
            'Webcam image temporarily unavailable',
            503
        );
        return;
    }
    
    // Get file info
    $fileSize = filesize($cacheFile);
    $mtime = filemtime($cacheFile);
    
    // Set content type
    $contentType = match ($format) {
        'webp' => 'image/webp',
        'avif' => 'image/avif',
        default => 'image/jpeg',
    };
    
    // Get webcam refresh interval (from camera config, airport config, or default)
    require_once __DIR__ . '/../../lib/config.php';
    $webcamRefresh = getDefaultWebcamRefresh();
    if (isset($airport['webcam_refresh_seconds'])) {
        $webcamRefresh = intval($airport['webcam_refresh_seconds']);
    }
    $cam = $webcams[$camIndex];
    if (isset($cam['refresh_seconds'])) {
        $webcamRefresh = intval($cam['refresh_seconds']);
    }
    
    // Send headers
    header('Content-Type: ' . $contentType);
    header('Content-Length: ' . $fileSize);
    header('Last-Modified: ' . gmdate('D, d M Y H:i:s', $mtime) . ' GMT');
    
    // Cache headers: browser cache matches refresh interval, CDN uses half (min 5s)
    $headers = generateCacheHeaders($webcamRefresh, $webcamRefresh);
    foreach ($headers as $name => $value) {
        header($name . ': ' . $value);
    }
    
    // CORS headers
    header('Access-Control-Allow-Origin: *');
    
    // Output image
    readfile($cacheFile);
}

