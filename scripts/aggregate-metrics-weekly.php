<?php
/**
 * Aggregate daily metrics into a weekly bucket (ISO week, UTC).
 *
 * Usage:
 *   php scripts/aggregate-metrics-weekly.php
 *   php scripts/aggregate-metrics-weekly.php --week=YYYY-Www
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "CLI only\n");
    exit(1);
}

require_once __DIR__ . '/../lib/config.php';
require_once __DIR__ . '/../lib/logger.php';
require_once __DIR__ . '/../lib/metrics.php';

$weekId = null;
foreach (array_slice($argv, 1) as $arg) {
    if (str_starts_with($arg, '--week=')) {
        $weekId = substr($arg, strlen('--week='));
    }
}
if ($weekId === null || $weekId === '') {
    $weekId = gmdate('Y-\WW', time() - (7 * 86400));
}
if (!preg_match('/^\d{4}-W\d{2}$/', $weekId)) {
    fwrite(STDERR, "aggregate-metrics-weekly: invalid --week={$weekId}\n");
    exit(1);
}

$ok = metrics_aggregate_weekly($weekId);

if ($ok) {
    aviationwx_log('info', 'aggregate-metrics-weekly: complete', [
        'week' => $weekId,
    ], 'app');
} else {
    aviationwx_log('warning', 'aggregate-metrics-weekly: failed', [
        'week' => $weekId,
    ], 'app');
}

echo json_encode([
    'week' => $weekId,
    'success' => $ok,
]) . PHP_EOL;

exit($ok ? 0 : 1);
