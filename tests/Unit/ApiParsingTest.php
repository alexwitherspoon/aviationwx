<?php
/**
 * Unit Tests for API Response Parsing Functions
 * 
 * Tests parsing of Tempest, Ambient Weather, and METAR API responses
 */

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../api/weather.php';
require_once __DIR__ . '/../mock-weather-responses.php';

class ApiParsingTest extends TestCase
{
    /**
     * Test parseTempestResponse - Valid response with all fields
     */
    public function testParseTempestResponse_ValidCompleteResponse()
    {
        // parseTempestResponse expects a JSON string, not an array
        $response = getMockTempestResponse();
        $result = parseTempestResponse($response);
        
        $this->assertIsArray($result);
        $this->assertArrayHasKey('temperature', $result);
        $this->assertArrayHasKey('humidity', $result);
        $this->assertArrayHasKey('pressure', $result);
        $this->assertArrayHasKey('wind_speed', $result);
        $this->assertArrayHasKey('wind_direction', $result);
        $this->assertArrayHasKey('gust_speed', $result);
        $this->assertArrayHasKey('precip_accum', $result);
        $this->assertArrayHasKey('dewpoint', $result);
        
        // Verify values are reasonable (humidity can be int or float)
        $this->assertIsFloat($result['temperature']);
        $this->assertIsNumeric($result['humidity']);
        $this->assertIsFloat($result['pressure']);
        $this->assertIsInt($result['wind_speed']);
    }
    
    /**
     * Test parseTempestResponse - Missing optional fields
     */
    public function testParseTempestResponse_MissingOptionalFields()
    {
        // parseTempestResponse expects a JSON string
        $response = json_encode([
            'status' => ['status_code' => 0],
            'obs' => [[
                'timestamp' => time(),
                'air_temperature' => 15.0,
                'relative_humidity' => 70.0,
                'sea_level_pressure' => 1019.2,
                // Missing wind_gust, precip_accum
            ]]
        ]);
        
        $result = parseTempestResponse($response);
        
        $this->assertIsArray($result);
        $this->assertEquals(15.0, $result['temperature']);
        // Missing fields should have null or default values
    }
    
    /**
     * Test parseTempestResponse - Empty obs array
     */
    public function testParseTempestResponse_EmptyObsArray()
    {
        $response = json_encode([
            'status' => ['status_code' => 0],
            'obs' => []
        ]);
        
        $result = parseTempestResponse($response);
        $this->assertNull($result);
    }
    
    /**
     * Test parseTempestResponse - Null response
     */
    public function testParseTempestResponse_NullResponse()
    {
        $result = parseTempestResponse(null);
        $this->assertNull($result);
    }
    
    /**
     * Test parseTempestResponse - Invalid structure
     */
    public function testParseTempestResponse_InvalidStructure()
    {
        $response = json_encode(['invalid' => 'structure']);
        $result = parseTempestResponse($response);
        $this->assertNull($result);
    }
    
    /**
     * Test parseTempestResponse - Invalid JSON
     */
    public function testParseTempestResponse_InvalidJson()
    {
        $response = 'invalid json string';
        $result = parseTempestResponse($response);
        $this->assertNull($result);
    }
    
    /**
     * Test parseTempestResponse - Pressure conversion (mb to inHg)
     */
    public function testParseTempestResponse_PressureConversion()
    {
        $response = json_encode([
            'status' => ['status_code' => 0],
            'obs' => [[
                'timestamp' => time(),
                'air_temperature' => 15.0,
                'relative_humidity' => 70.0,
                'sea_level_pressure' => 1013.25, // Standard atmospheric pressure in mb
                'wind_avg' => 0,
                'wind_direction' => 0,
                'wind_gust' => 0,
                'precip_accum_local_day_final' => 0,
                'dew_point' => 10.0
            ]]
        ]);
        
        $result = parseTempestResponse($response);
        
        // 1013.25 mb = 29.92 inHg (standard atmospheric pressure)
        $this->assertIsFloat($result['pressure']);
        $this->assertEqualsWithDelta(29.92, $result['pressure'], 0.1);
    }
    
    /**
     * Test parseTempestResponse - Wind speed conversion (m/s to knots)
     */
    public function testParseTempestResponse_WindSpeedConversion()
    {
        $response = json_encode([
            'status' => ['status_code' => 0],
            'obs' => [[
                'timestamp' => time(),
                'air_temperature' => 15.0,
                'relative_humidity' => 70.0,
                'sea_level_pressure' => 1013.25,
                'wind_avg' => 5.144, // 10 knots in m/s (10 / 1.943844)
                'wind_direction' => 180,
                'wind_gust' => 7.716, // 15 knots in m/s
                'precip_accum_local_day_final' => 0,
                'dew_point' => 10.0
            ]]
        ]);
        
        $result = parseTempestResponse($response);
        
        // Should convert to knots (rounded)
        $this->assertIsInt($result['wind_speed']);
        $this->assertEqualsWithDelta(10, $result['wind_speed'], 1);
        $this->assertEqualsWithDelta(15, $result['gust_speed'], 1);
    }
    
    /**
     * Test parseAmbientResponse - Valid response with all fields
     */
    public function testParseAmbientResponse_ValidCompleteResponse()
    {
        // parseAmbientResponse expects a JSON string
        $response = getMockAmbientResponse();
        $result = parseAmbientResponse($response);
        
        $this->assertIsArray($result);
        $this->assertArrayHasKey('temperature', $result);
        $this->assertArrayHasKey('humidity', $result);
        $this->assertArrayHasKey('pressure', $result);
        $this->assertArrayHasKey('wind_speed', $result);
        $this->assertArrayHasKey('wind_direction', $result);
        $this->assertArrayHasKey('gust_speed', $result);
        $this->assertArrayHasKey('precip_accum', $result);
        $this->assertArrayHasKey('dewpoint', $result);
        
        // Verify temperature conversion (F to C)
        $this->assertIsFloat($result['temperature']);
    }
    
    /**
     * Test parseAmbientResponse - Missing optional fields
     */
    public function testParseAmbientResponse_MissingOptionalFields()
    {
        $response = json_encode([[
            'macAddress' => 'AA:BB:CC:DD:EE:FF',
            'lastData' => [
                'dateutc' => time() * 1000,
                'tempf' => 60.0,
                'humidity' => 70.0,
                'baromrelin' => 30.0,
                // Missing wind data
            ]
        ]]);
        
        $result = parseAmbientResponse($response);
        
        $this->assertIsArray($result);
        $this->assertNotNull($result['temperature']);
        // Missing wind fields should have null or default values
    }
    
    /**
     * Test parseAmbientResponse - Empty array
     */
    public function testParseAmbientResponse_EmptyArray()
    {
        $response = json_encode([]);
        $result = parseAmbientResponse($response);
        $this->assertNull($result);
    }
    
    /**
     * Test parseAmbientResponse - Temperature conversion (F to C)
     */
    public function testParseAmbientResponse_TemperatureConversion()
    {
        $response = json_encode([[
            'macAddress' => 'AA:BB:CC:DD:EE:FF',
            'lastData' => [
                'dateutc' => time() * 1000,
                'tempf' => 32.0, // Freezing point
                'humidity' => 70.0,
                'baromrelin' => 30.0,
                'windspeedmph' => 0,
                'winddir' => 0,
                'windgustmph' => 0,
                'dailyrainin' => 0,
                'dewPoint' => 32.0
            ]
        ]]);
        
        $result = parseAmbientResponse($response);
        
        // 32°F = 0°C
        $this->assertEqualsWithDelta(0.0, $result['temperature'], 0.1);
    }
    
    /**
     * Test parseAmbientResponse - Wind speed conversion (mph to knots)
     */
    public function testParseAmbientResponse_WindSpeedConversion()
    {
        $response = json_encode([[
            'macAddress' => 'AA:BB:CC:DD:EE:FF',
            'lastData' => [
                'dateutc' => time() * 1000,
                'tempf' => 60.0,
                'humidity' => 70.0,
                'baromrelin' => 30.0,
                'windspeedmph' => 11.51, // 10 knots in mph (10 / 0.868976)
                'winddir' => 180,
                'windgustmph' => 17.26, // 15 knots in mph
                'dailyrainin' => 0,
                'dewPoint' => 50.0
            ]
        ]]);
        
        $result = parseAmbientResponse($response);
        
        // Should convert to knots (rounded)
        $this->assertIsInt($result['wind_speed']);
        $this->assertEqualsWithDelta(10, $result['wind_speed'], 1);
        $this->assertEqualsWithDelta(15, $result['gust_speed'], 1);
    }
    
    /**
     * Test parseMETARResponse - Valid response with all fields
     */
    public function testParseMETARResponse_ValidCompleteResponse()
    {
        // parseMETARResponse expects a JSON string
        $response = getMockMETARResponse();
        $airport = createTestAirport(['metar_station' => 'KSPB']);
        $result = parseMETARResponse($response, $airport);
        
        $this->assertIsArray($result);
        $this->assertArrayHasKey('temperature', $result);
        $this->assertArrayHasKey('humidity', $result);
        $this->assertArrayHasKey('pressure', $result);
        $this->assertArrayHasKey('wind_speed', $result);
        $this->assertArrayHasKey('wind_direction', $result);
        $this->assertArrayHasKey('visibility', $result);
        $this->assertArrayHasKey('dewpoint', $result);
    }
    
    /**
     * Test parseMETARResponse - Missing optional fields
     */
    public function testParseMETARResponse_MissingOptionalFields()
    {
        // parseMETARResponse expects a JSON string
        $response = json_encode([[
            'icaoId' => 'KSPB',
            'temp' => 15.0,
            'dewp' => 10.0,
            'altim' => 30.0,
            // Missing wind, visibility, ceiling
        ]]);
        
        $airport = createTestAirport(['metar_station' => 'KSPB']);
        $result = parseMETARResponse($response, $airport);
        
        $this->assertIsArray($result);
        $this->assertEquals(15.0, $result['temperature']);
        // Missing fields should be null
        $this->assertNull($result['wind_speed']);
        $this->assertNull($result['visibility']);
    }
    
    /**
     * Test parseMETARResponse - Empty array
     */
    public function testParseMETARResponse_EmptyArray()
    {
        $response = json_encode([]);
        $airport = createTestAirport(['metar_station' => 'KSPB']);
        $result = parseMETARResponse($response, $airport);
        $this->assertNull($result);
    }
    
    /**
     * Test parseMETARResponse - Invalid JSON
     */
    public function testParseMETARResponse_InvalidJson()
    {
        $response = 'invalid json string';
        $airport = createTestAirport(['metar_station' => 'KSPB']);
        $result = parseMETARResponse($response, $airport);
        $this->assertNull($result);
    }
    
    /**
     * Test parseMETARResponse - Ceiling calculations
     */
    public function testParseMETARResponse_CeilingCalculations()
    {
        // Test BKN clouds - should set ceiling
        $responseBkn = json_encode([[
            'icaoId' => 'KSPB',
            'temp' => 15.0,
            'dewp' => 10.0,
            'altim' => 30.0,
            'wdir' => 180,
            'wspd' => 10,
            'visib' => 10.0,
            'clouds' => [
                ['cover' => 'BKN', 'base' => 1200] // Broken at 1200 ft
            ]
        ]]);
        
        $airport = createTestAirport(['metar_station' => 'KSPB', 'elevation_ft' => 100]);
        $resultBkn = parseMETARResponse($responseBkn, $airport);
        
        $this->assertIsArray($resultBkn);
        $this->assertEquals(1200, $resultBkn['ceiling'], 'BKN clouds should set ceiling to base value');
        $this->assertEquals('BKN', $resultBkn['cloud_cover'], 'Cloud cover should be BKN');
        
        // Test OVC clouds - should set ceiling
        $responseOvc = json_encode([[
            'icaoId' => 'KSPB',
            'temp' => 15.0,
            'dewp' => 10.0,
            'altim' => 30.0,
            'wdir' => 180,
            'wspd' => 10,
            'visib' => 10.0,
            'clouds' => [
                ['cover' => 'OVC', 'base' => 2000] // Overcast at 2000 ft
            ]
        ]]);
        
        $resultOvc = parseMETARResponse($responseOvc, $airport);
        
        $this->assertEquals(2000, $resultOvc['ceiling'], 'OVC clouds should set ceiling to base value');
        $this->assertEquals('OVC', $resultOvc['cloud_cover'], 'Cloud cover should be OVC');
    }
    
    /**
     * Test parseMETARResponse - Cloud cover parsing
     */
    public function testParseMETARResponse_CloudCover()
    {
        $testCases = [
            ['cover' => 'SCT', 'expected_cover' => 'SCT', 'expected_ceiling' => null], // SCT should not set ceiling
            ['cover' => 'BKN', 'expected_cover' => 'BKN', 'expected_ceiling' => 2000], // BKN should set ceiling
            ['cover' => 'OVC', 'expected_cover' => 'OVC', 'expected_ceiling' => 2000], // OVC should set ceiling
            ['cover' => 'CLR', 'expected_cover' => null, 'expected_ceiling' => null], // Clear sky
        ];
        
        foreach ($testCases as $testCase) {
            $response = json_encode([[
                'icaoId' => 'KSPB',
                'temp' => 15.0,
                'dewp' => 10.0,
                'altim' => 30.0,
                'clouds' => [['cover' => $testCase['cover'], 'base' => 2000]]
            ]]);
            
            $airport = createTestAirport(['metar_station' => 'KSPB']);
            $result = parseMETARResponse($response, $airport);
            
            if ($testCase['expected_cover'] === null) {
                $this->assertNull($result['cloud_cover'], "Cloud cover should be null for {$testCase['cover']}");
            } else {
                $this->assertEquals($testCase['expected_cover'], $result['cloud_cover'], "Cloud cover should be {$testCase['expected_cover']} for {$testCase['cover']}");
            }
            
            if ($testCase['expected_ceiling'] === null) {
                $this->assertNull($result['ceiling'], "Ceiling should be null (unlimited) for {$testCase['cover']}");
            } else {
                $this->assertEquals($testCase['expected_ceiling'], $result['ceiling'], "Ceiling should be {$testCase['expected_ceiling']} for {$testCase['cover']}");
            }
        }
    }
    
    /**
     * Test parseMETARResponse - Humidity calculation from temp and dewpoint
     */
    public function testParseMETARResponse_HumidityCalculation()
    {
        $response = json_encode([[
            'icaoId' => 'KSPB',
            'temp' => 20.0,  // 20°C
            'dewp' => 20.0,  // Dewpoint = temp means 100% humidity
            'altim' => 30.0,
            'wdir' => 180,
            'wspd' => 10,
            'visib' => 10.0
        ]]);
        
        $airport = createTestAirport(['metar_station' => 'KSPB']);
        $result = parseMETARResponse($response, $airport);
        
        // When temp = dewpoint, humidity should be 100% (or very close due to rounding)
        $this->assertNotNull($result['humidity']);
        $this->assertGreaterThanOrEqual(99, $result['humidity']); // Allow for rounding
    }
    
    /**
     * Test parseMETARResponse - Visibility parsing
     */
    public function testParseMETARResponse_VisibilityParsing()
    {
        $response = json_encode([[
            'icaoId' => 'KSPB',
            'temp' => 15.0,
            'dewp' => 10.0,
            'altim' => 30.0,
            'wdir' => 180,
            'wspd' => 10,
            'visib' => 5.5  // 5.5 statute miles
        ]]);
        
        $airport = createTestAirport(['metar_station' => 'KSPB']);
        $result = parseMETARResponse($response, $airport);
        
        $this->assertEquals(5.5, $result['visibility']);
    }
    
    /**
     * Test parseMETARResponse - Wind direction parsing
     */
    public function testParseMETARResponse_WindDirection()
    {
        $response = json_encode([[
            'icaoId' => 'KSPB',
            'temp' => 15.0,
            'dewp' => 10.0,
            'altim' => 30.0,
            'wdir' => 270,  // West
            'wspd' => 10,
            'visib' => 10.0
        ]]);
        
        $airport = createTestAirport(['metar_station' => 'KSPB']);
        $result = parseMETARResponse($response, $airport);
        
        $this->assertEquals(270, $result['wind_direction']);
    }
    
    /**
     * Test parseMETARResponse - Precipitation parsing
     */
    public function testParseMETARResponse_Precipitation()
    {
        $response = json_encode([[
            'icaoId' => 'KSPB',
            'temp' => 15.0,
            'dewp' => 10.0,
            'altim' => 30.0,
            'wdir' => 180,
            'wspd' => 10,
            'visib' => 10.0,
            'pcp24hr' => 0.25  // 0.25 inches
        ]]);
        
        $airport = createTestAirport(['metar_station' => 'KSPB']);
        $result = parseMETARResponse($response, $airport);
        
        $this->assertEquals(0.25, $result['precip_accum']);
    }
    
    /**
     * Test parseMETARResponse - Null/Missing visibility
     */
    public function testParseMETARResponse_MissingVisibility()
    {
        $response = json_encode([[
            'icaoId' => 'KSPB',
            'temp' => 15.0,
            'dewp' => 10.0,
            'altim' => 30.0,
            // Missing visibility
        ]]);
        
        $airport = createTestAirport(['metar_station' => 'KSPB']);
        $result = parseMETARResponse($response, $airport);
        
        $this->assertNull($result['visibility']);
    }
    
    /**
     * Test parseMETARResponse - Unlimited ceiling (no clouds)
     */
    public function testParseMETARResponse_UnlimitedCeiling()
    {
        $response = json_encode([[
            'icaoId' => 'KSPB',
            'temp' => 15.0,
            'dewp' => 10.0,
            'altim' => 30.0,
            'wdir' => 180,
            'wspd' => 10,
            'visib' => 10.0,
            // No clouds array = unlimited ceiling
        ]]);
        
        $airport = createTestAirport(['metar_station' => 'KSPB']);
        $result = parseMETARResponse($response, $airport);
        
        $this->assertNull($result['ceiling']); // Unlimited ceiling
    }
    
    /**
     * Test parseMETARResponse - FEW/SCT clouds should not set ceiling (unlimited)
     */
    public function testParseMETARResponse_FewScatteredClouds_NoCeiling()
    {
        // Test FEW clouds - should not set ceiling
        $responseFew = json_encode([[
            'icaoId' => 'KSPB',
            'temp' => 15.0,
            'dewp' => 10.0,
            'altim' => 30.0,
            'wdir' => 180,
            'wspd' => 10,
            'visib' => 10.0,
            'clouds' => [
                ['cover' => 'FEW', 'base' => 200] // Few clouds at 200ft - should NOT set ceiling
            ]
        ]]);
        
        $airport = createTestAirport(['metar_station' => 'KSPB']);
        $resultFew = parseMETARResponse($responseFew, $airport);
        
        $this->assertNull($resultFew['ceiling'], 'FEW clouds should not set ceiling (unlimited)');
        $this->assertEquals('FEW', $resultFew['cloud_cover'], 'Cloud cover should still be set');
        
        // Test SCT clouds - should not set ceiling
        $responseSct = json_encode([[
            'icaoId' => 'KSPB',
            'temp' => 15.0,
            'dewp' => 10.0,
            'altim' => 30.0,
            'wdir' => 180,
            'wspd' => 10,
            'visib' => 10.0,
            'clouds' => [
                ['cover' => 'SCT', 'base' => 200] // Scattered clouds at 200ft - should NOT set ceiling
            ]
        ]]);
        
        $resultSct = parseMETARResponse($responseSct, $airport);
        
        $this->assertNull($resultSct['ceiling'], 'SCT clouds should not set ceiling (unlimited)');
        $this->assertEquals('SCT', $resultSct['cloud_cover'], 'Cloud cover should still be set');
    }
    
    /**
     * Test parseWeatherLinkResponse - Valid response with all fields
     */
    public function testParseWeatherLinkResponse_ValidCompleteResponse()
    {
        $response = getMockWeatherLinkResponse();
        $result = parseWeatherLinkResponse($response);
        
        $this->assertIsArray($result);
        $this->assertArrayHasKey('temperature', $result);
        $this->assertArrayHasKey('humidity', $result);
        $this->assertArrayHasKey('pressure', $result);
        $this->assertArrayHasKey('wind_speed', $result);
        $this->assertArrayHasKey('wind_direction', $result);
        $this->assertArrayHasKey('gust_speed', $result);
        $this->assertArrayHasKey('precip_accum', $result);
        $this->assertArrayHasKey('dewpoint', $result);
        $this->assertArrayHasKey('obs_time', $result);
        
        // Verify values are reasonable
        $this->assertIsFloat($result['temperature']);
        $this->assertIsNumeric($result['humidity']);
        $this->assertIsFloat($result['pressure']);
        $this->assertIsInt($result['wind_speed']);
        $this->assertIsInt($result['wind_direction']);
        $this->assertNotNull($result['obs_time']);
    }
    
    /**
     * Test parseWeatherLinkResponse - Temperature conversion (F to C)
     */
    public function testParseWeatherLinkResponse_TemperatureConversion()
    {
        $timestamp = time();
        $response = json_encode([
            'station_id' => 12345,
            'sensors' => [[
                'lsid' => 12345,
                'data' => [[
                    'ts' => $timestamp,
                    'data' => [
                        'temp' => 32.0, // Freezing point in Fahrenheit
                        'hum' => 70.0,
                        'bar_sea_level' => 30.0,
                        'wind_speed_last' => 0,
                        'wind_dir_last' => 0,
                        'wind_speed_hi_last_2_min' => 0,
                        'rainfall_daily_in' => 0
                    ]
                ]]
            ]]
        ]);
        
        $result = parseWeatherLinkResponse($response);
        
        // 32°F = 0°C
        $this->assertEqualsWithDelta(0.0, $result['temperature'], 0.1);
    }
    
    /**
     * Test parseWeatherLinkResponse - Wind speed conversion (mph to knots)
     */
    public function testParseWeatherLinkResponse_WindSpeedConversion()
    {
        $timestamp = time();
        $response = json_encode([
            'station_id' => 12345,
            'sensors' => [[
                'lsid' => 12345,
                'data' => [[
                    'ts' => $timestamp,
                    'data' => [
                        'temp' => 70.0,
                        'hum' => 50.0,
                        'bar_sea_level' => 30.0,
                        'wind_speed_last' => 10.0, // 10 mph
                        'wind_dir_last' => 180,
                        'wind_speed_hi_last_2_min' => 15.0, // 15 mph
                        'rainfall_daily_in' => 0
                    ]
                ]]
            ]]
        ]);
        
        $result = parseWeatherLinkResponse($response);
        
        // 10 mph ≈ 8.69 knots
        $this->assertEqualsWithDelta(9, $result['wind_speed'], 1);
        // 15 mph ≈ 13.03 knots
        $this->assertEqualsWithDelta(13, $result['gust_speed'], 1);
    }
    
    /**
     * Test parseWeatherLinkResponse - Missing optional fields
     */
    public function testParseWeatherLinkResponse_MissingOptionalFields()
    {
        $timestamp = time();
        $response = json_encode([
            'station_id' => 12345,
            'sensors' => [[
                'lsid' => 12345,
                'data' => [[
                    'ts' => $timestamp,
                    'data' => [
                        'temp' => 70.0,
                        'hum' => 50.0,
                        'bar_sea_level' => 30.0,
                        // Missing wind, precip fields
                    ]
                ]]
            ]]
        ]);
        
        $result = parseWeatherLinkResponse($response);
        
        $this->assertIsArray($result);
        $this->assertNotNull($result['temperature']);
        // Missing fields should have null values
        $this->assertNull($result['wind_speed']);
        $this->assertNull($result['precip_accum']);
    }
    
    /**
     * Test parseWeatherLinkResponse - Empty sensors array
     */
    public function testParseWeatherLinkResponse_EmptySensorsArray()
    {
        $response = json_encode([
            'station_id' => 12345,
            'sensors' => []
        ]);
        
        $result = parseWeatherLinkResponse($response);
        $this->assertIsArray($result);
        $this->assertNull($result['temperature']);
    }
    
    /**
     * Test parseWeatherLinkResponse - Missing sensors key
     */
    public function testParseWeatherLinkResponse_MissingSensorsKey()
    {
        $response = json_encode([
            'station_id' => 12345
        ]);
        
        $result = parseWeatherLinkResponse($response);
        $this->assertNull($result);
    }
    
    /**
     * Test parseWeatherLinkResponse - Invalid JSON
     */
    public function testParseWeatherLinkResponse_InvalidJson()
    {
        $response = 'invalid json';
        $result = parseWeatherLinkResponse($response);
        $this->assertNull($result);
    }
    
    /**
     * Test parseWeatherLinkResponse - Null response
     */
    public function testParseWeatherLinkResponse_NullResponse()
    {
        $result = parseWeatherLinkResponse(null);
        $this->assertNull($result);
    }
    
    /**
     * Test parseWeatherLinkResponse - Pressure in inHg (no conversion needed)
     */
    public function testParseWeatherLinkResponse_PressureInInHg()
    {
        $timestamp = time();
        $response = json_encode([
            'station_id' => 12345,
            'sensors' => [[
                'lsid' => 12345,
                'data' => [[
                    'ts' => $timestamp,
                    'data' => [
                        'temp' => 70.0,
                        'hum' => 50.0,
                        'bar_sea_level' => 30.12, // inHg
                        'wind_speed_last' => 5.0,
                        'wind_dir_last' => 180,
                        'rainfall_daily_in' => 0
                    ]
                ]]
            ]]
        ]);
        
        $result = parseWeatherLinkResponse($response);
        
        // Pressure should be in inHg (no conversion)
        $this->assertEqualsWithDelta(30.12, $result['pressure'], 0.01);
    }
    
    /**
     * Test parseWeatherLinkResponse - Rainfall in inches
     */
    public function testParseWeatherLinkResponse_RainfallInInches()
    {
        $timestamp = time();
        $response = json_encode([
            'station_id' => 12345,
            'sensors' => [[
                'lsid' => 12345,
                'data' => [[
                    'ts' => $timestamp,
                    'data' => [
                        'temp' => 70.0,
                        'hum' => 50.0,
                        'bar_sea_level' => 30.0,
                        'wind_speed_last' => 5.0,
                        'wind_dir_last' => 180,
                        'rainfall_daily_in' => 0.5 // 0.5 inches
                    ]
                ]]
            ]]
        ]);
        
        $result = parseWeatherLinkResponse($response);
        
        // Rainfall should be in inches (no conversion)
        $this->assertEqualsWithDelta(0.5, $result['precip_accum'], 0.01);
    }
}

