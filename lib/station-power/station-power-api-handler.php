<?php
/**
 * Business logic for GET /api/station-power.php (no I/O except cache read; no rate limiting).
 */

declare(strict_types=1);

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../constants.php';
require_once __DIR__ . '/station-power-cache.php';

/**
 * Build HTTP status and JSON body for the station power read API.
 *
 * @param string $rawIdentifier Raw `airport` query value (ICAO, FAA, or airport id)
 * @return array{0: int, 1: array<string, mixed>} Tuple of [http_status, json_body]
 */
function stationPowerApiBuildResponse(string $rawIdentifier): array
{
    $rawIdentifier = trim($rawIdentifier);
    if ($rawIdentifier === '') {
        return [400, ['success' => false, 'error' => 'Missing airport parameter']];
    }

    $result = findAirportByIdentifier($rawIdentifier);
    if ($result === null || !isset($result['airport'], $result['airportId'])) {
        return [HTTP_STATUS_NOT_FOUND, ['success' => false, 'error' => 'Airport not found']];
    }

    $airport = $result['airport'];
    $airportId = $result['airportId'];

    if (!is_array($airport) || !isAirportEnabled($airport)) {
        return [HTTP_STATUS_NOT_FOUND, ['success' => false, 'error' => 'Airport not found']];
    }

    if (!shouldFetchStationPowerForAirport($airport)) {
        return [HTTP_STATUS_NOT_FOUND, ['success' => false, 'error' => 'Airport not found']];
    }

    $pollSeconds = getStationPowerRefreshSeconds($airport);
    $cache = loadStationPowerCache($airportId);
    $displayable = is_array($cache) && stationPowerCacheIsDisplayable($cache);

    $payload = [
        'success' => true,
        'poll_interval_seconds' => $pollSeconds,
        'displayable' => $displayable,
        'station_power' => $displayable ? $cache : null,
    ];

    aviationwx_log('info', 'station_power api served', [
        'airport_id' => $airportId,
        'displayable' => $displayable,
    ], 'user');

    return [HTTP_STATUS_OK, $payload];
}

/**
 * Encode API payload as JSON; returns null if encoding fails (caller must handle).
 *
 * @param array<string, mixed> $payload Response body
 * @return string|null UTF-8 JSON or null on failure
 */
function stationPowerApiEncodeJson(array $payload): ?string
{
    $json = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

    return $json === false ? null : $json;
}
