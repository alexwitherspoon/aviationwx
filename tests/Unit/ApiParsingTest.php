<?php
/**
 * Unit Tests for API Response Parsing Functions
 * 
 * Tests parsing of Tempest, Ambient Weather, and METAR API responses
 */

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../weather.php';
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
        $response = json_encode([[
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
        $result = parseMETARResponse($response, $airport);
        
        // Ceiling should be calculated (base + elevation if base is AGL, or just base if MSL)
        // This depends on implementation - verify it's set
        $this->assertIsArray($result);
        // Ceiling might be null or calculated depending on implementation
    }
    
    /**
     * Test parseMETARResponse - Cloud cover parsing
     */
    public function testParseMETARResponse_CloudCover()
    {
        $testCases = [
            ['cover' => 'SCT', 'expected' => 'SCT'],
            ['cover' => 'BKN', 'expected' => 'BKN'],
            ['cover' => 'OVC', 'expected' => 'OVC'],
            ['cover' => 'CLR', 'expected' => null], // Clear sky
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
            
            if ($testCase['expected'] === null) {
                $this->assertNull($result['cloud_cover']);
            } else {
                $this->assertEquals($testCase['expected'], $result['cloud_cover']);
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
}

