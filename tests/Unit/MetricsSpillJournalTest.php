<?php
/**
 * Per-worker JSONL journal helpers: payload shape, append flock, claim rename, path classification.
 */

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../lib/cache-paths.php';
require_once __DIR__ . '/../../lib/constants.php';
require_once __DIR__ . '/../../lib/metrics-spill-journal.php';

class MetricsSpillJournalTest extends TestCase
{
    public function testBuildPayload_IncludesSchemaHourIdAndCounters(): void
    {
        $hourId = '2099-04-01-12';
        $payload = metrics_spill_build_payload($hourId, 88020, ['global_page_views' => 9]);

        $this->assertSame(METRICS_SPILL_FILE_SCHEMA_VERSION, $payload['schema_version']);
        $this->assertSame($hourId, $payload['hour_id']);
        $this->assertSame(88020, $payload['pid']);
        $this->assertSame(9, $payload['counters']['global_page_views']);
        $this->assertIsInt($payload['generated_at']);
    }

    public function testAppendLocked_AppendsNewlineTerminatedLines(): void
    {
        $hourId = '2099-04-01-13';
        $journal = getMetricsSpillWorkerJournalPath($hourId, 88021);
        $dir = dirname($journal);
        if (!@mkdir($dir, 0755, true) && !is_dir($dir)) {
            $this->fail('Could not create spill hour directory');
        }

        $this->assertTrue(metrics_spill_journal_append_locked(
            $journal,
            metrics_spill_build_payload($hourId, 88021, ['global_page_views' => 1])
        ));
        $this->assertTrue(metrics_spill_journal_append_locked(
            $journal,
            metrics_spill_build_payload($hourId, 88021, ['global_page_views' => 2])
        ));

        $raw = file_get_contents($journal);
        $this->assertNotFalse($raw);
        $this->assertSame(2, substr_count($raw, "\n"));

        @unlink($journal);
        @rmdir($dir);
    }

    public function testClaimForMerge_RenamesLiveJournalAwayFromAppendPath(): void
    {
        $hourId = '2099-04-01-14';
        $live = getMetricsSpillWorkerJournalPath($hourId, 88022);
        $dir = dirname($live);
        if (!@mkdir($dir, 0755, true) && !is_dir($dir)) {
            $this->fail('Could not create spill hour directory');
        }

        $this->assertNotFalse(file_put_contents(
            $live,
            json_encode(metrics_spill_build_payload($hourId, 88022, ['global_page_views' => 1])) . "\n"
        ));

        $claimed = metrics_spill_journal_claim_for_merge($live);
        $this->assertIsString($claimed);
        $this->assertFileDoesNotExist($live);
        $this->assertFileExists($claimed);
        $this->assertTrue(metrics_spill_path_is_claimed_journal($claimed));
        $this->assertFalse(metrics_spill_path_is_worker_journal($claimed));

        @unlink($claimed);
        @rmdir($dir);
    }

    public function testPathClassification_DistinguishesLiveClaimedAndTmp(): void
    {
        $this->assertTrue(metrics_spill_path_is_worker_journal('/cache/spill/2099-01-01-01/42.jsonl'));
        $this->assertFalse(metrics_spill_path_is_worker_journal('/cache/spill/2099-01-01-01/42.jsonl.merging.9'));
        $this->assertFalse(metrics_spill_path_is_worker_journal('/cache/spill/2099-01-01-01/42.jsonl.tmp.1'));

        $this->assertTrue(metrics_spill_path_is_claimed_journal('/cache/spill/2099-01-01-01/42.jsonl.merging.9'));
        $this->assertFalse(metrics_spill_path_is_claimed_journal('/cache/spill/2099-01-01-01/42.jsonl'));
    }

    public function testListJournalPathsForHour_IncludesAbandonedClaimFiles(): void
    {
        require_once __DIR__ . '/../../lib/metrics-spill-aggregator.php';

        $hourId = '2099-04-01-15';
        $dir = getMetricsSpillHourDir($hourId);
        if (!@mkdir($dir, 0755, true) && !is_dir($dir)) {
            $this->fail('Could not create spill hour directory');
        }

        $claimed = $dir . '/99.jsonl.merging.12345';
        $this->assertNotFalse(file_put_contents($claimed, "\n"));

        $paths = metrics_spill_aggregator_list_journal_paths_for_hour($dir);
        $this->assertContains($claimed, $paths);

        @unlink($claimed);
        @rmdir($dir);
    }
}
