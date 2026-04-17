<?php
/**
 * Tempest WeatherFlow API Adapter v1
 *
 * Handles fetching and parsing weather data from Tempest WeatherFlow API.
 * Primary data path: federated GET /observations/station/{id}. When that response has no usable `obs`
 * row, or `obs[0]` parses but has no sensor measurements (timestamp-only skeleton), the unified fetcher
 * may follow with GET /stations/{id} and GET /observations/device/{st_device_id} for the first device_type ST.
 *
 * API documentation: https://weatherflow.github.io/Tempest/api/
 */

require_once __DIR__ . '/../../constants.php';
require_once __DIR__ . '/../../logger.php';
require_once __DIR__ . '/../../test-mocks.php';
require_once __DIR__ . '/../data/WeatherReading.php';
require_once __DIR__ . '/../data/WindGroup.php';
require_once __DIR__ . '/../data/WeatherSnapshot.php';

use AviationWX\Weather\Data\WeatherReading;
use AviationWX\Weather\Data\WindGroup;
use AviationWX\Weather\Data\WeatherSnapshot;

/**
 * Tempest Adapter Class (implements new interface pattern)
 * 
 * Provides self-describing adapter for Tempest WeatherFlow API.
 */
class TempestAdapter {
    
    /** Fields this adapter can provide */
    public const FIELDS_PROVIDED = [
        'temperature',
        'dewpoint',
        'humidity',
        'pressure',
        'wind_speed',
        'wind_direction',
        'gust_speed',
        'precip_accum',
    ];
    
    /** Typical update frequency in seconds (Tempest updates ~1/min) */
    public const UPDATE_FREQUENCY = 60;
    
    /** Max acceptable age before data is stale (5 minutes) */
    public const MAX_ACCEPTABLE_AGE = 300;
    
    /** Source type identifier */
    public const SOURCE_TYPE = 'tempest';

    /**
     * Timeout (seconds) for follow-up `/stations` and `/observations/device` HTTP calls.
     * Capped below the primary `CURL_TIMEOUT` so fallback cannot stack three full primary waits serially.
     */
    public const FALLBACK_HTTP_TIMEOUT_SECONDS = 8;
    
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
     * Build the federated station observation URL (primary Tempest fetch).
     *
     * @param array $config Must include `station_id` and `api_key`
     * @return string|null URL or null if config incomplete
     */
    public static function buildUrl(array $config): ?string {
        if (!isset($config['api_key']) || !isset($config['station_id'])) {
            return null;
        }
        return "https://swd.weatherflow.com/swd/rest/observations/station/{$config['station_id']}?token={$config['api_key']}";
    }

    /**
     * Station metadata URL (lists devices: hub HB + sensor ST).
     *
     * @param array $config Tempest source config
     * @return string|null URL or null if invalid
     */
    public static function buildStationsMetadataUrl(array $config): ?string {
        if (!isset($config['api_key']) || !isset($config['station_id'])) {
            return null;
        }
        return "https://swd.weatherflow.com/swd/rest/stations/{$config['station_id']}?token={$config['api_key']}";
    }

    /**
     * Latest device observation URL (raw obs_st layout).
     *
     * @param int|string $deviceId WeatherFlow device_id
     * @param string $apiKey API token
     * @return string URL
     */
    public static function buildDeviceObservationsUrl($deviceId, string $apiKey): string {
        return 'https://swd.weatherflow.com/swd/rest/observations/device/' . rawurlencode((string) $deviceId)
            . '?token=' . rawurlencode($apiKey);
    }
    
    /**
     * Parse API response into a WeatherSnapshot
     * 
     * Units returned:
     * - Temperature/Dewpoint: Celsius
     * - Humidity: Percent
     * - Pressure: inHg (converted from mb)
     * - Precipitation: inches (converted from mm)
     * - Wind: knots (converted from m/s)
     * 
     * @param string $response Raw API response
     * @param array $config Source configuration
     * @return WeatherSnapshot|null
     */
    public static function parseToSnapshot(string $response, array $config = []): ?WeatherSnapshot {
        $parsed = parseTempestResponse($response);
        if ($parsed === null) {
            return WeatherSnapshot::empty(self::SOURCE_TYPE);
        }
        
        $obsTime = $parsed['obs_time'] ?? time();
        $source = self::SOURCE_TYPE;
        
        return new WeatherSnapshot(
            source: $source,
            fetchTime: time(),
            temperature: WeatherReading::celsius($parsed['temperature'], $source, $obsTime),
            dewpoint: WeatherReading::celsius($parsed['dewpoint'], $source, $obsTime),
            humidity: WeatherReading::percent($parsed['humidity'], $source, $obsTime),
            pressure: WeatherReading::inHg($parsed['pressure'], $source, $obsTime),
            precipAccum: WeatherReading::inches($parsed['precip_accum'], $source, $obsTime),
            wind: ($parsed['wind_speed'] !== null && $parsed['wind_direction'] !== null)
                ? WindGroup::from(
                    $parsed['wind_speed'],
                    $parsed['wind_direction'],
                    $parsed['gust_speed'],
                    $source,
                    $obsTime
                )
                : WindGroup::empty(),
            visibility: WeatherReading::null($source), // Tempest doesn't provide visibility
            ceiling: WeatherReading::null($source),    // Tempest doesn't provide ceiling
            cloudCover: WeatherReading::null($source), // Tempest doesn't provide cloud cover
            isValid: true
        );
    }
}

/**
 * GET helper for Tempest REST; test mode consults fixtures before curl (no network in CI).
 *
 * @param string $url Full request URL
 * @param int|null $requestTimeoutSeconds CURLOPT_TIMEOUT; null uses CURL_TIMEOUT or 30s default
 * @return string|null Response body or null on failure
 */
function tempestHttpGet(string $url, ?int $requestTimeoutSeconds = null): ?string {
    $mock = getMockHttpResponse($url);
    if ($mock !== null) {
        return $mock;
    }
    $ch = curl_init($url);
    if ($ch === false) {
        return null;
    }
    $timeout = $requestTimeoutSeconds ?? (defined('CURL_TIMEOUT') ? CURL_TIMEOUT : 30);
    $connectTimeout = $requestTimeoutSeconds !== null
        ? max(3, min(10, $requestTimeoutSeconds))
        : 10;
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => $timeout,
        CURLOPT_CONNECTTIMEOUT => $connectTimeout,
        CURLOPT_USERAGENT => 'AviationWX/2.0',
        CURLOPT_FAILONERROR => false,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_MAXREDIRS => 3,
        CURLOPT_HTTPHEADER => ['Accept: application/json'],
    ]);
    $body = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if ($body === false || $code < 200 || $code >= 300) {
        return null;
    }
    return (string) $body;
}

/**
 * Map one WeatherFlow `obs_st` numeric row to federated-style keys for a single code path through conversions.
 * Index 6 is mb on the wire; it is stored in `sea_level_pressure` so the same mb→inHg path runs as for federated obs.
 *
 * @param array<int, mixed> $row Observation value array
 * @return array<string, mixed>|null
 */
function tempestObsStRowToStationObservationAssoc(array $row): ?array {
    if (!isset($row[0]) || !is_numeric($row[0])) {
        return null;
    }
    $assoc = [
        'timestamp' => (int) $row[0],
    ];
    if (isset($row[2]) && is_numeric($row[2])) {
        $assoc['wind_avg'] = (float) $row[2];
    }
    if (isset($row[3]) && is_numeric($row[3])) {
        $assoc['wind_gust'] = (float) $row[3];
    }
    if (isset($row[4]) && is_numeric($row[4])) {
        $assoc['wind_direction'] = (float) $row[4];
    }
    if (isset($row[6]) && is_numeric($row[6])) {
        $assoc['sea_level_pressure'] = (float) $row[6];
    }
    if (isset($row[7]) && is_numeric($row[7])) {
        $assoc['air_temperature'] = (float) $row[7];
    }
    if (isset($row[8]) && is_numeric($row[8])) {
        $assoc['relative_humidity'] = (float) $row[8];
    }
    if (isset($row[18]) && is_numeric($row[18])) {
        $assoc['precip_accum_local_day_final'] = (float) $row[18];
    }
    return $assoc;
}

/**
 * Parse /stations/{id} JSON and return the first `device_type` ST device_id (deterministic when several ST exist).
 *
 * @param string $json Raw JSON from stations endpoint
 * @return int|null
 */
function tempestExtractStDeviceIdFromStationsJson(string $json): ?int {
    $data = json_decode($json, true);
    if (!is_array($data)) {
        return null;
    }
    $stations = $data['stations'] ?? null;
    if (!is_array($stations) || $stations === []) {
        return null;
    }
    $devices = $stations[0]['devices'] ?? null;
    if (!is_array($devices)) {
        return null;
    }
    foreach ($devices as $device) {
        if (!is_array($device)) {
            continue;
        }
        if (($device['device_type'] ?? null) === 'ST' && isset($device['device_id']) && is_numeric($device['device_id'])) {
            return (int) $device['device_id'];
        }
    }
    return null;
}

/**
 * True when parsed output includes at least one sensor field we would show (not timestamp-only skeleton data).
 * Parser defaults missing precip to 0, so only non-zero precip counts as usable without other fields.
 *
 * @param array<string, mixed> $parsed Return value of parseTempestResponse()
 * @return bool
 */
function tempestParsedObservationHasUsableSensorFields(array $parsed): bool {
    if (($parsed['temperature'] ?? null) !== null) {
        return true;
    }
    if (($parsed['humidity'] ?? null) !== null) {
        return true;
    }
    if (($parsed['pressure'] ?? null) !== null) {
        return true;
    }
    if (($parsed['dewpoint'] ?? null) !== null) {
        return true;
    }
    if (($parsed['wind_speed'] ?? null) !== null
        || ($parsed['wind_direction'] ?? null) !== null
        || ($parsed['gust_speed'] ?? null) !== null) {
        return true;
    }
    $precip = $parsed['precip_accum'] ?? null;
    if ($precip !== null && is_numeric($precip) && (float) $precip !== 0.0) {
        return true;
    }
    return false;
}

/**
 * When federated data is missing or has no usable sensor fields, follow station metadata to the ST device endpoint.
 *
 * @param string $stationObservationBody Body from observations/station
 * @param array $source weather_sources tempest entry
 * @param string $airportId Airport id for logs (may be empty for legacy callers)
 * @return string Body to feed into parseTempestResponse (station or device JSON)
 */
function tempestApplyDeviceFallbackIfNeeded(string $stationObservationBody, array $source, string $airportId): string {
    $parsedStation = parseTempestResponse($stationObservationBody);
    if ($parsedStation !== null && tempestParsedObservationHasUsableSensorFields($parsedStation)) {
        return $stationObservationBody;
    }
    $stationId = $source['station_id'] ?? null;
    $apiKey = $source['api_key'] ?? null;
    if (!is_string($apiKey) || $apiKey === '' || $stationId === null || $stationId === '') {
        return $stationObservationBody;
    }
    $metaUrl = TempestAdapter::buildStationsMetadataUrl($source);
    if ($metaUrl === null) {
        return $stationObservationBody;
    }
    $fallbackTimeout = defined('CURL_TIMEOUT')
        ? max(5, min(TempestAdapter::FALLBACK_HTTP_TIMEOUT_SECONDS, (int) CURL_TIMEOUT))
        : TempestAdapter::FALLBACK_HTTP_TIMEOUT_SECONDS;
    $stationsBody = tempestHttpGet($metaUrl, $fallbackTimeout);
    if ($stationsBody === null) {
        return $stationObservationBody;
    }
    $deviceId = tempestExtractStDeviceIdFromStationsJson($stationsBody);
    if ($deviceId === null) {
        return $stationObservationBody;
    }
    $deviceUrl = TempestAdapter::buildDeviceObservationsUrl($deviceId, $apiKey);
    $deviceBody = tempestHttpGet($deviceUrl, $fallbackTimeout);
    if ($deviceBody === null) {
        return $stationObservationBody;
    }
    $parsedDevice = parseTempestResponse($deviceBody);
    if ($parsedDevice === null || !tempestParsedObservationHasUsableSensorFields($parsedDevice)) {
        return $stationObservationBody;
    }
    aviationwx_log('info', 'Tempest: using device observations after empty or sensor-less federated station obs', [
        'airport_id' => $airportId,
        'station_id' => (string) $stationId,
        'device_id' => $deviceId,
    ], 'app', true);
    return $deviceBody;
}

// =============================================================================
// LEGACY FUNCTIONS (kept for backward compatibility during migration)
// =============================================================================

/**
 * Parse Tempest API response (federated station or device ObservationSet).
 *
 * Accepts:
 * - Station federated JSON: `obs[0]` is an associative observation object (WeatherFlow station layout).
 * - Device JSON: top-level `type` === `obs_st` and `obs` is a list of numeric rows; the last row is used
 *   and mapped into the same associative shape before conversions run.
 *
 * Rejects malformed payloads where `obs[0]` is a list (prevents mis-reading numeric arrays as keyed fields).
 * Handles unit conversions (m/s to knots, mb to inHg, mm to inches). Observation time is Unix seconds.
 *
 * @param string|null $response JSON from observations/station, observations/device, or equivalent mock
 * @return array|null Weather data array with standard keys, or null on parse error
 */
function parseTempestResponse($response): ?array {
    if ($response === null || $response === '' || !is_string($response)) {
        return null;
    }
    $data = json_decode($response, true);
    if (!is_array($data)) {
        return null;
    }

    if (($data['type'] ?? null) === 'obs_st' && isset($data['obs']) && is_array($data['obs']) && $data['obs'] !== []) {
        $rows = $data['obs'];
        $last = $rows[count($rows) - 1];
        if (!is_array($last)) {
            return null;
        }
        $assoc = tempestObsStRowToStationObservationAssoc($last);
        if ($assoc === null) {
            return null;
        }
        $data = ['obs' => [$assoc]];
    }

    if (!isset($data['obs'][0])) {
        return null;
    }

    $obs = $data['obs'][0];
    if (!is_array($obs) || array_is_list($obs)) {
        return null;
    }

    // Timestamp is Unix seconds on both federated and device-normalized payloads.
    $obsTime = null;
    if (isset($obs['timestamp']) && is_numeric($obs['timestamp'])) {
        $obsTime = (int)$obs['timestamp'];
    }
    
    // Note: Daily stats (high/low temp, peak gust) are not available from the basic Tempest API
    // These would require a different API endpoint or subscription level
    $tempHigh = null;
    $tempLow = null;
    $peakGust = null;
    
    // Use current gust as peak gust (as it's the only gust data available)
    // This will be set later if wind_gust is numeric
    
    // Convert pressure from mb to inHg
    $pressureInHg = isset($obs['sea_level_pressure']) ? $obs['sea_level_pressure'] / 33.8639 : null;
    
    // Convert wind speed from m/s to knots
    // Add type checks to handle unexpected input types gracefully
    $windSpeedKts = null;
    if (isset($obs['wind_avg']) && is_numeric($obs['wind_avg'])) {
        $windSpeedKts = (int)round((float)$obs['wind_avg'] * 1.943844);
    }
    $gustSpeedKts = null;
    if (isset($obs['wind_gust']) && is_numeric($obs['wind_gust'])) {
        $gustSpeedKts = (int)round((float)$obs['wind_gust'] * 1.943844);
    }
    // Also update peak_gust calculation
    if ($gustSpeedKts !== null) {
        $peakGust = $gustSpeedKts;
    }
    
    return [
        'temperature' => isset($obs['air_temperature']) ? $obs['air_temperature'] : null, // Celsius
        'humidity' => isset($obs['relative_humidity']) ? $obs['relative_humidity'] : null,
        'pressure' => $pressureInHg, // sea level pressure in inHg
        'wind_speed' => $windSpeedKts,
        'wind_direction' => isset($obs['wind_direction']) && is_numeric($obs['wind_direction']) ? (int)round((float)$obs['wind_direction']) : null,
        'gust_speed' => $gustSpeedKts,
        'precip_accum' => isset($obs['precip_accum_local_day_final']) ? $obs['precip_accum_local_day_final'] * 0.0393701 : 0, // mm to inches
        'dewpoint' => isset($obs['dew_point']) ? $obs['dew_point'] : null,
        'visibility' => null, // Not available from Tempest
        'ceiling' => null, // Not available from Tempest
        'temp_high' => $tempHigh,
        'temp_low' => $tempLow,
        'peak_gust' => $peakGust,
        'obs_time' => $obsTime, // Observation time when weather was actually measured
    ];
}

/**
 * Fetch weather from Tempest API (synchronous, for fallback)
 * 
 * Makes HTTP request to Tempest WeatherFlow API to fetch current weather observations.
 * Used as fallback when async fetch fails. Returns parsed weather data or null on failure.
 * 
 * @param array $source Weather source configuration (must contain 'api_key' and 'station_id')
 * @return array|null Weather data array with standard keys, or null on failure
 */
function fetchTempestWeather($source): ?array {
    if (!is_array($source) || !isset($source['api_key']) || !isset($source['station_id'])) {
        return null;
    }
    
    $apiKey = $source['api_key'];
    $stationId = $source['station_id'];
    
    // Fetch current observation
    $url = "https://swd.weatherflow.com/swd/rest/observations/station/{$stationId}?token={$apiKey}";
    
    // Check for mock response in test mode
    $mockResponse = getMockHttpResponse($url);
    if ($mockResponse !== null) {
        $response = $mockResponse;
    } else {
        // Create context with explicit timeout
        $context = stream_context_create([
            'http' => [
                'timeout' => CURL_TIMEOUT,
                'ignore_errors' => true,
            ],
        ]);
        
        $response = @file_get_contents($url, false, $context);
        
        if ($response === false) {
            return null;
        }
    }

    $response = tempestApplyDeviceFallbackIfNeeded((string) $response, $source, '');
    return parseTempestResponse($response);
}

