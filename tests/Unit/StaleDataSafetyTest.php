<?php
/**
 * Unit Tests for Stale Data Safety Checks
 * 
 * Critical safety feature: ensures pilots don't see dangerously outdated weather data
 */

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../weather.php';

class StaleDataSafetyTest extends TestCase
{
    /**
     * Test nullStaleFieldsBySource - Both sources fresh
     */
    public function testNullStaleFieldsBySource_BothFresh()
    {
        $data = createTestWeatherData([
            'temperature' => 15.0,
            'visibility' => 10.0,
            'ceiling' => 5000,
            'last_updated_primary' => time() - 300,  // 5 minutes ago (fresh)
            'last_updated_metar' => time() - 180     // 3 minutes ago (fresh)
        ]);
        
        $originalTemp = $data['temperature'];
        $originalVisibility = $data['visibility'];
        
        nullStaleFieldsBySource($data, 10800);  // 3 hour threshold
        
        // All data should remain (both sources fresh)
        $this->assertEquals($originalTemp, $data['temperature']);
        $this->assertEquals($originalVisibility, $data['visibility']);
    }

    /**
     * Test nullStaleFieldsBySource - Primary source stale
     */
    public function testNullStaleFieldsBySource_PrimaryStale()
    {
        $data = createTestWeatherData([
            'temperature' => 15.0,
            'dewpoint' => 10.0,
            'humidity' => 70,
            'wind_speed' => 8,
            'pressure' => 30.12,
            'precip_accum' => 0.5,
            'visibility' => 10.0,
            'ceiling' => 5000,
            'last_updated_primary' => time() - 11000,  // ~3+ hours ago (stale)
            'last_updated_metar' => time() - 300        // 5 minutes ago (fresh)
        ]);
        
        nullStaleFieldsBySource($data, 10800);  // 3 hour threshold
        
        // Primary source fields should be nulled
        $this->assertNull($data['temperature']);
        $this->assertNull($data['dewpoint']);
        $this->assertNull($data['humidity']);
        $this->assertNull($data['wind_speed']);
        $this->assertNull($data['pressure']);
        $this->assertNull($data['precip_accum']);
        
        // METAR fields should remain (METAR is fresh)
        $this->assertEquals(10.0, $data['visibility']);
        $this->assertEquals(5000, $data['ceiling']);
    }

    /**
     * Test nullStaleFieldsBySource - METAR source stale
     */
    public function testNullStaleFieldsBySource_METARStale()
    {
        $data = createTestWeatherData([
            'temperature' => 15.0,
            'visibility' => 10.0,
            'ceiling' => 5000,
            'cloud_cover' => 'SCT',
            'last_updated_primary' => time() - 300,  // 5 minutes ago (fresh)
            'last_updated_metar' => time() - 11000    // ~3+ hours ago (stale)
        ]);
        
        nullStaleFieldsBySource($data, 10800);  // 3 hour threshold
        
        // Primary source fields should remain (primary is fresh)
        $this->assertEquals(15.0, $data['temperature']);
        
        // METAR fields should be nulled
        $this->assertNull($data['visibility']);
        $this->assertNull($data['ceiling']);
        $this->assertNull($data['cloud_cover']);
        
        // Flight category should be recalculated (may be null if both visibility and ceiling are null)
        // Verify that flight_category_class is set (indicates recalculation occurred)
        $this->assertArrayHasKey('flight_category_class', $data);
    }

    /**
     * Test nullStaleFieldsBySource - Both sources stale
     */
    public function testNullStaleFieldsBySource_BothStale()
    {
        $data = createTestWeatherData([
            'temperature' => 15.0,
            'visibility' => 10.0,
            'ceiling' => 5000,
            'last_updated_primary' => time() - 11000,  // ~3+ hours ago (stale)
            'last_updated_metar' => time() - 11000      // ~3+ hours ago (stale)
        ]);
        
        nullStaleFieldsBySource($data, 10800);  // 3 hour threshold
        
        // All fields should be nulled
        $this->assertNull($data['temperature']);
        $this->assertNull($data['visibility']);
        $this->assertNull($data['ceiling']);
    }

    /**
     * Test nullStaleFieldsBySource - Daily tracking values preserved
     */
    public function testNullStaleFieldsBySource_DailyTrackingPreserved()
    {
        $data = createTestWeatherData([
            'temperature' => 15.0,
            'temp_high_today' => 20.0,
            'temp_low_today' => 10.0,
            'peak_gust_today' => 25,
            'last_updated_primary' => time() - 11000,  // Stale
            'last_updated_metar' => time() - 11000     // Stale
        ]);
        
        nullStaleFieldsBySource($data, 10800);
        
        // Current temperature should be nulled (from stale primary source)
        $this->assertNull($data['temperature']);
        
        // Daily tracking values should be preserved (never stale)
        $this->assertEquals(20.0, $data['temp_high_today']);
        $this->assertEquals(10.0, $data['temp_low_today']);
        $this->assertEquals(25, $data['peak_gust_today']);
    }

    /**
     * Test nullStaleFieldsBySource - Exactly at threshold
     */
    public function testNullStaleFieldsBySource_AtThreshold()
    {
        $threshold = 10800;  // 3 hours
        $data = createTestWeatherData([
            'temperature' => 15.0,
            'last_updated_primary' => time() - $threshold  // Exactly at threshold
        ]);
        
        nullStaleFieldsBySource($data, $threshold);
        
        // Should still be nulled (>= threshold is stale)
        $this->assertNull($data['temperature']);
    }

    /**
     * Test nullStaleFieldsBySource - Just before threshold
     */
    public function testNullStaleFieldsBySource_JustBeforeThreshold()
    {
        $threshold = 10800;  // 3 hours
        $data = createTestWeatherData([
            'temperature' => 15.0,
            'last_updated_primary' => time() - ($threshold - 1)  // 1 second before threshold
        ]);
        
        nullStaleFieldsBySource($data, $threshold);
        
        // Should remain (not yet stale)
        $this->assertEquals(15.0, $data['temperature']);
    }
}

