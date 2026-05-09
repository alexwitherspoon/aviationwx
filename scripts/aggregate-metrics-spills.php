<?php
/**
 * CLI entry: merge metrics spill shards into hourly/*.json (singleton flock).
 *
 * Invoked by the scheduler on METRICS_SPILL_MERGE_INTERVAL_SECONDS.
 */

declare(strict_types=1);

require_once __DIR__ . '/../lib/metrics-spill-aggregator.php';

$stats = metrics_run_spill_aggregator_once();

// Lock contention alone is benign (another merge holds the lock); real failures set errors[].
$fatal = !empty($stats['errors']);
exit($fatal ? 1 : 0);
