<?php
/**
 * Aggregate last week's daily metrics into a weekly bucket (ISO week, UTC).
 *
 * Usage: php scripts/aggregate-metrics-weekly.php
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "CLI only\n");
    exit(1);
}

require_once __DIR__ . '/../lib/config.php';
require_once __DIR__ . '/../lib/logger.php';
require_once __DIR__ . '/../lib/metrics.php';

$lastWeekId = gmdate('Y-\WW', time() - (7 * 86400));
$ok = metrics_aggregate_weekly($lastWeekId);

if ($ok) {
    aviationwx_log('info', 'aggregate-metrics-weekly: complete', [
        'week' => $lastWeekId,
    ], 'app');
} else {
    aviationwx_log('warning', 'aggregate-metrics-weekly: failed', [
        'week' => $lastWeekId,
    ], 'app');
}

echo json_encode([
    'week' => $lastWeekId,
    'success' => $ok,
]) . PHP_EOL;

exit($ok ? 0 : 1);
