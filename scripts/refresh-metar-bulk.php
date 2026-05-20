<?php
/**
 * CLI wrapper for `metarBulkRefreshRun()`. Usage: `php scripts/refresh-metar-bulk.php`
 */

require_once __DIR__ . '/../lib/config.php';
require_once __DIR__ . '/../lib/cache-paths.php';
require_once __DIR__ . '/../lib/logger.php';
require_once __DIR__ . '/../lib/metar-bulk.php';

$result = metarBulkRefreshRun();
$ok = (bool) ($result['ok'] ?? false);
if (!$ok) {
    aviationwx_log('warning', 'refresh-metar-bulk: run failed', metarBulkObservabilityContext([
        'note' => $result['note'] ?? null,
        'http_ok' => $result['http_ok'] ?? null,
        'http_code' => $result['http_code'] ?? null,
        'meta_written' => $result['meta_written'] ?? null,
        'written' => $result['written'] ?? 0,
        'scanned' => $result['scanned'] ?? 0,
    ]), 'app');
}
exit($ok ? 0 : 1);
