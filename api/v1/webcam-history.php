<?php
/**
 * Public API - Get Webcam History Endpoint
 * 
 * GET /v1/airports/{id}/webcams/{cam}/history
 * 
 * Returns list of historical webcam frames, or a specific historical frame.
 * 
 * Query parameters:
 * - ts: Timestamp of specific frame to retrieve (returns image)
 *       If omitted, returns JSON list of available frames
 */

require_once __DIR__ . '/../../lib/public-api/middleware.php';
require_once __DIR__ . '/../../lib/public-api/query.php';
require_once __DIR__ . '/../../lib/public-api/response.php';
require_once __DIR__ . '/../../lib/config.php';
require_once __DIR__ . '/../../lib/cache-paths.php';
require_once __DIR__ . '/../../lib/webcam-history.php';
require_once __DIR__ . '/../../lib/webcam-metadata.php';

/**
 * Handle GET /v1/airports/{id}/webcams/{cam}/history request
 * 
 * @param array $params Path parameters [0 => airport_id, 1 => cam_index]
 * @param array $context Request context from middleware
 */
function handleGetWebcamHistory(array $params, array $context): void
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
    
    // Check if history is enabled and available
    $historyStatus = getHistoryStatus($airportId, $camIndex);
    
    if (!$historyStatus['enabled']) {
        sendPublicApiError(
            PUBLIC_API_ERROR_INVALID_REQUEST,
            'Webcam history is not configured for this airport',
            404
        );
        return;
    }
    
    $timestampParse = parsePublicApiWebcamTimestampQueryFromGet();
    if (!$timestampParse['ok']) {
        sendPublicApiError(
            PUBLIC_API_ERROR_INVALID_REQUEST,
            $timestampParse['error'],
            400
        );
        return;
    }

    if ($timestampParse['present']) {
        handleGetHistoricalFrame($airportId, $camIndex, $timestampParse['timestamp']);
        return;
    }
    
    // Return list of available frames
    handleGetFrameList($airportId, $camIndex, $airport);
}

/**
 * Return list of available historical frames
 * 
 * Uses APCu cache with 60-second TTL to avoid repeated directory scans.
 * Cache key includes airport and camera to ensure proper isolation.
 * 
 * @param string $airportId Airport ID
 * @param int $camIndex Camera index
 * @param array $airport Airport configuration
 */
function handleGetFrameList(string $airportId, int $camIndex, array $airport): void
{
    require_once __DIR__ . '/../../lib/webcam-history.php';
    
    // Try APCu cache first (60-second TTL matches refresh interval)
    $cacheKey = "webcam_history_frames_{$airportId}_{$camIndex}";
    $frames = false;
    
    if (function_exists('apcu_fetch')) {
        $frames = apcu_fetch($cacheKey);
    }
    
    // Cache miss - fetch from filesystem
    if ($frames === false) {
        $frames = getHistoryFrames($airportId, $camIndex);
        
        $frameList = [];
        foreach ($frames as $frame) {
            // Use variants data already computed by getHistoryFrames()
            // Avoids N+1 query pattern (1000+ redundant directory scans)
            $variantList = [];
            if (!empty($frame['variants'])) {
                foreach ($frame['variants'] as $variant => $formats) {
                    if ($variant === 'original') {
                        $variantList[] = 'original';
                    } elseif (is_numeric($variant)) {
                        $variantList[] = (int)$variant;
                    }
                }
            }
            
            // If no variants found, default to original
            if (empty($variantList)) {
                $variantList = ['original'];
            }
            
            $frameList[] = [
                'timestamp' => $frame['timestamp'],
                'timestamp_iso' => gmdate('c', $frame['timestamp']),
                'url' => '/v1/airports/' . $airportId . '/webcams/' . $camIndex . '/history?ts=' . $frame['timestamp'],
                'formats' => $frame['formats'] ?? ['jpg'],
                'variants' => $variantList
            ];
        }
        
        $frames = $frameList;
        
        // Sort by timestamp descending (newest first)
        usort($frames, function ($a, $b) {
            return $b['timestamp'] - $a['timestamp'];
        });
        
        // Store in APCu cache (60-second TTL)
        if (function_exists('apcu_store')) {
            apcu_store($cacheKey, $frames, 60);
        }
    }
    
    // Get max frames setting
    $maxFrames = $airport['webcam_history_max_frames'] ?? 12;
    
    // Get variant heights for metadata
    $variantHeights = getVariantHeights($airportId, $camIndex);
    
    // Build metadata
    $meta = [
        'airport_id' => $airportId,
        'cam_index' => $camIndex,
        'frame_count' => count($frames),
        'max_frames' => $maxFrames,
        'timezone' => $airport['timezone'] ?? 'UTC',
        'variantHeights' => $variantHeights,
    ];
    
    // Send cache headers for live data (frames list changes as new frames arrive)
    sendPublicApiCacheHeaders('live');
    
    // Send response
    sendPublicApiSuccess(
        ['frames' => $frames],
        $meta
    );
}

/**
 * Return a specific historical frame image
 * 
 * @param string $airportId Airport ID
 * @param int $camIndex Camera index
 * @param int $timestamp Frame timestamp
 */
function handleGetHistoricalFrame(string $airportId, int $camIndex, int $timestamp): void
{
    require_once __DIR__ . '/../../lib/webcam-history.php';

    $sizeParse = parsePublicApiWebcamSizeQueryFromGet();
    if (!$sizeParse['ok']) {
        sendPublicApiError(
            PUBLIC_API_ERROR_INVALID_REQUEST,
            $sizeParse['error'],
            400
        );
        return;
    }
    $size = $sizeParse['size'];

    $fmtParse = parsePublicApiWebcamFmtQueryFromGet();
    if (!$fmtParse['ok']) {
        sendPublicApiError(
            PUBLIC_API_ERROR_INVALID_REQUEST,
            $fmtParse['error'],
            400
        );
        return;
    }
    $explicitFmt = $fmtParse['explicit'];

    if ($size === 'original' && $explicitFmt) {
        sendPublicApiError(
            PUBLIC_API_ERROR_INVALID_REQUEST,
            'fmt applies to generated size variants. Omit fmt for the original.',
            400
        );
        return;
    }

    $cacheFile = null;
    $format = 'jpg';

    if ($size === 'original' && !$explicitFmt) {
        $resolved = resolveWebcamOriginalAtTimestamp($airportId, $camIndex, $timestamp);
        if (!$resolved['ok']) {
            if ($resolved['error'] === 'unknown') {
                sendPublicApiError(
                    PUBLIC_API_ERROR_INVALID_REQUEST,
                    'Original image format is not supported',
                    400
                );
            } else {
                sendPublicApiError(
                    PUBLIC_API_ERROR_INVALID_REQUEST,
                    'Historical frame not found for timestamp: ' . $timestamp,
                    404
                );
            }
            return;
        }
        $cacheFile = $resolved['path'];
        $format = $resolved['format'];
    } else {
        $format = $fmtParse['format'] ?? 'jpg';

        $found = findHistoricalWebcamSizeFile($airportId, $camIndex, $timestamp, $size, $format);
        $cacheFile = $found['path'];
        $size = $found['size'];
    }

    if ($cacheFile === null || !file_exists($cacheFile)) {
        sendPublicApiError(
            PUBLIC_API_ERROR_INVALID_REQUEST,
            'Historical frame not found for timestamp: ' . $timestamp,
            404
        );
        return;
    }

    $opened = openServableWebcamImage($cacheFile);
    if ($opened === null) {
        sendPublicApiError(
            PUBLIC_API_ERROR_INVALID_REQUEST,
            'Image format is not supported',
            400
        );
        return;
    }
    if (!webcamExplicitFmtMatchesFile($explicitFmt, $format, $opened['format'])) {
        fclose($opened['handle']);
        sendPublicApiError(
            PUBLIC_API_ERROR_INVALID_REQUEST,
            "Requested format '{$format}' does not match the image file",
            400
        );
        return;
    }
    $format = $opened['format'];

    $variant = ($size === 'original') ? 'original' : (int)$size;
    $filename = $timestamp . '_' . $variant . '.' . $format;

    header('Cache-Control: public, max-age=31536000, immutable');
    header('Access-Control-Allow-Origin: *');

    require_once __DIR__ . '/../../lib/http-integrity.php';
    if (addIntegrityHeadersForOpenFile(
        $opened['handle'],
        $cacheFile,
        $opened['mtime'],
        $opened['size']
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

