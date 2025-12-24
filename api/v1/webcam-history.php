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
    
    // Check if history is enabled
    $historyEnabled = isset($airport['webcam_history_enabled']) 
        && $airport['webcam_history_enabled'] === true;
    
    if (!$historyEnabled) {
        sendPublicApiError(
            PUBLIC_API_ERROR_INVALID_REQUEST,
            'Webcam history is not enabled for this airport',
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
    $historyDir = __DIR__ . '/../../cache/webcams/' . $airportId . '/history/cam' . $camIndex;
    
    $frames = [];
    
    if (is_dir($historyDir)) {
        $files = glob($historyDir . '/*.jpg');
        
        foreach ($files as $file) {
            $basename = basename($file, '.jpg');
            // Filename format: {timestamp}.jpg
            if (is_numeric($basename)) {
                $timestamp = (int)$basename;
                $frames[] = [
                    'timestamp' => $timestamp,
                    'timestamp_iso' => gmdate('c', $timestamp),
                    'url' => '/v1/airports/' . $airportId . '/webcams/' . $camIndex . '/history?ts=' . $timestamp,
                ];
            }
        }
        
        // Sort by timestamp descending (newest first)
        usort($frames, function ($a, $b) {
            return $b['timestamp'] - $a['timestamp'];
        });
    }
    
    // Get max frames setting
    $maxFrames = $airport['webcam_history_max_frames'] ?? 12;
    
    // Build metadata
    $meta = [
        'airport_id' => $airportId,
        'cam_index' => $camIndex,
        'frame_count' => count($frames),
        'max_frames' => $maxFrames,
        'timezone' => $airport['timezone'] ?? 'UTC',
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
    $historyDir = __DIR__ . '/../../cache/webcams/' . $airportId . '/history/cam' . $camIndex;
    $frameFile = $historyDir . '/' . $timestamp . '.jpg';
    
    if (!file_exists($frameFile)) {
        sendPublicApiError(
            PUBLIC_API_ERROR_INVALID_REQUEST,
            'Historical frame not found for timestamp: ' . $timestamp,
            404
        );
        return;
    }
    
    // Get file info
    $fileSize = filesize($frameFile);
    $mtime = filemtime($frameFile);
    
    // Send headers
    header('Content-Type: image/jpeg');
    header('Content-Length: ' . $fileSize);
    header('Last-Modified: ' . gmdate('D, d M Y H:i:s', $mtime) . ' GMT');
    
    // Immutable cache - historical frames never change
    header('Cache-Control: public, max-age=31536000, immutable');
    
    // CORS headers
    header('Access-Control-Allow-Origin: *');
    
    // Output image
    readfile($frameFile);
}

