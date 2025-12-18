<?php
/**
 * Unit Tests for isMetarEnabled() helper function
 * 
 * METAR is enabled when metar_station exists and is not empty/whitespace.
 */

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../lib/weather/utils.php';

class MetarEnabledTest extends TestCase
{
    public function testIsMetarEnabled_StationConfigured_ReturnsTrue(): void
    {
        $airport = ['metar_station' => 'KSPB'];
        $this->assertTrue(isMetarEnabled($airport));
    }
    
    public function testIsMetarEnabled_NoStation_ReturnsFalse(): void
    {
        $airport = ['icao' => 'KSPB'];
        $this->assertFalse(isMetarEnabled($airport));
    }
    
    public function testIsMetarEnabled_EmptyOrNull_ReturnsFalse(): void
    {
        $this->assertFalse(isMetarEnabled(['metar_station' => '']));
        $this->assertFalse(isMetarEnabled(['metar_station' => null]));
        $this->assertFalse(isMetarEnabled(['metar_station' => '   ']));
    }
}
