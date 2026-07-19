<?php

/**
 * OurAirports bulk CSV fetch worker.
 *
 * Full GET for each CSV that policy marks due; ingest identity and frequencies JSON from disk.
 *
 * Usage: php scripts/fetch-ourairports-bulk.php
 */

require_once __DIR__ . '/../lib/config.php';
require_once __DIR__ . '/../lib/logger.php';
require_once __DIR__ . '/../lib/cache-paths.php';
require_once __DIR__ . '/../lib/worker-timeout.php';
require_once __DIR__ . '/../lib/ourairports/download.php';
require_once __DIR__ . '/../lib/ourairports/locks.php';
require_once __DIR__ . '/../lib/ourairports/refresh.php';
require_once __DIR__ . '/../lib/ourairports/ingest-airports.php';

if (PHP_SAPI !== 'cli') {
    exit(1);
}

$scriptName = $_SERVER['PHP_SELF'] ?? $_SERVER['SCRIPT_NAME'] ?? '';
if (basename($scriptName) !== basename(__FILE__) && $scriptName !== __FILE__) {
    return;
}

initWorkerTimeout(OURAIRPORTS_BULK_WORKER_TIMEOUT, 'ourairports_bulk');

if (!ourAirportsBulkNeedsFetch()) {
    exit(0);
}

$lock = ourAirportsAcquireExclusiveLock(CACHE_OURAIRPORTS_BULK_LOCK);
if ($lock === false) {
    aviationwx_log('info', 'ourairports bulk fetch skipped, lock held', [], 'app');
    exit(0);
}

try {
    $downloadedAny = false;
    $failures = 0;

    foreach (ourAirportsCsvFileKeys() as $fileKey) {
        if (!ourAirportsFileNeedsFetch($fileKey)) {
            continue;
        }

        $result = ourAirportsDownloadFile($fileKey);
        if (!$result['ok']) {
            $failures++;
            aviationwx_log('warning', 'ourairports bulk fetch failed', [
                'file_key' => $fileKey,
                'http_code' => $result['http_code'] ?? null,
            ], 'app');
            continue;
        }

        if ($result['downloaded']) {
            $downloadedAny = true;
        }
    }

    if (is_readable(ourAirportsCsvPath('airports'))) {
        ingestOurAirportsIdentityFromDisk();
    }

    if (is_readable(ourAirportsCsvPath('airport_frequencies'))) {
        ingestOurAirportsFrequenciesFromDisk();
    }

    aviationwx_log('info', 'ourairports bulk fetch complete', [
        'downloaded_any' => $downloadedAny,
        'failures' => $failures,
    ], 'app');

    exit($failures > 0 && !$downloadedAny ? 1 : 0);
} finally {
    ourAirportsReleaseExclusiveLock($lock, CACHE_OURAIRPORTS_BULK_LOCK);
}
