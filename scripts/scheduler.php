<?php
/**
 * Combined Scheduler Daemon
 * Handles weather and webcam updates with sub-minute granularity
 * 
 * Features:
 * - Non-blocking main loop (1s sleep)
 * - ProcessPool integration for workload control
 * - Process lock file with cumulative identity and health
 * - Config reload check (configurable interval, default 60s)
 * - METAR refresh at global 60s interval
 * - Only scheduler errors affect health (worker errors separate)
 * 
 * Usage:
 *   Start: nohup php scheduler.php > /dev/null 2>&1 &
 *   Stop: kill <pid> (SIGTERM for graceful shutdown)
 */

require_once __DIR__ . '/../lib/config.php';
require_once __DIR__ . '/../lib/logger.php';
require_once __DIR__ . '/../lib/process-pool.php';

// Lock file location
$lockFile = '/tmp/scheduler.lock';
$lockFp = null;
$running = true;
$loopCount = 0;
$lastConfigReload = 0;
$config = null;
$healthStatus = 'healthy';
$lastError = null;

// Signal handlers for graceful shutdown
pcntl_signal(SIGTERM, function() use (&$running) { $running = false; });
pcntl_signal(SIGINT, function() use (&$running) { $running = false; });

/**
 * Acquire lock file with exclusive lock
 * 
 * Prevents duplicate scheduler instances from running.
 * Cleans up stale locks (uses FILE_LOCK_STALE_SECONDS constant).
 * 
 * @param string $lockFile Path to lock file
 * @return resource|false File handle on success, false on failure
 */
function acquireLock($lockFile) {
    // Clean up stale locks (use constant for threshold)
    if (file_exists($lockFile)) {
        $lockAge = time() - filemtime($lockFile);
        if ($lockAge > FILE_LOCK_STALE_SECONDS) {
            @unlink($lockFile);
        }
    }
    
    $fp = @fopen($lockFile, 'c+');
    if (!$fp) {
        aviationwx_log('error', 'scheduler: cannot create lock file', [
            'lock_file' => $lockFile
        ], 'app', true);
        exit(1);
    }
    
    if (!@flock($fp, LOCK_EX | LOCK_NB)) {
        @fclose($fp);
        aviationwx_log('error', 'scheduler: another instance running', [
            'lock_file' => $lockFile
        ], 'app', true);
        exit(1);
    }
    
    return $fp;
}

/**
 * Update lock file with cumulative identity and health status
 * 
 * Writes scheduler state to lock file for health check inspection.
 * Contains PID, start time, health status, loop count, and config info.
 * 
 * @param resource $fp Lock file handle
 * @param int $pid Process ID
 * @param int $startTime Start timestamp
 * @param int $loopCount Loop iteration count
 * @param string $healthStatus Health status ('healthy' or 'unhealthy')
 * @param string|null $lastError Last error message (if any)
 * @param array|null $config Config array
 * @param int $lastConfigReload Last config reload timestamp
 * @return void
 */
function updateLockFile($fp, $pid, $startTime, $loopCount, $healthStatus, $lastError, $config, $lastConfigReload) {
    $lockData = [
        'pid' => $pid,
        'started' => $startTime,
        'updated' => time(),
        'loop_count' => $loopCount,
        'health' => $healthStatus,
        'last_error' => $lastError,
        'config_airports_count' => isset($config['airports']) ? count($config['airports']) : 0,
        'config_last_reload' => $lastConfigReload
    ];
    
    @ftruncate($fp, 0);
    @fseek($fp, 0);
    @fwrite($fp, json_encode($lockData));
    @fflush($fp);
}

// Acquire lock file
$lockFp = acquireLock($lockFile);
$pid = getmypid();
$startTime = time();

// Set scheduler to low priority (nice 10) to avoid blocking user requests
// Workers run at nice 5, user requests at nice 0 (default)
$schedulerNice = 10;
if (function_exists('proc_nice')) {
    $niceResult = @proc_nice($schedulerNice);
    if ($niceResult === false) {
        aviationwx_log('warning', 'scheduler: failed to set nice level', [
            'nice' => $schedulerNice
        ], 'app');
    }
}

aviationwx_log('info', 'scheduler: started', [
    'pid' => $pid,
    'start_time' => $startTime,
    'nice' => $schedulerNice
], 'app');

// Register shutdown function for cleanup
register_shutdown_function(function() use ($lockFp, $lockFile, &$loopCount, $startTime) {
    if ($lockFp) {
        @flock($lockFp, LOCK_UN);
        @fclose($lockFp);
    }
    if (file_exists($lockFile)) {
        @unlink($lockFile);
    }
    
    aviationwx_log('info', 'scheduler: shutdown', [
        'pid' => getmypid(),
        'loop_count' => $loopCount,
        'uptime' => time() - $startTime
    ], 'app');
});

// Initialize ProcessPools (will be created on first config load)
$weatherPool = null;
$webcamPool = null;
$notamPool = null;
$invocationId = aviationwx_get_invocation_id();

// Main scheduler loop
while ($running) {
    $loopCount++;
    
    try {
        $now = time();
        
        // Check if config needs reload (configurable interval)
        $configReloadInterval = getSchedulerConfigReloadInterval();
        if (($now - $lastConfigReload) >= $configReloadInterval) {
            $newConfig = loadConfig(false);
            if ($newConfig !== null) {
                $config = $newConfig;
                $lastConfigReload = $now;
                
                // Reinitialize ProcessPools with new config
                $weatherPoolSize = getWeatherWorkerPoolSize();
                $webcamPoolSize = getWebcamWorkerPoolSize();
                $notamPoolSize = getNotamWorkerPoolSize();
                $workerTimeout = getWorkerTimeout();
                
                $weatherPool = new ProcessPool(
                    $weatherPoolSize,
                    $workerTimeout,
                    'fetch-weather.php',
                    $invocationId
                );
                
                $webcamPool = new ProcessPool(
                    $webcamPoolSize,
                    $workerTimeout,
                    'fetch-webcam.php',
                    $invocationId
                );
                
                $notamPool = new ProcessPool(
                    $notamPoolSize,
                    $workerTimeout,
                    'fetch-notam.php',
                    $invocationId
                );
                
                aviationwx_log('info', 'scheduler: config reloaded', [
                    'airports_count' => count($config['airports'] ?? [])
                ], 'app');
            }
        }
        
        // Ensure config is loaded (first time or after error)
        if ($config === null) {
            $config = loadConfig(false);
            if ($config === null) {
                throw new Exception('Failed to load config');
            }
            $lastConfigReload = $now;
            
            // Initialize ProcessPools on first load
            $weatherPoolSize = getWeatherWorkerPoolSize();
            $webcamPoolSize = getWebcamWorkerPoolSize();
            $notamPoolSize = getNotamWorkerPoolSize();
            $workerTimeout = getWorkerTimeout();
            
            $weatherPool = new ProcessPool(
                $weatherPoolSize,
                $workerTimeout,
                'fetch-weather.php',
                $invocationId
            );
            
            $webcamPool = new ProcessPool(
                $webcamPoolSize,
                $workerTimeout,
                'fetch-webcam.php',
                $invocationId
            );
            
            $notamPool = new ProcessPool(
                $notamPoolSize,
                $workerTimeout,
                'fetch-notam.php',
                $invocationId
            );
        }
        
        // Process weather updates (non-blocking)
        // Note: METAR refresh is handled as part of weather updates via the weather API endpoint
        // The global metar_refresh_seconds config is respected by the weather API internally
        if ($weatherPool !== null && isset($config['airports'])) {
            foreach ($config['airports'] as $airportId => $airport) {
                if (!isAirportEnabled($airport)) {
                    continue;
                }
                
                // Get refresh interval (per-airport, then global default, then function default)
                $refreshInterval = isset($airport['weather_refresh_seconds'])
                    ? intval($airport['weather_refresh_seconds'])
                    : getDefaultWeatherRefresh();
                
                // Enforce minimum (configurable)
                $minRefresh = getMinimumRefreshInterval();
                $refreshInterval = max($minRefresh, $refreshInterval);
                
                // Check cache age (stateless - use filemtime)
                $cacheFile = __DIR__ . "/../cache/weather_{$airportId}.json";
                $cacheAge = file_exists($cacheFile) ? ($now - filemtime($cacheFile)) : PHP_INT_MAX;
                
                // Check if update needed
                if ($cacheAge >= $refreshInterval) {
                    // Non-blocking: add to pool (ProcessPool handles duplicates)
                    $weatherPool->addJob([$airportId]);
                }
            }
        }
        
        // Process webcam updates (non-blocking)
        if ($webcamPool !== null && isset($config['airports'])) {
            foreach ($config['airports'] as $airportId => $airport) {
                if (!isAirportEnabled($airport)) {
                    continue;
                }
                
                if (!isset($airport['webcams']) || !is_array($airport['webcams'])) {
                    continue;
                }
                
                // Get refresh interval (per-airport, then global default, then function default)
                $refreshInterval = isset($airport['webcam_refresh_seconds'])
                    ? intval($airport['webcam_refresh_seconds'])
                    : getDefaultWebcamRefresh();
                
                // Enforce minimum (configurable)
                $minRefresh = getMinimumRefreshInterval();
                $refreshInterval = max($minRefresh, $refreshInterval);
                
                foreach ($airport['webcams'] as $index => $cam) {
                    // Skip push webcams (handled by process-push-webcams.php)
                    $isPush = (isset($cam['type']) && $cam['type'] === 'push') || isset($cam['push_config']);
                    if ($isPush) {
                        continue;
                    }
                    
                    // Check cache age (stateless - use filemtime)
                    $cacheFile = __DIR__ . "/../cache/webcams/{$airportId}_{$index}.jpg";
                    $cacheAge = file_exists($cacheFile) ? ($now - filemtime($cacheFile)) : PHP_INT_MAX;
                    
                    // Check if update needed
                    if ($cacheAge >= $refreshInterval) {
                        // Non-blocking: add to pool (ProcessPool handles duplicates)
                        $webcamPool->addJob([$airportId, $index]);
                    }
                }
            }
        }
        
        // Process NOTAM updates (non-blocking)
        if ($notamPool !== null && isset($config['airports'])) {
            foreach ($config['airports'] as $airportId => $airport) {
                if (!isAirportEnabled($airport)) {
                    continue;
                }
                
                // Get refresh interval
                $refreshInterval = getNotamRefreshSeconds();
                
                // Enforce minimum (configurable)
                $minRefresh = getMinimumRefreshInterval();
                $refreshInterval = max($minRefresh, $refreshInterval);
                
                // Check cache age (stateless - use filemtime)
                $cacheFile = __DIR__ . "/../cache/notam/{$airportId}.json";
                $cacheAge = file_exists($cacheFile) ? ($now - filemtime($cacheFile)) : PHP_INT_MAX;
                
                // Check if update needed
                if ($cacheAge >= $refreshInterval) {
                    // Non-blocking: add to pool (ProcessPool handles duplicates)
                    $notamPool->addJob([$airportId]);
                }
            }
        }
        
        // Cleanup finished workers (non-blocking)
        if ($weatherPool !== null) {
            $dummyStats = ['completed' => 0, 'timed_out' => 0, 'failed' => 0];
            $weatherPool->cleanupFinished($dummyStats);
        }
        if ($webcamPool !== null) {
            $dummyStats = ['completed' => 0, 'timed_out' => 0, 'failed' => 0];
            $webcamPool->cleanupFinished($dummyStats);
        }
        if ($notamPool !== null) {
            $dummyStats = ['completed' => 0, 'timed_out' => 0, 'failed' => 0];
            $notamPool->cleanupFinished($dummyStats);
        }
        
        // Update health status (only scheduler errors affect this)
        $healthStatus = 'healthy';
        $lastError = null;
        
    } catch (Exception $e) {
        // Scheduler errors affect health
        $healthStatus = 'unhealthy';
        $lastError = $e->getMessage();
        
        aviationwx_log('error', 'scheduler: error in main loop', [
            'error' => $e->getMessage(),
            'trace' => $e->getTraceAsString()
        ], 'app', true);
    } catch (Throwable $e) {
        // Catch any other throwables (PHP 7+)
        $healthStatus = 'unhealthy';
        $lastError = $e->getMessage();
        
        aviationwx_log('error', 'scheduler: throwable in main loop', [
            'error' => $e->getMessage(),
            'trace' => $e->getTraceAsString()
        ], 'app', true);
    }
    
    // Update lock file (non-blocking write)
    if ($lockFp) {
        updateLockFile($lockFp, $pid, $startTime, $loopCount, $healthStatus, $lastError, $config, $lastConfigReload);
    }
    
    // Dispatch signals
    pcntl_signal_dispatch();
    
    // Sleep 1 second (non-blocking)
    sleep(1);
}

// Cleanup
aviationwx_log('info', 'scheduler: shutting down', [
    'pid' => $pid,
    'loop_count' => $loopCount,
    'uptime' => time() - $startTime
], 'app');

