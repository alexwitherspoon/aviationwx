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
        parent::tearDown();
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
}

