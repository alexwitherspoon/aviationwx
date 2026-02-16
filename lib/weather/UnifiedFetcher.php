<?php
/**
 * Unified Weather Fetcher
 * 
 * Clean, simple weather data fetching that:
 * 1. Fetches from all configured sources in the `weather_sources` array in parallel
 * 2. Parses responses into WeatherSnapshots
 * 3. Aggregates using the WeatherAggregator (freshest data wins)
 * 4. Returns the aggregated result
 * 
 * Sources with `backup: true` are only used when other sources fail or are stale.
 * 
 * No side effects. No cache reading. Pure data fetching and aggregation.
 * 
 * @package AviationWX\Weather
 */

require_once __DIR__ . '/data/WeatherReading.php';
require_once __DIR__ . '/data/WindGroup.php';
require_once __DIR__ . '/data/WeatherSnapshot.php';
require_once __DIR__ . '/AggregationPolicy.php';
require_once __DIR__ . '/WeatherAggregator.php';
require_once __DIR__ . '/adapter/tempest-v1.php';
require_once __DIR__ . '/adapter/ambient-v1.php';
require_once __DIR__ . '/adapter/synopticdata-v1.php';
require_once __DIR__ . '/adapter/weatherlink-v2-api.php';
require_once __DIR__ . '/adapter/weatherlink-v1-api.php';
require_once __DIR__ . '/adapter/pwsweather-v1.php';
require_once __DIR__ . '/adapter/metar-v1.php';
require_once __DIR__ . '/adapter/nws-api-v1.php';
require_once __DIR__ . '/adapter/aviationwx-api-v1.php';
require_once __DIR__ . '/adapter/awosnet-v1.php';
require_once __DIR__ . '/adapter/swob-helper.php';
require_once __DIR__ . '/adapter/swob-auto-v1.php';
require_once __DIR__ . '/adapter/swob-man-v1.php';
require_once __DIR__ . '/calculator.php';
require_once __DIR__ . '/validation.php';
require_once __DIR__ . '/../constants.php';
require_once __DIR__ . '/../circuit-breaker.php';
require_once __DIR__ . '/../logger.php';
require_once __DIR__ . '/../weather-health.php';

use AviationWX\Weather\Data\WeatherSnapshot;
use AviationWX\Weather\WeatherAggregator;
use AviationWX\Weather\Adapter\AviationWXAPIAdapter;

/**
 * Fetch weather from all configured sources and aggregate
 * 
 * @param array $airport Airport configuration
 * @param string $airportId Airport identifier
 * @return array Aggregated weather data
 */
function fetchWeatherUnified(array $airport, string $airportId): array {
    $fetchStart = microtime(true);
    
    // Build list of sources to fetch
    $sources = buildSourceList($airport);
    if (empty($sources)) {
        return emptyWeatherResult($airportId);
    }
    
    // Fetch all sources in parallel using curl_multi
    $responses = fetchAllSources($sources, $airportId);
    
    // Parse responses into snapshots
    $snapshots = [];
    
    foreach ($sources as $sourceKey => $source) {
        if (!isset($responses[$sourceKey]) || $responses[$sourceKey] === null) {
            continue;
        }
        
        $snapshot = parseSourceResponse($source, $responses[$sourceKey], $airport);
        if ($snapshot !== null && $snapshot->isValid) {
            $snapshots[] = $snapshot;
        }
    }
    
    // Aggregate using the new aggregator
    // Aggregator now uses failclosed threshold (3 hours) for all sources
    // Staleness indicators (warning/error) are applied later during display
    $aggregator = new WeatherAggregator();
    $result = $aggregator->aggregate($snapshots);
    
    // Validate and fix pressure if it's clearly in wrong units
    // Normal pressure range is 28-32 inHg. Values > 100 indicate unit conversion issues.
    // Common issues:
    // - Value in hundredths of inHg (e.g., 3038.93 = 30.3893 inHg) - divide by 100
    // - Value from Pa->inHg conversion when API returned Pa instead of hPa - divide by 100
    if (isset($result['pressure']) && is_numeric($result['pressure'])) {
        $pressure = (float)$result['pressure'];
        if ($pressure > 100) {
            // Pressure is ~100x too high, likely in wrong units - divide by 100
            $result['pressure'] = $pressure / 100.0;
        }
    }
    
    // Validate all weather fields against climate bounds
    // This catches unit conversion errors, API format changes, and sensor malfunctions
    $result = validateWeatherData($result, $airportId);
    
    // Add calculated fields
    $result = addCalculatedFields($result, $airport);
    
    
    // Add metadata
    $result['airport_id'] = $airportId;
    $result['_fetch_duration_ms'] = round((microtime(true) - $fetchStart) * 1000);
    $result['_sources_attempted'] = count($sources);
    $result['_sources_succeeded'] = count($snapshots);
    
    return $result;
}

/**
 * Build list of sources to fetch
 * 
 * All sources are configured in a unified `weather_sources` array.
 * Sources with `backup: true` are only used when other sources fail or are stale.
 * 
 * @param array $airport Airport configuration
 * @return array Source configurations keyed by identifier
 */
function buildSourceList(array $airport): array {
    $sources = [];
    
    if (isset($airport['weather_sources']) && is_array($airport['weather_sources'])) {
        foreach ($airport['weather_sources'] as $index => $source) {
            if (!empty($source['type'])) {
                // Use backup_ prefix for backup sources, source_ for regular sources
                $isBackup = !empty($source['backup']);
                $key = $isBackup ? "backup_{$index}" : "source_{$index}";
                $sources[$key] = $source;
            }
        }
    }
    
    return $sources;
}

/**
 * Fetch from all sources in parallel
 * 
 * @param array $sources Source configurations
 * @param string $airportId Airport ID for logging
 * @return array Responses keyed by source identifier
 */
function fetchAllSources(array $sources, string $airportId): array {
    $mh = curl_multi_init();
    $handles = [];
    
    foreach ($sources as $sourceKey => $source) {
        // Check circuit breaker
        $sourceType = $source['type'];
        $breakerResult = checkWeatherCircuitBreaker($airportId, $sourceType);
        if (is_array($breakerResult) && ($breakerResult['skip'] ?? false)) {
            // Track circuit breaker skip for health metrics
            weather_health_track_circuit_open($airportId, $sourceType);
            continue;
        }
        
        // Build URL
        $url = buildSourceUrl($source);
        if ($url === null) {
            continue;
        }
        
        // Get headers for this source
        $headers = buildSourceHeaders($source);
        
        // Create curl handle
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => defined('CURL_TIMEOUT') ? CURL_TIMEOUT : 30,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_USERAGENT => 'AviationWX/2.0',
            CURLOPT_FAILONERROR => false,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS => 3,
            CURLOPT_HTTPHEADER => $headers,
        ]);
        
        curl_multi_add_handle($mh, $ch);
        $handles[$sourceKey] = $ch;
    }
    
    // Execute all requests in parallel
    $running = null;
    $startTime = microtime(true);
    $maxTime = defined('CURL_TIMEOUT') ? CURL_TIMEOUT + 5 : 35;
    
    do {
        $status = curl_multi_exec($mh, $running);
        if ($running > 0) {
            curl_multi_select($mh, 0.1);
        }
        
        // Prevent infinite loop
        if ((microtime(true) - $startTime) > $maxTime) {
            break;
        }
    } while ($running > 0);
    
    // Collect responses
    $responses = [];
    foreach ($handles as $sourceKey => $ch) {
        $response = curl_multi_getcontent($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $sourceType = $sources[$sourceKey]['type'];
        
        // Circuit breaker should only open on API/auth failures (HTTP errors, network issues)
        // Valid responses (2xx) should always pass through, even if data is null/empty
        // The aggregator and staleness system will handle missing/old weather data
        if ($httpCode >= 200 && $httpCode < 300) {
            // Success: API returned valid response (even if weather data is null/empty)
            $responses[$sourceKey] = $response; // Pass through even if empty - aggregator will handle it
            recordWeatherSuccess($airportId, $sourceType);
            weather_health_track_fetch($airportId, $sourceType, true, $httpCode);
        } else {
            // Failure: HTTP error or network issue - trigger circuit breaker
            $responses[$sourceKey] = null;
            recordWeatherFailure($airportId, $sourceType, 'transient', $httpCode);
            weather_health_track_fetch($airportId, $sourceType, false, $httpCode);
        }
        
        curl_multi_remove_handle($mh, $ch);
    }
    
    curl_multi_close($mh);
    
    return $responses;
}

/**
 * Build URL for a source
 * 
 * @param array $source Source configuration
 * @return string|null URL or null if invalid
 */
function buildSourceUrl(array $source): ?string {
    $type = $source['type'] ?? null;
    
    return match($type) {
        'tempest' => TempestAdapter::buildUrl($source),
        'ambient' => AmbientAdapter::buildUrl($source),
        'synopticdata' => SynopticDataAdapter::buildUrl($source),
        'weatherlink_v2' => WeatherLinkV2Adapter::buildUrl($source),
        'weatherlink_v1' => WeatherLinkV1Adapter::buildUrl($source),
        'pwsweather' => PWSWeatherAdapter::buildUrl($source),
        'metar' => MetarAdapter::buildUrl($source),
        'nws' => NwsApiAdapter::buildUrl($source),
        'aviationwx_api' => AviationWXAPIAdapter::buildUrl($source),
        'awosnet' => AwosnetAdapter::buildUrl($source),
        'swob_auto' => SwobAutoAdapter::buildUrl($source),
        'swob_man' => SwobManAdapter::buildUrl($source),
        default => null,
    };
}

/**
 * Build HTTP headers for a source
 * 
 * @param array $source Source configuration
 * @return array HTTP headers array
 */
function buildSourceHeaders(array $source): array {
    $type = $source['type'] ?? null;
    
    return match($type) {
        'weatherlink_v2' => WeatherLinkV2Adapter::getHeaders($source),
        'weatherlink_v1' => WeatherLinkV1Adapter::getHeaders($source),
        'nws' => NwsApiAdapter::getHeaders(),
        'awosnet' => AwosnetAdapter::getHeaders($source),
        default => ['Accept: application/json'],
    };
}

/**
 * Parse response into a WeatherSnapshot
 * 
 * @param array $source Source configuration
 * @param string $response Raw API response
 * @param array $airport Airport configuration
 * @return WeatherSnapshot|null
 */
function parseSourceResponse(array $source, string $response, array $airport): ?WeatherSnapshot {
    $type = $source['type'] ?? null;
    
    return match($type) {
        'tempest' => TempestAdapter::parseToSnapshot($response, $source),
        'ambient' => AmbientAdapter::parseToSnapshot($response, $source),
        'synopticdata' => SynopticDataAdapter::parseToSnapshot($response, $source),
        'weatherlink_v2' => WeatherLinkV2Adapter::parseToSnapshot($response, $source),
        'weatherlink_v1' => WeatherLinkV1Adapter::parseToSnapshot($response, $source),
        'pwsweather' => PWSWeatherAdapter::parseToSnapshot($response, $source),
        'metar' => MetarAdapter::parseToSnapshot($response, $airport),
        'nws' => NwsApiAdapter::parseToSnapshot($response, $source),
        'aviationwx_api' => AviationWXAPIAdapter::parseResponse($response, $source),
        'awosnet' => AwosnetAdapter::parseToSnapshot($response, $source),
        'swob_auto' => SwobAutoAdapter::parseToSnapshot($response, $source),
        'swob_man' => SwobManAdapter::parseToSnapshot($response, $source),
        default => null,
    };
}

/**
 * Add calculated fields to weather data
 * 
 * Uses existing calculator functions that expect $weather and $airport arrays.
 * Handles all derived calculations from raw weather data.
 * 
 * @param array $data Weather data
 * @param array $airport Airport configuration
 * @return array Weather data with calculated fields
 */
function addCalculatedFields(array $data, array $airport): array {
    // Calculate dewpoint from humidity if missing
    if (($data['dewpoint'] ?? null) === null && 
        ($data['temperature'] ?? null) !== null && 
        ($data['humidity'] ?? null) !== null) {
        $data['dewpoint'] = calculateDewpointFromHumidity($data['temperature'], $data['humidity']);
    }
    
    // Calculate humidity from dewpoint if missing
    if (($data['humidity'] ?? null) === null && 
        ($data['temperature'] ?? null) !== null && 
        ($data['dewpoint'] ?? null) !== null) {
        $data['humidity'] = calculateHumidityFromDewpoint($data['temperature'], $data['dewpoint']);
    }
    
    // Temperature conversions (Celsius to Fahrenheit)
    if (($data['temperature'] ?? null) !== null) {
        $data['temperature_f'] = round($data['temperature'] * 9 / 5 + 32, 1);
    } else {
        $data['temperature_f'] = null;
    }
    
    if (($data['dewpoint'] ?? null) !== null) {
        $data['dewpoint_f'] = round($data['dewpoint'] * 9 / 5 + 32, 1);
    } else {
        $data['dewpoint_f'] = null;
    }
    
    // Dewpoint spread
    if (($data['temperature'] ?? null) !== null && ($data['dewpoint'] ?? null) !== null) {
        $data['dewpoint_spread'] = round($data['temperature'] - $data['dewpoint'], 1);
    } else {
        $data['dewpoint_spread'] = null;
    }
    
    // Flight category - use existing calculator
    $data['flight_category'] = calculateFlightCategory($data);
    $data['flight_category_class'] = getFlightCategoryClass($data['flight_category']);
    
    // Pressure/density altitude - use existing calculators
    $data['pressure_altitude'] = calculatePressureAltitude($data, $airport);
    $data['density_altitude'] = calculateDensityAltitude($data, $airport);
    
    // Sunrise/sunset times
    $data['sunrise'] = getSunriseTime($airport);
    $data['sunset'] = getSunsetTime($airport);
    
    // ISO timestamp
    $lastUpdated = $data['last_updated'] ?? time();
    $data['last_updated_iso'] = date('c', $lastUpdated);
    
    return $data;
}

/**
 * Get CSS class for flight category
 * 
 * @param string|null $category Flight category (VFR, MVFR, IFR, LIFR)
 * @return string CSS class name
 */
function getFlightCategoryClass(?string $category): string {
    if ($category === null) {
        return '';
    }
    return 'status-' . strtolower($category);
}

/**
 * Generate empty weather result
 * 
 * @param string $airportId Airport identifier
 * @return array Empty weather data structure
 */
function emptyWeatherResult(string $airportId): array {
    return [
        'airport_id' => $airportId,
        'temperature' => null,
        'temperature_f' => null,
        'dewpoint' => null,
        'dewpoint_f' => null,
        'dewpoint_spread' => null,
        'humidity' => null,
        'pressure' => null,
        'pressure_altitude' => null,
        'density_altitude' => null,
        'wind_speed' => null,
        'wind_direction' => null,
        'gust_speed' => null,
        'gust_factor' => null,
        'visibility' => null,
        'visibility_greater_than' => false,
        'ceiling' => null,
        'cloud_cover' => null,
        'flight_category' => null,
        'precip_accum' => null,
        'last_updated' => time(),
        'last_updated_iso' => date('c'),
        '_field_obs_time_map' => [],
        '_field_source_map' => [],
        '_sources_attempted' => 0,
        '_sources_succeeded' => 0,
    ];
}

