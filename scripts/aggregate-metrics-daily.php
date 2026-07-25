<?php
/**
 * Aggregate yesterday's hourly metrics into a daily bucket (UTC).
 *
 * Usage: php scripts/aggregate-metrics-daily.php
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "CLI only\n");
    exit(1);
}

require_once __DIR__ . '/../lib/config.php';
require_once __DIR__ . '/../lib/logger.php';
require_once __DIR__ . '/../lib/metrics.php';

$yesterdayId = gmdate('Y-m-d', time() - 86400);
$ok = metrics_aggregate_daily($yesterdayId);

if ($ok) {
    aviationwx_log('info', 'aggregate-metrics-daily: complete', [
        'date' => $yesterdayId,
    ], 'app');
} else {
    aviationwx_log('warning', 'aggregate-metrics-daily: failed', [
        'date' => $yesterdayId,
    ], 'app');
}

echo json_encode([
    'date' => $yesterdayId,
    'success' => $ok,
]) . PHP_EOL;

exit($ok ? 0 : 1);
