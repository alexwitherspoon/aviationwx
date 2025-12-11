<?php
/**
 * Test Mocking Infrastructure
 * 
 * Provides HTTP request mocking for test environments.
 * Intercepts file_get_contents() and curl_exec() calls to return mock responses
 * instead of making real HTTP requests.
 */

require_once __DIR__ . '/config.php';

/**
 * Get mock HTTP response for a given URL
 * 
 * Maps API URLs to appropriate mock responses based on the provider type.
 * Returns null if no mock is available (allows real request to proceed).
 * 
 * @param string $url The URL to get a mock response for
 * @return string|null Mock response string, or null if no mock available
 */
function getMockHttpResponse(string $url): ?string {
    if (!isTestMode()) {
        return null; // Not in test mode, don't mock
    }
    
    // Map URLs to mock responses
    if (strpos($url, 'swd.weatherflow.com') !== false) {
        // Tempest API
        require_once __DIR__ . '/../tests/mock-weather-responses.php';
        return getMockTempestResponse();
    }
    
    if (strpos($url, 'api.ambientweather.net') !== false) {
        // Ambient Weather API
        require_once __DIR__ . '/../tests/mock-weather-responses.php';
        return getMockAmbientResponse();
    }
    
    if (strpos($url, 'api.weatherlink.com') !== false) {
        // WeatherLink API
        require_once __DIR__ . '/../tests/mock-weather-responses.php';
        return getMockWeatherLinkResponse();
    }
    
    if (strpos($url, 'aviationweather.gov') !== false) {
        // METAR API
        require_once __DIR__ . '/../tests/mock-weather-responses.php';
        return getMockMETARResponse();
    }
    
    if (strpos($url, 'api.aerisapi.com') !== false) {
        // AerisWeather API (PWSWeather.com)
        require_once __DIR__ . '/../tests/mock-weather-responses.php';
        return getMockPWSWeatherResponse();
    }
    
    // Webcam URLs - return placeholder image
    if (strpos($url, 'example.com') !== false || 
        strpos($url, 'test') !== false ||
        strpos($url, 'mock') !== false) {
        // Return path to placeholder image
        $placeholderPath = __DIR__ . '/../public/images/placeholder.jpg';
        if (file_exists($placeholderPath)) {
            return file_get_contents($placeholderPath);
        }
        // If placeholder doesn't exist, return a minimal valid JPEG
        // JPEG header + minimal data
        return "\xff\xd8\xff\xe0\x00\x10JFIF\x00\x01\x01\x01\x00H\x00H\x00\x00\xff\xdb\x00C\x00\x08\x06\x06\x07\x06\x05\x08\x07\x07\x07\t\t\x08\n\x0c\x14\r\x0c\x0b\x0b\x0c\x19\x12\x13\x0f\x14\x1d\x1a\x1f\x1e\x1d\x1a\x1c\x1c $.\" \x1c\x1c(7),01444\x1f'9=82<.342\xff\xc0\x00\x11\x08\x00\x01\x00\x01\x01\x01\x11\x00\x02\x11\x01\x03\x11\x01\xff\xc4\x00\x14\x00\x01\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00\x08\xff\xc4\x00\x14\x10\x01\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00\xff\xda\x00\x08\x01\x01\x00\x00?\x00\xff\xd9";
    }
    
    return null; // No mock available, proceed with real request
}

