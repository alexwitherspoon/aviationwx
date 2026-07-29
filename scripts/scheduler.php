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
 * - METAR: bulk gzip refresh on `METAR_BULK_REFRESH_INTERVAL_SECONDS`, plus per-airport weather refresh (see `metar_refresh_seconds`)
 * - NWS: /points cache warmup on `NWS_POINTS_REFRESH_INTERVAL_SECONDS` for airports with an NWS source (see `refresh-nws-points.php`)
 * - Only scheduler errors affect health (worker errors separate)
 * - Unified webcam worker handles BOTH push and pull cameras
 * - WebcamScheduleQueue (min-heap) for O(log N) scheduling efficiency
 * - Per-camera refresh_seconds with config hierarchy: camera > airport > global > default
 * - Rate bounds: MIN_WEBCAM_REFRESH (10s) to MAX_WEBCAM_REFRESH (1hr)
 * - Runways cache refresh (background worker; OurAirports daily probe + bulk fetch)
 * - Airport country resolution aggregate (first-loop evaluation; then hourly checks; worker when missing, stale SHA, invalid, or aggregate older than policy max age)
 * 
 * Usage:
 *   Start: nohup php scheduler.php > /dev/null 2>&1 &
 *   Stop: kill <pid> (SIGTERM for graceful shutdown)
 */

require_once __DIR__ . '/../lib/config.php';
require_once __DIR__ . '/../lib/cache-paths.php';
require_once __DIR__ . '/../lib/logger.php';
require_once __DIR__ . '/../lib/process-pool.php';
require_once __DIR__ . '/../lib/webcam-format-generation.php';
require_once __DIR__ . '/../lib/worker-timeout.php';
require_once __DIR__ . '/../lib/webcam-schedule-queue.php';
require_once __DIR__ . '/../lib/nasr/cache.php';
require_once __DIR__ . '/../lib/nasr/frequencies-cache.php';
require_once __DIR__ . '/../lib/nasr/workers.php';
require_once __DIR__ . '/../lib/runways.php';
require_once __DIR__ . '/../lib/airport-country-resolution-merge.php';
require_once __DIR__ . '/../lib/weather/utils.php';
require_once __DIR__ . '/../lib/scheduler-daemon-lock.php';
require_once __DIR__ . '/../lib/deploy-drain.php';
require_once __DIR__ . '/../lib/scheduler-work-registry.php';
// Metrics spill merge, variant-health flush, and related housekeeping: drain-gated background workers.

// Lock file location
$lockFile = '/tmp/scheduler.lock';
$lockFp = null;
$running = true;
$loopCount = 0;
$lastConfigReload = 0;
$lastConfigMtime = null; // Track config file mtime to detect changes
$lastConfigSha = null; // Track config file SHA hash to detect ANY content changes
$lastMetricsSpillMerge = 0;
$lastVariantHealthHttpFlush = 0;
$lastMetricsHealthCheck = 0; // Separate timestamp for health checks
$lastMetricsCleanup = 0;
$lastDailySpawnAttempt = 0;
$lastWeeklySpawnAttempt = 0;
$lastWeatherHealthUpdate = 0;
$lastStuckWorkerCleanup = 0;
$lastRunwaysFetch = 0;
$lastOurAirportsProbe = 0;
$lastOurAirportsBulkFetch = 0;
$lastNasrAptFetch = 0;
$lastNasrFrqFetch = 0;
$lastCountryResolutionSchedulerCheck = 0;
$countryResolutionSchedulerStartupEval = false;
$lastCloudflareAnalyticsFetch = 0;
$lastStatusPageCachesFetch = 0;
$lastOperationsSnapshotBuild = 0;
$lastMetarBulkRefresh = 0;
$lastNwsPointsRefresh = 0;
$lastFaaTfrWfsRefresh = 0;
$lastNwsPointsMissingLog = 0;
$deployDrainAnnounced = false;
$runwaysFetchOnStartupDone = false;
$nasrAptFetchOnStartupDone = false;
$nasrFrqFetchOnStartupDone = false;
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
 * Prevents duplicate scheduler instances from running. Does not unlink stale paths: that
 * overlapped with live fds and allowed a second daemon to flock a new inode (see scheduler_lock_acquire_exclusive_nb()).
 *
 * @param string $lockFile Path to lock file
 * @return resource File handle on success (caller must hold until exit)
 */
function acquireLock(string $lockFile) {
    $result = scheduler_lock_acquire_exclusive_nb($lockFile);
    if (($result['ok'] ?? false) === true) {
        return $result['fp'];
    }

    $reason = $result['reason'] ?? 'unknown';
    if ($reason === 'fopen') {
        aviationwx_log('error', 'scheduler: cannot create lock file', [
            'lock_file' => $lockFile
        ], 'app', true);
    } else {
        aviationwx_log('error', 'scheduler: another instance running', [
            'lock_file' => $lockFile
        ], 'app', true);
    }

    exit(1);
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

// Refresh lock metadata immediately so cron health checks do not treat pre-restart mtime as stale
// while the first loop (runways fetch, pools, etc.) can exceed the health check lock-age threshold.
if ($lockFp) {
    updateLockFile($lockFp, $pid, $startTime, 0, 'healthy', null, null, 0, null);
}

// Register shutdown function for cleanup
register_shutdown_function(function() use ($lockFp, $lockFile, &$loopCount, $startTime) {
    if ($lockFp) {
        $fpStat = @fstat($lockFp);
        @flock($lockFp, LOCK_UN);
        @fclose($lockFp);
        $pathStat = @stat($lockFile);
        if (is_array($fpStat) && is_array($pathStat)
            && ($fpStat['dev'] ?? null) === ($pathStat['dev'] ?? null)
            && ($fpStat['ino'] ?? null) === ($pathStat['ino'] ?? null)
        ) {
            @unlink($lockFile);
        }
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
$stationPowerPool = null;
$workRegistry = new SchedulerWorkRegistry();
$webcamScheduleQueue = null; // Priority queue for efficient webcam scheduling
$invocationId = aviationwx_get_invocation_id();


// Drain-gated work: register via setPool / registerEnqueueTick (run only when allow_new_work).
$workRegistry->registerEnqueueTick('metar_bulk', function (int $now) use (&$lastMetarBulkRefresh): void {
    // METAR bulk gzip download/ingest runs in a background worker (see refresh-metar-bulk.php).
    if (($now - $lastMetarBulkRefresh) >= METAR_BULK_REFRESH_INTERVAL_SECONDS) {
        $metarBulkScript = __DIR__ . '/refresh-metar-bulk.php';
        if (file_exists($metarBulkScript)) {
            $phpBin = PHP_BINARY !== '' && PHP_BINARY !== false ? PHP_BINARY : 'php';
            exec(escapeshellarg($phpBin) . ' ' . escapeshellarg($metarBulkScript) . ' > /dev/null 2>&1 &');
            reapZombies();
        }
        $lastMetarBulkRefresh = $now;
    }
});

$workRegistry->registerEnqueueTick('faa_tfr_wfs', function (int $now) use (&$lastFaaTfrWfsRefresh): void {
    // National FAA TFR WFS → unified airspace store (see fetch-faa-tfr-wfs.php).
    if (($now - $lastFaaTfrWfsRefresh) >= FAA_TFR_WFS_REFRESH_INTERVAL_SECONDS) {
        $wfsScript = __DIR__ . '/fetch-faa-tfr-wfs.php';
        if (file_exists($wfsScript)) {
            $phpBin = PHP_BINARY !== '' && PHP_BINARY !== false ? PHP_BINARY : 'php';
            exec(escapeshellarg($phpBin) . ' ' . escapeshellarg($wfsScript) . ' > /dev/null 2>&1 &');
            reapZombies();
        }
        $lastFaaTfrWfsRefresh = $now;
    }
});

$workRegistry->registerEnqueueTick('nws_points', function (int $now) use (&$lastNwsPointsRefresh, &$lastNwsPointsMissingLog): void {
    // NWS /points metadata cache warmup (stale entries only; see refresh-nws-points.php).
    if (($now - $lastNwsPointsRefresh) >= NWS_POINTS_REFRESH_INTERVAL_SECONDS) {
        $nwsPointsScript = __DIR__ . '/refresh-nws-points.php';
        if (file_exists($nwsPointsScript)) {
            $phpBin = PHP_BINARY !== '' && PHP_BINARY !== false ? PHP_BINARY : 'php';
            exec(escapeshellarg($phpBin) . ' ' . escapeshellarg($nwsPointsScript) . ' > /dev/null 2>&1 &');
            reapZombies();
            $lastNwsPointsRefresh = $now;
        } elseif (($now - $lastNwsPointsMissingLog) >= 300) {
            aviationwx_log('warning', 'scheduler: refresh-nws-points.php missing', [
                'path' => $nwsPointsScript,
            ], 'app');
            $lastNwsPointsMissingLog = $now;
        }
    }
});

$workRegistry->registerEnqueueTick('weather', function (int $now) use (&$weatherPool, &$config): void {
    if ($weatherPool !== null && isset($config['airports'])) {
        foreach ($config['airports'] as $airportId => $airport) {
            if (!is_array($airport) || !isAirportEnabled($airport)) {
                continue;
            }
            // Match api/weather.php: no weather_sources means nothing to refresh.
            if (!hasWeatherSources($airport)) {
                continue;
            }
            $refreshInterval = isset($airport['weather_refresh_seconds'])
                ? intval($airport['weather_refresh_seconds'])
                : getDefaultWeatherRefresh();
            $refreshInterval = max(getMinimumRefreshInterval(), $refreshInterval);
            $cacheFile = getWeatherCachePath($airportId);
            $cacheAge = file_exists($cacheFile) ? ($now - filemtime($cacheFile)) : PHP_INT_MAX;
            if ($cacheAge >= $refreshInterval) {
                $weatherPool->addJob([$airportId]);
            }
        }
    }
});

$workRegistry->registerEnqueueTick('webcam', function (int $now) use (&$webcamPool, &$webcamScheduleQueue): void {
    // Min-heap schedule: both push and pull cams with per-camera refresh_seconds.
    if ($webcamPool !== null && $webcamScheduleQueue !== null) {
        foreach ($webcamScheduleQueue->getReadyCameras() as $entry) {
            $webcamPool->addJob([$entry->airportId, $entry->camIndex]);
        }
    }
});

$workRegistry->registerEnqueueTick('notam', function (int $now) use (&$notamPool, &$config): void {
    // Process NOTAM updates (non-blocking, spread across time - lower urgency than weather/webcams)
    if ($notamPool !== null && isset($config['airports'])) {
        require_once __DIR__ . '/../lib/notam/scheduler-queue.php';

        $refreshInterval = getNotamRefreshSeconds();
        $minRefresh = getMinimumRefreshInterval();
        $refreshInterval = max($minRefresh, $refreshInterval);

        $notamCandidateIds = [];
        foreach ($config['airports'] as $airportId => $airport) {
            if (!is_array($airport) || !isAirportEnabled($airport) || isAirportInMaintenance($airport)) {
                continue;
            }
            $notamCandidateIds[] = (string) $airportId;
        }

        $notamToEnqueue = notamSelectAirportsToEnqueue(
            $notamCandidateIds,
            $refreshInterval,
            notamSchedulerMaxEnqueuePerLoop(),
            $now
        );
        foreach ($notamToEnqueue as $notamAirportId) {
            $notamPool->addJob([$notamAirportId]);
        }
    }
});

$workRegistry->registerEnqueueTick('station_power', function (int $now) use (&$stationPowerPool, &$config): void {
    // Station power updates (non-blocking)
    if ($stationPowerPool !== null && isset($config['airports'])) {
        foreach ($config['airports'] as $airportId => $airport) {
            if (!is_array($airport) || !shouldFetchStationPowerForAirport($airport)) {
                continue;
            }
            $refreshInterval = STATION_POWER_FETCH_INTERVAL_SECONDS;
            $minRefresh = getMinimumRefreshInterval();
            $refreshInterval = max($minRefresh, $refreshInterval);
            $stagger = crc32((string) $airportId) % max(1, (int) (STATION_POWER_FETCH_INTERVAL_SECONDS / 10));
            $cacheFile = getStationPowerCachePath($airportId);
            $cacheAge = file_exists($cacheFile) ? ($now - filemtime($cacheFile)) : PHP_INT_MAX;
            if ($cacheAge >= ($refreshInterval + $stagger)) {
                $stationPowerPool->addJob([$airportId]);
            }
        }
    }
});

$workRegistry->registerEnqueueTick('reference_data', function (int $now) use (&$lastOurAirportsProbe, &$lastOurAirportsBulkFetch, &$lastRunwaysFetch, &$runwaysFetchOnStartupDone, &$lastNasrAptFetch, &$nasrAptFetchOnStartupDone, &$lastNasrFrqFetch, &$nasrFrqFetchOnStartupDone, &$lastCountryResolutionSchedulerCheck, &$countryResolutionSchedulerStartupEval, &$lastConfigSha, &$config): void {
    // 8. OurAirports upstream probe (daily; background worker only)
    if (($now - $lastOurAirportsProbe) >= OURAIRPORTS_PROBE_INTERVAL && ourAirportsProbeWorkerShouldRun()) {
        $probeScript = __DIR__ . '/probe-ourairports.php';
        if (file_exists($probeScript)) {
            $phpBin = PHP_BINARY !== '' && PHP_BINARY !== false ? PHP_BINARY : 'php';
            exec(escapeshellarg($phpBin) . ' ' . escapeshellarg($probeScript) . ' > /dev/null 2>&1 &');
            reapZombies();
            aviationwx_log('info', 'scheduler: ourairports probe started', [], 'app');
        } else {
            aviationwx_log('warning', 'scheduler: probe-ourairports.php missing', [
            'path' => $probeScript,
            ], 'app');
        }
        $lastOurAirportsProbe = $now;
    }

    // 8-i. OurAirports bulk CSV fetch when policy requires
    if (($now - $lastOurAirportsBulkFetch) >= OURAIRPORTS_BULK_FETCH_CHECK_INTERVAL && ourAirportsBulkWorkerShouldRun()) {
        $bulkScript = __DIR__ . '/fetch-ourairports-bulk.php';
        if (file_exists($bulkScript)) {
            $phpBin = PHP_BINARY !== '' && PHP_BINARY !== false ? PHP_BINARY : 'php';
            exec(escapeshellarg($phpBin) . ' ' . escapeshellarg($bulkScript) . ' > /dev/null 2>&1 &');
            reapZombies();
            aviationwx_log('info', 'scheduler: ourairports bulk fetch started', [], 'app');
        } else {
            aviationwx_log('warning', 'scheduler: fetch-ourairports-bulk.php missing', [
            'path' => $bulkScript,
            ], 'app');
        }
        $lastOurAirportsBulkFetch = $now;
    }

    // 8-ii. Runways merge fetch (background; reads OurAirports CSVs from disk)
    $runwaysScript = __DIR__ . '/fetch-runways.php';
    if (!$runwaysFetchOnStartupDone) {
        if (runwaysMergeWorkerShouldRun()) {
            if (file_exists($runwaysScript)) {
                $phpBin = PHP_BINARY !== '' && PHP_BINARY !== false ? PHP_BINARY : 'php';
                exec(escapeshellarg($phpBin) . ' ' . escapeshellarg($runwaysScript) . ' > /dev/null 2>&1 &');
                reapZombies();
                aviationwx_log('info', 'scheduler: runways fetch started (startup)', [], 'app');
            } else {
                aviationwx_log('warning', 'scheduler: fetch-runways.php missing', [
                'path' => $runwaysScript,
                ], 'app');
            }
        }
        $runwaysFetchOnStartupDone = true;
        $lastRunwaysFetch = $now;
    } elseif (($now - $lastRunwaysFetch) >= OURAIRPORTS_BULK_FETCH_CHECK_INTERVAL && runwaysMergeWorkerShouldRun()) {
        if (file_exists($runwaysScript)) {
            $phpBin = PHP_BINARY !== '' && PHP_BINARY !== false ? PHP_BINARY : 'php';
            exec(escapeshellarg($phpBin) . ' ' . escapeshellarg($runwaysScript) . ' > /dev/null 2>&1 &');
            reapZombies();
            aviationwx_log('info', 'scheduler: runways fetch started', [], 'app');
        } else {
            aviationwx_log('warning', 'scheduler: fetch-runways.php missing', [
            'path' => $runwaysScript,
            ], 'app');
        }
        $lastRunwaysFetch = $now;
    }

    // 8a. NASR APT cache fetch (weekly check; startup if missing)
    if (!$nasrAptFetchOnStartupDone) {
        if (nasrAptWorkerShouldRun()) {
            $nasrScript = __DIR__ . '/fetch-nasr-apt.php';
            if (file_exists($nasrScript)) {
                $phpBin = PHP_BINARY !== '' && PHP_BINARY !== false ? PHP_BINARY : 'php';
                exec(escapeshellarg($phpBin) . ' ' . escapeshellarg($nasrScript) . ' > /dev/null 2>&1 &');
                reapZombies();
                aviationwx_log('info', 'scheduler: nasr apt fetch started (startup)', [], 'app');
            } else {
                aviationwx_log('warning', 'scheduler: fetch-nasr-apt.php missing', [
                'path' => $nasrScript,
                ], 'app');
            }
            $lastNasrAptFetch = $now;
        }
        $nasrAptFetchOnStartupDone = true;
    } elseif (($now - $lastNasrAptFetch) >= NASR_FETCH_CHECK_INTERVAL && nasrAptWorkerShouldRun()) {
        $nasrScript = __DIR__ . '/fetch-nasr-apt.php';
        if (file_exists($nasrScript)) {
            $phpBin = PHP_BINARY !== '' && PHP_BINARY !== false ? PHP_BINARY : 'php';
            exec(escapeshellarg($phpBin) . ' ' . escapeshellarg($nasrScript) . ' > /dev/null 2>&1 &');
            reapZombies();
            aviationwx_log('info', 'scheduler: nasr apt fetch started (weekly)', [], 'app');
        } else {
            aviationwx_log('warning', 'scheduler: fetch-nasr-apt.php missing', [
            'path' => $nasrScript,
            ], 'app');
        }
        $lastNasrAptFetch = $now;
    }

    // 8a-ii. NASR FRQ cache fetch (weekly check; startup if missing)
    if (!$nasrFrqFetchOnStartupDone) {
        if (nasrFrqWorkerShouldRun()) {
            $nasrFrqScript = __DIR__ . '/fetch-nasr-frq.php';
            if (file_exists($nasrFrqScript)) {
                $phpBin = PHP_BINARY !== '' && PHP_BINARY !== false ? PHP_BINARY : 'php';
                exec(escapeshellarg($phpBin) . ' ' . escapeshellarg($nasrFrqScript) . ' > /dev/null 2>&1 &');
                reapZombies();
                aviationwx_log('info', 'scheduler: nasr frq fetch started (startup)', [], 'app');
            } else {
                aviationwx_log('warning', 'scheduler: fetch-nasr-frq.php missing', [
                'path' => $nasrFrqScript,
                ], 'app');
            }
            $lastNasrFrqFetch = $now;
        }
        $nasrFrqFetchOnStartupDone = true;
    } elseif (($now - $lastNasrFrqFetch) >= NASR_FETCH_CHECK_INTERVAL && nasrFrqWorkerShouldRun()) {
        $nasrFrqScript = __DIR__ . '/fetch-nasr-frq.php';
        if (file_exists($nasrFrqScript)) {
            $phpBin = PHP_BINARY !== '' && PHP_BINARY !== false ? PHP_BINARY : 'php';
            exec(escapeshellarg($phpBin) . ' ' . escapeshellarg($nasrFrqScript) . ' > /dev/null 2>&1 &');
            reapZombies();
            aviationwx_log('info', 'scheduler: nasr frq fetch started (weekly)', [], 'app');
        } else {
            aviationwx_log('warning', 'scheduler: fetch-nasr-frq.php missing', [
            'path' => $nasrFrqScript,
            ], 'app');
        }
        $lastNasrFrqFetch = $now;
    }

    // 8b. Airport country resolution aggregate (first loop immediately; then at most hourly)
    $countryResolutionScript = __DIR__ . '/refresh-airport-country-resolution.php';
    $countryResolutionEvalDue = !$countryResolutionSchedulerStartupEval
    || (($now - $lastCountryResolutionSchedulerCheck) >= COUNTRY_RESOLUTION_SCHEDULER_CHECK_INTERVAL);
    if ($countryResolutionEvalDue
    && file_exists($countryResolutionScript)
    && $config !== null
    && $lastConfigSha !== null) {
        $lastCountryResolutionSchedulerCheck = $now;
        $countryResolutionSchedulerStartupEval = true;
        $cfgPath = getConfigFilePath();
        if ($cfgPath !== null && is_readable($cfgPath)) {
            $countryNeedsRefresh = countryResolutionAggregateShouldRefresh($cfgPath, $lastConfigSha);
            if ($countryNeedsRefresh) {
                $phpBin = PHP_BINARY !== '' && PHP_BINARY !== false ? PHP_BINARY : 'php';
                exec(escapeshellarg($phpBin) . ' ' . escapeshellarg($countryResolutionScript) . ' > /dev/null 2>&1 &');
                reapZombies();
                aviationwx_log('info', 'scheduler: airport country resolution refresh spawned', [], 'app');
            }
        }
    }
});

$workRegistry->registerEnqueueTick('status_prewarm', function (int $now) use (&$lastCloudflareAnalyticsFetch, &$lastStatusPageCachesFetch, &$lastOperationsSnapshotBuild): void {
    $phpBin = PHP_BINARY !== '' && PHP_BINARY !== false ? PHP_BINARY : 'php';

    // 9. Cloudflare analytics pre-warm (every 15 min, non-blocking)
    // Runs worker in background; page loads read from file cache
    if (($now - $lastCloudflareAnalyticsFetch) >= CLOUDFLARE_ANALYTICS_FETCH_INTERVAL) {
        $cloudflareScript = __DIR__ . '/fetch-cloudflare-analytics.php';
        if (file_exists($cloudflareScript)) {
            exec(escapeshellarg($phpBin) . ' ' . escapeshellarg($cloudflareScript) . ' > /dev/null 2>&1 &');
            reapZombies(); // Reap shell spawned by exec() with &
            $lastCloudflareAnalyticsFetch = $now;
        }
    }

    // 10. Status page caches pre-warm (every STATUS_PAGE_BACKGROUND_FETCH_INTERVAL, non-blocking)
    // Health, metrics bundle, performance JSON - aligned TTL (STATUS_PAGE_CACHE_TTL)
    if (($now - $lastStatusPageCachesFetch) >= STATUS_PAGE_BACKGROUND_FETCH_INTERVAL) {
        $statusScripts = [
        'fetch-status-health.php',
        'fetch-status-metrics.php',
        'fetch-performance-metrics.php',
        ];
        foreach ($statusScripts as $script) {
            $path = __DIR__ . '/' . $script;
            if (file_exists($path)) {
                exec(escapeshellarg($phpBin) . ' ' . escapeshellarg($path) . ' > /dev/null 2>&1 &');
            }
        }
        reapZombies();
        $lastStatusPageCachesFetch = $now;
    }

    // 11. Public API operations snapshot (every OPERATIONS_SNAPSHOT_BUILD_INTERVAL_SECONDS, non-blocking)
    if (($now - $lastOperationsSnapshotBuild) >= OPERATIONS_SNAPSHOT_BUILD_INTERVAL_SECONDS) {
        $opsScript = __DIR__ . '/build-operations-snapshot.php';
        if (file_exists($opsScript)) {
            exec(escapeshellarg($phpBin) . ' ' . escapeshellarg($opsScript) . ' > /dev/null 2>&1 &');
            reapZombies();
        }
        $lastOperationsSnapshotBuild = $now;
    }
});

$workRegistry->registerEnqueueTick('metrics_spill', function (int $now) use (&$lastMetricsSpillMerge): void {
    if (($now - $lastMetricsSpillMerge) < METRICS_SPILL_MERGE_INTERVAL_SECONDS) {
        return;
    }
    $aggScript = __DIR__ . '/aggregate-metrics-spills.php';
    if (!file_exists($aggScript)) {
        $lastMetricsSpillMerge = $now;
        aviationwx_log('warning', 'scheduler: aggregate-metrics-spills.php missing', [
            'path' => $aggScript,
        ], 'app');
        return;
    }
    $phpBin = PHP_BINARY !== '' && PHP_BINARY !== false ? PHP_BINARY : 'php';
    exec(escapeshellarg($phpBin) . ' ' . escapeshellarg($aggScript) . ' > /dev/null 2>&1 &');
    reapZombies();
    $lastMetricsSpillMerge = $now;
});

$workRegistry->registerEnqueueTick('metrics_variant_health', function (int $now) use (&$lastVariantHealthHttpFlush): void {
    if (($now - $lastVariantHealthHttpFlush) < METRICS_FLUSH_INTERVAL_SECONDS) {
        return;
    }
    $script = __DIR__ . '/flush-variant-health.php';
    if (!file_exists($script)) {
        $lastVariantHealthHttpFlush = $now;
        aviationwx_log('warning', 'scheduler: flush-variant-health.php missing', ['path' => $script], 'app');
        return;
    }
    $phpBin = PHP_BINARY !== '' && PHP_BINARY !== false ? PHP_BINARY : 'php';
    exec(escapeshellarg($phpBin) . ' ' . escapeshellarg($script) . ' > /dev/null 2>&1 &');
    reapZombies();
    $lastVariantHealthHttpFlush = $now;
});

$workRegistry->registerEnqueueTick('metrics_upstream_health', function (int $now) use (&$lastWeatherHealthUpdate): void {
    if (($now - $lastWeatherHealthUpdate) < 60) {
        return;
    }
    $script = __DIR__ . '/flush-upstream-health.php';
    if (!file_exists($script)) {
        $lastWeatherHealthUpdate = $now;
        aviationwx_log('warning', 'scheduler: flush-upstream-health.php missing', ['path' => $script], 'app');
        return;
    }
    $phpBin = PHP_BINARY !== '' && PHP_BINARY !== false ? PHP_BINARY : 'php';
    exec(escapeshellarg($phpBin) . ' ' . escapeshellarg($script) . ' > /dev/null 2>&1 &');
    reapZombies();
    $lastWeatherHealthUpdate = $now;
});

$workRegistry->registerEnqueueTick('metrics_daily', function (int $now) use (&$lastDailySpawnAttempt): void {
    $yesterdayId = gmdate('Y-m-d', $now - 86400);
    $dailyPath = getMetricsDailyPath($yesterdayId);
    if (is_file($dailyPath)) {
        return;
    }
    if (($now - $lastDailySpawnAttempt) < 60) {
        return;
    }
    $script = __DIR__ . '/aggregate-metrics-daily.php';
    if (!file_exists($script)) {
        $lastDailySpawnAttempt = $now;
        aviationwx_log('warning', 'scheduler: aggregate-metrics-daily.php missing', ['path' => $script], 'app');
        return;
    }
    $phpBin = PHP_BINARY !== '' && PHP_BINARY !== false ? PHP_BINARY : 'php';
    $cmd = escapeshellarg($phpBin) . ' ' . escapeshellarg($script)
        . ' --date=' . escapeshellarg($yesterdayId) . ' > /dev/null 2>&1 &';
    exec($cmd);
    reapZombies();
    $lastDailySpawnAttempt = $now;
});

$workRegistry->registerEnqueueTick('metrics_weekly', function (int $now) use (&$lastWeeklySpawnAttempt): void {
    $lastWeekId = gmdate('Y-\WW', $now - (7 * 86400));
    if ((int) gmdate('N', $now) !== 1 || (int) gmdate('H', $now) < 2) {
        return;
    }
    $weeklyPath = getMetricsWeeklyPath($lastWeekId);
    if (is_file($weeklyPath)) {
        return;
    }
    if (($now - $lastWeeklySpawnAttempt) < 60) {
        return;
    }
    $script = __DIR__ . '/aggregate-metrics-weekly.php';
    if (!file_exists($script)) {
        $lastWeeklySpawnAttempt = $now;
        aviationwx_log('warning', 'scheduler: aggregate-metrics-weekly.php missing', ['path' => $script], 'app');
        return;
    }
    $phpBin = PHP_BINARY !== '' && PHP_BINARY !== false ? PHP_BINARY : 'php';
    $cmd = escapeshellarg($phpBin) . ' ' . escapeshellarg($script)
        . ' --week=' . escapeshellarg($lastWeekId) . ' > /dev/null 2>&1 &';
    exec($cmd);
    reapZombies();
    $lastWeeklySpawnAttempt = $now;
});

$workRegistry->registerEnqueueTick('metrics_cleanup', function (int $now) use (&$lastMetricsCleanup): void {
    if (($now - $lastMetricsCleanup) < 86400) {
        return;
    }
    $script = __DIR__ . '/cleanup-metrics.php';
    if (!file_exists($script)) {
        $lastMetricsCleanup = $now;
        aviationwx_log('warning', 'scheduler: cleanup-metrics.php missing', ['path' => $script], 'app');
        return;
    }
    $phpBin = PHP_BINARY !== '' && PHP_BINARY !== false ? PHP_BINARY : 'php';
    exec(escapeshellarg($phpBin) . ' ' . escapeshellarg($script) . ' > /dev/null 2>&1 &');
    reapZombies();
    $lastMetricsCleanup = $now;
});

$workRegistry->registerEnqueueTick('metrics_health', function (int $now) use (&$lastMetricsHealthCheck): void {
    if (($now - $lastMetricsHealthCheck) < 300) {
        return;
    }
    $script = __DIR__ . '/check-metrics-health.php';
    if (!file_exists($script)) {
        $lastMetricsHealthCheck = $now;
        aviationwx_log('warning', 'scheduler: check-metrics-health.php missing', ['path' => $script], 'app');
        return;
    }
    $phpBin = PHP_BINARY !== '' && PHP_BINARY !== false ? PHP_BINARY : 'php';
    exec(escapeshellarg($phpBin) . ' ' . escapeshellarg($script) . ' > /dev/null 2>&1 &');
    reapZombies();
    $lastMetricsHealthCheck = $now;
});

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
                // Keep prior SHA when the config file could not be read this tick.
                if ($currentSha !== null) {
                    $lastConfigSha = $currentSha;
                }
                
                // Rebuild pools only when config changed or pools were never created.
                // Recreating every reload tick orphans in-flight ProcessPool children from the registry.
                if ($configChanged || $weatherPool === null) {
                    $weatherPoolSize = getWeatherWorkerPoolSize();
                    $webcamPoolSize = getWebcamWorkerPoolSize();
                    $notamPoolSize = getNotamWorkerPoolSize();
                    $stationPowerPoolSize = getStationPowerWorkerPoolSize();
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
                        'unified-webcam-worker.php',
                        $invocationId
                    );
                    
                    $notamPool = new ProcessPool(
                        $notamPoolSize,
                        $workerTimeout,
                        'fetch-notam.php',
                        $invocationId
                    );

                    $stationPowerPool = new ProcessPool(
                        $stationPowerPoolSize,
                        $workerTimeout,
                        'fetch-station-power.php',
                        $invocationId
                    );

                    $workRegistry->setPool('weather', $weatherPool);
                    $workRegistry->setPool('webcam', $webcamPool);
                    $workRegistry->setPool('notam', $notamPool);
                    $workRegistry->setPool('station_power', $stationPowerPool);

                    $webcamScheduleQueue = new WebcamScheduleQueue();
                    $webcamScheduleQueue->initialize($config['airports'] ?? [], $config);
                    
                    aviationwx_log('info', 'scheduler: config reloaded', [
                        'airports_count' => count($config['airports'] ?? []),
                        'webcam_count' => $webcamScheduleQueue->count(),
                        'pools_recreated' => true,
                        'config_changed' => $configChanged,
                    ], 'app');
                }
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
            $stationPowerPoolSize = getStationPowerWorkerPoolSize();
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

            $stationPowerPool = new ProcessPool(
                $stationPowerPoolSize,
                $workerTimeout,
                'fetch-station-power.php',
                $invocationId
            );

            $workRegistry->setPool('weather', $weatherPool);
            $workRegistry->setPool('webcam', $webcamPool);
            $workRegistry->setPool('notam', $notamPool);
            $workRegistry->setPool('station_power', $stationPowerPool);

            // Initialize webcam schedule queue with config
            // This uses a priority queue (min-heap) for O(log N) scheduling
            $webcamScheduleQueue = new WebcamScheduleQueue();
            $webcamScheduleQueue->initialize($config['airports'] ?? [], $config);
            
            aviationwx_log('info', 'scheduler: initialized webcam schedule queue', [
                'webcam_count' => $webcamScheduleQueue->count()
            ], 'app');
        }
        
        // Deploy drain uses registered pools/ticks only.
        $workRegistry->cleanupFinishedAll();

        reapZombies();

        $drainActiveWorkers = $workRegistry->sumActiveWorkers();
        $drainTick = deploy_drain_evaluate_scheduler_tick($drainActiveWorkers, $now);
        if (!$drainTick['allow_new_work']) {
            if (!$deployDrainAnnounced) {
                aviationwx_log('info', 'scheduler: deploy drain active - pausing new workers', [
                    'active_workers' => $drainActiveWorkers,
                    'action' => $drainTick['action'],
                    'elapsed_seconds' => deploy_drain_elapsed_seconds($now),
                    'max_seconds' => DEPLOY_WORKER_DRAIN_MAX_SECONDS,
                    'ttl_seconds' => deploy_drain_ttl_seconds(),
                    'registered_pools' => $workRegistry->registeredPoolNames(),
                ], 'app');
                $deployDrainAnnounced = true;
            }
        } else {
            if ($deployDrainAnnounced || $drainTick['action'] === 'abandon_clear') {
                if ($drainTick['action'] === 'abandon_clear') {
                    aviationwx_log('warning', 'scheduler: deploy drain abandoned - resuming workers', [
                        'elapsed_seconds' => deploy_drain_elapsed_seconds($now),
                        'ttl_seconds' => deploy_drain_ttl_seconds(),
                    ], 'app');
                }
            }
            $deployDrainAnnounced = false;
        }
        $drainApplied = deploy_drain_apply_scheduler_action(
            $drainTick['action'],
            $now,
            static function () use ($workRegistry, $drainActiveWorkers, $drainTick): void {
                if ($drainTick['action'] !== 'force_terminate' && $drainTick['action'] !== 'abandon_clear') {
                    return;
                }
                if ($drainActiveWorkers <= 0) {
                    return;
                }
                aviationwx_log('warning', 'scheduler: deploy drain terminating pool workers', [
                    'active_workers' => $drainActiveWorkers,
                    'action' => $drainTick['action'],
                    'max_seconds' => DEPLOY_WORKER_DRAIN_MAX_SECONDS,
                    'registered_pools' => $workRegistry->registeredPoolNames(),
                ], 'app');
                $workRegistry->terminateAll();
            }
        );
        if (!$drainApplied) {
            aviationwx_log('error', 'scheduler: deploy drain marker update failed', [
                'action' => $drainTick['action'],
                'active_workers' => $drainActiveWorkers,
                'flag' => deploy_drain_flag_path(),
                'done' => deploy_drain_done_path(),
            ], 'app', true);
        }

        if ($drainTick['allow_new_work']) {
            $workRegistry->runEnqueueTicks($now);
        }
        
        // Clean up stuck worker processes (every 60 seconds).
        // Safety net for workers that become stuck despite self-timeout mechanisms.
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

        // PASV / DDNS: root cron runs /usr/local/libexec/aviationwx/maybe-run-refresh-upload-endpoints.sh (ProFTPD needs root).

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

