<?php
/**
 * Unit Tests for Weather Source Utility Functions
 * 
 * Tests the unified sources array configuration handling including
 * hasWeatherSources(), getPrimaryWeatherSourceType(), and related functions.
 */

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../lib/weather/utils.php';
require_once __DIR__ . '/../Helpers/TestHelper.php';

class WeatherSourceNormalizationTest extends TestCase
{
    /**
     * Test hasWeatherSources - Returns true when sources array is configured
     */
    public function testHasWeatherSources_Configured_ReturnsTrue()
    {
        $airport = createTestAirport([
            'icao' => 'KSPB',
            'weather_sources' => [
                ['type' => 'tempest', 'station_id' => '123', 'api_key' => 'key']
            ]
        ]);
        
        $result = hasWeatherSources($airport);
        
        $this->assertTrue($result, 'Should return true when sources is configured');
    }
    
    /**
     * Test hasWeatherSources - Returns false when no sources array
     */
    public function testHasWeatherSources_NoSources_ReturnsFalse()
    {
        $airport = createTestAirport([
            'icao' => 'KABC'
        ]);
        unset($airport['weather_sources']);
        
        $result = hasWeatherSources($airport);
        
        $this->assertFalse($result, 'Should return false when no sources array');
    }
    
    /**
     * Test hasWeatherSources - Returns false when sources array is empty
     */
    public function testHasWeatherSources_EmptySources_ReturnsFalse()
    {
        $airport = createTestAirport([
            'icao' => 'KABC',
            'weather_sources' => []
        ]);
        
        $result = hasWeatherSources($airport);
        
        $this->assertFalse($result, 'Should return false when sources array is empty');
    }
    
    /**
     * Test hasWeatherSources - Returns false when sources have no type
     */
    public function testHasWeatherSources_SourcesWithoutType_ReturnsFalse()
    {
        $airport = createTestAirport([
            'icao' => 'KABC',
            'weather_sources' => [
                ['station_id' => '123']
            ]
        ]);
        
        $result = hasWeatherSources($airport);
        
        $this->assertFalse($result, 'Should return false when sources have no type');
    }
    
    /**
     * Test getPrimaryWeatherSourceType - Returns first non-backup source type
     */
    public function testGetPrimaryWeatherSourceType_ReturnsFirstNonBackup()
    {
        $airport = createTestAirport([
            'icao' => 'KSPB',
            'weather_sources' => [
                ['type' => 'tempest', 'station_id' => '123'],
                ['type' => 'metar', 'station_id' => 'KSPB']
            ]
        ]);
        
        $result = getPrimaryWeatherSourceType($airport);
        
        $this->assertEquals('tempest', $result, 'Should return first non-backup source type');
    }
    
    /**
     * Test getPrimaryWeatherSourceType - Skips backup sources
     */
    public function testGetPrimaryWeatherSourceType_SkipsBackupSources()
    {
        $airport = createTestAirport([
            'icao' => 'KSPB',
            'weather_sources' => [
                ['type' => 'ambient', 'backup' => true],
                ['type' => 'tempest', 'station_id' => '123']
            ]
        ]);
        
        $result = getPrimaryWeatherSourceType($airport);
        
        $this->assertEquals('tempest', $result, 'Should skip backup sources');
    }
    
    /**
     * Test getPrimaryWeatherSourceType - Returns null when no sources
     */
    public function testGetPrimaryWeatherSourceType_NoSources_ReturnsNull()
    {
        $airport = createTestAirport([
            'icao' => 'KABC'
        ]);
        unset($airport['weather_sources']);
        
        $result = getPrimaryWeatherSourceType($airport);
        
        $this->assertNull($result, 'Should return null when no sources');
    }
    
    /**
     * Test hasWeatherSources - Works with all weather source types
     */
    public function testHasWeatherSources_AllSourceTypes_ReturnsTrue()
    {
        $sourceTypes = ['tempest', 'ambient', 'weatherlink_v2', 'weatherlink_v1', 'pwsweather', 'metar', 'nws', 'synopticdata'];
        
        foreach ($sourceTypes as $type) {
            $airport = createTestAirport([
                'icao' => 'KSPB',
                'weather_sources' => [
                    ['type' => $type, 'station_id' => 'TEST']
                ]
            ]);
            
            $result = hasWeatherSources($airport);
            
            $this->assertTrue($result, "Should return true for {$type} source type");
        }
    }
    
    /**
     * Test isMetarEnabled - Returns true when METAR source exists
     */
    public function testIsMetarEnabled_WithMetarSource_ReturnsTrue()
    {
        $airport = createTestAirport([
            'icao' => 'KSPB',
            'weather_sources' => [
                ['type' => 'tempest', 'station_id' => '123'],
                ['type' => 'metar', 'station_id' => 'KSPB']
            ]
        ]);
        
        $result = isMetarEnabled($airport);
        
        $this->assertTrue($result, 'Should return true when METAR source exists');
    }
    
    /**
     * Test isMetarEnabled - Returns false when no METAR source
     */
    public function testIsMetarEnabled_NoMetarSource_ReturnsFalse()
    {
        $airport = createTestAirport([
            'icao' => 'KSPB',
            'weather_sources' => [
                ['type' => 'tempest', 'station_id' => '123']
            ]
        ]);
        
        $result = isMetarEnabled($airport);
        
        $this->assertFalse($result, 'Should return false when no METAR source');
    }
    
    /**
     * Test getMetarStationId - Returns station ID from METAR source
     */
    public function testGetMetarStationId_ReturnsStationId()
    {
        $airport = createTestAirport([
            'icao' => 'KSPB',
            'weather_sources' => [
                ['type' => 'metar', 'station_id' => 'KSPB']
            ]
        ]);
        
        $result = getMetarStationId($airport);
        
        $this->assertEquals('KSPB', $result, 'Should return METAR station ID');
    }
    
    /**
     * Test multiple sources with backup flag
     */
    public function testMultipleSources_WithBackupFlag_HandledCorrectly()
    {
        $airport = createTestAirport([
            'icao' => 'KSPB',
            'weather_sources' => [
                ['type' => 'tempest', 'station_id' => '123'],
                ['type' => 'ambient', 'backup' => true],
                ['type' => 'metar', 'station_id' => 'KSPB']
            ]
        ]);
        
        $this->assertTrue(hasWeatherSources($airport), 'Should have weather sources');
        $this->assertEquals('tempest', getPrimaryWeatherSourceType($airport), 'Primary should be tempest');
        $this->assertTrue(isMetarEnabled($airport), 'METAR should be enabled');
    }
}
