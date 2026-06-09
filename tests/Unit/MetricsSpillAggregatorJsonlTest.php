<?php
/**
 * Per-worker JSONL spill journals merge into hourly metrics files.
 */

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../lib/cache-paths.php';
require_once __DIR__ . '/../../lib/constants.php';
require_once __DIR__ . '/../../lib/metrics-spill-journal.php';
require_once __DIR__ . '/../../lib/metrics-spill-aggregator.php';

class MetricsSpillAggregatorJsonlTest extends TestCase
{
    public function testAggregator_MergesWorkerJournalLinesIntoHourlyFile(): void
    {
        $hourId = '2099-03-10-08';
        $hourDir = getMetricsSpillHourDir($hourId);
        if (!@mkdir($hourDir, 0755, true) && !is_dir($hourDir)) {
            $this->fail('Could not create spill hour directory');
        }

        $journal = getMetricsSpillWorkerJournalPath($hourId, 88001);
        $lines = [
            metrics_spill_build_payload($hourId, 88001, ['global_page_views' => 2]),
            metrics_spill_build_payload($hourId, 88001, ['global_page_views' => 3]),
        ];
        $content = '';
        foreach ($lines as $payload) {
            $content .= json_encode($payload) . "\n";
        }
        $this->assertNotFalse(file_put_contents($journal, $content));

        $stats = metrics_run_spill_aggregator_once();

        $this->assertFalse($stats['lock_contended']);
        $this->assertSame(2, (int) $stats['spills_merged']);
        $this->assertFileDoesNotExist($journal);

        $hourPath = getMetricsHourlyPath($hourId);
        $this->assertFileExists($hourPath);
        $hourJson = json_decode((string) file_get_contents($hourPath), true);
        $this->assertIsArray($hourJson);
        $this->assertSame(5, $hourJson['global']['page_views'] ?? null);

        @unlink($hourPath);
        @rmdir($hourDir);
        @unlink(getMetricsAggregatorLastRunPath());
        @unlink(getMetricsAggregatorLockPath());
    }

    public function testAggregator_MergesLegacyJsonAndWorkerJournalTogether(): void
    {
        $hourId = '2099-03-10-09';
        $hourDir = getMetricsSpillHourDir($hourId);
        if (!@mkdir($hourDir, 0755, true) && !is_dir($hourDir)) {
            $this->fail('Could not create spill hour directory');
        }

        $legacy = $hourDir . '/legacy_shard.json';
        $legacyPayload = [
            'schema_version' => METRICS_SPILL_FILE_SCHEMA_VERSION,
            'generated_at' => time(),
            'hour_id' => $hourId,
            'pid' => 88002,
            'counters' => ['global_page_views' => 4],
        ];
        $this->assertNotFalse(file_put_contents($legacy, json_encode($legacyPayload)));

        $journal = getMetricsSpillWorkerJournalPath($hourId, 88003);
        $journalLine = metrics_spill_build_payload($hourId, 88003, ['global_page_views' => 6]);
        $this->assertNotFalse(file_put_contents($journal, json_encode($journalLine) . "\n"));

        $stats = metrics_run_spill_aggregator_once();

        $this->assertGreaterThanOrEqual(2, (int) $stats['spills_merged']);
        $hourPath = getMetricsHourlyPath($hourId);
        $hourJson = json_decode((string) file_get_contents($hourPath), true);
        $this->assertSame(10, $hourJson['global']['page_views'] ?? null);

        @unlink($hourPath);
        @rmdir($hourDir);
        @unlink(getMetricsAggregatorLastRunPath());
        @unlink(getMetricsAggregatorLockPath());
    }

    public function testJournalAppend_MultipleShutdownsAppendLinesToSameFile(): void
    {
        $hourId = '2099-03-10-10';
        $journal = getMetricsSpillWorkerJournalPath($hourId, 88004);
        $dir = dirname($journal);
        if (!@mkdir($dir, 0755, true) && !is_dir($dir)) {
            $this->fail('Could not create spill hour directory');
        }

        $this->assertTrue(metrics_spill_journal_append_locked(
            $journal,
            metrics_spill_build_payload($hourId, 88004, ['global_page_views' => 1])
        ));
        $this->assertTrue(metrics_spill_journal_append_locked(
            $journal,
            metrics_spill_build_payload($hourId, 88004, ['global_page_views' => 2])
        ));

        $raw = file_get_contents($journal);
        $this->assertNotFalse($raw);
        $this->assertSame(2, substr_count($raw, "\n"));

        @unlink($journal);
        @rmdir($dir);
    }
}
