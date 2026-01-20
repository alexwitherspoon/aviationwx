<?php
/**
 * Unit Tests for isMetarEnabled() helper function
 * 
 * METAR is enabled when there's a METAR source in the sources array with a station_id.
 */

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../lib/weather/utils.php';

class MetarEnabledTest extends TestCase
{
    public function testIsMetarEnabled_SourceConfigured_ReturnsTrue(): void
    {
        $airport = [
            'weather_sources' => [
                ['type' => 'metar', 'station_id' => 'KSPB']
            ]
        ];
        $this->assertTrue(isMetarEnabled($airport));
    }
    
    public function testIsMetarEnabled_NoSources_ReturnsFalse(): void
    {
        $airport = ['icao' => 'KSPB'];
        $this->assertFalse(isMetarEnabled($airport));
    }
    
    public function testIsMetarEnabled_EmptySources_ReturnsFalse(): void
    {
        $airport = ['weather_sources' => []];
        $this->assertFalse(isMetarEnabled($airport));
    }
    
    public function testIsMetarEnabled_NoMetarSource_ReturnsFalse(): void
    {
        $airport = [
            'weather_sources' => [
                ['type' => 'tempest', 'station_id' => '12345']
            ]
        ];
        $this->assertFalse(isMetarEnabled($airport));
    }
    
    public function testIsMetarEnabled_MetarWithoutStationId_ReturnsFalse(): void
    {
        $airport = [
            'weather_sources' => [
                ['type' => 'metar']
            ]
        ];
        $this->assertFalse(isMetarEnabled($airport));
    }
    
    public function testIsMetarEnabled_MultipleSourcesWithMetar_ReturnsTrue(): void
    {
        $airport = [
            'weather_sources' => [
                ['type' => 'tempest', 'station_id' => '12345'],
                ['type' => 'metar', 'station_id' => 'KSPB']
            ]
        ];
        $this->assertTrue(isMetarEnabled($airport));
    }
    
    public function testGetMetarStationId_ReturnsStationId(): void
    {
        $airport = [
            'weather_sources' => [
                ['type' => 'metar', 'station_id' => 'KSPB']
            ]
        ];
        $this->assertEquals('KSPB', getMetarStationId($airport));
    }
    
    public function testGetMetarStationId_NoMetar_ReturnsNull(): void
    {
        $airport = [
            'weather_sources' => [
                ['type' => 'tempest', 'station_id' => '12345']
            ]
        ];
        $this->assertNull(getMetarStationId($airport));
    }
    
    public function testHasWeatherSources_WithSources_ReturnsTrue(): void
    {
        $airport = [
            'weather_sources' => [
                ['type' => 'tempest', 'station_id' => '12345']
            ]
        ];
        $this->assertTrue(hasWeatherSources($airport));
    }
    
    public function testHasWeatherSources_EmptySources_ReturnsFalse(): void
    {
        $airport = ['weather_sources' => []];
        $this->assertFalse(hasWeatherSources($airport));
    }
    
    public function testHasWeatherSources_NoSources_ReturnsFalse(): void
    {
        $airport = ['icao' => 'KSPB'];
        $this->assertFalse(hasWeatherSources($airport));
    }
    
    public function testGetPrimaryWeatherSourceType_ReturnsPrimaryType(): void
    {
        $airport = [
            'weather_sources' => [
                ['type' => 'tempest', 'station_id' => '12345'],
                ['type' => 'metar', 'station_id' => 'KSPB']
            ]
        ];
        $this->assertEquals('tempest', getPrimaryWeatherSourceType($airport));
    }
    
    public function testGetPrimaryWeatherSourceType_SkipsBackupSources(): void
    {
        $airport = [
            'weather_sources' => [
                ['type' => 'ambient', 'backup' => true],
                ['type' => 'tempest', 'station_id' => '12345']
            ]
        ];
        $this->assertEquals('tempest', getPrimaryWeatherSourceType($airport));
    }
    
    public function testGetPrimaryWeatherSourceType_OnlyBackups_ReturnsFirstBackup(): void
    {
        $airport = [
            'weather_sources' => [
                ['type' => 'ambient', 'backup' => true],
                ['type' => 'metar', 'backup' => true, 'station_id' => 'KSPB']
            ]
        ];
        $this->assertEquals('ambient', getPrimaryWeatherSourceType($airport));
    }
    
    public function testGetPrimaryWeatherSourceType_NoSources_ReturnsNull(): void
    {
        $airport = ['icao' => 'KSPB'];
        $this->assertNull(getPrimaryWeatherSourceType($airport));
    }
}
