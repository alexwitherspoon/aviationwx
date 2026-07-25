<?php
/**
 * Scheduler work registry for deploy-drain gating.
 *
 * ProcessPools (setPool) and enqueue ticks (registerEnqueueTick) register here.
 * Drain counts, force-terminate, and allow_new_work execution iterate the registry
 * so new workers are covered when registered.
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
        if ($pool === null) {
            unset($this->pools[$name]);
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
     * @return void
     */
    public function cleanupFinishedAll(): void
    {
        foreach ($this->pools as $pool) {
            if (!method_exists($pool, 'cleanupFinished')) {
                continue;
            }
            $dummyStats = ['completed' => 0, 'timed_out' => 0, 'failed' => 0, 'skipped' => 0];
            $pool->cleanupFinished($dummyStats);
        }
    }

    /**
     * @return int
     */
    public function sumActiveWorkers(): int
    {
        return deploy_drain_sum_active_workers(array_values($this->pools));
    }

    /**
     * @return void
     */
    public function terminateAll(): void
    {
        foreach ($this->pools as $pool) {
            if (method_exists($pool, 'cleanup')) {
                $pool->cleanup();
            }
        }
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
}
