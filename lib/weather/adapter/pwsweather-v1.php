<?php
/**
 * PWSWeather.com API Adapter v1 (via AerisWeather/XWeather API)
 * 
 * Handles fetching and parsing weather data from PWSWeather.com stations.
 * API documentation: https://www.xweather.com/docs/weather-api/endpoints/observations
 * 
 * SETUP: Obtaining XWeather API Credentials
 * -----------------------------------------
 * 1. Create account at https://www.xweather.com/ (free tier available)
 * 2. Go to Apps > New Application to generate client_id and client_secret
 * 3. Find your PWS station ID at https://dashboard.pwsweather.com/
 * 4. Configure in airports.json:
 *    "weather_sources": [{
 *      "type": "pwsweather",
 *      "station_id": "YOURSTATIONID",    // From PWS dashboard (no PWS_ prefix needed)
 *      "client_id": "your_client_id",     // From XWeather app
 *      "client_secret": "your_secret"     // From XWeather app
 *    }]
 * 
 * Note: The adapter auto-prepends "PWS_" to station_id for the API request.
 */

require_once __DIR__ . '/../../constants.php';
require_once __DIR__ . '/../../test-mocks.php';
require_once __DIR__ . '/../data/WeatherReading.php';
require_once __DIR__ . '/../data/WindGroup.php';
require_once __DIR__ . '/../data/WeatherSnapshot.php';

use AviationWX\Weather\Data\WeatherReading;
use AviationWX\Weather\Data\WindGroup;
use AviationWX\Weather\Data\WeatherSnapshot;

/**
 * PWSWeather Adapter Class
 * 
 * Provides self-describing adapter for PWSWeather stations via AerisWeather API.
 */
class PWSWeatherAdapter {
    
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
        'visibility',
    ];
    
    /** Typical update frequency in seconds */
    public const UPDATE_FREQUENCY = 300;
    
    /** Max acceptable age before data is stale (15 minutes) */
    public const MAX_ACCEPTABLE_AGE = 900;
    
    /** Source type identifier */
    public const SOURCE_TYPE = 'pwsweather';
    
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
     * Build the API URL for fetching data
     * 
     * @param array $config Source configuration (station_id, client_id, client_secret)
     * @return string|null API URL or null if config is invalid
     */
    public static function buildUrl(array $config): ?string {
        if (!isset($config['station_id']) || !isset($config['client_id']) || !isset($config['client_secret'])) {
            return null;
        }
        
        $stationId = $config['station_id'];
        if (strpos($stationId, 'PWS_') !== 0) {
            $stationId = 'PWS_' . $stationId;
        }
        
        return "https://api.aerisapi.com/observations/{$stationId}?client_id=" 
            . urlencode($config['client_id']) 
            . "&client_secret=" . urlencode($config['client_secret']);
    }
    
    /**
     * Parse API response into a WeatherSnapshot
     * 
     * Units returned:
     * - Temperature/Dewpoint: Celsius
     * - Humidity: Percent
     * - Pressure: inHg
     * - Precipitation: inches
     * - Wind: knots
     * - Visibility: statute miles
     */
    public static function parseToSnapshot(string $response, array $config = []): ?WeatherSnapshot {
        $parsed = parsePWSWeatherResponse($response);
        if ($parsed === null) {
            return WeatherSnapshot::empty(self::SOURCE_TYPE);
        }
        
        $obsTime = $parsed['obs_time'] ?? time();
        $source = self::SOURCE_TYPE;
        
        $hasCompleteWind = $parsed['wind_speed'] !== null && $parsed['wind_direction'] !== null;
        
        return new WeatherSnapshot(
            source: $source,
            fetchTime: time(),
            temperature: WeatherReading::celsius($parsed['temperature'], $source, $obsTime),
            dewpoint: WeatherReading::celsius($parsed['dewpoint'], $source, $obsTime),
            humidity: WeatherReading::percent($parsed['humidity'], $source, $obsTime),
            pressure: WeatherReading::inHg($parsed['pressure'], $source, $obsTime),
            precipAccum: WeatherReading::inches($parsed['precip_accum'], $source, $obsTime),
            wind: $hasCompleteWind
                ? WindGroup::from(
                    $parsed['wind_speed'],
                    $parsed['wind_direction'],
                    $parsed['gust_speed'],
                    $source,
                    $obsTime
                )
                : WindGroup::empty(),
            visibility: WeatherReading::statuteMiles($parsed['visibility'], $source, $obsTime),
            ceiling: WeatherReading::null($source),
            cloudCover: WeatherReading::null($source),
            isValid: true
        );
    }
}

/**
 * Parse AerisWeather API response
 * 
 * API returns: { "success": true, "response": { "id": "...", "ob": { ... } } }
 * 
 * @param string|null $response JSON response from AerisWeather API
 * @return array|null Weather data array or null on parse error
 */
function parsePWSWeatherResponse(?string $response): ?array {
    if ($response === null || $response === '') {
        return null;
    }
    
    $data = json_decode($response, true);
    if ($data === null || !is_array($data)) {
        return null;
    }
    
    if (isset($data['success']) && $data['success'] === false) {
        return null;
    }
    
    if (!isset($data['response']) || !is_array($data['response'])) {
        return null;
    }
    
    $responseData = $data['response'];
    
    // API returns observation data directly in response.ob
    if (!isset($responseData['ob']) || !is_array($responseData['ob'])) {
        return null;
    }
    
    $obs = $responseData['ob'];
    
    if (!isset($obs['timestamp']) || !is_numeric($obs['timestamp'])) {
        return null;
    }
    
    $windSpeedKts = null;
    if (isset($obs['windSpeedKTS']) && is_numeric($obs['windSpeedKTS'])) {
        $windSpeedKts = (int)round((float)$obs['windSpeedKTS']);
    } elseif (isset($obs['windKTS']) && is_numeric($obs['windKTS'])) {
        $windSpeedKts = (int)round((float)$obs['windKTS']);
    }
    
    $gustSpeedKts = null;
    if (isset($obs['windGustKTS']) && is_numeric($obs['windGustKTS'])) {
        $gustSpeedKts = (int)round((float)$obs['windGustKTS']);
    }
    
    return [
        'temperature' => isset($obs['tempC']) && is_numeric($obs['tempC']) ? (float)$obs['tempC'] : null,
        'humidity' => isset($obs['humidity']) && is_numeric($obs['humidity']) ? (float)$obs['humidity'] : null,
        'pressure' => isset($obs['pressureIN']) && is_numeric($obs['pressureIN']) ? (float)$obs['pressureIN'] : null,
        'wind_speed' => $windSpeedKts,
        'wind_direction' => isset($obs['windDirDEG']) && is_numeric($obs['windDirDEG']) ? (int)round((float)$obs['windDirDEG']) : null,
        'gust_speed' => $gustSpeedKts,
        'precip_accum' => isset($obs['precipIN']) && is_numeric($obs['precipIN']) ? (float)$obs['precipIN'] : 0,
        'dewpoint' => isset($obs['dewpointC']) && is_numeric($obs['dewpointC']) ? (float)$obs['dewpointC'] : null,
        'visibility' => isset($obs['visibilityMI']) && is_numeric($obs['visibilityMI']) ? (float)$obs['visibilityMI'] : null,
        'ceiling' => null,
        'temp_high' => null,
        'temp_low' => null,
        'peak_gust' => $gustSpeedKts,
        'peak_gust_historical' => null,
        'peak_gust_historical_obs_time' => null,
        'obs_time' => (int)$obs['timestamp'],
    ];
}

/**
 * Fetch weather from AerisWeather API
 * 
 * @param array $source Weather source configuration
 * @return array|null Weather data array or null on failure
 */
function fetchPWSWeather($source): ?array {
    if (!is_array($source) || !isset($source['station_id']) || !isset($source['client_id']) || !isset($source['client_secret'])) {
        return null;
    }
    
    $url = PWSWeatherAdapter::buildUrl($source);
    if ($url === null) {
        return null;
    }
    
    $mockResponse = getMockHttpResponse($url);
    if ($mockResponse !== null) {
        return parsePWSWeatherResponse($mockResponse);
    }
    
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
    
    return parsePWSWeatherResponse($response);
}

