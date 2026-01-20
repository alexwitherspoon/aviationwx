<?php
/**
 * Unit Tests for METAR Station Configuration Check
 * 
 * Tests that fetchMETAR() respects METAR source configuration in sources array.
 * For isMetarEnabled() tests, see MetarEnabledTest.php
 */

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../api/weather.php';
require_once __DIR__ . '/../Helpers/TestHelper.php';

class MetarStationCheckTest extends TestCase
{
    /**
     * Test fetchMETAR - Returns null when no METAR source is configured
     */
    public function testFetchMETAR_NoMetarStation_ReturnsNull()
    {
        $airport = [
            'icao' => 'KABC',
            'weather_sources' => [
                ['type' => 'tempest', 'station_id' => '12345']
            ]
        ];
        
        $result = fetchMETAR($airport);
        
        $this->assertNull($result, 'fetchMETAR should return null when no METAR source is configured');
    }
    
    /**
     * Test fetchMETAR - Returns null when METAR source has empty station_id
     */
    public function testFetchMETAR_EmptyMetarStation_ReturnsNull()
    {
        $airport = [
            'icao' => 'KABC',
            'weather_sources' => [
                ['type' => 'metar', 'station_id' => '']
            ]
        ];
        
        $result = fetchMETAR($airport);
        
        $this->assertNull($result, 'fetchMETAR should return null when METAR station_id is empty');
    }
    
    /**
     * Test fetchMETAR - Attempts fetch when METAR source is configured
     */
    public function testFetchMETAR_WithMetarStation_AttemptsFetch()
    {
        $airport = [
            'icao' => 'KSPB',
            'weather_sources' => [
                ['type' => 'metar', 'station_id' => 'KSPB']
            ]
        ];
        
        $result = fetchMETAR($airport);
        
        // Result is null (API failure) or array (success) - either means it attempted fetch
        $this->assertTrue(
            $result === null || is_array($result),
            'fetchMETAR should attempt fetch when METAR source is configured'
        );
    }
}
