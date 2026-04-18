<?php
/**
 * Mock Weather API Responses for Testing
 *
 * JSON fixtures for tests (many responses use `time()` for observation freshness). For WeatherFlow
 * (`swd.weatherflow.com`), `lib/test-mocks.php` selects the fixture by URL path: federated station observation,
 * `/rest/stations/`, or `/observations/device/`.
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
 * Mock GET /swd/rest/stations/{id} (hub + ST) for tests that exercise Tempest device fallback.
 *
 * @return string JSON
 */
function getMockTempestStationsMetadataResponse(): string {
    return json_encode([
        'status' => [
            'status_code' => 0,
            'status_message' => 'SUCCESS',
        ],
        'stations' => [
            [
                'station_id' => 123456,
                'devices' => [
                    ['device_id' => 900001, 'device_type' => 'HB', 'serial_number' => 'HB-MOCK'],
                    ['device_id' => 900002, 'device_type' => 'ST', 'serial_number' => 'ST-MOCK'],
                ],
            ],
        ],
    ], JSON_THROW_ON_ERROR);
}

/**
 * Mock GET /swd/rest/observations/device/{id} (obs_st numeric row) aligned with getMockTempestResponse().
 *
 * @return string JSON
 */
function getMockTempestDeviceObsStResponse(): string {
    // Fixed epoch so obs_st mock is stable across runs (station mock still uses time() for freshness).
    $epoch = 1700000000;
    $row = array_fill(0, 22, 0);
    $row[0] = $epoch;
    $row[2] = 2.5;
    $row[3] = 3.2;
    $row[4] = 89;
    $row[6] = 1019.2;
    $row[7] = 5.6;
    $row[8] = 93;
    $row[18] = 11.94;
    return json_encode([
        'status' => [
            'status_code' => 0,
            'status_message' => 'OK',
        ],
        'device_id' => 900002,
        'type' => 'obs_st',
        'obs' => [$row],
    ], JSON_THROW_ON_ERROR);
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
 * Get a mock AWOSnet XML response (awiAwosNet.php format)
 *
 * Structured XML with METAR and individual fields. Invalid values (///, \\, ###, ***) normalize to null.
 *
 * @return string XML content
 */
function getMockAwosnetResponse() {
    return '<?xml version="1.0" encoding="UTF-8"?>
<awosnet>
<airportIdentifier value="S40" time="1771001654"></airportIdentifier>
<airTemperature value="5" time="1771001654"></airTemperature>
<altimeterSetting value="30.03" time="1771001654"></altimeterSetting>
<dewPoint value="5" time="1771001654"></dewPoint>
<METAR value="METAR S40 132345Z AUTO 09007KT 10SM CLR 05/05 A3003 RMK A01" time="1771001654"></METAR>
<relativeHumidity value="100" time="1771001654"></relativeHumidity>
<tenMinutevisibility value="10" time="1771001654"></tenMinutevisibility>
<twoMinutewindDirection value="90" time="1771001654"></twoMinutewindDirection>
<twoMinutewindSpeed value="7" time="1771001654"></twoMinutewindSpeed>
</awosnet>';
}

/**
 * Get a mock AWOSnet XML response with no valid data (///)
 *
 * @return string XML content
 */
function getMockAwosnetResponseNoData() {
    return '<?xml version="1.0" encoding="UTF-8"?>
<awosnet>
<airTemperature value="///" time="1771001654"></airTemperature>
<dewPoint value="///"></dewPoint>
<METAR value="///"></METAR>
</awosnet>';
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
        'altim' => 1020.0,  // hPa (hectopascals) - API returns in hPa, converts to ~30.12 inHg (1020.0 / 33.8639 ≈ 30.12)
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
 * Based on actual API structure: { "success": true, "response": { "id": "...", "ob": { ... } } }
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
            'id' => 'PWS_TESTSTATION',
            'dataSource' => 'PWS',
            'loc' => [
                'long' => -122.8618333,
                'lat' => 45.7710278
            ],
            'place' => [
                'name' => 'Scappoose',
                'state' => 'or',
                'country' => 'us'
            ],
            'profile' => [
                'tz' => 'America/Los_Angeles',
                'elevFT' => 58
            ],
            'obTimestamp' => $timestamp,
            'obDateTime' => date('c', $timestamp),
            'ob' => [
                'type' => 'station',
                'timestamp' => $timestamp,
                'dateTimeISO' => date('c', $timestamp),
                'tempC' => 5.6,
                'tempF' => 42.08,
                'dewpointC' => 4.6,
                'dewpointF' => 40.28,
                'humidity' => 93,
                'pressureMB' => 1019.2,
                'pressureIN' => 30.08,
                'windKTS' => 5,
                'windSpeedKTS' => 5,
                'windGustKTS' => 7,
                'windDirDEG' => 89,
                'windDir' => 'E',
                'visibilityMI' => 10.0,
                'precipIN' => 0.47,
                'solradWM2' => 0
            ]
        ]
    ]);
}

/**
 * Get a mock NWS API (api.weather.gov) response
 * Based on actual API structure from api.weather.gov stations observations endpoint
 * NWS API uses: { "properties": { "temperature": { "value": ..., "unitCode": "wmoUnit:degC" }, ... } }
 * 
 * Units: Temperature (degC), Wind Speed (km/h), Pressure (Pa), Visibility (m)
 * Note: All mock APIs use consistent values for cross-source testing
 * Wind: 9.26 km/h = ~5 knots (consistent with other mocks)
 * Pressure: 101920 Pa = ~30.08 inHg (consistent with other mocks)
 */
function getMockNwsApiResponse() {
    $timestamp = date('c', time());
    
    return json_encode([
        'id' => 'https://api.weather.gov/stations/KSPB/observations/' . $timestamp,
        'type' => 'Feature',
        'geometry' => [
            'type' => 'Point',
            'coordinates' => [-122.86, 45.77]
        ],
        'properties' => [
            '@id' => 'https://api.weather.gov/stations/KSPB/observations/' . $timestamp,
            '@type' => 'wx:ObservationStation',
            'elevation' => [
                'unitCode' => 'wmoUnit:m',
                'value' => 17
            ],
            'station' => 'https://api.weather.gov/stations/KSPB',
            'stationId' => 'KSPB',
            'stationName' => 'Scappoose Industrial Airpark',
            'timestamp' => $timestamp,
            'rawMessage' => '',
            'textDescription' => 'Cloudy',
            'icon' => 'https://api.weather.gov/icons/land/day/ovc?size=medium',
            'presentWeather' => [],
            'temperature' => [
                'unitCode' => 'wmoUnit:degC',
                'value' => 5.6,  // Celsius - consistent with other mocks
                'qualityControl' => 'V'
            ],
            'dewpoint' => [
                'unitCode' => 'wmoUnit:degC',
                'value' => 4.6,  // Celsius - consistent with other mocks
                'qualityControl' => 'V'
            ],
            'windDirection' => [
                'unitCode' => 'wmoUnit:degree_(angle)',
                'value' => 89,  // Degrees - consistent with other mocks
                'qualityControl' => 'V'
            ],
            'windSpeed' => [
                'unitCode' => 'wmoUnit:km_h-1',
                'value' => 9.26,  // km/h (converts to ~5 knots) - consistent with other mocks
                'qualityControl' => 'V'
            ],
            'windGust' => [
                'unitCode' => 'wmoUnit:km_h-1',
                'value' => 12.96,  // km/h (converts to ~7 knots) - consistent with other mocks
                'qualityControl' => 'V'
            ],
            'barometricPressure' => [
                'unitCode' => 'wmoUnit:Pa',
                'value' => 101920,  // Pa (converts to ~30.08 inHg) - consistent with other mocks
                'qualityControl' => 'V'
            ],
            'seaLevelPressure' => [
                'unitCode' => 'wmoUnit:Pa',
                'value' => null,
                'qualityControl' => 'Z'
            ],
            'visibility' => [
                'unitCode' => 'wmoUnit:m',
                'value' => 16093.44,  // meters (converts to 10 SM) - consistent with other mocks
                'qualityControl' => 'V'
            ],
            'relativeHumidity' => [
                'unitCode' => 'wmoUnit:percent',
                'value' => 93,  // Percentage - consistent with other mocks
                'qualityControl' => 'V'
            ],
            'windChill' => [
                'unitCode' => 'wmoUnit:degC',
                'value' => 3.5,
                'qualityControl' => 'V'
            ],
            'heatIndex' => [
                'unitCode' => 'wmoUnit:degC',
                'value' => null,
                'qualityControl' => 'V'
            ],
            'cloudLayers' => [
                [
                    'base' => [
                        'unitCode' => 'wmoUnit:m',
                        'value' => 1524
                    ],
                    'amount' => 'OVC'
                ]
            ]
        ]
    ]);
}

/**
 * Get a mock SynopticData API response
 * Based on actual API structure from documentation and sample responses
 * SynopticData uses: { "SUMMARY": { "RESPONSE_CODE": 1 }, "STATION": [ { "OBSERVATIONS": { "field_value_1": { "value": ..., "date_time": ... } } } ] }
 * 
 * Units: Temperature (C), Wind Speed (m/s), Pressure (mb/hPa), Precipitation (mm), Altimeter (inHg)
 * Note: All mock APIs use consistent values for cross-source testing
 * Wind: 2.5 m/s = ~4.9 knots (consistent with other mocks)
 * Pressure: 1019.2 mb = ~30.08 inHg (consistent with other mocks)
 * Precipitation: 11.94 mm = 0.47 inches (consistent with other mocks)
 */
function getMockSynopticDataResponse() {
    $timestamp = time();
    $dateTime = date('c', $timestamp);
    
    return json_encode([
        'SUMMARY' => [
            'RESPONSE_CODE' => 1,
            'RESPONSE_MESSAGE' => 'OK',
            'NUMBER_OF_OBJECTS' => 1
        ],
        'STATION' => [[
            'STID' => 'AT297',
            'NAME' => 'Test Station',
            'ELEVATION' => '28.0',
            'LATITUDE' => '45.19683',
            'LONGITUDE' => '-123.96733',
            'STATUS' => 'ACTIVE',
            'OBSERVATIONS' => [
                'air_temp_value_1' => [
                    'value' => 5.6,  // Celsius - consistent with other mocks (~42.8°F)
                    'date_time' => $dateTime
                ],
                'relative_humidity_value_1' => [
                    'value' => 93.0,  // Percentage - consistent with other mocks
                    'date_time' => $dateTime
                ],
                'wind_speed_value_1' => [
                    'value' => 2.5,  // m/s (converts to ~4.9 knots) - consistent with other mocks
                    'date_time' => $dateTime
                ],
                'wind_direction_value_1' => [
                    'value' => 89.0,  // Degrees - consistent with other mocks
                    'date_time' => $dateTime
                ],
                'wind_gust_value_1' => [
                    'value' => 3.2,  // m/s (converts to ~6.2 knots) - consistent with other mocks
                    'date_time' => $dateTime
                ],
                'dew_point_temperature_value_1d' => [
                    'value' => 4.6,  // Celsius - consistent with other mocks
                    'date_time' => $dateTime
                ],
                'precip_accum_since_local_midnight_value_1' => [
                    'value' => 11.94,  // mm (converts to 0.47 inches) - consistent with other mocks
                    'date_time' => $dateTime
                ],
                'sea_level_pressure_value_1d' => [
                    'value' => 1019.2,  // mb/hPa (converts to ~30.08 inHg) - consistent with other mocks
                    'date_time' => $dateTime
                ]
            ]
        ]]
    ]);
}
