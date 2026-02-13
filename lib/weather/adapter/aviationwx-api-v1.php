<?php
/**
 * AviationWX API Federation Adapter
 * 
 * Fetches weather data from another AviationWX instance's public API.
 * Enables federated architecture where single-airport installations can
 * become part of the larger AviationWX.org network.
 * 
 * Configuration example:
 * {
 *   "type": "aviationwx_api",
 *   "base_url": "https://weather.myairport.com",
 *   "api_key": "ak_live_federated_xyz123",
 *   "timeout_seconds": 10
 * }
 * 
 * @package AviationWX\Weather\Adapter
 */

namespace AviationWX\Weather\Adapter;

use AviationWX\Weather\Data\WeatherSnapshot;

require_once __DIR__ . '/../../constants.php';
require_once __DIR__ . '/../data/WeatherSnapshot.php';
require_once __DIR__ . '/../../../lib/circuit-breaker.php';
require_once __DIR__ . '/../../../lib/logger.php';

class AviationWXAPIAdapter {
    
    /**
     * Get the fields this adapter can provide
     * 
     * Federation adapter can provide all fields since it's fetching from
     * another AviationWX instance that has full weather data.
     */
    public static function getFieldsProvided(): array {
        return [
            'temperature',
            'feels_like',
            'humidity',
            'dew_point',
            'pressure',
            'wind_speed',
            'wind_gust',
            'wind_direction',
            'precipitation',
            'solar_radiation',
            'uv',
            'aqi',
            'visibility',
            'flight_category',
            'metar_raw'
        ];
    }
    
    /**
     * Get typical update frequency
     * 
     * Federated sources update as often as their underlying sources,
     * typically 60 seconds for real-time weather stations.
     */
    public static function getTypicalUpdateFrequency(): int {
        return 60; // 1 minute
    }
    
    /**
     * Get maximum acceptable age
     * 
     * Allow up to 10 minutes for federated data before considering stale.
     */
    public static function getMaxAcceptableAge(): int {
        return 600; // 10 minutes
    }
    
    /**
     * Get the source type identifier
     */
    public static function getSourceType(): string {
        return 'aviationwx_api';
    }
    
    /**
     * Check if this source provides a specific field
     */
    public static function providesField(string $fieldName): bool {
        return in_array($fieldName, self::getFieldsProvided());
    }
    
    /**
     * Parse API response into WeatherSnapshot
     * 
     * The federated API returns data in AviationWX's standard format,
     * so we just validate and convert it to WeatherSnapshot.
     */
    public static function parseResponse(string $response, array $config = []): ?WeatherSnapshot {
        $data = json_decode($response, true);
        
        if (!is_array($data) || !isset($data['success']) || $data['success'] !== true) {
            \aviationwx_log('error', 'AviationWXAPIAdapter: Invalid API response structure');
            return null;
        }
        
        if (!isset($data['data']) || !is_array($data['data'])) {
            \aviationwx_log('error', 'AviationWXAPIAdapter: Missing data in API response');
            return null;
        }
        
        $weatherData = $data['data'];
        
        // Validate required fields
        if (!isset($weatherData['timestamp'])) {
            \aviationwx_log('error', 'AviationWXAPIAdapter: Missing timestamp in response');
            return null;
        }
        
        // Check data age
        $timestamp = strtotime($weatherData['timestamp']);
        if ($timestamp === false) {
            \aviationwx_log('error', 'AviationWXAPIAdapter: Invalid timestamp format');
            return null;
        }
        
        $age = time() - $timestamp;
        if ($age > self::getMaxAcceptableAge()) {
            \aviationwx_log('warning', 'AviationWXAPIAdapter: Data too old', [
                'age_seconds' => $age,
                'max_age' => self::getMaxAcceptableAge()
            ]);
            return null;
        }
        
        // Create WeatherSnapshot from API data
        $snapshot = new WeatherSnapshot();
        $snapshot->source_type = self::getSourceType();
        $snapshot->source_id = $config['base_url'] ?? 'federated';
        $snapshot->timestamp = $timestamp;
        
        // Map API fields to snapshot
        $fieldMap = [
            'temperature' => 'temperature',
            'feels_like' => 'feels_like',
            'humidity' => 'humidity',
            'dew_point' => 'dew_point',
            'pressure' => 'pressure',
            'wind_speed' => 'wind_speed',
            'wind_gust' => 'wind_gust',
            'wind_direction' => 'wind_direction',
            'precipitation_rate' => 'precipitation',
            'solar_radiation' => 'solar_radiation',
            'uv' => 'uv',
            'aqi' => 'aqi',
            'visibility' => 'visibility',
            'flight_category' => 'flight_category',
            'metar' => 'metar_raw'
        ];
        
        foreach ($fieldMap as $apiField => $snapshotField) {
            if (isset($weatherData[$apiField]) && $weatherData[$apiField] !== null) {
                $snapshot->$snapshotField = $weatherData[$apiField];
            }
        }
        
        return $snapshot;
    }
    
    /**
     * Build the API URL for fetching data
     */
    public static function buildUrl(array $config): ?string {
        if (!isset($config['base_url']) || empty($config['base_url'])) {
            \aviationwx_log('error', 'AviationWXAPIAdapter: Missing base_url in config');
            return null;
        }
        
        if (!isset($config['airport_id']) || empty($config['airport_id'])) {
            \aviationwx_log('error', 'AviationWXAPIAdapter: Missing airport_id in config');
            return null;
        }
        
        $baseUrl = rtrim($config['base_url'], '/');
        $airportId = $config['airport_id'];
        
        return "{$baseUrl}/api/v1/weather/{$airportId}";
    }
    
    /**
     * Fetch weather data from federated source
     * 
     * This is a helper method that handles the HTTP request with circuit breaker.
     * Called by UnifiedFetcher.
     */
    public static function fetch(string $airportId, array $sourceConfig): ?WeatherSnapshot {
        $baseUrl = rtrim($sourceConfig['base_url'] ?? '', '/');
        $apiKey = $sourceConfig['api_key'] ?? null;
        $timeout = $sourceConfig['timeout_seconds'] ?? 10;
        
        if (empty($baseUrl)) {
            \aviationwx_log('error', 'AviationWXAPIAdapter: Missing base_url', [
                'airport_id' => $airportId
            ]);
            return null;
        }
        
        // Check circuit breaker
        $breakerKey = "aviationwx_api_{$baseUrl}_{$airportId}";
        if (\isCircuitOpen($breakerKey)) {
            \aviationwx_log('warning', 'AviationWXAPIAdapter: Circuit breaker open', [
                'airport_id' => $airportId,
                'base_url' => $baseUrl
            ]);
            return null;
        }
        
        try {
            // Build URL
            $config = array_merge($sourceConfig, ['airport_id' => $airportId]);
            $url = self::buildUrl($config);
            
            if (!$url) {
                return null;
            }
            
            // Make HTTP request
            $ch = curl_init();
            curl_setopt_array($ch, [
                CURLOPT_URL => $url,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT => $timeout,
                CURLOPT_CONNECTTIMEOUT => 5,
                CURLOPT_HTTPHEADER => self::buildHeaders($apiKey),
                CURLOPT_USERAGENT => 'AviationWX-Federation/1.0'
            ]);
            
            $response = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $error = curl_error($ch);
            
            if ($response === false) {
                \recordCircuitBreakerFailure($breakerKey);
                \aviationwx_log('error', 'AviationWXAPIAdapter: cURL error', [
                    'airport_id' => $airportId,
                    'base_url' => $baseUrl,
                    'error' => $error
                ]);
                return null;
            }
            
            if ($httpCode !== 200) {
                \recordCircuitBreakerFailure($breakerKey);
                \aviationwx_log('error', 'AviationWXAPIAdapter: HTTP error', [
                    'airport_id' => $airportId,
                    'base_url' => $baseUrl,
                    'http_code' => $httpCode
                ]);
                return null;
            }
            
            // Success - reset circuit breaker
            \recordCircuitBreakerSuccess($breakerKey);
            
            // Parse response
            return self::parseResponse($response, $config);
            
        } catch (\Exception $e) {
            \recordCircuitBreakerFailure($breakerKey);
            \aviationwx_log('error', 'AviationWXAPIAdapter: Exception', [
                'airport_id' => $airportId,
                'base_url' => $baseUrl,
                'exception' => $e->getMessage()
            ]);
            return null;
        }
    }
    
    /**
     * Build HTTP headers for API request
     */
    private static function buildHeaders(?string $apiKey): array {
        $headers = [
            'Accept: application/json',
            'Content-Type: application/json'
        ];
        
        if ($apiKey) {
            $headers[] = "X-API-Key: {$apiKey}";
        }
        
        return $headers;
    }
}
