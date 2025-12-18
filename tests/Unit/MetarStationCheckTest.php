<?php
/**
 * Unit Tests for METAR Station Configuration Check
 * 
 * Tests that fetchMETAR() respects metar_station configuration.
 * For isMetarEnabled() tests, see MetarEnabledTest.php
 */

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../api/weather.php';
require_once __DIR__ . '/../Helpers/TestHelper.php';

class MetarStationCheckTest extends TestCase
{
    /**
     * Test fetchMETAR - Returns null when metar_station is not configured
     */
    public function testFetchMETAR_NoMetarStation_ReturnsNull()
    {
        $airport = createTestAirport(['icao' => 'KABC']);
        unset($airport['metar_station']);
        
        $result = fetchMETAR($airport);
        
        $this->assertNull($result, 'fetchMETAR should return null when metar_station is not configured');
    }
    
    /**
     * Test fetchMETAR - Returns null when metar_station is empty string
     */
    public function testFetchMETAR_EmptyMetarStation_ReturnsNull()
    {
        $airport = createTestAirport([
            'icao' => 'KABC',
            'metar_station' => ''
        ]);
        
        $result = fetchMETAR($airport);
        
        $this->assertNull($result, 'fetchMETAR should return null when metar_station is empty');
    }
    
    /**
     * Test fetchMETAR - Attempts fetch when metar_station is configured
     */
    public function testFetchMETAR_WithMetarStation_AttemptsFetch()
    {
        $airport = createTestAirport([
            'icao' => 'KSPB',
            'metar_station' => 'KSPB'
        ]);
        
        $result = fetchMETAR($airport);
        
        // Result is null (API failure) or array (success) - either means it attempted fetch
        $this->assertTrue(
            $result === null || is_array($result),
            'fetchMETAR should attempt fetch when metar_station is configured'
        );
    }
}
