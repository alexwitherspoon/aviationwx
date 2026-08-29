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
 * - fmt: Enabled generation format for sized variants ('jpg', 'webp' when configured). Not valid on the original.
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
 * - Neither: Native original. Format is read from the file (jpg, png, or webp) and returned in Content-Type. Unsupported files are not served.
 * 
 * FAA Profile (profile=faa):
 *   - Applies per-camera crop_margins to exclude timestamps/watermarks
 *   - Forces 4:3 aspect ratio
 *   - Forces JPG format
 *   - Quality-capped: 1280x960 if source supports, else 640x480 (no upscaling)
 *   GET /v1/airports/kspb/webcams/0/image?profile=faa
 */

require_once __DIR__ . '/../../lib/public-api/middleware.php';
require_once __DIR__ . '/../../lib/public-api/query.php';
require_once __DIR__ . '/../../lib/public-api/response.php';
require_once __DIR__ . '/../../lib/config.php';
require_once __DIR__ . '/../../lib/cache-headers.php';
require_once __DIR__ . '/../../lib/webcam-metadata.php';
require_once __DIR__ . '/../../lib/image-transform.php';
require_once __DIR__ . '/../../lib/metrics.php';

/**
 * @param string $format jpg, png, or webp
 * @return string Content-Type
 * @throws InvalidArgumentException When $format is not jpg, png, or webp
 */
function publicApiWebcamContentType(string $format): string
{
    $type = webcamImageContentType($format);
    if ($type === null) {
        throw new InvalidArgumentException('Unsupported webcam image format');
    }

    return $type;
}

/**
 * fmt is only valid with a generated size or width/height transform.
 *
 * @param bool $explicitFormatRequest True when the client sent fmt
 * @param string $size Normalized size (original or a height)
 * @param int|null $requestedWidth Transform width
 * @param int|null $requestedHeight Transform height
 * @return bool True when fmt must be rejected
 */
function publicApiWebcamFmtRejectedOnOriginal(
    bool $explicitFormatRequest,
    string $size,
    ?int $requestedWidth,
    ?int $requestedHeight
): bool {
    if (!$explicitFormatRequest) {
        return false;
    }
    if ($requestedWidth !== null || $requestedHeight !== null) {
        return false;
    }

    return $size === 'original';
}

/**
 * Public API image URL for a variant/format pair.
 *
 * Native original has no fmt. Sized rows add fmt only when the generation format is not jpg.
 *
 * @param string $airportId Airport ID
 * @param int $camIndex Camera index
 * @param string $variantKey original or a height as a string
 * @param string $format jpg, png, or webp
 * @return string Path with optional query
 */
function publicApiWebcamVariantUrl(string $airportId, int $camIndex, string $variantKey, string $format): string
{
    $url = '/v1/airports/' . $airportId . '/webcams/' . $camIndex . '/image';
    $params = [];
    if ($variantKey !== 'original') {
        if ($format !== 'jpg') {
            $params[] = 'fmt=' . $format;
        }
        $params[] = 'size=' . $variantKey;
    }
    if ($params !== []) {
        $url .= '?' . implode('&', $params);
    }

    return $url;
}

/**
 * Effective webcam refresh interval for caching.
 *
 * @param array $airport Airport config
 * @param array $cam Webcam config (camera-level refresh override)
 * @return int Refresh interval in seconds (camera -> airport -> global)
 */
function webcamRefreshInterval(array $airport, array $cam): int {
    $refresh = getDefaultWebcamRefresh();
    if (isset($airport['webcam_refresh_seconds'])) {
        $refresh = intval($airport['webcam_refresh_seconds']);
    }
    if (isset($cam['refresh_seconds'])) {
        $refresh = intval($cam['refresh_seconds']);
    }
    return $refresh;
}

/**
 * Headers for an over-age webcam frame: serve the last known image as 200 but
 * refuse to cache it as current and log the delivery.
 *
 * @param string $airportId Airport identifier
 * @param int $camIndex Camera index (0-based)
 * @param int $captureTimestamp Capture timestamp of the frame
 * @param array $cam Webcam config (camera-level fail-closed override)
 * @param array $airport Airport config
 * @return array{headers: array<string,string>}
 */
function webcamStaleHeaders(string $airportId, int $camIndex, int $captureTimestamp, array $cam, array $airport): array {
    aviationwx_log('warning', 'serving over-age webcam frame past fail-closed threshold', [
        'airport' => $airportId,
        'cam' => $camIndex,
        'capture_timestamp' => $captureTimestamp,
        'age_seconds' => time() - $captureTimestamp,
        'failclosed_seconds' => getWebcamStaleFailclosedSeconds($cam, $airport),
    ], 'app');
    $headers = getNoStoreCacheHeaders(0);
    $headers['Warning'] = '110 - "Response is stale"';
    return ['headers' => $headers];
}

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
    
    // Handle download request - native original with attachment filename
    if (isset($_GET['download']) && $_GET['download'] == '1') {
        $fmtParse = parsePublicApiWebcamFmtQueryFromGet();
        if (!$fmtParse['ok']) {
            sendPublicApiError(
                PUBLIC_API_ERROR_INVALID_REQUEST,
                $fmtParse['error'],
                400
            );
            return;
        }
        if ($fmtParse['explicit']) {
            sendPublicApiError(
                PUBLIC_API_ERROR_INVALID_REQUEST,
                'fmt applies to generated size variants. Omit fmt for the original.',
                400
            );
            return;
        }

        $currentOriginal = getCurrentServableWebcamOriginal($airportId, $camIndex);
        if ($currentOriginal === null || !is_readable($currentOriginal['path'])) {
            sendPublicApiError(
                PUBLIC_API_ERROR_SERVICE_UNAVAILABLE,
                'Original image not available for download',
                503
            );
            return;
        }
        $originalFile = $currentOriginal['path'];

        $opened = openServableWebcamImage($originalFile);
        if ($opened === null) {
            sendPublicApiError(
                PUBLIC_API_ERROR_INVALID_REQUEST,
                'Image format is not supported',
                400
            );
            return;
        }
        $format = $opened['format'];
        $mtime = $opened['mtime'];
        $filenameStamp = gmdate('Y-m-d_His', $currentOriginal['timestamp']) . '_UTC';
        $filename = strtolower($airportId) . "_{$camIndex}_{$filenameStamp}." . $format;

        // Latest download is mutable. Over-age frames use the stale policy (no-store,
        // flagged); fresh frames below the threshold use the normal refresh TTL (they
        // flip to stale at the threshold, which is re-evaluated here).
        $captureTimestamp = $currentOriginal['timestamp'];
        $stale = $captureTimestamp > 0 && (time() - $captureTimestamp) > getWebcamStaleFailclosedSeconds($webcam, $airport);
        if ($stale) {
            $headers = webcamStaleHeaders($airportId, $camIndex, $captureTimestamp, $webcam, $airport)['headers'];
        } else {
            $webcamRefresh = webcamRefreshInterval($airport, $webcam);
            $age = max(0, time() - $captureTimestamp);
            $remaining = max(0, getWebcamStaleFailclosedSeconds($webcam, $airport) - $age);
            if ($remaining <= STALE_WHILE_REVALIDATE_SECONDS + $webcamRefresh) {
                $headers = getNoStoreCacheHeaders(0);
            } else {
                $headers = generateCacheHeaders($webcamRefresh, $webcamRefresh);
            }
        }
        foreach ($headers as $name => $value) {
            header($name . ': ' . $value);
        }

        require_once __DIR__ . '/../../lib/http-integrity.php';
        if (addIntegrityHeadersForOpenFile(
            $opened['handle'],
            $originalFile,
            $mtime,
            $opened['size'],
            $stale ? $captureTimestamp : null
        )) {
            fclose($opened['handle']);
            exit;
        }

        header('Content-Type: ' . $opened['content_type']);
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        header('Content-Length: ' . $opened['size']);

        fpassthru($opened['handle']);
        fclose($opened['handle']);
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
    
    $fmtParse = parsePublicApiWebcamFmtQueryFromGet();
    if (!$fmtParse['ok']) {
        sendPublicApiError(
            PUBLIC_API_ERROR_INVALID_REQUEST,
            $fmtParse['error'],
            400
        );
        return;
    }

    $widthParse = parsePublicApiWebcamDimensionQueryFromGet('width', 16, 3840);
    $heightParse = parsePublicApiWebcamDimensionQueryFromGet('height', 16, 2160);
    $sizeParse = parsePublicApiWebcamSizeQueryFromGet();
    foreach ([$widthParse, $heightParse, $sizeParse] as $queryParse) {
        if (!$queryParse['ok']) {
            sendPublicApiError(
                PUBLIC_API_ERROR_INVALID_REQUEST,
                $queryParse['error'],
                400
            );
            return;
        }
    }
    $requestedWidth = $widthParse['value'];
    $requestedHeight = $heightParse['value'];
    $size = $sizeParse['size'];
    if (
        array_key_exists('size', $_GET)
        && ($requestedWidth !== null || $requestedHeight !== null)
    ) {
        sendPublicApiError(
            PUBLIC_API_ERROR_INVALID_REQUEST,
            'size cannot be combined with width or height',
            400
        );
        return;
    }
    $explicitFormatRequest = $fmtParse['explicit'];
    if (publicApiWebcamFmtRejectedOnOriginal(
        $explicitFormatRequest,
        $size,
        $requestedWidth,
        $requestedHeight
    )) {
        sendPublicApiError(
            PUBLIC_API_ERROR_INVALID_REQUEST,
            'fmt applies to generated size variants. Omit fmt for the original.',
            400
        );
        return;
    }

    $currentOriginal = getCurrentServableWebcamOriginal($airportId, $camIndex);
    $timestamp = $currentOriginal['timestamp'] ?? getLatestImageTimestamp($airportId, $camIndex);
    
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
    $format = $fmtParse['format'] ?? 'jpg';

    if ($requestedWidth !== null || $requestedHeight !== null) {
        $cacheFile = handleTransformRequest(
            $airportId,
            $camIndex,
            $timestamp,
            $requestedWidth,
            $requestedHeight,
            $format
        );
        
        if ($cacheFile === null) {
            return;
        }
        
        if ($requestedWidth !== null && $requestedHeight !== null) {
            $variant = $requestedWidth . 'x' . $requestedHeight;
        } elseif ($requestedWidth !== null) {
            $variant = 'w' . $requestedWidth;
        } else {
            $variant = 'h' . $requestedHeight;
        }
    } else {
        if ($size === 'original' && !$explicitFormatRequest) {
            if ($currentOriginal === null) {
                sendPublicApiError(
                    PUBLIC_API_ERROR_SERVICE_UNAVAILABLE,
                    'Webcam image temporarily unavailable',
                    503
                );
                return;
            }
            $cacheFile = $currentOriginal['path'];
            $format = $currentOriginal['format'];
        } else {
            $cacheFile = getImagePathForSize($airportId, $camIndex, $timestamp, $size, $format);

            if ($cacheFile === null && $explicitFormatRequest) {
                $variantHeights = getVariantHeights($airportId, $camIndex);
                $availableSizes = [];

                foreach ($variantHeights as $height) {
                    $variantPath = getImagePathForSize($airportId, $camIndex, $timestamp, $height, $format);
                    if ($variantPath !== null) {
                        $availableSizes[] = $height;
                    }
                }

                if (!empty($availableSizes)) {
                    sendPublicApiError(
                        PUBLIC_API_ERROR_INVALID_REQUEST,
                        "Format '{$format}' is not available for size '{$size}'. " .
                        "Available sizes for {$format}: " . implode(', ', $availableSizes) . '.',
                        400
                    );
                    return;
                }

                sendPublicApiError(
                    PUBLIC_API_ERROR_INVALID_REQUEST,
                    "Format '{$format}' is not available for this webcam. Use the size parameter to request specific variants (e.g. 720, 1080).",
                    400
                );
                return;
            }

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

    sendImageResponse(
        $airportId,
        $camIndex,
        $cacheFile,
        $timestamp,
        $variant,
        $format,
        $airport,
        $webcams[$camIndex],
        $explicitFormatRequest
    );
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
    $currentOriginal = getCurrentServableWebcamOriginal($airportId, $camIndex);
    $timestamp = $currentOriginal['timestamp'] ?? 0;
    
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
        $sourcePath = resolveWebcamTransformSourcePath($airportId, $camIndex, $timestamp);
        if ($sourcePath === null) {
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
 * @param string $format Caller hint; body type is taken from the file header
 * @param array $airport Airport configuration
 * @param array $cam Camera configuration
 * @param bool $explicitFormatRequest Whether fmt was supplied by the client
 */
function sendImageResponse(
    string $airportId,
    int $camIndex,
    string $cacheFile,
    int $timestamp,
    string|int $variant,
    string $format,
    array $airport,
    array $cam,
    bool $explicitFormatRequest = false
): void {
    $opened = openServableWebcamImage($cacheFile);
    if ($opened === null) {
        sendPublicApiError(
            PUBLIC_API_ERROR_INVALID_REQUEST,
            'Image format is not supported',
            400
        );
        return;
    }
    if (!webcamExplicitFmtMatchesFile($explicitFormatRequest, $format, $opened['format'])) {
        fclose($opened['handle']);
        sendPublicApiError(
            PUBLIC_API_ERROR_INVALID_REQUEST,
            "Requested format '{$format}' does not match the image file",
            400
        );
        return;
    }
    $format = $opened['format'];

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

    $mtime = $opened['mtime'];
    $filename = $timestamp . '_' . $variant . '.' . $format;

    $webcamRefresh = getDefaultWebcamRefresh();
    if (isset($airport['webcam_refresh_seconds'])) {
        $webcamRefresh = intval($airport['webcam_refresh_seconds']);
    }
    if (isset($cam['refresh_seconds'])) {
        $webcamRefresh = intval($cam['refresh_seconds']);
    }

    // Over-age: last known good frame, served as 200 but no-store and flagged
    // stale; fresh frames below the threshold use the normal refresh TTL.
    $stale = $timestamp > 0 && (time() - $timestamp) > getWebcamStaleFailclosedSeconds($cam, $airport);
    if ($stale) {
        $headers = webcamStaleHeaders($airportId, $camIndex, $timestamp, $cam, $airport)['headers'];
    } else {
        $age = max(0, time() - $timestamp);
        $remaining = max(0, getWebcamStaleFailclosedSeconds($cam, $airport) - $age);
        if ($remaining <= STALE_WHILE_REVALIDATE_SECONDS + $webcamRefresh) {
            $headers = getNoStoreCacheHeaders(0);
        } else {
            $headers = generateCacheHeaders($webcamRefresh, $webcamRefresh);
        }
    }
    foreach ($headers as $name => $value) {
        header($name . ': ' . $value);
    }
    header('Access-Control-Allow-Origin: *');

    require_once __DIR__ . '/../../lib/http-integrity.php';
    if (addIntegrityHeadersForOpenFile(
        $opened['handle'],
        $cacheFile,
        $mtime,
        $opened['size'],
        $stale ? $timestamp : null
    )) {
        fclose($opened['handle']);
        return;
    }

    header('Content-Type: ' . $opened['content_type']);
    header('Content-Disposition: inline; filename="' . $filename . '"');
    header('Content-Length: ' . $opened['size']);

    fpassthru($opened['handle']);
    fclose($opened['handle']);
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
    
    $currentOriginal = getCurrentServableWebcamOriginal($airportId, $camIndex);
    if ($currentOriginal === null) {
        sendPublicApiError(
            PUBLIC_API_ERROR_SERVICE_UNAVAILABLE,
            'Webcam image temporarily unavailable',
            503
        );
        return;
    }
    $timestamp = $currentOriginal['timestamp'];
    
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
            $urls[$urlKey] = publicApiWebcamVariantUrl(
                $airportId,
                $camIndex,
                (string) $variantKey,
                $format
            );
        }
    }
    
    // Build recommended sizes (available sized variants, sorted descending)
    $recommendedSizes = array_filter(array_keys($availableVariants), function($v) {
        return $v !== 'original' && is_numeric($v);
    });
    $recommendedSizes = array_map('intval', $recommendedSizes);
    rsort($recommendedSizes);
    
    // Build response
    $ageSeconds = max(0, time() - $timestamp);
    $staleFailClosed = getWebcamStaleFailclosedSeconds($webcam, $airport);
    $data = [
        'timestamp' => $timestamp,
        'timestamp_iso' => gmdate('c', $timestamp),
        'age_seconds' => $ageSeconds,
        'stale' => $ageSeconds > $staleFailClosed,
        'stale_failclosed_seconds' => $staleFailClosed,
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
    
    // age_seconds/stale are time-relative, so this payload must not be cached;
    // a 60s shared cache would serve values that no longer match the capture age.
    sendPublicApiCacheHeaders('none');
    
    // Send response
    sendPublicApiSuccess($data, $meta);
}

