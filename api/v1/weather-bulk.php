<?php
/**
 * Public API - Bulk Weather Endpoint
 *
 * GET /v1/weather/bulk?airports=kspb,kczk,kpfc
 *
 * Returns current weather for multiple airports in a single request. When the
 * airports parameter is omitted, weather for all listed airports is returned.
 * operator filters by weather-source operator; maintenance controls whether
 * maintenance airports are included (excluded by default).
 *
 * Selection and payload construction are pure helpers so the endpoint logic is
 * unit-testable without an HTTP round trip.
 */

require_once __DIR__ . '/../../lib/public-api/middleware.php';
require_once __DIR__ . '/../../lib/public-api/response.php';
require_once __DIR__ . '/../../lib/public-api/config.php';
require_once __DIR__ . '/../../lib/config.php';
require_once __DIR__ . '/../../lib/metrics.php';
require_once __DIR__ . '/airports.php';

// Include the weather formatting function from the single weather endpoint
require_once __DIR__ . '/weather.php';

/**
 * Handle GET /v1/weather/bulk request
 *
 * @param array $params Path parameters (empty for this endpoint)
 * @param array $context Request context from middleware
 */
function handleGetWeatherBulk(array $params, array $context): void
{
    $operatorParse = parseOperatorQueryFromGet();
    if (!$operatorParse['ok']) {
        sendPublicApiError(
            PUBLIC_API_ERROR_INVALID_REQUEST,
            $operatorParse['error'],
            400
        );
        return;
    }
    $operator = $operatorParse['value'];
    $maintenance = parsePublicApiOptionalBooleanQuery('maintenance');

    $airportsParam = $_GET['airports'] ?? '';
    $requestedIds = null;
    if (is_string($airportsParam) && trim($airportsParam) !== '') {
        $requestedIds = [];
        foreach (explode(',', $airportsParam) as $rawId) {
            $trimmed = trim($rawId);
            if ($trimmed !== '') {
                $requestedIds[] = $trimmed;
            }
        }

        if (empty($requestedIds)) {
            sendPublicApiError(
                PUBLIC_API_ERROR_INVALID_REQUEST,
                'No valid airport IDs provided',
                400
            );
            return;
        }

        if (count($requestedIds) > getPublicApiBulkMaxAirports()) {
            sendPublicApiError(
                PUBLIC_API_ERROR_INVALID_REQUEST,
                'Too many airports requested. Maximum is ' . getPublicApiBulkMaxAirports(),
                400
            );
            return;
        }
    }

    $config = loadConfig();
    if ($config === null) {
        $config = ['airports' => []];
    }

    $errors = [];
    $candidates = resolveWeatherBulkCandidates($config, $maintenance === true, $requestedIds, $errors);
    $requestedCount = count($candidates);

    [$weatherData, $returned] = buildWeatherBulkPayload(
        filterWeatherBulkCandidates($candidates, $operator)
    );

    $meta = [
        'requested' => $requestedCount,
        'returned' => $returned,
        'units' => getPublicApiWeatherUnits(),
    ];

    if (!empty($errors)) {
        $meta['errors'] = $errors;
    }

    // Send cache headers for live data
    sendPublicApiCacheHeaders('live');

    // Send response
    sendPublicApiSuccess(
        ['weather' => $weatherData],
        $meta
    );
}

/**
 * Resolve the candidate airports for a weather bulk response.
 *
 * Explicit mode validates each requested id and reports id-level problems in
 * $errors. All mode takes every enabled, listed airport. Maintenance airports
 * are excluded unless $includeMaintenance is true, matching weathercam/bulk.
 *
 * @param array{airports?: array<string, mixed>} $config Loaded config
 * @param bool $includeMaintenance True when maintenance airports are allowed
 * @param list<string>|null $requestedIds Explicit airport ids, or null for all
 * @param array<string, string> $errors Populated with id-level error messages
 * @return array<string, array<string, mixed>> Airport id => config
 */
function resolveWeatherBulkCandidates(array $config, bool $includeMaintenance, ?array $requestedIds, array &$errors): array
{
    $airports = $config['airports'] ?? [];

    if ($requestedIds !== null) {
        $candidates = [];
        $seen = [];
        foreach ($requestedIds as $rawId) {
            $airportId = validatePublicApiAirportId($rawId);
            if ($airportId === null) {
                $errors[$rawId] = 'Invalid airport ID format';
                continue;
            }
            if (isset($seen[$airportId])) {
                continue;
            }
            $seen[$airportId] = true;
            $airport = $airports[$airportId] ?? null;
            if (!is_array($airport) || !isAirportEnabled($airport)) {
                $errors[$rawId] = 'Airport not found';
                continue;
            }
            if (!$includeMaintenance && ($airport['maintenance'] ?? false) === true) {
                $errors[$rawId] = 'Airport not found';
                continue;
            }
            $candidates[$airportId] = $airport;
        }
        return $candidates;
    }

    $candidates = [];
    foreach ($airports as $airportId => $airport) {
        if (!is_string($airportId) || $airportId === '' || !is_array($airport) || !isAirportEnabled($airport)) {
            continue;
        }
        if (isAirportUnlisted($airport)) {
            continue;
        }
        if (!$includeMaintenance && ($airport['maintenance'] ?? false) === true) {
            continue;
        }
        $candidates[$airportId] = $airport;
    }

    return $candidates;
}

/**
 * Keep airports that have weather matching the source operator.
 *
 * operator matches weather sources only, never webcams, so an aviationwx webcam
 * does not pull in a field whose own weather is FAA-only.
 *
 * @param array<string, array<string, mixed>> $candidates Airport id => config
 * @param string|null $operator Weather-source operator slug, or null for all
 * @return array<string, array<string, mixed>>
 */
function filterWeatherBulkCandidates(array $candidates, ?string $operator): array
{
    return array_filter($candidates, function (array $airport) use ($operator): bool {
        if (!hasWeatherSources($airport)) {
            return false;
        }
        return $operator === null || airportHasWeatherSourceOperator($airport, $operator);
    });
}

/**
 * Build the weather payload for the candidate airports.
 *
 * Airports with no current observation produce a null value rather than being
 * dropped, since "null when missing" is the per-field convention. Missing fields
 * inside an observation are also null via formatWeatherResponse().
 *
 * @param array<string, array<string, mixed>> $candidates Airport id => config
 * @return array{array<string, array<string, mixed>|null>, int}
 */
function buildWeatherBulkPayload(array $candidates): array
{
    $weatherData = [];
    $returned = 0;

    foreach ($candidates as $airportId => $airport) {
        metrics_track_weather_request($airportId);
        $weather = getWeatherFromCache($airportId);

        if ($weather === null) {
            $weatherData[$airportId] = null;
            continue;
        }

        $weatherData[$airportId] = formatWeatherResponse($weather, $airport, $airportId);
        $returned++;
    }

    return [$weatherData, $returned];
}
