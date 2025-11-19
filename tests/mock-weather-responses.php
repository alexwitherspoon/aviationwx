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

/**
 * Get a mock WeatherLink v2 API response
 * Based on actual API structure from documentation and sample responses
 * WeatherLink uses: { "station_id": ..., "sensors": [ { "lsid": ..., "data": [ { "ts": ..., "data": { ... } } ] } ] }
 * Units: Temperature (F), Wind Speed (mph), Pressure (inHg), Rainfall (inches)
 */
function getMockWeatherLinkResponse() {
    $timestamp = time();
    return json_encode([
        'station_id' => 374964,
        'sensors' => [
            [
                'lsid' => 5271270,
                'data' => [
                    [
                        'ts' => $timestamp,
                        'data' => [
                            'temp' => 42.8,  // Fahrenheit
                            'hum' => 93,  // Percentage
                            'dew_point' => 40.3,  // Fahrenheit
                            'wind_chill' => 42.0,  // Fahrenheit (calculated)
                            'thw_index' => 42.5,  // Temperature-Humidity-Wind index
                            'thsw_index' => 43.0,  // Temperature-Humidity-Solar-Wind index
                            'wet_bulb' => 41.5,  // Fahrenheit
                            'heat_index' => 43.2,  // Fahrenheit
                            'wind_speed_last' => 5.0,  // mph
                            'wind_dir_last' => 89,  // degrees
                            'wind_speed_avg_last_1_min' => 4.8,  // mph
                            'wind_dir_scalar_avg_last_1_min' => 90,  // degrees
                            'wind_speed_avg_last_10_min' => 4.5,  // mph
                            'wind_dir_scalar_avg_last_10_min' => 88,  // degrees
                            'wind_speed_hi_last_2_min' => 7.0,  // mph (gust)
                            'wind_dir_at_hi_speed_last_2_min' => 88,  // degrees
                            'wind_speed_hi_last_10_min' => 6.5,  // mph
                            'rainfall_daily_in' => 0.47,  // inches
                            'rainfall_daily_mm' => 11.9,  // millimeters
                            'rainfall_last_60_min_in' => 0.0,  // inches
                            'rainfall_last_60_min_mm' => 0.0,  // millimeters
                            'bar_sea_level' => 30.08,  // inHg
                            'bar_trend' => 0.02,  // inHg
                            'bar_absolute' => 30.05  // inHg
                        ]
                    ]
                ]
            ]
        ]
    ]);
}

