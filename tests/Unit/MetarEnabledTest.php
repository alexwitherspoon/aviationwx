<?php
/**
 * Unit Tests for METAR Enable/Disable Feature
 * 
 * Tests the isMetarEnabled() helper function to ensure per-airport
 * METAR enable/disable functionality works correctly.
 */

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../lib/weather/utils.php';

class MetarEnabledTest extends TestCase
{
    /**
     * Test isMetarEnabled - enabled with metar_station configured
     */
    public function testIsMetarEnabled_EnabledWithStation_ReturnsTrue(): void
    {
        $airport = [
            'icao' => 'KSPB',
            'metar_enabled' => true,
            'metar_station' => 'KSPB'
        ];
        
        $this->assertTrue(isMetarEnabled($airport), 'Should return true when metar_enabled=true and metar_station is set');
    }
    
    /**
     * Test isMetarEnabled - disabled explicitly
     */
    public function testIsMetarEnabled_DisabledExplicitly_ReturnsFalse(): void
    {
        $airport = [
            'icao' => 'KSPB',
            'metar_enabled' => false,
            'metar_station' => 'KSPB'
        ];
        
        $this->assertFalse(isMetarEnabled($airport), 'Should return false when metar_enabled=false');
    }
    
    /**
     * Test isMetarEnabled - field missing (defaults to disabled)
     */
    public function testIsMetarEnabled_FieldMissing_ReturnsFalse(): void
    {
        $airport = [
            'icao' => 'KSPB',
            'metar_station' => 'KSPB'
            // metar_enabled not set
        ];
        
        $this->assertFalse(isMetarEnabled($airport), 'Should return false when metar_enabled field is missing (opt-in model)');
    }
    
    /**
     * Test isMetarEnabled - enabled but no metar_station
     */
    public function testIsMetarEnabled_EnabledButNoStation_ReturnsFalse(): void
    {
        $airport = [
            'icao' => 'KSPB',
            'metar_enabled' => true
            // metar_station not set
        ];
        
        $this->assertFalse(isMetarEnabled($airport), 'Should return false when metar_enabled=true but metar_station is missing');
    }
    
    /**
     * Test isMetarEnabled - enabled but empty metar_station
     */
    public function testIsMetarEnabled_EnabledButEmptyStation_ReturnsFalse(): void
    {
        $airport = [
            'icao' => 'KSPB',
            'metar_enabled' => true,
            'metar_station' => ''
        ];
        
        $this->assertFalse(isMetarEnabled($airport), 'Should return false when metar_station is empty string');
    }
    
    /**
     * Test isMetarEnabled - per-airport independence
     */
    public function testIsMetarEnabled_PerAirportIndependence_WorksCorrectly(): void
    {
        $airportA = [
            'icao' => 'KSPB',
            'metar_enabled' => true,
            'metar_station' => 'KSPB'
        ];
        
        $airportB = [
            'icao' => 'KABC',
            'metar_enabled' => false,
            'metar_station' => 'KABC'
        ];
        
        $airportC = [
            'icao' => 'KDEF',
            'metar_station' => 'KDEF'
            // metar_enabled not set (defaults to false)
        ];
        
        $this->assertTrue(isMetarEnabled($airportA), 'Airport A should have METAR enabled');
        $this->assertFalse(isMetarEnabled($airportB), 'Airport B should have METAR disabled');
        $this->assertFalse(isMetarEnabled($airportC), 'Airport C should have METAR disabled (default)');
    }
    
    /**
     * Test isMetarEnabled - non-boolean metar_enabled values
     */
    public function testIsMetarEnabled_NonBooleanValue_ReturnsFalse(): void
    {
        $airport1 = [
            'icao' => 'KSPB',
            'metar_enabled' => 1, // truthy but not boolean true
            'metar_station' => 'KSPB'
        ];
        
        $airport2 = [
            'icao' => 'KABC',
            'metar_enabled' => 'true', // string, not boolean
            'metar_station' => 'KABC'
        ];
        
        $this->assertFalse(isMetarEnabled($airport1), 'Should return false for non-boolean truthy value');
        $this->assertFalse(isMetarEnabled($airport2), 'Should return false for string "true"');
    }
}
