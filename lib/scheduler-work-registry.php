<?php
/**
 * Scheduler work registry for deploy-drain gating.
 *
 * ProcessPools (setPool) and enqueue ticks (registerEnqueueTick) register here.
 * Drain counts, force-terminate, and allow_new_work execution iterate the registry
 * so new workers are covered when registered.
 *
 * Replacing a pool that still has active children keeps the old pool in a retiring
 * list until idle (or terminateAll), so deploy drain cannot lose in-flight workers
 * across config-driven ProcessPool recreation.
 */

declare(strict_types=1);

require_once __DIR__ . '/deploy-drain.php';

/**
 * Named ProcessPool + enqueue-tick registry for the scheduler daemon.
 */
final class SchedulerWorkRegistry
{
    /** @var array<string, object> getActiveCount / cleanupFinished / cleanup */
    private array $pools = [];

    /** @var list<object> Former pools still finishing children after setPool replacement */
    private array $retiringPools = [];

    /** @var array<string, callable(int):void> */
    private array $enqueueTicks = [];

    /**
     * Register or replace a ProcessPool. Null removes the name.
     *
     * @param string $name Stable worker family name (weather, webcam, ...)
     * @param object|null $pool Pool instance or null to unregister
     * @return void
     */
    public function setPool(string $name, ?object $pool): void
    {
        $name = trim($name);
        if ($name === '') {
            return;
        }

        if (isset($this->pools[$name])) {
            $previous = $this->pools[$name];
            if ($pool !== $previous) {
                $this->retirePoolIfActive($previous);
            }
            unset($this->pools[$name]);
        }

        if ($pool === null) {
            return;
        }
        $this->pools[$name] = $pool;
    }

    /**
     * Register or replace an enqueue tick (skipped while drain pauses new work).
     *
     * @param string $name Stable tick name
     * @param callable(int):void $tick Receives unix time
     * @return void
     */
    public function registerEnqueueTick(string $name, callable $tick): void
    {
        $name = trim($name);
        if ($name === '') {
            return;
        }
        $this->enqueueTicks[$name] = $tick;
    }

    /**
     * @return list<string>
     */
    public function registeredPoolNames(): array
    {
        return array_keys($this->pools);
    }

    /**
     * @return list<string>
     */
    public function registeredEnqueueTickNames(): array
    {
        return array_keys($this->enqueueTicks);
    }

    /**
     * @return int
     */
    public function retiringPoolCount(): int
    {
        $this->pruneRetiringPools();
        return count($this->retiringPools);
    }

    /**
     * @return void
     */
    public function cleanupFinishedAll(): void
    {
        foreach ($this->allTrackedPools() as $pool) {
            if (!method_exists($pool, 'cleanupFinished')) {
                continue;
            }
            $dummyStats = ['completed' => 0, 'timed_out' => 0, 'failed' => 0, 'skipped' => 0];
            $pool->cleanupFinished($dummyStats);
        }
        $this->pruneRetiringPools();
    }

    /**
     * @return int
     */
    public function sumActiveWorkers(): int
    {
        $this->pruneRetiringPools();
        return deploy_drain_sum_active_workers($this->allTrackedPools());
    }

    /**
     * @return void
     */
    public function terminateAll(): void
    {
        foreach ($this->allTrackedPools() as $pool) {
            if (method_exists($pool, 'cleanup')) {
                $pool->cleanup();
            }
        }
        $this->retiringPools = [];
    }

    /**
     * @param int $now Unix time
     * @return void
     */
    public function runEnqueueTicks(int $now): void
    {
        foreach ($this->enqueueTicks as $tick) {
            $tick($now);
        }
    }

    /**
     * @param object $pool
     * @return void
     */
    private function retirePoolIfActive(object $pool): void
    {
        if (!method_exists($pool, 'getActiveCount')) {
            return;
        }
        $count = $pool->getActiveCount();
        if (!is_int($count) && !is_float($count)) {
            return;
        }
        if ((int) $count <= 0) {
            return;
        }
        $this->retiringPools[] = $pool;
    }

    /**
     * @return void
     */
    private function pruneRetiringPools(): void
    {
        if ($this->retiringPools === []) {
            return;
        }
        $kept = [];
        foreach ($this->retiringPools as $pool) {
            if (!method_exists($pool, 'getActiveCount')) {
                continue;
            }
            $count = $pool->getActiveCount();
            if ((is_int($count) || is_float($count)) && (int) $count > 0) {
                $kept[] = $pool;
            }
        }
        $this->retiringPools = $kept;
    }

    /**
     * @return list<object>
     */
    private function allTrackedPools(): array
    {
        return array_merge(array_values($this->pools), $this->retiringPools);
    }
}
