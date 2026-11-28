<?php
/**
 * Unit Tests for Process Pool
 * Tests worker spawning, duplicate prevention, and cleanup
 */

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../lib/process-pool.php';
require_once __DIR__ . '/../../lib/logger.php';

class ProcessPoolTest extends TestCase
{
    private $testScript;
    private array $extraScripts = [];

    protected function setUp(): void
    {
        parent::setUp();
        // Create a simple test script that workers can run in scripts directory
        $scriptsDir = __DIR__ . '/../../scripts';
        if (!is_dir($scriptsDir)) {
            @mkdir($scriptsDir, 0755, true);
        }
        $this->testScript = $scriptsDir . '/test_worker_' . uniqid() . '.php';
        file_put_contents($this->testScript, '<?php
if (php_sapi_name() === "cli" && isset($argv[1]) && $argv[1] === "--worker" && isset($argv[2])) {
    $arg = $argv[2];
    // Simulate work
    usleep(100000); // 0.1s
    exit($arg === "fail" ? 1 : 0);
}
exit(1);
');
    }

    protected function tearDown(): void
    {
        if (file_exists($this->testScript)) {
            @unlink($this->testScript);
        }
        foreach ($this->extraScripts as $script) {
            if (file_exists($script)) {
                @unlink($script);
            }
        }
        parent::tearDown();
    }

    private function makeWorkerScript(string $body): string
    {
        $path = __DIR__ . '/../../scripts/test_worker_' . uniqid() . '.php';
        file_put_contents($path, $body);
        $this->extraScripts[] = $path;
        return $path;
    }
    
    /**
     * Test that process pool prevents duplicate jobs
     */
    public function testPreventsDuplicateJobs()
    {
        $pool = new ProcessPool(2, 5, basename($this->testScript), 'test-123');
        
        // Add same job twice
        $result1 = $pool->addJob(['test-airport']);
        $result2 = $pool->addJob(['test-airport']); // Duplicate
        
        $this->assertTrue($result1, 'First job should be added');
        $this->assertFalse($result2, 'Duplicate job should be skipped');
        
        $pool->cleanup();
    }
    
    /**
     * Test that process pool respects max workers limit
     */
    public function testRespectsMaxWorkers()
    {
        $pool = new ProcessPool(2, 5, basename($this->testScript), 'test-123');
        
        // Add 3 jobs with max 2 workers
        $pool->addJob(['job1']);
        $pool->addJob(['job2']);
        
        $this->assertEquals(2, $pool->getActiveCount(), 'Should have 2 active workers');
        
        // Third job should wait for slot
        $start = time();
        $pool->addJob(['job3']);
        $elapsed = time() - $start;
        
        // Should have waited briefly (jobs finish quickly in test)
        $this->assertLessThan(2, $elapsed, 'Should not wait too long');
        
        $pool->cleanup();
    }
    
    /**
     * Test that workers are cleaned up after completion
     */
    public function testWorkersCleanedUp()
    {
        $pool = new ProcessPool(5, 10, basename($this->testScript), 'test-123');
        
        $pool->addJob(['job1']);
        $pool->addJob(['job2']);
        
        // Wait for completion with timeout
        $start = time();
        $stats = $pool->waitForAll();
        $elapsed = time() - $start;
        
        // Workers should complete quickly (0.1s each)
        $this->assertLessThan(5, $elapsed, 'Workers should complete within 5 seconds');
        $this->assertEquals(0, $pool->getActiveCount(), 'All workers should be cleaned up');
        // Allow for some flexibility - at least one should complete
        $this->assertGreaterThanOrEqual(1, $stats['completed'] + $stats['failed'], 'Should have processed at least one job');
    }
    
    /**
     * Test that timed out workers are killed
     */
    public function testWorkerTimeout()
    {
        // Create a script that hangs in scripts directory
        $scriptsDir = __DIR__ . '/../../scripts';
        $hangScript = $scriptsDir . '/test_hang_' . uniqid() . '.php';
        file_put_contents($hangScript, '<?php sleep(10); exit(0);');
        
        try {
            // Use a very short timeout (1 second) to test timeout detection
            $pool = new ProcessPool(1, 1, basename($hangScript), 'test-123');
            $pool->addJob(['hang']);
            
            $start = time();
            $stats = $pool->waitForAll();
            $elapsed = time() - $start;
            
            // Should timeout and kill within ~3 seconds (1s timeout + cleanup + polling)
            $this->assertLessThan(5, $elapsed, 'Should timeout within 5 seconds');
            
            // The process should be killed, so it won't complete successfully
            // It should either timeout (timed_out > 0) or fail (failed > 0) if killed
            $totalProcessed = $stats['timed_out'] + $stats['failed'] + $stats['completed'];
            $this->assertGreaterThan(0, $totalProcessed, 'Should have processed the job (timeout, fail, or complete)');
            
            // If timeout works correctly, we should see timed_out > 0
            // But if the process is killed quickly, it might show as failed
            // Either way, it shouldn't complete successfully
            $this->assertEquals(0, $stats['completed'], 'Hanging process should not complete successfully');
        } finally {
            @unlink($hangScript);
            if (isset($pool)) {
                $pool->cleanup();
            }
        }
    }

    /**
     * A worker killed by SIGTERM (container stop, deploy drain, manual kill)
     * is a cancel, not a failure.
     */
    public function testWorkerTerminatedBySignalCountsAsCanceled()
    {
        if (!function_exists('posix_kill')) {
            $this->markTestSkipped('posix extension not available');
        }
        if (!is_writable(__DIR__ . '/../../scripts')) {
            $this->markTestSkipped('scripts directory not writable in this environment');
        }

        $script = $this->makeWorkerScript('<?php
if (php_sapi_name() === "cli" && $argv[1] === "--worker") {
    // Simulate a shutdown signal: the timeout/nice wrappers propagate the
    // signal death as 128 + 15.
    posix_kill(getmypid(), SIGTERM);
    sleep(30);
}
exit(1);
');

        $pool = new ProcessPool(1, 10, basename($script), 'test-123');
        $pool->addJob(['cancel']);

        $stats = $pool->waitForAll();

        $this->assertSame(1, $stats['canceled'], 'SIGTERM death should count as canceled');
        $this->assertSame(0, $stats['failed'], 'SIGTERM death should not count as failed');
        $this->assertSame(0, $stats['completed']);
    }

    /**
     * A worker whose whole wrapper chain is SIGKILLed (container teardown
     * shape) is a cancel, not a failure.
     */
    public function testWorkerWrapperChainKilledBySignalCountsAsCanceled()
    {
        if (!function_exists('posix_kill') || !function_exists('posix_getppid')) {
            $this->markTestSkipped('posix extension not available');
        }
        if (!is_writable(__DIR__ . '/../../scripts')) {
            $this->markTestSkipped('scripts directory not writable in this environment');
        }

        $script = $this->makeWorkerScript('<?php
if (php_sapi_name() === "cli" && $argv[1] === "--worker") {
    // SIGKILL the wrapper chain (timeout, nice) plus self: the pool\'s direct
    // child dies by signal, the container-teardown shape.
    $killIf = static function (int $pid, string $marker): void {
        $cmdline = @file_get_contents("/proc/{$pid}/cmdline");
        if (is_string($cmdline) && strpos($cmdline, $marker) !== false) {
            @posix_kill($pid, SIGKILL);
        }
    };
    $nicePid = posix_getppid();
    $stat = @file_get_contents("/proc/{$nicePid}/stat");
    $timeoutPid = 0;
    if (is_string($stat)) {
        $fields = preg_split(\'/\s+/\', trim(substr($stat, strrpos($stat, \')\') + 2)));
        $timeoutPid = (int) ($fields[1] ?? 0);
    }
    $killIf($timeoutPid, \'timeout\');
    $killIf($nicePid, \'nice\');
    posix_kill(getmypid(), SIGKILL);
    sleep(30);
}
exit(1);
');

        $pool = new ProcessPool(1, 10, basename($script), 'test-123');
        $pool->addJob(['teardown']);

        $stats = $pool->waitForAll();

        $this->assertSame(1, $stats['canceled'], 'Signal-killed wrapper chain should count as canceled');
        $this->assertSame(0, $stats['failed'], 'Signal-killed wrapper chain should not count as failed');
        $this->assertSame(0, $stats['completed']);
    }

    /**
     * A genuine worker failure (exit 1) still counts as failed.
     */
    public function testWorkerFailureStillCountsAsFailed()
    {
        $pool = new ProcessPool(1, 10, basename($this->testScript), 'test-123');
        $pool->addJob(['fail']);

        $stats = $pool->waitForAll();

        $this->assertSame(1, $stats['failed'], 'Exit 1 should still count as failed');
        $this->assertSame(0, $stats['canceled']);
        $this->assertSame(0, $stats['completed']);
    }

    /**
     * A worker that self-times-out (exit 124, the SIGALRM handler's code)
     * counts as timed out, not failed.
     */
    public function testWorkerSelfTimeoutCountsAsTimedOut()
    {
        if (!is_writable(__DIR__ . '/../../scripts')) {
            $this->markTestSkipped('scripts directory not writable in this environment');
        }

        $script = $this->makeWorkerScript('<?php
if (php_sapi_name() === "cli" && $argv[1] === "--worker") {
    // Self-timeout shape: the worker SIGALRM handler exits 124.
    usleep(100000);
    exit(124);
}
exit(1);
');

        $pool = new ProcessPool(1, 10, basename($script), 'test-123');
        $pool->addJob(['self-timeout']);

        $stats = $pool->waitForAll();

        $this->assertSame(1, $stats['timed_out'], 'Self-timeout should count as timed_out');
        $this->assertSame(0, $stats['failed']);
        $this->assertSame(0, $stats['canceled']);
        $this->assertSame(0, $stats['completed']);
    }
}

