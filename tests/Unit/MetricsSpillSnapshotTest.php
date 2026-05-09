<?php
/**
 * Per-worker spill snapshot writes JSON and resets APCu counters (direct call; web uses shutdown hook).
 */

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../lib/cache-paths.php';
require_once __DIR__ . '/../../lib/constants.php';
require_once __DIR__ . '/../../lib/metrics.php';

class MetricsSpillSnapshotTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        if (function_exists('apcu_clear_cache')) {
            @apcu_clear_cache();
        }
    }

    public function testWriteSpillSnapshot_PersistsCountersAndResetsApcu(): void
    {
        if (!function_exists('apcu_store') || !function_exists('apcu_enabled') || !@apcu_enabled()) {
            $this->markTestSkipped('APCu not available or disabled');
        }

        metrics_increment('global_page_views');
        metrics_increment('global_page_views');
        $this->assertSame(2, metrics_get('global_page_views'));

        $this->assertTrue(metrics_write_spill_snapshot_and_reset_counters());

        $this->assertSame(0, metrics_get('global_page_views'));

        $hourId = metrics_get_hour_id();
        $path = getMetricsSpillPathForWorker($hourId, getmypid());
        $this->assertFileExists($path);

        $raw = file_get_contents($path);
        $this->assertNotFalse($raw);
        $data = json_decode($raw, true);
        $this->assertIsArray($data);
        $this->assertSame(METRICS_SPILL_FILE_SCHEMA_VERSION, $data['schema_version']);
        $this->assertSame($hourId, $data['hour_id']);
        $this->assertSame(2, $data['counters']['global_page_views'] ?? null);

        @unlink($path);
        @rmdir(dirname($path));
    }
}
