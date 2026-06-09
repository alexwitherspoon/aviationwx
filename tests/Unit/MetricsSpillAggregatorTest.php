<?php
/**
 * Spill aggregator integration: claim JSONL journals, merge lines into hourly buckets, deferred cleanup.
 */

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../lib/cache-paths.php';
require_once __DIR__ . '/../../lib/constants.php';
require_once __DIR__ . '/../../lib/metrics-spill-journal.php';
require_once __DIR__ . '/../../lib/metrics-spill-aggregator.php';

class MetricsSpillAggregatorTest extends TestCase
{
    /**
     * @param array<string, int> $counters
     */
    private function seedJournal(string $hourId, int $pid, array $counters): string
    {
        $hourDir = getMetricsSpillHourDir($hourId);
        if (!@mkdir($hourDir, 0755, true) && !is_dir($hourDir)) {
            $this->fail('Could not create spill hour directory');
        }

        $journal = getMetricsSpillWorkerJournalPath($hourId, $pid);
        $payload = metrics_spill_build_payload($hourId, $pid, $counters);
        $this->assertNotFalse(file_put_contents($journal, json_encode($payload) . "\n"));

        return $journal;
    }

    /**
     * @param list<array<string, int>> $counterSets
     */
    private function seedMultiLineJournal(string $hourId, int $pid, array $counterSets): string
    {
        $hourDir = getMetricsSpillHourDir($hourId);
        if (!@mkdir($hourDir, 0755, true) && !is_dir($hourDir)) {
            $this->fail('Could not create spill hour directory');
        }

        $journal = getMetricsSpillWorkerJournalPath($hourId, $pid);
        $content = '';
        foreach ($counterSets as $counters) {
            $content .= json_encode(metrics_spill_build_payload($hourId, $pid, $counters)) . "\n";
        }
        $this->assertNotFalse(file_put_contents($journal, $content));

        return $journal;
    }

    private function cleanupAggregatorRun(string $hourId): void
    {
        $hourPath = getMetricsHourlyPath($hourId);
        @unlink($hourPath);

        $hourDir = getMetricsSpillHourDir($hourId);
        foreach (glob($hourDir . '/*') ?: [] as $file) {
            @unlink($file);
        }
        @rmdir($hourDir);

        @unlink(getMetricsAggregatorLastRunPath());
        @unlink(getMetricsAggregatorLockPath());
    }

    public function testMerge_SingleJournalLineIntoHourlyFile(): void
    {
        $hourId = metrics_get_hour_id();
        $journal = $this->seedJournal($hourId, 424242, ['global_page_views' => 7]);

        $stats = metrics_run_spill_aggregator_once();

        $this->assertFalse($stats['lock_contended']);
        $this->assertSame(1, (int) $stats['spills_merged']);
        $this->assertSame(1, (int) $stats['spills_deleted']);
        $this->assertFileDoesNotExist($journal);

        $hourJson = json_decode((string) file_get_contents(getMetricsHourlyPath($hourId)), true);
        $this->assertIsArray($hourJson);
        $this->assertSame(7, $hourJson['global']['page_views'] ?? null);

        $this->cleanupAggregatorRun($hourId);
    }

    public function testMerge_MultipleLinesInOneWorkerJournal(): void
    {
        $hourId = '2099-03-10-08';
        $journal = $this->seedMultiLineJournal($hourId, 88001, [
            ['global_page_views' => 2],
            ['global_page_views' => 3],
        ]);

        $stats = metrics_run_spill_aggregator_once();

        $this->assertFalse($stats['lock_contended']);
        $this->assertSame(2, (int) $stats['spills_merged']);
        $this->assertSame(1, (int) $stats['spills_deleted']);
        $this->assertFileDoesNotExist($journal);

        $hourJson = json_decode((string) file_get_contents(getMetricsHourlyPath($hourId)), true);
        $this->assertSame(5, $hourJson['global']['page_views'] ?? null);

        $this->cleanupAggregatorRun($hourId);
    }

    public function testMerge_MultipleWorkerJournalsSameHour(): void
    {
        $hourId = '2099-03-10-09';
        $this->seedJournal($hourId, 88002, ['global_page_views' => 4]);
        $this->seedJournal($hourId, 88003, ['global_page_views' => 6]);

        $stats = metrics_run_spill_aggregator_once();

        $this->assertGreaterThanOrEqual(2, (int) $stats['spills_merged']);
        $this->assertSame(2, (int) $stats['spills_deleted']);

        $hourJson = json_decode((string) file_get_contents(getMetricsHourlyPath($hourId)), true);
        $this->assertSame(10, $hourJson['global']['page_views'] ?? null);

        $this->cleanupAggregatorRun($hourId);
    }

    public function testMerge_PreviouslyClaimedJournalIsMergedOnRetry(): void
    {
        $hourId = '2099-03-10-11';
        $hourDir = getMetricsSpillHourDir($hourId);
        if (!@mkdir($hourDir, 0755, true) && !is_dir($hourDir)) {
            $this->fail('Could not create spill hour directory');
        }

        $claimed = $hourDir . '/88005.jsonl.merging.99999';
        $payload = metrics_spill_build_payload($hourId, 88005, ['global_page_views' => 8]);
        $this->assertNotFalse(file_put_contents($claimed, json_encode($payload) . "\n"));

        $stats = metrics_run_spill_aggregator_once();

        $this->assertSame(1, (int) $stats['spills_merged']);
        $this->assertSame(1, (int) $stats['spills_deleted']);
        $this->assertFileDoesNotExist($claimed);

        $hourJson = json_decode((string) file_get_contents(getMetricsHourlyPath($hourId)), true);
        $this->assertSame(8, $hourJson['global']['page_views'] ?? null);

        $this->cleanupAggregatorRun($hourId);
    }

    public function testMerge_MixedValidAndInvalidLinesSkipsInvalid(): void
    {
        $hourId = '2099-03-10-12';
        $hourDir = getMetricsSpillHourDir($hourId);
        if (!@mkdir($hourDir, 0755, true) && !is_dir($hourDir)) {
            $this->fail('Could not create spill hour directory');
        }

        $journal = getMetricsSpillWorkerJournalPath($hourId, 88006);
        $valid = metrics_spill_build_payload($hourId, 88006, ['global_page_views' => 5]);
        $invalid = [
            'schema_version' => 99999,
            'generated_at' => time(),
            'hour_id' => $hourId,
            'pid' => 88006,
            'counters' => ['global_page_views' => 100],
        ];
        $this->assertNotFalse(file_put_contents(
            $journal,
            json_encode($invalid) . "\n" . json_encode($valid) . "\n"
        ));

        $stats = metrics_run_spill_aggregator_once();

        $this->assertSame(1, (int) $stats['spills_merged']);
        $hourJson = json_decode((string) file_get_contents(getMetricsHourlyPath($hourId)), true);
        $this->assertSame(5, $hourJson['global']['page_views'] ?? null);

        $this->cleanupAggregatorRun($hourId);
    }

    public function testMerge_InvalidOnlyJournalLeavesClaimedArtifact(): void
    {
        $hourId = metrics_get_hour_id();
        $journal = getMetricsSpillWorkerJournalPath($hourId, 88010);
        $hourDir = dirname($journal);
        if (!@mkdir($hourDir, 0755, true) && !is_dir($hourDir)) {
            $this->fail('Could not create spill hour directory');
        }

        $invalid = [
            'schema_version' => 99999,
            'generated_at' => time(),
            'hour_id' => $hourId,
            'pid' => 88010,
            'counters' => ['global_page_views' => 1],
        ];
        $this->assertNotFalse(file_put_contents($journal, json_encode($invalid) . "\n"));

        $stats = metrics_run_spill_aggregator_once();

        $this->assertFalse($stats['lock_contended']);
        $this->assertSame(0, (int) $stats['spills_merged']);
        $this->assertSame(0, (int) $stats['spills_deleted']);
        $this->assertFileDoesNotExist($journal);

        $claimed = glob($hourDir . '/88010.jsonl.merging.*') ?: [];
        $this->assertCount(1, $claimed);

        @unlink($claimed[0]);
        $this->cleanupAggregatorRun($hourId);
    }

    public function testMerge_ClaimedJournalRemainsWhenHourlyWriteFails(): void
    {
        $hourId = '2099-03-10-13';
        $this->seedJournal($hourId, 88011, ['global_page_views' => 3]);

        $hourlyDir = CACHE_METRICS_HOURLY_DIR;
        $previousMode = @fileperms($hourlyDir);
        if ($previousMode === false) {
            $this->markTestSkipped('Could not read hourly metrics directory permissions');
        }

        @chmod($hourlyDir, 0555);

        try {
            $stats = metrics_run_spill_aggregator_once();
            $this->assertGreaterThanOrEqual(1, (int) $stats['spills_merged']);
            $this->assertSame(0, (int) $stats['spills_deleted']);
            $this->assertFileDoesNotExist(getMetricsHourlyPath($hourId));

            $claimed = glob(getMetricsSpillHourDir($hourId) . '/88011.jsonl.merging.*') ?: [];
            $this->assertCount(1, $claimed);
        } finally {
            @chmod($hourlyDir, $previousMode);
            foreach (glob(getMetricsSpillHourDir($hourId) . '/*') ?: [] as $file) {
                @unlink($file);
            }
            @rmdir(getMetricsSpillHourDir($hourId));
            @unlink(getMetricsAggregatorLastRunPath());
            @unlink(getMetricsAggregatorLockPath());
        }
    }
}
