<?php
/**
 * Unit Tests for Weather Source Normalization
 * 
 * Tests that weather_source configuration is properly normalized,
 * including handling airports with metar_station but no weather_source.
 */

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../lib/weather/utils.php';
require_once __DIR__ . '/../Helpers/TestHelper.php';

class WeatherSourceNormalizationTest extends TestCase
{
    /**
     * Test normalizeWeatherSource - Returns true when weather_source is already configured
     */
    public function testNormalizeWeatherSource_AlreadyConfigured_ReturnsTrue()
    {
        $airport = createTestAirport([
            'icao' => 'KSPB',
            'weather_source' => ['type' => 'tempest', 'station_id' => '123', 'api_key' => 'key']
        ]);
        
        $result = normalizeWeatherSource($airport);
        
        $this->assertTrue($result, 'Should return true when weather_source is already configured');
        $this->assertEquals('tempest', $airport['weather_source']['type'], 'Should preserve existing weather_source type');
    }
    
    /**
     * Test normalizeWeatherSource - Defaults to METAR when metar_station is configured but weather_source is missing
     */
    public function testNormalizeWeatherSource_MetarStationOnly_DefaultsToMetar()
    {
        $airport = createTestAirport([
            'icao' => 'KPFC',
            'metar_station' => 'KPFC'
        ]);
        // Explicitly remove weather_source to simulate the bug scenario
        unset($airport['weather_source']);
        
        $result = normalizeWeatherSource($airport);
        
        $this->assertTrue($result, 'Should return true when metar_station is configured');
        $this->assertArrayHasKey('weather_source', $airport, 'Should add weather_source to airport config');
        $this->assertEquals('metar', $airport['weather_source']['type'], 'Should default to METAR type');
    }
    
    /**
     * Test normalizeWeatherSource - Returns false when neither weather_source nor metar_station is configured
     */
    public function testNormalizeWeatherSource_NoSourceConfigured_ReturnsFalse()
    {
        $airport = createTestAirport([
            'icao' => 'KABC'
        ]);
        // Explicitly remove both weather_source and metar_station
        unset($airport['weather_source']);
        unset($airport['metar_station']);
        
        $result = normalizeWeatherSource($airport);
        
        $this->assertFalse($result, 'Should return false when no weather source is configured');
        $this->assertArrayNotHasKey('weather_source', $airport, 'Should not add weather_source when no source available');
    }
    
    /**
     * Test normalizeWeatherSource - Handles weather_source without type field
     */
    public function testNormalizeWeatherSource_MissingType_DefaultsToMetarIfMetarStationExists()
    {
        $airport = createTestAirport([
            'icao' => 'KPFC',
            'metar_station' => 'KPFC',
            'weather_source' => [] // Empty array, missing type
        ]);
        
        $result = normalizeWeatherSource($airport);
        
        $this->assertTrue($result, 'Should return true when metar_station is configured');
        $this->assertEquals('metar', $airport['weather_source']['type'], 'Should default to METAR type');
    }
    
    /**
     * Test normalizeWeatherSource - Handles empty metar_station string
     */
    public function testNormalizeWeatherSource_EmptyMetarStation_ReturnsFalse()
    {
        $airport = createTestAirport([
            'icao' => 'KABC',
            'metar_station' => '' // Empty string
        ]);
        unset($airport['weather_source']);
        
        $result = normalizeWeatherSource($airport);
        
        $this->assertFalse($result, 'Should return false when metar_station is empty');
    }
    
    /**
     * Test normalizeWeatherSource - Preserves existing weather_source when both are configured
     */
    public function testNormalizeWeatherSource_BothConfigured_PreservesWeatherSource()
    {
        $airport = createTestAirport([
            'icao' => 'KSPB',
            'metar_station' => 'KSPB',
            'weather_source' => ['type' => 'ambient', 'api_key' => 'key', 'application_key' => 'appkey']
        ]);
        
        $result = normalizeWeatherSource($airport);
        
        $this->assertTrue($result, 'Should return true');
        $this->assertEquals('ambient', $airport['weather_source']['type'], 'Should preserve existing weather_source type');
    }
    
    /**
     * Test normalizeWeatherSource - Works with all weather source types
     */
    public function testNormalizeWeatherSource_AllSourceTypes_Preserved()
    {
        $sourceTypes = ['tempest', 'ambient', 'weatherlink', 'pwsweather', 'metar'];
        
        foreach ($sourceTypes as $type) {
            $airport = createTestAirport([
                'icao' => 'KSPB',
                'weather_source' => ['type' => $type]
            ]);
            
            $result = normalizeWeatherSource($airport);
            
            $this->assertTrue($result, "Should return true for {$type} source type");
            $this->assertEquals($type, $airport['weather_source']['type'], "Should preserve {$type} type");
        }
    }
}



