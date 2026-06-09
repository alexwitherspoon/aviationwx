<?php
/**
 * CLI entry: merge metrics spill journals into hourly/*.json (singleton flock).
 *
 * Invoked by the scheduler on METRICS_SPILL_MERGE_INTERVAL_SECONDS.
 */

declare(strict_types=1);

require_once __DIR__ . '/../lib/metrics-spill-aggregator.php';

$stats = metrics_run_spill_aggregator_once();

// Single-line JSON for scheduler (stdout): mirror refresh only when spills_merged > 0.
$summaryJson = json_encode([
    'spills_merged' => $stats['spills_merged'],
    'hours_touched' => $stats['hours_touched'],
    'lock_contended' => $stats['lock_contended'],
    'errors' => $stats['errors'],
]);
if ($summaryJson !== false) {
    echo $summaryJson . PHP_EOL;
}

// Lock contention alone is benign (another merge holds the lock); real failures set errors[].
$fatal = !empty($stats['errors']);
exit($fatal ? 1 : 0);
