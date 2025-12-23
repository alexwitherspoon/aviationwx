<?php
/**
 * WeatherLink v2 API Adapter v1
 * 
 * Handles fetching and parsing weather data from WeatherLink v2 API.
 * API documentation: https://weatherlink.github.io/v2-api/
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
 * WeatherLink Adapter Class (implements new interface pattern)
 * 
 * Davis Instruments WeatherLink stations provide real-time weather data.
 * Updates every ~60 seconds depending on model/configuration.
 */
class WeatherLinkAdapter {
    
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
    public const SOURCE_TYPE = 'weatherlink';
    
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
     * Parse API response into a WeatherSnapshot
     */
    public static function parseToSnapshot(string $response, array $config = []): ?WeatherSnapshot {
        $parsed = parseWeatherLinkResponse($response);
        if ($parsed === null) {
            return WeatherSnapshot::empty(self::SOURCE_TYPE);
        }
        
        $obsTime = $parsed['obs_time'] ?? time();
        $source = self::SOURCE_TYPE;
        
        $hasCompleteWind = $parsed['wind_speed'] !== null && $parsed['wind_direction'] !== null;
        
        return new WeatherSnapshot(
            source: $source,
            fetchTime: time(),
            temperature: $parsed['temperature'] !== null 
                ? WeatherReading::from($parsed['temperature'], $source, $obsTime)
                : WeatherReading::null($source),
            dewpoint: $parsed['dewpoint'] !== null
                ? WeatherReading::from($parsed['dewpoint'], $source, $obsTime)
                : WeatherReading::null($source),
            humidity: $parsed['humidity'] !== null
                ? WeatherReading::from($parsed['humidity'], $source, $obsTime)
                : WeatherReading::null($source),
            pressure: $parsed['pressure'] !== null
                ? WeatherReading::from($parsed['pressure'], $source, $obsTime)
                : WeatherReading::null($source),
            precipAccum: $parsed['precip_accum'] !== null
                ? WeatherReading::from($parsed['precip_accum'], $source, $obsTime)
                : WeatherReading::null($source),
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
}

// =============================================================================
// LEGACY FUNCTIONS (kept for backward compatibility during migration)
// =============================================================================

/**
 * Parse WeatherLink v2 API response
 * 
 * Parses JSON response from WeatherLink v2 API and converts to standard format.
 * WeatherLink uses a sensor-based structure with sensors array containing lsid and data arrays.
 * Handles unit conversions (Fahrenheit to Celsius, mph to knots).
 * 
 * Response structure: { "station_id": ..., "sensors": [ { "lsid": ..., "data": [ { "ts": ..., "data": { ... } } ] } ] }
 * Field units: Temperature (F), Wind Speed (mph), Pressure (inHg), Rainfall (inches)
 * 
 * @param string|null $response JSON response from WeatherLink v2 API
 * @return array|null Weather data array with standard keys, or null on parse error
 */
function parseWeatherLinkResponse(?string $response): ?array {
    if ($response === null || $response === '') {
        return null;
    }
    $data = json_decode($response, true);
    
    if ($data === null || !is_array($data)) {
        return null;
    }
    
    // WeatherLink response structure: sensors array with lsid and data arrays
    // Each sensor has a data array with observation records
    // Each record has: ts (timestamp) and data (object with field names and values)
    
    if (!isset($data['sensors']) || !is_array($data['sensors'])) {
        return null;
    }
    
    // Initialize result array
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
    
    // Find the most recent observation time across all sensors
    $latestTimestamp = null;
    
    // Iterate through sensors to find weather data
    foreach ($data['sensors'] as $sensor) {
        if (!isset($sensor['data']) || !is_array($sensor['data']) || empty($sensor['data'])) {
            continue;
        }
        
        // Get the most recent data entry (first in array is typically most recent)
        $sensorData = $sensor['data'][0];
        
        // WeatherLink structure: each data entry has 'ts' (timestamp) and 'data' (fields object)
        // Some responses may have fields directly in sensorData, others nested in sensorData['data']
        $dataFields = isset($sensorData['data']) && is_array($sensorData['data']) 
            ? $sensorData['data'] 
            : $sensorData;
        
        // Extract timestamp if available
        if (isset($sensorData['ts']) && is_numeric($sensorData['ts'])) {
            $timestamp = (int)$sensorData['ts'];
            if ($latestTimestamp === null || $timestamp > $latestTimestamp) {
                $latestTimestamp = $timestamp;
            }
        }
        
        // Temperature - Davis stations use Fahrenheit
        // Field names may vary: 'temp', 'temp_in', 'temp_out', or calculated fields like 'wind_chill'
        // Prefer actual temp over calculated fields
        if (isset($dataFields['temp']) && is_numeric($dataFields['temp'])) {
            $result['temperature'] = ((float)$dataFields['temp'] - 32) / 1.8; // Convert F to C
        } elseif (isset($dataFields['temp_out']) && is_numeric($dataFields['temp_out'])) {
            $result['temperature'] = ((float)$dataFields['temp_out'] - 32) / 1.8; // Convert F to C
        } elseif (isset($dataFields['wind_chill']) && is_numeric($dataFields['wind_chill'])) {
            // Use wind_chill as fallback (calculated, but better than nothing)
            $result['temperature'] = ((float)$dataFields['wind_chill'] - 32) / 1.8; // Convert F to C
        }
        
        // Humidity - percentage
        if (isset($dataFields['hum']) && is_numeric($dataFields['hum'])) {
            $result['humidity'] = (float)$dataFields['hum'];
        }
        
        // Dewpoint - Fahrenheit, convert to Celsius
        if (isset($dataFields['dew_point']) && is_numeric($dataFields['dew_point'])) {
            $result['dewpoint'] = ((float)$dataFields['dew_point'] - 32) / 1.8; // Convert F to C
        }
        
        // Pressure - WeatherLink provides in inHg (bar_sea_level or bar)
        if (isset($dataFields['bar_sea_level']) && is_numeric($dataFields['bar_sea_level'])) {
            $result['pressure'] = (float)$dataFields['bar_sea_level']; // Already in inHg
        } elseif (isset($dataFields['bar']) && is_numeric($dataFields['bar'])) {
            $result['pressure'] = (float)$dataFields['bar']; // Already in inHg
        }
        
        // Wind speed - WeatherLink uses mph, convert to knots
        // Prefer 'wind_speed_last' (instantaneous) over averages
        if (isset($dataFields['wind_speed_last']) && is_numeric($dataFields['wind_speed_last'])) {
            $result['wind_speed'] = (int)round((float)$dataFields['wind_speed_last'] * 0.868976); // Convert mph to knots
        } elseif (isset($dataFields['wind_speed_avg_last_1_min']) && is_numeric($dataFields['wind_speed_avg_last_1_min'])) {
            $result['wind_speed'] = (int)round((float)$dataFields['wind_speed_avg_last_1_min'] * 0.868976); // Convert mph to knots
        } elseif (isset($dataFields['wind_speed_avg_last_10_min']) && is_numeric($dataFields['wind_speed_avg_last_10_min'])) {
            $result['wind_speed'] = (int)round((float)$dataFields['wind_speed_avg_last_10_min'] * 0.868976); // Convert mph to knots
        }
        
        // Wind direction - degrees (0-359)
        if (isset($dataFields['wind_dir_last']) && is_numeric($dataFields['wind_dir_last'])) {
            $result['wind_direction'] = (int)round((float)$dataFields['wind_dir_last']);
        } elseif (isset($dataFields['wind_dir_scalar_avg_last_1_min']) && is_numeric($dataFields['wind_dir_scalar_avg_last_1_min'])) {
            $result['wind_direction'] = (int)round((float)$dataFields['wind_dir_scalar_avg_last_1_min']);
        } elseif (isset($dataFields['wind_dir_scalar_avg_last_10_min']) && is_numeric($dataFields['wind_dir_scalar_avg_last_10_min'])) {
            $result['wind_direction'] = (int)round((float)$dataFields['wind_dir_scalar_avg_last_10_min']);
        }
        
        // Gust speed - WeatherLink uses mph, convert to knots
        // Prefer 2-minute high over 10-minute high
        if (isset($dataFields['wind_speed_hi_last_2_min']) && is_numeric($dataFields['wind_speed_hi_last_2_min'])) {
            $gustSpeed = (int)round((float)$dataFields['wind_speed_hi_last_2_min'] * 0.868976); // Convert mph to knots
            $result['gust_speed'] = $gustSpeed;
            $result['peak_gust'] = $gustSpeed;
        } elseif (isset($dataFields['wind_speed_hi_last_10_min']) && is_numeric($dataFields['wind_speed_hi_last_10_min'])) {
            $gustSpeed = (int)round((float)$dataFields['wind_speed_hi_last_10_min'] * 0.868976); // Convert mph to knots
            $result['gust_speed'] = $gustSpeed;
            $result['peak_gust'] = $gustSpeed;
        }
        
        // Note: WeatherLink v2 API does not provide daily peak gust fields in the current observations endpoint.
        // Daily peak gust tracking is handled by the application using current gust values.
        $peakGustHistorical = null;
        $peakGustHistoricalObsTime = null;
        
        // Precipitation - WeatherLink provides both inches and mm versions
        // Prefer daily accumulation, fallback to hourly
        // Normalize to 0 for no precipitation (unified standard: 0 = no precip, null = failed)
        if (isset($dataFields['rainfall_daily_in']) && is_numeric($dataFields['rainfall_daily_in'])) {
            $result['precip_accum'] = (float)$dataFields['rainfall_daily_in']; // Already in inches
        } elseif (isset($dataFields['rainfall_daily_mm']) && is_numeric($dataFields['rainfall_daily_mm'])) {
            $result['precip_accum'] = (float)$dataFields['rainfall_daily_mm'] * 0.0393701; // Convert mm to inches
        } elseif (isset($dataFields['rainfall_last_60_min_in']) && is_numeric($dataFields['rainfall_last_60_min_in'])) {
            $result['precip_accum'] = (float)$dataFields['rainfall_last_60_min_in']; // Already in inches
        } elseif (isset($dataFields['rainfall_last_60_min_mm']) && is_numeric($dataFields['rainfall_last_60_min_mm'])) {
            $result['precip_accum'] = (float)$dataFields['rainfall_last_60_min_mm'] * 0.0393701; // Convert mm to inches
        } else {
            // Default to 0 if no precip (unified standard: 0 = no precip, null = failed)
            $result['precip_accum'] = 0;
        }
    }
    
    // Set observation time
    if ($latestTimestamp !== null) {
        $result['obs_time'] = $latestTimestamp;
    }return $result;
}

/**
 * Fetch weather from WeatherLink v2 API (synchronous, for fallback)
 * 
 * Makes HTTP request to WeatherLink v2 API to fetch current weather observations.
 * Requires API key, API secret, and station ID. API key in query, secret in header.
 * Used as fallback when async fetch fails.
 * 
 * @param array $source Weather source configuration (must contain 'api_key', 'api_secret', and 'station_id')
 * @return array|null Weather data array with standard keys, or null on failure
 */
function fetchWeatherLinkWeather($source): ?array {
    // WeatherLink v2 API requires API Key, API Secret, and Station ID
    if (!is_array($source) || !isset($source['api_key']) || !isset($source['api_secret']) || !isset($source['station_id'])) {
        return null;
    }
    
    $apiKey = $source['api_key'];
    $apiSecret = $source['api_secret'];
    $stationId = $source['station_id'];
    
    // Fetch current conditions from WeatherLink v2 API
    // API key goes in query parameter, secret goes in header
    $url = "https://api.weatherlink.com/v2/current/{$stationId}?api-key=" . urlencode($apiKey);
    
    // Check for mock response in test mode
    $mockResponse = getMockHttpResponse($url);
    if ($mockResponse !== null) {
        $response = $mockResponse;
        $httpCode = 200;
    } else {
        // Use curl for proper header support
        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => CURL_TIMEOUT,
            CURLOPT_CONNECTTIMEOUT => CURL_TIMEOUT,
            CURLOPT_HTTPHEADER => [
                'x-api-secret: ' . $apiSecret,
                'Accept: application/json',
            ],
            CURLOPT_USERAGENT => 'AviationWX/1.0',
        ]);
        
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);
        
        if ($response === false || !empty($error) || $httpCode !== 200) {
            aviationwx_log('warning', 'WeatherLink API fetch failed', [
                'http_code' => $httpCode,
                'error' => $error,
                'response_length' => $response ? strlen($response) : 0
            ], 'app');
            return null;
        }
    }
    
    return parseWeatherLinkResponse($response);
}

