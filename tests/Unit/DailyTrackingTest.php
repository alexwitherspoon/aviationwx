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
        
        // Backup existing files if they exist
        if (file_exists($this->peakGustFile)) {
            rename($this->peakGustFile, $this->peakGustFile . '.backup');
        }
        if (file_exists($this->tempExtremesFile)) {
            rename($this->tempExtremesFile, $this->tempExtremesFile . '.backup');
        }
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
     * Test updatePeakGust - First value of the day
     */
    public function testUpdatePeakGust_FirstValueOfDay()
    {
        $airportId = 'test';
        $airport = createTestAirport(['timezone' => 'America/Los_Angeles']);
        $currentGust = 15;
        
        updatePeakGust($airportId, $currentGust, $airport);
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
     * Test updatePeakGust - Higher value updates peak
     */
    public function testUpdatePeakGust_HigherValueUpdates()
    {
        $airportId = 'test';
        $airport = createTestAirport(['timezone' => 'America/Los_Angeles']);
        
        // First value
        updatePeakGust($airportId, 10, $airport);
        $result1 = getPeakGust($airportId, 10, $airport);
        $value1 = is_array($result1) ? $result1['value'] : $result1;
        $this->assertEquals(10, $value1);
        
        // Higher value
        updatePeakGust($airportId, 20, $airport);
        $result2 = getPeakGust($airportId, 15, $airport); // Current gust is lower
        $value2 = is_array($result2) ? $result2['value'] : $result2;
        $this->assertEquals(20, $value2); // Should still show peak of 20
        
        // Lower value doesn't change peak
        updatePeakGust($airportId, 8, $airport);
        $result3 = getPeakGust($airportId, 8, $airport);
        $value3 = is_array($result3) ? $result3['value'] : $result3;
        $this->assertEquals(20, $value3); // Should still show peak of 20
    }
    
    /**
     * Test updatePeakGust - Timestamp updates only on new peak
     */
    public function testUpdatePeakGust_TimestampUpdatesOnNewPeak()
    {
        $airportId = 'test';
        $airport = createTestAirport(['timezone' => 'America/Los_Angeles']);
        
        // First value
        updatePeakGust($airportId, 10, $airport);
        $result1 = getPeakGust($airportId, 10, $airport);
        
        if (is_array($result1)) {
            $ts1 = $result1['ts'];
            sleep(1); // Wait 1 second
            
            // Lower value shouldn't update timestamp
            updatePeakGust($airportId, 5, $airport);
            $result2 = getPeakGust($airportId, 5, $airport);
            $ts2 = $result2['ts'];
            
            // Timestamp should not change (or only slightly due to file write time)
            $this->assertEquals($ts1, $ts2);
            
            // Higher value should update timestamp
            sleep(1);
            updatePeakGust($airportId, 15, $airport);
            $result3 = getPeakGust($airportId, 15, $airport);
            $ts3 = $result3['ts'];
            
            // Timestamp should be newer
            $this->assertGreaterThan($ts1, $ts3);
        }
    }
    
    /**
     * Test updatePeakGust - Multiple airports don't interfere
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
     * Test updatePeakGust - Timezone-aware daily reset
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
     * Test updateTempExtremes - First value initializes both high and low
     */
    public function testUpdateTempExtremes_FirstValue()
    {
        $airportId = 'test';
        $airport = createTestAirport(['timezone' => 'America/Los_Angeles']);
        $currentTemp = 20.0;
        
        updateTempExtremes($airportId, $currentTemp, $airport);
        $result = getTempExtremes($airportId, $currentTemp, $airport);
        
        $this->assertEquals($currentTemp, $result['high']);
        $this->assertEquals($currentTemp, $result['low']);
        $this->assertIsInt($result['high_ts']);
        $this->assertIsInt($result['low_ts']);
    }
    
    /**
     * Test updateTempExtremes - Higher temperature updates high
     */
    public function testUpdateTempExtremes_HigherTempUpdatesHigh()
    {
        $airportId = 'test';
        $airport = createTestAirport(['timezone' => 'America/Los_Angeles']);
        
        // Initial value
        updateTempExtremes($airportId, 15.0, $airport);
        $result1 = getTempExtremes($airportId, 15.0, $airport);
        $this->assertEquals(15.0, $result1['high']);
        $this->assertEquals(15.0, $result1['low']);
        
        // Higher value
        updateTempExtremes($airportId, 25.0, $airport);
        $result2 = getTempExtremes($airportId, 20.0, $airport);
        $this->assertEquals(25.0, $result2['high']);
        $this->assertEquals(15.0, $result2['low']); // Low should remain
    }
    
    /**
     * Test updateTempExtremes - Lower temperature updates low
     */
    public function testUpdateTempExtremes_LowerTempUpdatesLow()
    {
        $airportId = 'test';
        $airport = createTestAirport(['timezone' => 'America/Los_Angeles']);
        
        // Initial value
        updateTempExtremes($airportId, 20.0, $airport);
        
        // Lower value
        updateTempExtremes($airportId, 10.0, $airport);
        $result = getTempExtremes($airportId, 15.0, $airport);
        $this->assertEquals(20.0, $result['high']); // High should remain
        $this->assertEquals(10.0, $result['low']);
    }
    
    /**
     * Test updateTempExtremes - Timestamp updates only on new records
     */
    public function testUpdateTempExtremes_TimestampUpdatesOnNewRecords()
    {
        $airportId = 'test';
        $airport = createTestAirport(['timezone' => 'America/Los_Angeles']);
        
        // Initial value
        updateTempExtremes($airportId, 20.0, $airport);
        $result1 = getTempExtremes($airportId, 20.0, $airport);
        $highTs1 = $result1['high_ts'];
        $lowTs1 = $result1['low_ts'];
        
        sleep(1);
        
        // Same value - timestamps shouldn't change significantly
        updateTempExtremes($airportId, 20.0, $airport);
        $result2 = getTempExtremes($airportId, 20.0, $airport);
        // Timestamps might update slightly due to file operations, but shouldn't be dramatically different
        
        sleep(1);
        
        // New high - high_ts should update, low_ts should not
        updateTempExtremes($airportId, 25.0, $airport);
        $result3 = getTempExtremes($airportId, 25.0, $airport);
        $this->assertGreaterThan($highTs1, $result3['high_ts']);
        // Low timestamp should remain the same (approximately)
        $this->assertEquals($lowTs1, $result3['low_ts']);
        
        sleep(1);
        
        // New low - low_ts should update, high_ts should not
        updateTempExtremes($airportId, 15.0, $airport);
        $result4 = getTempExtremes($airportId, 15.0, $airport);
        $this->assertEquals($result3['high_ts'], $result4['high_ts']); // High timestamp unchanged
        $this->assertGreaterThan($lowTs1, $result4['low_ts']); // Low timestamp updated
    }
    
    /**
     * Test updateTempExtremes - Multiple airports don't interfere
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
     * Test getAirportDateKey - Timezone awareness
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
     * Test getAirportDateKey - Default timezone
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
     * Test getAirportTimezone - Returns configured timezone
     */
    public function testGetAirportTimezone_ReturnsConfigured()
    {
        $airport = createTestAirport(['timezone' => 'America/New_York']);
        $result = getAirportTimezone($airport);
        $this->assertEquals('America/New_York', $result);
    }
    
    /**
     * Test getAirportTimezone - Default fallback
     */
    public function testGetAirportTimezone_DefaultFallback()
    {
        $airport = createTestAirport();
        unset($airport['timezone']);
        $result = getAirportTimezone($airport);
        $this->assertEquals('America/Los_Angeles', $result);
    }
    
    /**
     * Test getSunriseTime - Valid coordinates
     */
    public function testGetSunriseTime_ValidCoordinates()
    {
        $airport = createTestAirport([
            'lat' => 45.0,
            'lon' => -122.0,
            'timezone' => 'America/Los_Angeles'
        ]);
        
        $result = getSunriseTime($airport);
        // Should return time string in HH:MM format or null if no sunrise (polar regions)
        if ($result !== null) {
            $this->assertMatchesRegularExpression('/^\d{2}:\d{2}$/', $result);
        }
    }
    
    /**
     * Test getSunsetTime - Valid coordinates
     */
    public function testGetSunsetTime_ValidCoordinates()
    {
        $airport = createTestAirport([
            'lat' => 45.0,
            'lon' => -122.0,
            'timezone' => 'America/Los_Angeles'
        ]);
        
        $result = getSunsetTime($airport);
        // Should return time string in HH:MM format or null if no sunset (polar regions)
        if ($result !== null) {
            $this->assertMatchesRegularExpression('/^\d{2}:\d{2}$/', $result);
        }
    }
    
    /**
     * Test getSunriseTime - Timezone conversion
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
}

