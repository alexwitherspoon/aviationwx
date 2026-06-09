<?php
/**
 * Spill aggregator merges worker JSONL journals into hourly metrics files.
 */

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../lib/cache-paths.php';
require_once __DIR__ . '/../../lib/constants.php';
require_once __DIR__ . '/../../lib/metrics-spill-journal.php';
require_once __DIR__ . '/../../lib/metrics-spill-aggregator.php';

class MetricsSpillAggregatorTest extends TestCase
{
    public function testAggregator_MergesJournalIntoHourlyFile(): void
    {
        $hourId = metrics_get_hour_id();
        $hourDir = getMetricsSpillHourDir($hourId);
        if (!@mkdir($hourDir, 0755, true) && !is_dir($hourDir)) {
            $this->fail('Could not create spill hour directory');
        }

        $journalPath = getMetricsSpillWorkerJournalPath($hourId, 424242);
        $payload = metrics_spill_build_payload($hourId, 424242, ['global_page_views' => 7]);
        $this->assertNotFalse(file_put_contents($journalPath, json_encode($payload) . "\n"));

        $stats = metrics_run_spill_aggregator_once();

        $this->assertFalse($stats['lock_contended']);
        $this->assertGreaterThanOrEqual(1, (int) $stats['spills_merged']);
        $this->assertFileDoesNotExist($journalPath);

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
