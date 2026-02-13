<?php

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../lib/cache-paths.php';

/**
 * Tests for peak gust tracking with observation timestamps
 *
 * Verifies that peak gusts are stored under the correct date key
 * based on observation timestamp, not current time.
 * Uses per-airport file layout (cache/peak_gusts/{airport}.json).
 */
class PeakGustDateKeyTest extends TestCase
{
    private const TEST_AIRPORT = 'kboi';

    protected function setUp(): void
    {
        ensureCacheDir(CACHE_PEAK_GUSTS_DIR);
        $file = getPeakGustTrackingPath(self::TEST_AIRPORT);
        if (file_exists($file)) {
            @unlink($file);
        }
    }

    protected function tearDown(): void
    {
        foreach ([self::TEST_AIRPORT, 'klax'] as $airport) {
            $file = getPeakGustTrackingPath($airport);
            if (file_exists($file)) {
                @unlink($file);
            }
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
        
        updatePeakGust(self::TEST_AIRPORT, $gust, $airport, $obsTimestamp);

        $file = getPeakGustTrackingPath(self::TEST_AIRPORT);
        $this->assertFileExists($file);
        $content = file_get_contents($file);
        $data = json_decode($content, true);

        $tz = new DateTimeZone('America/Boise');
        $obsDate = new DateTime('@' . $obsTimestamp);
        $obsDate->setTimezone($tz);
        $expectedDateKey = $obsDate->format('Y-m-d');

        $this->assertArrayHasKey($expectedDateKey, $data,
            'Date key should be based on observation timestamp in airport timezone');
        $stored = $data[$expectedDateKey];
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
        updatePeakGust(self::TEST_AIRPORT, 15, $airport, $obs1);

        $obs2 = mktime(0, 48, 0, 2, 13, 2026);
        updatePeakGust(self::TEST_AIRPORT, 25, $airport, $obs2);

        $obs3 = mktime(1, 39, 0, 2, 13, 2026);
        updatePeakGust(self::TEST_AIRPORT, 18, $airport, $obs3);

        $content = file_get_contents(getPeakGustTrackingPath(self::TEST_AIRPORT));
        $data = json_decode($content, true);

        $tz = new DateTimeZone('America/Boise');
        $date1 = new DateTime('@' . $obs1);
        $date1->setTimezone($tz);
        $expectedDateKey = $date1->format('Y-m-d');

        $this->assertArrayHasKey($expectedDateKey, $data);
        $stored = $data[$expectedDateKey];

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
        
        $obs1 = mktime(22, 0, 0, 2, 12, 2026);
        updatePeakGust(self::TEST_AIRPORT, 30, $airport, $obs1);

        $obs2 = mktime(6, 30, 0, 2, 13, 2026);
        updatePeakGust(self::TEST_AIRPORT, 20, $airport, $obs2);

        $content = file_get_contents(getPeakGustTrackingPath(self::TEST_AIRPORT));
        $data = json_decode($content, true);

        $this->assertCount(1, $data, 'Should only have one date key despite UTC midnight crossing');

        $tz = new DateTimeZone('America/Boise');
        $date2 = new DateTime('@' . $obs2);
        $date2->setTimezone($tz);
        $expectedDateKey = $date2->format('Y-m-d');
        $this->assertEquals('2026-02-12', $expectedDateKey, 'Feb 13 06:30 UTC should be Feb 12 in Boise');

        $stored = $data[$expectedDateKey];
        $this->assertEquals(30, $stored['value']);
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
        
        $obs1 = mktime(17, 0, 0, 2, 12, 2026);
        updatePeakGust(self::TEST_AIRPORT, 12, $airport, $obs1);

        $obs2 = mktime(21, 0, 0, 2, 12, 2026);
        updatePeakGust(self::TEST_AIRPORT, 22, $airport, $obs2);

        $obs3 = mktime(5, 0, 0, 2, 13, 2026);
        updatePeakGust(self::TEST_AIRPORT, 28, $airport, $obs3);

        $content = file_get_contents(getPeakGustTrackingPath(self::TEST_AIRPORT));
        $data = json_decode($content, true);

        $tz = new DateTimeZone('America/Boise');
        $date3 = new DateTime('@' . $obs3);
        $date3->setTimezone($tz);
        $expectedDateKey = $date3->format('Y-m-d');

        $stored = $data[$expectedDateKey];
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
        
        $obs3 = mktime(1, 0, 0, 2, 13, 2026);
        updatePeakGust(self::TEST_AIRPORT, 18, $airport, $obs3);

        $obs1 = mktime(21, 0, 0, 2, 12, 2026);
        updatePeakGust(self::TEST_AIRPORT, 25, $airport, $obs1);

        $obs2 = mktime(23, 0, 0, 2, 12, 2026);
        updatePeakGust(self::TEST_AIRPORT, 20, $airport, $obs2);

        $content = file_get_contents(getPeakGustTrackingPath(self::TEST_AIRPORT));
        $data = json_decode($content, true);

        $tz = new DateTimeZone('America/Boise');
        $date = new DateTime('@' . $obs1);
        $date->setTimezone($tz);
        $expectedDateKey = $date->format('Y-m-d');

        $stored = $data[$expectedDateKey];
        $this->assertEquals(25, $stored['value']);
        $this->assertEquals($obs1, $stored['ts']);
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
        
        $obs1 = mktime(18, 0, 0, 2, 12, 2026);
        updatePeakGust(self::TEST_AIRPORT, 15, $airport, $obs1);

        $obs2 = mktime(21, 0, 0, 2, 13, 2026);
        updatePeakGust(self::TEST_AIRPORT, 22, $airport, $obs2);

        $content = file_get_contents(getPeakGustTrackingPath(self::TEST_AIRPORT));
        $data = json_decode($content, true);

        $this->assertCount(2, $data, 'Should have two separate date keys for two different days');

        $tz = new DateTimeZone('America/Boise');
        $date1 = new DateTime('@' . $obs1);
        $date1->setTimezone($tz);
        $dateKey1 = $date1->format('Y-m-d');
        $this->assertEquals(15, $data[$dateKey1]['value']);

        $date2 = new DateTime('@' . $obs2);
        $date2->setTimezone($tz);
        $dateKey2 = $date2->format('Y-m-d');
        $this->assertEquals(22, $data[$dateKey2]['value']);
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

        $tz1 = new DateTimeZone('America/Boise');
        $date1 = new DateTime('@' . $obsTime);
        $date1->setTimezone($tz1);
        $dateKey1 = $date1->format('Y-m-d');

        $tz2 = new DateTimeZone('America/Los_Angeles');
        $date2 = new DateTime('@' . $obsTime);
        $date2->setTimezone($tz2);
        $dateKey2 = $date2->format('Y-m-d');

        $kboiData = json_decode(file_get_contents(getPeakGustTrackingPath('kboi')), true);
        $klaxData = json_decode(file_get_contents(getPeakGustTrackingPath('klax')), true);

        $this->assertEquals(25, $kboiData[$dateKey1]['value']);
        $this->assertEquals(18, $klaxData[$dateKey2]['value']);
    }
}
