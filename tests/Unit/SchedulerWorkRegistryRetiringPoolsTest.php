<?php

declare(strict_types=1);

namespace AviationWx\Tests\Unit;

use PHPUnit\Framework\TestCase;
use SchedulerWorkRegistry;

/**
 * TDD: registry must keep drain-visible active counts across setPool replacement.
 */
final class SchedulerWorkRegistryRetiringPoolsTest extends TestCase
{
    protected function setUp(): void
    {
        require_once dirname(__DIR__, 2) . '/lib/deploy-drain.php';
        require_once dirname(__DIR__, 2) . '/lib/scheduler-work-registry.php';
    }

    public function testSetPool_ReplaceWhileActive_KeepsSumUntilOldPoolIdle(): void
    {
        $reg = new SchedulerWorkRegistry();
        $oldActive = 3;
        $old = $this->makeMutablePool($oldActive);
        $reg->setPool('weather', $old);
        $this->assertSame(3, $reg->sumActiveWorkers());

        $newActive = 0;
        $reg->setPool('weather', $this->makeMutablePool($newActive));
        $this->assertSame(
            3,
            $reg->sumActiveWorkers(),
            'Replacing a pool with in-flight workers must not drop drain active count'
        );

        $oldActive = 0;
        $reg->cleanupFinishedAll();
        $this->assertSame(0, $reg->sumActiveWorkers());
    }

    public function testTerminateAll_TerminatesRetiringPools(): void
    {
        $reg = new SchedulerWorkRegistry();
        $terminated = 0;
        $old = new class ($terminated) {
            private int $terminated;
            private int $active = 2;
            public function __construct(int &$terminated)
            {
                $this->terminated = &$terminated;
            }
            public function getActiveCount(): int
            {
                return $this->active;
            }
            public function cleanup(): void
            {
                $this->terminated++;
                $this->active = 0;
            }
        };
        $reg->setPool('weather', $old);
        $idle = 0;
        $reg->setPool('weather', $this->makeMutablePool($idle));

        $reg->terminateAll();
        $this->assertSame(1, $terminated);
        $this->assertSame(0, $reg->sumActiveWorkers());
    }

    public function testCleanupFinishedAll_RunsOnRetiringPoolsAndPrunesWhenIdle(): void
    {
        $reg = new SchedulerWorkRegistry();
        $cleaned = 0;
        $active = 1;
        $old = new class ($cleaned, $active) {
            private int $cleaned;
            private int $active;
            public function __construct(int &$cleaned, int &$active)
            {
                $this->cleaned = &$cleaned;
                $this->active = &$active;
            }
            public function getActiveCount(): int
            {
                return $this->active;
            }
            public function cleanupFinished(array &$stats): void
            {
                $this->cleaned++;
                $this->active = 0;
                $stats['completed'] = 1;
            }
        };
        $reg->setPool('webcam', $old);
        $idle = 0;
        $reg->setPool('webcam', $this->makeMutablePool($idle));
        $this->assertSame(1, $reg->sumActiveWorkers());

        $reg->cleanupFinishedAll();
        $this->assertSame(1, $cleaned);
        $this->assertSame(0, $reg->sumActiveWorkers());
    }

    public function testSetPool_NullWithActiveWorkers_RetiresUntilIdle(): void
    {
        $reg = new SchedulerWorkRegistry();
        $active = 2;
        $reg->setPool('notam', $this->makeMutablePool($active));
        $reg->setPool('notam', null);
        $this->assertSame([], $reg->registeredPoolNames());
        $this->assertSame(2, $reg->sumActiveWorkers());

        $active = 0;
        $reg->cleanupFinishedAll();
        $this->assertSame(0, $reg->sumActiveWorkers());
    }

    /**
     * @return object
     */
    private function makeMutablePool(int &$active): object
    {
        return new class ($active) {
            private int $active;
            public function __construct(int &$active)
            {
                $this->active = &$active;
            }
            public function getActiveCount(): int
            {
                return $this->active;
            }
        };
    }
}
