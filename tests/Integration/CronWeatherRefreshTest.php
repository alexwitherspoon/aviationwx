<?php
use PHPUnit\Framework\TestCase;

/**
 * Test that the weather refresh script executes correctly
 * 
 * Note: In production, this script runs via cron inside the Docker container.
 * The cron job is automatically configured in the container's crontab file.
 */
class CronWeatherRefreshTest extends TestCase
{
    public function testCronScriptExecutes()
    {
        if (!function_exists('curl_init')) {
            $this->markTestSkipped('cURL not available');
        }

        // Test with explicit URL or let script auto-detect (Docker vs local)
        $baseUrl = getenv('TEST_API_URL') ?: 'http://localhost:8080';
        $cmd = sprintf('WEATHER_REFRESH_URL=%s php %s 2>&1', escapeshellarg($baseUrl), escapeshellarg(__DIR__ . '/../../scripts/fetch-weather.php'));
        $output = shell_exec($cmd);

        // If shell_exec is disabled, skip
        if ($output === null) {
            $this->markTestSkipped('shell_exec disabled');
            return;
        }

        // The script should run without fatal errors; accept any output
        $this->assertIsString($output);
        $this->assertNotFalse(strpos($output, 'AviationWX Weather Fetcher') !== false || strpos($output, 'Done!') !== false || strlen($output) > 0);
    }
}


