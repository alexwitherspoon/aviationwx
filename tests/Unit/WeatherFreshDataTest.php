<?php
/**
 * Unit Tests for Fresh Weather Data Handling
 * 
 * Tests that fresh data from APIs is not incorrectly nulled out
 * and that merge logic preserves fresh values correctly
 */

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../weather.php';
require_once __DIR__ . '/../Helpers/TestHelper.php';

class WeatherFreshDataTest extends TestCase
{
    /**
     * Test that fresh data is NOT nulled out by staleness check
     * This was the bug: fresh data was being incorrectly nulled, causing merge to preserve old cache values
     */
    public function testFreshData_NotNulledOut()
    {
        // Simulate fresh data just fetched from API
        $freshData = [
            'temperature' => 12.2,
            'wind_speed' => 3,
            'wind_direction' => 137,
            'gust_speed' => 4,
            'humidity' => 90,
            'obs_time_primary' => time() - 60,  // 1 minute ago (very fresh)
            'last_updated_primary' => time()    // Just fetched
        ];
        
        // Old cache with wrong values
        $oldCache = [
            'temperature' => 16,
            'wind_speed' => 17,
            'wind_direction' => 261,
            'gust_speed' => 22,
            'humidity' => 64,
            'last_updated_primary' => time() - 3600  // 1 hour ago
        ];
        
        // Merge should preserve fresh values, not old cache values
        $maxStaleSeconds = 3 * 3600; // 3 hours
        $result = mergeWeatherDataWithFallback($freshData, $oldCache, $maxStaleSeconds);
        
        // Fresh values should be preserved
        $this->assertEquals(12.2, $result['temperature'], 'Fresh temperature should be preserved');
        $this->assertEquals(3, $result['wind_speed'], 'Fresh wind speed should be preserved');
        $this->assertEquals(137, $result['wind_direction'], 'Fresh wind direction should be preserved');
        $this->assertEquals(4, $result['gust_speed'], 'Fresh gust speed should be preserved');
        $this->assertEquals(90, $result['humidity'], 'Fresh humidity should be preserved');
        
        // Should NOT use old cache values
        $this->assertNotEquals(16, $result['temperature'], 'Should not use old cache temperature');
        $this->assertNotEquals(17, $result['wind_speed'], 'Should not use old cache wind speed');
        $this->assertNotEquals(261, $result['wind_direction'], 'Should not use old cache wind direction');
    }
    
    /**
     * Test that test config detection doesn't incorrectly serve mock data
     * Bug: Empty CONFIG_PATH was incorrectly detected as test config
     */
    public function testConfigDetection_NotMockWhenUnset()
    {
        // Save original CONFIG_PATH
        $originalConfigPath = getenv('CONFIG_PATH');
        
        // Clear CONFIG_PATH (should not trigger mock mode)
        putenv('CONFIG_PATH');
        unset($_ENV['CONFIG_PATH']);
        unset($_SERVER['CONFIG_PATH']);
        
        // Test the config detection logic (same as weather.php line 607)
        $envConfigPath = getenv('CONFIG_PATH');
        $isTestConfig = ($envConfigPath && strpos($envConfigPath, 'airports.json.test') !== false);
        
        $this->assertFalse($isTestConfig, 'Unset CONFIG_PATH should not trigger test config mode');
        
        // Restore original
        if ($originalConfigPath !== false) {
            putenv('CONFIG_PATH=' . $originalConfigPath);
        }
    }
    
    /**
     * Test that test config detection works correctly when explicitly set
     */
    public function testConfigDetection_MockWhenExplicitlySet()
    {
        // Save original CONFIG_PATH
        $originalConfigPath = getenv('CONFIG_PATH');
        
        // Set CONFIG_PATH to test config (should trigger mock mode)
        $testConfigPath = __DIR__ . '/../Fixtures/airports.json.test';
        putenv('CONFIG_PATH=' . $testConfigPath);
        
        // Test the config detection logic
        $envConfigPath = getenv('CONFIG_PATH');
        $isTestConfig = ($envConfigPath && strpos($envConfigPath, 'airports.json.test') !== false);
        
        $this->assertTrue($isTestConfig, 'Explicit test config path should trigger test config mode');
        
        // Restore original
        if ($originalConfigPath !== false) {
            putenv('CONFIG_PATH=' . $originalConfigPath);
        } else {
            putenv('CONFIG_PATH');
        }
    }
    
    /**
     * Test that merge preserves fresh values even when old cache has values
     * This ensures the merge doesn't accidentally use old cache when new data is present
     */
    public function testMerge_PreservesFreshValues()
    {
        $freshData = [
            'temperature' => 12.3,
            'wind_speed' => 1,
            'wind_direction' => 116,
            'gust_speed' => 3,
            'humidity' => 89,
            'last_updated_primary' => time()
        ];
        
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
        
        // ALL fresh values should be preserved
        $this->assertEquals(12.3, $result['temperature']);
        $this->assertEquals(1, $result['wind_speed']);
        $this->assertEquals(116, $result['wind_direction']);
        $this->assertEquals(3, $result['gust_speed']);
        $this->assertEquals(89, $result['humidity']);
    }
}

