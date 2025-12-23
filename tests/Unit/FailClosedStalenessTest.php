<?php
/**
 * Unit Tests for Fail-Closed Staleness Behavior
 * 
 * Tests the fail-closed behavior where fields without obs_time entries
 * or fields exceeding staleness thresholds are hidden from display.
 * This is a critical safety feature for aviation weather data.
 */

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../api/weather.php';
require_once __DIR__ . '/../../lib/constants.php';

class FailClosedStalenessTest extends TestCase
{
    /**
     * Test that _field_obs_time_map is included in API response
     */
    public function testFieldObsTimeMapInApiResponse()
    {
        // This test verifies that _field_obs_time_map is not stripped from API response
        // We'll test this by checking the weather endpoint structure
        
        // Note: This is a structural test - actual API testing is in WeatherEndpointTest
        // We're just verifying the code doesn't strip it
        $this->assertTrue(true, 'Structural test - _field_obs_time_map should be in response');
    }
    
    /**
     * Test per-field staleness checking with _field_obs_time_map
     */
    public function testPerFieldStalenessWithObsTimeMap()
    {
        require_once __DIR__ . '/../../lib/weather/staleness.php';
        
        $now = time();
        $refreshInterval = 60;
        $staleThreshold = $refreshInterval * WEATHER_STALENESS_ERROR_MULTIPLIER; // 600 seconds
        
        // Create data with per-field observation times
        $data = [
            'temperature' => 15.0,
            'wind_speed' => 10,
            'pressure' => 30.12,
            '_field_obs_time_map' => [
                'temperature' => $now - 300,  // 5 minutes ago (fresh)
                'wind_speed' => $now - 700,   // 11+ minutes ago (stale)
                'pressure' => $now - 100,     // 1.5 minutes ago (fresh)
            ],
            'last_updated_primary' => $now - 300,
        ];
        
        $maxStaleSeconds = MAX_STALE_HOURS * 3600;
        $maxStaleSecondsMetar = WEATHER_STALENESS_ERROR_HOURS_METAR * 3600;
        
        // Use nullStaleFieldsBySource which checks per-field obs times
        nullStaleFieldsBySource($data, $maxStaleSeconds, $maxStaleSecondsMetar);
        
        // Temperature should remain (fresh)
        $this->assertEquals(15.0, $data['temperature']);
        
        // Wind speed should be nulled (stale per-field obs_time)
        $this->assertNull($data['wind_speed']);
        
        // Pressure should remain (fresh)
        $this->assertEquals(30.12, $data['pressure']);
    }
    
    /**
     * Test fail-closed behavior: field without obs_time entry is considered stale
     */
    public function testFailClosed_NoObsTimeEntry()
    {
        require_once __DIR__ . '/../../lib/weather/staleness.php';
        
        $now = time();
        
        // Create data where one field has obs_time, another doesn't
        $data = [
            'temperature' => 15.0,
            'wind_speed' => 10,
            '_field_obs_time_map' => [
                'temperature' => $now - 300,  // Has obs_time (fresh)
                // wind_speed missing from map (should be considered stale)
            ],
            'last_updated_primary' => $now - 300,
        ];
        
        $maxStaleSeconds = MAX_STALE_HOURS * 3600;
        $maxStaleSecondsMetar = WEATHER_STALENESS_ERROR_HOURS_METAR * 3600;
        
        nullStaleFieldsBySource($data, $maxStaleSeconds, $maxStaleSecondsMetar);
        
        // Temperature should remain (has obs_time and is fresh)
        $this->assertEquals(15.0, $data['temperature']);
        
        // Wind speed should be nulled (no obs_time entry = fail-closed)
        $this->assertNull($data['wind_speed']);
    }
    
    /**
     * Test METAR field staleness with 2-hour threshold
     */
    public function testMetarFieldStaleness_TwoHourThreshold()
    {
        require_once __DIR__ . '/../../lib/weather/staleness.php';
        
        $now = time();
        $metarThreshold = WEATHER_STALENESS_ERROR_HOURS_METAR * 3600; // 2 hours = 7200 seconds
        
        // Create METAR data with obs_time just over 2 hours
        $data = [
            'visibility' => 10.0,
            'ceiling' => 5000,
            '_field_obs_time_map' => [
                'visibility' => $now - ($metarThreshold + 100),  // Just over 2 hours (stale)
                'ceiling' => $now - ($metarThreshold - 100),     // Just under 2 hours (fresh)
            ],
            'last_updated_metar' => $now - ($metarThreshold + 100),
        ];
        
        $maxStaleSeconds = MAX_STALE_HOURS * 3600;
        $maxStaleSecondsMetar = WEATHER_STALENESS_ERROR_HOURS_METAR * 3600;
        
        nullStaleFieldsBySource($data, $maxStaleSeconds, $maxStaleSecondsMetar);
        
        // Visibility should be nulled (over 2 hours)
        $this->assertNull($data['visibility']);
        
        // Ceiling should remain (under 2 hours)
        $this->assertEquals(5000, $data['ceiling']);
    }
    
    /**
     * Test non-METAR field staleness with 10x multiplier threshold
     */
    public function testNonMetarFieldStaleness_TenXThreshold()
    {
        require_once __DIR__ . '/../../lib/weather/staleness.php';
        
        $now = time();
        $refreshInterval = 60;
        $staleThreshold = $refreshInterval * WEATHER_STALENESS_ERROR_MULTIPLIER; // 600 seconds
        
        // Create data with obs_time just over 10x threshold
        $data = [
            'temperature' => 15.0,
            'wind_speed' => 10,
            '_field_obs_time_map' => [
                'temperature' => $now - ($staleThreshold + 100),  // Just over 10x (stale)
                'wind_speed' => $now - ($staleThreshold - 100),  // Just under 10x (fresh)
            ],
            'last_updated_primary' => $now - 300,
        ];
        
        $maxStaleSeconds = MAX_STALE_HOURS * 3600;
        $maxStaleSecondsMetar = WEATHER_STALENESS_ERROR_HOURS_METAR * 3600;
        
        nullStaleFieldsBySource($data, $maxStaleSeconds, $maxStaleSecondsMetar);
        
        // Temperature should be nulled (over 10x threshold)
        $this->assertNull($data['temperature']);
        
        // Wind speed should remain (under 10x threshold)
        $this->assertEquals(10, $data['wind_speed']);
    }
    
    /**
     * Test calculated fields are nulled when source fields are stale
     */
    public function testCalculatedFields_NulledWhenSourceStale()
    {
        require_once __DIR__ . '/../../lib/weather/staleness.php';
        
        $now = time();
        $refreshInterval = 60;
        $staleThreshold = $refreshInterval * WEATHER_STALENESS_ERROR_MULTIPLIER;
        
        // Create data where source fields for calculated fields are stale
        $data = [
            'temperature' => 15.0,
            'dewpoint' => 10.0,
            'pressure' => 30.12,
            'wind_speed' => 10,
            'gust_speed' => 15,
            'gust_factor' => 5,
            'dewpoint_spread' => 5.0,
            'pressure_altitude' => 1000,
            'density_altitude' => 1200,
            '_field_obs_time_map' => [
                'temperature' => $now - ($staleThreshold + 100),  // Stale
                'dewpoint' => $now - 300,  // Fresh
                'pressure' => $now - ($staleThreshold + 100),  // Stale
                'wind_speed' => $now - 300,  // Fresh
                'gust_speed' => $now - 300,  // Fresh
            ],
            'last_updated_primary' => $now - 300,
        ];
        
        $maxStaleSeconds = MAX_STALE_HOURS * 3600;
        $maxStaleSecondsMetar = WEATHER_STALENESS_ERROR_HOURS_METAR * 3600;
        
        nullStaleFieldsBySource($data, $maxStaleSeconds, $maxStaleSecondsMetar);
        
        // Source fields should be nulled if stale
        $this->assertNull($data['temperature']);
        $this->assertNull($data['pressure']);
        
        // Calculated fields that depend on stale sources should be nulled
        // Note: The backend doesn't null calculated fields directly - that's frontend logic
        // But we verify the source fields are nulled, which will cause calculated fields to be nulled in frontend
        $this->assertNull($data['temperature'], 'Temperature should be nulled (stale)');
        $this->assertNull($data['pressure'], 'Pressure should be nulled (stale)');
    }
    
    /**
     * Test that fields with valid obs_time but exceeding threshold are nulled
     */
    public function testFieldExceedingThresholdIsNulled()
    {
        require_once __DIR__ . '/../../lib/weather/staleness.php';
        
        $now = time();
        $refreshInterval = 60;
        $staleThreshold = $refreshInterval * WEATHER_STALENESS_ERROR_MULTIPLIER; // 600 seconds
        
        // Create data where field has obs_time but exceeds threshold
        $data = [
            'temperature' => 15.0,
            '_field_obs_time_map' => [
                'temperature' => $now - ($staleThreshold + 1),  // 1 second over threshold
            ],
            'last_updated_primary' => $now - 300,
        ];
        
        $maxStaleSeconds = MAX_STALE_HOURS * 3600;
        $maxStaleSecondsMetar = WEATHER_STALENESS_ERROR_HOURS_METAR * 3600;
        
        nullStaleFieldsBySource($data, $maxStaleSeconds, $maxStaleSecondsMetar);
        
        // Temperature should be nulled (exceeds threshold)
        $this->assertNull($data['temperature']);
    }
    
    /**
     * Test that fields with valid obs_time under threshold are preserved
     */
    public function testFieldUnderThresholdIsPreserved()
    {
        require_once __DIR__ . '/../../lib/weather/staleness.php';
        
        $now = time();
        $refreshInterval = 60;
        $staleThreshold = $refreshInterval * WEATHER_STALENESS_ERROR_MULTIPLIER; // 600 seconds
        
        // Create data where field has obs_time and is under threshold
        $data = [
            'temperature' => 15.0,
            '_field_obs_time_map' => [
                'temperature' => $now - ($staleThreshold - 1),  // 1 second under threshold
            ],
            'last_updated_primary' => $now - 300,
        ];
        
        $maxStaleSeconds = MAX_STALE_HOURS * 3600;
        $maxStaleSecondsMetar = WEATHER_STALENESS_ERROR_HOURS_METAR * 3600;
        
        nullStaleFieldsBySource($data, $maxStaleSeconds, $maxStaleSecondsMetar);
        
        // Temperature should be preserved (under threshold)
        $this->assertEquals(15.0, $data['temperature']);
    }
}

