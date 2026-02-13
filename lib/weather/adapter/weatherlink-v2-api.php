<?php
/**
 * WeatherLink v2 API Adapter
 * 
 * Handles fetching and parsing weather data from WeatherLink v2 API.
 * Supports: WeatherLink Live, WeatherLink Console, EnviroMonitor, WeatherLinkIP,
 *           Vantage Connect, and other Davis devices using data_structure_type 1 or 2.
 * 
 * API documentation: https://weatherlink.github.io/v2-api/
 * 
 * Authentication: API Key (query param) + API Secret (HMAC signature)
 * Required config: api_key, api_secret, station_id
 */

require_once __DIR__ . '/../../constants.php';
require_once __DIR__ . '/../../test-mocks.php';
require_once __DIR__ . '/../../logger.php';
require_once __DIR__ . '/../data/WeatherReading.php';
require_once __DIR__ . '/../data/WindGroup.php';
require_once __DIR__ . '/../data/WeatherSnapshot.php';

use AviationWX\Weather\Data\WeatherReading;
use AviationWX\Weather\Data\WindGroup;
use AviationWX\Weather\Data\WeatherSnapshot;

/**
 * WeatherLink v2 API Adapter Class
 * 
 * Davis Instruments WeatherLink stations provide real-time weather data.
 * The v2 API supports all modern Davis devices connected to WeatherLink.com.
 * 
 * Supports two data structure types:
 * - Type 1: WeatherLink Live, Console (fields like temp, hum, wind_speed_last)
 * - Type 2: WeatherLinkIP, Vantage Connect (fields like temp_out, hum_out, wind_speed)
 */
class WeatherLinkV2Adapter {
    
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
    
    /** Typical update frequency in seconds */
    public const UPDATE_FREQUENCY = 60;
    
    /** Max acceptable age before data is stale (5 minutes) */
    public const MAX_ACCEPTABLE_AGE = 300;
    
    /** Source type identifier */
    public const SOURCE_TYPE = 'weatherlink_v2';
    
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
     * WeatherLink v2 API uses HMAC-SHA256 signature for authentication.
     * Signature is computed over: api-key{key}station-id{id}t{timestamp}
     * 
     * @param array $config Source configuration (api_key, api_secret, station_id)
     * @return string|null API URL or null if config is invalid
     */
    public static function buildUrl(array $config): ?string {
        if (!isset($config['api_key']) || !isset($config['api_secret']) || !isset($config['station_id'])) {
            return null;
        }
        $timestamp = time();
        $stationId = $config['station_id'];
        
        // Signature string must include station-id for /current endpoint
        $signatureString = "api-key{$config['api_key']}station-id{$stationId}t{$timestamp}";
        $signature = hash_hmac('sha256', $signatureString, $config['api_secret']);
        
        return "https://api.weatherlink.com/v2/current/{$stationId}?api-key={$config['api_key']}&t={$timestamp}&api-signature={$signature}";
    }
    
    /**
     * Get headers required for API request
     * 
     * @param array $config Source configuration
     * @return array Headers array
     */
    public static function getHeaders(array $config): array {
        return [
            'Accept: application/json',
        ];
    }
    
    /**
     * Parse API response into a WeatherSnapshot
     * 
     * Units returned:
     * - Temperature/Dewpoint: Celsius (converted from F)
     * - Humidity: Percent
     * - Pressure: inHg
     * - Precipitation: inches
     * - Wind: knots (converted from mph)
     */
    public static function parseToSnapshot(string $response, array $config = []): ?WeatherSnapshot {
        $parsed = self::parseResponse($response);
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
            visibility: WeatherReading::null($source),
            ceiling: WeatherReading::null($source),
            cloudCover: WeatherReading::null($source),
            isValid: true
        );
    }
    
    /**
     * Parse WeatherLink v2 API response
     * 
     * Handles two data structure types:
     * - Type 1 (WeatherLink Live/Console): Nested data object, fields like temp, hum, wind_speed_last
     * - Type 2 (WeatherLinkIP/Vantage Connect): Flat structure, fields like temp_out, hum_out, wind_speed
     * 
     * @param string|null $response JSON response from WeatherLink v2 API
     * @return array|null Weather data array with standard keys, or null on parse error
     */
    public static function parseResponse(?string $response): ?array {
        if ($response === null || $response === '') {
            return null;
        }
        $data = json_decode($response, true);
        
        if ($data === null || !is_array($data)) {
            return null;
        }
        
        if (!isset($data['sensors']) || !is_array($data['sensors'])) {
            return null;
        }
        
        $result = [
            'temperature' => null,
            'humidity' => null,
            'pressure' => null,
            'wind_speed' => null,
            'wind_direction' => null,
            'gust_speed' => null,
            'precip_accum' => null,
            'dewpoint' => null,
            'visibility' => null,
            'ceiling' => null,
            'temp_high' => null,
            'temp_low' => null,
            'peak_gust' => null,
            'obs_time' => null,
        ];
        
        $latestTimestamp = null;
        
        foreach ($data['sensors'] as $sensor) {
            if (!isset($sensor['data']) || !is_array($sensor['data']) || empty($sensor['data'])) {
                continue;
            }
            
            $sensorData = $sensor['data'][0];
            
            // Type 1 has nested 'data' object, Type 2 has fields directly in sensorData
            $dataFields = isset($sensorData['data']) && is_array($sensorData['data']) 
                ? $sensorData['data'] 
                : $sensorData;
            
            // Extract timestamp
            if (isset($sensorData['ts']) && is_numeric($sensorData['ts'])) {
                $timestamp = (int)$sensorData['ts'];
                if ($latestTimestamp === null || $timestamp > $latestTimestamp) {
                    $latestTimestamp = $timestamp;
                }
            }
            
            // Temperature (Fahrenheit → Celsius)
            // Type 1: temp, Type 2: temp_out
            $tempF = self::getFirstNumeric($dataFields, ['temp', 'temp_out']);
            if ($tempF !== null) {
                $result['temperature'] = ($tempF - 32) / 1.8;
            }
            
            // Humidity (percentage)
            // Type 1: hum, Type 2: hum_out
            $humidity = self::getFirstNumeric($dataFields, ['hum', 'hum_out']);
            if ($humidity !== null) {
                $result['humidity'] = $humidity;
            }
            
            // Dewpoint (Fahrenheit → Celsius)
            $dewpointF = self::getFirstNumeric($dataFields, ['dew_point', 'dewpoint']);
            if ($dewpointF !== null) {
                $result['dewpoint'] = ($dewpointF - 32) / 1.8;
            }
            
            // Pressure (inHg)
            $pressure = self::getFirstNumeric($dataFields, ['bar_sea_level', 'bar']);
            if ($pressure !== null) {
                $result['pressure'] = $pressure;
            }
            
            // Wind speed (mph → knots)
            // Type 1: wind_speed_last, wind_speed_avg_last_1_min, wind_speed_avg_last_10_min
            // Type 2: wind_speed, wind_speed_10_min_avg
            $windMph = self::getFirstNumeric($dataFields, [
                'wind_speed_last',
                'wind_speed_avg_last_1_min', 
                'wind_speed_avg_last_10_min',
                'wind_speed',
                'wind_speed_10_min_avg'
            ]);
            if ($windMph !== null) {
                $result['wind_speed'] = (int)round($windMph * 0.868976);
            }
            
            // Wind direction (degrees)
            // Type 1: wind_dir_last, wind_dir_scalar_avg_last_1_min, wind_dir_scalar_avg_last_10_min
            // Type 2: wind_dir
            $windDir = self::getFirstNumeric($dataFields, [
                'wind_dir_last',
                'wind_dir_scalar_avg_last_1_min',
                'wind_dir_scalar_avg_last_10_min',
                'wind_dir'
            ]);
            if ($windDir !== null) {
                $result['wind_direction'] = (int)round($windDir);
            }
            
            // Wind gust (mph → knots)
            // Type 1: wind_speed_hi_last_2_min, wind_speed_hi_last_10_min
            // Type 2: wind_gust_10_min
            $gustMph = self::getFirstNumeric($dataFields, [
                'wind_speed_hi_last_2_min',
                'wind_speed_hi_last_10_min',
                'wind_gust_10_min'
            ]);
            if ($gustMph !== null) {
                $gustKts = (int)round($gustMph * 0.868976);
                $result['gust_speed'] = $gustKts;
                $result['peak_gust'] = $gustKts;
            }
            
            // Precipitation (inches)
            // Type 1: rainfall_daily_in, rainfall_daily_mm (convert)
            // Type 2: rain_day_in, rain_day_mm (convert)
            $precipIn = self::getFirstNumeric($dataFields, ['rainfall_daily_in', 'rain_day_in']);
            if ($precipIn !== null) {
                $result['precip_accum'] = $precipIn;
            } else {
                $precipMm = self::getFirstNumeric($dataFields, ['rainfall_daily_mm', 'rain_day_mm']);
                if ($precipMm !== null) {
                    $result['precip_accum'] = $precipMm * 0.0393701;
                }
            }
        }
        
        if ($latestTimestamp !== null) {
            $result['obs_time'] = $latestTimestamp;
        }
        
        // Default precip to 0 if sensor didn't report (0 = no precip, null = sensor failure)
        if ($result['precip_accum'] === null) {
            $result['precip_accum'] = 0;
        }
        
        return $result;
    }
    
    /**
     * Get first numeric value from dataFields matching any of the given keys
     * 
     * @param array $dataFields Data fields from API response
     * @param array $keys Field names to check in priority order
     * @return float|null First found numeric value, or null if none found
     */
    private static function getFirstNumeric(array $dataFields, array $keys): ?float {
        foreach ($keys as $key) {
            if (isset($dataFields[$key]) && is_numeric($dataFields[$key])) {
                return (float)$dataFields[$key];
            }
        }
        return null;
    }
}

// =============================================================================
// LEGACY FUNCTION ALIASES (for backward compatibility)
// =============================================================================

if (!class_exists('WeatherLinkAdapter')) {
    class_alias('WeatherLinkV2Adapter', 'WeatherLinkAdapter');
}

/**
 * Legacy function: Parse WeatherLink v2 API response
 * @deprecated Use WeatherLinkV2Adapter::parseResponse() instead
 */
function parseWeatherLinkResponse(?string $response): ?array {
    return WeatherLinkV2Adapter::parseResponse($response);
}

/**
 * Legacy function: Fetch weather from WeatherLink v2 API
 * @deprecated Use WeatherLinkV2Adapter with UnifiedFetcher instead
 */
function fetchWeatherLinkWeather($source): ?array {
    if (!is_array($source) || !isset($source['api_key']) || !isset($source['api_secret']) || !isset($source['station_id'])) {
        return null;
    }
    
    $url = WeatherLinkV2Adapter::buildUrl($source);
    if ($url === null) {
        return null;
    }
    
    // Check for mock response in test mode
    $mockResponse = getMockHttpResponse($url);
    if ($mockResponse !== null) {
        return WeatherLinkV2Adapter::parseResponse($mockResponse);
    }
    
    // Use curl for proper header support
    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL => $url,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => CURL_TIMEOUT,
        CURLOPT_CONNECTTIMEOUT => CURL_TIMEOUT,
        CURLOPT_HTTPHEADER => WeatherLinkV2Adapter::getHeaders($source),
        CURLOPT_USERAGENT => 'AviationWX/1.0',
    ]);
    
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error = curl_error($ch);
    
    if ($response === false || !empty($error) || $httpCode !== 200) {
        aviationwx_log('warning', 'WeatherLink v2 API fetch failed', [
            'http_code' => $httpCode,
            'error' => $error,
            'response_length' => $response ? strlen($response) : 0
        ], 'app');
        return null;
    }
    
    return WeatherLinkV2Adapter::parseResponse($response);
}

