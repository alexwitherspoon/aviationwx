<?php

use PHPUnit\Framework\TestCase;

/**
 * Tests for peak gust tracking with observation timestamps
 * 
 * Verifies that peak gusts are stored under the correct date key
 * based on observation timestamp, not current time.
 */
class PeakGustDateKeyTest extends TestCase
{
    private string $testCacheDir;
    private string $peakGustFile;

    protected function setUp(): void
    {
        // Use test-specific cache directory
        $this->testCacheDir = CACHE_BASE_DIR;
        $this->peakGustFile = $this->testCacheDir . '/peak_gusts.json';
        
        // Clean up any existing file
        if (file_exists($this->peakGustFile)) {
            @unlink($this->peakGustFile);
        }
        
        // Ensure cache directory exists
        if (!is_dir($this->testCacheDir)) {
            @mkdir($this->testCacheDir, 0755, true);
        }
    }

    protected function tearDown(): void
    {
        // Clean up test file
        if (file_exists($this->peakGustFile)) {
            @unlink($this->peakGustFile);
        }
    }

    /**
     * Test that observation timestamp determines date key, not current time
     * 
     * Scenario: Wind gust observation from Feb 12 at 5:48 PM MST,
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
        
        // Gust speed at observation time: 25 knots
        $gust = 25;
        
        // Update peak gust with observation timestamp
        updatePeakGust('kboi', $gust, $airport, $obsTimestamp);
        
        // Read file and verify
        $this->assertFileExists($this->peakGustFile);
        $content = file_get_contents($this->peakGustFile);
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
        $this->assertEquals($gust, $stored['value']);
        $this->assertEquals($obsTimestamp, $stored['ts']);
    }

    /**
     * Test that new observations update existing day correctly
     * 
     * Scenario: Multiple gust observations throughout the day with different timestamps.
     * All should update the same date key based on their observation times.
     */
    public function testMultipleObservationsUpdateSameDay(): void
    {
        require_once __DIR__ . '/../../lib/weather/daily-tracking.php';
        
        $airport = [
            'timezone' => 'America/Boise',
            'name' => 'Boise Air Terminal'
        ];
        
        // Observation 1: Feb 12, 2:00 PM MST (21:00 UTC) - 15 knots
        $obs1 = mktime(21, 0, 0, 2, 12, 2026);
        updatePeakGust('kboi', 15, $airport, $obs1);
        
        // Observation 2: Feb 12, 5:48 PM MST (00:48 UTC Feb 13) - 25 knots (new peak)
        $obs2 = mktime(0, 48, 0, 2, 13, 2026);
        updatePeakGust('kboi', 25, $airport, $obs2);
        
        // Observation 3: Feb 12, 6:39 PM MST (01:39 UTC Feb 13) - 18 knots
        $obs3 = mktime(1, 39, 0, 2, 13, 2026);
        updatePeakGust('kboi', 18, $airport, $obs3);
        
        // Read and verify
        $content = file_get_contents($this->peakGustFile);
        $data = json_decode($content, true);
        
        // All observations should be under Feb 12 (Boise timezone)
        $tz = new DateTimeZone('America/Boise');
        $date1 = new DateTime('@' . $obs1);
        $date1->setTimezone($tz);
        $expectedDateKey = $date1->format('Y-m-d');
        
        $this->assertArrayHasKey($expectedDateKey, $data);
        $stored = $data[$expectedDateKey]['kboi'];
        
        // Peak should be 25 knots (from obs2)
        $this->assertEquals(25, $stored['value']);
        $this->assertEquals($obs2, $stored['ts']);
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
        
        // First observation: Feb 12, 3:00 PM MST - 30 knots peak
        $obs1 = mktime(22, 0, 0, 2, 12, 2026); // Feb 12 22:00 UTC
        updatePeakGust('kboi', 30, $airport, $obs1);
        
        // Second observation: Feb 12, 11:30 PM MST - 20 knots
        // This is Feb 13 06:30 UTC (after midnight UTC!)
        $obs2 = mktime(6, 30, 0, 2, 13, 2026); // Feb 13 06:30 UTC
        updatePeakGust('kboi', 20, $airport, $obs2);
        
        // Read and verify
        $content = file_get_contents($this->peakGustFile);
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
        $this->assertEquals(30, $stored['value']); // Peak should remain 30
    }

    /**
     * Test higher gust at later time in the day updates peak correctly
     * 
     * Scenario: Peak increases throughout the day, ensuring highest value is retained.
     */
    public function testHigherGustLaterInDayUpdates(): void
    {
        require_once __DIR__ . '/../../lib/weather/daily-tracking.php';
        
        $airport = [
            'timezone' => 'America/Boise',
            'name' => 'Boise Air Terminal'
        ];
        
        // Morning: Feb 12, 10:00 AM MST (17:00 UTC) - 12 knots
        $obs1 = mktime(17, 0, 0, 2, 12, 2026);
        updatePeakGust('kboi', 12, $airport, $obs1);
        
        // Afternoon: Feb 12, 2:00 PM MST (21:00 UTC) - 22 knots (new peak)
        $obs2 = mktime(21, 0, 0, 2, 12, 2026);
        updatePeakGust('kboi', 22, $airport, $obs2);
        
        // Late evening: Feb 12, 10:00 PM MST (Feb 13 05:00 UTC) - 28 knots (new peak)
        $obs3 = mktime(5, 0, 0, 2, 13, 2026);
        updatePeakGust('kboi', 28, $airport, $obs3);
        
        // Read and verify
        $content = file_get_contents($this->peakGustFile);
        $data = json_decode($content, true);
        
        // All should be under Feb 12 Boise time
        $tz = new DateTimeZone('America/Boise');
        $date3 = new DateTime('@' . $obs3);
        $date3->setTimezone($tz);
        $expectedDateKey = $date3->format('Y-m-d');
        
        $stored = $data[$expectedDateKey]['kboi'];
        
        // Peak should be 28 knots (from obs3, the latest and highest)
        $this->assertEquals(28, $stored['value']);
        $this->assertEquals($obs3, $stored['ts']);
    }

    /**
     * Test that out-of-order observations are handled correctly
     * 
     * Scenario: Observations arrive out of chronological order (e.g., delayed API responses).
     * Peak should reflect the highest value, not the order received.
     */
    public function testOutOfOrderObservations(): void
    {
        require_once __DIR__ . '/../../lib/weather/daily-tracking.php';
        
        $airport = [
            'timezone' => 'America/Boise',
            'name' => 'Boise Air Terminal'
        ];
        
        // Receive observation 3 first: Feb 12, 6:00 PM MST - 18 knots
        $obs3 = mktime(1, 0, 0, 2, 13, 2026); // Feb 13 01:00 UTC
        updatePeakGust('kboi', 18, $airport, $obs3);
        
        // Then receive observation 1 (earlier): Feb 12, 2:00 PM MST - 25 knots (higher!)
        $obs1 = mktime(21, 0, 0, 2, 12, 2026); // Feb 12 21:00 UTC
        updatePeakGust('kboi', 25, $airport, $obs1);
        
        // Finally receive observation 2 (middle): Feb 12, 4:00 PM MST - 20 knots
        $obs2 = mktime(23, 0, 0, 2, 12, 2026); // Feb 12 23:00 UTC
        updatePeakGust('kboi', 20, $airport, $obs2);
        
        // Read and verify
        $content = file_get_contents($this->peakGustFile);
        $data = json_decode($content, true);
        
        $tz = new DateTimeZone('America/Boise');
        $date = new DateTime('@' . $obs1);
        $date->setTimezone($tz);
        $expectedDateKey = $date->format('Y-m-d');
        
        $stored = $data[$expectedDateKey]['kboi'];
        
        // Peak should be 25 knots (highest value, despite being received second)
        $this->assertEquals(25, $stored['value']);
        $this->assertEquals($obs1, $stored['ts']); // Timestamp should match when peak occurred
    }

    /**
     * Test that observations from different days don't interfere
     * 
     * Scenario: Observations span multiple days, ensuring each day has its own peak.
     */
    public function testObservationsAcrossDays(): void
    {
        require_once __DIR__ . '/../../lib/weather/daily-tracking.php';
        
        $airport = [
            'timezone' => 'America/Boise',
            'name' => 'Boise Air Terminal'
        ];
        
        // Feb 12, 11:00 AM MST - 15 knots
        $obs1 = mktime(18, 0, 0, 2, 12, 2026); // Feb 12 18:00 UTC
        updatePeakGust('kboi', 15, $airport, $obs1);
        
        // Feb 13, 2:00 PM MST - 22 knots (next day)
        $obs2 = mktime(21, 0, 0, 2, 13, 2026); // Feb 13 21:00 UTC
        updatePeakGust('kboi', 22, $airport, $obs2);
        
        // Read and verify
        $content = file_get_contents($this->peakGustFile);
        $data = json_decode($content, true);
        
        // Should have TWO date keys
        $this->assertCount(2, $data, 'Should have two separate date keys for two different days');
        
        // Verify Feb 12
        $tz = new DateTimeZone('America/Boise');
        $date1 = new DateTime('@' . $obs1);
        $date1->setTimezone($tz);
        $dateKey1 = $date1->format('Y-m-d');
        $this->assertEquals(15, $data[$dateKey1]['kboi']['value']);
        
        // Verify Feb 13
        $date2 = new DateTime('@' . $obs2);
        $date2->setTimezone($tz);
        $dateKey2 = $date2->format('Y-m-d');
        $this->assertEquals(22, $data[$dateKey2]['kboi']['value']);
    }

    /**
     * Test multiple airports don't interfere with each other
     */
    public function testMultipleAirportsIsolation(): void
    {
        require_once __DIR__ . '/../../lib/weather/daily-tracking.php';
        
        $airport1 = [
            'timezone' => 'America/Boise',
            'name' => 'Boise Air Terminal'
        ];
        
        $airport2 = [
            'timezone' => 'America/Los_Angeles',
            'name' => 'Los Angeles Intl'
        ];
        
        // Same observation time for both airports
        $obsTime = mktime(21, 0, 0, 2, 12, 2026); // Feb 12 21:00 UTC
        
        updatePeakGust('kboi', 25, $airport1, $obsTime);
        updatePeakGust('klax', 18, $airport2, $obsTime);
        
        // Read and verify
        $content = file_get_contents($this->peakGustFile);
        $data = json_decode($content, true);
        
        // Get date keys for each airport (might be same or different depending on timezone)
        $tz1 = new DateTimeZone('America/Boise');
        $date1 = new DateTime('@' . $obsTime);
        $date1->setTimezone($tz1);
        $dateKey1 = $date1->format('Y-m-d');
        
        $tz2 = new DateTimeZone('America/Los_Angeles');
        $date2 = new DateTime('@' . $obsTime);
        $date2->setTimezone($tz2);
        $dateKey2 = $date2->format('Y-m-d');
        
        // Verify each airport has its own data
        $this->assertEquals(25, $data[$dateKey1]['kboi']['value']);
        $this->assertEquals(18, $data[$dateKey2]['klax']['value']);
    }
}
