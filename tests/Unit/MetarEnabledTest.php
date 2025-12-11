<?php
/**
 * Unit Tests for METAR Enable/Disable Feature
 * 
 * Tests the isMetarEnabled() helper function. METAR is enabled
 * when metar_station is configured (exists and is not empty).
 */

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../lib/weather/utils.php';

class MetarEnabledTest extends TestCase
{
    /**
     * Test isMetarEnabled - station configured returns true
     */
    public function testIsMetarEnabled_StationConfigured_ReturnsTrue(): void
    {
        $airport = [
            'icao' => 'KSPB',
            'metar_station' => 'KSPB'
        ];
        
        $this->assertTrue(isMetarEnabled($airport), 'Should return true when metar_station is configured');
    }
    
    /**
     * Test isMetarEnabled - no station returns false
     */
    public function testIsMetarEnabled_NoStation_ReturnsFalse(): void
    {
        $airport = [
            'icao' => 'KSPB'
            // metar_station not set
        ];
        
        $this->assertFalse(isMetarEnabled($airport), 'Should return false when metar_station is not configured');
    }
    
    /**
     * Test isMetarEnabled - empty station returns false
     */
    public function testIsMetarEnabled_EmptyStation_ReturnsFalse(): void
    {
        $airport = [
            'icao' => 'KSPB',
            'metar_station' => ''
        ];
        
        $this->assertFalse(isMetarEnabled($airport), 'Should return false when metar_station is empty string');
    }
    
    /**
     * Test isMetarEnabled - null station returns false
     */
    public function testIsMetarEnabled_NullStation_ReturnsFalse(): void
    {
        $airport = [
            'icao' => 'KSPB',
            'metar_station' => null
        ];
        
        $this->assertFalse(isMetarEnabled($airport), 'Should return false when metar_station is null');
    }
    
    /**
     * Test isMetarEnabled - per-airport independence
     */
    public function testIsMetarEnabled_PerAirportIndependence_WorksCorrectly(): void
    {
        $airportA = [
            'icao' => 'KSPB',
            'metar_station' => 'KSPB'
        ];
        
        $airportB = [
            'icao' => 'KABC'
            // No metar_station configured
        ];
        
        $airportC = [
            'icao' => 'KDEF',
            'metar_station' => 'KDEF'
        ];
        
        $this->assertTrue(isMetarEnabled($airportA), 'Airport A should have METAR enabled (station configured)');
        $this->assertFalse(isMetarEnabled($airportB), 'Airport B should have METAR disabled (no station)');
        $this->assertTrue(isMetarEnabled($airportC), 'Airport C should have METAR enabled (station configured)');
    }
    
    /**
     * Test isMetarEnabled - whitespace-only station returns false
     */
    public function testIsMetarEnabled_WhitespaceStation_ReturnsFalse(): void
    {
        $airport = [
            'icao' => 'KSPB',
            'metar_station' => '   '
        ];
        
        // empty() considers whitespace-only strings as empty
        $this->assertFalse(isMetarEnabled($airport), 'Should return false when metar_station is whitespace only');
    }
}
