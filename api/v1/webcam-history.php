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
    
    // Check if specific timestamp requested (return image)
    if (isset($_GET['ts'])) {
        handleGetHistoricalFrame($airportId, $camIndex, (int)$_GET['ts']);
        return;
    }
    
    // Return list of available frames
    handleGetFrameList($airportId, $camIndex, $airport);
}

/**
 * Return list of available historical frames
 * 
 * @param string $airportId Airport ID
 * @param int $camIndex Camera index
 * @param array $airport Airport configuration
 */
function handleGetFrameList(string $airportId, int $camIndex, array $airport): void
{
    require_once __DIR__ . '/../../lib/webcam-history.php';
    
    $frames = getHistoryFrames($airportId, $camIndex);
    
    $frameList = [];
    foreach ($frames as $frame) {
        // Get available variants for this frame (height-based)
        $availableVariants = getAvailableVariants($airportId, $camIndex, $frame['timestamp']);
        $variantList = [];
        if (!empty($availableVariants)) {
            foreach ($availableVariants as $variant => $formats) {
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
    
    // Sort by timestamp descending (newest first)
    usort($frameList, function ($a, $b) {
        return $b['timestamp'] - $a['timestamp'];
    });
    
    $frames = $frameList;
    
    // Get max frames setting
    $maxFrames = $airport['webcam_history_max_frames'] ?? 12;
    
    // Get variant heights for metadata
    $variantHeights = getVariantHeights($airport, $airport['webcams'][$camIndex] ?? []);
    
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
    
    // Get requested format and size
    $format = $_GET['fmt'] ?? 'jpg';
    if (!in_array($format, ['jpg', 'webp'])) {
        $format = 'jpg';
    }
    
    // Get requested size (variant) - supports height-based variants or 'original'
    $size = $_GET['size'] ?? 'original';
    
    // Validate size: numeric height or 'original'
    if ($size !== 'original' && (!is_numeric($size) || (int)$size < 1 || (int)$size > 5000)) {
        $size = 'original';
    }
    
    // Get image path for requested size using new variant system
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
    
    if ($cacheFile === null || !file_exists($cacheFile)) {
        sendPublicApiError(
            PUBLIC_API_ERROR_INVALID_REQUEST,
            'Historical frame not found for timestamp: ' . $timestamp,
            404
        );
        return;
    }
    
    // Get file info
    $fileSize = filesize($cacheFile);
    $mtime = filemtime($cacheFile);
    
    // Set content type based on format
    $contentType = match ($format) {
        'webp' => 'image/webp',
        default => 'image/jpeg',
    };
    
    // Send headers
    header('Content-Type: ' . $contentType);
    header('Content-Length: ' . $fileSize);
    header('Last-Modified: ' . gmdate('D, d M Y H:i:s', $mtime) . ' GMT');
    
    // Immutable cache - historical frames never change
    header('Cache-Control: public, max-age=31536000, immutable');
    
    // CORS headers
    header('Access-Control-Allow-Origin: *');
    
    // Output image
    readfile($cacheFile);
}

