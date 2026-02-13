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
     * Test parseAmbientResponse - Single device object response (when MAC address specified)
     */
    public function testParseAmbientResponse_SingleDeviceObject()
    {
        // Single device object response (returned when querying specific device by MAC)
        $response = json_encode([
            'macAddress' => 'AA:BB:CC:DD:EE:FF',
            'lastData' => [
                'dateutc' => time() * 1000,
                'tempf' => 70.0,
                'humidity' => 65.0,
                'baromrelin' => 30.10,
                'windspeedmph' => 5.0,
                'winddir' => 270,
                'windgustmph' => 7.0,
                'dailyrainin' => 0.1,
                'dewPoint' => 60.0
            ]
        ]);
        
        $result = parseAmbientResponse($response);
        
        $this->assertIsArray($result);
        $this->assertArrayHasKey('temperature', $result);
        $this->assertArrayHasKey('humidity', $result);
        $this->assertNotNull($result['temperature']);
        $this->assertNotNull($result['humidity']);
        // Verify temperature conversion (70°F = ~21.1°C)
        $this->assertEqualsWithDelta(21.1, $result['temperature'], 0.5);
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
     * Test parseAwosnetResponse - Valid HTML with METAR
     */
    public function testParseAwosnetResponse_ValidCompleteResponse()
    {
        $response = getMockAwosnetResponse();
        $result = parseAwosnetResponse($response, []);
        
        $this->assertIsArray($result);
        $this->assertArrayHasKey('temperature', $result);
        $this->assertArrayHasKey('dewpoint', $result);
        $this->assertArrayHasKey('pressure', $result);
        $this->assertArrayHasKey('wind_speed', $result);
        $this->assertArrayHasKey('wind_direction', $result);
        $this->assertArrayHasKey('visibility', $result);
        $this->assertEquals(5, $result['temperature']);
        $this->assertEquals(5, $result['dewpoint']);
        $this->assertEqualsWithDelta(30.03, $result['pressure'], 0.01);
        $this->assertEquals(7, $result['wind_speed']);
        $this->assertEquals(90, $result['wind_direction']);
        $this->assertEquals(10.0, $result['visibility']);
    }
    
    /**
     * Test parseAwosnetResponse - No data (///) returns null
     */
    public function testParseAwosnetResponse_NoData_ReturnsNull()
    {
        $response = getMockAwosnetResponseNoData();
        $result = parseAwosnetResponse($response, []);
        $this->assertNull($result);
    }
    
    /**
     * Test parseAwosnetResponse - Invalid HTML returns null
     */
    public function testParseAwosnetResponse_InvalidHtml_ReturnsNull()
    {
        $result = parseAwosnetResponse('<html><body>No METAR here</body></html>', []);
        $this->assertNull($result);
    }
    
    /**
     * Test parseAwosnetResponse - Empty response returns null
     */
    public function testParseAwosnetResponse_EmptyResponse_ReturnsNull()
    {
        $result = parseAwosnetResponse('', []);
        $this->assertNull($result);
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
        // Missing fields should have null values (wind_speed) or default to 0 (precip_accum)
        // Note: precip_accum defaults to 0 (no precipitation) per unified standard, not null
        $this->assertNull($result['wind_speed']);
        $this->assertEquals(0, $result['precip_accum'], 'Missing precip_accum should default to 0 (no precipitation)');
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
    
    /**
     * Test parsePWSWeatherResponse - Valid response with all fields
     */
    public function testParsePWSWeatherResponse_ValidCompleteResponse()
    {
        $response = getMockPWSWeatherResponse();
        $result = parsePWSWeatherResponse($response);
        
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
     * Test parsePWSWeatherResponse - Missing optional fields
     */
    public function testParsePWSWeatherResponse_MissingOptionalFields()
    {
        $timestamp = time();
        $response = json_encode([
            'success' => true,
            'error' => null,
            'response' => [
                'id' => 'KMAHANOV10',
                'ob' => [
                        'timestamp' => $timestamp,
                        'tempC' => 15.0,
                        'humidity' => 70.0,
                        'pressureIN' => 30.0,
                        // Missing wind, precip fields
                    ]
                ]
            
        ]);
        
        $result = parsePWSWeatherResponse($response);
        
        $this->assertIsArray($result);
        $this->assertEquals(15.0, $result['temperature']);
        // Missing fields should have null values
        $this->assertNull($result['wind_speed']);
        $this->assertEquals(0, $result['precip_accum']); // Defaults to 0
    }
    
    /**
     * Test parsePWSWeatherResponse - Missing ob key
     */
    public function testParsePWSWeatherResponse_MissingObKey()
    {
        $response = json_encode([
            'success' => true,
            'error' => null,
            'response' => [
                'id' => 'PWS_TESTSTATION'
                // Missing 'ob' key
            ]
        ]);
        
        $result = parsePWSWeatherResponse($response);
        $this->assertNull($result);
    }
    
    /**
     * Test parsePWSWeatherResponse - Null response
     */
    public function testParsePWSWeatherResponse_NullResponse()
    {
        $result = parsePWSWeatherResponse(null);
        $this->assertNull($result);
    }
    
    /**
     * Test parsePWSWeatherResponse - Invalid structure
     */
    public function testParsePWSWeatherResponse_InvalidStructure()
    {
        $response = json_encode(['invalid' => 'structure']);
        $result = parsePWSWeatherResponse($response);
        $this->assertNull($result);
    }
    
    /**
     * Test parsePWSWeatherResponse - Gust speed from windGustKTS field
     */
    public function testParsePWSWeatherResponse_GustSpeedFromWindGustKTS()
    {
        $timestamp = time();
        $response = json_encode([
            'success' => true,
            'error' => null,
            'response' => [
                'id' => 'KMAHANOV10',
                'ob' => [
                        'timestamp' => $timestamp,
                        'tempC' => 15.0,
                        'windSpeedKTS' => 5,  // Wind speed
                        'windGustKTS' => 7,   // Gust speed (different from wind speed)
                    ]
                ]
            
        ]);
        
        $result = parsePWSWeatherResponse($response);
        
        $this->assertIsArray($result);
        $this->assertEquals(5, $result['wind_speed'], 'Wind speed should be 5 knots');
        $this->assertEquals(7, $result['gust_speed'], 'Gust speed should be 7 knots from windGustKTS field');
        $this->assertEquals(7, $result['peak_gust'], 'Peak gust should match gust_speed');
        // Verify gust speed is NOT the same as wind speed (proves we're using windGustKTS)
        $this->assertNotEquals($result['wind_speed'], $result['gust_speed'], 'Gust speed should differ from wind speed');
    }
    
    /**
     * Test parsePWSWeatherResponse - Missing gust speed (should be null, not wind speed)
     */
    public function testParsePWSWeatherResponse_MissingGustSpeed_ReturnsNull()
    {
        $timestamp = time();
        $response = json_encode([
            'success' => true,
            'error' => null,
            'response' => [
                'id' => 'KMAHANOV10',
                'ob' => [
                        'timestamp' => $timestamp,
                        'tempC' => 15.0,
                        'windSpeedKTS' => 5,  // Wind speed present
                        // windGustKTS missing
                    ]
                ]
            
        ]);
        
        $result = parsePWSWeatherResponse($response);
        
        $this->assertIsArray($result);
        $this->assertEquals(5, $result['wind_speed'], 'Wind speed should be 5 knots');
        $this->assertNull($result['gust_speed'], 'Gust speed should be null when windGustKTS is missing');
        $this->assertNull($result['peak_gust'], 'Peak gust should be null when gust speed is null');
    }
    
    /**
     * Test parsePWSWeatherResponse - API error response
     */
    public function testParsePWSWeatherResponse_ApiErrorResponse()
    {
        $response = json_encode([
            'success' => false,
            'error' => 'Invalid station ID'
        ]);
        
        $result = parsePWSWeatherResponse($response);
        $this->assertNull($result);
    }
    
    /**
     * Test parsePWSWeatherResponse - Visibility in statute miles (no conversion)
     */
    public function testParsePWSWeatherResponse_VisibilityInMiles()
    {
        $timestamp = time();
        $response = json_encode([
            'success' => true,
            'error' => null,
            'response' => [
                'id' => 'KMAHANOV10',
                'ob' => [
                        'timestamp' => $timestamp,
                        'tempC' => 15.0,
                        'humidity' => 70.0,
                        'pressureIN' => 30.0,
                        'windSpeedKTS' => 5,
                        'windDirDEG' => 180,
                        'visibilityMI' => 10.0, // 10 statute miles
                        'precipIN' => 0
                    ]
                ]
            
        ]);
        
        $result = parsePWSWeatherResponse($response);
        
        // Visibility should be in statute miles (no conversion)
        $this->assertEqualsWithDelta(10.0, $result['visibility'], 0.1);
    }
    
    /**
     * Test parsePWSWeatherResponse - Wind speed already in knots (no conversion)
     */
    public function testParsePWSWeatherResponse_WindSpeedInKnots()
    {
        $timestamp = time();
        $response = json_encode([
            'success' => true,
            'error' => null,
            'response' => [
                'id' => 'KMAHANOV10',
                'ob' => [
                        'timestamp' => $timestamp,
                        'tempC' => 15.0,
                        'humidity' => 70.0,
                        'pressureIN' => 30.0,
                        'windSpeedKTS' => 10, // Already in knots
                        'windDirDEG' => 180,
                        'precipIN' => 0
                    ]
                ]
            
        ]);
        
        $result = parsePWSWeatherResponse($response);
        
        // Wind speed should be in knots (no conversion)
        $this->assertEquals(10, $result['wind_speed']);
    }
    
    /**
     * Test parsePWSWeatherResponse - Pressure in inHg (no conversion)
     */
    public function testParsePWSWeatherResponse_PressureInInHg()
    {
        $timestamp = time();
        $response = json_encode([
            'success' => true,
            'error' => null,
            'response' => [
                'id' => 'KMAHANOV10',
                'ob' => [
                        'timestamp' => $timestamp,
                        'tempC' => 15.0,
                        'humidity' => 70.0,
                        'pressureIN' => 30.12, // inHg
                        'windSpeedKTS' => 5,
                        'windDirDEG' => 180,
                        'precipIN' => 0
                    ]
                ]
            
        ]);
        
        $result = parsePWSWeatherResponse($response);
        
        // Pressure should be in inHg (no conversion)
        $this->assertEqualsWithDelta(30.12, $result['pressure'], 0.01);
    }
    
    /**
     * Test parsePWSWeatherResponse - Precipitation in inches (no conversion)
     */
    public function testParsePWSWeatherResponse_PrecipitationInInches()
    {
        $timestamp = time();
        $response = json_encode([
            'success' => true,
            'error' => null,
            'response' => [
                'id' => 'KMAHANOV10',
                'ob' => [
                        'timestamp' => $timestamp,
                        'tempC' => 15.0,
                        'humidity' => 70.0,
                        'pressureIN' => 30.0,
                        'windSpeedKTS' => 5,
                        'windDirDEG' => 180,
                        'precipIN' => 0.5 // 0.5 inches
                    ]
                ]
            
        ]);
        
        $result = parsePWSWeatherResponse($response);
        
        // Precipitation should be in inches (no conversion)
        $this->assertEqualsWithDelta(0.5, $result['precip_accum'], 0.01);
    }
    
    /**
     * Test parsePWSWeatherResponse - Temperature already in Celsius (no conversion)
     */
    public function testParsePWSWeatherResponse_TemperatureInCelsius()
    {
        $timestamp = time();
        $response = json_encode([
            'success' => true,
            'error' => null,
            'response' => [
                'id' => 'KMAHANOV10',
                'ob' => [
                        'timestamp' => $timestamp,
                        'tempC' => 0.0, // Freezing point in Celsius
                        'humidity' => 70.0,
                        'pressureIN' => 30.0,
                        'windSpeedKTS' => 5,
                        'windDirDEG' => 180,
                        'precipIN' => 0
                    ]
                ]
            
        ]);
        
        $result = parsePWSWeatherResponse($response);
        
        // Temperature should be in Celsius (no conversion)
        $this->assertEqualsWithDelta(0.0, $result['temperature'], 0.1);
    }
    
    /**
     * Test parsePWSWeatherResponse - Observation timestamp parsing
     */
    public function testParsePWSWeatherResponse_ObservationTimestamp()
    {
        $timestamp = time();
        $response = json_encode([
            'success' => true,
            'error' => null,
            'response' => [
                'id' => 'KMAHANOV10',
                'ob' => [
                        'timestamp' => $timestamp,
                        'tempC' => 15.0,
                        'humidity' => 70.0,
                        'pressureIN' => 30.0,
                        'windSpeedKTS' => 5,
                        'windDirDEG' => 180,
                        'precipIN' => 0
                    ]
                ]
            
        ]);
        
        $result = parsePWSWeatherResponse($response);
        
        // Observation time should match timestamp
        $this->assertEquals($timestamp, $result['obs_time']);
    }
    
    /**
     * Test parsePWSWeatherResponse - Missing response key
     */
    public function testParsePWSWeatherResponse_MissingResponseKey()
    {
        $response = json_encode([
            'success' => true,
            'error' => null
        ]);
        
        $result = parsePWSWeatherResponse($response);
        $this->assertNull($result);
    }
    
    /**
     * Test parsePWSWeatherResponse - Invalid JSON
     */
    public function testParsePWSWeatherResponse_InvalidJson()
    {
        $response = 'invalid json string';
        $result = parsePWSWeatherResponse($response);
        $this->assertNull($result);
    }
    
    /**
     * Test parseSynopticDataResponse - Valid complete response
     */
    public function testParseSynopticDataResponse_ValidCompleteResponse()
    {
        require_once __DIR__ . '/../../lib/weather/adapter/synopticdata-v1.php';
        $response = getMockSynopticDataResponse();
        $result = parseSynopticDataResponse($response);
        
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
        $this->assertIsInt($result['gust_speed']);
        $this->assertIsFloat($result['precip_accum']);
        $this->assertIsFloat($result['dewpoint']);
        $this->assertIsInt($result['obs_time']);
    }
    
    /**
     * Test parseSynopticDataResponse - Missing optional fields
     */
    public function testParseSynopticDataResponse_MissingOptionalFields()
    {
        require_once __DIR__ . '/../../lib/weather/adapter/synopticdata-v1.php';
        $response = json_encode([
            'SUMMARY' => [
                'RESPONSE_CODE' => 1,
                'RESPONSE_MESSAGE' => 'OK'
            ],
            'STATION' => [[
                'STID' => 'AT297',
                'OBSERVATIONS' => [
                    'air_temp_value_1' => [
                        'value' => 15.0,
                        'date_time' => date('c', time())
                    ],
                    'relative_humidity_value_1' => [
                        'value' => 70.0,
                        'date_time' => date('c', time())
                    ]
                    // Missing wind, pressure, precip, dewpoint
                ]
            ]]
        ]);
        
        $result = parseSynopticDataResponse($response);
        
        $this->assertIsArray($result);
        $this->assertEquals(15.0, $result['temperature']);
        $this->assertEquals(70.0, $result['humidity']);
        // Missing fields should be null or default values
        $this->assertNull($result['wind_speed']);
        $this->assertNull($result['pressure']);
        $this->assertEquals(0, $result['precip_accum']); // Defaults to 0
    }
    
    /**
     * Test parseSynopticDataResponse - Empty STATION array
     */
    public function testParseSynopticDataResponse_EmptyStationArray()
    {
        require_once __DIR__ . '/../../lib/weather/adapter/synopticdata-v1.php';
        $response = json_encode([
            'SUMMARY' => [
                'RESPONSE_CODE' => 1,
                'RESPONSE_MESSAGE' => 'OK'
            ],
            'STATION' => []
        ]);
        
        $result = parseSynopticDataResponse($response);
        $this->assertNull($result);
    }
    
    /**
     * Test parseSynopticDataResponse - Null response
     */
    public function testParseSynopticDataResponse_NullResponse()
    {
        require_once __DIR__ . '/../../lib/weather/adapter/synopticdata-v1.php';
        $result = parseSynopticDataResponse(null);
        $this->assertNull($result);
    }
    
    /**
     * Test parseSynopticDataResponse - Invalid structure
     */
    public function testParseSynopticDataResponse_InvalidStructure()
    {
        require_once __DIR__ . '/../../lib/weather/adapter/synopticdata-v1.php';
        $response = json_encode(['invalid' => 'structure']);
        $result = parseSynopticDataResponse($response);
        $this->assertNull($result);
    }
    
    /**
     * Test parseSynopticDataResponse - Invalid JSON
     */
    public function testParseSynopticDataResponse_InvalidJson()
    {
        require_once __DIR__ . '/../../lib/weather/adapter/synopticdata-v1.php';
        $response = 'invalid json string';
        $result = parseSynopticDataResponse($response);
        $this->assertNull($result);
    }
    
    /**
     * Test parseSynopticDataResponse - API error response
     */
    public function testParseSynopticDataResponse_ApiError()
    {
        require_once __DIR__ . '/../../lib/weather/adapter/synopticdata-v1.php';
        $response = json_encode([
            'SUMMARY' => [
                'RESPONSE_CODE' => -1,
                'RESPONSE_MESSAGE' => 'Error: Invalid station ID'
            ],
            'STATION' => []
        ]);
        
        $result = parseSynopticDataResponse($response);
        $this->assertNull($result);
    }
    
    /**
     * Test parseSynopticDataResponse - Unit conversions (wind m/s to knots)
     */
    public function testParseSynopticDataResponse_WindSpeedConversion()
    {
        require_once __DIR__ . '/../../lib/weather/adapter/synopticdata-v1.php';
        $response = json_encode([
            'SUMMARY' => [
                'RESPONSE_CODE' => 1,
                'RESPONSE_MESSAGE' => 'OK'
            ],
            'STATION' => [[
                'STID' => 'AT297',
                'OBSERVATIONS' => [
                    'air_temp_value_1' => [
                        'value' => 15.0,
                        'date_time' => date('c', time())
                    ],
                    'wind_speed_value_1' => [
                        'value' => 5.144,  // 10 knots in m/s (10 / 1.943844)
                        'date_time' => date('c', time())
                    ],
                    'wind_gust_value_1' => [
                        'value' => 7.716,  // 15 knots in m/s
                        'date_time' => date('c', time())
                    ]
                ]
            ]]
        ]);
        
        $result = parseSynopticDataResponse($response);
        
        // Should convert m/s to knots (round to int)
        $this->assertEquals(10, $result['wind_speed']);
        $this->assertEquals(15, $result['gust_speed']);
    }
    
    /**
     * Test parseSynopticDataResponse - Pressure conversion (mb to inHg)
     */
    public function testParseSynopticDataResponse_PressureConversion()
    {
        require_once __DIR__ . '/../../lib/weather/adapter/synopticdata-v1.php';
        $response = json_encode([
            'SUMMARY' => [
                'RESPONSE_CODE' => 1,
                'RESPONSE_MESSAGE' => 'OK'
            ],
            'STATION' => [[
                'STID' => 'AT297',
                'OBSERVATIONS' => [
                    'air_temp_value_1' => [
                        'value' => 15.0,
                        'date_time' => date('c', time())
                    ],
                    'sea_level_pressure_value_1d' => [
                        'value' => 1013.25,  // Standard atmospheric pressure in mb
                        'date_time' => date('c', time())
                    ]
                ]
            ]]
        ]);
        
        $result = parseSynopticDataResponse($response);
        
        // 1013.25 mb = 29.92 inHg (standard atmospheric pressure)
        $this->assertIsFloat($result['pressure']);
        $this->assertEqualsWithDelta(29.92, $result['pressure'], 0.1);
    }
    
    /**
     * Test parseSynopticDataResponse - Altimeter handling (already in inHg, no conversion)
     */
    public function testParseSynopticDataResponse_AltimeterHandling()
    {
        require_once __DIR__ . '/../../lib/weather/adapter/synopticdata-v1.php';
        $response = json_encode([
            'SUMMARY' => [
                'RESPONSE_CODE' => 1,
                'RESPONSE_MESSAGE' => 'OK'
            ],
            'STATION' => [[
                'STID' => 'AT297',
                'OBSERVATIONS' => [
                    'air_temp_value_1' => [
                        'value' => 15.0,
                        'date_time' => date('c', time())
                    ],
                    'altimeter_value_1' => [
                        'value' => 29.92,  // Already in inHg, should not convert
                        'date_time' => date('c', time())
                    ]
                ]
            ]]
        ]);
        
        $result = parseSynopticDataResponse($response);
        
        // Altimeter is already in inHg, should use directly without conversion
        $this->assertIsFloat($result['pressure']);
        $this->assertEqualsWithDelta(29.92, $result['pressure'], 0.01);
    }
    
    /**
     * Test parseSynopticDataResponse - Precipitation conversion (mm to inches)
     */
    public function testParseSynopticDataResponse_PrecipitationConversion()
    {
        require_once __DIR__ . '/../../lib/weather/adapter/synopticdata-v1.php';
        $response = json_encode([
            'SUMMARY' => [
                'RESPONSE_CODE' => 1,
                'RESPONSE_MESSAGE' => 'OK'
            ],
            'STATION' => [[
                'STID' => 'AT297',
                'OBSERVATIONS' => [
                    'air_temp_value_1' => [
                        'value' => 15.0,
                        'date_time' => date('c', time())
                    ],
                    'precip_accum_since_local_midnight_value_1' => [
                        'value' => 25.4,  // 1 inch in mm
                        'date_time' => date('c', time())
                    ]
                ]
            ]]
        ]);
        
        $result = parseSynopticDataResponse($response);
        
        // 25.4 mm = 1.0 inches
        $this->assertIsFloat($result['precip_accum']);
        $this->assertEqualsWithDelta(1.0, $result['precip_accum'], 0.01);
    }
    
    /**
     * Test parseSynopticDataResponse - Observation time parsing
     */
    public function testParseSynopticDataResponse_ObservationTime()
    {
        require_once __DIR__ . '/../../lib/weather/adapter/synopticdata-v1.php';
        $expectedTime = time() - 300; // 5 minutes ago
        $dateTime = date('c', $expectedTime);
        
        $response = json_encode([
            'SUMMARY' => [
                'RESPONSE_CODE' => 1,
                'RESPONSE_MESSAGE' => 'OK'
            ],
            'STATION' => [[
                'STID' => 'AT297',
                'OBSERVATIONS' => [
                    'air_temp_value_1' => [
                        'value' => 15.0,
                        'date_time' => $dateTime
                    ]
                ]
            ]]
        ]);
        
        $result = parseSynopticDataResponse($response);
        
        // Should parse ISO 8601 timestamp to Unix seconds
        $this->assertIsInt($result['obs_time']);
        $this->assertEqualsWithDelta($expectedTime, $result['obs_time'], 1); // Allow 1 second difference
    }
}

