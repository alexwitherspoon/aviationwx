<?php
/**
 * WeatherLink v2 API Adapter
 * 
 * Handles fetching and parsing weather data from WeatherLink v2 API.
 * Supports: WeatherLink Live, WeatherLink Console, EnviroMonitor, and newer Davis devices.
 * 
 * API documentation: https://weatherlink.github.io/v2-api/
 * 
 * Authentication: API Key (query param) + API Secret (header)
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
 * Updates every ~60 seconds depending on model/configuration.
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
     * 
     * @param array $config Source configuration (api_key, api_secret, station_id)
     * @return string|null API URL or null if config is invalid
     */
    public static function buildUrl(array $config): ?string {
        if (!isset($config['api_key']) || !isset($config['api_secret']) || !isset($config['station_id'])) {
            return null;
        }
        $timestamp = time();
        $signature = hash_hmac('sha256', $config['api_key'] . $timestamp, $config['api_secret']);
        return "https://api.weatherlink.com/v2/current/{$config['station_id']}?api-key={$config['api_key']}&t={$timestamp}&api-signature={$signature}";
    }
    
    /**
     * Get headers required for API request
     * 
     * @param array $config Source configuration
     * @return array Headers array
     */
    public static function getHeaders(array $config): array {
        return [
            'x-api-secret: ' . ($config['api_secret'] ?? ''),
            'Accept: application/json',
        ];
    }
    
    /**
     * Parse API response into a WeatherSnapshot
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
    public static function parseResponse(?string $response): ?array {
        if ($response === null || $response === '') {
            return null;
        }
        $data = json_decode($response, true);
        
        if ($data === null || !is_array($data)) {
            return null;
        }
        
        // WeatherLink response structure: sensors array with lsid and data arrays
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
            if (isset($dataFields['temp']) && is_numeric($dataFields['temp'])) {
                $result['temperature'] = ((float)$dataFields['temp'] - 32) / 1.8;
            } elseif (isset($dataFields['temp_out']) && is_numeric($dataFields['temp_out'])) {
                $result['temperature'] = ((float)$dataFields['temp_out'] - 32) / 1.8;
            } elseif (isset($dataFields['wind_chill']) && is_numeric($dataFields['wind_chill'])) {
                $result['temperature'] = ((float)$dataFields['wind_chill'] - 32) / 1.8;
            }
            
            // Humidity - percentage
            if (isset($dataFields['hum']) && is_numeric($dataFields['hum'])) {
                $result['humidity'] = (float)$dataFields['hum'];
            }
            
            // Dewpoint - Fahrenheit, convert to Celsius
            if (isset($dataFields['dew_point']) && is_numeric($dataFields['dew_point'])) {
                $result['dewpoint'] = ((float)$dataFields['dew_point'] - 32) / 1.8;
            }
            
            // Pressure - WeatherLink provides in inHg
            if (isset($dataFields['bar_sea_level']) && is_numeric($dataFields['bar_sea_level'])) {
                $result['pressure'] = (float)$dataFields['bar_sea_level'];
            } elseif (isset($dataFields['bar']) && is_numeric($dataFields['bar'])) {
                $result['pressure'] = (float)$dataFields['bar'];
            }
            
            // Wind speed - WeatherLink uses mph, convert to knots
            if (isset($dataFields['wind_speed_last']) && is_numeric($dataFields['wind_speed_last'])) {
                $result['wind_speed'] = (int)round((float)$dataFields['wind_speed_last'] * 0.868976);
            } elseif (isset($dataFields['wind_speed_avg_last_1_min']) && is_numeric($dataFields['wind_speed_avg_last_1_min'])) {
                $result['wind_speed'] = (int)round((float)$dataFields['wind_speed_avg_last_1_min'] * 0.868976);
            } elseif (isset($dataFields['wind_speed_avg_last_10_min']) && is_numeric($dataFields['wind_speed_avg_last_10_min'])) {
                $result['wind_speed'] = (int)round((float)$dataFields['wind_speed_avg_last_10_min'] * 0.868976);
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
            if (isset($dataFields['wind_speed_hi_last_2_min']) && is_numeric($dataFields['wind_speed_hi_last_2_min'])) {
                $gustSpeed = (int)round((float)$dataFields['wind_speed_hi_last_2_min'] * 0.868976);
                $result['gust_speed'] = $gustSpeed;
                $result['peak_gust'] = $gustSpeed;
            } elseif (isset($dataFields['wind_speed_hi_last_10_min']) && is_numeric($dataFields['wind_speed_hi_last_10_min'])) {
                $gustSpeed = (int)round((float)$dataFields['wind_speed_hi_last_10_min'] * 0.868976);
                $result['gust_speed'] = $gustSpeed;
                $result['peak_gust'] = $gustSpeed;
            }
            
            // Precipitation - normalize to 0 for no precipitation
            if (isset($dataFields['rainfall_daily_in']) && is_numeric($dataFields['rainfall_daily_in'])) {
                $result['precip_accum'] = (float)$dataFields['rainfall_daily_in'];
            } elseif (isset($dataFields['rainfall_daily_mm']) && is_numeric($dataFields['rainfall_daily_mm'])) {
                $result['precip_accum'] = (float)$dataFields['rainfall_daily_mm'] * 0.0393701;
            } elseif (isset($dataFields['rainfall_last_60_min_in']) && is_numeric($dataFields['rainfall_last_60_min_in'])) {
                $result['precip_accum'] = (float)$dataFields['rainfall_last_60_min_in'];
            } elseif (isset($dataFields['rainfall_last_60_min_mm']) && is_numeric($dataFields['rainfall_last_60_min_mm'])) {
                $result['precip_accum'] = (float)$dataFields['rainfall_last_60_min_mm'] * 0.0393701;
            } else {
                $result['precip_accum'] = 0;
            }
        }
        
        // Set observation time
        if ($latestTimestamp !== null) {
            $result['obs_time'] = $latestTimestamp;
        }
        
        return $result;
    }
}

// =============================================================================
// LEGACY FUNCTION ALIASES (for backward compatibility)
// =============================================================================

// Alias the old class name for backward compatibility
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
    curl_close($ch);
    
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

