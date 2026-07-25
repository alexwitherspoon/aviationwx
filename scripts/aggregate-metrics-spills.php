<?php
/**
 * CLI entry: merge metrics spill journals into hourly/*.json (singleton flock).
 *
 * Invoked by the scheduler on METRICS_SPILL_MERGE_INTERVAL_SECONDS as a
 * fire-and-forget worker. When spills were merged, refreshes the status-bundle
 * APCu mirror via PHP-FPM HTTP (CLI cannot see FPM APCu).
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "CLI only\n");
    exit(1);
}

require_once __DIR__ . '/../lib/metrics.php';
require_once __DIR__ . '/../lib/metrics-spill-aggregator.php';

$stats = metrics_run_spill_aggregator_once();

$mirrorRefreshed = false;
$fatal = !empty($stats['errors']);
if (!$fatal && (int) $stats['spills_merged'] > 0) {
    $mirrorRefreshed = metrics_status_bundle_mirror_refresh_via_http();
    if (!$mirrorRefreshed) {
        aviationwx_log('warning', 'aggregate-metrics-spills: status bundle mirror refresh failed after merge', [
            'spills_merged' => (int) $stats['spills_merged'],
        ], 'app');
    }
}

$summaryJson = json_encode([
    'spills_merged' => $stats['spills_merged'],
    'hours_touched' => $stats['hours_touched'],
    'lock_contended' => $stats['lock_contended'],
    'errors' => $stats['errors'],
    'mirror_refreshed' => $mirrorRefreshed,
]);
if ($summaryJson !== false) {
    echo $summaryJson . PHP_EOL;
}

exit($fatal ? 1 : 0);
