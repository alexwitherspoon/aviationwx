<?php
/**
 * Public API - Get Webcam Image Endpoint
 * 
 * GET /v1/airports/{id}/webcams/{cam}/image
 * 
 * Returns the current webcam image with optional transformations.
 * 
 * Query parameters:
 * - profile: Output profile ('faa') - applies FAA-compliant settings
 * - fmt: Image format ('jpg', 'webp') - default is jpg
 * - size: Height-based variant (e.g., '720', '1080') or 'original' - preserves aspect ratio
 * - width: Target width in pixels (16-3840) - used with height for exact dimensions
 * - height: Target height in pixels (16-2160) - used with width for exact dimensions
 * 
 * Dimension behavior:
 * - profile=faa: FAA WCPO compliant (4:3, crop margins, quality-capped, JPG)
 * - width + height: Center-crop to target aspect ratio, then scale to exact dimensions
 * - width only: Scale to width, preserve original aspect ratio
 * - height only: Scale to height, preserve original aspect ratio (same as size=)
 * - size=: Height-based variant from pre-generated set (no cropping)
 * - Neither: Original image
 * 
 * FAA Profile (profile=faa):
 *   - Applies per-camera crop_margins to exclude timestamps/watermarks
 *   - Forces 4:3 aspect ratio
 *   - Forces JPG format
 *   - Quality-capped: 1280x960 if source supports, else 640x480 (no upscaling)
 *   GET /v1/airports/kspb/webcams/0/image?profile=faa
 */

require_once __DIR__ . '/../../lib/public-api/middleware.php';
require_once __DIR__ . '/../../lib/public-api/response.php';
require_once __DIR__ . '/../../lib/config.php';
require_once __DIR__ . '/../../lib/cache-headers.php';
require_once __DIR__ . '/../../lib/webcam-metadata.php';
require_once __DIR__ . '/../../lib/image-transform.php';
require_once __DIR__ . '/../../lib/metrics.php';

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
    
    $webcam = $webcams[$camIndex];
    
    // Track API request metric (combined with private API metrics for high-level view)
    metrics_track_webcam_request($airportId, $camIndex);
    
    // Handle download request - always serves original JPG with proper filename
    if (isset($_GET['download']) && $_GET['download'] == '1') {
        require_once __DIR__ . '/../../lib/webcam-metadata.php';
        $cacheDir = getWebcamCameraDir($airportId, $camIndex);
        $originalJpg = $cacheDir . '/original.jpg';
        
        if (!file_exists($originalJpg) || !is_readable($originalJpg)) {
            sendPublicApiError(
                PUBLIC_API_ERROR_SERVICE_UNAVAILABLE,
                'Original image not available for download',
                404
            );
            return;
        }
        
        // Get EXIF capture time for filename
        $captureTime = 0;
        if (function_exists('exif_read_data')) {
            $exif = @exif_read_data($originalJpg, 'EXIF', true);
            if ($exif !== false && isset($exif['EXIF']['DateTimeOriginal'])) {
                $dateTime = $exif['EXIF']['DateTimeOriginal'];
                $timestamp = @strtotime(str_replace(':', '-', substr($dateTime, 0, 10)) . ' ' . substr($dateTime, 11) . ' UTC');
                if ($timestamp !== false && $timestamp > 0) {
                    $captureTime = (int)$timestamp;
                }
            } elseif (isset($exif['DateTimeOriginal'])) {
                $dateTime = $exif['DateTimeOriginal'];
                $timestamp = @strtotime(str_replace(':', '-', substr($dateTime, 0, 10)) . ' ' . substr($dateTime, 11) . ' UTC');
                if ($timestamp !== false && $timestamp > 0) {
                    $captureTime = (int)$timestamp;
                }
            }
        }
        
        // Use EXIF time if available, otherwise file mtime
        if ($captureTime > 0) {
            $timestamp = gmdate('Y-m-d_His', $captureTime) . '_UTC';
        } else {
            $mtime = filemtime($originalJpg);
            $timestamp = gmdate('Y-m-d_His', $mtime) . '_UTC';
        }
        
        // Build filename: {airport}_{cam}_{timestamp}.jpg
        $filename = strtolower($airportId) . "_{$camIndex}_{$timestamp}.jpg";
        
        require_once __DIR__ . '/../../lib/http-integrity.php';
        $mtime = filemtime($originalJpg);
        if (addIntegrityHeadersForFile($originalJpg, $mtime)) {
            exit;
        }

        // Force download with proper filename
        header('Content-Type: image/jpeg');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        header('Content-Length: ' . filesize($originalJpg));
        header('Cache-Control: public, max-age=31536000, immutable');
        
        // Serve original file
        readfile($originalJpg);
        exit;
    }
    
    // Check for metadata request
    if (isset($_GET['metadata']) && $_GET['metadata'] == '1') {
        handleGetWebcamMetadata($airportId, $camIndex, $airport, $webcam);
        return;
    }
    
    // Check for profile parameter
    $profile = $_GET['profile'] ?? null;
    
    // Handle FAA profile
    if ($profile === 'faa') {
        handleFaaProfileRequest($airportId, $camIndex, $airport, $webcam);
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
        
        // Track if format was explicitly requested via query parameter
        $explicitFormatRequest = isset($_GET['fmt']);
        $requestedFormat = $format;
        
        // Get image path for requested size
        $cacheFile = getImagePathForSize($airportId, $camIndex, $timestamp, $size, $format);
        
        // Fall back to original if variant doesn't exist
        if ($cacheFile === null && $size !== 'original') {
            $cacheFile = getImagePathForSize($airportId, $camIndex, $timestamp, 'original', $format);
            $size = 'original';
        }
        
        // Handle explicit format request for unavailable format
        if ($cacheFile === null && $explicitFormatRequest && $requestedFormat !== 'jpg') {
            // Check if the format exists for any sized variant
            require_once __DIR__ . '/../../lib/webcam-metadata.php';
            $variantHeights = getVariantHeights($airportId, $camIndex);
            $availableSizes = [];
            
            foreach ($variantHeights as $height) {
                $variantPath = getImagePathForSize($airportId, $camIndex, $timestamp, $height, $requestedFormat);
                if ($variantPath !== null) {
                    $availableSizes[] = $height;
                }
            }
            
            if (!empty($availableSizes)) {
                // Format exists for sized variants but not for original
                sendPublicApiError(
                    PUBLIC_API_ERROR_INVALID_REQUEST,
                    "Format '{$requestedFormat}' is not available for size 'original'. " .
                    "Available sizes for {$requestedFormat}: " . implode(', ', $availableSizes) . ". " .
                    "Note: Original images preserve the source format from the camera.",
                    400
                );
                return;
            } else {
                // Format doesn't exist at all
                sendPublicApiError(
                    PUBLIC_API_ERROR_INVALID_REQUEST,
                    "Format '{$requestedFormat}' is not available for this webcam",
                    400
                );
                return;
            }
        }
        
        // Fall back to JPG if requested format doesn't exist (only for non-explicit requests)
        if ($cacheFile === null && $format !== 'jpg' && !$explicitFormatRequest) {
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
    sendImageResponse($airportId, $camIndex, $cacheFile, $timestamp, $variant, $format, $airport, $webcams[$camIndex]);
}

/**
 * Handle FAA profile request
 * 
 * Applies FAA WCPO-compliant transformations:
 * - Crop margins to exclude timestamps/watermarks
 * - 4:3 aspect ratio
 * - Quality-capped: 1280x960 or 640x480 (no upscaling)
 * - JPG format only
 * 
 * @param string $airportId Airport identifier
 * @param int $camIndex Camera index
 * @param array $airport Airport configuration
 * @param array $webcam Webcam configuration
 */
function handleFaaProfileRequest(
    string $airportId,
    int $camIndex,
    array $airport,
    array $webcam
): void {
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
    
    // Get FAA crop margins (resolves config hierarchy)
    $margins = getFaaCropMargins($webcam);
    
    // Get or create FAA-transformed image
    $result = getFaaTransformedImagePath($airportId, $camIndex, $timestamp, $margins);
    
    if ($result === null) {
        sendPublicApiError(
            PUBLIC_API_ERROR_SERVICE_UNAVAILABLE,
            'Unable to process FAA image',
            503
        );
        return;
    }
    
    $cacheFile = $result['path'];
    $outputWidth = $result['width'];
    $outputHeight = $result['height'];
    
    if (!file_exists($cacheFile)) {
        sendPublicApiError(
            PUBLIC_API_ERROR_SERVICE_UNAVAILABLE,
            'FAA image temporarily unavailable',
            503
        );
        return;
    }
    
    // Build variant name for filename
    $variant = 'faa_' . $outputWidth . 'x' . $outputHeight;
    
    // Send the image response (always JPG for FAA)
    sendImageResponse($airportId, $camIndex, $cacheFile, $timestamp, $variant, 'jpg', $airport, $webcam);
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
 * @param string $airportId Airport identifier
 * @param int $camIndex Camera index
 * @param string $cacheFile Path to image file
 * @param int $timestamp Image timestamp
 * @param string|int $variant Variant identifier for filename (original, 720, faa_1280x960, etc.)
 * @param string $format Image format (jpg, webp)
 * @param array $airport Airport configuration
 * @param array $cam Camera configuration
 */
function sendImageResponse(
    string $airportId,
    int $camIndex,
    string $cacheFile,
    int $timestamp,
    string|int $variant,
    string $format,
    array $airport,
    array $cam
): void {
    // Track webcam serve for status page format/size breakdown
    $sizeForMetrics = 'original';
    if (is_numeric($variant) && (int)$variant >= 1 && (int)$variant <= 5000) {
        $sizeForMetrics = (string)(int)$variant;
    } elseif ($variant === 'original') {
        $sizeForMetrics = 'original';
    } elseif (is_string($variant) && str_starts_with($variant, 'faa_')) {
        $sizeForMetrics = 'faa';
    } elseif (is_string($variant) && (str_contains($variant, 'x') || str_starts_with($variant, 'w') || str_starts_with($variant, 'h'))) {
        $sizeForMetrics = 'transform';
    }
    metrics_track_webcam_serve($airportId, $camIndex, $format, $sizeForMetrics);

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
    
    require_once __DIR__ . '/../../lib/http-integrity.php';

    // Integrity headers (ETag, Content-Digest, Content-MD5) - 304 if unchanged
    if (addIntegrityHeadersForFile($cacheFile, $mtime)) {
        return;
    }

    // Send headers
    header('Content-Type: ' . $contentType);
    header('Content-Disposition: inline; filename="' . $filename . '"');
    header('Content-Length: ' . $fileSize);
    
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

/**
 * Handle metadata request for current webcam image
 * 
 * Returns JSON with available formats and variants for the current image.
 * 
 * @param string $airportId Airport identifier
 * @param int $camIndex Camera index
 * @param array $airport Airport configuration
 * @param array $webcam Webcam configuration
 */
function handleGetWebcamMetadata(
    string $airportId,
    int $camIndex,
    array $airport,
    array $webcam
): void {
    require_once __DIR__ . '/../../lib/webcam-metadata.php';
    
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
    
    // Get available variants
    $availableVariants = getAvailableVariants($airportId, $camIndex, $timestamp);
    
    if (empty($availableVariants)) {
        sendPublicApiError(
            PUBLIC_API_ERROR_SERVICE_UNAVAILABLE,
            'No image variants available',
            503
        );
        return;
    }
    
    // Get configured variant heights
    $variantHeights = getVariantHeights($airportId, $camIndex);
    
    // Build formats structure: { variant: [formats] }
    $formats = [];
    $urls = [];
    
    foreach ($availableVariants as $variant => $variantFormats) {
        $variantKey = ($variant === 'original') ? 'original' : (int)$variant;
        $formats[$variantKey] = $variantFormats;
        
        // Build URLs for each format
        foreach ($variantFormats as $format) {
            $urlKey = $variantKey . '_' . $format;
            $url = '/v1/airports/' . $airportId . '/webcams/' . $camIndex . '/image';
            
            $params = [];
            if ($format !== 'jpg') {
                $params[] = 'fmt=' . $format;
            }
            if ($variant !== 'original') {
                $params[] = 'size=' . $variant;
            }
            
            if (!empty($params)) {
                $url .= '?' . implode('&', $params);
            }
            
            $urls[$urlKey] = $url;
        }
    }
    
    // Build recommended sizes (available sized variants, sorted descending)
    $recommendedSizes = array_filter(array_keys($availableVariants), function($v) {
        return $v !== 'original' && is_numeric($v);
    });
    $recommendedSizes = array_map('intval', $recommendedSizes);
    rsort($recommendedSizes);
    
    // Build response
    $data = [
        'timestamp' => $timestamp,
        'timestamp_iso' => gmdate('c', $timestamp),
        'formats' => $formats,
        'recommended_sizes' => array_values($recommendedSizes),
        'urls' => $urls,
    ];
    
    // Get webcam refresh interval for metadata
    $refreshSeconds = $webcam['refresh_seconds'] 
        ?? $airport['webcam_refresh_seconds'] 
        ?? 60;
    
    $meta = [
        'airport_id' => $airportId,
        'cam_index' => $camIndex,
        'refresh_seconds' => $refreshSeconds,
        'variant_heights' => $variantHeights,
    ];
    
    // Send cache headers (short TTL since image changes frequently)
    sendPublicApiCacheHeaders('live');
    
    // Send response
    sendPublicApiSuccess($data, $meta);
}

