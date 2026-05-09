<?php
/**
 * CLI entry: merge metrics spill shards into hourly/*.json (singleton flock).
 *
 * Invoked by the scheduler on METRICS_SPILL_MERGE_INTERVAL_SECONDS.
 */

declare(strict_types=1);

require_once __DIR__ . '/../lib/metrics-spill-aggregator.php';

metrics_run_spill_aggregator_once();

exit(0);
