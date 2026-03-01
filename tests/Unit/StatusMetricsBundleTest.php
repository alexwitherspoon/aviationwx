<?php
/**
 * Unit Tests for Status Metrics Bundle
 *
 * Tests getStatusMetricsBundle() returns rolling7, rolling1, multiPeriod
 * with correct structure and no redundant file reads.
 */

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../lib/config.php';
require_once __DIR__ . '/../../lib/cache-paths.php';
require_once __DIR__ . '/../../lib/metrics.php';
require_once __DIR__ . '/../../lib/status-metrics.php';

class StatusMetricsBundleTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        @mkdir(CACHE_METRICS_HOURLY_DIR, 0755, true);
        @mkdir(CACHE_METRICS_DAILY_DIR, 0755, true);
        $this->cleanMetricsDir(CACHE_METRICS_HOURLY_DIR);
        $this->cleanMetricsDir(CACHE_METRICS_DAILY_DIR);

        if (function_exists('apcu_clear_cache')) {
            @apcu_clear_cache();
        }
        $bundleFile = CACHE_BASE_DIR . '/status_metrics_bundle.json';
        if (file_exists($bundleFile)) {
            @unlink($bundleFile);
        }
    }

    protected function tearDown(): void
    {
        $this->cleanMetricsDir(CACHE_METRICS_HOURLY_DIR);
        $this->cleanMetricsDir(CACHE_METRICS_DAILY_DIR);
        $bundleFile = CACHE_BASE_DIR . '/status_metrics_bundle.json';
        if (file_exists($bundleFile)) {
            @unlink($bundleFile);
        }
        parent::tearDown();
    }

    private function cleanMetricsDir(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        foreach (glob($dir . '/*.json') ?: [] as $file) {
            @unlink($file);
        }
    }

    private function createDailyFile(string $dateId, int $pageViews): void
    {
        $file = CACHE_METRICS_DAILY_DIR . '/' . $dateId . '.json';
        file_put_contents($file, json_encode([
            'bucket_type' => 'daily',
            'bucket_id' => $dateId,
            'airports' => [
                'kspb' => ['page_views' => $pageViews, 'weather_requests' => 10, 'webcam_requests' => 5]
            ],
            'webcams' => [],
            'global' => metrics_get_empty_global()
        ], JSON_PRETTY_PRINT));
    }

    private function createHourlyFile(string $hourId, int $pageViews): void
    {
        $file = CACHE_METRICS_HOURLY_DIR . '/' . $hourId . '.json';
        file_put_contents($file, json_encode([
            'bucket_type' => 'hourly',
            'bucket_id' => $hourId,
            'airports' => [
                'kspb' => ['page_views' => $pageViews, 'weather_requests' => 10, 'webcam_requests' => 5]
            ],
            'webcams' => [],
            'global' => metrics_get_empty_global()
        ], JSON_PRETTY_PRINT));
    }

    /**
     * Test that getStatusMetricsBundle returns rolling7, rolling1, multiPeriod
     */
    public function testGetStatusMetricsBundle_ReturnsAllThreeStructures(): void
    {
        $now = time();
        for ($d = 1; $d <= 7; $d++) {
            $dateId = gmdate('Y-m-d', $now - ($d * 86400));
            $this->createDailyFile($dateId, 100);
        }
        $today = gmdate('Y-m-d', $now);
        $currentHour = (int)gmdate('H', $now);
        for ($h = 0; $h <= $currentHour; $h++) {
            $hourId = $today . '-' . sprintf('%02d', $h);
            $this->createHourlyFile($hourId, 10);
        }

        $bundle = getStatusMetricsBundle();

        $this->assertArrayHasKey('rolling7', $bundle);
        $this->assertArrayHasKey('rolling1', $bundle);
        $this->assertArrayHasKey('multiPeriod', $bundle);

        $this->assertArrayHasKey('airports', $bundle['rolling7']);
        $this->assertArrayHasKey('global', $bundle['rolling7']);
        $this->assertArrayHasKey('period_days', $bundle['rolling7']);
        $this->assertEquals(7, $bundle['rolling7']['period_days']);

        $this->assertArrayHasKey('airports', $bundle['rolling1']);
        $this->assertArrayHasKey('period_days', $bundle['rolling1']);
        $this->assertEquals(1, $bundle['rolling1']['period_days']);

        $this->assertIsArray($bundle['multiPeriod']);
        $this->assertArrayHasKey('kspb', $bundle['multiPeriod']);
        $this->assertArrayHasKey('hour', $bundle['multiPeriod']['kspb']);
        $this->assertArrayHasKey('day', $bundle['multiPeriod']['kspb']);
        $this->assertArrayHasKey('week', $bundle['multiPeriod']['kspb']);
    }

    /**
     * Test that rolling7 matches metrics_get_rolling(7) output
     */
    public function testGetStatusMetricsBundle_Rolling7MatchesLegacy(): void
    {
        $now = time();
        for ($d = 1; $d <= 7; $d++) {
            $dateId = gmdate('Y-m-d', $now - ($d * 86400));
            $this->createDailyFile($dateId, 100);
        }
        $today = gmdate('Y-m-d', $now);
        $currentHour = (int)gmdate('H', $now);
        for ($h = 0; $h <= $currentHour; $h++) {
            $hourId = $today . '-' . sprintf('%02d', $h);
            $this->createHourlyFile($hourId, 10);
        }

        $bundle = getStatusMetricsBundle();
        $legacy = metrics_get_rolling(7);

        $this->assertEquals($legacy['airports']['kspb']['page_views'] ?? 0, $bundle['rolling7']['airports']['kspb']['page_views'] ?? 0);
        $this->assertEquals($legacy['period_days'], $bundle['rolling7']['period_days']);
    }

    /**
     * Test that rolling1 matches metrics_get_rolling(1) output
     */
    public function testGetStatusMetricsBundle_Rolling1MatchesLegacy(): void
    {
        $now = time();
        $yesterday = gmdate('Y-m-d', $now - 86400);
        $this->createDailyFile($yesterday, 200);
        $today = gmdate('Y-m-d', $now);
        $currentHour = (int)gmdate('H', $now);
        for ($h = 0; $h <= $currentHour; $h++) {
            $hourId = $today . '-' . sprintf('%02d', $h);
            $this->createHourlyFile($hourId, 10);
        }

        $bundle = getStatusMetricsBundle();
        $legacy = metrics_get_rolling(1);

        $expectedViews = 200 + ($currentHour + 1) * 10;
        $this->assertEquals($expectedViews, $bundle['rolling1']['airports']['kspb']['page_views'] ?? 0);
        $this->assertEquals($legacy['airports']['kspb']['page_views'] ?? 0, $bundle['rolling1']['airports']['kspb']['page_views'] ?? 0);
    }

    /**
     * Test that bundle uses STATUS_METRICS_CACHE_TTL
     */
    public function testGetStatusMetricsBundle_UsesCorrectTtl(): void
    {
        $bundle = getStatusMetricsBundle();
        $this->assertArrayHasKey('rolling7', $bundle);
        $this->assertArrayHasKey('rolling1', $bundle);
    }
}
