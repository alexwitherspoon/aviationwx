<?php
/**
 * NOTAM Data Fetcher
 * Refreshes NOTAM cache for airports via scheduler
 * Supports process pool for parallel execution
 * 
 * Usage:
 *   Normal mode: php fetch-notam.php
 *   Worker mode:  php fetch-notam.php --worker <airport_id>
 */

require_once __DIR__ . '/../lib/config.php';
require_once __DIR__ . '/../lib/logger.php';
require_once __DIR__ . '/../lib/process-pool.php';
require_once __DIR__ . '/../lib/notam/fetcher.php';
require_once __DIR__ . '/../lib/notam/filter.php';

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
 * @return bool True on success, false on failure
 */
function processAirportNotam(string $airportId, string $invocationId): bool {
    $config = loadConfig();
    if ($config === null || !isset($config['airports'][$airportId])) {
        aviationwx_log('error', 'notam fetch: airport not found', [
            'invocation_id' => $invocationId,
            'airport' => $airportId
        ], 'app');
        return false;
    }
    
    $airport = $config['airports'][$airportId];
    
    // Skip if airport is disabled or in maintenance
    if (!isAirportEnabled($airport) || isAirportInMaintenance($airport)) {
        return true; // Not an error, just skip
    }
    
    try {
        // Fetch and filter NOTAMs
        $notams = fetchNotamsForAirport($airportId, $airport);
        
        // Build cache data
        $cacheData = [
            'fetched_at' => time(),
            'airport' => $airportId,
            'notams' => $notams,
            'status' => 'success'
        ];
        
        // Save to cache
        $cacheDir = __DIR__ . '/../cache/notam';
        if (!is_dir($cacheDir)) {
            @mkdir($cacheDir, 0755, true);
        }
        
        $cacheFile = $cacheDir . '/' . $airportId . '.json';
        $json = json_encode($cacheData, JSON_PRETTY_PRINT);
        
        if (@file_put_contents($cacheFile, $json) === false) {
            aviationwx_log('error', 'notam fetch: failed to write cache', [
                'invocation_id' => $invocationId,
                'airport' => $airportId,
                'cache_file' => $cacheFile
            ], 'app');
            return false;
        }
        
        aviationwx_log('info', 'notam fetch: success', [
            'invocation_id' => $invocationId,
            'airport' => $airportId,
            'notams_count' => count($notams)
        ], 'app');
        
        return true;
        
    } catch (Exception $e) {
        aviationwx_log('error', 'notam fetch: exception', [
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
    $invocationId = aviationwx_get_invocation_id();
    $success = processAirportNotam($workerAirportId, $invocationId);
    exit($success ? 0 : 1);
}

// Normal mode: use process pool (called by scheduler)
// This script is primarily used by scheduler.php, which handles the process pool
// For standalone execution, we could add pool logic here, but scheduler handles it

aviationwx_log('info', 'notam fetch: script called without --worker flag', [
    'note' => 'This script is typically called by scheduler.php with --worker flag'
], 'app');

exit(0);



