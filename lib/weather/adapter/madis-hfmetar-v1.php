<?php
/**
 * MADIS HFMETAR Adapter v1
 *
 * Handles parsing MADIS HFMETAR netCDF files into WeatherSnapshots for
 * Federal ASOS stations. HFMETAR carries ASOS observations at 5-minute
 * marks (12 per hour per station), which gives airports with otherwise
 * hourly METAR feeds a much denser wind history for the compass petals
 * and a faster-moving current wind.
 *
 * Data source: https://madis-data.ncep.noaa.gov/madisPublic1/data/LDAD/hfmetar/netCDF/
 * Public anonymous access; no API key required.
 *
 * Note: buildUrl() returns the current UTC hour's bulk file, which holds
 * every station's observations for that hour. A production integration
 * should share one download across airports (same pattern as the METAR
 * bulk refresh) rather than fetching the file per airport.
 *
 * @package AviationWX\Weather\Adapter
 */

require_once __DIR__ . '/../../constants.php';
require_once __DIR__ . '/../../logger.php';
require_once __DIR__ . '/../../test-mocks.php';
require_once __DIR__ . '/../data/WeatherReading.php';
require_once __DIR__ . '/../data/WindGroup.php';
require_once __DIR__ . '/../data/WeatherSnapshot.php';
require_once __DIR__ . '/../calculator.php';
require_once __DIR__ . '/../../madis-hfmetar/madis-hfmetar.php';

use AviationWX\Weather\Data\WeatherReading;
use AviationWX\Weather\Data\WindGroup;
use AviationWX\Weather\Data\WeatherSnapshot;

/**
 * MADIS HFMETAR Adapter
 *
 * Provides the fields ASOS reports at 5-minute marks. Wind, temperature,
 * dewpoint, altimeter, and visibility come from the structured netCDF
 * columns; humidity is derived from temperature and dewpoint.
 */
class MadisHfmetarAdapter {

    /** Fields this adapter can provide */
    public const FIELDS_PROVIDED = [
        'temperature',
        'dewpoint',
        'humidity',
        'pressure',
        'wind_speed',
        'wind_direction',
        'gust_speed',
        'visibility',
        'ceiling',
        'cloud_cover',
    ];

    /** Typical update frequency in seconds (5-minute ASOS marks) */
    public const UPDATE_FREQUENCY = 300;

    /**
     * Max acceptable age before data is stale.
     *
     * Observations arrive in the public feed roughly 10-25 minutes after
     * the 5-minute mark, so the threshold is an hour to avoid failing
     * closed on data that is simply in delivery.
     */
    public const MAX_ACCEPTABLE_AGE = 3600;

    /** Source type identifier */
    public const SOURCE_TYPE = 'madis_hfmetar';

    /**
     * Get fields this adapter can provide
     */
    public static function getFieldsProvided(): array {
        return self::FIELDS_PROVIDED;
    }

    /**
     * Get typical update frequency in seconds
     */
    public static function getTypicalUpdateFrequency(): int {
        return self::UPDATE_FREQUENCY;
    }

    /**
     * Get maximum acceptable age before data is stale
     */
    public static function getMaxAcceptableAge(): int {
        return self::MAX_ACCEPTABLE_AGE;
    }

    /**
     * Get source type identifier
     */
    public static function getSourceType(): string {
        return self::SOURCE_TYPE;
    }

    /**
     * Check if this adapter provides a specific field
     */
    public static function providesField(string $fieldName): bool {
        return in_array($fieldName, self::FIELDS_PROVIDED, true);
    }

    /**
     * Build the URL for fetching data
     *
     * Returns the current UTC hour's HFMETAR bulk file. The station_id is
     * validated here but does not appear in the URL because the file holds
     * every station; the station is extracted at parse time.
     *
     * @param array $config Source configuration with station_id
     * @return string|null URL or null if config is invalid
     */
    public static function buildUrl(array $config): ?string {
        $stationId = $config['station_id'] ?? '';
        if (!is_string($stationId) || trim($stationId) === '') {
            return null;
        }
        if (!preg_match('/^[A-Z0-9]{3,5}$/i', trim($stationId))) {
            return null;
        }

        return madisHfmetarCurrentHourFileUrl();
    }

    /**
     * Parse an HFMETAR netCDF response into a WeatherSnapshot
     *
     * The response is the gzipped (or raw) netCDF file bytes. When the
     * caller has already sliced the file to disk, config['file_path'] may
     * be set instead; the file is read and parsed the same way.
     *
     * Units converted by madisHfmetarDecodeBytes():
     * - Temperature/dewpoint: celsius
     * - Pressure: inHg
     * - Wind: knots (direction true north, degrees)
     * - Visibility: statute miles
     *
     * @param string $response Raw netCDF file bytes (gzipped or not)
     * @param array $config Source configuration with station_id
     * @return WeatherSnapshot|null
     */
    public static function parseResponse(string $response, array $config = []): ?WeatherSnapshot {
        $stationId = $config['station_id'] ?? '';
        if (!is_string($stationId) || trim($stationId) === '') {
            return WeatherSnapshot::empty(self::SOURCE_TYPE);
        }
        $stationId = strtoupper(trim($stationId));

        $bytes = $response;
        $filePath = $config['file_path'] ?? null;
        if (is_string($filePath) && $filePath !== '') {
            $bytes = @file_get_contents($filePath);
            if ($bytes === false) {
                return WeatherSnapshot::empty(self::SOURCE_TYPE);
            }
        }

        // A bulk slice is JSON; a direct download is gzipped netCDF.
        if (str_starts_with(ltrim($bytes), '{')) {
            $slice = json_decode($bytes, true);
            $observations = is_array($slice) ? ($slice['observations'] ?? null) : null;
            if (!is_array($observations) || $observations === []) {
                return WeatherSnapshot::empty(self::SOURCE_TYPE);
            }
        } else {
            $observations = madisHfmetarDecodeBytes($bytes, $stationId);
            if ($observations === null || $observations === []) {
                return WeatherSnapshot::empty(self::SOURCE_TYPE);
            }
        }

        // Observations are sorted ascending; the latest mark is the snapshot.
        $obs = $observations[count($observations) - 1];

        return self::snapshotFromObservation($obs, $stationId);
    }

    /**
     * Build a snapshot from one decoded observation array.
     *
     * @param array<string, mixed> $obs Observation from the decoder or a bulk slice
     * @param string $stationId Station ICAO (uppercase)
     * @return WeatherSnapshot
     */
    private static function snapshotFromObservation(array $obs, string $stationId): WeatherSnapshot
    {
        $obsTime = (int)$obs['obs_time'];
        $source = self::SOURCE_TYPE;

        $temperature = $obs['temperature_c'];
        $dewpoint = $obs['dewpoint_c'];
        $humidity = null;
        if ($temperature !== null && $dewpoint !== null && function_exists('calculateHumidityFromDewpoint')) {
            $humidity = calculateHumidityFromDewpoint($temperature, $dewpoint);
        }

        $windSpeed = $obs['wind_speed_kts'];
        $windDirection = $obs['wind_direction_deg'];
        $gustSpeed = $obs['gust_speed_kts'];
        $hasCompleteWind = $windSpeed !== null && $windDirection !== null;

        return new WeatherSnapshot(
            source: $source,
            fetchTime: time(),
            temperature: WeatherReading::celsius($temperature, $source, $obsTime),
            dewpoint: WeatherReading::celsius($dewpoint, $source, $obsTime),
            humidity: WeatherReading::percent($humidity, $source, $obsTime),
            pressure: WeatherReading::inHg($obs['altimeter_inhg'], $source, $obsTime),
            precipAccum: WeatherReading::inches(null, $source, $obsTime),
            wind: $hasCompleteWind
                ? WindGroup::from($windSpeed, $windDirection, $gustSpeed, $source, $obsTime)
                : WindGroup::empty(),
            visibility: WeatherReading::statuteMiles($obs['visibility_sm'], $source, $obsTime),
            ceiling: WeatherReading::feet($obs['ceiling_ft'], $source, $obsTime),
            cloudCover: WeatherReading::text($obs['cloud_cover'], $source, $obsTime),
            rawMetar: null,
            isValid: true,
            metarStationId: null,
            stationId: $stationId,
            metarFieldCompleteness: null
        );
    }
}

// =============================================================================
// STATION RESPONSE RESOLUTION (mock/bulk/HTTP, mirroring the METAR adapter)
// =============================================================================

/** MADIS resolve produced a body (mock, bulk slice, or HTTP). */
const MADIS_HFMETAR_RESOLVE_OK = 'ok';
/** HTTP budget exhausted for this cycle; not an upstream failure. */
const MADIS_HFMETAR_RESOLVE_THROTTLED = 'throttled';
/** Per-airport MADIS circuit open; HTTP skipped (bulk/mock already attempted). */
const MADIS_HFMETAR_RESOLVE_CIRCUIT_OPEN = 'circuit_open';
/** Per-station HTTP attempt failed after bulk miss. */
const MADIS_HFMETAR_RESOLVE_HTTP_FAILED = 'http_failed';
/** Missing or empty station_id. */
const MADIS_HFMETAR_RESOLVE_INVALID_STATION = 'invalid_station';

/**
 * Resolve the MADIS response body for one station.
 *
 * Precedence: test mock, fresh bulk slice, then HTTP (gated by circuit
 * breaker and throttle). Throttle and circuit-open outcomes are not
 * upstream failures; callers must not record them as fetch failures.
 *
 * @param string $stationId ICAO station id (e.g. KSPB)
 * @param string|null $airportId Airport slug for per-airport MADIS circuit (null skips HTTP gate)
 * @return array{body: ?string, outcome: string, http_code?: int|null, response_headers?: array<string, string>}
 */
function madisHfmetarResolveStationResponse(string $stationId, ?string $airportId = null): array
{
    $stationId = strtoupper(trim($stationId));
    if ($stationId === '') {
        return ['body' => null, 'outcome' => MADIS_HFMETAR_RESOLVE_INVALID_STATION];
    }

    $stationSource = ['type' => 'madis_hfmetar', 'station_id' => $stationId];
    $url = MadisHfmetarAdapter::buildUrl($stationSource);
    if ($url === null) {
        return ['body' => null, 'outcome' => MADIS_HFMETAR_RESOLVE_INVALID_STATION];
    }

    $mockResponse = getMockHttpResponse($url);
    if ($mockResponse !== null) {
        return ['body' => $mockResponse, 'outcome' => MADIS_HFMETAR_RESOLVE_OK];
    }

    require_once __DIR__ . '/../../madis-hfmetar/bulk.php';
    $sliceJson = madisHfmetarTryReadStationSlice($stationId);
    if ($sliceJson !== null) {
        return ['body' => $sliceJson, 'outcome' => MADIS_HFMETAR_RESOLVE_OK];
    }

    if ($airportId !== null && $airportId !== '') {
        require_once __DIR__ . '/../../circuit-breaker.php';
        $breakerResult = checkWeatherCircuitBreaker($airportId, 'madis_hfmetar', $stationSource);
        if (is_array($breakerResult) && ($breakerResult['skip'] ?? false)) {
            return ['body' => null, 'outcome' => MADIS_HFMETAR_RESOLVE_CIRCUIT_OPEN];
        }
    }

    require_once __DIR__ . '/../../upstream-rate-limit.php';
    if (!upstreamRateLimitConsumeForSource($stationSource)['allowed']) {
        return ['body' => null, 'outcome' => MADIS_HFMETAR_RESOLVE_THROTTLED];
    }

    $context = stream_context_create([
        'http' => [
            'timeout' => CURL_TIMEOUT,
            'ignore_errors' => true,
        ],
    ]);
    $response = @file_get_contents($url, false, $context);
    $rawHeaders = $http_response_header ?? null;
    $httpCode = null;
    if (is_array($rawHeaders)) {
        foreach ($rawHeaders as $headerLine) {
            if (preg_match('/^HTTP\/\S+\s+(\d{3})/', $headerLine, $matches)) {
                $httpCode = (int) $matches[1];
                break;
            }
        }
    }

    if ($response === false || ($httpCode !== null && $httpCode >= 400)) {
        return [
            'body' => null,
            'outcome' => MADIS_HFMETAR_RESOLVE_HTTP_FAILED,
            'http_code' => $httpCode,
        ];
    }

    return [
        'body' => $response,
        'outcome' => MADIS_HFMETAR_RESOLVE_OK,
        'http_code' => $httpCode,
    ];
}
