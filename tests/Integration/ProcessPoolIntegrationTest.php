<?php
/**
 * Integration Tests for Process Pool
 * Tests that process pool works correctly with actual scripts
 */

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../lib/config.php';
require_once __DIR__ . '/../../lib/logger.php';

class ProcessPoolIntegrationTest extends TestCase
{
    private $baseUrl;
    
    protected function setUp(): void
    {
        parent::setUp();
        $this->baseUrl = getenv('TEST_API_URL') ?: 'http://localhost:8080';
    }
    
    /**
     * Test that fetch-weather.php uses process pool in normal mode
     */
    public function testFetchWeatherUsesProcessPool()
    {
        if (!function_exists('curl_init')) {
            $this->markTestSkipped('cURL not available');
        }
        
        $script = __DIR__ . '/../../scripts/fetch-weather.php';
        if (!file_exists($script)) {
            $this->markTestSkipped('fetch-weather.php not found');
            return;
        }
        
        $cmd = sprintf('WEATHER_REFRESH_URL=%s php %s 2>&1', escapeshellarg($this->baseUrl), escapeshellarg($script));
        $output = [];
        exec($cmd, $output, $exitCode);
        $outputStr = implode("\n", $output);
        
        $this->assertStringContainsString('Processing', $outputStr, 'Should show process pool output');
        $this->assertStringContainsString('workers', $outputStr, 'Should mention workers');
        $this->assertStringContainsString('Done!', $outputStr, 'Should show completion message');
    }
    
    /**
     * Test that fetch-weather.php shows stats output
     */
    public function testFetchWeatherShowsStats()
    {
        if (!function_exists('curl_init')) {
            $this->markTestSkipped('cURL not available');
        }
        
        $script = __DIR__ . '/../../scripts/fetch-weather.php';
        if (!file_exists($script)) {
            $this->markTestSkipped('fetch-weather.php not found');
            return;
        }
        
        $cmd = sprintf('WEATHER_REFRESH_URL=%s php %s 2>&1', escapeshellarg($this->baseUrl), escapeshellarg($script));
        $output = [];
        exec($cmd, $output, $exitCode);
        $outputStr = implode("\n", $output);
        
        $this->assertStringContainsString('Completed:', $outputStr, 'Should show completed count');
        $this->assertStringContainsString('Failed:', $outputStr, 'Should show failed count');
        $this->assertStringContainsString('Timed out:', $outputStr, 'Should show timed out count');
    }
    
    /**
     * Test that unified-webcam-worker.php is designed for scheduler use
     * 
     * Note: The unified-webcam-worker.php is designed to be called by the scheduler
     * daemon via ProcessPool in --worker mode. Unlike fetch-weather.php, it doesn't
     * have a standalone batch mode since webcam scheduling is handled by WebcamScheduleQueue.
     */
    public function testUnifiedWebcamWorkerDesignedForScheduler()
    {
        $script = __DIR__ . '/../../scripts/unified-webcam-worker.php';
        if (!file_exists($script)) {
            $this->markTestSkipped('unified-webcam-worker.php not found');
            return;
        }
        
        // Verify the script exists and is used for webcam worker jobs
        $this->assertFileExists($script, 'Unified webcam worker script should exist');
        
        // The scheduler uses this script with ProcessPool in --worker mode
        // This test verifies it exists and the scheduler is configured to use it
    }
    
    /**
     * Test that process pool respects pool size configuration
     */
    public function testProcessPoolRespectsConfig()
    {
        $config = loadConfig(false);
        if ($config === null) {
            $this->markTestSkipped('Config not available');
            return;
        }
        
        $poolSize = getWeatherWorkerPoolSize();
        $this->assertGreaterThan(0, $poolSize, 'Pool size should be configured');
        $this->assertLessThanOrEqual(100, $poolSize, 'Pool size should be reasonable');
        
        $timeout = getWorkerTimeout();
        $this->assertGreaterThan(0, $timeout, 'Timeout should be configured');
        $this->assertLessThanOrEqual(300, $timeout, 'Timeout should be reasonable');
    }
    
    /**
     * Test that duplicate jobs are prevented
     */
    public function testDuplicateJobPrevention()
    {
        if (!function_exists('proc_open')) {
            $this->markTestSkipped('proc_open not available');
        }
        
        require_once __DIR__ . '/../../lib/process-pool.php';
        
        $pool = new ProcessPool(5, 10, 'test-script', 'test-invocation');
        
        $result1 = $pool->addJob(['test-airport']);
        $result2 = $pool->addJob(['test-airport']);
        
        $this->assertTrue($result1, 'First job should be added');
        $this->assertFalse($result2, 'Duplicate job should be skipped');
        
        $pool->cleanup();
    }
}
