<?php
/**
 * Spill aggregator merges shard JSON into hourly metrics files.
 */

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../lib/cache-paths.php';
require_once __DIR__ . '/../../lib/constants.php';
require_once __DIR__ . '/../../lib/metrics-spill-aggregator.php';

class MetricsSpillAggregatorTest extends TestCase
{
    public function testAggregator_MergesSpillIntoHourlyFile(): void
    {
        $hourId = metrics_get_hour_id();
        $hourDir = getMetricsSpillHourDir($hourId);
        if (!@mkdir($hourDir, 0755, true) && !is_dir($hourDir)) {
            $this->fail('Could not create spill hour directory');
        }

        $payload = [
            'schema_version' => METRICS_SPILL_FILE_SCHEMA_VERSION,
            'generated_at' => time(),
            'hour_id' => $hourId,
            'pid' => 424242,
            'counters' => [
                'global_page_views' => 7,
            ],
        ];

        $spillPath = $hourDir . '/424242.json';
        $this->assertNotFalse(file_put_contents($spillPath, json_encode($payload)));

        $stats = metrics_run_spill_aggregator_once();

        $this->assertFalse($stats['lock_contended']);
        $this->assertGreaterThanOrEqual(1, (int) $stats['spills_merged']);
        $this->assertFileDoesNotExist($spillPath);

        $hourPath = getMetricsHourlyPath($hourId);
        $this->assertFileExists($hourPath);
        $hourJson = json_decode((string) file_get_contents($hourPath), true);
        $this->assertIsArray($hourJson);
        $this->assertSame(7, $hourJson['global']['page_views'] ?? null);

        @unlink($hourPath);
        @rmdir($hourDir);
        @unlink(getMetricsAggregatorLastRunPath());
        @unlink(getMetricsAggregatorLockPath());
    }
}
