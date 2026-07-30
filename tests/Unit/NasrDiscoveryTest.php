<?php
/**
 * Offline unit tests for NASR cycle discovery (no FAA/NFDC network).
 */

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../lib/nasr/discovery.php';

class NasrDiscoveryTest extends TestCase
{
    protected function tearDown(): void
    {
        unset(
            $GLOBALS['nasrHttpTestTransport'],
            $GLOBALS['nasrHttpThrottleTestDir'],
            $GLOBALS['nasrHttpThrottleTestForceEnforcement'],
            $GLOBALS['nasrHttpMinIntervalSeconds'],
            $GLOBALS['nasrHttpThrottleTestSleep']
        );
        parent::tearDown();
    }

    /**
     * @param callable(string, array): array{ok: bool, http_code: int, body: ?string, retryable: bool} $transport
     */
    private function setTransport(callable $transport): void
    {
        $GLOBALS['nasrHttpTestTransport'] = $transport;
    }

    public function testDiscoverTrackedCyclesUsesFaaIndexWithoutNetwork(): void
    {
        $reference = strtotime('2026-07-14 UTC');
        $this->setTransport(static function (string $url, array $options): array {
            if (str_contains($url, 'NASR_Subscription') && !preg_match('#/\d{4}-\d{2}-\d{2}#', $url)) {
                return [
                    'ok' => true,
                    'http_code' => 200,
                    'body' => '<a href="/air_traffic/flight_info/aeronav/aero_data/NASR_Subscription/2026-07-09">'
                        . 'Current</a>'
                        . '<a href="/air_traffic/flight_info/aeronav/aero_data/NASR_Subscription/2026-08-06">'
                        . 'Preview</a>',
                    'retryable' => false,
                ];
            }

            return ['ok' => false, 'http_code' => 404, 'body' => null, 'retryable' => false];
        });

        $tracked = discoverNasrTrackedCycles(null, $reference);

        $this->assertSame('faa_index', $tracked['source']);
        $this->assertSame('2026-07-09', $tracked['current_cycle_date']);
        $this->assertSame('2026-08-06', $tracked['next_cycle_date']);
    }

    public function testDiscoverTrackedCyclesUsesCachedMetaWhenZipMockedReachable(): void
    {
        $reference = strtotime('2026-07-14 UTC');
        $this->setTransport(static function (string $url, array $options): array {
            if (str_contains($url, '09_Jul_2026_APT_CSV.zip') && isset($options['range_bytes'])) {
                return ['ok' => true, 'http_code' => 206, 'body' => 'P', 'retryable' => false];
            }

            return ['ok' => false, 'http_code' => 404, 'body' => null, 'retryable' => false];
        });

        $tracked = discoverNasrTrackedCycles([
            'tracked_current_cycle_date' => '2026-07-09',
            'tracked_next_cycle_date' => '2026-08-06',
        ], $reference);

        $this->assertSame('cached_meta', $tracked['source']);
        $this->assertSame('2026-07-09', $tracked['current_cycle_date']);
        $this->assertSame('2026-08-06', $tracked['next_cycle_date']);
    }

    public function testDiscoverTrackedCyclesFallsBackToAiracProbeWhenIndexEmpty(): void
    {
        $reference = strtotime('2026-07-30 UTC');
        $hits = [];
        $this->setTransport(static function (string $url, array $options) use (&$hits): array {
            $hits[] = $url;

            if (str_contains($url, 'NASR_Subscription')) {
                return ['ok' => false, 'http_code' => 403, 'body' => null, 'retryable' => false];
            }

            if (isset($options['range_bytes'])
                && (str_contains($url, '09_Jul_2026_APT_CSV.zip')
                    || str_contains($url, '06_Aug_2026_APT_CSV.zip'))
            ) {
                return ['ok' => true, 'http_code' => 206, 'body' => 'P', 'retryable' => false];
            }

            return ['ok' => false, 'http_code' => 404, 'body' => null, 'retryable' => false];
        });

        $tracked = discoverNasrTrackedCycles(null, $reference);

        $this->assertSame('nfdc_airac', $tracked['source']);
        $this->assertSame('2026-07-09', $tracked['current_cycle_date']);
        $this->assertSame('2026-08-06', $tracked['next_cycle_date']);
        $this->assertLessThan(10, count($hits), 'AIRAC path should not spray daily probes');
    }

    public function testBuildDownloadPlansSkipsRedundantZipCheckForCachedMeta(): void
    {
        $reference = strtotime('2026-07-14 UTC');
        $rangeChecks = 0;
        $this->setTransport(static function (string $url, array $options) use (&$rangeChecks): array {
            if (isset($options['range_bytes']) && str_contains($url, '09_Jul_2026_APT_CSV.zip')) {
                $rangeChecks++;
                return ['ok' => true, 'http_code' => 206, 'body' => 'P', 'retryable' => false];
            }

            return ['ok' => false, 'http_code' => 404, 'body' => null, 'retryable' => false];
        });

        $plans = buildNasrAptDownloadPlans($reference, [
            'tracked_current_cycle_date' => '2026-07-09',
            'tracked_next_cycle_date' => '2026-08-06',
        ]);

        $this->assertCount(1, $plans);
        $this->assertSame('2026-07-09', $plans[0]['effective_date']);
        $this->assertSame(1, $rangeChecks);
    }

    public function testFinalizeProbedCyclesRetriesTransientNextCycleProbe(): void
    {
        $attempts = 0;
        $this->setTransport(static function (string $url, array $options) use (&$attempts): array {
            if (!isset($options['range_bytes']) || !str_contains($url, '06_Aug_2026_APT_CSV.zip')) {
                return ['ok' => false, 'http_code' => 404, 'body' => null, 'retryable' => false];
            }

            $attempts++;
            if ($attempts < 2) {
                return ['ok' => false, 'http_code' => 503, 'body' => null, 'retryable' => true];
            }

            return ['ok' => true, 'http_code' => 206, 'body' => 'P', 'retryable' => false];
        });

        $final = nasrFinalizeProbedCycles(['2026-07-09'], strtotime('2026-07-14 UTC'));

        $this->assertSame('2026-07-09', $final['current_cycle_date']);
        $this->assertSame('2026-08-06', $final['next_cycle_date']);
        $this->assertGreaterThanOrEqual(2, $attempts);
    }

    public function testNasrHttpThrottleSpacesRequestsWhenEnforced(): void
    {
        $dir = sys_get_temp_dir() . '/nasr-http-throttle-' . bin2hex(random_bytes(4));
        mkdir($dir, 0700, true);
        $GLOBALS['nasrHttpThrottleTestDir'] = $dir;
        $GLOBALS['nasrHttpThrottleTestForceEnforcement'] = true;
        $GLOBALS['nasrHttpMinIntervalSeconds'] = 60;
        $slept = [];
        $GLOBALS['nasrHttpThrottleTestSleep'] = static function (float $seconds) use (&$slept): void {
            $slept[] = $seconds;
        };

        try {
            nasrHttpThrottle();
            nasrHttpThrottle();
            $this->assertCount(1, $slept);
            $this->assertGreaterThan(50.0, $slept[0]);
            $this->assertLessThanOrEqual(60.0, $slept[0]);
        } finally {
            @unlink($dir . '/.http_last_request');
            @unlink($dir . '/.http_throttle.lock');
            @rmdir($dir);
        }
    }

    public function testNasrHttpThrottleSkippedInTestModeByDefault(): void
    {
        $dir = sys_get_temp_dir() . '/nasr-http-throttle-' . bin2hex(random_bytes(4));
        mkdir($dir, 0700, true);
        $GLOBALS['nasrHttpThrottleTestDir'] = $dir;
        $GLOBALS['nasrHttpMinIntervalSeconds'] = 30;
        unset($GLOBALS['nasrHttpThrottleTestForceEnforcement']);
        $slept = [];
        $GLOBALS['nasrHttpThrottleTestSleep'] = static function (float $seconds) use (&$slept): void {
            $slept[] = $seconds;
        };

        try {
            nasrHttpThrottle();
            nasrHttpThrottle();
            $this->assertSame([], $slept);
        } finally {
            @unlink($dir . '/.http_last_request');
            @unlink($dir . '/.http_throttle.lock');
            @rmdir($dir);
        }
    }
}
