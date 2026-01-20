<?php
/**
 * Test Helper Functions
 */

/**
 * Create a test airport configuration
 */
function createTestAirport($overrides = []) {
    return array_merge([
        'name' => 'Test Airport',
        'icao' => 'TEST',
        'address' => 'Test City, State',
        'lat' => 45.0,
        'lon' => -122.0,
        'elevation_ft' => 100,
        'timezone' => 'America/Los_Angeles',
        'weather_sources' => [
            [
                'type' => 'tempest',
                'station_id' => '12345',
                'api_key' => 'test_key'
            ],
            [
                'type' => 'metar',
                'station_id' => 'KSPB'
            ]
        ],
        'webcams' => []
    ], $overrides);
}

/**
 * Create test weather data
 */
function createTestWeatherData($overrides = []) {
    return array_merge([
        'temperature' => 15.0,
        'temperature_f' => 59,
        'dewpoint' => 10.0,
        'dewpoint_f' => 50,
        'humidity' => 70,
        'pressure' => 30.12,
        'wind_speed' => 8,
        'wind_direction' => 230,
        'gust_speed' => 12,
        'gust_factor' => 4,
        'visibility' => 10.0,
        'ceiling' => null,
        'cloud_cover' => 'SCT',
        'precip_accum' => 0.0,
        'flight_category' => 'VFR',
        'density_altitude' => 1000,
        'pressure_altitude' => 500,
        'dewpoint_spread' => 5.0,
        'last_updated' => time(),
        'last_updated_iso' => date('c')
    ], $overrides);
}

/**
 * Assert weather API response structure
 */
function assertWeatherResponse($response) {
    PHPUnit\Framework\Assert::assertIsArray($response);
    PHPUnit\Framework\Assert::assertArrayHasKey('success', $response);
    
    if ($response['success']) {
        PHPUnit\Framework\Assert::assertArrayHasKey('weather', $response);
        $weather = $response['weather'];
        
        // Check required fields
        $requiredFields = ['temperature', 'wind_speed', 'flight_category'];
        foreach ($requiredFields as $field) {
            PHPUnit\Framework\Assert::assertArrayHasKey($field, $weather, "Missing required field: $field");
        }
        
        // Validate flight category
        if (isset($weather['flight_category'])) {
            PHPUnit\Framework\Assert::assertContains(
                $weather['flight_category'],
                ['VFR', 'MVFR', 'IFR', 'LIFR', null],
                'Invalid flight category'
            );
        }
    } else {
        PHPUnit\Framework\Assert::assertArrayHasKey('error', $response);
    }
}

