<?php
/**
 * Unit Tests for Fetch Status Health Worker
 *
 * Tests that fetch-status-health.php produces valid cache files
 * in getCachedData format.
 */

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../lib/config.php';
require_once __DIR__ . '/../../lib/constants.php';
require_once __DIR__ . '/../../lib/cache-paths.php';
require_once __DIR__ . '/../../lib/cached-data-loader.php';

class FetchStatusHealthTest extends TestCase
{
    private array $backupFiles = [];

    protected function setUp(): void
    {
        parent::setUp();
        foreach (
            [CACHE_SYSTEM_HEALTH_FILE, CACHE_AIRPORT_HEALTH_FILE, CACHE_PUBLIC_API_HEALTH_FILE]
            as $path
        ) {
            if (file_exists($path)) {
                $this->backupFiles[$path] = file_get_contents($path);
                @unlink($path);
            }
        }
    }

    protected function tearDown(): void
    {
        foreach ($this->backupFiles as $path => $content) {
            file_put_contents($path, $content);
        }
        foreach ([CACHE_SYSTEM_HEALTH_FILE, CACHE_AIRPORT_HEALTH_FILE, CACHE_PUBLIC_API_HEALTH_FILE] as $path) {
            if (!isset($this->backupFiles[$path]) && file_exists($path)) {
                @unlink($path);
            }
        }
        parent::tearDown();
    }

    /**
     * Test that fetch-status-health script produces valid cache file format
     */
    public function testFetchStatusHealth_ScriptProducesValidCacheFormat(): void
    {
        $script = __DIR__ . '/../../scripts/fetch-status-health.php';
        if (!file_exists($script)) {
            $this->markTestSkipped('fetch-status-health.php not found');
            return;
        }

        $output = [];
        $exitCode = -1;
        exec('php ' . escapeshellarg($script) . ' 2>&1', $output, $exitCode);

        $this->assertEquals(0, $exitCode, 'Script should exit 0: ' . implode("\n", $output));

        $this->assertFileExists(CACHE_SYSTEM_HEALTH_FILE);
        $this->assertFileExists(CACHE_AIRPORT_HEALTH_FILE);

        $systemContent = file_get_contents(CACHE_SYSTEM_HEALTH_FILE);
        $systemDecoded = json_decode($systemContent, true);
        $this->assertIsArray($systemDecoded);
        $this->assertArrayHasKey('cached_at', $systemDecoded);
        $this->assertArrayHasKey('data', $systemDecoded);
        $systemData = $systemDecoded['data'] ?? [];
        $this->assertArrayHasKey('components', $systemData);

        $airportContent = file_get_contents(CACHE_AIRPORT_HEALTH_FILE);
        $airportDecoded = json_decode($airportContent, true);
        $this->assertIsArray($airportDecoded);
        $this->assertArrayHasKey('data', $airportDecoded);
        $this->assertIsArray($airportDecoded['data']);
    }
}
