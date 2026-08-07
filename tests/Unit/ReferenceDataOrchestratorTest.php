<?php

declare(strict_types=1);

namespace AviationWx\Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Reference-data orchestrator gates (ProcessPool enqueue policy).
 */
final class ReferenceDataOrchestratorTest extends TestCase
{
    protected function setUp(): void
    {
        require_once dirname(__DIR__, 2) . '/lib/constants.php';
        require_once dirname(__DIR__, 2) . '/lib/reference-data/orchestrator.php';
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
