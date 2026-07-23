<?php
/**
 * Unit tests for NASR APT parsing, runway selection, and cycle discovery.
 */

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../lib/nasr/parse.php';
require_once __DIR__ . '/../../lib/nasr/runway-selection.php';
require_once __DIR__ . '/../../lib/nasr/cache.php';
require_once __DIR__ . '/../../lib/nasr/discovery.php';

class NasrParseTest extends TestCase
{
    private string $fixtureDir;

    protected function setUp(): void
    {
        $this->fixtureDir = __DIR__ . '/../Fixtures/nasr';
        resetNasrAptCacheMemo();
    }

    public function testParseFixtureContainsExpectedAirports(): void
    {
        $parsed = nasrParseAptCsvDirectory($this->fixtureDir);
        $this->assertArrayHasKey('03S', $parsed['airports']);
        $this->assertArrayHasKey('ID76', $parsed['airports']);
        $this->assertArrayHasKey('C80', $parsed['airports']);
        $this->assertSame('RIU', $parsed['airports']['C80']['notam_id']);
    }

    public function testParseFixtureAttachesCalmWindFromAptRmk(): void
    {
        $parsed = nasrParseAptCsvDirectory($this->fixtureDir);
        $this->assertArrayHasKey('SPB', $parsed['airports']);
        $calm = $parsed['airports']['SPB']['calm_wind'] ?? null;
        $this->assertIsArray($calm);
        $this->assertSame('15', $calm['arrival']);
        $this->assertSame('33', $calm['departure']);
    }

    public function testParseFixtureRunwayLightsCode(): void
    {
        $parsed = nasrParseAptCsvDirectory($this->fixtureDir);
        $runways = $parsed['airports']['C80']['runways'] ?? [];
        $byId = [];
        foreach ($runways as $runway) {
            $byId[$runway['rwy_id']] = $runway;
        }
        $this->assertSame('MED', $byId['12/30']['lights_code'] ?? null);
    }

    public function testParseFixtureMagneticDeclinationEastEul(): void
    {
        $parsed = nasrParseAptCsvDirectory($this->fixtureDir);
        $this->assertSame(16.0, $parsed['airports']['EUL']['mag_declination_deg']);
        $this->assertSame(2000, $parsed['airports']['EUL']['mag_declination_year']);
    }

    public function testParseMagneticDeclinationWestReturnsNegative(): void
    {
        $this->assertSame(-12.5, nasrParseMagneticDeclinationDeg('12.5', 'W'));
        $this->assertSame(15.0, nasrParseMagneticDeclinationDeg('15', 'E'));
        $this->assertNull(nasrParseMagneticDeclinationDeg('', 'E'));
        $this->assertNull(nasrParseMagneticDeclinationDeg('14', ''));
    }

    public function testParseFixtureId76HasNoMagneticDeclination(): void
    {
        $parsed = nasrParseAptCsvDirectory($this->fixtureDir);
        $this->assertNull($parsed['airports']['ID76']['mag_declination_deg']);
        $this->assertNull($parsed['airports']['ID76']['mag_declination_year']);
    }

    public function testSelectLongestRunwayExcludesFailedSurface(): void
    {
        $parsed = nasrParseAptCsvDirectory($this->fixtureDir);
        $selected = nasrSelectLongestActiveLandRunway($parsed['airports']['C80']);
        $this->assertNotNull($selected);
        $this->assertSame(5000, $selected['length_ft']);
        $this->assertSame('12/30', $selected['rwy_id']);
    }

    public function testSelectLongestRunwayUsesTurfRunwayForId76(): void
    {
        $parsed = nasrParseAptCsvDirectory($this->fixtureDir);
        $selected = nasrSelectLongestActiveLandRunway($parsed['airports']['ID76']);
        $this->assertNotNull($selected);
        $this->assertSame(2260, $selected['length_ft']);
        $this->assertTrue(nasrIsNonPavedSurface($selected['surface']));
    }

    public function testEffectiveDepartureLengthUsesDisplacedThresholdAndTkofDist(): void
    {
        $this->assertSame(4800, nasrEffectiveDepartureLengthFt([
            'displaced_thr_len' => 500,
            'tkof_dist_avbl' => 4800,
        ], 5500));
        $this->assertSame(4500, nasrEffectiveDepartureLengthFt([
            'displaced_thr_len' => 1000,
            'tkof_dist_avbl' => null,
        ], 5500));
        $this->assertSame(3000, nasrEffectiveDepartureLengthFt([
            'displaced_thr_len' => null,
            'tkof_dist_avbl' => 3000,
        ], 5500));
        $this->assertSame(0, nasrEffectiveDepartureLengthFt([], 0));
    }

    public function testBuildNasrAptZipUrlUsesFaaFilenameFormat(): void
    {
        $this->assertSame(
            'https://nfdc.faa.gov/webContent/28DaySub/extra/15_May_2025_APT_CSV.zip',
            buildNasrAptZipUrl('2025-05-15')
        );
        $this->assertSame(
            'https://nfdc.faa.gov/webContent/28DaySub/extra/09_Jul_2026_APT_CSV.zip',
            buildNasrAptZipUrl('2026-07-09')
        );
    }

    public function testSelectCurrentNasrCycleDatePrefersActiveCycleOverPreview(): void
    {
        $reference = strtotime('2026-07-14 UTC');
        $current = selectCurrentNasrCycleDate(
            ['2026-08-06', '2026-07-09', '2026-05-14'],
            $reference
        );

        $this->assertSame('2026-07-09', $current);
    }

    public function testSelectNextNasrCycleDateReturnsEarliestFutureCycle(): void
    {
        $next = selectNextNasrCycleDate(
            ['2026-08-06', '2026-09-03', '2026-07-09'],
            '2026-07-09'
        );

        $this->assertSame('2026-08-06', $next);
    }

    public function testNasrCycleRediscoveryNeededWhenNoTrackedCycles(): void
    {
        $this->assertTrue(nasrCycleRediscoveryNeeded(null));
        $this->assertTrue(nasrCycleRediscoveryNeeded([]));
    }

    public function testNasrCycleRediscoveryNeededWhenNextCyclePassed(): void
    {
        $reference = strtotime('2026-08-07 UTC');
        $meta = [
            'tracked_current_cycle_date' => '2026-07-09',
            'tracked_next_cycle_date' => '2026-08-06',
        ];

        $this->assertTrue(nasrCycleRediscoveryNeeded($meta, $reference));
    }

    public function testNasrCycleRediscoveryNotNeededBeforeNextCycle(): void
    {
        $reference = strtotime('2026-07-14 UTC');
        $meta = [
            'tracked_current_cycle_date' => '2026-07-09',
            'tracked_next_cycle_date' => '2026-08-06',
        ];

        $this->assertFalse(nasrCycleRediscoveryNeeded($meta, $reference));
    }

    public function testNasrCycleRediscoveryNeededWhenEstimatedNextCyclePassed(): void
    {
        $reference = strtotime('2026-08-07 UTC');
        $meta = [
            'tracked_current_cycle_date' => '2026-07-09',
            'tracked_next_cycle_date' => null,
        ];

        $this->assertTrue(nasrCycleRediscoveryNeeded($meta, $reference));
    }

    public function testNasrEstimateNextCycleDate(): void
    {
        $this->assertSame('2026-08-06', nasrEstimateNextCycleDate('2026-07-09'));
    }

    public function testGenerateNasrNarrowProbeWindowSize(): void
    {
        $dates = generateNasrNarrowProbeWindow('2026-07-09', 14, 14);
        $this->assertCount(29, $dates);
        $this->assertContains('2026-07-09', $dates);
    }

    public function testRankNasrCycleDatesByProximityPrefersCurrentOverPreview(): void
    {
        $reference = strtotime('2026-07-14 UTC');
        $ranked = rankNasrCycleDatesByProximityToToday(
            ['2026-08-06', '2026-07-09', '2026-05-14'],
            $reference
        );

        $this->assertSame('2026-07-09', $ranked[0]);
        $this->assertSame('2026-08-06', $ranked[1]);
    }

    public function testRankNasrCycleDatesByProximityTiePrefersEarlierCycle(): void
    {
        $reference = strtotime('2026-07-22 UTC');
        $ranked = rankNasrCycleDatesByProximityToToday(
            ['2026-07-09', '2026-08-06'],
            $reference
        );

        $this->assertSame('2026-07-09', $ranked[0]);
        $this->assertSame('2026-08-06', $ranked[1]);
    }

    public function testNasrFrqCacheNeedsRefreshWhenCycleRediscoveryDue(): void
    {
        require_once __DIR__ . '/../../lib/nasr/frequencies-cache.php';

        $frqPath = CACHE_NASR_FRQ_DATA_FILE;
        $metaPath = CACHE_NASR_APT_META_FILE;
        $frqBackup = file_exists($frqPath) ? file_get_contents($frqPath) : null;
        $metaBackup = file_exists($metaPath) ? file_get_contents($metaPath) : null;

        $dir = dirname($frqPath);
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        file_put_contents($frqPath, json_encode([
            'schema_version' => NASR_FRQ_SCHEMA_VERSION,
            'airports' => ['KTEST' => ['TWR' => '118.0']],
        ], JSON_UNESCAPED_SLASHES));

        try {
            file_put_contents($metaPath, json_encode([
                'tracked_current_cycle_date' => gmdate('Y-m-d', time() - (NASR_CYCLE_PERIOD_DAYS * 86400)),
                'tracked_next_cycle_date' => gmdate('Y-m-d', time() - 86400),
                'frq_fetched_at' => gmdate('c', time() - 86400),
            ], JSON_UNESCAPED_SLASHES));

            $this->assertTrue(nasrFrqCacheNeedsRefresh());
        } finally {
            if ($frqBackup !== null) {
                file_put_contents($frqPath, $frqBackup);
            } elseif (file_exists($frqPath)) {
                @unlink($frqPath);
            }
            if ($metaBackup !== null) {
                file_put_contents($metaPath, $metaBackup);
            } elseif (file_exists($metaPath)) {
                @unlink($metaPath);
            }
        }
    }
}
