<?php
/**
 * Unit Tests for Weather Data Merge Functionality
 * 
 * Tests the mergeWeatherDataWithFallback function that preserves last known good values
 * for fields that are missing or invalid in new data
 */

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../api/weather.php';
require_once __DIR__ . '/../../lib/constants.php';
require_once __DIR__ . '/../Helpers/TestHelper.php';

class WeatherDataMergeTest extends TestCase
{

    /**
     * Test mergeWeatherDataWithFallback - Missing fields in new data should use old values
     */
    public function testMergeWeatherDataWithFallback_MissingFields()
    {
        $newData = [
            'temperature' => 16.0,
            'humidity' => 75,
            'pressure' => 30.1,
            'wind_speed' => null,  // Missing
            'wind_direction' => null,  // Missing
            'last_updated_primary' => time() - 1800,
        ];
        
        $existingData = createTestWeatherData([
            'wind_speed' => 10,
            'wind_direction' => 180,
            'last_updated_primary' => time() - 3600,  // 1 hour ago (not stale)
        ]);
        
        $maxStaleSeconds = 3 * 3600;  // 3 hours
        $result = mergeWeatherDataWithFallback($newData, $existingData, $maxStaleSeconds);
        
        // New values should be used
        $this->assertEquals(16.0, $result['temperature']);
        $this->assertEquals(75, $result['humidity']);
        $this->assertEquals(30.1, $result['pressure']);
        
        // Missing fields should be preserved from cache
        $this->assertEquals(10, $result['wind_speed'], 'Wind speed should be preserved from cache');
        $this->assertEquals(180, $result['wind_direction'], 'Wind direction should be preserved from cache');
    }

    /**
     * Test mergeWeatherDataWithFallback - Stale values should not be preserved
     */
    public function testMergeWeatherDataWithFallback_StaleValuesNotPreserved()
    {
        $newData = [
            'temperature' => 16.0,
            'wind_speed' => null,  // Missing
            'last_updated_primary' => time() - 1800,
        ];
        
        $existingData = createTestWeatherData([
            'wind_speed' => 10,
            'last_updated_primary' => time() - 4 * 3600,  // 4 hours ago (stale)
        ]);
        
        $maxStaleSeconds = 3 * 3600;  // 3 hours
        $result = mergeWeatherDataWithFallback($newData, $existingData, $maxStaleSeconds);
        
        // Stale wind_speed should not be preserved
        $this->assertNull($result['wind_speed'], 'Stale wind speed should not be preserved');
    }

    /**
     * Test mergeWeatherDataWithFallback - METAR fields should NOT be preserved when METAR was fetched
     * When METAR data is successfully fetched (last_updated_metar is set), null values mean
     * unlimited/missing, so they should overwrite old cached values, not preserve them.
     * This test verifies the new behavior where unlimited ceiling overwrites old cache.
     */
    public function testMergeWeatherDataWithFallback_MetarFieldsNotPreservedWhenFetched()
    {
        $newData = [
            'temperature' => 16.0,
            'visibility' => null,  // Unlimited/missing (METAR was fetched)
            'ceiling' => null,  // Unlimited/missing (METAR was fetched)
            'last_updated_primary' => time() - 1800,
            'last_updated_metar' => time() - 1800,  // METAR was successfully fetched
        ];
        
        $existingData = createTestWeatherData([
            'visibility' => 10.0,
            'ceiling' => 5000,
            'last_updated_metar' => time() - 3600,  // 1 hour ago (not stale)
        ]);
        
        $maxStaleSeconds = 3 * 3600;  // 3 hours
        $result = mergeWeatherDataWithFallback($newData, $existingData, $maxStaleSeconds);
        
        // METAR fields should NOT be preserved when METAR was fetched and values are null
        // Null means unlimited/missing, so it should overwrite old cached values
        $this->assertNull($result['visibility'], 'Visibility should be null (unlimited) when METAR fetched with null');
        $this->assertNull($result['ceiling'], 'Ceiling should be null (unlimited) when METAR fetched with null');
    }

    /**
     * Test mergeWeatherDataWithFallback - Daily tracking values should always be preserved
     */
    public function testMergeWeatherDataWithFallback_DailyTrackingAlwaysPreserved()
    {
        $newData = [
            'temperature' => 16.0,
            'last_updated_primary' => time() - 1800,
        ];
        
        $existingData = createTestWeatherData([
            'temp_high_today' => 20.0,
            'temp_low_today' => 10.0,
            'peak_gust_today' => 25,
            'temp_high_ts' => time() - 3600,
            'temp_low_ts' => time() - 7200,
            'peak_gust_time' => time() - 1800,
            'last_updated_primary' => time() - 5 * 3600,  // 5 hours ago (stale, but daily tracking should still be preserved)
        ]);
        
        $maxStaleSeconds = 3 * 3600;  // 3 hours
        $result = mergeWeatherDataWithFallback($newData, $existingData, $maxStaleSeconds);
        
        // Daily tracking values should always be preserved, even if primary source is stale
        $this->assertEquals(20.0, $result['temp_high_today'], 'Daily high temp should always be preserved');
        $this->assertEquals(10.0, $result['temp_low_today'], 'Daily low temp should always be preserved');
        $this->assertEquals(25, $result['peak_gust_today'], 'Daily peak gust should always be preserved');
        $this->assertNotNull($result['temp_high_ts'], 'Daily tracking timestamps should be preserved');
        $this->assertNotNull($result['temp_low_ts'], 'Daily tracking timestamps should be preserved');
        $this->assertNotNull($result['peak_gust_time'], 'Daily tracking timestamps should be preserved');
    }

    /**
     * Test mergeWeatherDataWithFallback - Invalid input handling
     */
    public function testMergeWeatherDataWithFallback_InvalidInput()
    {
        $maxStaleSeconds = 3 * 3600;
        
        // Test with null newData
        $result1 = mergeWeatherDataWithFallback(null, createTestWeatherData(), $maxStaleSeconds);
        $this->assertNull($result1, 'Should return null when newData is null');
        
        // Test with null existingData
        $result2 = mergeWeatherDataWithFallback(createTestWeatherData(), null, $maxStaleSeconds);
        $this->assertIsArray($result2, 'Should return array when existingData is null');
        
        // Test with non-array newData
        $result3 = mergeWeatherDataWithFallback('not an array', createTestWeatherData(), $maxStaleSeconds);
        $this->assertEquals('not an array', $result3, 'Should return newData as-is when not array');
    }

    /**
     * Test mergeWeatherDataWithFallback - Primary fields preserved, METAR null overwrites
     * Primary fields (wind_speed) should be preserved when missing.
     * METAR fields (visibility) should be null when METAR was fetched and value is null.
     */
    public function testMergeWeatherDataWithFallback_BothSourcesPreserved()
    {
        $newData = [
            'temperature' => 16.0,
            'wind_speed' => null,  // Missing from primary source
            'visibility' => null,  // Unlimited/missing from METAR (METAR was fetched)
            'last_updated_primary' => time() - 1800,
            'last_updated_metar' => time() - 1800,  // METAR was successfully fetched
        ];
        
        $existingData = createTestWeatherData([
            'wind_speed' => 10,
            'visibility' => 10.0,
            'last_updated_primary' => time() - 3600,  // 1 hour ago (not stale)
            'last_updated_metar' => time() - 3600,     // 1 hour ago (not stale)
        ]);
        
        $maxStaleSeconds = 3 * 3600;  // 3 hours
        $result = mergeWeatherDataWithFallback($newData, $existingData, $maxStaleSeconds);
        
        // Primary field should be preserved (not fetched, so preserve old value)
        $this->assertEquals(10, $result['wind_speed'], 'Primary field should be preserved');
        
        // METAR field should be null (METAR was fetched, null means unlimited/missing)
        $this->assertNull($result['visibility'], 'METAR field should be null when METAR fetched with null');
    }

    /**
     * Test mergeWeatherDataWithFallback - Field at staleness threshold should not be preserved
     */
    public function testMergeWeatherDataWithFallback_AtThreshold()
    {
        $threshold = 3 * 3600;  // 3 hours
        
        $newData = [
            'temperature' => 16.0,
            'wind_speed' => null,
            'last_updated_primary' => time() - 1800,
        ];
        
        $existingData = createTestWeatherData([
            'wind_speed' => 10,
            'last_updated_primary' => time() - $threshold,  // Exactly at threshold
        ]);
        
        $result = mergeWeatherDataWithFallback($newData, $existingData, $threshold);
        
        // At threshold, field should be considered stale and not preserved
        $this->assertNull($result['wind_speed'], 'Field at threshold should not be preserved');
    }

    /**
     * Test mergeWeatherDataWithFallback - Field just before threshold should be preserved
     */
    public function testMergeWeatherDataWithFallback_JustBeforeThreshold()
    {
        $threshold = 3 * 3600;  // 3 hours
        
        $newData = [
            'temperature' => 16.0,
            'wind_speed' => null,
            'last_updated_primary' => time() - 1800,
        ];
        
        $existingData = createTestWeatherData([
            'wind_speed' => 10,
            'last_updated_primary' => time() - ($threshold - 1),  // 1 second before threshold
        ]);
        
        $result = mergeWeatherDataWithFallback($newData, $existingData, $threshold);
        
        // Just before threshold, field should be preserved
        $this->assertEquals(10, $result['wind_speed'], 'Field just before threshold should be preserved');
    }

    /**
     * Test mergeWeatherDataWithFallback - New values override old values
     */
    public function testMergeWeatherDataWithFallback_NewValuesOverride()
    {
        $newData = [
            'temperature' => 16.0,
            'wind_speed' => 15,  // New value
            'visibility' => 8.0,  // New value
            'last_updated_primary' => time() - 1800,
            'last_updated_metar' => time() - 1800,
        ];
        
        $existingData = createTestWeatherData([
            'temperature' => 15.0,  // Old value
            'wind_speed' => 10,     // Old value
            'visibility' => 10.0,   // Old value
        ]);
        
        $maxStaleSeconds = 3 * 3600;
        $result = mergeWeatherDataWithFallback($newData, $existingData, $maxStaleSeconds);
        
        // New values should override old values
        $this->assertEquals(16.0, $result['temperature'], 'New temperature should override old');
        $this->assertEquals(15, $result['wind_speed'], 'New wind speed should override old');
        $this->assertEquals(8.0, $result['visibility'], 'New visibility should override old');
    }

    /**
     * Test mergeWeatherDataWithFallback - Mixed primary and METAR staleness
     */
    public function testMergeWeatherDataWithFallback_MixedStaleness()
    {
        $newData = [
            'temperature' => 16.0,
            'wind_speed' => null,  // Missing
            // visibility is missing (not in array) - should preserve non-stale old value
            'last_updated_primary' => time() - 1800,
            'last_updated_metar' => time() - 1800,
        ];
        
        $existingData = createTestWeatherData([
            'wind_speed' => 10,
            'visibility' => 10.0,
            'last_updated_primary' => time() - 4 * 3600,  // 4 hours ago (stale)
            'last_updated_metar' => time() - 3600,        // 1 hour ago (not stale for METAR - under 2 hour threshold)
        ]);
        
        $maxStaleSeconds = MAX_STALE_HOURS * 3600;
        $maxStaleSecondsMetar = MAX_STALE_HOURS_METAR * 3600;
        $result = mergeWeatherDataWithFallback($newData, $existingData, $maxStaleSeconds, $maxStaleSecondsMetar);
        
        // Primary field (stale) should not be preserved
        $this->assertNull($result['wind_speed'], 'Stale primary field should not be preserved');
        
        // METAR field (not stale) should be preserved when missing from newData
        $this->assertEquals(10.0, $result['visibility'], 'Non-stale METAR field should be preserved when missing from newData');
    }
    
    /**
     * Test mergeWeatherDataWithFallback - Fresh data should always override old cache
     * This test ensures the bug where fresh data was incorrectly nulled doesn't happen
     * Fresh values should be preserved even if old cache has different values
     */
    public function testMergeWeatherDataWithFallback_FreshDataOverridesOldCache()
    {
        // Fresh data just fetched from API
        $freshData = [
            'temperature' => 12.2,
            'wind_speed' => 3,
            'wind_direction' => 137,
            'gust_speed' => 4,
            'humidity' => 90,
            'last_updated_primary' => time()  // Just fetched
        ];
        
        // Old cache with different (wrong) values
        $oldCache = [
            'temperature' => 16,
            'wind_speed' => 17,
            'wind_direction' => 261,
            'gust_speed' => 22,
            'humidity' => 64,
            'last_updated_primary' => time() - 3600
        ];
        
        $maxStaleSeconds = 3 * 3600;
        $result = mergeWeatherDataWithFallback($freshData, $oldCache, $maxStaleSeconds);
        
        // Fresh values should ALWAYS be used (not old cache values)
        $this->assertEquals(12.2, $result['temperature'], 'Fresh temperature must override old cache');
        $this->assertEquals(3, $result['wind_speed'], 'Fresh wind speed must override old cache');
        $this->assertEquals(137, $result['wind_direction'], 'Fresh wind direction must override old cache');
        $this->assertEquals(4, $result['gust_speed'], 'Fresh gust speed must override old cache');
        $this->assertEquals(90, $result['humidity'], 'Fresh humidity must override old cache');
        
        // Should NOT use old cache values
        $this->assertNotEquals(16, $result['temperature'], 'Should not use old cache temperature');
        $this->assertNotEquals(17, $result['wind_speed'], 'Should not use old cache wind speed');
        $this->assertNotEquals(261, $result['wind_direction'], 'Should not use old cache wind direction');
    }

    /**
     * Test mergeWeatherDataWithFallback - METAR null values should overwrite old cache
     * When METAR data is successfully fetched and ceiling/visibility is null (unlimited),
     * it should overwrite old cached values, not preserve them.
     * This fixes the bug where unlimited ceiling (FEW/SCT clouds) was incorrectly preserved
     * from old cache with a specific ceiling value.
     */
    public function testMergeWeatherDataWithFallback_MetarNullOverwritesOldCache()
    {
        // New METAR data with unlimited ceiling (FEW/SCT clouds)
        $newData = [
            'temperature' => 16.0,
            'ceiling' => null,  // Unlimited ceiling (FEW/SCT clouds) - explicitly null
            // visibility is missing (not in array) - should preserve non-stale old value
            'cloud_cover' => 'SCT',  // Scattered clouds
            'last_updated_primary' => time() - 1800,
            'last_updated_metar' => time() - 1800,  // METAR was successfully fetched
        ];
        
        // Old cache with incorrect ceiling value (from buggy code)
        $existingData = createTestWeatherData([
            'ceiling' => 200,  // Old incorrect value (should be overwritten with null)
            'visibility' => 10.0,  // Old visibility (should be preserved if not stale)
            'last_updated_metar' => time() - 3600,  // 1 hour ago (not stale)
        ]);
        
        $maxStaleSeconds = 3 * 3600;  // 3 hours
        $result = mergeWeatherDataWithFallback($newData, $existingData, $maxStaleSeconds);
        
        // Ceiling should be null (unlimited) - overwrites old cached value
        $this->assertNull($result['ceiling'], 'Unlimited ceiling should overwrite old cached value');
        
        // Visibility should be preserved (not stale) when missing from newData
        $this->assertEquals(10.0, $result['visibility'], 'Non-stale visibility should be preserved when missing from newData');
        
        // Cloud cover should be set
        $this->assertEquals('SCT', $result['cloud_cover'], 'Cloud cover should be set');
    }

    /**
     * Test mergeWeatherDataWithFallback - METAR null values when METAR not fetched
     * When METAR data is NOT fetched (no last_updated_metar), null values should
     * preserve old cached values (normal behavior).
     */
    public function testMergeWeatherDataWithFallback_MetarNullPreservesWhenNotFetched()
    {
        // New data without METAR fetch
        $newData = [
            'temperature' => 16.0,
            'ceiling' => null,  // Missing (METAR not fetched)
            'visibility' => null,  // Missing (METAR not fetched)
            'last_updated_primary' => time() - 1800,
            // No last_updated_metar - METAR was not fetched
        ];
        
        // Old cache with values
        $existingData = createTestWeatherData([
            'ceiling' => 5000,
            'visibility' => 10.0,
            'last_updated_metar' => time() - 3600,  // 1 hour ago (not stale)
        ]);
        
        $maxStaleSeconds = 3 * 3600;  // 3 hours
        $result = mergeWeatherDataWithFallback($newData, $existingData, $maxStaleSeconds);
        
        // Should preserve old values when METAR was not fetched
        $this->assertEquals(5000, $result['ceiling'], 'Should preserve old ceiling when METAR not fetched');
        $this->assertEquals(10.0, $result['visibility'], 'Should preserve old visibility when METAR not fetched');
    }

    /**
     * Test mergeWeatherDataWithFallback - Precipitation should not be preserved from cache
     * Precipitation is a daily value that should reset each day. If missing from new data,
     * it should be set to 0.0 (no precipitation today) rather than preserving yesterday's value.
     */
    public function testMergeWeatherDataWithFallback_PrecipitationNotPreserved()
    {
        // New data without precipitation (missing or null)
        $newData = [
            'temperature' => 16.0,
            'precip_accum' => null,  // Missing from new data
            'last_updated_primary' => time() - 1800,
        ];
        
        // Old cache with yesterday's precipitation value
        $existingData = createTestWeatherData([
            'precip_accum' => 0.5,  // Yesterday's precipitation (should NOT be preserved)
            'last_updated_primary' => time() - 3600,  // 1 hour ago (not stale)
        ]);
        
        $maxStaleSeconds = 3 * 3600;  // 3 hours
        $result = mergeWeatherDataWithFallback($newData, $existingData, $maxStaleSeconds);
        
        // Precipitation should be 0.0 (no precipitation today), NOT preserved from cache
        $this->assertEquals(0.0, $result['precip_accum'], 'Precipitation should be 0.0 when missing, not preserved from cache');
        $this->assertNotEquals(0.5, $result['precip_accum'], 'Should not preserve yesterday\'s precipitation value');
    }

    /**
     * Test mergeWeatherDataWithFallback - Precipitation should use new value when provided
     * When new data includes precipitation, it should be used (not overwritten with 0.0).
     */
    public function testMergeWeatherDataWithFallback_PrecipitationUsesNewValue()
    {
        // New data with precipitation
        $newData = [
            'temperature' => 16.0,
            'precip_accum' => 0.25,  // Today's precipitation
            'last_updated_primary' => time() - 1800,
        ];
        
        // Old cache with different precipitation value
        $existingData = createTestWeatherData([
            'precip_accum' => 0.5,  // Old value (should be overridden)
            'last_updated_primary' => time() - 3600,
        ]);
        
        $maxStaleSeconds = 3 * 3600;
        $result = mergeWeatherDataWithFallback($newData, $existingData, $maxStaleSeconds);
        
        // New precipitation value should be used
        $this->assertEquals(0.25, $result['precip_accum'], 'New precipitation value should be used');
        $this->assertNotEquals(0.5, $result['precip_accum'], 'Should not use old cache precipitation value');
    }
}

