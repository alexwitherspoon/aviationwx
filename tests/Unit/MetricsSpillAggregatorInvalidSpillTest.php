<?php
/**
 * Invalid spill shards are skipped and left in place (operators can inspect bad JSON).
 */

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../lib/cache-paths.php';
require_once __DIR__ . '/../../lib/constants.php';
require_once __DIR__ . '/../../lib/metrics-spill-aggregator.php';

class MetricsSpillAggregatorInvalidSpillTest extends TestCase
{
    public function testInvalidSchemaSpill_IsNotDeleted(): void
    {
        $hourId = metrics_get_hour_id();
        $hourDir = getMetricsSpillHourDir($hourId);
        if (!@mkdir($hourDir, 0755, true) && !is_dir($hourDir)) {
            $this->fail('Could not create spill hour directory');
        }

        $badPath = $hourDir . '/invalid_schema.json';
        $payload = [
            'schema_version' => 99999,
            'generated_at' => time(),
            'hour_id' => $hourId,
            'counters' => ['global_page_views' => 1],
        ];
        $this->assertNotFalse(file_put_contents($badPath, json_encode($payload)));

        $stats = metrics_run_spill_aggregator_once();
        $this->assertFalse($stats['lock_contended']);
        $this->assertFileExists($badPath);
        $this->assertSame(0, (int) $stats['spills_merged']);

        @unlink($badPath);
        @rmdir($hourDir);
        @unlink(getMetricsAggregatorLastRunPath());
        @unlink(getMetricsAggregatorLockPath());
    }
}
