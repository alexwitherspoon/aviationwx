<?php

declare(strict_types=1);

namespace AviationWx\Tests\Unit;

use PHPUnit\Framework\TestCase;
use SchedulerWorkRegistry;

/**
 * SchedulerWorkRegistry: drain-gated pools + enqueue ticks.
 */
final class SchedulerWorkRegistryTest extends TestCase
{
    protected function setUp(): void
    {
        require_once dirname(__DIR__, 2) . '/lib/deploy-drain.php';
        require_once dirname(__DIR__, 2) . '/lib/scheduler-work-registry.php';
    }

    public function testSetPool_RegistersAndSumsActiveWorkers(): void
    {
        $reg = new SchedulerWorkRegistry();
        $this->assertSame([], $reg->registeredPoolNames());
        $this->assertSame(0, $reg->sumActiveWorkers());

        $reg->setPool('weather', $this->makePool(2));
        $reg->setPool('webcam', $this->makePool(3));

        $this->assertSame(['weather', 'webcam'], $reg->registeredPoolNames());
        $this->assertSame(5, $reg->sumActiveWorkers());
    }

    public function testSetPool_NullUnregistersAndReplaceUpdatesCount(): void
    {
        $reg = new SchedulerWorkRegistry();
        $reg->setPool('weather', $this->makePool(2));
        $reg->setPool('weather', $this->makePool(9));
        // Prior pool still has 2 active workers; keep them in the retiring set for drain.
        $this->assertSame(11, $reg->sumActiveWorkers());

        $reg->setPool('weather', null);
        $this->assertSame([], $reg->registeredPoolNames());
        $this->assertSame(11, $reg->sumActiveWorkers());
    }

    public function testSetPool_BlankNameIgnored(): void
    {
        $reg = new SchedulerWorkRegistry();
        $reg->setPool('   ', $this->makePool(4));
        $reg->setPool('', $this->makePool(4));
        $this->assertSame([], $reg->registeredPoolNames());
        $this->assertSame(0, $reg->sumActiveWorkers());
    }

    public function testCleanupFinishedAll_InvokesOnlyPoolsWithMethod(): void
    {
        $reg = new SchedulerWorkRegistry();
        $cleaned = 0;
        $reg->setPool('with_cleanup', new class ($cleaned) {
            private int $cleaned;
            public function __construct(int &$cleaned)
            {
                $this->cleaned = &$cleaned;
            }
            public function cleanupFinished(array &$stats): void
            {
                $this->cleaned++;
                $stats['completed'] = 1;
            }
            public function getActiveCount(): int
            {
                return 0;
            }
        });
        $reg->setPool('active_only', $this->makePool(1));

        $reg->cleanupFinishedAll();
        $this->assertSame(1, $cleaned);
        $this->assertSame(1, $reg->sumActiveWorkers());
    }

    public function testTerminateAll_InvokesCleanupOnlyWhenPresent(): void
    {
        $reg = new SchedulerWorkRegistry();
        $terminated = 0;
        $reg->setPool('terminable', new class ($terminated) {
            private int $terminated;
            public function __construct(int &$terminated)
            {
                $this->terminated = &$terminated;
            }
            public function cleanup(): void
            {
                $this->terminated++;
            }
            public function getActiveCount(): int
            {
                return 2;
            }
        });
        $reg->setPool('active_only', $this->makePool(1));

        $reg->terminateAll();
        $this->assertSame(1, $terminated);
    }

    public function testRegisterEnqueueTick_BlankNameIgnored(): void
    {
        $reg = new SchedulerWorkRegistry();
        $ran = false;
        $reg->registerEnqueueTick('', static function () use (&$ran): void {
            $ran = true;
        });
        $reg->registerEnqueueTick('  ', static function () use (&$ran): void {
            $ran = true;
        });
        $this->assertSame([], $reg->registeredEnqueueTickNames());
        $reg->runEnqueueTicks(1);
        $this->assertFalse($ran);
    }

    public function testRunEnqueueTicks_EmptyRegistryIsNoop(): void
    {
        $reg = new SchedulerWorkRegistry();
        $reg->runEnqueueTicks(123);
        $this->assertSame([], $reg->registeredEnqueueTickNames());
    }

    public function testRunEnqueueTicks_PreservesRegistrationOrder(): void
    {
        $reg = new SchedulerWorkRegistry();
        $order = [];
        $reg->registerEnqueueTick('first', static function (int $now) use (&$order): void {
            $order[] = 'first:' . $now;
        });
        $reg->registerEnqueueTick('second', static function (int $now) use (&$order): void {
            $order[] = 'second:' . $now;
        });

        $this->assertSame(['first', 'second'], $reg->registeredEnqueueTickNames());
        $reg->runEnqueueTicks(42);
        $this->assertSame(['first:42', 'second:42'], $order);
    }

    public function testRegisterEnqueueTick_SameNameReplacesPrevious(): void
    {
        $reg = new SchedulerWorkRegistry();
        $hits = 0;
        $reg->registerEnqueueTick('weather', static function () use (&$hits): void {
            $hits = 1;
        });
        $reg->registerEnqueueTick('weather', static function () use (&$hits): void {
            $hits = 2;
        });

        $reg->runEnqueueTicks(1);
        $this->assertSame(2, $hits);
        $this->assertSame(['weather'], $reg->registeredEnqueueTickNames());
    }

    public function testDrainGateContract_CallerSkipsRunEnqueueTicksWhenPaused(): void
    {
        $reg = new SchedulerWorkRegistry();
        $ran = false;
        $reg->registerEnqueueTick('weather', static function () use (&$ran): void {
            $ran = true;
        });
        $reg->setPool('weather', $this->makePool(1));

        $tick = deploy_drain_evaluate_state(
            true,
            false,
            1000,
            $reg->sumActiveWorkers(),
            0,
            1010,
            120,
            7200,
            600
        );
        $this->assertFalse($tick['allow_new_work']);
        $this->assertSame('wait', $tick['action']);
        // Scheduler must not call runEnqueueTicks while paused.
        if ($tick['allow_new_work']) {
            $reg->runEnqueueTicks(1010);
        }
        $this->assertFalse($ran);

        $tickIdle = deploy_drain_evaluate_state(false, false, null, 0, 0, 1010, 120, 7200, 600);
        $this->assertTrue($tickIdle['allow_new_work']);
        if ($tickIdle['allow_new_work']) {
            $reg->runEnqueueTicks(1010);
        }
        $this->assertTrue($ran);
    }

    public function testTerminatePoolsByClass_OnlyCleansRequestedClassIncludingRetiring(): void
    {
        $reg = new SchedulerWorkRegistry();
        $liveHits = 0;
        $refHits = 0;

        $reg->setPool('weather', $this->makeTerminablePool($liveHits, 2), SCHEDULER_POOL_CLASS_LIVE);
        $reg->setPool('nasr_apt', $this->makeTerminablePool($refHits, 2), SCHEDULER_POOL_CLASS_REFERENCE);

        // Replace while active so both classes keep a retiring entry.
        $reg->setPool('weather', $this->makeTerminablePool($liveHits, 1), SCHEDULER_POOL_CLASS_LIVE);
        $reg->setPool('nasr_apt', $this->makeTerminablePool($refHits, 1), SCHEDULER_POOL_CLASS_REFERENCE);

        $byClass = $reg->sumActiveWorkersByClass();
        $this->assertSame(3, $byClass['live']);
        $this->assertSame(3, $byClass['reference']);
        $this->assertSame(2, $reg->retiringPoolCount());

        $reg->terminatePoolsByClass(SCHEDULER_POOL_CLASS_LIVE);
        $this->assertSame(2, $liveHits, 'current + retiring live pools');
        $this->assertSame(0, $refHits);
        $this->assertSame(1, $reg->retiringPoolCount(), 'reference retiring pool remains');

        $reg->terminatePoolsByClass(SCHEDULER_POOL_CLASS_REFERENCE);
        $this->assertSame(2, $liveHits);
        $this->assertSame(2, $refHits, 'current + retiring reference pools');
        $this->assertSame(0, $reg->retiringPoolCount());
    }

    /**
     * @return object
     */
    private function makeTerminablePool(int &$hits, int $active): object
    {
        return new class ($hits, $active) {
            private int $hits;
            private int $active;

            public function __construct(int &$hits, int $active)
            {
                $this->hits = &$hits;
                $this->active = $active;
            }

            public function cleanup(): void
            {
                $this->hits++;
                $this->active = 0;
            }

            public function getActiveCount(): int
            {
                return $this->active;
            }
        };
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
        };
    }
}
