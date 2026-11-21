<?php
/**
 * CLI wrapper for `madisHfmetarBulkRefreshRun()`. Usage: `php scripts/refresh-madis-hfmetar.php`
 */

require_once __DIR__ . '/../lib/madis-hfmetar/bulk.php';

$result = madisHfmetarBulkRefreshRun();
$ok = (bool) ($result['ok'] ?? false);
if (!$ok) {
    aviationwx_log('warning', 'refresh-madis-hfmetar: run failed', [
        'note' => $result['note'] ?? null,
        'http_ok' => $result['http_ok'] ?? null,
        'http_code' => $result['http_code'] ?? null,
        'meta_written' => $result['meta_written'] ?? null,
        'written' => $result['written'] ?? 0,
        'scanned' => $result['scanned'] ?? 0,
    ], 'app');
}
exit($ok ? 0 : 1);
