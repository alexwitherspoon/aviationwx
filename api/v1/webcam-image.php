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
    
    // Build cache file path (format: {airportId}_{camIndex}.{ext})
    $cacheDir = __DIR__ . '/../../cache/webcams';
    $extension = $format === 'jpg' ? 'jpg' : $format;
    $cacheFile = $cacheDir . '/' . $airportId . '_' . $camIndex . '.' . $extension;
    
    // Fall back to JPG if requested format doesn't exist
    if (!file_exists($cacheFile) && $format !== 'jpg') {
        $cacheFile = $cacheDir . '/' . $airportId . '_' . $camIndex . '.jpg';
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
    
    // Send headers
    header('Content-Type: ' . $contentType);
    header('Content-Length: ' . $fileSize);
    header('Last-Modified: ' . gmdate('D, d M Y H:i:s', $mtime) . ' GMT');
    
    // Cache headers for live data (60 seconds)
    header('Cache-Control: public, max-age=60, s-maxage=60, stale-while-revalidate=30');
    
    // CORS headers
    header('Access-Control-Allow-Origin: *');
    
    // Output image
    readfile($cacheFile);
}

