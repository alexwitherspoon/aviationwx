<?php
/**
 * Delete expired metrics cache files.
 *
 * Usage: php scripts/cleanup-metrics.php
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "CLI only\n");
    exit(1);
}

require_once __DIR__ . '/../lib/config.php';
require_once __DIR__ . '/../lib/logger.php';
require_once __DIR__ . '/../lib/metrics.php';

$deletedCount = metrics_cleanup();
if ($deletedCount > 0) {
    aviationwx_log('info', 'cleanup-metrics: complete', [
        'deleted_files' => $deletedCount,
    ], 'app');
}

echo json_encode([
    'deleted_files' => $deletedCount,
    'success' => true,
]) . PHP_EOL;

exit(0);
