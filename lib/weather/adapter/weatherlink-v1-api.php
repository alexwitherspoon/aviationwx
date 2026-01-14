<?php
/**
 * WeatherLink v1 API Adapter
 * 
 * Handles fetching and parsing weather data from WeatherLink v1 API (legacy).
 * Supports: Vantage Connect, WeatherLinkIP, WeatherLink USB/Serial loggers,
 *           and WeatherLink Network Annual Subscription connected stations.
 * 
 * API documentation: https://www.weatherlink.com/static/docs/APIdocumentation.pdf
 * 
 * Authentication: Device ID (DID) + API Token
 * Required config: device_id, api_token
 * 
 * Note: The v1 API is for legacy devices. New Davis devices (WeatherLink Live,
 * WeatherLink Console, EnviroMonitor) should use the v2 API instead.
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
 * WeatherLink v1 API Adapter Class
 * 
 * Legacy Davis Instruments WeatherLink stations using older hardware.
 * The v1 API uses Device ID (DID) and API Token for authentication.
 * Data is returned in NOAA Extended format (JSON).
 * Updates every ~60 seconds depending on model/configuration.
 */
class WeatherLinkV1Adapter {
    
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
    public const SOURCE_TYPE = 'weatherlink_v1';
    
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
     * WeatherLink v1 API uses Device ID and API Token as query parameters.
     * 
     * Endpoint format: https://api.weatherlink.com/v1/NoaaExt.json?user={user}&pass={pass}&apiToken={token}
     * Note: 'user' is the Device ID (DID) and 'pass' can be empty or any value.
     * 
     * @param array $config Source configuration (device_id, api_token)
     * @return string|null API URL or null if config is invalid
     */
    public static function buildUrl(array $config): ?string {
        if (!isset($config['device_id']) || !isset($config['api_token'])) {
            return null;
        }
        
        $deviceId = urlencode($config['device_id']);
        $apiToken = urlencode($config['api_token']);
        
        // The v1 API uses 'user' for Device ID and 'pass' (can be empty)
        // Format: NoaaExt.json returns JSON formatted NOAA extended data
        return "https://api.weatherlink.com/v1/NoaaExt.json?user={$deviceId}&pass=&apiToken={$apiToken}";
    }
    
    /**
     * Get headers required for API request
     * 
     * @param array $config Source configuration
     * @return array Headers array (v1 API doesn't need special headers)
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
     * Parse WeatherLink v1 API response (NOAA Extended JSON format)
     * 
     * The v1 API returns data in NOAA Extended format with fields like:
     * - temp_f: Temperature in Fahrenheit
     * - temp_c: Temperature in Celsius
     * - dewpoint_f: Dewpoint in Fahrenheit
     * - dewpoint_c: Dewpoint in Celsius
     * - relative_humidity: Humidity percentage
     * - pressure_in: Barometric pressure in inHg
     * - pressure_mb: Barometric pressure in mb
     * - wind_mph: Wind speed in mph
     * - wind_degrees: Wind direction in degrees
     * - wind_kt: Wind speed in knots
     * - davis_current_observation: Nested object with additional Davis-specific fields
     * 
     * @param string|null $response JSON response from WeatherLink v1 API
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
        
        // Check for error response
        if (isset($data['error'])) {
            aviationwx_log('warning', 'WeatherLink v1 API error', [
                'error' => $data['error']
            ], 'app');
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
        
        // Parse observation time
        // v1 API provides observation_time_rfc822 or observation_time
        if (isset($data['observation_time_rfc822'])) {
            $timestamp = strtotime($data['observation_time_rfc822']);
            if ($timestamp !== false) {
                $result['obs_time'] = $timestamp;
            }
        } elseif (isset($data['observation_time'])) {
            $timestamp = strtotime($data['observation_time']);
            if ($timestamp !== false) {
                $result['obs_time'] = $timestamp;
            }
        }
        
        // Temperature - prefer Celsius, fallback to Fahrenheit conversion
        if (isset($data['temp_c']) && is_numeric($data['temp_c'])) {
            $result['temperature'] = (float)$data['temp_c'];
        } elseif (isset($data['temp_f']) && is_numeric($data['temp_f'])) {
            $result['temperature'] = ((float)$data['temp_f'] - 32) / 1.8;
        }
        
        // Dewpoint - prefer Celsius, fallback to Fahrenheit conversion
        if (isset($data['dewpoint_c']) && is_numeric($data['dewpoint_c'])) {
            $result['dewpoint'] = (float)$data['dewpoint_c'];
        } elseif (isset($data['dewpoint_f']) && is_numeric($data['dewpoint_f'])) {
            $result['dewpoint'] = ((float)$data['dewpoint_f'] - 32) / 1.8;
        }
        
        // Humidity
        if (isset($data['relative_humidity']) && is_numeric($data['relative_humidity'])) {
            $result['humidity'] = (float)$data['relative_humidity'];
        }
        
        // Pressure - prefer inHg (our standard), fallback to mb conversion
        if (isset($data['pressure_in']) && is_numeric($data['pressure_in'])) {
            $result['pressure'] = (float)$data['pressure_in'];
        } elseif (isset($data['pressure_mb']) && is_numeric($data['pressure_mb'])) {
            $result['pressure'] = (float)$data['pressure_mb'] / 33.8639;
        }
        
        // Wind speed - prefer knots, fallback to mph conversion
        if (isset($data['wind_kt']) && is_numeric($data['wind_kt'])) {
            $result['wind_speed'] = (int)round((float)$data['wind_kt']);
        } elseif (isset($data['wind_mph']) && is_numeric($data['wind_mph'])) {
            $result['wind_speed'] = (int)round((float)$data['wind_mph'] * 0.868976);
        }
        
        // Wind direction
        if (isset($data['wind_degrees']) && is_numeric($data['wind_degrees'])) {
            $result['wind_direction'] = (int)round((float)$data['wind_degrees']);
        }
        
        // Check for Davis-specific extended data
        if (isset($data['davis_current_observation']) && is_array($data['davis_current_observation'])) {
            $davis = $data['davis_current_observation'];
            
            // Gust speed from Davis extended data
            if (isset($davis['wind_ten_min_gust_mph']) && is_numeric($davis['wind_ten_min_gust_mph'])) {
                $gustSpeed = (int)round((float)$davis['wind_ten_min_gust_mph'] * 0.868976);
                $result['gust_speed'] = $gustSpeed;
                $result['peak_gust'] = $gustSpeed;
            }
            
            // Daily precipitation from Davis extended data
            if (isset($davis['rain_day_in']) && is_numeric($davis['rain_day_in'])) {
                $result['precip_accum'] = (float)$davis['rain_day_in'];
            }
            
            // Temperature high/low from Davis extended data
            if (isset($davis['temp_day_high_f']) && is_numeric($davis['temp_day_high_f'])) {
                $result['temp_high'] = ((float)$davis['temp_day_high_f'] - 32) / 1.8;
            }
            if (isset($davis['temp_day_low_f']) && is_numeric($davis['temp_day_low_f'])) {
                $result['temp_low'] = ((float)$davis['temp_day_low_f'] - 32) / 1.8;
            }
        }
        
        // Default precip to 0 if not set (unified standard: 0 = no precip, null = failed)
        if ($result['precip_accum'] === null) {
            $result['precip_accum'] = 0;
        }
        
        return $result;
    }
}

/**
 * Fetch weather from WeatherLink v1 API (synchronous)
 * 
 * @param array $source Source configuration (device_id, api_token)
 * @return array|null Weather data array or null on failure
 */
function fetchWeatherLinkV1Weather($source): ?array {
    if (!is_array($source) || !isset($source['device_id']) || !isset($source['api_token'])) {
        return null;
    }
    
    $url = WeatherLinkV1Adapter::buildUrl($source);
    if ($url === null) {
        return null;
    }
    
    // Check for mock response in test mode
    $mockResponse = getMockHttpResponse($url);
    if ($mockResponse !== null) {
        return WeatherLinkV1Adapter::parseResponse($mockResponse);
    }
    
    // Create context with timeout
    $context = stream_context_create([
        'http' => [
            'timeout' => CURL_TIMEOUT,
            'ignore_errors' => true,
            'header' => "Accept: application/json\r\n",
        ],
    ]);
    
    $response = @file_get_contents($url, false, $context);
    
    if ($response === false) {
        aviationwx_log('warning', 'WeatherLink v1 API fetch failed', [
            'url_masked' => preg_replace('/apiToken=[^&]+/', 'apiToken=***', $url),
        ], 'app');
        return null;
    }
    
    return WeatherLinkV1Adapter::parseResponse($response);
}

