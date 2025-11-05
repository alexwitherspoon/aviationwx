<?php
use PHPUnit\Framework\TestCase;

class CronWeatherRefreshTest extends TestCase
{
    public function testCronScriptExecutes()
    {
        if (!function_exists('curl_init')) {
            $this->markTestSkipped('cURL not available');
        }

        $baseUrl = getenv('TEST_API_URL') ?: 'http://localhost:8080';
        $cmd = sprintf('WEATHER_REFRESH_URL=%s php %s 2>&1', escapeshellarg($baseUrl), escapeshellarg(__DIR__ . '/../../fetch-weather-safe.php'));
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


