<?php
/**
 * Per-worker spill journal writes JSONL lines and resets APCu counters (direct call; web uses shutdown hook).
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

    public function testWriteSpillSnapshot_AppendsJournalLineAndResetsApcu(): void
    {
        if (!function_exists('apcu_store') || !function_exists('apcu_enabled') || !@apcu_enabled()) {
            $this->markTestSkipped('APCu not available or disabled');
        }

        metrics_increment('global_page_views');
        metrics_increment('global_page_views');
        $this->assertSame(2, metrics_get('global_page_views'));

        $pid = getmypid();
        $this->assertTrue(metrics_write_spill_snapshot_and_reset_counters());

        $this->assertSame(0, metrics_get('global_page_views'));

        $pattern = getMetricsSpillRootDir() . '/*/' . $pid . '.jsonl';
        $files = glob($pattern) ?: [];
        $this->assertCount(1, $files, 'Expected exactly one worker journal for this PID');
        $path = $files[0];
        $this->assertFileExists($path);

        $raw = file_get_contents($path);
        $this->assertNotFalse($raw);
        $line = trim(explode("\n", $raw)[0]);
        $data = json_decode($line, true);
        $this->assertIsArray($data);
        $this->assertSame(METRICS_SPILL_FILE_SCHEMA_VERSION, $data['schema_version']);
        $this->assertIsString($data['hour_id'] ?? null);
        $this->assertSame($data['hour_id'], basename(dirname($path)));
        $this->assertSame(2, $data['counters']['global_page_views'] ?? null);

        @unlink($path);
        @rmdir(dirname($path));
    }

    public function testWriteSpillSnapshot_MultipleCallsAppendMultipleLines(): void
    {
        if (!function_exists('apcu_store') || !function_exists('apcu_enabled') || !@apcu_enabled()) {
            $this->markTestSkipped('APCu not available or disabled');
        }

        $pid = getmypid();
        metrics_increment('global_page_views');
        $this->assertTrue(metrics_write_spill_snapshot_and_reset_counters());
        metrics_increment('global_page_views');
        metrics_increment('global_page_views');
        $this->assertTrue(metrics_write_spill_snapshot_and_reset_counters());

        $pattern = getMetricsSpillRootDir() . '/*/' . $pid . '.jsonl';
        $files = glob($pattern) ?: [];
        $this->assertCount(1, $files);
        $raw = file_get_contents($files[0]);
        $this->assertNotFalse($raw);
        $this->assertSame(2, substr_count($raw, "\n"));

        @unlink($files[0]);
        @rmdir(dirname($files[0]));
    }
}
