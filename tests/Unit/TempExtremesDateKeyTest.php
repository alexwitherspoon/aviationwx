<?php

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../lib/cache-paths.php';

/**
 * Tests for daily temperature extreme tracking with observation timestamps
 *
 * Verifies that temp extremes are stored under the correct date key
 * based on observation timestamp, not current time.
 * Uses per-airport file layout (cache/temp_extremes/{airport}.json).
 */
class TempExtremesDateKeyTest extends TestCase
{
    private const TEST_AIRPORT = 'kboi';

    protected function setUp(): void
    {
        ensureCacheDir(CACHE_TEMP_EXTREMES_DIR);
        $file = getTempExtremesTrackingPath(self::TEST_AIRPORT);
        if (file_exists($file)) {
            @unlink($file);
        }
    }

    protected function tearDown(): void
    {
        $file = getTempExtremesTrackingPath(self::TEST_AIRPORT);
        if (file_exists($file)) {
            @unlink($file);
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
        
        updateTempExtremes(self::TEST_AIRPORT, $temp, $airport, $obsTimestamp);

        $file = getTempExtremesTrackingPath(self::TEST_AIRPORT);
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
        updateTempExtremes(self::TEST_AIRPORT, 5.0, $airport, $obs1);

        $obs2 = mktime(0, 48, 0, 2, 13, 2026);
        updateTempExtremes(self::TEST_AIRPORT, 9.1, $airport, $obs2);

        $obs3 = mktime(1, 39, 0, 2, 13, 2026);
        updateTempExtremes(self::TEST_AIRPORT, 7.3, $airport, $obs3);

        $content = file_get_contents(getTempExtremesTrackingPath(self::TEST_AIRPORT));
        $data = json_decode($content, true);

        $tz = new DateTimeZone('America/Boise');
        $date1 = new DateTime('@' . $obs1);
        $date1->setTimezone($tz);
        $expectedDateKey = $date1->format('Y-m-d');

        $this->assertArrayHasKey($expectedDateKey, $data);
        $stored = $data[$expectedDateKey];
        
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
        updateTempExtremes(self::TEST_AIRPORT, 10.0, $airport, $obs1);

        $obs2 = mktime(6, 30, 0, 2, 13, 2026);
        updateTempExtremes(self::TEST_AIRPORT, 3.0, $airport, $obs2);

        $content = file_get_contents(getTempExtremesTrackingPath(self::TEST_AIRPORT));
        $data = json_decode($content, true);

        $this->assertCount(1, $data, 'Should only have one date key despite UTC midnight crossing');

        $tz = new DateTimeZone('America/Boise');
        $date2 = new DateTime('@' . $obs2);
        $date2->setTimezone($tz);
        $expectedDateKey = $date2->format('Y-m-d');
        $this->assertEquals('2026-02-12', $expectedDateKey, 'Feb 13 06:30 UTC should be Feb 12 in Boise');

        $stored = $data[$expectedDateKey];
        $this->assertEquals(10.0, $stored['high']);
        $this->assertEquals(3.0, $stored['low']);
    }

    /**
     * Test updateTempExtremes stores data with observation timestamp
     */
    public function testUpdateTempExtremesUsesObservationTimestamp(): void
    {
        require_once __DIR__ . '/../../lib/weather/daily-tracking.php';

        $airport = [
            'timezone' => 'America/Boise',
            'name' => 'Boise Air Terminal'
        ];

        $obsTimestamp = mktime(5, 0, 0, 2, 13, 2026);
        $temp = 8.5;

        updateTempExtremes(self::TEST_AIRPORT, $temp, $airport, $obsTimestamp);

        $tz = new DateTimeZone('America/Boise');
        $obsDate = new DateTime('@' . $obsTimestamp);
        $obsDate->setTimezone($tz);
        $expectedDateKey = $obsDate->format('Y-m-d');

        $content = file_get_contents(getTempExtremesTrackingPath(self::TEST_AIRPORT));
        $data = json_decode($content, true);

        $this->assertArrayHasKey($expectedDateKey, $data);
        $this->assertEquals($temp, $data[$expectedDateKey]['high']);
    }
}
