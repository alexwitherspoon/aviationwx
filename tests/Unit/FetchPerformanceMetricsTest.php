<?php
/**
 * Unit Tests for Fetch Performance Metrics Worker
 *
 * Tests that fetch-performance-metrics.php produces valid cache files
 * in getCachedData format, and that getCachedData reads them correctly.
 */

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../lib/config.php';
require_once __DIR__ . '/../../lib/constants.php';
require_once __DIR__ . '/../../lib/cache-paths.php';
require_once __DIR__ . '/../../lib/cached-data-loader.php';
require_once __DIR__ . '/../../lib/performance-metrics.php';
require_once __DIR__ . '/../../lib/status-utils.php';

class FetchPerformanceMetricsTest extends TestCase
{
    private array $backupFiles = [];

    protected function setUp(): void
    {
        parent::setUp();

        foreach (
            [
                CACHE_NODE_PERFORMANCE_FILE,
                CACHE_IMAGE_PROCESSING_METRICS_FILE,
                CACHE_PAGE_RENDER_METRICS_FILE
            ] as $path
        ) {
            if (file_exists($path)) {
                $this->backupFiles[$path] = file_get_contents($path);
                @unlink($path);
            }
        }

        if (function_exists('apcu_delete')) {
            @apcu_delete('cached_status_node_performance');
            @apcu_delete('cached_status_image_processing');
            @apcu_delete('cached_status_page_render');
        }
    }

    protected function tearDown(): void
    {
        foreach ($this->backupFiles as $path => $content) {
            file_put_contents($path, $content);
        }
        foreach (
            [
                CACHE_NODE_PERFORMANCE_FILE,
                CACHE_IMAGE_PROCESSING_METRICS_FILE,
                CACHE_PAGE_RENDER_METRICS_FILE
            ] as $path
        ) {
            if (!isset($this->backupFiles[$path]) && file_exists($path)) {
                @unlink($path);
            }
        }
        parent::tearDown();
    }

    /**
     * Test that getCachedData reads pre-written performance cache files correctly
     */
    public function testGetCachedData_WithPerformanceCacheFile_ReturnsFileData(): void
    {
        $nodeData = [
            'cpu_load' => ['1min' => 1.5, '5min' => 2.0, '15min' => 2.5],
            'memory_used_bytes' => 300000000,
            'storage_used_bytes' => 5000000000
        ];
        $fileData = [
            'cached_at' => time(),
            'ttl' => PERFORMANCE_METRICS_CACHE_TTL,
            'key' => 'status_node_performance',
            'data' => $nodeData
        ];
        $cacheDir = dirname(CACHE_NODE_PERFORMANCE_FILE);
        if (!is_dir($cacheDir)) {
            @mkdir($cacheDir, 0755, true);
        }
        file_put_contents(CACHE_NODE_PERFORMANCE_FILE, json_encode($fileData, JSON_PRETTY_PRINT));

        $computeCount = 0;
        $result = getCachedData(
            function () use (&$computeCount) {
                $computeCount++;
                return [];
            },
            'status_node_performance',
            CACHE_NODE_PERFORMANCE_FILE,
            PERFORMANCE_METRICS_CACHE_TTL
        );

        $this->assertEquals(0, $computeCount, 'Should not compute when valid file exists');
        $this->assertEquals(1.5, $result['cpu_load']['1min'] ?? null);
        $this->assertEquals(300000000, $result['memory_used_bytes'] ?? null);
    }

    /**
     * Test that fetch-performance-metrics script produces valid cache file format
     */
    public function testFetchPerformanceMetrics_ScriptProducesValidCacheFormat(): void
    {
        $script = __DIR__ . '/../../scripts/fetch-performance-metrics.php';
        if (!file_exists($script)) {
            $this->markTestSkipped('fetch-performance-metrics.php not found');
            return;
        }

        $output = [];
        $exitCode = -1;
        exec('php ' . escapeshellarg($script) . ' 2>&1', $output, $exitCode);

        $this->assertEquals(0, $exitCode, 'Script should exit 0: ' . implode("\n", $output));

        $expectedFiles = [
            CACHE_NODE_PERFORMANCE_FILE => 'status_node_performance',
            CACHE_IMAGE_PROCESSING_METRICS_FILE => 'status_image_processing',
            CACHE_PAGE_RENDER_METRICS_FILE => 'status_page_render'
        ];

        foreach ($expectedFiles as $path => $expectedKey) {
            $this->assertFileExists($path, "Cache file should exist: $path");
            $content = file_get_contents($path);
            $decoded = json_decode($content, true);
            $this->assertIsArray($decoded, "Cache file should be valid JSON: $path");
            $this->assertArrayHasKey('cached_at', $decoded, "Should have cached_at: $path");
            $this->assertArrayHasKey('data', $decoded, "Should have data: $path");
            $this->assertArrayHasKey('ttl', $decoded, "Should have ttl: $path");
            $this->assertEquals($expectedKey, $decoded['key'] ?? '', "Key should match: $path");
        }

        $nodeContent = file_get_contents(CACHE_NODE_PERFORMANCE_FILE);
        $nodeDecoded = json_decode($nodeContent, true);
        $nodeData = $nodeDecoded['data'] ?? [];
        $this->assertArrayHasKey('cpu_load', $nodeData);
        $this->assertArrayHasKey('storage_used_bytes', $nodeData);
    }
}
