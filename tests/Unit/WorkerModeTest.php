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

    /**
     * Test that maintenance airport worker exits 0 (success) or 2 (skip), never 1 (failure)
     * Maintenance failures are expected; we use exit 2 so process pool treats as skip, not error
     */
    public function testFetchWeatherWorkerMode_MaintenanceAirport_DoesNotEmitErrorExitCode(): void
    {
        $script = __DIR__ . '/../../scripts/fetch-weather.php';
        if (!file_exists($script)) {
            $this->markTestSkipped('fetch-weather.php not found');
            return;
        }

        $env = 'APP_ENV=testing CONFIG_PATH=' . escapeshellarg(__DIR__ . '/../Fixtures/airports.json.test');
        $baseUrl = getenv('TEST_API_URL') ?: 'http://localhost:8080';
        $cmd = sprintf('%s WEATHER_REFRESH_URL=%s php %s --worker pdx 2>/dev/null', $env, escapeshellarg($baseUrl), escapeshellarg($script));
        exec($cmd, $output, $exitCode);

        $this->assertNotEquals(1, $exitCode, 'Maintenance airport should not exit 1 (would emit process pool: worker failed)');
        $this->assertContains($exitCode, [0, 2], 'Maintenance airport should exit 0 (success) or 2 (skip)');
    }

    /**
     * Test that unlisted (commissioning) airport worker exits 0 (success) or 2 (skip), never 1 (failure)
     * Unlisted airports are often new; webcam/weather may not be functional yet
     */
    public function testFetchWeatherWorkerMode_UnlistedAirport_DoesNotEmitErrorExitCode(): void
    {
        $script = __DIR__ . '/../../scripts/fetch-weather.php';
        if (!file_exists($script)) {
            $this->markTestSkipped('fetch-weather.php not found');
            return;
        }

        $env = 'APP_ENV=testing CONFIG_PATH=' . escapeshellarg(__DIR__ . '/../Fixtures/airports.json.test');
        $baseUrl = getenv('TEST_API_URL') ?: 'http://localhost:8080';
        $cmd = sprintf('%s WEATHER_REFRESH_URL=%s php %s --worker test 2>/dev/null', $env, escapeshellarg($baseUrl), escapeshellarg($script));
        exec($cmd, $output, $exitCode);

        $this->assertNotEquals(1, $exitCode, 'Unlisted airport should not exit 1 (would emit process pool: worker failed)');
        $this->assertContains($exitCode, [0, 2], 'Unlisted airport should exit 0 (success) or 2 (skip)');
    }

    /**
     * fetch-station-power.php without --worker exits 2 with usage (manual refresh uses --worker like the scheduler).
     */
    public function testFetchStationPower_NoWorkerArgs_Exit2(): void
    {
        $script = __DIR__ . '/../../scripts/fetch-station-power.php';
        if (!file_exists($script)) {
            $this->markTestSkipped('fetch-station-power.php not found');
            return;
        }
        $cmd = sprintf('php %s 2>&1', escapeshellarg($script));
        exec($cmd, $output, $exitCode);
        $this->assertSame(2, $exitCode, 'No --worker should exit 2');
        $outputStr = implode("\n", $output);
        $this->assertStringContainsString('--worker', $outputStr);
    }

    /**
     * fetch-station-power.php --help exits 0
     */
    public function testFetchStationPower_Help_Exit0(): void
    {
        $script = __DIR__ . '/../../scripts/fetch-station-power.php';
        if (!file_exists($script)) {
            $this->markTestSkipped('fetch-station-power.php not found');
            return;
        }
        $cmd = sprintf('php %s --help 2>&1', escapeshellarg($script));
        exec($cmd, $output, $exitCode);
        $this->assertSame(0, $exitCode);
        $this->assertStringContainsString('--worker', implode("\n", $output));
    }
}
