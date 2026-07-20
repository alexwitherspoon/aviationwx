<?php

/**
 * OurAirports upstream probe worker.
 *
 * HEAD each bulk CSV; update meta only. Scheduler invokes at most daily.
 *
 * Usage: php scripts/probe-ourairports.php
 */

require_once __DIR__ . '/../lib/config.php';
require_once __DIR__ . '/../lib/logger.php';
require_once __DIR__ . '/../lib/worker-timeout.php';
require_once __DIR__ . '/../lib/ourairports/locks.php';
require_once __DIR__ . '/../lib/ourairports/probe.php';

if (PHP_SAPI !== 'cli') {
    exit(1);
}

$scriptName = $_SERVER['PHP_SELF'] ?? $_SERVER['SCRIPT_NAME'] ?? '';
if (basename($scriptName) !== basename(__FILE__) && $scriptName !== __FILE__) {
    return;
}

initWorkerTimeout(OURAIRPORTS_PROBE_WORKER_TIMEOUT, 'ourairports_probe');

if (ourAirportsBulkFetchInProgress()) {
    aviationwx_log('info', 'ourairports probe skipped, bulk fetch in progress', [], 'app');
    exit(0);
}

$results = ourAirportsProbeAll();
if ($results === []) {
    aviationwx_log('info', 'ourairports probe skipped, lock held or bulk fetch in progress', [], 'app');
    exit(0);
}

$changed = 0;
$errors = 0;
$skipped = 0;

foreach ($results as $row) {
    if (($row['skipped'] ?? false) === true) {
        $skipped++;
        continue;
    }
    if (($row['result'] ?? '') === 'changed') {
        $changed++;
    }
    if (($row['result'] ?? '') === 'error') {
        $errors++;
    }
}

aviationwx_log('info', 'ourairports probe complete', [
    'files' => count($results),
    'changed' => $changed,
    'errors' => $errors,
    'skipped' => $skipped,
], 'app');

exit($errors > 0 && $changed === 0 ? 1 : 0);
