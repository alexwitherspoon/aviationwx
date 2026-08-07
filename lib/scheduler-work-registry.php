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
 *
 * Pool class (live vs reference) controls drain force timing: live uses the short
 * CD window; reference waits up to DEPLOY_WORKER_DRAIN_REFERENCE_MAX_SECONDS.
 */

declare(strict_types=1);

require_once __DIR__ . '/deploy-drain.php';
require_once __DIR__ . '/reference-data/jobs.php';

/**
 * Named ProcessPool + enqueue-tick registry for the scheduler daemon.
 */
final class SchedulerWorkRegistry
{
    /** @var array<string, object> getActiveCount / cleanupFinished / cleanup */
    private array $pools = [];

    /** @var array<string, string> Pool name => SCHEDULER_POOL_CLASS_* */
    private array $poolClasses = [];

    /** @var list<array{pool: object, class: string}> Former pools still finishing children */
    private array $retiringPools = [];

    /** @var array<string, callable(int):void> */
    private array $enqueueTicks = [];

    /**
     * Register or replace a ProcessPool. Null removes the name.
     *
     * @param string $name Stable worker family name (weather, nasr_apt, ...)
     * @param object|null $pool Pool instance or null to unregister
     * @param string $class SCHEDULER_POOL_CLASS_LIVE or SCHEDULER_POOL_CLASS_REFERENCE
     * @return void
     */
    public function setPool(string $name, ?object $pool, string $class = SCHEDULER_POOL_CLASS_LIVE): void
    {
        $name = trim($name);
        if ($name === '') {
            return;
        }

        $class = $class === SCHEDULER_POOL_CLASS_REFERENCE
            ? SCHEDULER_POOL_CLASS_REFERENCE
            : SCHEDULER_POOL_CLASS_LIVE;

        if (isset($this->pools[$name])) {
            $previous = $this->pools[$name];
            if ($pool !== $previous) {
                $this->retirePoolIfActive($previous, $this->poolClasses[$name] ?? SCHEDULER_POOL_CLASS_LIVE);
            }
            unset($this->pools[$name], $this->poolClasses[$name]);
        }

        if ($pool === null) {
            return;
        }
        $this->pools[$name] = $pool;
        $this->poolClasses[$name] = $class;
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
     * @return array<string, string>
     */
    public function registeredPoolClasses(): array
    {
        return $this->poolClasses;
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
        foreach ($this->allTrackedPoolEntries() as $entry) {
            $pool = $entry['pool'];
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
        $byClass = $this->sumActiveWorkersByClass();
        return $byClass['live'] + $byClass['reference'];
    }

    /**
     * @return array{live: int, reference: int}
     */
    public function sumActiveWorkersByClass(): array
    {
        $this->pruneRetiringPools();
        $live = 0;
        $reference = 0;
        foreach ($this->allTrackedPoolEntries() as $entry) {
            $pool = $entry['pool'];
            if (!method_exists($pool, 'getActiveCount')) {
                continue;
            }
            $count = $pool->getActiveCount();
            if (!is_int($count) && !is_float($count)) {
                continue;
            }
            $n = max(0, (int) $count);
            if ($entry['class'] === SCHEDULER_POOL_CLASS_REFERENCE) {
                $reference += $n;
            } else {
                $live += $n;
            }
        }

        return ['live' => $live, 'reference' => $reference];
    }

    /**
     * @return void
     */
    public function terminateAll(): void
    {
        foreach ($this->allTrackedPoolEntries() as $entry) {
            $pool = $entry['pool'];
            if (method_exists($pool, 'cleanup')) {
                $pool->cleanup();
            }
        }
        $this->retiringPools = [];
    }

    /**
     * Force-terminate pools in one drain class only.
     *
     * @param string $class SCHEDULER_POOL_CLASS_LIVE or SCHEDULER_POOL_CLASS_REFERENCE
     * @return void
     */
    public function terminatePoolsByClass(string $class): void
    {
        $class = $class === SCHEDULER_POOL_CLASS_REFERENCE
            ? SCHEDULER_POOL_CLASS_REFERENCE
            : SCHEDULER_POOL_CLASS_LIVE;

        foreach ($this->pools as $name => $pool) {
            if (($this->poolClasses[$name] ?? SCHEDULER_POOL_CLASS_LIVE) !== $class) {
                continue;
            }
            if (method_exists($pool, 'cleanup')) {
                $pool->cleanup();
            }
        }

        $kept = [];
        foreach ($this->retiringPools as $entry) {
            if ($entry['class'] === $class) {
                if (method_exists($entry['pool'], 'cleanup')) {
                    $entry['pool']->cleanup();
                }
                continue;
            }
            $kept[] = $entry;
        }
        $this->retiringPools = $kept;
        $this->pruneRetiringPools();
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
     * @param string $class
     * @return void
     */
    private function retirePoolIfActive(object $pool, string $class): void
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
        $this->retiringPools[] = ['pool' => $pool, 'class' => $class];
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
        foreach ($this->retiringPools as $entry) {
            $pool = $entry['pool'];
            if (!method_exists($pool, 'getActiveCount')) {
                continue;
            }
            $count = $pool->getActiveCount();
            if ((is_int($count) || is_float($count)) && (int) $count > 0) {
                $kept[] = $entry;
            }
        }
        $this->retiringPools = $kept;
    }

    /**
     * @return list<array{pool: object, class: string}>
     */
    private function allTrackedPoolEntries(): array
    {
        $entries = [];
        foreach ($this->pools as $name => $pool) {
            $entries[] = [
                'pool' => $pool,
                'class' => $this->poolClasses[$name] ?? SCHEDULER_POOL_CLASS_LIVE,
            ];
        }

        return array_merge($entries, $this->retiringPools);
    }
}
