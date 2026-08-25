<?php
/**
 * Public API - List Weathercams Endpoint
 *
 * GET /v1/airports/{id}/webcams
 *
 * Returns a list of weathercams for an airport with metadata.
 * Path and JSON key remain `webcams` for compatibility.
 *
 * Query parameters:
 * - operator: Only include weathercams whose operator equals this slug
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
    
    $operatorParse = parseOperatorQueryFromGet();
    if (!$operatorParse['ok']) {
        sendPublicApiError(
            PUBLIC_API_ERROR_INVALID_REQUEST,
            $operatorParse['error'],
            400
        );
        return;
    }
    $weathercamOperatorFilter = $operatorParse['value'];

    $webcams = $airport['webcams'] ?? [];

    // Keep original indexes so image URLs do not move when a filter omits a camera
    $formattedWebcams = [];
    foreach ($webcams as $index => $webcam) {
        if (!is_array($webcam) || !weathercamMatchesOperator($webcam, $weathercamOperatorFilter)) {
            continue;
        }
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
 * Format weathercam data for API response
 *
 * @param string $airportId Airport ID
 * @param int $index Weathercam index
 * @param array $webcam Weathercam configuration
 * @param array $airport Airport configuration
 * @return array Formatted weathercam metadata
 */
function formatWebcamMetadata(string $airportId, int $index, array $webcam, array $airport): array
{
    // Check if history is enabled for this weathercam (max_frames >= 2 enables history)
    $historyEnabled = isWebcamHistoryEnabledForAirport($airportId);
    
    // Get refresh interval
    $refreshSeconds = $webcam['refresh_seconds'] 
        ?? $airport['webcam_refresh_seconds'] 
        ?? 60;
    
    $metadata = [
        'index' => $index,
        'name' => $webcam['name'] ?? 'Camera ' . ($index + 1),
        'image_url' => '/v1/airports/' . $airportId . '/webcams/' . $index . '/image',
        'refresh_seconds' => $refreshSeconds,
        'approximate_heading' => formatWebcamApproximateHeadingForApi($webcam),
        'operator' => getWeathercamOperator($webcam),
    ];

    if ($historyEnabled) {
        $metadata['history_enabled'] = true;
        $metadata['history_url'] = '/v1/airports/' . $airportId . '/webcams/' . $index . '/history';
    }

    return $metadata;
}

/**
 * Resolve approximate_heading for Public API output without unsafe coercion.
 *
 * Config validation requires integer 0-360, but runtime config may be stale or
 * hand-edited. Reject non-integers and out-of-range values rather than casting.
 *
 * @param array $webcam Weathercam configuration
 * @return int|null Heading degrees, or null when omitted or invalid
 */
function formatWebcamApproximateHeadingForApi(array $webcam): ?int
{
    if (!array_key_exists('approximate_heading', $webcam)) {
        return null;
    }

    $heading = $webcam['approximate_heading'];
    if (!is_int($heading) || $heading < 0 || $heading > 360) {
        return null;
    }

    return $heading;
}

