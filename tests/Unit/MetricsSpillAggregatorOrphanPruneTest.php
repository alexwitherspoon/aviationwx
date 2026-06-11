<?php
/**
 * Orphan spill journals and abandoned claim files older than METRICS_SPILL_ORPHAN_MAX_AGE_SECONDS are pruned.
 */

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../lib/cache-paths.php';
require_once __DIR__ . '/../../lib/constants.php';
require_once __DIR__ . '/../../lib/metrics-spill-aggregator.php';

class MetricsSpillAggregatorOrphanPruneTest extends TestCase
{
    /**
     * Stale artifacts must live under an invalid hour dir so the merge loop skips them.
     *
     * @return string Absolute spill directory path
     */
    private function orphanSpillDir(): string
    {
        $dir = getMetricsSpillRootDir() . '/orphan-prune-' . bin2hex(random_bytes(4));
        if (!@mkdir($dir, 0755, true) && !is_dir($dir)) {
            $this->fail('Could not create orphan spill directory');
        }

        return $dir;
    }

    public function testPrune_RemovesVeryOldSpillJournal(): void
    {
        $dir = $this->orphanSpillDir();

        $stalePath = $dir . '/1.jsonl';
        $this->assertNotFalse(file_put_contents($stalePath, "{\"orphan\":true}\n"));
        $staleMtime = time() - METRICS_SPILL_ORPHAN_MAX_AGE_SECONDS - 120;
        $this->assertTrue(touch($stalePath, $staleMtime));

        $stats = metrics_run_spill_aggregator_once();
        $this->assertFalse($stats['lock_contended']);
        $this->assertGreaterThanOrEqual(1, (int) $stats['orphans_pruned']);
        $this->assertFileDoesNotExist($stalePath);

        @rmdir($dir);
        @unlink(getMetricsAggregatorLastRunPath());
        @unlink(getMetricsAggregatorLockPath());
    }

    public function testPrune_RemovesVeryOldClaimedJournal(): void
    {
        $dir = $this->orphanSpillDir();

        $claimedPath = $dir . '/42.jsonl.merging.99999';
        $this->assertNotFalse(file_put_contents($claimedPath, "{\"orphan\":true}\n"));
        $staleMtime = time() - METRICS_SPILL_ORPHAN_MAX_AGE_SECONDS - 120;
        $this->assertTrue(touch($claimedPath, $staleMtime));

        $stats = metrics_run_spill_aggregator_once();
        $this->assertFalse($stats['lock_contended']);
        $this->assertGreaterThanOrEqual(1, (int) $stats['orphans_pruned']);
        $this->assertFileDoesNotExist($claimedPath);

        @rmdir($dir);
        @unlink(getMetricsAggregatorLastRunPath());
        @unlink(getMetricsAggregatorLockPath());
    }

    public function testPrune_RemovesVeryOldLegacyJsonShard(): void
    {
        $dir = $this->orphanSpillDir();

        $stalePath = $dir . '/90000_abc123.json';
        $this->assertNotFalse(file_put_contents($stalePath, json_encode(['legacy' => true])));
        $staleMtime = time() - METRICS_SPILL_ORPHAN_MAX_AGE_SECONDS - 120;
        $this->assertTrue(touch($stalePath, $staleMtime));

        $stats = metrics_run_spill_aggregator_once();
        $this->assertFalse($stats['lock_contended']);
        $this->assertGreaterThanOrEqual(1, (int) $stats['orphans_pruned']);
        $this->assertFileDoesNotExist($stalePath);

        @rmdir($dir);
        @unlink(getMetricsAggregatorLastRunPath());
        @unlink(getMetricsAggregatorLockPath());
    }
}
