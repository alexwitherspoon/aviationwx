<?php
/**
 * Unit Tests for Fetch Status Metrics Worker
 *
 * Tests that fetch-status-metrics.php produces valid cache file
 * in getCachedData format.
 */

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../lib/config.php';
require_once __DIR__ . '/../../lib/constants.php';
require_once __DIR__ . '/../../lib/cache-paths.php';

class FetchStatusMetricsTest extends TestCase
{
    private ?string $backupContent = null;

    protected function setUp(): void
    {
        parent::setUp();
        if (file_exists(CACHE_STATUS_METRICS_BUNDLE_FILE)) {
            $this->backupContent = file_get_contents(CACHE_STATUS_METRICS_BUNDLE_FILE);
            @unlink(CACHE_STATUS_METRICS_BUNDLE_FILE);
        }
    }

    protected function tearDown(): void
    {
        if ($this->backupContent !== null) {
            file_put_contents(CACHE_STATUS_METRICS_BUNDLE_FILE, $this->backupContent);
        } elseif (file_exists(CACHE_STATUS_METRICS_BUNDLE_FILE)) {
            @unlink(CACHE_STATUS_METRICS_BUNDLE_FILE);
        }
        parent::tearDown();
    }

    /**
     * Test that fetch-status-metrics script produces valid cache file format
     */
    public function testFetchStatusMetrics_ScriptProducesValidCacheFormat(): void
    {
        $script = __DIR__ . '/../../scripts/fetch-status-metrics.php';
        if (!file_exists($script)) {
            $this->markTestSkipped('fetch-status-metrics.php not found');
            return;
        }

        $output = [];
        $exitCode = -1;
        exec('php ' . escapeshellarg($script) . ' 2>&1', $output, $exitCode);

        $this->assertEquals(0, $exitCode, 'Script should exit 0: ' . implode("\n", $output));
        $this->assertFileExists(CACHE_STATUS_METRICS_BUNDLE_FILE);

        $content = file_get_contents(CACHE_STATUS_METRICS_BUNDLE_FILE);
        $decoded = json_decode($content, true);
        $this->assertIsArray($decoded);
        $this->assertArrayHasKey('cached_at', $decoded);
        $this->assertArrayHasKey('data', $decoded);
        $this->assertArrayHasKey('ttl', $decoded);
        $this->assertEquals('status_metrics_bundle', $decoded['key'] ?? '');

        $data = $decoded['data'] ?? [];
        $this->assertArrayHasKey('rolling7', $data);
        $this->assertArrayHasKey('rolling1', $data);
        $this->assertArrayHasKey('today', $data);
    }
}
