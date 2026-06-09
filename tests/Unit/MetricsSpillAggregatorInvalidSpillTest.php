<?php
/**
 * Invalid journal lines are skipped; journals with no valid lines are not merged into hourly data.
 */

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../lib/cache-paths.php';
require_once __DIR__ . '/../../lib/constants.php';
require_once __DIR__ . '/../../lib/metrics-spill-journal.php';
require_once __DIR__ . '/../../lib/metrics-spill-aggregator.php';

class MetricsSpillAggregatorInvalidSpillTest extends TestCase
{
    public function testInvalidSchemaJournalLine_IsNotMerged(): void
    {
        $hourId = metrics_get_hour_id();
        $hourDir = getMetricsSpillHourDir($hourId);
        if (!@mkdir($hourDir, 0755, true) && !is_dir($hourDir)) {
            $this->fail('Could not create spill hour directory');
        }

        $journalPath = getMetricsSpillWorkerJournalPath($hourId, 88010);
        $payload = [
            'schema_version' => 99999,
            'generated_at' => time(),
            'hour_id' => $hourId,
            'pid' => 88010,
            'counters' => ['global_page_views' => 1],
        ];
        $this->assertNotFalse(file_put_contents($journalPath, json_encode($payload) . "\n"));

        $stats = metrics_run_spill_aggregator_once();
        $this->assertFalse($stats['lock_contended']);
        $this->assertSame(0, (int) $stats['spills_merged']);
        $this->assertFileDoesNotExist($journalPath);

        @rmdir($hourDir);
        @unlink(getMetricsAggregatorLastRunPath());
        @unlink(getMetricsAggregatorLockPath());
    }
}
