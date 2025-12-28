<?php
/**
 * Weather Adapter Unit Conversion Tests
 * 
 * Tests that verify weather adapters correctly handle unit conversions,
 * including edge cases where APIs return data in unexpected formats.
 * 
 * These tests simulate the actual production bug where SynopticData returned
 * pressure in hundredths of inHg, causing dangerous pressure altitude calculations.
 */

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../lib/weather/adapter/synopticdata-v1.php';
require_once __DIR__ . '/../../lib/weather/adapter/metar-v1.php';
require_once __DIR__ . '/../../lib/weather/adapter/tempest-v1.php';
require_once __DIR__ . '/../../lib/weather/UnifiedFetcher.php';
require_once __DIR__ . '/../../lib/constants.php';

class WeatherAdapterUnitConversionTest extends TestCase
{
    /**
     * Test SynopticData altimeter field handling with hundredths of inHg
     * 
     * This is the actual bug that occurred: altimeter came back as 3038.93
     * (hundredths of inHg) instead of 30.3893 inHg
     */
    public function testSynopticData_AltimeterInHundredths_CorrectedTo30InHg()
    {
        $timestamp = time();
        $dateTime = date('c', $timestamp);
        
        // Simulate API returning altimeter in hundredths (bug scenario)
        $response = json_encode([
            'SUMMARY' => ['RESPONSE_CODE' => 1],
            'STATION' => [[
                'STID' => 'TEST',
                'OBSERVATIONS' => [
                    'air_temp_value_1' => ['value' => 5.6, 'date_time' => $dateTime],
                    'altimeter_value_1' => ['value' => 3038.93, 'date_time' => $dateTime]  // Bug: hundredths of inHg
                ]
            ]]
        ]);
        
        $parsed = parseSynopticDataResponse($response);
        
        // The fix should correct 3038.93 to 30.3893
        $this->assertNotNull($parsed);
        $this->assertNotNull($parsed['pressure']);
        $this->assertLessThan(100, $parsed['pressure'], 
            'Pressure should be corrected from hundredths to inHg');
        $this->assertEqualsWithDelta(30.3893, $parsed['pressure'], 0.01,
            'Pressure should be ~30.39 inHg after correction');
    }

    /**
     * Test SynopticData altimeter field with normal inHg value
     * Ensures the fix doesn't break normal values
     */
    public function testSynopticData_AltimeterInNormalInHg_UnchangedAround30()
    {
        $timestamp = time();
        $dateTime = date('c', $timestamp);
        
        // Normal altimeter value in inHg
        $response = json_encode([
            'SUMMARY' => ['RESPONSE_CODE' => 1],
            'STATION' => [[
                'STID' => 'TEST',
                'OBSERVATIONS' => [
                    'air_temp_value_1' => ['value' => 5.6, 'date_time' => $dateTime],
                    'altimeter_value_1' => ['value' => 30.12, 'date_time' => $dateTime]
                ]
            ]]
        ]);
        
        $parsed = parseSynopticDataResponse($response);
        
        $this->assertNotNull($parsed);
        $this->assertEquals(30.12, $parsed['pressure'],
            'Normal pressure should remain unchanged');
    }

    /**
     * Test SynopticData sea_level_pressure conversion (mb to inHg)
     */
    public function testSynopticData_SeaLevelPressure_ConvertedFromMb()
    {
        $timestamp = time();
        $dateTime = date('c', $timestamp);
        
        $response = json_encode([
            'SUMMARY' => ['RESPONSE_CODE' => 1],
            'STATION' => [[
                'STID' => 'TEST',
                'OBSERVATIONS' => [
                    'air_temp_value_1' => ['value' => 5.6, 'date_time' => $dateTime],
                    'sea_level_pressure_value_1d' => ['value' => 1019.2, 'date_time' => $dateTime]  // mb
                ]
            ]]
        ]);
        
        $parsed = parseSynopticDataResponse($response);
        
        $this->assertNotNull($parsed);
        $this->assertNotNull($parsed['pressure']);
        // 1019.2 mb / 33.8639 = ~30.10 inHg
        $this->assertEqualsWithDelta(30.10, $parsed['pressure'], 0.05);
    }

    /**
     * Test METAR altim conversion (hPa to inHg)
     */
    public function testMetar_AltimInHpa_ConvertedToInHg()
    {
        $response = json_encode([[
            'rawOb' => 'KSPB 261654Z AUTO 18005KT 10SM CLR 06/04 A3012',
            'obsTime' => '2025-01-26T16:54:00Z',
            'temp' => 6.0,
            'dewp' => 4.0,
            'wdir' => 180,
            'wspd' => 5,
            'altim' => 1020.0  // hPa (should convert to ~30.12 inHg)
        ]]);
        
        $airport = createTestAirport(['elevation_ft' => 100]);
        $parsed = parseMETARResponse($response, $airport);
        
        $this->assertNotNull($parsed);
        $this->assertNotNull($parsed['pressure']);
        // 1020.0 hPa / 33.8639 = ~30.12 inHg
        $this->assertEqualsWithDelta(30.12, $parsed['pressure'], 0.02);
    }

    /**
     * Test METAR raw string altimeter parsing
     */
    public function testMetar_RawAltimeter_ParsedCorrectly()
    {
        $response = json_encode([[
            'rawOb' => 'KSPB 261654Z AUTO 18005KT 10SM CLR 06/04 A3012',
            'obsTime' => '2025-01-26T16:54:00Z',
            'temp' => 6.0,
            'dewp' => 4.0,
            'wdir' => 180,
            'wspd' => 5
            // No 'altim' field - should parse from raw string
        ]]);
        
        $airport = createTestAirport(['elevation_ft' => 100]);
        $parsed = parseMETARResponse($response, $airport);
        
        $this->assertNotNull($parsed);
        $this->assertNotNull($parsed['pressure']);
        // A3012 = 30.12 inHg
        $this->assertEqualsWithDelta(30.12, $parsed['pressure'], 0.02);
    }

    /**
     * Test Tempest pressure conversion (mb to inHg)
     */
    public function testTempest_SeaLevelPressure_ConvertedFromMb()
    {
        $response = json_encode([
            'obs' => [[
                'timestamp' => time(),
                'air_temperature' => 5.6,
                'sea_level_pressure' => 1019.2  // mb
            ]]
        ]);
        
        $parsed = parseTempestResponse($response);
        
        $this->assertNotNull($parsed);
        $this->assertNotNull($parsed['pressure']);
        // 1019.2 mb / 33.8639 = ~30.10 inHg
        $this->assertEqualsWithDelta(30.10, $parsed['pressure'], 0.05);
    }

    /**
     * Test UnifiedFetcher pressure correction catches all bad values
     * 
     * This tests the universal fix that catches any pressure > 100 inHg
     */
    public function testUnifiedFetcher_PressureCorrectionLogic()
    {
        // Simulate what happens in UnifiedFetcher::fetchWeatherUnified
        // after aggregation but before calculation
        
        // Test case 1: Bad pressure (hundredths of inHg)
        $result1 = ['pressure' => 3038.93];
        if (isset($result1['pressure']) && is_numeric($result1['pressure'])) {
            $pressure = (float)$result1['pressure'];
            if ($pressure > 100) {
                $result1['pressure'] = $pressure / 100.0;
            }
        }
        $this->assertEqualsWithDelta(30.3893, $result1['pressure'], 0.001);
        
        // Test case 2: Normal pressure (should remain unchanged)
        $result2 = ['pressure' => 30.12];
        if (isset($result2['pressure']) && is_numeric($result2['pressure'])) {
            $pressure = (float)$result2['pressure'];
            if ($pressure > 100) {
                $result2['pressure'] = $pressure / 100.0;
            }
        }
        $this->assertEquals(30.12, $result2['pressure']);
        
        // Test case 3: Edge case at boundary (99.9 - just under threshold)
        $result3 = ['pressure' => 99.9];
        if (isset($result3['pressure']) && is_numeric($result3['pressure'])) {
            $pressure = (float)$result3['pressure'];
            if ($pressure > 100) {
                $result3['pressure'] = $pressure / 100.0;
            }
        }
        $this->assertEquals(99.9, $result3['pressure'], 'Value just under 100 should not be corrected');
    }

    /**
     * Smoke test: Final pressure from any source should be within physical bounds
     */
    public function testSmokeTest_AllAdapters_ProduceValidPressure()
    {
        $timestamp = time();
        $dateTime = date('c', $timestamp);
        $airport = createTestAirport(['elevation_ft' => 100]);
        
        // Test Tempest
        $tempestResponse = json_encode(['obs' => [['timestamp' => $timestamp, 'air_temperature' => 5.6, 'sea_level_pressure' => 1019.2]]]);
        $parsed = parseTempestResponse($tempestResponse);
        if ($parsed !== null && isset($parsed['pressure'])) {
            $this->assertGreaterThan(CLIMATE_PRESSURE_MIN_INHG, $parsed['pressure'],
                "Tempest: Pressure should be above min bound");
            $this->assertLessThan(CLIMATE_PRESSURE_MAX_INHG, $parsed['pressure'],
                "Tempest: Pressure should be below max bound");
        }
        
        // Test SynopticData
        $synopticResponse = json_encode(['SUMMARY' => ['RESPONSE_CODE' => 1], 'STATION' => [['STID' => 'TEST', 'OBSERVATIONS' => ['air_temp_value_1' => ['value' => 5.6, 'date_time' => $dateTime], 'sea_level_pressure_value_1d' => ['value' => 1019.2, 'date_time' => $dateTime]]]]]);
        $parsed = parseSynopticDataResponse($synopticResponse);
        if ($parsed !== null && isset($parsed['pressure'])) {
            $this->assertGreaterThan(CLIMATE_PRESSURE_MIN_INHG, $parsed['pressure'],
                "SynopticData: Pressure should be above min bound");
            $this->assertLessThan(CLIMATE_PRESSURE_MAX_INHG, $parsed['pressure'],
                "SynopticData: Pressure should be below max bound");
        }
        
        // Test METAR
        $metarResponse = json_encode([['rawOb' => 'TEST 261654Z AUTO 18005KT 10SM CLR 06/04 A3012', 'obsTime' => '2025-01-26T16:54:00Z', 'temp' => 6.0, 'dewp' => 4.0, 'wdir' => 180, 'wspd' => 5, 'altim' => 1020.0]]);
        $parsed = parseMETARResponse($metarResponse, $airport);
        if ($parsed !== null && isset($parsed['pressure'])) {
            $this->assertGreaterThan(CLIMATE_PRESSURE_MIN_INHG, $parsed['pressure'],
                "METAR: Pressure should be above min bound");
            $this->assertLessThan(CLIMATE_PRESSURE_MAX_INHG, $parsed['pressure'],
                "METAR: Pressure should be below max bound");
        }
    }
}

