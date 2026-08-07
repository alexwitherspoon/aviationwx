<?php

/**
 * Unit tests for worker heartbeat stale detection (declared timeout).
 */

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../lib/config.php';
require_once __DIR__ . '/../../lib/constants.php';
require_once __DIR__ . '/../../lib/worker-timeout.php';

class WorkerTimeoutHeartbeatTest extends TestCase
{
    public function testDeclaredTimeout_UsesTimeoutPlusBuffer(): void
    {
        $timeout = (int) NASR_APT_WORKER_TIMEOUT;
        $this->assertSame(
            $timeout + 30,
            workerHeartbeatStaleAfterSeconds(['timeout' => $timeout], null, 120)
        );
    }

    public function testMissingDeclaredTimeout_UsesDefault(): void
    {
        $this->assertSame(
            120,
            workerHeartbeatStaleAfterSeconds(['pid' => 1], null, 120)
        );
    }

    public function testLongDeclaredTimeout_NotTreatedStaleAtWebcamDefault(): void
    {
        $age = getWorkerTimeout() + 30 + 30;
        $this->assertLessThan((int) NASR_APT_WORKER_TIMEOUT, $age);

        $id = 'test_' . bin2hex(random_bytes(4));
        $path = '/tmp/worker_heartbeat_' . $id . '.json';
        file_put_contents($path, json_encode([
            'pid' => 2147483000,
            'started' => time() - $age - 30,
            'heartbeat' => time() - $age,
            'timeout' => NASR_APT_WORKER_TIMEOUT,
        ]));

        try {
            $stuck = cleanupStaleWorkerHeartbeats();
            $this->assertNotContains(2147483000, $stuck);
            $this->assertFileExists($path);
        } finally {
            @unlink($path);
        }
    }
}
