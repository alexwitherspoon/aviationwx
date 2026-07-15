<?php
/**
 * Live NASR/FAA discovery integration tests (network-dependent).
 *
 * Not part of the default Integration suite (see phpunit.xml exclude).
 * Opt-in via RUN_EXTERNAL_UPSTREAM_TESTS=1 (make test-external-apis).
 */

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../lib/nasr/discovery.php';
require_once __DIR__ . '/../../lib/nasr/cache.php';

class NasrDiscoveryIntegrationTest extends TestCase
{
    protected function setUp(): void
    {
        if (getenv('RUN_EXTERNAL_UPSTREAM_TESTS') !== '1') {
            $this->markTestSkipped(
                'Live NASR/FAA discovery checks are opt-in. Run: make test-external-apis (see docs/TESTING.md).'
            );
        }
    }

    public function testBuildNasrDownloadPlansResolvesCurrentCycle(): void
    {
        $reference = strtotime('2026-07-14 UTC');
        $plans = buildNasrAptDownloadPlans($reference);

        if ($plans === []) {
            $this->markTestSkipped('NFDC NASR APT zips unreachable from CI environment');
        }

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
