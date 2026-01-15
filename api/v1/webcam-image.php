<?php
/**
 * Public API - Get Webcam Image Endpoint
 * 
 * GET /v1/airports/{id}/webcams/{cam}/image
 * 
 * Returns the current webcam image with optional transformations.
 * 
 * Query parameters:
 * - fmt: Image format ('jpg', 'webp') - default is jpg
 * - size: Height-based variant (e.g., '720', '1080') or 'original' - preserves aspect ratio
 * - width: Target width in pixels (16-3840) - used with height for exact dimensions
 * - height: Target height in pixels (16-2160) - used with width for exact dimensions
 * 
 * Dimension behavior:
 * - width + height: Center-crop to target aspect ratio, then scale to exact dimensions
 * - width only: Scale to width, preserve original aspect ratio
 * - height only: Scale to height, preserve original aspect ratio (same as size=)
 * - size=: Height-based variant from pre-generated set (no cropping)
 * - Neither: Original image
 * 
 * Example for FAA weathercam (4:3 @ 1280x960):
 *   GET /v1/airports/kspb/webcams/0/image?width=1280&height=960&fmt=jpg
 */

require_once __DIR__ . '/../../lib/public-api/middleware.php';
require_once __DIR__ . '/../../lib/public-api/response.php';
require_once __DIR__ . '/../../lib/config.php';
require_once __DIR__ . '/../../lib/cache-headers.php';
require_once __DIR__ . '/../../lib/webcam-metadata.php';
require_once __DIR__ . '/../../lib/image-transform.php';

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
    if (!in_array($format, ['jpg', 'webp'])) {
        $format = 'jpg';
    }
    
    // Parse dimension parameters
    $requestedWidth = isset($_GET['width']) && is_numeric($_GET['width']) ? (int)$_GET['width'] : null;
    $requestedHeight = isset($_GET['height']) && is_numeric($_GET['height']) ? (int)$_GET['height'] : null;
    $size = $_GET['size'] ?? null;
    
    // Get latest timestamp
    $timestamp = getLatestImageTimestamp($airportId, $camIndex);
    
    if ($timestamp === 0) {
        sendPublicApiError(
            PUBLIC_API_ERROR_SERVICE_UNAVAILABLE,
            'Webcam image temporarily unavailable',
            503
        );
        return;
    }
    
    // Determine which path to take: transform (width/height) or variant (size)
    $cacheFile = null;
    $variant = 'original';
    
    if ($requestedWidth !== null || $requestedHeight !== null) {
        // Use image transformation (center-crop + resize)
        $cacheFile = handleTransformRequest(
            $airportId,
            $camIndex,
            $timestamp,
            $requestedWidth,
            $requestedHeight,
            $format
        );
        
        if ($cacheFile === null) {
            // Error already sent by handleTransformRequest
            return;
        }
        
        // Build variant name for filename
        if ($requestedWidth !== null && $requestedHeight !== null) {
            $variant = $requestedWidth . 'x' . $requestedHeight;
        } elseif ($requestedWidth !== null) {
            $variant = 'w' . $requestedWidth;
        } else {
            $variant = 'h' . $requestedHeight;
        }
    } else {
        // Use existing variant system (no cropping)
        if ($size === null) {
            $size = 'original';
        }
        
        // Validate size: numeric height or 'original'
        if ($size !== 'original' && (!is_numeric($size) || (int)$size < 1 || (int)$size > 5000)) {
            $size = 'original';
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
        
        $variant = ($size === 'original') ? 'original' : (int)$size;
    }
    
    if ($cacheFile === null || !file_exists($cacheFile)) {
        sendPublicApiError(
            PUBLIC_API_ERROR_SERVICE_UNAVAILABLE,
            'Webcam image temporarily unavailable',
            503
        );
        return;
    }
    
    // Send the image response
    sendImageResponse($cacheFile, $timestamp, $variant, $format, $airport, $webcams[$camIndex]);
}

/**
 * Handle a transform request (width and/or height specified)
 * 
 * @param string $airportId Airport identifier
 * @param int $camIndex Camera index
 * @param int $timestamp Image timestamp
 * @param int|null $requestedWidth Requested width
 * @param int|null $requestedHeight Requested height
 * @param string $format Output format
 * @return string|null Path to transformed image or null on error (error sent)
 */
function handleTransformRequest(
    string $airportId,
    int $camIndex,
    int $timestamp,
    ?int $requestedWidth,
    ?int $requestedHeight,
    string $format
): ?string {
    // Validate transform parameters
    $validation = validateTransformParams($requestedWidth, $requestedHeight, $format);
    
    if (!$validation['valid']) {
        sendPublicApiError(
            PUBLIC_API_ERROR_INVALID_REQUEST,
            $validation['error'],
            400
        );
        return null;
    }
    
    // If only one dimension specified, we need to calculate the other from source
    if ($requestedWidth === null || $requestedHeight === null) {
        // Get source image dimensions
        $sourcePath = getWebcamOriginalTimestampedPath($airportId, $camIndex, $timestamp, 'jpg');
        if (!file_exists($sourcePath)) {
            $sourcePath = getWebcamOriginalTimestampedPath($airportId, $camIndex, $timestamp, 'webp');
        }
        
        if (!file_exists($sourcePath)) {
            sendPublicApiError(
                PUBLIC_API_ERROR_SERVICE_UNAVAILABLE,
                'Webcam image temporarily unavailable',
                503
            );
            return null;
        }
        
        $imageInfo = @getimagesize($sourcePath);
        if ($imageInfo === false) {
            sendPublicApiError(
                PUBLIC_API_ERROR_SERVICE_UNAVAILABLE,
                'Unable to read image dimensions',
                503
            );
            return null;
        }
        
        $sourceWidth = $imageInfo[0];
        $sourceHeight = $imageInfo[1];
        
        // Calculate scaled dimensions (preserves aspect ratio)
        $dimensions = calculateScaledDimensions(
            $sourceWidth,
            $sourceHeight,
            $requestedWidth,
            $requestedHeight
        );
        
        $requestedWidth = $dimensions['width'];
        $requestedHeight = $dimensions['height'];
        
        // Re-validate calculated dimensions
        $validation = validateTransformParams($requestedWidth, $requestedHeight, $format);
        if (!$validation['valid']) {
            sendPublicApiError(
                PUBLIC_API_ERROR_INVALID_REQUEST,
                $validation['error'],
                400
            );
            return null;
        }
    }
    
    // Get or create the transformed image
    $transformedPath = getTransformedImagePath(
        $airportId,
        $camIndex,
        $timestamp,
        $requestedWidth,
        $requestedHeight,
        $format
    );
    
    if ($transformedPath === null) {
        sendPublicApiError(
            PUBLIC_API_ERROR_SERVICE_UNAVAILABLE,
            'Unable to process image',
            503
        );
        return null;
    }
    
    return $transformedPath;
}

/**
 * Send the image response with appropriate headers
 * 
 * @param string $cacheFile Path to image file
 * @param int $timestamp Image timestamp
 * @param string $variant Variant identifier for filename
 * @param string $format Image format
 * @param array $airport Airport configuration
 * @param array $cam Camera configuration
 */
function sendImageResponse(
    string $cacheFile,
    int $timestamp,
    string $variant,
    string $format,
    array $airport,
    array $cam
): void {
    // Get file info
    $fileSize = filesize($cacheFile);
    $mtime = filemtime($cacheFile);
    
    // Set content type
    $contentType = match ($format) {
        'webp' => 'image/webp',
        default => 'image/jpeg',
    };
    
    // Build filename
    $filename = $timestamp . '_' . $variant . '.' . $format;
    
    // Get webcam refresh interval (from camera config, airport config, or default)
    $webcamRefresh = getDefaultWebcamRefresh();
    if (isset($airport['webcam_refresh_seconds'])) {
        $webcamRefresh = intval($airport['webcam_refresh_seconds']);
    }
    if (isset($cam['refresh_seconds'])) {
        $webcamRefresh = intval($cam['refresh_seconds']);
    }
    
    // Send headers
    header('Content-Type: ' . $contentType);
    header('Content-Disposition: inline; filename="' . $filename . '"');
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

