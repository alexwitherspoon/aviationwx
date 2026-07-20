<?php

/**
 * Unit tests for NASR worker scheduling helpers.
 */

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../lib/cache-paths.php';
require_once __DIR__ . '/../../lib/nasr/workers.php';

class NasrWorkersTest extends TestCase
{
    public function testFrqWorkerWaitsForAptFetchLock(): void
    {
        $lockPath = getNasrAptFetchLockPath();
        $dir = dirname($lockPath);
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        $fp = fopen($lockPath, 'c+');
        $this->assertIsResource($fp);
        $this->assertTrue(flock($fp, LOCK_EX | LOCK_NB));

        try {
            $this->assertFalse(nasrFrqWorkerShouldRun());
        } finally {
            flock($fp, LOCK_UN);
            fclose($fp);
        }
    }

    public function testAptWorkerSkipsWhenLockHeld(): void
    {
        $lockPath = getNasrAptFetchLockPath();
        $dir = dirname($lockPath);
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        $fp = fopen($lockPath, 'c+');
        $this->assertIsResource($fp);
        $this->assertTrue(flock($fp, LOCK_EX | LOCK_NB));

        try {
            $this->assertFalse(nasrAptWorkerShouldRun());
        } finally {
            flock($fp, LOCK_UN);
            fclose($fp);
        }
    }
}
