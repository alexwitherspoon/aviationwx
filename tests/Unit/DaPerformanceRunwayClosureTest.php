<?php
/**
 * Closed runway exclusion for density altitude performance scoring.
 */

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../Helpers/LoadsNasrAptFixtureCacheTrait.php';
require_once __DIR__ . '/../../lib/constants.php';
require_once __DIR__ . '/../../lib/notam/cache.php';
require_once __DIR__ . '/../../lib/weather/da-performance-notam-closures.php';
require_once __DIR__ . '/../../lib/weather/density-altitude-performance.php';

class DaPerformanceRunwayClosureTest extends TestCase
{
    use LoadsNasrAptFixtureCacheTrait;

    private static string $notamCacheDir;

    public static function setUpBeforeClass(): void
    {
        self::$notamCacheDir = sys_get_temp_dir() . '/aviationwx-da-closure-notam-' . bin2hex(random_bytes(4));
        mkdir(self::$notamCacheDir, 0755, true);
        $GLOBALS['notamCacheTestDirectory'] = self::$notamCacheDir;
    }

    public static function tearDownAfterClass(): void
    {
        unset($GLOBALS['notamCacheTestDirectory']);
        if (is_dir(self::$notamCacheDir)) {
            foreach (scandir(self::$notamCacheDir) ?: [] as $item) {
                if ($item === '.' || $item === '..') {
                    continue;
                }
                @unlink(self::$notamCacheDir . '/' . $item);
            }
            @rmdir(self::$notamCacheDir);
        }
    }

    protected function setUp(): void
    {
        $this->loadNasrAptFixtureCache();
        foreach (scandir(self::$notamCacheDir) ?: [] as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            @unlink(self::$notamCacheDir . '/' . $item);
        }
    }

    protected function tearDown(): void
    {
        $this->tearDownNasrAptFixtureCache();
    }

    public function testNasrFailedConditionRunwayIsExcluded(): void
    {
        $nasrRecord = getNasrAirportForConfig(['faa' => 'C80']);
        $this->assertNotNull($nasrRecord);

        $runways = nasrSelectActiveLandRunwaysForPerformance($nasrRecord);
        $rwyIds = array_column($runways, 'rwy_id');

        $this->assertNotContains('01/19', $rwyIds);
        $this->assertContains('12/30', $rwyIds);
    }

    public function testActiveNotamPairClosureExcludesRunwayFromScoring(): void
    {
        $this->writeNotamCache('69v', [[
            'id' => 'n1',
            'location' => '69V',
            'text' => '69V RWY 18/36 CLSD',
            'code' => 'QMRXX',
            'start_time_utc' => gmdate('Y-m-d\TH:i:s\Z', time() - 3600),
            'end_time_utc' => gmdate('Y-m-d\TH:i:s\Z', time() + 3600),
        ]]);

        $nasrRecord = getNasrAirportForConfig(['faa' => '69V']);
        $runways = nasrSelectActiveLandRunwaysForPerformance($nasrRecord);
        $filtered = filterPerformanceRunwaysForActiveNotamClosures($runways, [
            'faa' => '69V',
            'icao' => '69V',
            'timezone' => 'America/Denver',
        ], '69v');

        $this->assertNotContains('18/36', array_column($filtered, 'rwy_id'));
        $this->assertContains('08/26', array_column($filtered, 'rwy_id'));
    }

    public function testActiveAerodromeClosureRemovesAllRunways(): void
    {
        $this->writeNotamCache('69v', [[
            'id' => 'n2',
            'location' => '69V',
            'text' => '69V AD AP CLSD',
            'code' => 'QFAXX',
            'start_time_utc' => gmdate('Y-m-d\TH:i:s\Z', time() - 3600),
            'end_time_utc' => gmdate('Y-m-d\TH:i:s\Z', time() + 3600),
        ]]);

        $nasrRecord = getNasrAirportForConfig(['faa' => '69V']);
        $runways = nasrSelectActiveLandRunwaysForPerformance($nasrRecord);
        $filtered = filterPerformanceRunwaysForActiveNotamClosures($runways, [
            'faa' => '69V',
            'timezone' => 'America/Denver',
        ], '69v');

        $this->assertSame([], $filtered);
    }

    public function testPartialRunwayRestrictionNotamDoesNotExcludeRunway(): void
    {
        $this->writeNotamCache('hio', [[
            'id' => 'n3',
            'location' => 'HIO',
            'text' => 'HIO RWY 13R/31L CLSD TO ACFT WINGSPAN MORE THAN 118FT',
            'code' => 'QMRXX',
            'start_time_utc' => gmdate('Y-m-d\TH:i:s\Z', time() - 3600),
            'end_time_utc' => gmdate('Y-m-d\TH:i:s\Z', time() + 3600),
        ]]);

        $nasrRecord = getNasrAirportForConfig(['faa' => 'HIO']);
        $runways = nasrSelectActiveLandRunwaysForPerformance($nasrRecord);
        $filtered = filterPerformanceRunwaysForActiveNotamClosures($runways, [
            'faa' => 'HIO',
            'icao' => 'KHIO',
            'timezone' => 'America/Los_Angeles',
        ], 'hio');

        $this->assertSame(count($runways), count($filtered));
    }

    public function testKboiTaxiwayNotamDoesNotMarkRunwayPairClosed(): void
    {
        $this->writeNotamCache('kboi', [
            [
                'id' => 'A1032/2026',
                'location' => 'KBOI',
                'text' => 'BOI RWY 10L/28R CLSD EXC XNG',
                'code' => 'QMRXX',
                'start_time_utc' => gmdate('Y-m-d\TH:i:s\Z', time() - 3600),
                'end_time_utc' => gmdate('Y-m-d\TH:i:s\Z', time() + 3600),
            ],
            [
                'id' => '06/140/2026',
                'location' => 'KBOI',
                'text' => 'TWY G BTN RWY 10R/28L AND TWY A CLSD CONSTRUCTION',
                'code' => '',
                'start_time_utc' => gmdate('Y-m-d\TH:i:s\Z', time() - 3600),
                'end_time_utc' => gmdate('Y-m-d\TH:i:s\Z', time() + 3600),
            ],
        ]);

        $closures = getActiveRunwayNotamClosuresForAirport('kboi', [
            'icao' => 'KBOI',
            'faa' => 'BOI',
            'timezone' => 'America/Boise',
        ]);

        $this->assertSame(['10L/28R'], $closures['closed_pair_designators']);
        $this->assertFalse($closures['aerodrome_closed']);
    }

    public function testUpcomingRunwayClosureNotamDoesNotExcludeRunway(): void
    {
        $this->writeNotamCache('69v', [[
            'id' => 'n4',
            'location' => '69V',
            'text' => '69V RWY 18/36 CLSD',
            'code' => 'QMRXX',
            'start_time_utc' => gmdate('Y-m-d\TH:i:s\Z', time() + 86400),
            'end_time_utc' => gmdate('Y-m-d\TH:i:s\Z', time() + 172800),
        ]]);

        $nasrRecord = getNasrAirportForConfig(['faa' => '69V']);
        $runways = nasrSelectActiveLandRunwaysForPerformance($nasrRecord);
        $filtered = filterPerformanceRunwaysForActiveNotamClosures($runways, [
            'faa' => '69V',
            'timezone' => 'America/Denver',
        ], '69v');

        $this->assertSame(count($runways), count($filtered));
    }

    public function testStaleNotamCacheDoesNotExcludeRunways(): void
    {
        $this->writeNotamCache('69v', [[
            'id' => 'n5',
            'location' => '69V',
            'text' => '69V RWY 08/26 CLSD',
            'code' => 'QMRXX',
            'start_time_utc' => gmdate('Y-m-d\TH:i:s\Z', time() - 3600),
            'end_time_utc' => gmdate('Y-m-d\TH:i:s\Z', time() + 3600),
        ]], time() - getNotamStaleFailclosedSeconds() - 60);

        $nasrRecord = getNasrAirportForConfig(['faa' => '69V']);
        $runways = nasrSelectActiveLandRunwaysForPerformance($nasrRecord);
        $filtered = filterPerformanceRunwaysForActiveNotamClosures($runways, [
            'faa' => '69V',
            'timezone' => 'America/Denver',
        ], '69v');

        $this->assertSame(count($runways), count($filtered));
    }

    public function testGetActiveRunwayNotamClosuresForAirport_NormalizesAirportIdForMemoKey(): void
    {
        $this->writeNotamCache('69v', [[
            'id' => 'n-memo-case',
            'location' => '69V',
            'text' => '69V RWY 18/36 CLSD',
            'code' => 'QMRXX',
            'start_time_utc' => gmdate('Y-m-d\TH:i:s\Z', time() - 3600),
            'end_time_utc' => gmdate('Y-m-d\TH:i:s\Z', time() + 3600),
        ]]);

        $airport = ['faa' => '69V', 'timezone' => 'America/Denver'];
        $upper = getActiveRunwayNotamClosuresForAirport('69V', $airport);
        $lower = getActiveRunwayNotamClosuresForAirport('69v', $airport);

        $this->assertContains('18/36', $upper['closed_pair_designators']);
        $this->assertSame($upper, $lower);
    }

    public function testBuildUsesRemainingRunwayWhenNotamClosesWorstStripAt69v(): void
    {
        $this->writeNotamCache('69v', [[
            'id' => 'n6',
            'location' => '69V',
            'text' => '69V RWY 18/36 CLSD',
            'code' => 'QMRXX',
            'start_time_utc' => gmdate('Y-m-d\TH:i:s\Z', time() - 3600),
            'end_time_utc' => gmdate('Y-m-d\TH:i:s\Z', time() + 3600),
        ]]);

        $result = computeDensityAltitudePerformance([
            'density_altitude' => 9399,
            'pressure_altitude' => 5673,
            'temperature' => 34.7,
        ], [
            'id' => '69v',
            'faa' => '69V',
            'elevation_ft' => 5915,
            'timezone' => 'America/Denver',
        ], '69v');

        $this->assertIsArray($result);
        $this->assertSame('normal', $result['tier']);
        $this->assertFalse($result['fallback']);
        $this->assertSame('08/26', $result['best_end']['rwy_id']);
        $rwyIds = array_map(static fn (array $row): string => (string) ($row['rwy_id'] ?? ''), $result['ends']);
        $this->assertNotContains('18/36', $rwyIds);
    }

    /**
     * @param list<array<string, mixed>> $notams
     */
    private function writeNotamCache(string $airportId, array $notams, ?int $mtime = null): void
    {
        $path = notamCacheFilePath($airportId);
        $written = notamWriteCacheFile($path, [
            'fetched_at' => gmdate('c'),
            'airport' => $airportId,
            'notams' => $notams,
            'status' => 'success',
        ]);
        $this->assertTrue($written);

        if ($mtime !== null) {
            touch($path, $mtime);
        }
    }
}
