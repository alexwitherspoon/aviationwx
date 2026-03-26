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

/**
 * NOTAM start/end UTC strings that are strictly in the future and still on the current UTC calendar day.
 *
 * Used by NOTAM unit tests so "upcoming_today" is stable (avoids midnight rollover and avoids
 * fixed clock times that become "expired" later in the day).
 *
 * @return array{start_time_utc: string, end_time_utc: string}
 */
function notamTestTimesUpcomingLaterTodayUtc(): array
{
    $tz = new DateTimeZone('UTC');
    $now = new DateTimeImmutable('now', $tz);
    $todayEnd = $now->setTime(23, 59, 59);

    $slots = [
        [14, 0, 14, 30],
        [18, 0, 18, 30],
        [21, 0, 21, 30],
        [23, 0, 23, 30],
        [23, 40, 23, 58],
    ];

    foreach ($slots as [$sh, $sm, $eh, $em]) {
        $start = $now->setTime($sh, $sm, 0);
        $end = $now->setTime($eh, $em, 0);
        if ($start > $now && $end > $start && $end <= $todayEnd) {
            return [
                'start_time_utc' => gmdate('Y-m-d\TH:i:s\Z', $start->getTimestamp()),
                'end_time_utc' => gmdate('Y-m-d\TH:i:s\Z', $end->getTimestamp()),
            ];
        }
    }

    $start = $now->modify('+3 seconds');
    $end = $now->modify('+12 seconds');
    if ($end > $todayEnd) {
        $end = $todayEnd;
        $start = $end->modify('-2 seconds');
        if ($start <= $now) {
            $start = $now->modify('+1 second');
            $end = $start->modify('+1 second');
        }
    }

    return [
        'start_time_utc' => gmdate('Y-m-d\TH:i:s\Z', $start->getTimestamp()),
        'end_time_utc' => gmdate('Y-m-d\TH:i:s\Z', $end->getTimestamp()),
    ];
}

