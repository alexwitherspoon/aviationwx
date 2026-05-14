<?php
/**
 * Build Public API operations snapshot (GET /v1/operations).
 *
 * Aggregates pre-warmed status JSON under CACHE_BASE_DIR, parses recent app.log tail,
 * and writes cache/operations_snapshot.json. Invoked by scheduler every
 * OPERATIONS_SNAPSHOT_BUILD_INTERVAL_SECONDS (non-blocking).
 *
 * Usage: php scripts/build-operations-snapshot.php
 */

require_once __DIR__ . '/../lib/config.php';
require_once __DIR__ . '/../lib/constants.php';
require_once __DIR__ . '/../lib/logger.php';
require_once __DIR__ . '/../lib/cache-paths.php';
require_once __DIR__ . '/../lib/operations-snapshot.php';

$config = loadConfig();
if ($config === null) {
    fwrite(STDERR, "build-operations-snapshot: loadConfig failed\n");
    exit(1);
}

$payload = operations_snapshot_build(CACHE_BASE_DIR, [
    'now' => time(),
    'log_path' => defined('AVIATIONWX_LOG_FILE') ? AVIATIONWX_LOG_FILE : '',
]);

if (!operations_snapshot_write_envelope(CACHE_OPERATIONS_SNAPSHOT_FILE, $payload, OPERATIONS_SNAPSHOT_MAX_AGE_SECONDS)) {
    aviationwx_log('warning', 'build-operations-snapshot: failed to write envelope', [
        'path' => CACHE_OPERATIONS_SNAPSHOT_FILE,
    ], 'app');
    exit(1);
}

exit(0);
