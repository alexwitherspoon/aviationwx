<?php
/**
 * Unit Tests for Weather Data Merge Functionality
 * 
 * Tests the mergeWeatherDataWithFallback function that preserves last known good values
 * for fields that are missing or invalid in new data
 */

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../weather.php';
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
     * Test mergeWeatherDataWithFallback - METAR fields should be preserved if not stale
     */
    public function testMergeWeatherDataWithFallback_MetarFieldsPreserved()
    {
        $newData = [
            'temperature' => 16.0,
            'visibility' => null,  // Missing
            'ceiling' => null,  // Missing
            'last_updated_primary' => time() - 1800,
            'last_updated_metar' => time() - 1800,
        ];
        
        $existingData = createTestWeatherData([
            'visibility' => 10.0,
            'ceiling' => 5000,
            'last_updated_metar' => time() - 3600,  // 1 hour ago (not stale)
        ]);
        
        $maxStaleSeconds = 3 * 3600;  // 3 hours
        $result = mergeWeatherDataWithFallback($newData, $existingData, $maxStaleSeconds);
        
        // METAR fields should be preserved
        $this->assertEquals(10.0, $result['visibility'], 'Visibility should be preserved from cache');
        $this->assertEquals(5000, $result['ceiling'], 'Ceiling should be preserved from cache');
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
        $result1 = mergeWeatherDataWithFallback(null, $this->createTestWeatherData(), $maxStaleSeconds);
        $this->assertNull($result1, 'Should return null when newData is null');
        
        // Test with null existingData
        $result2 = mergeWeatherDataWithFallback($this->createTestWeatherData(), null, $maxStaleSeconds);
        $this->assertIsArray($result2, 'Should return array when existingData is null');
        
        // Test with non-array newData
        $result3 = mergeWeatherDataWithFallback('not an array', $this->createTestWeatherData(), $maxStaleSeconds);
        $this->assertEquals('not an array', $result3, 'Should return newData as-is when not array');
    }

    /**
     * Test mergeWeatherDataWithFallback - Both primary and METAR fields can be preserved
     */
    public function testMergeWeatherDataWithFallback_BothSourcesPreserved()
    {
        $newData = [
            'temperature' => 16.0,
            'wind_speed' => null,
            'visibility' => null,
            'last_updated_primary' => time() - 1800,
            'last_updated_metar' => time() - 1800,
        ];
        
        $existingData = createTestWeatherData([
            'wind_speed' => 10,
            'visibility' => 10.0,
            'last_updated_primary' => time() - 3600,  // 1 hour ago (not stale)
            'last_updated_metar' => time() - 3600,     // 1 hour ago (not stale)
        ]);
        
        $maxStaleSeconds = 3 * 3600;  // 3 hours
        $result = mergeWeatherDataWithFallback($newData, $existingData, $maxStaleSeconds);
        
        // Both primary and METAR fields should be preserved
        $this->assertEquals(10, $result['wind_speed'], 'Primary field should be preserved');
        $this->assertEquals(10.0, $result['visibility'], 'METAR field should be preserved');
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
            'visibility' => null,  // Missing
            'last_updated_primary' => time() - 1800,
            'last_updated_metar' => time() - 1800,
        ];
        
        $existingData = createTestWeatherData([
            'wind_speed' => 10,
            'visibility' => 10.0,
            'last_updated_primary' => time() - 4 * 3600,  // 4 hours ago (stale)
            'last_updated_metar' => time() - 3600,        // 1 hour ago (not stale)
        ]);
        
        $maxStaleSeconds = 3 * 3600;  // 3 hours
        $result = mergeWeatherDataWithFallback($newData, $existingData, $maxStaleSeconds);
        
        // Primary field (stale) should not be preserved
        $this->assertNull($result['wind_speed'], 'Stale primary field should not be preserved');
        
        // METAR field (not stale) should be preserved
        $this->assertEquals(10.0, $result['visibility'], 'Non-stale METAR field should be preserved');
    }
}

