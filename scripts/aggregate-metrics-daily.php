<?php
/**
 * Aggregate hourly metrics into a daily bucket (UTC).
 *
 * Usage:
 *   php scripts/aggregate-metrics-daily.php
 *   php scripts/aggregate-metrics-daily.php --date=YYYY-MM-DD
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "CLI only\n");
    exit(1);
}

require_once __DIR__ . '/../lib/config.php';
require_once __DIR__ . '/../lib/logger.php';
require_once __DIR__ . '/../lib/metrics.php';

$dateId = null;
foreach (array_slice($argv, 1) as $arg) {
    if (str_starts_with($arg, '--date=')) {
        $dateId = substr($arg, strlen('--date='));
    }
}
if ($dateId === null || $dateId === '') {
    $dateId = gmdate('Y-m-d', time() - 86400);
}
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateId)) {
    fwrite(STDERR, "aggregate-metrics-daily: invalid --date={$dateId}\n");
    exit(1);
}

$ok = metrics_aggregate_daily($dateId);

if ($ok) {
    aviationwx_log('info', 'aggregate-metrics-daily: complete', [
        'date' => $dateId,
    ], 'app');
} else {
    aviationwx_log('warning', 'aggregate-metrics-daily: failed', [
        'date' => $dateId,
    ], 'app');
}

echo json_encode([
    'date' => $dateId,
    'success' => $ok,
]) . PHP_EOL;

exit($ok ? 0 : 1);
