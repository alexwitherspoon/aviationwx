<?php
/**
 * Public API - List Webcams Endpoint
 * 
 * GET /v1/airports/{id}/webcams
 * 
 * Returns a list of webcams for an airport with metadata.
 */

require_once __DIR__ . '/../../lib/public-api/middleware.php';
require_once __DIR__ . '/../../lib/public-api/response.php';
require_once __DIR__ . '/../../lib/config.php';

/**
 * Handle GET /v1/airports/{id}/webcams request
 * 
 * @param array $params Path parameters [0 => airport_id]
 * @param array $context Request context from middleware
 */
function handleListWebcams(array $params, array $context): void
{
    $airportId = validatePublicApiAirportId($params[0] ?? '');
    
    if ($airportId === null) {
        sendPublicApiError(
            PUBLIC_API_ERROR_INVALID_REQUEST,
            'Invalid airport ID format',
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
    
    // Get webcams
    $webcams = $airport['webcams'] ?? [];
    
    // Format webcams for response
    $formattedWebcams = [];
    foreach ($webcams as $index => $webcam) {
        $formattedWebcams[] = formatWebcamMetadata($airportId, $index, $webcam, $airport);
    }
    
    // Build metadata
    $meta = [
        'airport_id' => $airportId,
        'airport_name' => $airport['name'] ?? '',
        'webcam_count' => count($formattedWebcams),
    ];
    
    // Send cache headers for metadata
    sendPublicApiCacheHeaders('metadata');
    
    // Send response
    sendPublicApiSuccess(
        ['webcams' => $formattedWebcams],
        $meta
    );
}

/**
 * Format webcam data for API response
 * 
 * @param string $airportId Airport ID
 * @param int $index Webcam index
 * @param array $webcam Webcam configuration
 * @param array $airport Airport configuration
 * @return array Formatted webcam metadata
 */
function formatWebcamMetadata(string $airportId, int $index, array $webcam, array $airport): array
{
    // Check if history is enabled for this webcam (max_frames >= 2 enables history)
    $historyEnabled = isWebcamHistoryEnabledForAirport($airportId);
    
    // Get refresh interval
    $refreshSeconds = $webcam['refresh_seconds'] 
        ?? $airport['webcam_refresh_seconds'] 
        ?? 60;
    
    return [
        'index' => $index,
        'name' => $webcam['name'] ?? 'Camera ' . ($index + 1),
        'image_url' => '/v1/airports/' . $airportId . '/webcams/' . $index . '/image',
        'history_enabled' => $historyEnabled,
        'history_url' => $historyEnabled 
            ? '/v1/airports/' . $airportId . '/webcams/' . $index . '/history'
            : null,
        'refresh_seconds' => $refreshSeconds,
    ];
}

