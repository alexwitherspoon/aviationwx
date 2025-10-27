<?php
/**
 * Mock Weather API Responses for Testing
 * This file provides mock responses for weather APIs to avoid requiring real API keys
 */

/**
 * Get a mock Tempest API response
 */
function getMockTempestResponse() {
    return json_encode([
        'status' => [
            'status_code' => 0,
            'status_message' => 'OK'
        ],
        'obs' => [[
            'timestamp' => time(),
            'air_temperature' => 5.6,
            'relative_humidity' => 93,
            'sea_level_pressure' => 1019.2,
            'wind_avg' => 2.5,
            'wind_direction' => 89,
            'wind_gust' => 3.2,
            'precip_accum_local_day_final' => 0.47,
            'dew_point' => 4.6
        ]]
    ]);
}

/**
 * Get a mock Ambient Weather API response
 */
function getMockAmbientResponse() {
    return json_encode([[
        'macAddress' => 'AA:BB:CC:DD:EE:FF',
        'lastData' => [
            'dateutc' => time() * 1000,
            'tempf' => 42.8,
            'humidity' => 93,
            'baromrelin' => 30.08,
            'windspeedmph' => 5,
            'winddir' => 89,
            'windgustmph' => 7,
            'dailyrainin' => 0.47,
            'dewPoint' => 40.3
        ]
    ]]);
}

/**
 * Get a mock METAR API response
 */
function getMockMETARResponse() {
    return json_encode([[
        'rawOb' => 'KSPB 261654Z AUTO 18005KT 10SM CLR 06/04 A3012',
        'obsTime' => '2025-01-26T16:54:00Z',
        'temp' => 6.0,
        'dewp' => 4.0,
        'wdir' => 180,
        'wspd' => 5,
        'visib' => '10',
        'altim' => 30.12,
        'clouds' => [
            ['cover' => 'CLR', 'base' => null]
        ]
    ]]);
}

