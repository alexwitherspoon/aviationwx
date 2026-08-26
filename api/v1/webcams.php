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
require_once __DIR__ . '/../../lib/webcam-metadata.php';

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
    $formattedWebcams = listFormattedWebcamsForAirport($airportId, $airport, $weathercamOperatorFilter);

    $meta = [
        'airport_id' => $airportId,
        'airport_name' => $airport['name'] ?? '',
        'webcam_count' => count($formattedWebcams),
    ];

    sendPublicApiCacheHeaders('metadata');
    sendPublicApiSuccess(
        ['webcams' => $formattedWebcams],
        $meta
    );
}

/**
 * Format weathercams for one airport, keeping original config indexes.
 *
 * @param string $airportId Airport ID
 * @param array $airport Airport configuration
 * @param string|null $operatorFilter Lowercase slug, or null for every weathercam
 * @param bool $absoluteUrls When true, image and history URLs use the canonical v1 base
 * @return list<array<string, mixed>>
 */
function listFormattedWebcamsForAirport(
    string $airportId,
    array $airport,
    ?string $operatorFilter,
    bool $absoluteUrls = false
): array
{
    $webcams = $airport['webcams'] ?? [];
    $formattedWebcams = [];
    foreach ($webcams as $index => $webcam) {
        if (!is_array($webcam) || !weathercamMatchesOperator($webcam, $operatorFilter)) {
            continue;
        }
        $formattedWebcams[] = formatWebcamMetadata(
            $airportId,
            (int) $index,
            $webcam,
            $airport,
            $absoluteUrls
        );
    }

    return $formattedWebcams;
}

/**
 * Format weathercam data for API response
 *
 * @param string $airportId Airport ID
 * @param int $index Weathercam index
 * @param array $webcam Weathercam configuration
 * @param array $airport Airport configuration
 * @param bool $absoluteUrls When true, image and history URLs use the canonical v1 base
 * @return array Formatted weathercam metadata
 */
function formatWebcamMetadata(
    string $airportId,
    int $index,
    array $webcam,
    array $airport,
    bool $absoluteUrls = false
): array
{
    $historyEnabled = isWebcamHistoryEnabledForAirport($airportId);

    $refreshSeconds = $webcam['refresh_seconds']
        ?? $airport['webcam_refresh_seconds']
        ?? 60;

    $imagePath = '/airports/' . $airportId . '/webcams/' . $index . '/image';
    $metadata = [
        'index' => $index,
        'name' => $webcam['name'] ?? 'Camera ' . ($index + 1),
        'image_url' => publicApiV1Url($imagePath, $absoluteUrls),
        'refresh_seconds' => $refreshSeconds,
        'approximate_heading' => formatWebcamApproximateHeadingForApi($webcam),
        'operator' => getWeathercamOperator($webcam),
        'images' => formatWebcamImageVariants($airportId, $index, $absoluteUrls),
    ];

    if ($historyEnabled) {
        $metadata['history_enabled'] = true;
        $metadata['history_url'] = publicApiV1Url(
            '/airports/' . $airportId . '/webcams/' . $index . '/history',
            $absoluteUrls
        );
    }

    return $metadata;
}

/**
 * Configured original plus height variants. Original is jpg only.
 *
 * @param string $airportId Airport ID
 * @param int $index Weathercam index
 * @param bool $absoluteUrls When true, URLs use the canonical v1 base
 * @return list<array{variant: string, height: int|null, format: string, url: string}>
 */
function formatWebcamImageVariants(string $airportId, int $index, bool $absoluteUrls): array
{
    $imagePath = '/airports/' . $airportId . '/webcams/' . $index . '/image';
    $images = [
        [
            'variant' => 'original',
            'height' => null,
            'format' => 'jpg',
            'url' => publicApiV1Url($imagePath, $absoluteUrls),
        ],
    ];

    $formats = getEnabledWebcamFormats();
    foreach (getVariantHeights($airportId, $index) as $height) {
        foreach ($formats as $format) {
            $query = [];
            if ($format !== 'jpg') {
                $query['fmt'] = $format;
            }
            $query['size'] = (string) $height;
            $images[] = [
                'variant' => (string) $height,
                'height' => (int) $height,
                'format' => $format,
                'url' => publicApiV1Url($imagePath, $absoluteUrls, $query),
            ];
        }
    }

    return $images;
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

