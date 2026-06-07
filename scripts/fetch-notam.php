<?php
/**
 * NOTAM Data Fetcher
 * Refreshes NOTAM cache for airports via scheduler
 * Supports process pool for parallel execution
 * 
 * Usage:
 *   Worker mode (scheduler): php fetch-notam.php --worker <airport_id>
 *   Direct CLI without --worker: logs and exits (no fetch); use worker mode for real work
 */

require_once __DIR__ . '/../lib/config.php';
require_once __DIR__ . '/../lib/logger.php';
require_once __DIR__ . '/../lib/process-pool.php';
require_once __DIR__ . '/../lib/notam/fetcher.php';
require_once __DIR__ . '/../lib/notam/filter.php';
require_once __DIR__ . '/../lib/notam/cache.php';

// Check for worker mode
$isWorkerMode = false;
$workerAirportId = null;

if (php_sapi_name() === 'cli' && isset($argv) && is_array($argv)) {
    if (isset($argv[1]) && $argv[1] === '--worker' && isset($argv[2])) {
        $isWorkerMode = true;
        $workerAirportId = $argv[2];
    }
}

/**
 * Process single airport NOTAM refresh
 * 
 * Fetches NOTAMs, filters for relevant closures/TFRs, and saves to cache.
 * 
 * @param string $airportId Airport ID (e.g., 'kspb')
 * @param string $invocationId Invocation ID for log correlation
 * @param bool $expectFailures True if failures are expected (commissioning/unlisted)
 * @return bool True on success, false on failure
 */
function processAirportNotam(string $airportId, string $invocationId, bool $expectFailures = false): bool {
    $config = loadConfig();
    if ($config === null || !isset($config['airports'][$airportId])) {
        aviationwx_log('error', 'notam fetch: airport not found', [
            'invocation_id' => $invocationId,
            'airport' => $airportId
        ], 'app');
        return false;
    }

    $airport = $config['airports'][$airportId];
    if (!is_array($airport)) {
        aviationwx_log('error', 'notam fetch: malformed airport config', [
            'invocation_id' => $invocationId,
            'airport' => $airportId
        ], 'app');
        return false;
    }

    // Skip if airport is disabled or in maintenance
    if (!isAirportEnabled($airport) || isAirportInMaintenance($airport)) {
        return true; // Not an error, just skip
    }
    
    try {
        $fetchSucceeded = false;
        $notams = fetchNotamsForAirport($airportId, $airport, $fetchSucceeded);

        $cacheFile = notamCacheFilePath($airportId);
        if (!$fetchSucceeded) {
            notamRecordFetchAttempt($airportId);

            if (is_file($cacheFile)) {
                aviationwx_log('warning', 'notam fetch: NMS queries failed, preserving cache', [
                    'invocation_id' => $invocationId,
                    'airport' => $airportId,
                    'cache_file' => $cacheFile,
                ], 'app');

                return false;
            }

            $logLevel = $expectFailures ? 'info' : 'error';
            aviationwx_log($logLevel, 'notam fetch: NMS queries failed with no cache', [
                'invocation_id' => $invocationId,
                'airport' => $airportId,
            ], 'app');

            return false;
        }
        
        // Build cache data
        $cacheData = [
            'fetched_at' => time(),
            'airport' => $airportId,
            'notams' => $notams,
            'status' => 'success'
        ];
        
        if (!notamWriteCacheFile($cacheFile, $cacheData)) {
            $logLevel = $expectFailures ? 'info' : 'error';
            aviationwx_log($logLevel, 'notam fetch: failed to write cache', [
                'invocation_id' => $invocationId,
                'airport' => $airportId,
                'cache_file' => $cacheFile
            ], 'app');
            return false;
        }

        notamClearFetchAttempt($airportId);
        
        aviationwx_log('info', 'notam fetch: success', [
            'invocation_id' => $invocationId,
            'airport' => $airportId,
            'notams_count' => count($notams)
        ], 'app');
        
        return true;
        
    } catch (Exception $e) {
        $logLevel = $expectFailures ? 'info' : 'error';
        aviationwx_log($logLevel, 'notam fetch: exception', [
            'invocation_id' => $invocationId,
            'airport' => $airportId,
            'error' => $e->getMessage(),
            'trace' => $e->getTraceAsString()
        ], 'app');
        return false;
    }
}

// Worker mode: process single airport
if ($isWorkerMode) {
    // Validate airport ID before any work (fail fast)
    if (empty($workerAirportId) || !validateAirportId($workerAirportId)) {
        aviationwx_log('error', 'notam fetch: invalid airport ID', [
            'airport' => $workerAirportId
        ], 'app');
        exit(1);
    }

    // Load config to check if airport is unlisted (commissioning - expect failures)
    $config = loadConfig(false);
    $expectFailures = false;
    if ($config && isset($config['airports'][$workerAirportId]) && is_array($config['airports'][$workerAirportId])) {
        $expectFailures = isAirportUnlisted($config['airports'][$workerAirportId]);
    }

    // Initialize self-timeout to prevent zombie workers
    // Worker will terminate itself before ProcessPool's hard kill
    require_once __DIR__ . '/../lib/worker-timeout.php';
    initWorkerTimeout(null, "notam_{$workerAirportId}");

    $invocationId = aviationwx_get_invocation_id();
    $success = processAirportNotam($workerAirportId, $invocationId, $expectFailures);
    // Exit 2 = skip when commissioning (process pool does not log "worker failed")
    exit($success ? 0 : ($expectFailures ? 2 : 1));
}

if (!defined('AVIATIONWX_FETCH_NOTAM_LOAD_ONLY')) {
    // Non-worker CLI is a no-op; scheduler enqueues --worker jobs via ProcessPool.
    // AVIATIONWX_FETCH_NOTAM_LOAD_ONLY lets tests include this file without exit().
    aviationwx_log('info', 'notam fetch: script called without --worker flag', [
        'note' => 'Invoke with --worker <airport_id> to refresh cache (scheduler does this via ProcessPool)',
    ], 'app');

    exit(0);
}




