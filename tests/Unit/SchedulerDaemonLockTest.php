<?php

declare(strict_types=1);

namespace AviationWx\Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Regression: scheduler daemon lock must not unlink paths; flock must exclude a second holder.
 */
final class SchedulerDaemonLockTest extends TestCase
{
    private string $lockPath;

    protected function setUp(): void
    {
        require_once __DIR__ . '/../../lib/scheduler-daemon-lock.php';
        $this->lockPath = sys_get_temp_dir() . '/aviationwx_sched_lock_test_' . bin2hex(random_bytes(6)) . '.lock';
    }

    protected function tearDown(): void
    {
        if (is_file($this->lockPath)) {
            @unlink($this->lockPath);
        }
    }

    public function testSchedulerLockAcquireExclusiveNb_SecondHolderGetsFlockReason(): void
    {
        $first = scheduler_lock_acquire_exclusive_nb($this->lockPath);
        $this->assertTrue($first['ok']);
        $this->assertIsResource($first['fp']);

        $second = scheduler_lock_acquire_exclusive_nb($this->lockPath);
        $this->assertFalse($second['ok']);
        $this->assertSame('flock', $second['reason']);

        fclose($first['fp']);

        $third = scheduler_lock_acquire_exclusive_nb($this->lockPath);
        $this->assertTrue($third['ok']);
        $this->assertIsResource($third['fp']);
        fclose($third['fp']);
    }

    public function testSchedulerLockAcquireExclusiveNb_FopenReasonWhenPathIsDirectory(): void
    {
        $dir = sys_get_temp_dir() . '/aviationwx_sched_lock_dir_' . bin2hex(random_bytes(4));
        $this->assertNotFalse(mkdir($dir, 0700, true));

        try {
            $result = scheduler_lock_acquire_exclusive_nb($dir);
            $this->assertFalse($result['ok']);
            $this->assertSame('fopen', $result['reason']);
        } finally {
            @rmdir($dir);
        }
    }
}
