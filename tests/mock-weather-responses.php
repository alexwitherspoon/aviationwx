<?php
/**
 * Mock Weather API Responses for Testing
 * This file provides mock responses for weather APIs to avoid requiring real API keys
 */

/**
 * Get a mock Tempest API response
 * 
 * Units: Temperature (C), Pressure (mb), Wind Speed (m/s), Precipitation (mm)
 * Note: All mock APIs use consistent values for cross-source testing
 */
function getMockTempestResponse() {
    return json_encode([
        'status' => [
            'status_code' => 0,
            'status_message' => 'OK'
        ],
        'obs' => [[
            'timestamp' => time(),
            'air_temperature' => 5.6,  // Celsius
            'relative_humidity' => 93,  // Percentage
            'sea_level_pressure' => 1019.2,  // mb (converts to ~30.08 inHg)
            'wind_avg' => 2.5,  // m/s (converts to ~4.9 knots)
            'wind_direction' => 89,  // Degrees
            'wind_gust' => 3.2,  // m/s (converts to ~6.2 knots)
            'precip_accum_local_day_final' => 11.94,  // mm (0.47 inches) - consistent with other mocks
            'dew_point' => 4.6  // Celsius
        ]]
    ]);
}

/**
 * Get a mock Ambient Weather API response
 * 
 * Units: Temperature (F), Pressure (inHg), Wind Speed (mph), Precipitation (inches)
 * Note: All mock APIs use consistent values for cross-source testing
 */
function getMockAmbientResponse() {
    return json_encode([[
        'macAddress' => 'AA:BB:CC:DD:EE:FF',
        'lastData' => [
            'dateutc' => time() * 1000,  // Milliseconds
            'tempf' => 42.8,  // Fahrenheit (converts to ~6.0°C)
            'humidity' => 93,  // Percentage
            'baromrelin' => 30.08,  // inHg
            'windspeedmph' => 5,  // mph (converts to ~4.3 knots)
            'winddir' => 89,  // Degrees
            'windgustmph' => 7,  // mph (converts to ~6.1 knots)
            'dailyrainin' => 0.47,  // inches (11.94 mm) - consistent with other mocks
            'dewPoint' => 40.3  // Fahrenheit (converts to ~4.6°C)
        ]
    ]]);
}

/**
 * Get a mock METAR API response
 * 
 * Units: Temperature (C), Pressure (inHg), Wind Speed (knots), Visibility (SM)
 * Note: METAR is a different source (aviation weather service) so wind direction
 * may differ from personal weather stations, but other values are consistent.
 */
function getMockMETARResponse() {
    return json_encode([[
        'rawOb' => 'KSPB 261654Z AUTO 18005KT 10SM CLR 06/04 A3012',
        'obsTime' => '2025-01-26T16:54:00Z',
        'temp' => 6.0,  // Celsius (consistent with other mocks)
        'dewp' => 4.0,  // Celsius (consistent with other mocks)
        'wdir' => 180,  // Degrees (different from PWS sources - this is acceptable)
        'wspd' => 5,  // Knots (consistent with other mocks)
        'visib' => '10',  // Statute miles
        'altim' => 30.12,  // inHg (consistent with other mocks)
        'clouds' => [
            ['cover' => 'CLR', 'base' => null]
        ]
    ]]);
}

/**
 * Get a mock WeatherLink v2 API response
 * Based on actual API structure from documentation and sample responses
 * WeatherLink uses: { "station_id": ..., "sensors": [ { "lsid": ..., "data": [ { "ts": ..., "data": { ... } } ] } ] }
 * 
 * Units: Temperature (F), Wind Speed (mph), Pressure (inHg), Rainfall (inches)
 * Note: All mock APIs use consistent values for cross-source testing
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
                            'temp' => 42.8,  // Fahrenheit (converts to ~6.0°C) - consistent with other mocks
                            'hum' => 93,  // Percentage - consistent with other mocks
                            'dew_point' => 40.3,  // Fahrenheit (converts to ~4.6°C) - consistent with other mocks
                            'wind_chill' => 42.0,  // Fahrenheit (calculated)
                            'thw_index' => 42.5,  // Temperature-Humidity-Wind index
                            'thsw_index' => 43.0,  // Temperature-Humidity-Solar-Wind index
                            'wet_bulb' => 41.5,  // Fahrenheit
                            'heat_index' => 43.2,  // Fahrenheit
                            'wind_speed_last' => 5.0,  // mph (converts to ~4.3 knots) - consistent with other mocks
                            'wind_dir_last' => 89,  // degrees - consistent with other mocks
                            'wind_speed_avg_last_1_min' => 4.8,  // mph
                            'wind_dir_scalar_avg_last_1_min' => 90,  // degrees
                            'wind_speed_avg_last_10_min' => 4.5,  // mph
                            'wind_dir_scalar_avg_last_10_min' => 88,  // degrees
                            'wind_speed_hi_last_2_min' => 7.0,  // mph (gust, converts to ~6.1 knots) - consistent with other mocks
                            'wind_dir_at_hi_speed_last_2_min' => 88,  // degrees
                            'wind_speed_hi_last_10_min' => 6.5,  // mph
                            'rainfall_daily_in' => 0.47,  // inches (11.94 mm) - consistent with other mocks
                            'rainfall_daily_mm' => 11.94,  // millimeters (0.47 inches) - consistent with other mocks
                            'rainfall_last_60_min_in' => 0.0,  // inches
                            'rainfall_last_60_min_mm' => 0.0,  // millimeters
                            'bar_sea_level' => 30.08,  // inHg - consistent with other mocks
                            'bar_trend' => 0.02,  // inHg
                            'bar_absolute' => 30.05  // inHg
                        ]
                    ]
                ]
            ]
        ]
    ]);
}

/**
 * Get a mock AerisWeather API response (for PWSWeather.com stations)
 * Based on actual API structure from documentation and sample responses
 * AerisWeather uses: { "success": true, "response": { "periods": [ { "ob": { ... } } ] } }
 * 
 * Units: Temperature (C), Wind Speed (knots), Pressure (inHg), Precipitation (inches), Visibility (miles)
 * Note: All mock APIs use consistent values for cross-source testing
 */
function getMockPWSWeatherResponse() {
    $timestamp = time();
    return json_encode([
        'success' => true,
        'error' => null,
        'response' => [
            'id' => 'KMAHANOV10',
            'loc' => [
                'long' => -122.8618333,
                'lat' => 45.7710278
            ],
            'place' => [
                'name' => 'Scappoose',
                'state' => 'OR',
                'country' => 'US'
            ],
            'periods' => [
                [
                    'ob' => [
                        'timestamp' => $timestamp,
                        'dateTimeISO' => date('c', $timestamp),
                        'tempC' => 5.6,  // Celsius - consistent with other mocks (~42.8°F)
                        'tempF' => 42.08,  // Fahrenheit
                        'dewpointC' => 4.6,  // Celsius - consistent with other mocks
                        'dewpointF' => 40.28,  // Fahrenheit
                        'humidity' => 93,  // Percentage - consistent with other mocks
                        'pressureMB' => 1019.2,  // Millibars
                        'pressureIN' => 30.08,  // inHg - consistent with other mocks
                        'windKTS' => 5,  // Knots - consistent with other mocks
                        'windKPH' => 9,  // Kilometers per hour
                        'windMPH' => 6,  // Miles per hour
                        'windSpeedKTS' => 5,  // Knots - consistent with other mocks
                        'windSpeedKPH' => 9,  // Kilometers per hour
                        'windSpeedMPH' => 6,  // Miles per hour
                        'windDirDEG' => 89,  // Degrees - consistent with other mocks
                        'windDir' => 'E',  // Cardinal direction
                        'weather' => 'Clear',
                        'weatherShort' => 'Clear',
                        'weatherCoded' => 'CL',
                        'icon' => 'clear.png',
                        'visibilityKM' => 16.1,  // Kilometers
                        'visibilityMI' => 10.0,  // Statute miles
                        'sky' => 0,  // Sky cover (0-8 scale)
                        'precipMM' => 11.94,  // Millimeters
                        'precipIN' => 0.47,  // Inches - consistent with other mocks
                        'solradWM2' => 0,  // Solar radiation
                        'solradMethod' => 'estimated'
                    ]
                ]
            ]
        ]
    ]);
}

