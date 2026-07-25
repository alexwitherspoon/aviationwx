<?php
/**
 * Log metrics system health and APCu memory pressure.
 *
 * Usage: php scripts/check-metrics-health.php
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "CLI only\n");
    exit(1);
}

require_once __DIR__ . '/../lib/config.php';
require_once __DIR__ . '/../lib/logger.php';
require_once __DIR__ . '/../lib/metrics.php';

$healthStatus = metrics_get_health_status();

if (!$healthStatus['healthy']) {
    foreach ($healthStatus['errors'] as $error) {
        aviationwx_log('error', 'check-metrics-health: failed', [
            'error' => $error,
        ], 'app');
    }
}

if (!empty($healthStatus['warnings'])) {
    foreach ($healthStatus['warnings'] as $warning) {
        aviationwx_log('warning', 'check-metrics-health: warning', [
            'warning' => $warning,
        ], 'app');
    }
}

$memInfo = metrics_get_apcu_memory_info();
if ($memInfo && $memInfo['used_percent'] > 80) {
    aviationwx_log('warning', 'check-metrics-health: APCu memory pressure', [
        'used_percent' => $memInfo['used_percent'],
        'used_mb' => round($memInfo['used_bytes'] / 1048576, 2),
        'total_mb' => round($memInfo['total_bytes'] / 1048576, 2),
    ], 'app');
}

$diskInfo = metrics_get_disk_space_info();
echo json_encode([
    'healthy' => $healthStatus['healthy'],
    'errors' => $healthStatus['errors'],
    'warnings' => $healthStatus['warnings'],
    'disk' => [
        'used_percent' => $diskInfo['used_percent'],
        'free_bytes' => $diskInfo['free_bytes'],
        'is_low' => $diskInfo['is_low'],
        'is_critical' => $diskInfo['is_critical'],
    ],
]) . PHP_EOL;

exit($healthStatus['healthy'] ? 0 : 1);
