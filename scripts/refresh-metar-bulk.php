<?php
/**
 * CLI wrapper for `metarBulkRefreshRun()`. Usage: `php scripts/refresh-metar-bulk.php`
 */

require_once __DIR__ . '/../lib/config.php';
require_once __DIR__ . '/../lib/cache-paths.php';
require_once __DIR__ . '/../lib/logger.php';
require_once __DIR__ . '/../lib/metar-bulk.php';

$result = metarBulkRefreshRun();
$code = ($result['ok'] ?? false) ? 0 : 1;
exit($code);
