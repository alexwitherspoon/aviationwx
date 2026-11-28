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

    /**
     * A worker that receives SIGTERM traps it, exits 143 promptly, so the
     * process pool classifies the death as a cancel.
     */
    public function testWorkerTrapsSigtermAndExits143(): void
    {
        if (!function_exists('pcntl_signal') || !function_exists('posix_kill')) {
            $this->markTestSkipped('pcntl/posix not available');
        }

        $script = sys_get_temp_dir() . '/worker_signal_' . bin2hex(random_bytes(6)) . '.php';
        file_put_contents($script, '<?php
require_once ' . var_export(dirname(__DIR__, 2) . '/lib/worker-timeout.php', true) . ';
initWorkerTimeout(30, null);
while (true) {
    usleep(100000);
}
');

        $pipes = [];
        $proc = proc_open([PHP_BINARY, $script], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        $this->assertIsResource($proc, 'worker process should start');

        try {
            // Give initWorkerTimeout a moment to install the handlers
            usleep(300000);

            $pid = proc_get_status($proc)['pid'] ?? 0;
            $this->assertGreaterThan(0, $pid);

            $start = microtime(true);
            $this->assertTrue(posix_kill($pid, SIGTERM));
            while (proc_get_status($proc)['running'] && (microtime(true) - $start) < 10) {
                usleep(100000);
            }
            $elapsed = microtime(true) - $start;

            $status = proc_get_status($proc);
            $this->assertFalse($status['running'], 'worker should exit after SIGTERM');
            $this->assertSame(143, $status['exitcode'], 'trapped SIGTERM should exit 143');
            $this->assertLessThan(5.0, $elapsed, 'trap should fire promptly, not on the next opcode boundary of a long block');
        } finally {
            if (is_resource($proc)) {
                proc_close($proc);
            }
            @unlink($script);
        }
    }
}
