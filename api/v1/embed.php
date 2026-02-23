<?php
/**
 * Public API - Embed Endpoint
 *
 * GET /v1/airports/{id}/embed
 *
 * Single endpoint for embed widgets. Full payload or differential (refresh=1).
 * Uses permissive CORS for third-party embedding.
 *
 * Query params:
 *   - refresh=1: Return only changed topics (value-diff from APCu)
 */

require_once __DIR__ . '/../../lib/public-api/middleware.php';
require_once __DIR__ . '/../../lib/public-api/response.php';
require_once __DIR__ . '/../../lib/config.php';
require_once __DIR__ . '/../../lib/embed-diff.php';
require_once __DIR__ . '/../../lib/cache-paths.php';
require_once __DIR__ . '/../../lib/weather/utils.php';

/**
 * Handle GET /v1/airports/{id}/embed request
 *
 * @param array $params Path parameters [0 => airport_id]
 * @param array $context Request context from middleware
 */
function handleGetEmbed(array $params, array $context): void
{
    $airportId = validatePublicApiAirportId($params[0] ?? '');

    if ($airportId === null) {
        sendPublicApiError(
            PUBLIC_API_ERROR_INVALID_REQUEST,
            'Invalid airport ID format',
            400,
            null,
            [],
            true
        );
        return;
    }

    $airport = getPublicApiAirport($airportId);

    if ($airport === null) {
        sendPublicApiError(
            PUBLIC_API_ERROR_AIRPORT_NOT_FOUND,
            'Airport not found: ' . ($params[0] ?? ''),
            404,
            null,
            [],
            true
        );
        return;
    }

    sendPublicApiCacheHeaders('live');

    $weatherData = getWeatherFromCache($airportId);
    $payload = buildEmbedPayloadByTopic($airportId, $weatherData, $airport);

    $useDiff = isset($_GET['refresh']) && $_GET['refresh'] === '1';

    if ($useDiff) {
        $diff = getEmbedDiffPayload($airportId, $payload);
        if ($diff === null) {
            sendPublicApiSuccess(
                ['embed' => $payload, 'diff' => false],
                [
                    'airport_id' => $airportId,
                    'full' => true,
                ],
                200,
                [],
                true
            );
            return;
        }

        sendPublicApiSuccess(
            ['embed' => $diff, 'diff' => true],
            ['airport_id' => $airportId],
            200,
            [],
            true
        );
        return;
    }

    sendPublicApiSuccess(
        ['embed' => $payload, 'diff' => false],
        ['airport_id' => $airportId],
        200,
        [],
        true
    );
}

/**
 * Get weather data from cache file
 *
 * @param string $airportId Airport ID
 * @return array|null Weather data or null if unavailable
 */
function getWeatherFromCache(string $airportId): ?array
{
    $cacheFile = getWeatherCachePath($airportId);

    if (!file_exists($cacheFile)) {
        return null;
    }

    // @ suppresses read errors; we handle failures explicitly below
    $content = @file_get_contents($cacheFile);
    if ($content === false) {
        return null;
    }

    $data = json_decode($content, true);
    if (!is_array($data)) {
        return null;
    }

    return $data;
}
