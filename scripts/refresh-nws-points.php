<?php
/**
 * CLI wrapper for `nwsPointsRefreshRun()`. Usage: `php scripts/refresh-nws-points.php`
 */

require_once __DIR__ . '/../lib/config.php';
require_once __DIR__ . '/../lib/cache-paths.php';
require_once __DIR__ . '/../lib/logger.php';
require_once __DIR__ . '/../lib/nws-points-refresh.php';

$result = nwsPointsRefreshRun();
$ok = (bool) ($result['ok'] ?? false);
if (!$ok) {
    aviationwx_log('warning', 'refresh-nws-points: run failed', [
        'note' => $result['note'] ?? null,
        'coordinates' => $result['coordinates'] ?? 0,
        'fetched' => $result['fetched'] ?? 0,
        'failed' => $result['failed'] ?? 0,
        'skipped_throttle' => $result['skipped_throttle'] ?? 0,
    ], 'app');
}
exit($ok ? 0 : 1);
