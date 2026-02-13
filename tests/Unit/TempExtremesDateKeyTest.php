<?php

use PHPUnit\Framework\TestCase;

/**
 * Tests for daily temperature extreme tracking with observation timestamps
 * 
 * Verifies that temp extremes are stored under the correct date key
 * based on observation timestamp, not current time.
 */
class TempExtremesDateKeyTest extends TestCase
{
    private string $testCacheDir;
    private string $tempExtremesFile;

    protected function setUp(): void
    {
        // Use test-specific cache directory
        $this->testCacheDir = CACHE_BASE_DIR;
        $this->tempExtremesFile = $this->testCacheDir . '/temp_extremes.json';
        
        // Clean up any existing file
        if (file_exists($this->tempExtremesFile)) {
            @unlink($this->tempExtremesFile);
        }
        
        // Ensure cache directory exists
        if (!is_dir($this->testCacheDir)) {
            @mkdir($this->testCacheDir, 0755, true);
        }
    }

    protected function tearDown(): void
    {
        // Clean up test file
        if (file_exists($this->tempExtremesFile)) {
            @unlink($this->tempExtremesFile);
        }
    }

    /**
     * Test that observation timestamp determines date key, not current time
     * 
     * Scenario: Weather observation from Feb 12 at 5:48 PM MST,
     * but processed after midnight UTC (Feb 13 UTC).
     * Should store under "2026-02-12" based on observation time in Boise timezone.
     */
    public function testObservationTimestampDeterminesDateKey(): void
    {
        require_once __DIR__ . '/../../lib/weather/daily-tracking.php';
        
        // Mock airport config with Boise timezone
        $airport = [
            'timezone' => 'America/Boise',
            'name' => 'Boise Air Terminal'
        ];
        
        // Observation time: Feb 12, 2026 5:48 PM MST = Feb 13, 2026 00:48 UTC
        $obsTimestamp = mktime(0, 48, 0, 2, 13, 2026); // UTC time
        
        // Current time (when update runs): Feb 12, 2026 8:30 PM MST = Feb 13, 2026 03:30 UTC
        // This is AFTER midnight UTC but still Feb 12 in Boise
        
        // Temperature at observation time: 9.1°C (48.4°F)
        $temp = 9.1;
        
        // Update temp extremes with observation timestamp
        updateTempExtremes('kboi', $temp, $airport, $obsTimestamp);
        
        // Read file and verify
        $this->assertFileExists($this->tempExtremesFile);
        $content = file_get_contents($this->tempExtremesFile);
        $data = json_decode($content, true);
        
        // Convert observation timestamp to Boise date
        $tz = new DateTimeZone('America/Boise');
        $obsDate = new DateTime('@' . $obsTimestamp);
        $obsDate->setTimezone($tz);
        $expectedDateKey = $obsDate->format('Y-m-d');
        
        // Verify data is stored under correct date key (Feb 12, not Feb 13)
        $this->assertArrayHasKey($expectedDateKey, $data, 
            'Date key should be based on observation timestamp in airport timezone');
        $this->assertArrayHasKey('kboi', $data[$expectedDateKey]);
        
        $stored = $data[$expectedDateKey]['kboi'];
        $this->assertEquals($temp, $stored['high']);
        $this->assertEquals($temp, $stored['low']);
        $this->assertEquals($obsTimestamp, $stored['high_ts']);
        $this->assertEquals($obsTimestamp, $stored['low_ts']);
    }

    /**
     * Test that new observations update existing day correctly
     * 
     * Scenario: Multiple observations throughout the day with different timestamps.
     * All should update the same date key based on their observation times.
     */
    public function testMultipleObservationsUpdateSameDay(): void
    {
        require_once __DIR__ . '/../../lib/weather/daily-tracking.php';
        
        $airport = [
            'timezone' => 'America/Boise',
            'name' => 'Boise Air Terminal'
        ];
        
        // Observation 1: Feb 12, 2:00 PM MST (21:00 UTC) - 5°C
        $obs1 = mktime(21, 0, 0, 2, 12, 2026);
        updateTempExtremes('kboi', 5.0, $airport, $obs1);
        
        // Observation 2: Feb 12, 5:48 PM MST (00:48 UTC Feb 13) - 9.1°C (new high)
        $obs2 = mktime(0, 48, 0, 2, 13, 2026);
        updateTempExtremes('kboi', 9.1, $airport, $obs2);
        
        // Observation 3: Feb 12, 6:39 PM MST (01:39 UTC Feb 13) - 7.3°C
        $obs3 = mktime(1, 39, 0, 2, 13, 2026);
        updateTempExtremes('kboi', 7.3, $airport, $obs3);
        
        // Read and verify
        $content = file_get_contents($this->tempExtremesFile);
        $data = json_decode($content, true);
        
        // All observations should be under Feb 12 (Boise timezone)
        $tz = new DateTimeZone('America/Boise');
        $date1 = new DateTime('@' . $obs1);
        $date1->setTimezone($tz);
        $expectedDateKey = $date1->format('Y-m-d');
        
        $this->assertArrayHasKey($expectedDateKey, $data);
        $stored = $data[$expectedDateKey]['kboi'];
        
        // High should be 9.1°C (from obs2)
        $this->assertEquals(9.1, $stored['high']);
        $this->assertEquals($obs2, $stored['high_ts']);
        
        // Low should still be 5.0°C (from obs1)
        $this->assertEquals(5.0, $stored['low']);
        $this->assertEquals($obs1, $stored['low_ts']);
    }

    /**
     * Test crossing midnight UTC doesn't trigger premature day reset
     * 
     * Scenario: Observation from 11:30 PM MST (Feb 12) arrives at 12:30 AM UTC (Feb 13).
     * Should store under Feb 12 in Boise timezone, not Feb 13.
     */
    public function testMidnightUTCCrossingDoesNotResetDay(): void
    {
        require_once __DIR__ . '/../../lib/weather/daily-tracking.php';
        
        $airport = [
            'timezone' => 'America/Boise',
            'name' => 'Boise Air Terminal'
        ];
        
        // First observation: Feb 12, 3:00 PM MST - 10°C high
        $obs1 = mktime(22, 0, 0, 2, 12, 2026); // Feb 12 22:00 UTC
        updateTempExtremes('kboi', 10.0, $airport, $obs1);
        
        // Second observation: Feb 12, 11:30 PM MST - 3°C low
        // This is Feb 13 06:30 UTC (after midnight UTC!)
        $obs2 = mktime(6, 30, 0, 2, 13, 2026); // Feb 13 06:30 UTC
        updateTempExtremes('kboi', 3.0, $airport, $obs2);
        
        // Read and verify
        $content = file_get_contents($this->tempExtremesFile);
        $data = json_decode($content, true);
        
        // Should only have ONE date key (Feb 12 in Boise timezone)
        $this->assertCount(1, $data, 'Should only have one date key despite UTC midnight crossing');
        
        // Both observations should be under Feb 12 Boise time
        $tz = new DateTimeZone('America/Boise');
        $date2 = new DateTime('@' . $obs2);
        $date2->setTimezone($tz);
        $expectedDateKey = $date2->format('Y-m-d');
        $this->assertEquals('2026-02-12', $expectedDateKey, 'Feb 13 06:30 UTC should be Feb 12 in Boise');
        
        $stored = $data[$expectedDateKey]['kboi'];
        $this->assertEquals(10.0, $stored['high']);
        $this->assertEquals(3.0, $stored['low']);
    }

    /**
     * Test fallback path also uses observation timestamp correctly
     */
    public function testFallbackUsesObservationTimestamp(): void
    {
        require_once __DIR__ . '/../../lib/weather/daily-tracking.php';
        
        $airport = [
            'timezone' => 'America/Boise',
            'name' => 'Boise Air Terminal'
        ];
        
        // Observation from Feb 12, 10:00 PM MST (Feb 13 05:00 UTC)
        $obsTimestamp = mktime(5, 0, 0, 2, 13, 2026);
        $temp = 8.5;
        
        // Calculate expected date key
        $tz = new DateTimeZone('America/Boise');
        $obsDate = new DateTime('@' . $obsTimestamp);
        $obsDate->setTimezone($tz);
        $expectedDateKey = $obsDate->format('Y-m-d');
        
        // Call fallback directly
        updateTempExtremesFallback('kboi', $temp, $airport, $obsTimestamp, $this->tempExtremesFile, $expectedDateKey);
        
        // Verify
        $content = file_get_contents($this->tempExtremesFile);
        $data = json_decode($content, true);
        
        $this->assertArrayHasKey($expectedDateKey, $data);
        $this->assertEquals($temp, $data[$expectedDateKey]['kboi']['high']);
    }
}
