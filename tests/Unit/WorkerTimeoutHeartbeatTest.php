<?php

/**
 * Unit tests for worker heartbeat stale detection.
 */

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../lib/config.php';
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
        $this->assertSame(
            7230,
            workerHeartbeatStaleAfterSeconds(['timeout' => 7200], null, 120)
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
            workerHeartbeatStaleAfterSeconds(['timeout' => 7200], 60, 120)
        );
    }

    public function testCorruptHugeTimeout_IsCapped(): void
    {
        $this->assertSame(
            86400 + 30,
            workerHeartbeatStaleAfterSeconds(['timeout' => 999999999], null, 120)
        );
    }

    public function testLongDeclaredTimeout_NotTreatedStaleAtWebcamDefault(): void
    {
        $age = $this->agePastDefaultStaleThreshold();
        $path = $this->writeHeartbeat([
            'pid' => 2147483000,
            'started' => time() - $age - 30,
            'heartbeat' => time() - $age,
            'timeout' => 7200,
        ]);

        $stuck = cleanupStaleWorkerHeartbeats(null, $this->heartbeatGlob());
        $this->assertSame([], $stuck);
        $this->assertFileExists($path);
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
            'timeout' => 7200,
        ]);

        $stuck = cleanupStaleWorkerHeartbeats(60, $this->heartbeatGlob());
        $this->assertSame([], $stuck);
        $this->assertFileDoesNotExist($path);
    }

    /**
     * Heartbeat age past getWorkerTimeout()+30, but well below NASR APT's declared timeout.
     */
    private function agePastDefaultStaleThreshold(): int
    {
        $defaultStale = getWorkerTimeout() + 30;
        $age = $defaultStale + 30;
        $this->assertLessThan(7200, $age);

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
