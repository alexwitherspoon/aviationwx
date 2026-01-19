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
 * - Unified webcam worker handles BOTH push and pull cameras
 * - WebcamScheduleQueue (min-heap) for O(log N) scheduling efficiency
 * - Per-camera refresh_seconds with config hierarchy: camera > airport > global > default
 * - Rate bounds: MIN_WEBCAM_REFRESH (10s) to MAX_WEBCAM_REFRESH (1hr)
 * 
 * Usage:
 *   Start: nohup php scheduler.php > /dev/null 2>&1 &
 *   Stop: kill <pid> (SIGTERM for graceful shutdown)
 */

require_once __DIR__ . '/../lib/config.php';
require_once __DIR__ . '/../lib/logger.php';
require_once __DIR__ . '/../lib/process-pool.php';
require_once __DIR__ . '/../lib/webcam-format-generation.php';
require_once __DIR__ . '/../lib/metrics.php';
require_once __DIR__ . '/../lib/weather-health.php';
require_once __DIR__ . '/../lib/worker-timeout.php';
require_once __DIR__ . '/../lib/webcam-schedule-queue.php';
// Note: variant-health.php flush is handled by metrics_flush_via_http() endpoint

// Lock file location
$lockFile = '/tmp/scheduler.lock';
$lockFp = null;
$running = true;
$loopCount = 0;
$lastConfigReload = 0;
$lastConfigMtime = null; // Track config file mtime to detect changes
$lastConfigSha = null; // Track config file SHA hash to detect ANY content changes
$lastMetricsFlush = 0;
$lastMetricsCleanup = 0;
$lastDailyAggregation = '';
$lastWeeklyAggregation = '';
$lastWeatherHealthUpdate = 0;
$lastStuckWorkerCleanup = 0;
$lastDynamicDnsCheck = 0;
$config = null;
$healthStatus = 'healthy';
$lastError = null;

// Signal handlers for graceful shutdown
pcntl_signal(SIGTERM, function() use (&$running) { $running = false; });
pcntl_signal(SIGINT, function() use (&$running) { $running = false; });

/**
 * Reap zombie child processes
 * 
 * Calls waitpid() with WNOHANG to collect exit status of any child processes
 * that have finished but haven't been waited on. This prevents zombie accumulation.
 * 
 * @return int Number of zombies reaped in this call
 */
function reapZombies(): int {
    $reaped = 0;
    // Keep reaping until no more finished children
    while (($pid = pcntl_waitpid(-1, $status, WNOHANG)) > 0) {
        $reaped++;
    }
    return $reaped;
}

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
 * @param int|null $lastConfigMtime Last config file mtime
 * @return void
 */
function updateLockFile($fp, $pid, $startTime, $loopCount, $healthStatus, $lastError, $config, $lastConfigReload, $lastConfigMtime) {
    $lockData = [
        'pid' => $pid,
        'started' => $startTime,
        'updated' => time(),
        'loop_count' => $loopCount,
        'health' => $healthStatus,
        'last_error' => $lastError,
        'config_airports_count' => isset($config['airports']) ? count($config['airports']) : 0,
        'config_last_reload' => $lastConfigReload,
        'config_last_mtime' => $lastConfigMtime
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

// Set scheduler to normal priority (nice 0) for responsive coordination
// Scheduler is I/O-bound (config reloads, worker management), not CPU-intensive
// Workers run at nice 5, user requests at nice 0 (default)
$schedulerNice = 0;
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
$webcamScheduleQueue = null; // Priority queue for efficient webcam scheduling
$invocationId = aviationwx_get_invocation_id();

// Main scheduler loop
while ($running) {
    $loopCount++;
    
    try {
        $now = time();
        
        // Check if config needs reload (configurable interval)
        $configReloadInterval = getSchedulerConfigReloadInterval();
        if (($now - $lastConfigReload) >= $configReloadInterval) {
            // Check if config file has actually changed by comparing SHA hash
            // SHA hash detects ANY content change (webcam names, settings, etc.), not just structural changes
            $configFilePath = getConfigFilePath();
            $configChanged = false;
            $currentMtime = null;
            $currentSha = null;
            
            if ($configFilePath && file_exists($configFilePath)) {
                // Read file content to compute SHA hash (primary change detection)
                $fileContent = @file_get_contents($configFilePath);
                if ($fileContent !== false) {
                    $currentSha = hash('sha256', $fileContent);
                    // Check if SHA hash changed since last reload (most reliable change detection)
                    if ($lastConfigSha !== null && $currentSha !== $lastConfigSha) {
                        $configChanged = true;
                    }
                }
                // Keep mtime for logging/debugging only
                $currentMtime = filemtime($configFilePath);
            }
            
            $newConfig = loadConfig(false);
            if ($newConfig !== null) {
                // Only clear cache if config actually changed (SHA hash differs)
                // SHA hash is reliable, so we don't need to always clear
                if ($configChanged) {
                    clearConfigCache();
                    
                    // Also clear webcam metadata cache since it includes cam names from config
                    require_once __DIR__ . '/../lib/webcam-metadata.php';
                    clearWebcamMetadataCache();
                    
                    aviationwx_log('info', 'scheduler: config changed (SHA hash), cleared APCu cache', [
                        'old_sha' => $lastConfigSha ? substr($lastConfigSha, 0, 8) : 'none',
                        'new_sha' => substr($currentSha, 0, 8),
                        'mtime' => $currentMtime
                    ], 'app');
                }
                
                $config = $newConfig;
                $lastConfigReload = $now;
                $lastConfigMtime = $currentMtime; // Keep for logging/debugging
                $lastConfigSha = $currentSha;
                
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
                
                // Unified webcam worker handles both push and pull webcams
                $webcamPool = new ProcessPool(
                    $webcamPoolSize,
                    $workerTimeout,
                    'unified-webcam-worker.php',
                    $invocationId
                );
                
                $notamPool = new ProcessPool(
                    $notamPoolSize,
                    $workerTimeout,
                    'fetch-notam.php',
                    $invocationId
                );
                
                // Reinitialize webcam schedule queue with new config
                // This uses a priority queue (min-heap) for O(log N) scheduling
                $webcamScheduleQueue = new WebcamScheduleQueue();
                $webcamScheduleQueue->initialize($config['airports'] ?? [], $config);
                
                aviationwx_log('info', 'scheduler: config reloaded', [
                    'airports_count' => count($config['airports'] ?? []),
                    'webcam_count' => $webcamScheduleQueue->count()
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
            // Initialize SHA hash tracking (primary change detection)
            $configFilePath = getConfigFilePath();
            if ($configFilePath && file_exists($configFilePath)) {
                $lastConfigMtime = filemtime($configFilePath); // Keep for logging/debugging
                // Initialize SHA hash for change detection
                $fileContent = @file_get_contents($configFilePath);
                if ($fileContent !== false) {
                    $lastConfigSha = hash('sha256', $fileContent);
                }
            }
            
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
            
            // Unified webcam worker handles both push and pull webcams
            $webcamPool = new ProcessPool(
                $webcamPoolSize,
                $workerTimeout,
                'unified-webcam-worker.php',
                $invocationId
            );
            
            $notamPool = new ProcessPool(
                $notamPoolSize,
                $workerTimeout,
                'fetch-notam.php',
                $invocationId
            );
            
            // Initialize webcam schedule queue with config
            // This uses a priority queue (min-heap) for O(log N) scheduling
            $webcamScheduleQueue = new WebcamScheduleQueue();
            $webcamScheduleQueue->initialize($config['airports'] ?? [], $config);
            
            aviationwx_log('info', 'scheduler: initialized webcam schedule queue', [
                'webcam_count' => $webcamScheduleQueue->count()
            ], 'app');
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
                $cacheFile = getWeatherCachePath($airportId);
                $cacheAge = file_exists($cacheFile) ? ($now - filemtime($cacheFile)) : PHP_INT_MAX;
                
                // Check if update needed
                if ($cacheAge >= $refreshInterval) {
                    // Non-blocking: add to pool (ProcessPool handles duplicates)
                    $weatherPool->addJob([$airportId]);
                }
            }
        }
        
        // Process webcam updates using priority queue (non-blocking)
        // The WebcamScheduleQueue uses a min-heap for O(log N) scheduling efficiency
        // It handles BOTH push and pull webcams with per-camera refresh_seconds
        if ($webcamPool !== null && $webcamScheduleQueue !== null) {
            // Get all cameras that are due for processing (O(k log N) where k is ready cameras)
            $readyCameras = $webcamScheduleQueue->getReadyCameras();
            
            foreach ($readyCameras as $entry) {
                // Non-blocking: add to pool (ProcessPool handles duplicates)
                // Unified worker handles both push and pull webcams
                // ScheduleEntry has airportId and camIndex properties
                $webcamPool->addJob([$entry->airportId, $entry->camIndex]);
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
            $dummyStats = ['completed' => 0, 'timed_out' => 0, 'failed' => 0, 'skipped' => 0];
            $weatherPool->cleanupFinished($dummyStats);
        }
        if ($webcamPool !== null) {
            $dummyStats = ['completed' => 0, 'timed_out' => 0, 'failed' => 0, 'skipped' => 0];
            $webcamPool->cleanupFinished($dummyStats);
        }
        if ($notamPool !== null) {
            $dummyStats = ['completed' => 0, 'timed_out' => 0, 'failed' => 0, 'skipped' => 0];
            $notamPool->cleanupFinished($dummyStats);
        }
        
        // Reap any zombie child processes that proc_close() may have missed
        // This is a safety net for edge cases where child processes become orphaned
        reapZombies();
        
        // Process metrics tasks (non-blocking)
        // 1. Flush APCu counters to hourly file (every 5 minutes)
        // Note: Uses HTTP endpoint because APCu is process-isolated (CLI vs PHP-FPM)
        if (($now - $lastMetricsFlush) >= METRICS_FLUSH_INTERVAL_SECONDS) {
            if (metrics_flush_via_http()) {
                $lastMetricsFlush = $now;
            }
        }
        
        // 2. Aggregate yesterday's hourly data into daily (once per day, after midnight UTC)
        $yesterdayId = gmdate('Y-m-d', $now - 86400);
        if ($lastDailyAggregation !== $yesterdayId && (int)gmdate('H') >= 1) {
            if (metrics_aggregate_daily($yesterdayId)) {
                $lastDailyAggregation = $yesterdayId;
                aviationwx_log('info', 'scheduler: metrics daily aggregation complete', [
                    'date' => $yesterdayId
                ], 'app');
            }
        }
        
        // 3. Aggregate last week's daily data into weekly (once per week, on Monday after midnight UTC)
        $lastWeekId = gmdate('Y-\WW', $now - (7 * 86400));
        if ($lastWeeklyAggregation !== $lastWeekId && (int)gmdate('N') === 1 && (int)gmdate('H') >= 2) {
            if (metrics_aggregate_weekly($lastWeekId)) {
                $lastWeeklyAggregation = $lastWeekId;
                aviationwx_log('info', 'scheduler: metrics weekly aggregation complete', [
                    'week' => $lastWeekId
                ], 'app');
            }
        }
        
        // 4. Cleanup old metrics files (once per day)
        if (($now - $lastMetricsCleanup) >= 86400) {
            $deletedCount = metrics_cleanup();
            if ($deletedCount > 0) {
                aviationwx_log('info', 'scheduler: metrics cleanup complete', [
                    'deleted_files' => $deletedCount
                ], 'app');
            }
            $lastMetricsCleanup = $now;
        }
        
        // 5. Flush weather health counters to cache file (every 60 seconds)
        // Note: Variant health flush is handled by metrics_flush_via_http() above
        // This pre-computes weather fetch health so status page doesn't check file ages
        if (($now - $lastWeatherHealthUpdate) >= 60) {
            if (weather_health_flush()) {
                $lastWeatherHealthUpdate = $now;
            }
        }
        
        // 6. Clean up stuck worker processes (every 60 seconds)
        // This is a safety net for workers that become stuck despite self-timeout mechanisms
        if (($now - $lastStuckWorkerCleanup) >= 60) {
            $stuckPids = cleanupStaleWorkerHeartbeats();
            if (!empty($stuckPids)) {
                $killed = killStuckWorkers($stuckPids);
                if ($killed > 0) {
                    aviationwx_log('warning', 'scheduler: cleaned up stuck workers', [
                        'killed_count' => $killed,
                        'pids' => $stuckPids
                    ], 'app');
                }
            }
            $lastStuckWorkerCleanup = $now;
        }
        
        // 7. Dynamic DNS check for FTP passive mode (configurable interval)
        // Updates vsftpd pasv_address if the resolved IP has changed (useful for DDNS)
        $dynamicDnsInterval = getDynamicDnsRefreshSeconds();
        if ($dynamicDnsInterval > 0 && ($now - $lastDynamicDnsCheck) >= $dynamicDnsInterval) {
            $updateScript = __DIR__ . '/update-pasv-address.sh';
            if (file_exists($updateScript) && is_executable($updateScript)) {
                $output = [];
                $exitCode = 0;
                exec("$updateScript 2>&1", $output, $exitCode);
                
                if ($exitCode === 0) {
                    // Success or no change needed
                    $outputStr = implode("\n", $output);
                    if (strpos($outputStr, 'change detected') !== false) {
                        aviationwx_log('info', 'scheduler: dynamic DNS updated pasv_address', [
                            'output' => $outputStr
                        ], 'app');
                    }
                } elseif ($exitCode === 2) {
                    // vsftpd not running - not an error
                    aviationwx_log('debug', 'scheduler: dynamic DNS check skipped (vsftpd not running)', [], 'app');
                } else {
                    aviationwx_log('warning', 'scheduler: dynamic DNS check failed', [
                        'exit_code' => $exitCode,
                        'output' => implode("\n", $output)
                    ], 'app');
                }
            }
            $lastDynamicDnsCheck = $now;
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
        updateLockFile($lockFp, $pid, $startTime, $loopCount, $healthStatus, $lastError, $config, $lastConfigReload, $lastConfigMtime);
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

