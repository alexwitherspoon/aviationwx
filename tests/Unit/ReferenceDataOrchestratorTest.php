<?php

declare(strict_types=1);

namespace AviationWx\Tests\Unit;

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../Helpers/IsolatesOurAirportsCacheTrait.php';

/**
 * Reference-data orchestrator gates (ProcessPool enqueue policy).
 */
final class ReferenceDataOrchestratorTest extends TestCase
{
    use \IsolatesOurAirportsCacheTrait;

    protected function setUp(): void
    {
        require_once dirname(__DIR__, 2) . '/lib/cache-paths.php';
        require_once dirname(__DIR__, 2) . '/lib/constants.php';
        require_once dirname(__DIR__, 2) . '/lib/ourairports/meta.php';
        require_once dirname(__DIR__, 2) . '/lib/reference-data/orchestrator.php';
        $this->resetOurAirportsTestCacheState();
    }

    public function testJobs_ListExpectedSingletons(): void
    {
        $jobs = referenceDataJobs();
        $this->assertSame(
            [
                'ourairports_probe',
                'ourairports_bulk',
                'runways_merge',
                'nasr_apt',
                'nasr_frq',
                'country_resolution',
            ],
            array_keys($jobs)
        );
        $this->assertSame(NASR_APT_WORKER_TIMEOUT, $jobs['nasr_apt']['timeout']);
        $this->assertSame(COUNTRY_RESOLUTION_WORKER_TIMEOUT, $jobs['country_resolution']['timeout']);
    }

    public function testFrq_BlockedWhileAptPoolActive(): void
    {
        $pools = [
            'nasr_apt' => $this->makePool(1),
            'nasr_frq' => $this->makePool(0),
        ];
        $state = ['last_nasr_frq' => 0];

        $this->assertFalse(
            referenceDataShouldEnqueue('nasr_frq', time(), $pools, $state),
            'FRQ must not enqueue while APT pool is active'
        );
    }

    public function testRunways_BlockedWhileBulkPoolActive(): void
    {
        $pools = [
            'ourairports_bulk' => $this->makePool(1),
            'runways_merge' => $this->makePool(0),
        ];
        $state = [
            'runways_startup_done' => true,
            'last_runways' => 0,
        ];

        $this->assertFalse(referenceDataShouldEnqueue('runways_merge', time(), $pools, $state));
    }

    public function testProbe_BlockedWhileBulkPoolActive(): void
    {
        $pools = [
            'ourairports_probe' => $this->makePool(0),
            'ourairports_bulk' => $this->makePool(1),
        ];
        $state = ['last_ourairports_probe' => 0];

        $this->assertFalse(referenceDataShouldEnqueue('ourairports_probe', time(), $pools, $state));
    }

    public function testEnqueue_StartsJobAndUpdatesState(): void
    {
        $addedArgs = null;
        $pool = new class ($addedArgs) {
            /** @var array<string, mixed>|null */
            private $addedArgs;

            public function __construct(?array &$addedArgs)
            {
                $this->addedArgs = &$addedArgs;
            }

            public function getActiveCount(): int
            {
                return 0;
            }

            public function addJob(array $args): bool
            {
                $this->addedArgs = $args;
                return true;
            }
        };

        $now = time();
        $state = [
            'last_ourairports_probe' => 0,
            'last_ourairports_bulk' => $now,
            'last_runways' => $now,
            'runways_startup_done' => true,
            'last_nasr_apt' => $now,
            'last_nasr_frq' => $now,
            'last_country_check' => $now,
            'country_startup_eval' => true,
            'config_path' => null,
            'config_sha' => null,
        ];
        $pools = [
            'ourairports_probe' => $pool,
            'ourairports_bulk' => $this->makePool(0),
            'runways_merge' => $this->makePool(0),
            'nasr_apt' => $this->makePool(0),
            'nasr_frq' => $this->makePool(0),
            'country_resolution' => $this->makePool(0),
        ];

        $started = referenceDataEnqueueDueJobs($now, $pools, $state);
        $this->assertSame(['ourairports_probe'], $started);
        $this->assertSame([], $addedArgs);
        $this->assertSame($now, $state['last_ourairports_probe']);
    }

    public function testEnqueue_KeepsRunwaysStartupPendingWhileBulkActive(): void
    {
        $now = time();
        $state = [
            'last_ourairports_probe' => $now,
            'last_ourairports_bulk' => $now,
            'last_runways' => 0,
            'runways_startup_done' => false,
            'last_nasr_apt' => $now,
            'last_nasr_frq' => $now,
            'last_country_check' => $now,
            'country_startup_eval' => true,
            'config_path' => null,
            'config_sha' => null,
        ];
        $pools = [
            'ourairports_probe' => $this->makePool(0),
            'ourairports_bulk' => $this->makePool(1),
            'runways_merge' => $this->makePool(0),
            'nasr_apt' => $this->makePool(0),
            'nasr_frq' => $this->makePool(0),
            'country_resolution' => $this->makePool(0),
        ];

        $started = referenceDataEnqueueDueJobs($now, $pools, $state);
        $this->assertSame([], $started);
        $this->assertFalse($state['runways_startup_done']);
        $this->assertSame(0, $state['last_runways']);
    }

    public function testEnqueue_AdvancesRunwaysStartupWhenSkippedWithoutUpstream(): void
    {
        $now = time();
        $state = [
            'last_ourairports_probe' => $now,
            'last_ourairports_bulk' => $now,
            'last_runways' => 0,
            'runways_startup_done' => false,
            'last_nasr_apt' => $now,
            'last_nasr_frq' => $now,
            'last_country_check' => $now,
            'country_startup_eval' => true,
            'config_path' => null,
            'config_sha' => null,
        ];
        $pools = [
            'ourairports_probe' => $this->makePool(0),
            'ourairports_bulk' => $this->makePool(0),
            'runways_merge' => $this->makePool(0),
            'nasr_apt' => $this->makePool(0),
            'nasr_frq' => $this->makePool(0),
            'country_resolution' => $this->makePool(0),
        ];

        // No runway inputs in test cache => shouldEnqueue false; still advances startup flag.
        $started = referenceDataEnqueueDueJobs($now, $pools, $state);
        $this->assertNotContains('runways_merge', $started);
        $this->assertTrue($state['runways_startup_done']);
        $this->assertSame($now, $state['last_runways']);
    }

    /**
     * System regression: early merge stamps last_runways; bulk then lands newer CSVs.
     * Source mtime must beat the last-attempt throttle so merge is not delayed an hour.
     */
    public function testRunways_EnqueuesWhenSourcesNewerDespiteRecentLastAttempt(): void
    {
        $now = time();
        $this->seedRunnableRunwayMergeInputs($now - 7200, $now);

        $state = [
            'runways_startup_done' => true,
            'last_runways' => $now - 60,
        ];
        $pools = [
            'runways_merge' => $this->makePool(0),
            'ourairports_bulk' => $this->makePool(0),
        ];

        $this->assertTrue(referenceDataRunwaysSourceInputsNewerThanMerge());
        $this->assertTrue(
            referenceDataShouldEnqueue('runways_merge', $now, $pools, $state),
            'Newer source CSVs must bypass last_runways interval'
        );
    }

    public function testRunways_KeepsBackoffWhenSourcesUnchangedAfterFailedAttempt(): void
    {
        $now = time();
        // Sources newer than merge, but last attempt is after those source mtimes
        // (failed merge that did not publish a new cache).
        $sourceMtime = $now - 120;
        $this->seedRunnableRunwayMergeInputs($now - 7200, $sourceMtime);

        $state = [
            'runways_startup_done' => true,
            'last_runways' => $now - 30,
        ];
        $pools = [
            'runways_merge' => $this->makePool(0),
            'ourairports_bulk' => $this->makePool(0),
        ];

        $this->assertTrue(referenceDataRunwaysSourceInputsNewerThanMerge());
        $this->assertFalse(
            referenceDataShouldEnqueue('runways_merge', $now, $pools, $state),
            'Unchanged sources after a failed attempt must keep the interval backoff'
        );
    }

    public function testEnqueue_DoesNotAdvanceCountryEvalWhenConfigIdentityMissing(): void
    {
        $now = time();
        $state = [
            'last_ourairports_probe' => $now,
            'last_ourairports_bulk' => $now,
            'last_runways' => $now,
            'runways_startup_done' => true,
            'last_nasr_apt' => $now,
            'last_nasr_frq' => $now,
            'last_country_check' => 0,
            'country_startup_eval' => false,
            'config_path' => null,
            'config_sha' => null,
        ];
        $pools = [
            'ourairports_probe' => $this->makePool(0),
            'ourairports_bulk' => $this->makePool(0),
            'runways_merge' => $this->makePool(0),
            'nasr_apt' => $this->makePool(0),
            'nasr_frq' => $this->makePool(0),
            'country_resolution' => $this->makePool(0),
        ];

        $started = referenceDataEnqueueDueJobs($now, $pools, $state);
        $this->assertNotContains('country_resolution', $started);
        $this->assertFalse($state['country_startup_eval']);
        $this->assertSame(0, $state['last_country_check']);
    }

    public function testRunways_EnqueuesWhenNgdaNewerDespiteRecentLastAttempt(): void
    {
        $now = time();
        // OA CSVs not newer than merge; NGDA CSV is.
        $this->seedRunnableRunwayMergeInputs($now - 60, $now - 60);
        file_put_contents(CACHE_FAA_NGDA_RUNWAYS_CSV, "ARPT_ID,RWY_ID\nKTEST,18/36\n", LOCK_EX);
        touch(CACHE_FAA_NGDA_RUNWAYS_CSV, $now);

        $state = [
            'runways_startup_done' => true,
            'last_runways' => $now - 60,
        ];
        $pools = [
            'runways_merge' => $this->makePool(0),
            'ourairports_bulk' => $this->makePool(0),
        ];

        $this->assertTrue(faaNgdaRunwayCsvNewerThanMerge());
        $this->assertTrue(referenceDataRunwaysSourceInputsNewerThanMerge());
        $this->assertTrue(referenceDataShouldEnqueue('runways_merge', $now, $pools, $state));
    }

    /**
     * Full tick: after bulk lands newer CSVs, enqueue must start merge despite a fresh last_runways.
     */
    public function testEnqueue_StartsRunwaysWhenSourcesNewerWithinInterval(): void
    {
        $now = time();
        $this->seedRunnableRunwayMergeInputs($now - 7200, $now);

        $added = false;
        $runwaysPool = new class ($added) {
            private bool $added;

            public function __construct(bool &$added)
            {
                $this->added = &$added;
            }

            public function getActiveCount(): int
            {
                return 0;
            }

            public function addJob(array $args): bool
            {
                $this->added = true;
                return true;
            }
        };

        $state = [
            'last_ourairports_probe' => $now,
            'last_ourairports_bulk' => $now,
            'last_runways' => $now - 60,
            'runways_startup_done' => true,
            'last_nasr_apt' => $now,
            'last_nasr_frq' => $now,
            'last_country_check' => $now,
            'country_startup_eval' => true,
            'config_path' => null,
            'config_sha' => null,
        ];
        $pools = [
            'ourairports_probe' => $this->makePool(0),
            'ourairports_bulk' => $this->makePool(0),
            'runways_merge' => $runwaysPool,
            'nasr_apt' => $this->makePool(0),
            'nasr_frq' => $this->makePool(0),
            'country_resolution' => $this->makePool(0),
        ];

        $started = referenceDataEnqueueDueJobs($now, $pools, $state);
        $this->assertSame(['runways_merge'], $started);
        $this->assertTrue($added);
        $this->assertSame($now, $state['last_runways']);
    }

    public function testRunways_IdleThrottleHoldsWhenSourcesNotNewer(): void
    {
        $now = time();
        // Merge and sources share the same age; force max-age refresh due so the interval
        // gate is the only thing preventing enqueue.
        $this->seedRunnableRunwayMergeInputs($now - RUNWAYS_CACHE_MAX_AGE - 10, $now - RUNWAYS_CACHE_MAX_AGE - 10);

        $state = [
            'runways_startup_done' => true,
            'last_runways' => $now - 60,
        ];
        $pools = [
            'runways_merge' => $this->makePool(0),
            'ourairports_bulk' => $this->makePool(0),
        ];

        $this->assertFalse(referenceDataRunwaysSourceInputsNewerThanMerge());
        $this->assertTrue(runwaysCacheNeedsRefresh(), 'max-age should still make merge due');
        $this->assertFalse(
            referenceDataShouldEnqueue('runways_merge', $now, $pools, $state),
            'Without newer sources, last_runways remains an idle throttle'
        );
    }

    public function testRunways_EnqueuesAfterIntervalWhenSourcesNotNewerButDue(): void
    {
        $now = time();
        $this->seedRunnableRunwayMergeInputs($now - RUNWAYS_CACHE_MAX_AGE - 10, $now - RUNWAYS_CACHE_MAX_AGE - 10);

        $state = [
            'runways_startup_done' => true,
            'last_runways' => $now - OURAIRPORTS_BULK_FETCH_CHECK_INTERVAL - 1,
        ];
        $pools = [
            'runways_merge' => $this->makePool(0),
            'ourairports_bulk' => $this->makePool(0),
        ];

        $this->assertTrue(referenceDataShouldEnqueue('runways_merge', $now, $pools, $state));
    }

    /**
     * @param int $mergeMtime Published merge cache mtime
     * @param int $sourceMtime OurAirports CSV mtime
     */
    private function seedRunnableRunwayMergeInputs(int $mergeMtime, int $sourceMtime): void
    {
        file_put_contents(CACHE_RUNWAYS_DATA_FILE, '{}', LOCK_EX);
        touch(CACHE_RUNWAYS_DATA_FILE, $mergeMtime);

        file_put_contents(CACHE_OURAIRPORTS_AIRPORTS_CSV, "id,ident\n", LOCK_EX);
        file_put_contents(CACHE_OURAIRPORTS_RUNWAYS_CSV, "id,airport_ident\n", LOCK_EX);
        touch(CACHE_OURAIRPORTS_AIRPORTS_CSV, $sourceMtime);
        touch(CACHE_OURAIRPORTS_RUNWAYS_CSV, $sourceMtime);

        ourAirportsUpdateFileMeta('airports', [
            'last_probe_result' => 'unchanged',
            'last_fetch_at' => $sourceMtime,
        ]);
        ourAirportsUpdateFileMeta('runways', [
            'last_probe_result' => 'unchanged',
            'last_fetch_at' => $sourceMtime,
        ]);
    }

    /**
     * @return object
     */
    private function makePool(int $active): object
    {
        return new class ($active) {
            private int $active;

            public function __construct(int $active)
            {
                $this->active = $active;
            }

            public function getActiveCount(): int
            {
                return $this->active;
            }

            public function addJob(array $args): bool
            {
                return false;
            }
        };
    }
}
