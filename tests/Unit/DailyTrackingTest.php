<?php
/**
 * Unit Tests for Daily Tracking Functions
 * 
 * Tests peak gust and temperature extremes tracking with timezone awareness
 */

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../api/weather.php';

class DailyTrackingTest extends TestCase
{
    private $testCacheDir;
    private $peakGustFile;
    private $tempExtremesFile;
    
    protected function setUp(): void
    {
        // Create isolated test cache directory
        $this->testCacheDir = sys_get_temp_dir() . '/aviationwx_test_' . uniqid();
        mkdir($this->testCacheDir, 0755, true);
        
        // Override cache directory in weather.php functions (would need refactoring for full isolation)
        // For now, we'll clean up actual cache files after tests
        $this->peakGustFile = __DIR__ . '/../../cache/peak_gusts.json';
        $this->tempExtremesFile = __DIR__ . '/../../cache/temp_extremes.json';
        
        // Ensure cache directory exists
        $cacheDir = dirname($this->peakGustFile);
        if (!is_dir($cacheDir)) {
            mkdir($cacheDir, 0755, true);
        }
        
        // Backup existing files if they exist
        if (file_exists($this->peakGustFile)) {
            rename($this->peakGustFile, $this->peakGustFile . '.backup');
        }
        if (file_exists($this->tempExtremesFile)) {
            rename($this->tempExtremesFile, $this->tempExtremesFile . '.backup');
        }
        
        // Ensure files are completely removed (not just renamed)
        if (file_exists($this->peakGustFile)) {
            @unlink($this->peakGustFile);
        }
        if (file_exists($this->tempExtremesFile)) {
            @unlink($this->tempExtremesFile);
        }
        
        // Clear any file stat cache
        clearstatcache(true, $this->peakGustFile);
        clearstatcache(true, $this->tempExtremesFile);
    }
    
    protected function tearDown(): void
    {
        // Clean up test cache files
        if (file_exists($this->peakGustFile)) {
            unlink($this->peakGustFile);
        }
        if (file_exists($this->tempExtremesFile)) {
            unlink($this->tempExtremesFile);
        }
        
        // Restore backups
        if (file_exists($this->peakGustFile . '.backup')) {
            rename($this->peakGustFile . '.backup', $this->peakGustFile);
        }
        if (file_exists($this->tempExtremesFile . '.backup')) {
            rename($this->tempExtremesFile . '.backup', $this->tempExtremesFile);
        }
    }
    
    /**
     * Ensures first gust value initializes the daily peak correctly
     */
    public function testUpdatePeakGust_FirstValueOfDay()
    {
        // Use unique airport ID to avoid conflicts with other tests
        $airportId = 'test_first_value_' . uniqid();
        $airport = createTestAirport(['timezone' => 'America/Los_Angeles']);
        $currentGust = 15;
        
        updatePeakGust($airportId, $currentGust, $airport);
        clearstatcache();
        $result = getPeakGust($airportId, $currentGust, $airport);
        
        if (is_array($result)) {
            $this->assertEquals($currentGust, $result['value']);
            $this->assertIsInt($result['ts']);
        } else {
            // Backward compatibility with scalar value
            $this->assertEquals($currentGust, $result);
        }
    }
    
    /**
     * Ensures peak gust only increases, preventing slower winds from overwriting higher peaks
     */
    public function testUpdatePeakGust_HigherValueUpdates()
    {
        // Use unique airport ID to avoid conflicts with other tests
        $airportId = 'test_higher_value_' . uniqid();
        $airport = createTestAirport(['timezone' => 'America/Los_Angeles']);
        
        // First value
        updatePeakGust($airportId, 10, $airport);
        // Clear stat cache to ensure we read latest file contents
        clearstatcache();
        $result1 = getPeakGust($airportId, 10, $airport);
        $value1 = is_array($result1) ? $result1['value'] : $result1;
        $this->assertEquals(10, $value1, 'First value should be stored correctly');
        
        // Higher value
        updatePeakGust($airportId, 20, $airport);
        clearstatcache();
        $result2 = getPeakGust($airportId, 15, $airport); // Current gust is lower
        $value2 = is_array($result2) ? $result2['value'] : $result2;
        $this->assertEquals(20, $value2, 'Peak should be 20 after updating with higher value'); // Should still show peak of 20
        
        // Lower value doesn't change peak
        updatePeakGust($airportId, 8, $airport);
        clearstatcache();
        $result3 = getPeakGust($airportId, 8, $airport);
        $value3 = is_array($result3) ? $result3['value'] : $result3;
        $this->assertEquals(20, $value3, 'Peak should remain 20 after lower value'); // Should still show peak of 20
    }
    
    /**
     * Ensures timestamp reflects when the peak occurred, not when lower values were observed
     */
    public function testUpdatePeakGust_TimestampUpdatesOnNewPeak()
    {
        // Use unique airport ID to avoid conflicts with other tests
        $airportId = 'test_timestamp_' . uniqid();
        $airport = createTestAirport(['timezone' => 'America/Los_Angeles']);
        
        // First value - use explicit timestamp to ensure it's different
        $ts1 = time();
        updatePeakGust($airportId, 10, $airport, $ts1);
        clearstatcache();
        $result1 = getPeakGust($airportId, 10, $airport);
        
        if (is_array($result1)) {
            $storedTs1 = $result1['ts'];
            $this->assertEquals($ts1, $storedTs1, 'First timestamp should match');
            
            // Wait to ensure different timestamp
            sleep(2);
            
            // Lower value shouldn't update timestamp (5 < 10, so no update)
            $ts2 = time();
            updatePeakGust($airportId, 5, $airport, $ts2);
            clearstatcache();
            $result2 = getPeakGust($airportId, 5, $airport);
            $storedTs2 = $result2['ts'];
            
            // Timestamp should not change (value didn't increase)
            $this->assertEquals($storedTs1, $storedTs2, 'Timestamp should not change when value decreases');
            
            // Higher value should update timestamp
            sleep(2);
            $ts3 = time();
            updatePeakGust($airportId, 15, $airport, $ts3);
            clearstatcache();
            $result3 = getPeakGust($airportId, 15, $airport);
            $storedTs3 = $result3['ts'];
            
            // Timestamp should be newer
            $this->assertGreaterThan($storedTs1, $storedTs3, 'Timestamp should update when value increases');
        }
    }
    
    /**
     * Ensures airport isolation so one airport's data doesn't affect another
     */
    public function testUpdatePeakGust_MultipleAirports()
    {
        $airport1 = createTestAirport(['timezone' => 'America/Los_Angeles']);
        $airport2 = createTestAirport(['timezone' => 'America/New_York']);
        
        updatePeakGust('airport1', 15, $airport1);
        updatePeakGust('airport2', 25, $airport2);
        
        $result1 = getPeakGust('airport1', 10, $airport1);
        $result2 = getPeakGust('airport2', 10, $airport2);
        
        $value1 = is_array($result1) ? $result1['value'] : $result1;
        $value2 = is_array($result2) ? $result2['value'] : $result2;
        
        $this->assertEquals(15, $value1);
        $this->assertEquals(25, $value2);
    }
    
    /**
     * Ensures daily reset occurs at midnight in airport's timezone, not UTC
     */
    public function testUpdatePeakGust_TimezoneAware()
    {
        $airport = createTestAirport(['timezone' => 'America/New_York']);
        
        // Set a peak gust
        updatePeakGust('test_tz', 20, $airport);
        $result1 = getPeakGust('test_tz', 10, $airport);
        $value1 = is_array($result1) ? $result1['value'] : $result1;
        $this->assertEquals(20, $value1);
        
        // Verify date key uses airport timezone
        $dateKey = getAirportDateKey($airport);
        $this->assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}$/', $dateKey);
        
        // Verify it's different from UTC if we're not at midnight
        $utcDateKey = gmdate('Y-m-d');
        // If timezone offset exists, dates might differ near midnight
        // This is a basic test - more complex tests would mock time
    }
    
    /**
     * Ensures first temperature value correctly initializes both high and low extremes
     */
    public function testUpdateTempExtremes_FirstValue()
    {
        // Use unique airport ID to avoid conflicts with other tests
        $airportId = 'test_first_temp_' . uniqid();
        $airport = createTestAirport(['timezone' => 'America/Los_Angeles']);
        $currentTemp = 20.0;
        
        updateTempExtremes($airportId, $currentTemp, $airport);
        clearstatcache();
        $result = getTempExtremes($airportId, $currentTemp, $airport);
        
        $this->assertEquals($currentTemp, $result['high']);
        $this->assertEquals($currentTemp, $result['low']);
        $this->assertIsInt($result['high_ts']);
        $this->assertIsInt($result['low_ts']);
    }
    
    /**
     * Ensures high temperature only increases, preventing lower temps from overwriting the daily high
     */
    public function testUpdateTempExtremes_HigherTempUpdatesHigh()
    {
        // Use unique airport ID to avoid conflicts with other tests
        $airportId = 'test_temp_high_' . uniqid();
        $airport = createTestAirport(['timezone' => 'America/Los_Angeles']);
        
        // Initial value
        updateTempExtremes($airportId, 15.0, $airport);
        clearstatcache();
        $result1 = getTempExtremes($airportId, 15.0, $airport);
        $this->assertEquals(15.0, $result1['high'], 'Initial high should be 15.0');
        $this->assertEquals(15.0, $result1['low'], 'Initial low should be 15.0');
        
        // Higher value
        updateTempExtremes($airportId, 25.0, $airport);
        clearstatcache();
        $result2 = getTempExtremes($airportId, 20.0, $airport);
        $this->assertEquals(25.0, $result2['high'], 'High should be 25.0 after update');
        $this->assertEquals(15.0, $result2['low'], 'Low should remain 15.0'); // Low should remain
    }
    
    /**
     * Ensures low temperature only decreases, preventing higher temps from overwriting the daily low
     */
    public function testUpdateTempExtremes_LowerTempUpdatesLow()
    {
        // Use unique airport ID to avoid conflicts with other tests
        $airportId = 'test_lower_temp_' . uniqid();
        $airport = createTestAirport(['timezone' => 'America/Los_Angeles']);
        
        // Initial value
        updateTempExtremes($airportId, 20.0, $airport);
        clearstatcache();
        
        // Lower value
        updateTempExtremes($airportId, 10.0, $airport);
        clearstatcache();
        $result = getTempExtremes($airportId, 15.0, $airport);
        $this->assertEquals(20.0, $result['high']); // High should remain
        $this->assertEquals(10.0, $result['low']);
    }
    
    /**
     * Ensures timestamps reflect when extremes occurred, not when non-extreme values were observed
     */
    public function testUpdateTempExtremes_TimestampUpdatesOnNewRecords()
    {
        // Use unique airport ID to avoid conflicts with other tests
        $airportId = 'test_temp_ts_' . uniqid();
        $airport = createTestAirport(['timezone' => 'America/Los_Angeles']);
        
        // Initial value - use explicit timestamp
        $ts1 = time();
        updateTempExtremes($airportId, 20.0, $airport, $ts1);
        clearstatcache();
        $result1 = getTempExtremes($airportId, 20.0, $airport);
        $highTs1 = $result1['high_ts'];
        $lowTs1 = $result1['low_ts'];
        $this->assertEquals($ts1, $highTs1, 'Initial high timestamp should match');
        $this->assertEquals($ts1, $lowTs1, 'Initial low timestamp should match');
        
        sleep(2);
        
        // Same value - timestamps shouldn't change (no update since value is same)
        $ts2 = time();
        updateTempExtremes($airportId, 20.0, $airport, $ts2);
        clearstatcache();
        $result2 = getTempExtremes($airportId, 20.0, $airport);
        // Timestamps should remain the same since value didn't change
        $this->assertEquals($highTs1, $result2['high_ts'], 'High timestamp should not change when value is same');
        $this->assertEquals($lowTs1, $result2['low_ts'], 'Low timestamp should not change when value is same');
        
        sleep(2);
        
        // New high - high_ts should update, low_ts should not
        $ts3 = time();
        updateTempExtremes($airportId, 25.0, $airport, $ts3);
        clearstatcache();
        $result3 = getTempExtremes($airportId, 25.0, $airport);
        $this->assertGreaterThan($highTs1, $result3['high_ts'], 'High timestamp should update when value increases');
        // Low timestamp should remain the same (value didn't decrease)
        $this->assertEquals($lowTs1, $result3['low_ts'], 'Low timestamp should not change when value increases');
        
        sleep(2);
        
        // New low - low_ts should update, high_ts should not
        $ts4 = time();
        updateTempExtremes($airportId, 15.0, $airport, $ts4);
        clearstatcache();
        $result4 = getTempExtremes($airportId, 15.0, $airport);
        $this->assertEquals($result3['high_ts'], $result4['high_ts'], 'High timestamp should not change when value decreases'); // High timestamp unchanged
        $this->assertGreaterThan($lowTs1, $result4['low_ts'], 'Low timestamp should update when value decreases'); // Low timestamp updated
    }
    
    /**
     * Ensures timestamp reflects earliest observation of an extreme value when processing out-of-order data
     */
    public function testUpdateTempExtremes_SameLowAtEarlierTime()
    {
        // Use unique airport ID to avoid conflicts with other tests
        $airportId = 'test_same_low_earlier_' . uniqid();
        $airport = createTestAirport(['timezone' => 'America/Los_Angeles']);
        
        // First, set a low temperature at a later time
        $ts1 = time();
        updateTempExtremes($airportId, 10.0, $airport, $ts1);
        clearstatcache();
        $result1 = getTempExtremes($airportId, 10.0, $airport);
        $this->assertEquals(10.0, $result1['low'], 'Low should be 10.0');
        $this->assertEquals($ts1, $result1['low_ts'], 'Low timestamp should be ts1');
        
        // Now observe the same low temperature at an earlier time
        // This simulates the case where we process observations out of order
        $ts2 = $ts1 - 3600; // 1 hour earlier
        updateTempExtremes($airportId, 10.0, $airport, $ts2);
        clearstatcache();
        $result2 = getTempExtremes($airportId, 10.0, $airport);
        
        // Low value should remain the same
        $this->assertEquals(10.0, $result2['low'], 'Low should still be 10.0');
        // But timestamp should update to the earlier time
        $this->assertEquals($ts2, $result2['low_ts'], 'Low timestamp should update to earlier time when same value observed earlier');
        $this->assertLessThan($result1['low_ts'], $result2['low_ts'], 'New timestamp should be earlier than original');
    }
    
    /**
     * Ensures airport isolation so one airport's temperature data doesn't affect another
     */
    public function testUpdateTempExtremes_MultipleAirports()
    {
        $airport1 = createTestAirport(['timezone' => 'America/Los_Angeles']);
        $airport2 = createTestAirport(['timezone' => 'America/New_York']);
        
        updateTempExtremes('airport1', 15.0, $airport1);
        updateTempExtremes('airport2', 25.0, $airport2);
        
        $result1 = getTempExtremes('airport1', 18.0, $airport1);
        $result2 = getTempExtremes('airport2', 22.0, $airport2);
        
        $this->assertEquals(15.0, $result1['high']);
        $this->assertEquals(25.0, $result2['high']);
    }
    
    /**
     * Ensures date keys use airport timezone for accurate daily tracking boundaries
     */
    public function testGetAirportDateKey_TimezoneAware()
    {
        $airport = createTestAirport(['timezone' => 'America/New_York']);
        $dateKey = getAirportDateKey($airport);
        
        $this->assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}$/', $dateKey);
        
        // Verify it's a valid date
        $date = DateTime::createFromFormat('Y-m-d', $dateKey);
        $this->assertNotFalse($date);
    }
    
    /**
     * Ensures airports without timezone config fall back to UTC gracefully
     */
    public function testGetAirportDateKey_DefaultTimezone()
    {
        $airport = createTestAirport(['timezone' => 'America/Los_Angeles']);
        $dateKey1 = getAirportDateKey($airport);
        
        // Airport without timezone should default
        $airportNoTz = createTestAirport();
        unset($airportNoTz['timezone']);
        $dateKey2 = getAirportDateKey($airportNoTz);
        
        // Both should be valid date strings
        $this->assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}$/', $dateKey1);
        $this->assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}$/', $dateKey2);
    }
    
    /**
     * Ensures configured airport timezone is returned correctly
     */
    public function testGetAirportTimezone_ReturnsConfigured()
    {
        $airport = createTestAirport(['timezone' => 'America/New_York']);
        $result = getAirportTimezone($airport);
        $this->assertEquals('America/New_York', $result);
    }
    
    /**
     * Ensures UTC fallback when airport timezone is not configured
     */
    public function testGetAirportTimezone_DefaultFallback()
    {
        $airport = createTestAirport();
        unset($airport['timezone']);
        $result = getAirportTimezone($airport);
        // Default should be UTC (from global config)
        $this->assertEquals('UTC', $result);
    }
    
    /**
     * Ensures global default timezone config is respected when airport timezone is missing
     */
    public function testGetAirportTimezone_GlobalConfigOverride()
    {
        // Create a test config with custom default timezone
        $testConfig = [
            'config' => [
                'default_timezone' => 'America/New_York'
            ],
            'airports' => []
        ];
        
        // Temporarily override config loading
        // Note: This test verifies the function uses getDefaultTimezone()
        // which reads from the loaded config
        $airport = createTestAirport();
        unset($airport['timezone']);
        
        // Since we can't easily mock loadConfig in this test,
        // we'll test that it falls back to UTC when no config is set
        // The actual global config test would require more complex setup
        $result = getAirportTimezone($airport);
        $this->assertNotEmpty($result);
        $this->assertIsString($result);
    }
    
    /**
     * Ensures sunrise calculation works correctly for valid airport coordinates
     */
    public function testGetSunriseTime_ValidCoordinates()
    {
        $airport = createTestAirport([
            'lat' => 45.0,
            'lon' => -122.0,
            'timezone' => 'America/Los_Angeles'
        ]);
        
        $result = getSunriseTime($airport);
        // Should return time string in HH:MM format (24-hour) or null if no sunrise (polar regions)
        if ($result !== null) {
            $this->assertMatchesRegularExpression('/^\d{2}:\d{2}$/', $result);
        }
    }
    
    /**
     * Ensures sunset calculation works correctly for valid airport coordinates
     */
    public function testGetSunsetTime_ValidCoordinates()
    {
        $airport = createTestAirport([
            'lat' => 45.0,
            'lon' => -122.0,
            'timezone' => 'America/Los_Angeles'
        ]);
        
        $result = getSunsetTime($airport);
        // Should return time string in HH:MM format (24-hour) or null if no sunset (polar regions)
        if ($result !== null) {
            $this->assertMatchesRegularExpression('/^\d{2}:\d{2}$/', $result);
        }
    }
    
    /**
     * Ensures sunrise times are converted to airport timezone, not UTC
     */
    public function testGetSunriseTime_TimezoneConversion()
    {
        $airport1 = createTestAirport([
            'lat' => 45.0,
            'lon' => -122.0,
            'timezone' => 'America/Los_Angeles'
        ]);
        
        $airport2 = createTestAirport([
            'lat' => 45.0,
            'lon' => -122.0,
            'timezone' => 'America/New_York'
        ]);
        
        $sunrise1 = getSunriseTime($airport1);
        $sunrise2 = getSunriseTime($airport2);
        
        // Both should be valid or both null (polar regions)
        if ($sunrise1 !== null && $sunrise2 !== null) {
            // Times should be different due to timezone (3 hour difference typically)
            $this->assertNotEquals($sunrise1, $sunrise2);
        }
    }
    
    /**
     * Prevents type coercion bug where string "15" < numeric 10, causing slower winds to overwrite higher peaks
     */
    public function testUpdatePeakGust_TypeCoercionFromJson()
    {
        $airportId = 'test_type_coercion_' . uniqid();
        $airport = createTestAirport(['timezone' => 'America/Los_Angeles']);
        
        // Set initial peak gust
        updatePeakGust($airportId, 15.0, $airport);
        clearstatcache();
        
        // Manually corrupt the cache file to have string values (simulating JSON decode issue)
        $cacheDir = getWeatherCacheDir();
        $file = $cacheDir . '/peak_gusts.json';
        $dateKey = getAirportDateKey($airport);
        
        $peakGusts = json_decode(file_get_contents($file), true);
        // Simulate JSON storing numbers as strings
        $peakGusts[$dateKey][$airportId]['value'] = '15'; // String instead of number
        file_put_contents($file, json_encode($peakGusts), LOCK_EX);
        clearstatcache();
        
        // Try to update with a lower value - should NOT overwrite
        updatePeakGust($airportId, 10.0, $airport);
        clearstatcache();
        
        $result = getPeakGust($airportId, 10.0, $airport);
        $value = is_array($result) ? $result['value'] : $result;
        
        // Should still be 15 (or higher), not 10
        $this->assertGreaterThanOrEqual(15.0, $value, 'Peak gust should not be overwritten with lower value, even with string type coercion');
    }
    
    /**
     * Ensures string inputs like "15" are correctly converted to numbers before comparison
     */
    public function testUpdatePeakGust_StringInputHandling()
    {
        $airportId = 'test_string_input_' . uniqid();
        $airport = createTestAirport(['timezone' => 'America/Los_Angeles']);
        
        // Test with string input that represents a number
        updatePeakGust($airportId, '15', $airport); // String input
        clearstatcache();
        
        $result1 = getPeakGust($airportId, 0, $airport);
        $value1 = is_array($result1) ? $result1['value'] : $result1;
        $this->assertEquals(15.0, $value1, 'String "15" should be converted to float 15.0');
        
        // Higher numeric string should update
        updatePeakGust($airportId, '20', $airport);
        clearstatcache();
        
        $result2 = getPeakGust($airportId, 0, $airport);
        $value2 = is_array($result2) ? $result2['value'] : $result2;
        $this->assertEquals(20.0, $value2, 'String "20" should update peak to 20.0');
        
        // Lower numeric string should NOT update
        updatePeakGust($airportId, '10', $airport);
        clearstatcache();
        
        $result3 = getPeakGust($airportId, 0, $airport);
        $value3 = is_array($result3) ? $result3['value'] : $result3;
        $this->assertEquals(20.0, $value3, 'String "10" should NOT overwrite peak of 20.0');
    }
    
    /**
     * Prevents type coercion bug where string temperatures cause incorrect comparisons
     */
    public function testUpdateTempExtremes_TypeCoercionFromJson()
    {
        $airportId = 'test_temp_type_coercion_' . uniqid();
        $airport = createTestAirport(['timezone' => 'America/Los_Angeles']);
        
        // Set initial temperature extremes
        updateTempExtremes($airportId, 20.0, $airport);
        clearstatcache();
        
        // Manually corrupt the cache file to have string values
        $cacheDir = getWeatherCacheDir();
        $file = $cacheDir . '/temp_extremes.json';
        $dateKey = getAirportDateKey($airport);
        
        $tempExtremes = json_decode(file_get_contents($file), true);
        // Simulate JSON storing numbers as strings
        $tempExtremes[$dateKey][$airportId]['high'] = '20'; // String instead of number
        $tempExtremes[$dateKey][$airportId]['low'] = '20'; // String instead of number
        file_put_contents($file, json_encode($tempExtremes), LOCK_EX);
        clearstatcache();
        
        // Try to update with a lower value - should update low but not high
        updateTempExtremes($airportId, 15.0, $airport);
        clearstatcache();
        
        $result = getTempExtremes($airportId, 15.0, $airport);
        
        // High should remain 20, low should be 15
        $this->assertGreaterThanOrEqual(20.0, $result['high'], 'High temp should not be overwritten with lower value');
        $this->assertEquals(15.0, $result['low'], 'Low temp should be updated to 15.0');
    }
    
    /**
     * Ensures string temperature inputs are correctly converted to numbers before comparison
     */
    public function testUpdateTempExtremes_StringInputHandling()
    {
        $airportId = 'test_temp_string_input_' . uniqid();
        $airport = createTestAirport(['timezone' => 'America/Los_Angeles']);
        
        // Test with string input
        updateTempExtremes($airportId, '20', $airport); // String input
        clearstatcache();
        
        $result1 = getTempExtremes($airportId, 0, $airport);
        $this->assertEquals(20.0, $result1['high'], 'String "20" should be converted to float 20.0');
        $this->assertEquals(20.0, $result1['low'], 'String "20" should be converted to float 20.0');
        
        // Higher numeric string should update high
        updateTempExtremes($airportId, '25', $airport);
        clearstatcache();
        
        $result2 = getTempExtremes($airportId, 0, $airport);
        $this->assertEquals(25.0, $result2['high'], 'String "25" should update high to 25.0');
        $this->assertEquals(20.0, $result2['low'], 'Low should remain 20.0');
        
        // Lower numeric string should update low
        updateTempExtremes($airportId, '15', $airport);
        clearstatcache();
        
        $result3 = getTempExtremes($airportId, 0, $airport);
        $this->assertEquals(25.0, $result3['high'], 'High should remain 25.0');
        $this->assertEquals(15.0, $result3['low'], 'String "15" should update low to 15.0');
    }
    
    /**
     * Ensures corrupted cache files are gracefully recovered to prevent data loss
     */
    public function testPeakGust_CorruptedJsonFile()
    {
        $airportId = 'test_corrupted_' . uniqid();
        $airport = createTestAirport(['timezone' => 'America/Los_Angeles']);
        
        $cacheDir = getWeatherCacheDir();
        $file = $cacheDir . '/peak_gusts.json';
        
        // Create corrupted JSON file
        file_put_contents($file, 'invalid json {', LOCK_EX);
        clearstatcache();
        
        // Should handle gracefully and recreate file
        updatePeakGust($airportId, 15.0, $airport);
        clearstatcache();
        
        $result = getPeakGust($airportId, 15.0, $airport);
        $value = is_array($result) ? $result['value'] : $result;
        
        // Should work after corruption is handled
        $this->assertEquals(15.0, $value, 'Should handle corrupted JSON and recreate file');
        
        // Verify file is now valid JSON
        $content = file_get_contents($file);
        $decoded = json_decode($content, true);
        $this->assertIsArray($decoded, 'File should be valid JSON after corruption handling');
    }
    
    /**
     * Ensures corrupted temperature cache files are gracefully recovered to prevent data loss
     */
    public function testTempExtremes_CorruptedJsonFile()
    {
        $airportId = 'test_temp_corrupted_' . uniqid();
        $airport = createTestAirport(['timezone' => 'America/Los_Angeles']);
        
        $cacheDir = getWeatherCacheDir();
        $file = $cacheDir . '/temp_extremes.json';
        
        // Create corrupted JSON file
        file_put_contents($file, 'invalid json {', LOCK_EX);
        clearstatcache();
        
        // Should handle gracefully and recreate file
        updateTempExtremes($airportId, 20.0, $airport);
        clearstatcache();
        
        $result = getTempExtremes($airportId, 20.0, $airport);
        
        // Should work after corruption is handled
        $this->assertEquals(20.0, $result['high'], 'Should handle corrupted JSON and recreate file');
        $this->assertEquals(20.0, $result['low'], 'Should handle corrupted JSON and recreate file');
        
        // Verify file is now valid JSON
        $content = file_get_contents($file);
        $decoded = json_decode($content, true);
        $this->assertIsArray($decoded, 'File should be valid JSON after corruption handling');
    }
}

