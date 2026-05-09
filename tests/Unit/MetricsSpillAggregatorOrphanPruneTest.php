<?php
/**
 * Orphan spill files older than METRICS_SPILL_ORPHAN_MAX_AGE_SECONDS are pruned.
 */

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../lib/cache-paths.php';
require_once __DIR__ . '/../../lib/constants.php';
require_once __DIR__ . '/../../lib/metrics-spill-aggregator.php';

class MetricsSpillAggregatorOrphanPruneTest extends TestCase
{
    public function testPrune_RemovesVeryOldSpillJson(): void
    {
        $oldHour = '2019-12-31-23';
        $dir = getMetricsSpillHourDir($oldHour);
        if (!@mkdir($dir, 0755, true) && !is_dir($dir)) {
            $this->fail('Could not create spill hour directory');
        }

        $stalePath = $dir . '/1.json';
        $this->assertNotFalse(file_put_contents($stalePath, json_encode(['orphan' => true])));
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
