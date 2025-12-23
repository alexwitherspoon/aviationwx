<?php
/**
 * Unified Weather Fetcher
 * 
 * Clean, simple weather data fetching that:
 * 1. Fetches from all configured sources (primary + sources + METAR) in parallel
 * 2. Parses responses into WeatherSnapshots
 * 3. Aggregates using the WeatherAggregator
 * 4. Returns the aggregated result
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
require_once __DIR__ . '/adapter/weatherlink-v1.php';
require_once __DIR__ . '/adapter/pwsweather-v1.php';
require_once __DIR__ . '/adapter/metar-v1.php';
require_once __DIR__ . '/calculator.php';
require_once __DIR__ . '/../constants.php';
require_once __DIR__ . '/../circuit-breaker.php';

use AviationWX\Weather\Data\WeatherSnapshot;
use AviationWX\Weather\WeatherAggregator;

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
    $maxAges = [];
    
    foreach ($sources as $sourceKey => $source) {
        if (!isset($responses[$sourceKey]) || $responses[$sourceKey] === null) {
            continue;
        }
        
        $snapshot = parseSourceResponse($source, $responses[$sourceKey], $airport);
        if ($snapshot !== null && $snapshot->isValid) {
            $snapshots[] = $snapshot;
            $maxAges[$snapshot->source] = getSourceMaxAge($source);
        }
    }
    
    // Aggregate using the new aggregator
    $aggregator = new WeatherAggregator();
    $result = $aggregator->aggregate($snapshots, $maxAges);
    
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
 * Returns sources in priority order:
 * 1. Primary weather source (weather_source)
 * 2. Additional sources (sources array, if configured)
 * 3. METAR (if metar_station configured)
 * 
 * @param array $airport Airport configuration
 * @return array Source configurations keyed by identifier
 */
function buildSourceList(array $airport): array {
    $sources = [];
    
    // Primary source
    if (isset($airport['weather_source']) && !empty($airport['weather_source']['type'])) {
        $sources['primary'] = $airport['weather_source'];
    }
    
    // Additional sources (new unified config)
    if (isset($airport['sources']) && is_array($airport['sources'])) {
        foreach ($airport['sources'] as $index => $source) {
            if (!empty($source['type'])) {
                $sources["source_{$index}"] = $source;
            }
        }
    }
    
    // Legacy backup source
    if (isset($airport['weather_source_backup']) && !empty($airport['weather_source_backup']['type'])) {
        $sources['backup'] = $airport['weather_source_backup'];
    }
    
    // METAR
    if (isset($airport['metar_station']) && !empty($airport['metar_station'])) {
        $sources['metar'] = [
            'type' => 'metar',
            'station_id' => $airport['metar_station'],
        ];
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
            continue;
        }
        
        // Build URL
        $url = buildSourceUrl($source);
        if ($url === null) {
            continue;
        }
        
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
        
        if ($httpCode >= 200 && $httpCode < 300 && !empty($response)) {
            $responses[$sourceKey] = $response;
            recordWeatherSuccess($airportId, $sourceType);
        } else {
            $responses[$sourceKey] = null;
            recordWeatherFailure($airportId, $sourceType);
        }
        
        curl_multi_remove_handle($mh, $ch);
        curl_close($ch);
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
        'weatherlink' => buildWeatherLinkUrl($source),
        'pwsweather' => buildPWSWeatherUrl($source),
        'metar' => MetarAdapter::buildUrl($source['station_id'] ?? ''),
        default => null,
    };
}

/**
 * Build WeatherLink URL
 */
function buildWeatherLinkUrl(array $source): ?string {
    if (!isset($source['api_key']) || !isset($source['api_secret']) || !isset($source['station_id'])) {
        return null;
    }
    $timestamp = time();
    $signature = hash_hmac('sha256', $source['api_key'] . $timestamp, $source['api_secret']);
    return "https://api.weatherlink.com/v2/current/{$source['station_id']}?api-key={$source['api_key']}&t={$timestamp}&api-signature={$signature}";
}

/**
 * Build PWSWeather URL
 */
function buildPWSWeatherUrl(array $source): ?string {
    if (!isset($source['client_id']) || !isset($source['client_secret']) || !isset($source['station_id'])) {
        return null;
    }
    return "https://api.aerisapi.com/observations/{$source['station_id']}?client_id={$source['client_id']}&client_secret={$source['client_secret']}";
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
        'weatherlink' => WeatherLinkAdapter::parseToSnapshot($response, $source),
        'pwsweather' => PWSWeatherAdapter::parseToSnapshot($response, $source),
        'metar' => MetarAdapter::parseToSnapshot($response, $airport),
        default => null,
    };
}

/**
 * Get max age for a source
 * 
 * @param array $source Source configuration
 * @return int Max age in seconds
 */
function getSourceMaxAge(array $source): int {
    $type = $source['type'] ?? 'unknown';
    
    return match($type) {
        'tempest' => TempestAdapter::getMaxAcceptableAge(),
        'ambient' => AmbientAdapter::getMaxAcceptableAge(),
        'synopticdata' => SynopticDataAdapter::getMaxAcceptableAge(),
        'weatherlink' => WeatherLinkAdapter::getMaxAcceptableAge(),
        'pwsweather' => PWSWeatherAdapter::getMaxAcceptableAge(),
        'metar' => MetarAdapter::getMaxAcceptableAge(),
        default => 600, // 10 minutes default
    };
}

/**
 * Add calculated fields to weather data
 * 
 * Uses existing calculator functions that expect $weather and $airport arrays.
 * 
 * @param array $data Weather data
 * @param array $airport Airport configuration
 * @return array Weather data with calculated fields
 */
function addCalculatedFields(array $data, array $airport): array {
    // Temperature conversions (Celsius to Fahrenheit)
    if ($data['temperature'] !== null) {
        $data['temperature_f'] = round($data['temperature'] * 9 / 5 + 32, 1);
    } else {
        $data['temperature_f'] = null;
    }
    
    if ($data['dewpoint'] !== null) {
        $data['dewpoint_f'] = round($data['dewpoint'] * 9 / 5 + 32, 1);
    } else {
        $data['dewpoint_f'] = null;
    }
    
    // Dewpoint spread
    if ($data['temperature'] !== null && $data['dewpoint'] !== null) {
        $data['dewpoint_spread'] = round($data['temperature'] - $data['dewpoint'], 1);
    } else {
        $data['dewpoint_spread'] = null;
    }
    
    // Flight category - use existing calculator
    $data['flight_category'] = calculateFlightCategory($data);
    
    // Pressure/density altitude - use existing calculators
    $data['pressure_altitude'] = calculatePressureAltitude($data, $airport);
    $data['density_altitude'] = calculateDensityAltitude($data, $airport);
    
    return $data;
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

