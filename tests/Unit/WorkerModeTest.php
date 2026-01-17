<?php
/**
 * Unit Tests for Worker Mode
 * Tests that scripts correctly detect and handle --worker argument
 */

use PHPUnit\Framework\TestCase;

class WorkerModeTest extends TestCase
{
    /**
     * Test that fetch-weather.php detects worker mode and exits with error for invalid airport
     */
    public function testFetchWeatherWorkerMode_InvalidAirport()
    {
        $script = __DIR__ . '/../../scripts/fetch-weather.php';
        
        if (!file_exists($script)) {
            $this->markTestSkipped('fetch-weather.php not found');
            return;
        }
        
        $cmd = sprintf('php %s --worker invalid_airport 2>&1', escapeshellarg($script));
        $output = [];
        $exitCode = 0;
        exec($cmd, $output, $exitCode);
        
        $this->assertNotEquals(0, $exitCode, 'Should exit with error for invalid airport');
        // The script logs errors to log file, not stdout/stderr, so we just verify exit code
        // The error "airport not found" is logged via aviationwx_log() which writes to log file
    }
    
    /**
     * Test that fetch-weather.php worker mode doesn't show process pool output
     */
    public function testFetchWeatherWorkerMode_NoProcessPoolOutput()
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
        
        $this->assertStringNotContainsString('Processing', $outputStr, 'Worker mode should not show process pool output');
        $this->assertStringNotContainsString('workers', $outputStr, 'Worker mode should not show workers output');
    }
    
    /**
     * Test that unified-webcam-worker.php detects worker mode and exits with error for invalid airport
     */
    public function testUnifiedWebcamWorkerMode_InvalidAirport()
    {
        $script = __DIR__ . '/../../scripts/unified-webcam-worker.php';
        
        if (!file_exists($script)) {
            $this->markTestSkipped('unified-webcam-worker.php not found');
            return;
        }
        
        $cmd = sprintf('php %s --worker invalid_airport 0 2>&1', escapeshellarg($script));
        $output = [];
        $exitCode = 0;
        exec($cmd, $output, $exitCode);
        
        $this->assertNotEquals(0, $exitCode, 'Should exit with error for invalid airport');
    }
    
    /**
     * Test that unified-webcam-worker.php worker mode doesn't show process pool output
     */
    public function testUnifiedWebcamWorkerMode_NoProcessPoolOutput()
    {
        $script = __DIR__ . '/../../scripts/unified-webcam-worker.php';
        
        if (!file_exists($script)) {
            $this->markTestSkipped('unified-webcam-worker.php not found');
            return;
        }
        
        $cmd = sprintf('php %s --worker invalid_airport 0 2>&1', escapeshellarg($script));
        $output = [];
        exec($cmd, $output, $exitCode);
        $outputStr = implode("\n", $output);
        
        $this->assertStringNotContainsString('Processing', $outputStr, 'Worker mode should not show process pool output');
        $this->assertStringNotContainsString('workers', $outputStr, 'Worker mode should not show workers output');
    }
}
