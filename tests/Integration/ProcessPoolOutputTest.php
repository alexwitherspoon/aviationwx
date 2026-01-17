<?php
/**
 * Integration Tests for Process Pool Output Format
 * Tests that output format is correct in both normal and worker modes
 */

use PHPUnit\Framework\TestCase;

class ProcessPoolOutputTest extends TestCase
{
    private $baseUrl;
    
    protected function setUp(): void
    {
        parent::setUp();
        $this->baseUrl = getenv('TEST_API_URL') ?: 'http://localhost:8080';
    }
    
    /**
     * Test that normal mode shows process pool header
     */
    public function testNormalModeShowsProcessPoolHeader()
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
        
        $this->assertStringContainsString('AviationWX Weather Fetcher', $outputStr, 'Should show header');
        $this->assertStringContainsString('Processing', $outputStr, 'Should show processing message');
        $this->assertStringContainsString('airports', $outputStr, 'Should mention airports');
        $this->assertStringContainsString('workers', $outputStr, 'Should mention workers');
    }
    
    /**
     * Test that normal mode shows completion stats
     */
    public function testNormalModeShowsStats()
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
        
        $this->assertStringContainsString('Done!', $outputStr, 'Should show done message');
        $this->assertMatchesRegularExpression('/Completed:\s*\d+/', $outputStr, 'Should show completed count');
        $this->assertMatchesRegularExpression('/Failed:\s*\d+/', $outputStr, 'Should show failed count');
        $this->assertMatchesRegularExpression('/Timed out:\s*\d+/', $outputStr, 'Should show timed out count');
    }
    
    /**
     * Test that worker mode doesn't show process pool output
     */
    public function testWorkerModeNoProcessPoolOutput()
    {
        $script = __DIR__ . '/../../scripts/fetch-weather.php';
        if (!file_exists($script)) {
            $this->markTestSkipped('fetch-weather.php not found');
            return;
        }
        
        $cmd = sprintf('php %s --worker invalid_airport 2>&1', escapeshellarg($script));
        $output = [];
        exec($cmd, $output, $exitCode);
        $outputStr = implode("\n", $output);
        
        $this->assertStringNotContainsString('Processing', $outputStr, 'Worker mode should not show processing message');
        $this->assertStringNotContainsString('workers', $outputStr, 'Worker mode should not mention workers');
        $this->assertStringNotContainsString('Done!', $outputStr, 'Worker mode should not show done message');
    }
    
    /**
     * Test that skipped count format is correct when shown
     */
    public function testSkippedCountFormat()
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
        
        if (strpos($outputStr, 'Skipped') !== false) {
            $this->assertMatchesRegularExpression('/Skipped.*\d+/', $outputStr, 'Should show skipped count if any');
        } else {
            $this->assertStringContainsString('Done!', $outputStr, 'Should show done message even if no skipped jobs');
        }
    }
    
    /**
     * Test that unified webcam worker requires worker mode
     * 
     * Note: The unified-webcam-worker.php is designed to be called by the scheduler
     * in --worker mode only. It doesn't have a standalone batch mode like fetch-weather.php.
     */
    public function testUnifiedWebcamWorkerRequiresWorkerMode()
    {
        $script = __DIR__ . '/../../scripts/unified-webcam-worker.php';
        if (!file_exists($script)) {
            $this->markTestSkipped('unified-webcam-worker.php not found');
            return;
        }
        
        // Running without --worker should error
        $cmd = sprintf('php %s 2>&1', escapeshellarg($script));
        $output = [];
        exec($cmd, $output, $exitCode);
        $outputStr = implode("\n", $output);
        
        // Should exit with error when not in worker mode
        $this->assertNotEquals(0, $exitCode, 'Should exit with error without --worker flag');
        $this->assertStringContainsString('worker mode', $outputStr, 'Should mention worker mode requirement');
    }
}
