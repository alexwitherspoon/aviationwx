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

    public function testBuildNasrDownloadPlansResolvesCurrentCycleWithinTimeout(): void
    {
        $reference = strtotime('2026-07-14 UTC');
        $start = time();
        $plans = buildNasrAptDownloadPlans($reference);
        $elapsed = time() - $start;

        if ($plans === []) {
            $this->markTestSkipped('NFDC NASR APT zips unreachable from CI environment');
        }

        $this->assertLessThanOrEqual(NASR_DISCOVERY_SMOKE_TIMEOUT_SECONDS, $elapsed);
        $this->assertCount(1, $plans);
        $this->assertSame('2026-07-09', $plans[0]['effective_date']);
        $this->assertStringContainsString('09_Jul_2026_APT_CSV.zip', $plans[0]['source_url']);
    }

    public function testDiscoverNasrTrackedCyclesUsesCachedMetaWhenZipReachable(): void
    {
        $reference = strtotime('2026-07-14 UTC');
        $meta = [
            'tracked_current_cycle_date' => '2026-07-09',
            'tracked_next_cycle_date' => '2026-08-06',
        ];

        $this->assertFalse(nasrCycleRediscoveryNeeded($meta, $reference));

        $tracked = discoverNasrTrackedCycles($meta, $reference);
        if ($tracked['source'] !== 'cached_meta') {
            $this->markTestSkipped('NFDC unreachable; cached-meta fast path not verified live');
        }

        $this->assertSame('2026-07-09', $tracked['current_cycle_date']);
        $this->assertSame('2026-08-06', $tracked['next_cycle_date']);
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

    public function testDiscoverNasrCycleDatesFromFaaIndexIncludesCurrentCycle(): void
    {
        $dates = discoverNasrCycleDatesFromFaaIndex();
        if ($dates === []) {
            $this->markTestSkipped('FAA NASR index unreachable from CI environment');
        }

        $this->assertContains('2026-07-09', $dates);
    }

    public function testDiscoverNasrAptZipUrlFromCyclePageMatchesExpectedPattern(): void
    {
        $url = discoverNasrAptZipUrlFromCyclePage('2026-07-09');
        if ($url === null) {
            $this->markTestSkipped('FAA NASR cycle page unreachable from CI environment');
        }

        $this->assertStringContainsString('09_Jul_2026_APT_CSV.zip', $url);
    }
}
