<?php

/**
 * Unit tests for worker heartbeat stale detection.
 */

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../lib/config.php';
require_once __DIR__ . '/../../lib/constants.php';
require_once __DIR__ . '/../../lib/worker-timeout.php';

class WorkerTimeoutHeartbeatTest extends TestCase
{
    /** @var list<string> */
    private array $heartbeatFiles = [];

    private string $heartbeatIdPrefix = '';

    protected function setUp(): void
    {
        parent::setUp();
        $this->heartbeatIdPrefix = 'test_' . bin2hex(random_bytes(4));
    }

    protected function tearDown(): void
    {
        foreach ($this->heartbeatFiles as $path) {
            if (is_file($path)) {
                @unlink($path);
            }
        }
        $this->heartbeatFiles = [];
        parent::tearDown();
    }

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

    public function testOverride_WinsOverDeclaredTimeout(): void
    {
        $this->assertSame(
            60,
            workerHeartbeatStaleAfterSeconds(['timeout' => NASR_APT_WORKER_TIMEOUT], 60, 120)
        );
    }

    public function testCorruptHugeTimeout_IsCapped(): void
    {
        $this->assertSame(
            86400 + 30,
            workerHeartbeatStaleAfterSeconds(['timeout' => 999999999], null, 120)
        );
        $this->assertSame(
            86400 + 30,
            workerHeartbeatStaleAfterSeconds(['timeout' => PHP_INT_MAX], null, 120)
        );
    }

    public function testGlobAllowsDefaultAndTestScopedPatterns(): void
    {
        $this->assertTrue(workerHeartbeatGlobIsAllowed('/tmp/worker_heartbeat_*.json'));
        $this->assertTrue(workerHeartbeatGlobIsAllowed($this->heartbeatGlob()));
        $this->assertTrue(workerHeartbeatGlobIsAllowed('/tmp/worker_heartbeat_nasr_apt.json'));
        $this->assertFalse(workerHeartbeatGlobIsAllowed('/tmp/other_*.json'));
        $this->assertFalse(workerHeartbeatGlobIsAllowed('/etc/passwd'));
        $this->assertFalse(workerHeartbeatGlobIsAllowed('/tmp/../etc/passwd'));
    }

    public function testLongDeclaredTimeout_NotTreatedStaleAtWebcamDefault(): void
    {
        $age = $this->agePastDefaultStaleThreshold();
        $path = $this->writeHeartbeat([
            'pid' => 2147483000,
            'started' => time() - $age - 30,
            'heartbeat' => time() - $age,
            'timeout' => NASR_APT_WORKER_TIMEOUT,
        ]);

        $stuck = cleanupStaleWorkerHeartbeats(null, $this->heartbeatGlob());
        $this->assertSame([], $stuck);
        $this->assertFileExists($path);
    }

    public function testPastDeclaredTimeout_RemovesHeartbeatWithoutLivePid(): void
    {
        $timeout = (int) NASR_APT_WORKER_TIMEOUT;
        $age = $timeout + 30 + 5;
        $path = $this->writeHeartbeat([
            'pid' => 2147483003,
            'started' => time() - $age - 30,
            'heartbeat' => time() - $age,
            'timeout' => $timeout,
        ]);

        $stuck = cleanupStaleWorkerHeartbeats(null, $this->heartbeatGlob());
        $this->assertSame([], $stuck, 'Synthetic PID must not match a live php process');
        $this->assertFileDoesNotExist($path);
    }

    public function testDefaultTimeout_RemovesStaleHeartbeatWithoutDeclaredTimeout(): void
    {
        $age = $this->agePastDefaultStaleThreshold();
        $path = $this->writeHeartbeat([
            'pid' => 2147483001,
            'started' => time() - $age - 30,
            'heartbeat' => time() - $age,
        ]);

        $stuck = cleanupStaleWorkerHeartbeats(null, $this->heartbeatGlob());
        $this->assertSame([], $stuck, 'Synthetic PID must not match a live php process');
        $this->assertFileDoesNotExist($path);
    }

    public function testExplicitStaleOverride_AppliesToLongTimeoutWorker(): void
    {
        $path = $this->writeHeartbeat([
            'pid' => 2147483002,
            'started' => time() - 180,
            'heartbeat' => time() - 150,
            'timeout' => NASR_APT_WORKER_TIMEOUT,
        ]);

        $stuck = cleanupStaleWorkerHeartbeats(60, $this->heartbeatGlob());
        $this->assertSame([], $stuck);
        $this->assertFileDoesNotExist($path);
    }

    public function testRejectedGlob_DoesNotTouchHeartbeatFiles(): void
    {
        $path = $this->writeHeartbeat([
            'pid' => 2147483004,
            'started' => time() - 200,
            'heartbeat' => time() - 200,
        ]);

        $stuck = cleanupStaleWorkerHeartbeats(1, '/tmp/not_worker_heartbeat_*.json');
        $this->assertSame([], $stuck);
        $this->assertFileExists($path);
    }

    public function testInitWorkerTimeout_WritesTimeoutUsedByStalePolicy(): void
    {
        $safeId = $this->heartbeatIdPrefix . '_contract';
        try {
            initWorkerTimeout(600, $safeId);
            $path = '/tmp/worker_heartbeat_' . $safeId . '.json';
            $this->heartbeatFiles[] = $path;

            $this->assertFileExists($path);
            $data = json_decode((string) file_get_contents($path), true);
            $this->assertIsArray($data);
            $this->assertSame(600, $data['timeout'] ?? null);
            $this->assertSame(
                630,
                workerHeartbeatStaleAfterSeconds($data, null, getWorkerTimeout() + 30)
            );
        } finally {
            if (function_exists('pcntl_alarm')) {
                pcntl_alarm(0);
            }
        }
    }

    /**
     * Silence past getWorkerTimeout()+30, still well below NASR APT declared timeout.
     */
    private function agePastDefaultStaleThreshold(): int
    {
        $defaultStale = getWorkerTimeout() + 30;
        $age = $defaultStale + 30;
        $this->assertLessThan((int) NASR_APT_WORKER_TIMEOUT, $age);

        return $age;
    }

    private function heartbeatGlob(): string
    {
        return '/tmp/worker_heartbeat_' . $this->heartbeatIdPrefix . '_*.json';
    }

    /**
     * @param array<string, mixed> $data
     */
    private function writeHeartbeat(array $data): string
    {
        $id = bin2hex(random_bytes(4));
        $path = '/tmp/worker_heartbeat_' . $this->heartbeatIdPrefix . '_' . $id . '.json';
        file_put_contents($path, json_encode($data, JSON_UNESCAPED_SLASHES));
        $this->heartbeatFiles[] = $path;

        return $path;
    }
}
