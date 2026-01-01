<?php
/**
 * Status Page
 * Displays system health status for AviationWX.org
 */

require_once __DIR__ . '/../lib/config.php';
require_once __DIR__ . '/../lib/constants.php';
require_once __DIR__ . '/../lib/logger.php';
require_once __DIR__ . '/../lib/seo.php';
require_once __DIR__ . '/../lib/process-utils.php';
require_once __DIR__ . '/../lib/weather/source-timestamps.php';
require_once __DIR__ . '/../lib/webcam-format-generation.php';
require_once __DIR__ . '/../lib/weather-health.php';

// =============================================================================
// OPTIMIZATION: Cached data loaders
// =============================================================================

/**
 * Get cached backoff data (reads file once per request)
 * 
 * @return array Backoff data array (empty if file doesn't exist)
 */
function getCachedBackoffData(): array {
    static $backoffData = null;
    
    if ($backoffData === null) {
        $backoffFile = __DIR__ . '/../cache/backoff.json';
        if (file_exists($backoffFile)) {
            $content = @file_get_contents($backoffFile);
            $backoffData = $content !== false ? (@json_decode($content, true) ?: []) : [];
        } else {
            $backoffData = [];
        }
    }
    
    return $backoffData;
}

/**
 * Get cached status page data with APCu caching
 * 
 * Caches health check results for 30 seconds to reduce file I/O.
 * 
 * @param callable $computeFunc Function to compute fresh data
 * @param string $cacheKey APCu cache key
 * @param int $ttl Cache TTL in seconds (default: 30)
 * @return mixed Cached or freshly computed data
 */
function getCachedStatusData(callable $computeFunc, string $cacheKey, int $ttl = 30) {
    // Check APCu cache first
    if (function_exists('apcu_fetch')) {
        $cached = @apcu_fetch($cacheKey, $success);
        if ($success) {
            return $cached;
        }
    }
    
    // Compute fresh data
    $data = $computeFunc();
    
    // Store in APCu
    if (function_exists('apcu_store')) {
        @apcu_store($cacheKey, $data, $ttl);
    }
    
    return $data;
}

// Prevent caching (only in web context, not CLI)
if (php_sapi_name() !== 'cli' && !headers_sent()) {
    header('Content-Type: text/html; charset=utf-8');
    header('Cache-Control: no-cache, no-store, must-revalidate');
    header('Pragma: no-cache');
    header('Expires: 0');
}

/**
 * Determine status color
 * 
 * @param string $status Status level: 'operational', 'degraded', 'down', 'maintenance', or other
 * @return string Color name: 'green', 'yellow', 'red', 'orange', or 'gray'
 */
function getStatusColor(string $status): string {
    switch ($status) {
        case 'operational': return 'green';
        case 'degraded': return 'yellow';
        case 'down': return 'red';
        case 'maintenance': return 'orange';
        default: return 'gray';
    }
}

/**
 * Get status icon
 * 
 * @param string $status Status level: 'operational', 'degraded', 'down', 'maintenance', or other
 * @return string Icon character: '●' for status states, '🚧' for maintenance, '○' for unknown
 */
function getStatusIcon(string $status): string {
    switch ($status) {
        case 'operational': return '●';
        case 'degraded': return '●';
        case 'down': return '●';
        case 'maintenance': return '🚧';
        default: return '○';
    }
}

/**
 * Format relative time (e.g., "5 minutes ago", "2 hours ago")
 * 
 * @param int $timestamp Unix timestamp
 * @return string Formatted relative time string or 'Unknown' if invalid
 */
function formatRelativeTime(int $timestamp): string {
    if (!$timestamp || $timestamp <= 0) return 'Unknown';
    $diff = time() - $timestamp;
    if ($diff < SECONDS_PER_MINUTE) return 'Just now';
    if ($diff < SECONDS_PER_HOUR) {
        $minutes = floor($diff / SECONDS_PER_MINUTE);
        return $minutes . ' minute' . ($minutes == 1 ? '' : 's') . ' ago';
    }
    if ($diff < SECONDS_PER_DAY) {
        $hours = floor($diff / SECONDS_PER_HOUR);
        return $hours . ' hour' . ($hours == 1 ? '' : 's') . ' ago';
    }
    if ($diff < SECONDS_PER_WEEK) {
        $days = floor($diff / SECONDS_PER_DAY);
        return $days . ' day' . ($days == 1 ? '' : 's') . ' ago';
    }
    $weeks = floor($diff / SECONDS_PER_WEEK);
    return $weeks . ' week' . ($weeks == 1 ? '' : 's') . ' ago';
}

/**
 * Format absolute timestamp with timezone
 * 
 * @param int $timestamp Unix timestamp
 * @return string Formatted timestamp string or 'Unknown' if invalid
 */
function formatAbsoluteTime(int $timestamp): string {
    if (!$timestamp || $timestamp <= 0) return 'Unknown';
    return date('Y-m-d H:i:s T', $timestamp);
}

/**
 * Calculate memory averages from historical snapshots
 * 
 * Stores periodic memory snapshots and calculates rolling averages
 * similar to CPU load averages (1min, 5min, 15min).
 * 
 * @param int $currentMemory Current memory usage in bytes
 * @return array {
 *   '1min' => float|null,
 *   '5min' => float|null,
 *   '15min' => float|null
 * }
 */
function calculateMemoryAverages(int $currentMemory): array {
    $cacheFile = __DIR__ . '/../cache/memory_history.json';
    $cacheDir = dirname($cacheFile);
    $now = time();
    
    // Ensure cache directory exists
    if (!is_dir($cacheDir)) {
        @mkdir($cacheDir, 0755, true);
    }
    
    // Load existing snapshots
    // Use @ to suppress errors for non-critical file operations
    // We handle failures explicitly with fallback mechanisms below
    $snapshots = [];
    if (file_exists($cacheFile)) {
        $content = @file_get_contents($cacheFile);
        if ($content !== false) {
            $data = @json_decode($content, true);
            if (is_array($data) && isset($data['snapshots']) && is_array($data['snapshots'])) {
                $snapshots = $data['snapshots'];
            }
        }
    }
    
    // Add current snapshot (only if enough time has passed since last snapshot, or if empty)
    // Sample every 5 seconds to avoid excessive writes
    $shouldAddSnapshot = true;
    if (!empty($snapshots)) {
        $lastSnapshot = end($snapshots);
        $lastTime = $lastSnapshot['timestamp'] ?? 0;
        if (($now - $lastTime) < 5) {
            $shouldAddSnapshot = false;
        }
    }
    
    if ($shouldAddSnapshot) {
        $snapshots[] = [
            'timestamp' => $now,
            'memory_bytes' => $currentMemory
        ];
    }
    
    // Prune old snapshots (keep last 20 minutes worth, sampling every ~5 seconds = ~240 snapshots max)
    $cutoffTime = $now - (20 * 60);
    $snapshots = array_values(array_filter(
        $snapshots,
        function($snapshot) use ($cutoffTime) {
            return isset($snapshot['timestamp']) && $snapshot['timestamp'] >= $cutoffTime;
        }
    ));
    
    // Save updated snapshots (only if we added a new one)
    // Use file locking to prevent race conditions in concurrent environments
    // Use @ to suppress errors for non-critical metrics (memory averages are informational)
    if ($shouldAddSnapshot) {
        $data = [
            'updated_at' => $now,
            'snapshots' => $snapshots
        ];
        @file_put_contents($cacheFile, json_encode($data), LOCK_EX);
    }
    
    // Calculate averages for 1min, 5min, 15min periods
    $averages = [
        '1min' => null,
        '5min' => null,
        '15min' => null
    ];
    
    $periods = [
        '1min' => 60,
        '5min' => 300,
        '15min' => 900
    ];
    
    foreach ($periods as $period => $seconds) {
        $cutoff = $now - $seconds;
        $periodSnapshots = array_filter(
            $snapshots,
            function($snapshot) use ($cutoff) {
                return isset($snapshot['timestamp']) && $snapshot['timestamp'] >= $cutoff;
            }
        );
        
        if (!empty($periodSnapshots)) {
            $sum = 0;
            $count = 0;
            foreach ($periodSnapshots as $snapshot) {
                if (isset($snapshot['memory_bytes'])) {
                    $sum += $snapshot['memory_bytes'];
                    $count++;
                }
            }
            if ($count > 0) {
                $averages[$period] = round($sum / $count, 0);
            }
        }
    }
    
    return $averages;
}

/**
 * Get node performance metrics from container perspective
 * 
 * @return array {
 *   'cpu_load' => array{
 *     '1min' => float|null,
 *     '5min' => float|null,
 *     '15min' => float|null
 *   },
 *   'memory_used_bytes' => int|null,
 *   'memory_average' => array{
 *     '1min' => float|null,
 *     '5min' => float|null,
 *     '15min' => float|null
 *   },
 *   'storage_used_bytes' => int,
 *   'storage_breakdown' => array{
 *     'cache' => int,
 *     'uploads' => int,
 *     'logs' => int
 *   }
 * }
 */
function getNodePerformance(): array {
    $performance = [
        'cpu_load' => [
            '1min' => null,
            '5min' => null,
            '15min' => null
        ],
        'memory_used_bytes' => null,
        'memory_average' => [
            '1min' => null,
            '5min' => null,
            '15min' => null
        ],
        'storage_used_bytes' => null
    ];
    
    // CPU Load Averages - sys_getloadavg() works on Linux/macOS
    if (function_exists('sys_getloadavg')) {
        $load = sys_getloadavg();
        if (is_array($load) && count($load) >= 3) {
            $performance['cpu_load']['1min'] = round($load[0], 2);
            $performance['cpu_load']['5min'] = round($load[1], 2);
            $performance['cpu_load']['15min'] = round($load[2], 2);
        }
    }
    
    // Memory Usage - Try cgroups v2 first (Docker), then v1, then /proc/meminfo
    $memoryUsed = null;
    
    // cgroups v2 (modern Docker)
    $cgroupV2Current = '/sys/fs/cgroup/memory.current';
    if (file_exists($cgroupV2Current) && is_readable($cgroupV2Current)) {
        $memoryUsed = (int) trim(@file_get_contents($cgroupV2Current));
    }
    
    // cgroups v1 fallback
    if ($memoryUsed === null) {
        $cgroupV1Usage = '/sys/fs/cgroup/memory/memory.usage_in_bytes';
        if (file_exists($cgroupV1Usage) && is_readable($cgroupV1Usage)) {
            $memoryUsed = (int) trim(@file_get_contents($cgroupV1Usage));
        }
    }
    
    // /proc/meminfo fallback (host view, less ideal but works)
    if ($memoryUsed === null && file_exists('/proc/meminfo')) {
        $meminfo = @file_get_contents('/proc/meminfo');
        if ($meminfo) {
            $total = 0;
            $available = 0;
            if (preg_match('/MemTotal:\s+(\d+)\s+kB/', $meminfo, $m)) {
                $total = (int) $m[1] * 1024;
            }
            if (preg_match('/MemAvailable:\s+(\d+)\s+kB/', $meminfo, $m)) {
                $available = (int) $m[1] * 1024;
            }
            if ($total > 0) {
                $memoryUsed = $total - $available;
            }
        }
    }
    
    $performance['memory_used_bytes'] = $memoryUsed;
    
    // Calculate memory averages from historical snapshots
    if ($memoryUsed !== null) {
        $memoryAverages = calculateMemoryAverages($memoryUsed);
        $performance['memory_average'] = $memoryAverages;
    }
    
    // Storage Usage - Calculate actual size of all data directories
    // Includes cache, uploads, and logs for complete picture
    $performance['storage_used_bytes'] = 0;
    $performance['storage_breakdown'] = [
        'cache' => 0,
        'uploads' => 0,
        'logs' => 0
    ];
    
    // Helper function to calculate directory size
    $calculateDirSize = function($path) {
        if (!is_dir($path)) {
            return 0;
        }
        $size = 0;
        try {
            $iterator = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($path, RecursiveDirectoryIterator::SKIP_DOTS),
                RecursiveIteratorIterator::LEAVES_ONLY
            );
            foreach ($iterator as $file) {
                if ($file->isFile()) {
                    $fileSize = @$file->getSize();
                    if ($fileSize !== false) {
                        $size += $fileSize;
                    }
                }
            }
        } catch (Exception $e) {
            // Directory not accessible, return 0
        }
        return $size;
    };
    
    // 1. Cache directory (weather data, webcam images, history frames)
    $cachePath = __DIR__ . '/../cache';
    $performance['storage_breakdown']['cache'] = $calculateDirSize($cachePath);
    
    // 2. Uploads directory (push webcam uploads waiting to be processed)
    $uploadsPath = __DIR__ . '/../uploads';
    $performance['storage_breakdown']['uploads'] = $calculateDirSize($uploadsPath);
    
    // 3. Logs directory (when file-based logging is enabled)
    // Check both the defined log directory and common locations
    $logPaths = [
        defined('AVIATIONWX_LOG_DIR') ? AVIATIONWX_LOG_DIR : '/var/log/aviationwx',
        '/var/log/aviationwx'
    ];
    foreach (array_unique($logPaths) as $logPath) {
        $logSize = $calculateDirSize($logPath);
        if ($logSize > 0) {
            $performance['storage_breakdown']['logs'] = $logSize;
            break;
        }
    }
    
    // Calculate total
    $performance['storage_used_bytes'] = array_sum($performance['storage_breakdown']);
    
    return $performance;
}

/**
 * Format bytes into human-readable format
 * 
 * @param int|null $bytes Number of bytes
 * @param int $precision Decimal precision
 * @return string Formatted string like "1.5 GB" or "Unknown"
 */
function formatBytes(?int $bytes, int $precision = 1): string {
    if ($bytes === null || $bytes < 0) {
        return '—';
    }
    
    $units = ['B', 'KB', 'MB', 'GB', 'TB'];
    $bytes = max($bytes, 0);
    $pow = floor(($bytes ? log($bytes) : 0) / log(1024));
    $pow = min($pow, count($units) - 1);
    $bytes /= pow(1024, $pow);
    
    return round($bytes, $precision) . ' ' . $units[$pow];
}

/**
 * Check system health
 * 
 * @return array {
 *   'components' => array<string, array{
 *     'name' => string,
 *     'status' => 'operational'|'degraded'|'down',
 *     'message' => string,
 *     'lastChanged' => int,
 *     'services'?: array
 *   }>
 * }
 */
function checkSystemHealth(): array {
    $health = [
        'components' => []
    ];
    
    // Check configuration
    $configPath = getenv('CONFIG_PATH') ?: __DIR__ . '/../config/airports.json';
    $configReadable = file_exists($configPath) && is_readable($configPath);
    $configValid = false;
    if ($configReadable) {
        $config = loadConfig(false); // Don't use cache for status check
        $configValid = $config !== null;
    }
    
    $configMtime = $configReadable ? filemtime($configPath) : 0;
    $health['components']['configuration'] = [
        'name' => 'Configuration',
        'status' => $configReadable && $configValid ? 'operational' : 'down',
        'message' => $configReadable && $configValid ? 'Configuration loaded successfully' : 'Configuration file missing or invalid',
        'lastChanged' => $configMtime
    ];
    
    // Check cache directories
    $cacheDir = CACHE_BASE_DIR;
    $webcamCacheDir = CACHE_WEBCAMS_DIR;
    $cacheExists = is_dir($cacheDir);
    $cacheWritable = $cacheExists && is_writable($cacheDir);
    $webcamCacheExists = is_dir($webcamCacheDir);
    $webcamCacheWritable = $webcamCacheExists && is_writable($webcamCacheDir);
    
    $cacheStatus = ($cacheExists && $cacheWritable && $webcamCacheExists && $webcamCacheWritable) ? 'operational' : 'down';
    
    // Find most recent cache file modification time
    $latestCacheMtime = 0;
    if ($cacheExists) {
        $latestCacheMtime = filemtime($cacheDir);
        if ($webcamCacheExists) {
            $webcamMtime = filemtime($webcamCacheDir);
            if ($webcamMtime > $latestCacheMtime) {
                $latestCacheMtime = $webcamMtime;
            }
            // Check individual webcam files
            $files = glob($webcamCacheDir . '/*.{jpg,webp}', GLOB_BRACE);
            if ($files) {
                foreach ($files as $file) {
                    $mtime = filemtime($file);
                    if ($mtime > $latestCacheMtime) {
                        $latestCacheMtime = $mtime;
                    }
                }
            }
        }
        // Check weather cache files (new location: cache/weather/*.json)
        $weatherFiles = glob(CACHE_WEATHER_DIR . '/*.json');
        if ($weatherFiles) {
            foreach ($weatherFiles as $file) {
                $mtime = filemtime($file);
                if ($mtime > $latestCacheMtime) {
                    $latestCacheMtime = $mtime;
                }
            }
        }
    }
    
    $health['components']['cache'] = [
        'name' => 'Cache System',
        'status' => $cacheStatus,
        'message' => $cacheStatus === 'operational' 
            ? 'Cache directories accessible' 
            : 'Cache directories missing or not writable',
        'lastChanged' => $latestCacheMtime > 0 ? $latestCacheMtime : 0
    ];
    
    // Check APCu
    $apcuAvailable = function_exists('apcu_fetch');
    // APCu status doesn't really change, so we'll use current time or 0
    $health['components']['apcu'] = [
        'name' => 'APCu Cache',
        'status' => $apcuAvailable ? 'operational' : 'degraded',
        'message' => $apcuAvailable ? 'APCu available' : 'APCu not available (performance may be reduced)',
        'lastChanged' => 0 // Static state, no meaningful timestamp
    ];
    
    // Check logging system (file-based logging)
    $logFile = AVIATIONWX_APP_LOG_FILE;
    $logDir = dirname($logFile);
    $logMtime = file_exists($logFile) ? filemtime($logFile) : 0;
    $hasRecentLogs = false;
    if ($logMtime > 0) {
        $hasRecentLogs = (time() - $logMtime) < STATUS_RECENT_LOG_THRESHOLD_SECONDS;
    }
    
    // Check if log directory is writable
    $logDirWritable = is_dir($logDir) && is_writable($logDir);
    
    // Determine logging status:
    // - Operational if recent logs exist or log directory is writable
    // - Degraded if log file exists but no recent activity
    // - Degraded if log directory not writable
    $loggingStatus = 'operational';
    $loggingMessage = 'Logging operational';
    if ($hasRecentLogs) {
        $loggingStatus = 'operational';
        $loggingMessage = 'Logging operational';
    } elseif ($logDirWritable) {
        $loggingStatus = 'operational';
        $loggingMessage = 'Logging ready';
    } elseif (file_exists($logFile)) {
        $loggingStatus = 'degraded';
        $loggingMessage = 'Logging degraded';
    } else {
        $loggingStatus = 'degraded';
        $loggingMessage = 'Logging unavailable';
    }
    
    $health['components']['logging'] = [
        'name' => 'Logging',
        'status' => $loggingStatus,
        'message' => $loggingMessage,
        'lastChanged' => $logMtime > 0 ? $logMtime : (is_dir($logDir) ? filemtime($logDir) : 0)
    ];
    
    // Check internal error rate (system errors only, not external data source failures)
    $errorRate = aviationwx_error_rate_last_hour();
    $errorRateStatus = $errorRate === 0 ? 'operational' : ($errorRate < ERROR_RATE_DEGRADED_THRESHOLD ? 'degraded' : 'down');
    
    // Get last error timestamp
    $lastErrorTime = 0;
    if (function_exists('apcu_fetch')) {
        $errorEvents = apcu_fetch('aviationwx_internal_error_events');
        if (is_array($errorEvents) && !empty($errorEvents)) {
            $lastErrorTime = max($errorEvents);
        }
    }
    
    $health['components']['error_rate'] = [
        'name' => 'System Error Rate',
        'status' => $errorRateStatus,
        'message' => $errorRate === 0 ? 'No internal system errors in the last hour' : "{$errorRate} internal system errors in the last hour",
        'lastChanged' => $lastErrorTime > 0 ? $lastErrorTime : ($errorRate === 0 ? time() : 0)
    ];
    
    // Check FTP/SFTP services
    $ftpSftpHealth = checkFtpSftpServices();
    $health['components']['ftp_sftp'] = $ftpSftpHealth;
    
    // Check scheduler status
    $schedulerStatus = getSchedulerStatus();
    $schedulerHealthStatus = 'operational';
    $schedulerMessage = 'Scheduler running and healthy';
    
    if (!$schedulerStatus['running']) {
        $schedulerHealthStatus = 'down';
        $schedulerMessage = $schedulerStatus['error'] ?? 'Scheduler not running';
    } elseif (!$schedulerStatus['healthy']) {
        $schedulerHealthStatus = 'degraded';
        $schedulerMessage = 'Scheduler running but unhealthy';
        if ($schedulerStatus['last_error']) {
            $schedulerMessage .= ': ' . $schedulerStatus['last_error'];
        }
    }
    
    $health['components']['scheduler'] = [
        'name' => 'Scheduler Daemon',
        'status' => $schedulerHealthStatus,
        'message' => $schedulerMessage,
        'lastChanged' => $schedulerStatus['started'] ?? 0,
        'details' => [
            'pid' => $schedulerStatus['pid'],
            'uptime_seconds' => $schedulerStatus['uptime'],
            'loop_count' => $schedulerStatus['loop_count'],
            'config_airports_count' => $schedulerStatus['config_airports_count'],
            'config_last_reload' => $schedulerStatus['config_last_reload']
        ]
    ];
    
    // Check webcam variant generation health
    $variantHealth = checkVariantGenerationHealth();
    $health['components']['variant_generation'] = $variantHealth;
    
    // Check weather data fetching health (uses cached data from weather-health.php)
    $weatherDataHealth = weather_health_get_status();
    
    // Include per-source breakdown in the metrics if available
    $weatherSources = weather_health_get_sources();
    if (!empty($weatherSources)) {
        $sourceSummary = [];
        foreach ($weatherSources as $type => $sourceData) {
            $rate = $sourceData['metrics']['success_rate'] ?? 100;
            $sourceSummary[] = ucfirst($type) . ': ' . $rate . '%';
        }
        $weatherDataHealth['source_summary'] = implode(' · ', $sourceSummary);
    }
    
    $health['components']['weather_fetching'] = $weatherDataHealth;
    
    return $health;
}

/**
 * Check webcam variant generation health
 * 
 * Uses cached health data from lib/variant-health.php (written by scheduler).
 * No log parsing needed - health is tracked at source via APCu counters.
 * 
 * @return array {
 *   'name' => string,
 *   'status' => 'operational'|'degraded'|'down',
 *   'message' => string,
 *   'lastChanged' => int,
 *   'metrics' => array
 * }
 */
function checkVariantGenerationHealth(): array {
    // Uses variant_health_get_status() from lib/variant-health.php
    // (included via webcam-format-generation.php)
    return variant_health_get_status();
}

/**
 * Get scheduler status
 * 
 * Reads scheduler lock file to determine if scheduler is running and healthy.
 * Uses /proc filesystem to check process existence (works across user boundaries).
 * 
 * @return array {
 *   'running' => bool,
 *   'healthy' => bool,
 *   'pid' => int|null,
 *   'started' => int|null,
 *   'uptime' => int,
 *   'loop_count' => int,
 *   'last_error' => string|null,
 *   'config_airports_count' => int,
 *   'config_last_reload' => int,
 *   'error' => string|null
 * }
 */
function getSchedulerStatus(): array {
    $lockFile = '/tmp/scheduler.lock';
    
    if (!file_exists($lockFile)) {
        return [
            'running' => false,
            'healthy' => false,
            'pid' => null,
            'started' => null,
            'uptime' => 0,
            'loop_count' => 0,
            'last_error' => null,
            'config_airports_count' => 0,
            'config_last_reload' => 0,
            'error' => 'Scheduler not running'
        ];
    }
    
    $lockContent = @file_get_contents($lockFile);
    if (!$lockContent) {
        return [
            'running' => false,
            'healthy' => false,
            'pid' => null,
            'started' => null,
            'uptime' => 0,
            'loop_count' => 0,
            'last_error' => null,
            'config_airports_count' => 0,
            'config_last_reload' => 0,
            'error' => 'Cannot read lock file'
        ];
    }
    
    $lockData = json_decode($lockContent, true);
    if (!$lockData) {
        return [
            'running' => false,
            'healthy' => false,
            'pid' => null,
            'started' => null,
            'uptime' => 0,
            'loop_count' => 0,
            'last_error' => null,
            'config_airports_count' => 0,
            'config_last_reload' => 0,
            'error' => 'Invalid lock file data'
        ];
    }
    
    $pid = $lockData['pid'] ?? null;
    $running = $pid && isProcessRunning((int)$pid);
    $healthy = $running && ($lockData['health'] ?? 'unknown') === 'healthy';
    
    return [
        'running' => $running,
        'healthy' => $healthy,
        'pid' => $pid,
        'started' => $lockData['started'] ?? null,
        'uptime' => isset($lockData['started']) ? (time() - $lockData['started']) : 0,
        'loop_count' => $lockData['loop_count'] ?? 0,
        'last_error' => $lockData['last_error'] ?? null,
        'config_airports_count' => $lockData['config_airports_count'] ?? 0,
        'config_last_reload' => $lockData['config_last_reload'] ?? 0,
        'error' => null
    ];
}

/**
 * Check FTP/SFTP service health
 * 
 * @return array {
 *   'name' => string,
 *   'status' => 'operational'|'degraded'|'down',
 *   'message' => string,
 *   'lastChanged' => int,
 *   'services' => array
 * }
 */
function checkFtpSftpServices(): array {
    $services = [
        'vsftpd' => [
            'name' => 'FTP/FTPS Server',
            'running' => false,
            'ports' => [2121, 2122]
        ],
        'sshd' => [
            'name' => 'SFTP Server',
            'running' => false,
            'ports' => [2222]
        ]
    ];
    
    // Check vsftpd process
    // Use @ to suppress errors for non-critical process checks
    // We handle failures explicitly with fallback mechanisms below
    $vsftpdRunning = false;
    if (function_exists('exec')) {
        @exec('pgrep -x vsftpd 2>/dev/null', $output, $code);
        $vsftpdRunning = ($code === 0 && !empty($output));
    }
    $services['vsftpd']['running'] = $vsftpdRunning;
    
    // Check sshd process
    // Use @ to suppress errors for non-critical process checks
    // We handle failures explicitly with fallback mechanisms below
    $sshdRunning = false;
    if (function_exists('exec')) {
        @exec('pgrep -x sshd 2>/dev/null', $output, $code);
        $sshdRunning = ($code === 0 && !empty($output));
    }
    $services['sshd']['running'] = $sshdRunning;
    
    // Determine overall status
    $allRunning = $vsftpdRunning && $sshdRunning;
    $noneRunning = !$vsftpdRunning && !$sshdRunning;
    
    if ($allRunning) {
        $status = 'operational';
        $message = 'FTP/FTPS and SFTP servers running';
    } elseif ($noneRunning) {
        $status = 'down';
        $message = 'FTP/FTPS and SFTP servers not running';
    } else {
        $status = 'degraded';
        $runningServices = [];
        if ($vsftpdRunning) $runningServices[] = 'FTP/FTPS';
        if ($sshdRunning) $runningServices[] = 'SFTP';
        $message = implode(' and ', $runningServices) . ' running';
    }
    
    return [
        'name' => 'FTP/SFTP Services',
        'status' => $status,
        'message' => $message,
        'lastChanged' => 0, // Static state, no meaningful timestamp
        'services' => $services
    ];
}

/**
 * Check airport health
 * 
 * @param string $airportId Airport identifier
 * @param array $airport Airport configuration array
 * @return array {
 *   'id' => string,
 *   'status' => 'operational'|'degraded'|'down'|'maintenance',
 *   'components' => array<string, array>
 * }
 */
function checkAirportHealth(string $airportId, array $airport): array {
    $health = [
        'id' => strtoupper($airportId),
        'status' => 'operational',
        'components' => []
    ];
    
    // Check weather sources - per-source status
    // Use shared helper to get timestamps
    $sourceTimestamps = getSourceTimestamps($airportId, $airport);
    
    $weatherRefresh = isset($airport['weather_refresh_seconds']) 
        ? intval($airport['weather_refresh_seconds']) 
        : getDefaultWeatherRefresh();
    
    // 3-tier staleness thresholds
    $warningSeconds = getStaleWarningSeconds($airport);
    $errorSeconds = getStaleErrorSeconds($airport);
    $failclosedSeconds = getStaleFailclosedSeconds($airport);
    $metarFailclosedSeconds = getMetarStaleFailclosedSeconds();
    
    $weatherSources = [];
    
    // Use centralized helper for source name mapping
    require_once __DIR__ . '/../lib/weather/utils.php';
    
    // Helper function to get HTTP error code from backoff state (uses cached data)
    $getHttpErrorInfo = function($airportId, $sourceType) {
        $backoffData = getCachedBackoffData();
        if (empty($backoffData)) {
            return null;
        }
        
        $key = $airportId . '_weather_' . $sourceType;
        if (!isset($backoffData[$key])) {
            return null;
        }
        
        $state = $backoffData[$key];
        $httpCode = isset($state['last_http_code']) ? (int)$state['last_http_code'] : null;
        $errorTime = isset($state['last_error_time']) ? (int)$state['last_error_time'] : 0;
        $nextAllowed = isset($state['next_allowed_time']) ? (int)$state['next_allowed_time'] : 0;
        
        // Only return HTTP code if:
        // 1. Code exists and is 4xx/5xx
        // 2. Source is in backoff (circuit open), OR
        // 3. Error occurred within last hour
        if ($httpCode !== null && $httpCode >= 400 && $httpCode < 600) {
            $now = time();
            $inBackoff = $nextAllowed > $now;
            $errorAge = $errorTime > 0 ? ($now - $errorTime) : 0;
            $oneHour = 3600;
            
            if ($inBackoff || $errorAge < $oneHour) {
                return [
                    'http_code' => $httpCode,
                    'error_time' => $errorTime,
                    'in_backoff' => $inBackoff
                ];
            }
        }
        
        return null;
    };
    
    // Check primary weather source
    $sourceType = isset($airport['weather_source']['type']) ? $airport['weather_source']['type'] : null;
    if ($sourceType && $sourceTimestamps['primary']['available']) {
        $sourceName = getWeatherSourceDisplayName($sourceType);
        $primaryStatus = 'down';
        $primaryMessage = 'No data available';
        $primaryLastChanged = $sourceTimestamps['primary']['timestamp'];
        
        if ($primaryLastChanged > 0) {
            $primaryAge = $sourceTimestamps['primary']['age'];
            
            // Use 3-tier staleness model
            if ($sourceType === 'metar') {
                // Use METAR-specific thresholds
                $metarWarning = getMetarStaleWarningSeconds();
                $metarError = getMetarStaleErrorSeconds();
                $metarFailclosed = getMetarStaleFailclosedSeconds();
                
                if ($primaryAge < $metarWarning) {
                    $primaryStatus = 'operational';
                    $primaryMessage = 'Fresh';
                } elseif ($primaryAge < $metarError) {
                    $primaryStatus = 'operational';
                    $primaryMessage = 'Recent (warning)';
                } elseif ($primaryAge < $metarFailclosed) {
                    $primaryStatus = 'degraded';
                    $primaryMessage = 'Stale (error)';
                } else {
                    $primaryStatus = 'down';
                    $primaryMessage = 'Expired (failclosed)';
                }
            } else {
                // Use general 3-tier staleness thresholds for non-METAR sources
                if ($primaryAge < $warningSeconds) {
                    $primaryStatus = 'operational';
                    $primaryMessage = 'Operational';
                } elseif ($primaryAge < $errorSeconds) {
                    $primaryStatus = 'operational';
                    $primaryMessage = 'Recent (warning)';
                } elseif ($primaryAge < $failclosedSeconds) {
                    $primaryStatus = 'degraded';
                    $primaryMessage = 'Stale (error)';
                } else {
                    $primaryStatus = 'down';
                    $primaryMessage = 'Expired (failclosed)';
                }
            }
        } else {
            $primaryStatus = 'down';
            $primaryMessage = 'No timestamp available';
        }
        
        // Check for HTTP error code to append to message
        $httpErrorInfo = $getHttpErrorInfo($airportId, 'primary');
        if ($httpErrorInfo !== null && ($primaryStatus === 'down' || $primaryStatus === 'degraded' || $httpErrorInfo['in_backoff'])) {
            $primaryMessage .= ' - HTTP ' . $httpErrorInfo['http_code'];
        }
        
        $weatherSources[] = [
            'name' => $sourceName,
            'status' => $primaryStatus,
            'message' => $primaryMessage,
            'lastChanged' => $primaryLastChanged
        ];
    }
    
    // Check METAR source (if configured separately or as supplement)
    $isMetarEnabled = isMetarEnabled($airport);
    $isMetarPrimary = ($sourceType === 'metar');
    
    // METAR is shown separately if:
    // 1. It's the primary source (already added above), OR
    // 2. It's configured as supplement (metar_station set and primary is not metar)
    if ($isMetarPrimary) {
        // Already added above, skip
    } elseif ($isMetarEnabled && $sourceTimestamps['metar']['available']) {
        $metarStatus = 'down';
        $metarMessage = 'No data available';
        $metarLastChanged = $sourceTimestamps['metar']['timestamp'];
        
        if ($metarLastChanged > 0) {
            $metarAge = $sourceTimestamps['metar']['age'];
            
            // METAR status using 3-tier staleness model
            $metarWarning = getMetarStaleWarningSeconds();
            $metarError = getMetarStaleErrorSeconds();
            $metarFailclosed = getMetarStaleFailclosedSeconds();
            
            if ($metarAge < $metarWarning) {
                $metarStatus = 'operational';
                $metarMessage = 'Fresh';
            } elseif ($metarAge < $metarError) {
                $metarStatus = 'operational';
                $metarMessage = 'Recent (warning)';
            } elseif ($metarAge < $metarFailclosed) {
                $metarStatus = 'degraded';
                $metarMessage = 'Stale (error)';
            } else {
                $metarStatus = 'down';
                $metarMessage = 'Expired';
            }
        } else {
            $metarStatus = 'down';
            $metarMessage = 'No timestamp available';
        }
        
        // Check for HTTP error code to append to message
        $httpErrorInfo = $getHttpErrorInfo($airportId, 'metar');
        if ($httpErrorInfo !== null && ($metarStatus === 'down' || $metarStatus === 'degraded' || $httpErrorInfo['in_backoff'])) {
            $metarMessage .= ' - HTTP ' . $httpErrorInfo['http_code'];
        }
        
        $weatherSources[] = [
            'name' => 'Aviation Weather',
            'status' => $metarStatus,
            'message' => $metarMessage,
            'lastChanged' => $metarLastChanged
        ];
    }
    
    // Check backup weather source (if configured)
    $backupSourceType = isset($airport['weather_source_backup']['type']) ? $airport['weather_source_backup']['type'] : null;
    if ($backupSourceType && $sourceTimestamps['backup']['available']) {
        $backupSourceName = getWeatherSourceDisplayName($backupSourceType) . ' (Backup)';
        $backupStatus = 'standby';
        $backupMessage = 'Standby';
        $backupLastChanged = $sourceTimestamps['backup']['timestamp'];
        
        // Check if backup is active (providing data for any fields)
        // Use backup_status from cache if available (more accurate than calculating)
        $backupActive = false;
        $weatherCacheFile = getWeatherCachePath($airportId);
        if (file_exists($weatherCacheFile)) {
            $weatherData = @json_decode(@file_get_contents($weatherCacheFile), true);
            if (is_array($weatherData)) {
                // Use backup_status from cache if available (set during merge)
                if (isset($weatherData['backup_status'])) {
                    $backupActive = ($weatherData['backup_status'] === 'active');
                } else {
                    // Fallback: calculate based on timestamps (for backward compatibility)
                    $backupAge = isset($weatherData['last_updated_backup']) && $weatherData['last_updated_backup'] > 0
                        ? time() - $weatherData['last_updated_backup']
                        : PHP_INT_MAX;
                    $primaryAge = isset($weatherData['last_updated_primary']) && $weatherData['last_updated_primary'] > 0
                        ? time() - $weatherData['last_updated_primary']
                        : PHP_INT_MAX;
                    // Backup is active if it has fresh data and primary is stale
                    $backupActive = ($backupAge < $warningSeconds) && ($primaryAge >= $warningSeconds);
                }
            }
        }
        
        if ($backupLastChanged > 0) {
            $backupAge = $sourceTimestamps['backup']['age'];
            
            // Check circuit breaker for backup
            require_once __DIR__ . '/../lib/circuit-breaker.php';
            $backupCircuit = checkWeatherCircuitBreaker($airportId, 'backup');
            
            if ($backupCircuit['skip']) {
                $backupStatus = 'failed';
                $backupMessage = 'Circuit breaker open';
            } elseif ($backupActive) {
                // Backup is active (providing data) - use 3-tier staleness
                if ($backupAge < $warningSeconds) {
                    $backupStatus = 'operational';
                    $backupMessage = 'Active';
                } elseif ($backupAge < $errorSeconds) {
                    $backupStatus = 'operational';
                    $backupMessage = 'Active (warning)';
                } elseif ($backupAge < $failclosedSeconds) {
                    $backupStatus = 'degraded';
                    $backupMessage = 'Active (error)';
                } else {
                    $backupStatus = 'down';
                    $backupMessage = 'Active (failclosed)';
                }
            } else {
                // Backup is in standby (configured but not active) - use 3-tier staleness
                if ($backupAge < $warningSeconds) {
                    $backupStatus = 'operational';
                    $backupMessage = 'Standby (ready)';
                } elseif ($backupAge < $errorSeconds) {
                    $backupStatus = 'operational';
                    $backupMessage = 'Standby (warning)';
                } elseif ($backupAge < $failclosedSeconds) {
                    $backupStatus = 'degraded';
                    $backupMessage = 'Standby (error)';
                } else {
                    $backupStatus = 'down';
                    $backupMessage = 'Standby (failclosed)';
                }
            }
        } else {
            // Check circuit breaker even if no timestamp
            require_once __DIR__ . '/../lib/circuit-breaker.php';
            $backupCircuit = checkWeatherCircuitBreaker($airportId, 'backup');
            if ($backupCircuit['skip']) {
                $backupStatus = 'failed';
                $backupMessage = 'Circuit breaker open';
            } else {
                $backupStatus = $backupActive ? 'operational' : 'standby';
                $backupMessage = $backupActive ? 'Active (no timestamp)' : 'Standby (no timestamp)';
            }
        }
        
        // Check for HTTP error code to append to message
        $httpErrorInfo = $getHttpErrorInfo($airportId, 'backup');
        if ($httpErrorInfo !== null && ($backupStatus === 'down' || $backupStatus === 'degraded' || $backupStatus === 'failed' || $httpErrorInfo['in_backoff'])) {
            $backupMessage .= ' - HTTP ' . $httpErrorInfo['http_code'];
        }
        
        $weatherSources[] = [
            'name' => $backupSourceName,
            'status' => $backupStatus,
            'message' => $backupMessage,
            'lastChanged' => $backupLastChanged
        ];
    }
    
    // Store weather sources (similar to webcam cameras) - only if we have sources
    if (!empty($weatherSources)) {
        $health['components']['weather'] = [
            'name' => 'Weather Sources',
            'sources' => $weatherSources
        ];
    }
    
    // Check webcam caches - per-camera status
    // Use shared helper for timestamps, but we still need per-camera status
    $webcamCacheDir = CACHE_WEBCAMS_DIR;
    $webcamCacheDirResolved = @realpath($webcamCacheDir) ?: $webcamCacheDir;
    $webcams = $airport['webcams'] ?? [];
    $webcamStatus = 'operational';
    $webcamIssues = [];
    $webcamComponents = [];
    
    // Check if cache directory exists and is readable
    $webcamCacheDirExists = is_dir($webcamCacheDirResolved);
    $webcamCacheDirReadable = $webcamCacheDirExists && is_readable($webcamCacheDirResolved);
    
    if (empty($webcams)) {
        $webcamStatus = 'degraded';
        $webcamIssues[] = 'No webcams configured';
    } else {
        $healthyCams = 0;
        $totalCams = count($webcams);
        
        foreach ($webcams as $idx => $cam) {
            // Determine camera type (Push or Pull)
            $isPush = (isset($cam['type']) && $cam['type'] === 'push') 
                   || isset($cam['push_config']);
            $cameraType = $isPush ? 'Push' : 'Pull';
            $camName = $cam['name'] ?? "Webcam {$idx}";
            
            // Check cache files using new structure (per-airport/per-camera directories)
            // Ensure webcam-format-generation.php is loaded (defensive check for CI/test environments)
            if (!function_exists('getCacheFile')) {
                $webcamFormatFile = __DIR__ . '/../lib/webcam-format-generation.php';
                if (file_exists($webcamFormatFile)) {
                    require_once $webcamFormatFile;
                }
                // If still not available after require, use fallback path construction
                if (!function_exists('getCacheFile')) {
                    $cacheDir = getWebcamCameraDir($airportId, $idx);
                    $cacheJpg = $cacheDir . '/current.jpg';
                    $cacheWebp = $cacheDir . '/current.webp';
                } else {
                    $cacheJpg = getCacheFile($airportId, $idx, 'jpg', 'primary');
                    $cacheWebp = getCacheFile($airportId, $idx, 'webp', 'primary');
                }
            } else {
                $cacheJpg = getCacheFile($airportId, $idx, 'jpg', 'primary');
                $cacheWebp = getCacheFile($airportId, $idx, 'webp', 'primary');
            }
            
            // Check if cache files exist and are readable
            $cacheExists = (@file_exists($cacheJpg) && @is_readable($cacheJpg)) 
                        || (@file_exists($cacheWebp) && @is_readable($cacheWebp));
            
            // Check variant availability
            $variants = ['thumb', 'small', 'medium', 'large', 'primary'];
            $formats = ['jpg', 'webp'];
            $variantAvailability = [];
            $totalVariants = count($variants) * count($formats);
            $availableVariants = 0;
            
            foreach ($variants as $variant) {
                foreach ($formats as $format) {
                    // Get most recent file for this variant/format
                    $cacheDir = getWebcamCacheDir($airportId, $idx);
                    // Check directory exists before glob (getWebcamCacheDir creates it, but verify)
                    if (is_dir($cacheDir) && is_readable($cacheDir)) {
                        $pattern = $cacheDir . '/*_' . $variant . '.' . $format;
                        $files = @glob($pattern);
                        $available = !empty($files);
                        if ($available) {
                            $availableVariants++;
                        }
                        $variantAvailability[$variant . '_' . $format] = $available;
                    } else {
                        $variantAvailability[$variant . '_' . $format] = false;
                    }
                }
            }
            
            $variantCoverage = $totalVariants > 0 ? ($availableVariants / $totalVariants) : 0;
            
            // Get refresh seconds (min 60)
            $webcamRefresh = isset($cam['refresh_seconds']) 
                ? max(60, intval($cam['refresh_seconds'])) 
                : (isset($airport['webcam_refresh_seconds']) 
                    ? max(60, intval($airport['webcam_refresh_seconds'])) 
                    : max(60, getDefaultWebcamRefresh()));
            
            $camStatus = 'operational';
            $camMessage = '';
            $camLastChanged = 0;
            
            if ($cacheExists) {
                // Determine which file to use (prefer JPG, fallback to WEBP)
                $cacheFile = (@file_exists($cacheJpg) && @is_readable($cacheJpg)) 
                           ? $cacheJpg 
                           : $cacheWebp;
                $cacheAge = time() - @filemtime($cacheFile);
                $camLastChanged = @filemtime($cacheFile) ?: 0;
                
                // 3-tier staleness thresholds (from config with airport override)
                $warningThreshold = getStaleWarningSeconds($airport);
                $errorThreshold = getStaleErrorSeconds($airport);
                $failclosedThreshold = getStaleFailclosedSeconds($airport);
                
                // Check for error files (pull cameras only)
                $errorFile = $cacheJpg . '.error.json';
                $hasError = !$isPush && file_exists($errorFile);
                
                // Check backoff state (pull cameras only) - uses cached data
                $inBackoff = false;
                if (!$isPush) {
                    $backoffData = getCachedBackoffData();
                    $key = $airportId . '_' . $idx;
                    if (isset($backoffData[$key])) {
                        $backoffUntil = $backoffData[$key]['next_allowed_time'] ?? 0;
                        $inBackoff = $backoffUntil > time();
                    }
                }
                
                if ($hasError || $inBackoff) {
                    $camStatus = 'degraded';
                    $camMessage = $hasError ? 'Has errors' : 'In backoff';
                } elseif ($cacheAge < $warningThreshold) {
                    $camStatus = 'operational';
                    $camMessage = 'Fresh';
                    $healthyCams++;
                } elseif ($cacheAge < $errorThreshold) {
                    $camStatus = 'operational';
                    $camMessage = 'Stale (warning)';
                    $healthyCams++;
                } elseif ($cacheAge < $failclosedThreshold) {
                    $camStatus = 'degraded';
                    $camMessage = 'Stale (error)';
                } else {
                    $camStatus = 'down';
                    $camMessage = 'Stale (failclosed)';
                }
            } else {
                $camStatus = 'down';
                if (!$webcamCacheDirExists) {
                    $camMessage = 'Cache directory does not exist';
                } elseif (!$webcamCacheDirReadable) {
                    $camMessage = 'Cache directory not readable';
                } else {
                    $camMessage = 'No cache available';
                }
            }
            
            // Build detailed message with variant coverage
            $detailedMessage = $camMessage;
            if ($cacheExists && isset($variantCoverage)) {
                $coveragePercent = round($variantCoverage * 100);
                if ($variantCoverage >= 0.9) {
                    $detailedMessage .= " • {$coveragePercent}% variants available";
                } elseif ($variantCoverage >= 0.5) {
                    $detailedMessage .= " • {$coveragePercent}% variants (degraded)";
                    if ($camStatus === 'operational') {
                        $camStatus = 'degraded';
                    }
                } else {
                    $detailedMessage .= " • {$coveragePercent}% variants (low coverage)";
                    if ($camStatus === 'operational') {
                        $camStatus = 'degraded';
                    }
                }
            }
            
            // Add per-camera component
            $webcamComponents[] = [
                'name' => "{$camName} ({$cameraType})",
                'status' => $camStatus,
                'message' => $detailedMessage,
                'lastChanged' => $camLastChanged,
                'variant_coverage' => isset($variantCoverage) ? round($variantCoverage * 100, 1) : null,
                'available_variants' => isset($availableVariants) ? $availableVariants : null,
                'total_variants' => isset($totalVariants) ? $totalVariants : null
            ];
            
            // Track issues for aggregate message
            if ($camStatus === 'down') {
                $webcamIssues[] = "{$camName}: {$camMessage}";
            } elseif ($camStatus === 'degraded') {
                if (empty($webcamIssues)) {
                    $webcamIssues[] = "{$camName}: {$camMessage}";
                }
            }
        }
        
        // Determine aggregate status
        $hasDown = false;
        $hasDegraded = false;
        foreach ($webcamComponents as $comp) {
            if ($comp['status'] === 'down') {
                $hasDown = true;
                break;
            } elseif ($comp['status'] === 'degraded') {
                $hasDegraded = true;
            }
        }
        
        if ($hasDown) {
            $webcamStatus = 'down';
        } elseif ($hasDegraded) {
            $webcamStatus = 'degraded';
        } else {
            $webcamStatus = 'operational';
        }
    }
    
    $webcamMessage = empty($webcamIssues) 
        ? ($totalCams > 0 ? "All {$totalCams} webcam(s) operational" : 'No webcams configured')
        : implode(', ', array_slice($webcamIssues, 0, 2)); // Show max 2 issues
    
    // Find most recent webcam cache file modification time
    $webcamLastChanged = 0;
    foreach ($webcamComponents as $comp) {
        if ($comp['lastChanged'] > $webcamLastChanged) {
            $webcamLastChanged = $comp['lastChanged'];
        }
    }
    
    // Store webcam cameras - only if we have cameras
    if (!empty($webcamComponents)) {
        $health['components']['webcams'] = [
            'name' => 'Webcams',
            'status' => $webcamStatus,
            'message' => $webcamMessage,
            'lastChanged' => $webcamLastChanged,
            'cameras' => $webcamComponents // Per-camera details
        ];
    }
    
    // Determine overall airport status
    // Check all components including individual sources for weather and webcams
    $hasDown = false;
    $hasDegraded = false;
    foreach ($health['components'] as $comp) {
        // Check weather sources
        if (isset($comp['sources']) && is_array($comp['sources'])) {
            foreach ($comp['sources'] as $source) {
                if ($source['status'] === 'down') {
                    $hasDown = true;
                    break 2; // Break out of both loops
                } elseif ($source['status'] === 'degraded') {
                    $hasDegraded = true;
                }
            }
        }
        // Check webcam cameras
        elseif (isset($comp['cameras']) && is_array($comp['cameras'])) {
            foreach ($comp['cameras'] as $camera) {
                if ($camera['status'] === 'down') {
                    $hasDown = true;
                    break 2; // Break out of both loops
                } elseif ($camera['status'] === 'degraded') {
                    $hasDegraded = true;
                }
            }
        }
        // Check other components that have direct status
        elseif (isset($comp['status'])) {
            if ($comp['status'] === 'down') {
                $hasDown = true;
                break;
            } elseif ($comp['status'] === 'degraded') {
                $hasDegraded = true;
            }
        }
    }
    
    $health['status'] = $hasDown ? 'down' : ($hasDegraded ? 'degraded' : 'operational');
    
    // Check if airport is in maintenance mode
    if (isAirportInMaintenance($airport)) {
        $health['status'] = 'maintenance';
    }
    
    return $health;
}

/**
 * Check Public API health
 * 
 * Performs lightweight health checks on public API endpoints.
 * Results are cached to avoid excessive internal requests.
 * 
 * @return array {
 *   'status' => 'operational'|'degraded'|'down',
 *   'endpoints' => array
 * }
 */
function checkPublicApiHealth(): array {
    $health = [
        'status' => 'operational',
        'endpoints' => []
    ];
    
    // Define endpoints to check
    // Use kspb as test airport since it's commonly available
    $endpoints = [
        '/api/v1/status' => 'API Status',
        '/api/v1/airports' => 'List Airports',
        '/api/v1/airports/kspb' => 'Airport Details',
        '/api/v1/airports/kspb/weather' => 'Weather Data',
        '/api/v1/airports/kspb/webcams' => 'Webcam List',
        '/api/v1/airports/kspb/weather/history' => 'Weather History',
        '/api/v1/weather/bulk?airports=kspb' => 'Bulk Weather',
    ];
    
    $hasDown = false;
    $hasDegraded = false;
    
    // Check cache for recent health check results
    $cacheFile = __DIR__ . '/../cache/public_api_health.json';
    $cacheValid = false;
    $cachedHealth = null;
    
    if (file_exists($cacheFile)) {
        $cached = @json_decode(@file_get_contents($cacheFile), true);
        if (is_array($cached) && isset($cached['checked_at'])) {
            // Cache is valid for 30 seconds
            if (time() - $cached['checked_at'] < 30) {
                $cacheValid = true;
                $cachedHealth = $cached;
            }
        }
    }
    
    if ($cacheValid && $cachedHealth) {
        return $cachedHealth;
    }
    
    // Perform health checks
    foreach ($endpoints as $endpoint => $name) {
        $result = performPublicApiHealthCheck($endpoint);
        $health['endpoints'][] = [
            'name' => $name,
            'endpoint' => $endpoint,
            'status' => $result['status'],
            'message' => $result['message'],
            'response_time_ms' => $result['response_time_ms'],
            'lastChanged' => time()
        ];
        
        if ($result['status'] === 'down') {
            $hasDown = true;
        } elseif ($result['status'] === 'degraded') {
            $hasDegraded = true;
        }
    }
    
    $health['status'] = $hasDown ? 'down' : ($hasDegraded ? 'degraded' : 'operational');
    $health['checked_at'] = time();
    
    // Cache the result
    @file_put_contents($cacheFile, json_encode($health), LOCK_EX);
    
    return $health;
}

/**
 * Perform a health check on a single API endpoint
 * 
 * @param string $endpoint The endpoint path to check
 * @return array {status: string, message: string, response_time_ms: int}
 */
function performPublicApiHealthCheck(string $endpoint): array {
    $start = microtime(true);
    
    // Use internal request with health check header
    $context = stream_context_create([
        'http' => [
            'header' => "X-Health-Check: internal\r\n",
            'timeout' => 5,
            'ignore_errors' => true
        ]
    ]);
    
    // Use localhost to avoid going through the network
    $url = 'http://127.0.0.1' . $endpoint;
    $response = @file_get_contents($url, false, $context);
    $elapsed = (microtime(true) - $start) * 1000;
    
    // Check HTTP response code
    $httpCode = 0;
    if (isset($http_response_header) && is_array($http_response_header)) {
        foreach ($http_response_header as $header) {
            if (preg_match('/HTTP\/\d\.\d\s+(\d+)/', $header, $matches)) {
                $httpCode = (int)$matches[1];
                break;
            }
        }
    }
    
    // Determine status
    if ($response === false || $httpCode === 0) {
        return [
            'status' => 'down',
            'message' => 'Endpoint unreachable',
            'response_time_ms' => round($elapsed)
        ];
    }
    
    if ($httpCode >= 500) {
        return [
            'status' => 'down',
            'message' => 'Server error (HTTP ' . $httpCode . ')',
            'response_time_ms' => round($elapsed)
        ];
    }
    
    if ($httpCode >= 400) {
        return [
            'status' => 'degraded',
            'message' => 'Client error (HTTP ' . $httpCode . ')',
            'response_time_ms' => round($elapsed)
        ];
    }
    
    // Check response time (slow = degraded)
    if ($elapsed > 2000) {
        return [
            'status' => 'degraded',
            'message' => 'Slow response (' . round($elapsed) . 'ms)',
            'response_time_ms' => round($elapsed)
        ];
    }
    
    return [
        'status' => 'operational',
        'message' => 'OK (' . round($elapsed) . 'ms)',
        'response_time_ms' => round($elapsed)
    ];
}

// Load configuration
$config = loadConfig();
if ($config === null) {
    http_response_code(503);
    die('Service Unavailable: Configuration cannot be loaded');
}

// Load usage metrics (multi-period: hour, day, 7-day)
require_once __DIR__ . '/../lib/metrics.php';
$usageMetrics = metrics_get_rolling(METRICS_STATUS_PAGE_DAYS);
$multiPeriodMetrics = metrics_get_multi_period();

// Get system health (cached for 30s)
$systemHealth = getCachedStatusData(function() {
    return checkSystemHealth();
}, 'status_system_health', 30);

// Get node performance metrics (cached for 30s)
$nodePerformance = getCachedStatusData(function() {
    return getNodePerformance();
}, 'status_node_performance', 30);

// Get public API health (if enabled, cached for 30s)
require_once __DIR__ . '/../lib/public-api/config.php';
$publicApiHealth = null;
if (isPublicApiEnabled()) {
    $publicApiHealth = getCachedStatusData(function() {
        return checkPublicApiHealth();
    }, 'status_public_api_health', 30);
}

// Get airport health for each configured airport (cached for 30s)
// Note: checkAirportHealth() uses getCacheFile() from webcam-format-generation.php
// which is already included at the top of this file
$airportHealth = getCachedStatusData(function() use ($config) {
    $health = [];
    if (isset($config['airports']) && is_array($config['airports'])) {
        foreach ($config['airports'] as $airportId => $airport) {
            $health[] = checkAirportHealth($airportId, $airport);
        }
    }
    return $health;
}, 'status_airport_health', 30);

// Sort airports by status (down first, then maintenance, then degraded, then operational)
usort($airportHealth, function($a, $b) {
    $statusOrder = ['down' => 0, 'maintenance' => 1, 'degraded' => 2, 'operational' => 3];
    return $statusOrder[$a['status']] <=> $statusOrder[$b['status']];
});

// Prevent HTML output in CLI mode (tests, scripts)
// Functions are still available for testing, but HTML output is skipped
if (php_sapi_name() === 'cli') {
    // In CLI mode, just return - functions are already defined and can be tested
    return;
}

?>
<!DOCTYPE html>
<html lang="en">
<script>
// Apply dark mode immediately based on browser preference to prevent flash
(function() {
    if (window.matchMedia && window.matchMedia('(prefers-color-scheme: dark)').matches) {
        document.documentElement.classList.add('dark-mode');
    }
})();
</script>
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>AviationWX Status</title>
    
    <?php
    // Favicon and icon tags
    echo generateFaviconTags();
    echo "\n    ";
    ?>
    
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }
        
        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Oxygen, Ubuntu, Cantarell, sans-serif;
            line-height: 1.6;
            color: #333;
            background: #f5f5f5;
            padding: 2rem 1rem 4rem 1rem;
        }
        
        .container {
            max-width: 1200px;
            margin: 0 auto;
        }
        
        .header {
            background: white;
            padding: 2rem;
            border-radius: 8px;
            box-shadow: 0 2px 4px rgba(0,0,0,0.1);
            margin-bottom: 2rem;
        }
        
        .header h1 {
            font-size: 2rem;
            font-weight: 600;
            margin-bottom: 0.5rem;
            color: #1a1a1a;
        }
        
        .header .subtitle {
            color: #555;
            font-size: 0.9rem;
        }
        
        .status-indicator {
            font-size: 1.5rem;
            line-height: 1;
        }
        
        .status-indicator.green { color: #10b981; }
        .status-indicator.yellow { color: #f59e0b; }
        .status-indicator.red { color: #ef4444; }
        .status-indicator.orange { color: #f97316; }
        .status-indicator.gray { color: #9ca3af; }
        
        .status-text {
            font-size: 1.1rem;
            font-weight: 600;
        }
        
        .status-card {
            background: white;
            border-radius: 8px;
            box-shadow: 0 2px 4px rgba(0,0,0,0.1);
            margin-bottom: 1.5rem;
            overflow: hidden;
        }
        
        .status-card-header {
            padding: 1.25rem 1.5rem;
            border-bottom: 1px solid #eee;
            display: flex;
            align-items: center;
            justify-content: space-between;
        }
        
        .airport-card-header {
            cursor: pointer;
            user-select: none;
            transition: background-color 0.2s;
        }
        
        .airport-card-header:hover {
            background-color: #f9fafb;
        }
        
        .airport-card-header h2 {
            font-size: 1.25rem;
            font-weight: 600;
            color: #1a1a1a;
            display: flex;
            align-items: center;
            gap: 0.75rem;
        }
        
        .airport-header-content {
            display: flex;
            flex-direction: row;
            align-items: center;
            gap: 1.5rem;
            flex: 1;
        }
        
        .airport-views-summary {
            font-size: 0.75rem;
            color: #888;
            display: flex;
            align-items: center;
            gap: 0.35rem;
            flex: 1;
            justify-content: center;
        }
        
        .airport-views-summary .views-label {
            color: #999;
        }
        
        .airport-views-summary .views-period {
            color: #666;
            font-weight: 500;
        }
        
        .airport-views-summary .views-sep {
            color: #ccc;
        }
        
        .usage-metrics-block {
            font-size: 0.85rem;
            color: #666;
        }
        
        .usage-metrics-block .metrics-line {
            display: flex;
            flex-wrap: wrap;
            gap: 1.5rem;
            margin-bottom: 0.25rem;
        }
        
        .usage-metrics-block .metrics-line:last-child {
            margin-bottom: 0;
        }
        
        .usage-metrics-block .metric-group {
            display: inline-flex;
            align-items: center;
            gap: 0.35rem;
        }
        
        .usage-metrics-block .metric-label {
            color: #888;
        }
        
        .usage-metrics-block .metric-value {
            font-weight: 600;
            color: #333;
        }
        
        .usage-metrics-block .metric-breakdown {
            color: #666;
        }
        
        .status-card-header h2 {
            font-size: 1.25rem;
            font-weight: 600;
            color: #1a1a1a;
        }
        
        .expand-icon {
            display: inline-block;
            transition: transform 0.2s;
            font-size: 0.875rem;
            color: #6b7280;
        }
        
        .airport-card-header.expanded .expand-icon {
            transform: rotate(90deg);
        }
        
        .airport-card-body {
            overflow: hidden;
            transition: max-height 0.3s ease-out, padding 0.3s ease-out;
        }
        
        .status-card-body.airport-card-body.collapsed {
            max-height: 0;
            padding: 0;
        }
        
        .status-card-body.airport-card-body.expanded {
            max-height: 5000px;
            transition: max-height 0.5s ease-in, padding 0.5s ease-in;
        }
        
        .status-card-header .status-badge {
            display: flex;
            align-items: center;
            gap: 0.5rem;
            font-size: 0.9rem;
            font-weight: 600;
            text-transform: capitalize;
        }
        
        .status-card-body {
            padding: 1.5rem;
        }
        
        .component-list {
            list-style: none;
        }
        
        .component-item {
            display: flex;
            align-items: flex-start;
            justify-content: space-between;
            padding: 0.75rem 0;
            border-bottom: 1px solid #f0f0f0;
        }
        
        .component-item:last-child {
            border-bottom: none;
        }
        
        .component-info {
            flex: 1;
        }
        
        .component-name {
            font-weight: 600;
            color: #1a1a1a;
            margin-bottom: 0.25rem;
        }
        
        .component-message {
            font-size: 0.875rem;
            color: #555;
            margin-bottom: 0.25rem;
        }
        
        .component-message code {
            font-size: 0.85rem;
            background: #f5f5f5;
            padding: 0.15rem 0.4rem;
            border-radius: 3px;
        }
        
        .api-links {
            margin-bottom: 1rem;
            color: #666;
            font-size: 0.9rem;
        }
        
        .api-links a {
            color: #0066cc;
        }
        
        .api-links a:hover {
            text-decoration: underline;
        }
        
        .component-timestamp {
            font-size: 0.75rem;
            color: #999;
            font-style: italic;
        }
        
        .component-status {
            display: flex;
            align-items: center;
            gap: 0.5rem;
            font-size: 0.875rem;
            font-weight: 600;
            text-transform: capitalize;
            margin-left: 1rem;
        }
        
        .footer {
            text-align: center;
            color: #555;
            font-size: 0.875rem;
            margin-top: 3rem;
            padding-top: 2rem;
            border-top: 1px solid #eee;
        }
        
        .footer a {
            color: #0066cc;
            text-decoration: none;
        }
        
        .footer a:hover {
            text-decoration: underline;
        }
        
        .node-metrics-row {
            display: flex;
            flex-wrap: wrap;
            justify-content: center;
            gap: 2rem;
            padding-bottom: 1rem;
            margin-bottom: 0.75rem;
            border-bottom: 1px solid #f0f0f0;
        }
        
        .metric-inline {
            display: flex;
            align-items: baseline;
            gap: 0.5rem;
        }
        
        .metric-label-inline {
            font-size: 0.8rem;
            color: #6b7280;
        }
        
        .metric-value-inline {
            font-size: 0.95rem;
            font-weight: 600;
            color: #1a1a1a;
            font-variant-numeric: tabular-nums;
        }
        
        .metric-sub {
            font-size: 0.7rem;
            font-weight: 400;
            color: #9ca3af;
        }
        
        @media (max-width: 768px) {
            .component-item {
                flex-direction: column;
                gap: 0.5rem;
            }
            
            .component-status {
                margin-left: 0;
            }
            
            .node-metrics-row {
                flex-direction: column;
                gap: 0.75rem;
            }
            
            /* Airport header stacks vertically on mobile */
            .status-card-header.airport-card-header {
                flex-direction: column;
                align-items: flex-start;
                gap: 0.75rem;
            }
            
            .airport-header-content {
                flex-direction: column;
                align-items: flex-start;
                gap: 0.5rem;
                width: 100%;
            }
            
            .airport-views-summary {
                justify-content: flex-start;
            }
            
            .status-card-header.airport-card-header .status-badge {
                align-self: flex-start;
            }
        }
        
        /* ============================================
           Dark Mode Overrides for Status Page
           Automatically applied based on browser preference
           ============================================ */
        @media (prefers-color-scheme: dark) {
            body {
                background: #121212;
                color: #e0e0e0;
            }
        }
        
        body.dark-mode {
            background: #121212;
            color: #e0e0e0;
        }
        
        body.dark-mode .header {
            background: #1e1e1e;
            box-shadow: 0 2px 4px rgba(0,0,0,0.3);
        }
        
        body.dark-mode .header h1 {
            color: #e0e0e0;
        }
        
        body.dark-mode .header .subtitle {
            color: #a0a0a0;
        }
        
        body.dark-mode .status-card {
            background: #1e1e1e;
            box-shadow: 0 2px 4px rgba(0,0,0,0.3);
        }
        
        body.dark-mode .status-card-header {
            border-bottom-color: #333;
        }
        
        body.dark-mode .status-card-header h2 {
            color: #e0e0e0;
        }
        
        body.dark-mode .airport-card-header:hover {
            background-color: #252525;
        }
        
        body.dark-mode .airport-views-summary {
            color: #777;
        }
        
        body.dark-mode .airport-views-summary .views-label {
            color: #666;
        }
        
        body.dark-mode .airport-views-summary .views-period {
            color: #999;
        }
        
        body.dark-mode .airport-views-summary .views-sep {
            color: #555;
        }
        
        body.dark-mode .usage-metrics-block .metric-label {
            color: #777;
        }
        
        body.dark-mode .usage-metrics-block .metric-value {
            color: #e0e0e0;
        }
        
        body.dark-mode .usage-metrics-block .metric-breakdown {
            color: #999;
        }
        
        body.dark-mode .component-item {
            border-bottom-color: #333;
        }
        
        body.dark-mode .component-name {
            color: #e0e0e0;
        }
        
        body.dark-mode .component-message {
            color: #a0a0a0;
        }
        
        body.dark-mode .component-message code {
            background: #2a2a2a;
            color: #ff7eb6;
        }
        
        body.dark-mode .api-links {
            color: #a0a0a0;
        }
        
        body.dark-mode .api-links a {
            color: #4a9eff;
        }
        
        body.dark-mode .component-timestamp {
            color: #707070;
        }
        
        body.dark-mode .node-metrics-row {
            border-bottom-color: #333;
        }
        
        body.dark-mode .metric-label-inline {
            color: #a0a0a0;
        }
        
        body.dark-mode .metric-value-inline {
            color: #e0e0e0;
        }
        
        body.dark-mode .metric-sub {
            color: #707070;
        }
        
        body.dark-mode .footer {
            border-top-color: #333;
            color: #a0a0a0;
        }
        
        body.dark-mode .footer a {
            color: #4a9eff;
        }
        
        body.dark-mode h2[style*="font-size: 1.5rem"] {
            color: #e0e0e0;
        }
        
        }
    </style>
</head>
<body>
    <script>
    // Sync dark-mode class from html to body
    if (document.documentElement.classList.contains('dark-mode')) {
        document.body.classList.add('dark-mode');
    }
    </script>
    <div class="container">
        <div class="header">
            <h1>AviationWX Status</h1>
            <div class="subtitle">Real-time status of AviationWX.org services</div>
        </div>
        
        <!-- System Status Card -->
        <div class="status-card">
            <div class="status-card-header">
                <h2>System Status</h2>
            </div>
            <div class="status-card-body">
                <!-- Node Performance Row -->
                <div class="node-metrics-row">
                    <div class="metric-inline">
                        <span class="metric-label-inline">CPU Load</span>
                        <span class="metric-value-inline">
                            <?php 
                            $load = $nodePerformance['cpu_load'];
                            if ($load['1min'] !== null) {
                                echo htmlspecialchars($load['1min']) . ' <span class="metric-sub">(1m)</span> ';
                                echo htmlspecialchars($load['5min']) . ' <span class="metric-sub">(5m)</span> ';
                                echo htmlspecialchars($load['15min']) . ' <span class="metric-sub">(15m)</span>';
                            } else {
                                echo '—';
                            }
                            ?>
                        </span>
                    </div>
                    <div class="metric-inline">
                        <span class="metric-label-inline">Memory</span>
                        <span class="metric-value-inline">
                            <?php 
                            $memoryAvg = $nodePerformance['memory_average'] ?? null;
                            if ($memoryAvg && ($memoryAvg['1min'] !== null || $memoryAvg['5min'] !== null || $memoryAvg['15min'] !== null)) {
                                // Show averages if available
                                $parts = [];
                                if ($memoryAvg['1min'] !== null) {
                                    $parts[] = formatBytes((int)$memoryAvg['1min']) . ' <span class="metric-sub">(1m)</span>';
                                }
                                if ($memoryAvg['5min'] !== null) {
                                    $parts[] = formatBytes((int)$memoryAvg['5min']) . ' <span class="metric-sub">(5m)</span>';
                                }
                                if ($memoryAvg['15min'] !== null) {
                                    $parts[] = formatBytes((int)$memoryAvg['15min']) . ' <span class="metric-sub">(15m)</span>';
                                }
                                echo implode(' ', $parts);
                            } else {
                                // Fallback to current value if averages not available
                                echo formatBytes($nodePerformance['memory_used_bytes']);
                            }
                            ?>
                        </span>
                    </div>
                    <div class="metric-inline" title="Cache: <?php echo formatBytes($nodePerformance['storage_breakdown']['cache']); ?>, Uploads: <?php echo formatBytes($nodePerformance['storage_breakdown']['uploads']); ?>, Logs: <?php echo formatBytes($nodePerformance['storage_breakdown']['logs']); ?>">
                        <span class="metric-label-inline">Storage</span>
                        <span class="metric-value-inline">
                            <?php echo formatBytes($nodePerformance['storage_used_bytes']); ?>
                            <span class="metric-sub">(<?php 
                                $parts = [];
                                if ($nodePerformance['storage_breakdown']['cache'] > 0) {
                                    $parts[] = formatBytes($nodePerformance['storage_breakdown']['cache']) . ' cache';
                                }
                                if ($nodePerformance['storage_breakdown']['uploads'] > 0) {
                                    $parts[] = formatBytes($nodePerformance['storage_breakdown']['uploads']) . ' uploads';
                                }
                                if ($nodePerformance['storage_breakdown']['logs'] > 0) {
                                    $parts[] = formatBytes($nodePerformance['storage_breakdown']['logs']) . ' logs';
                                }
                                echo !empty($parts) ? implode(', ', $parts) : 'cache';
                            ?>)</span>
                        </span>
                    </div>
                </div>
                
                <ul class="component-list">
                    <?php foreach ($systemHealth['components'] as $component): ?>
                    <li class="component-item">
                        <div class="component-info">
                            <div class="component-name"><?php echo htmlspecialchars($component['name']); ?></div>
                            <div class="component-message"><?php echo htmlspecialchars($component['message']); ?></div>
                            <?php if (isset($component['metrics']) && is_array($component['metrics']) && !empty($component['metrics'])): ?>
                            <div class="component-metrics" style="margin-top: 0.25rem; font-size: 0.8rem; color: #888;">
                                <?php 
                                $metricParts = [];
                                if (isset($component['metrics']['total_generations_last_hour'])) {
                                    $metricParts[] = $component['metrics']['total_generations_last_hour'] . ' generated (1h)';
                                }
                                if (isset($component['metrics']['generation_success_rate']) && $component['metrics']['generation_success_rate'] < 100) {
                                    $metricParts[] = $component['metrics']['generation_success_rate'] . '% gen success';
                                }
                                if (isset($component['metrics']['promotion_success_rate']) && $component['metrics']['promotion_success_rate'] < 100) {
                                    $metricParts[] = $component['metrics']['promotion_success_rate'] . '% promo success';
                                }
                                echo htmlspecialchars(implode(' · ', $metricParts));
                                ?>
                            </div>
                            <?php endif; ?>
                            <?php if (isset($component['lastChanged']) && $component['lastChanged'] > 0): ?>
                            <div class="component-timestamp">
                                Last changed: <?php echo formatRelativeTime($component['lastChanged']); ?>
                                <span style="color: #ccc;"> • </span>
                                <?php echo formatAbsoluteTime($component['lastChanged']); ?>
                            </div>
                            <?php endif; ?>
                        </div>
                        <div class="component-status">
                            <span class="status-indicator <?php echo getStatusColor($component['status']); ?>">
                                <?php echo getStatusIcon($component['status']); ?>
                            </span>
                            <?php echo ucfirst($component['status']); ?>
                        </div>
                    </li>
                    <?php endforeach; ?>
                </ul>
            </div>
        </div>
        
        <?php if ($publicApiHealth !== null): ?>
        <!-- Public API Status Card -->
        <div class="status-card">
            <div class="status-card-header">
                <h2>Public API</h2>
                <span class="status-badge">
                    <span class="status-indicator <?php echo getStatusColor($publicApiHealth['status']); ?>">
                        <?php echo getStatusIcon($publicApiHealth['status']); ?>
                    </span>
                    <?php echo ucfirst($publicApiHealth['status']); ?>
                </span>
            </div>
            <div class="status-card-body">
                <div class="api-links">
                    <a href="https://api.aviationwx.org" target="_blank" rel="noopener">api.aviationwx.org</a> · 
                    <a href="https://api.aviationwx.org/openapi.json" target="_blank" rel="noopener">OpenAPI Spec</a>
                </div>
                <ul class="component-list">
                    <?php foreach ($publicApiHealth['endpoints'] as $endpoint): ?>
                    <li class="component-item">
                        <div class="component-info">
                            <div class="component-name"><?php echo htmlspecialchars($endpoint['name']); ?></div>
                            <div class="component-message">
                                <code><?php echo htmlspecialchars($endpoint['endpoint']); ?></code>
                                · <?php echo htmlspecialchars($endpoint['message']); ?>
                            </div>
                        </div>
                        <div class="component-status">
                            <span class="status-indicator <?php echo getStatusColor($endpoint['status']); ?>">
                                <?php echo getStatusIcon($endpoint['status']); ?>
                            </span>
                            <?php echo ucfirst($endpoint['status']); ?>
                        </div>
                    </li>
                    <?php endforeach; ?>
                </ul>
            </div>
        </div>
        <?php endif; ?>
        
        <!-- Airport Status Cards -->
        <?php if (!empty($airportHealth)): ?>
        <h2 style="font-size: 1.5rem; font-weight: 600; margin-bottom: 1rem; color: #1a1a1a;">Airport Status</h2>
        <?php foreach ($airportHealth as $airport): ?>
        <?php 
        // Determine if airport should be expanded by default (not operational or maintenance = expanded)
        $isExpanded = ($airport['status'] !== 'operational' && $airport['status'] !== 'maintenance');
        ?>
        <?php 
        // Get multi-period metrics for this airport
        $airportIdLower = strtolower($airport['id']);
        $airportPeriodMetrics = $multiPeriodMetrics[$airportIdLower] ?? null;
        // Get view counts (default to 0 if no metrics)
        $hourViews = $airportPeriodMetrics['hour']['page_views'] ?? 0;
        $dayViews = $airportPeriodMetrics['day']['page_views'] ?? 0;
        $weekViews = $airportPeriodMetrics['week']['page_views'] ?? 0;
        ?>
        <div class="status-card">
            <div class="status-card-header airport-card-header <?php echo $isExpanded ? 'expanded' : ''; ?>" 
                 onclick="toggleAirport('<?php echo htmlspecialchars($airport['id']); ?>')">
                <div class="airport-header-content">
                    <h2>
                        <span class="expand-icon">▶</span>
                        <?php echo htmlspecialchars($airport['id']); ?>
                    </h2>
                    <div class="airport-views-summary">
                        <span class="views-label">Views:</span>
                        <span class="views-period" title="Last hour"><?php echo number_format($hourViews); ?>/hour</span>
                        <span class="views-sep">·</span>
                        <span class="views-period" title="Today"><?php echo number_format($dayViews); ?>/day</span>
                        <span class="views-sep">·</span>
                        <span class="views-period" title="Last 7 days"><?php echo number_format($weekViews); ?>/week</span>
                    </div>
                </div>
                <span class="status-badge">
                    <?php if ($airport['status'] === 'maintenance'): ?>
                        Under Maintenance <span class="status-indicator <?php echo getStatusColor($airport['status']); ?>"><?php echo getStatusIcon($airport['status']); ?></span>
                    <?php else: ?>
                        <span class="status-indicator <?php echo getStatusColor($airport['status']); ?>">
                            <?php echo getStatusIcon($airport['status']); ?>
                        </span>
                        <?php echo ucfirst($airport['status']); ?>
                    <?php endif; ?>
                </span>
            </div>
            <div class="status-card-body airport-card-body <?php echo $isExpanded ? 'expanded' : 'collapsed'; ?>" 
                 id="airport-<?php echo htmlspecialchars($airport['id']); ?>-body">
                <ul class="component-list">
                    <?php foreach ($airport['components'] as $component): ?>
                    <?php 
                    // Check if this is weather or webcams (which have individual sources)
                    $isWeather = ($component['name'] === 'Weather Sources' && isset($component['sources']));
                    $isWebcams = (isset($component['cameras']) && is_array($component['cameras']) && !empty($component['cameras']));
                    ?>
                    <?php if ($isWeather): ?>
                        <!-- Weather Sources - show individual sources -->
                        <?php foreach ($component['sources'] as $source): ?>
                        <li class="component-item">
                            <div class="component-info">
                                <div class="component-name"><?php echo htmlspecialchars($source['name']); ?></div>
                                <div class="component-message"><?php echo htmlspecialchars($source['message']); ?></div>
                                <?php if (isset($source['lastChanged']) && $source['lastChanged'] > 0): ?>
                                <div class="component-timestamp">
                                    Last changed: <?php echo formatRelativeTime($source['lastChanged']); ?>
                                    <span style="color: #ccc;"> • </span>
                                    <?php echo formatAbsoluteTime($source['lastChanged']); ?>
                                </div>
                                <?php endif; ?>
                            </div>
                            <div class="component-status">
                                <span class="status-indicator <?php echo getStatusColor($source['status']); ?>">
                                    <?php echo getStatusIcon($source['status']); ?>
                                </span>
                                <?php echo ucfirst($source['status']); ?>
                            </div>
                        </li>
                        <?php endforeach; ?>
                    <?php elseif ($isWebcams): ?>
                        <!-- Webcams - show individual cameras without overall status -->
                        <?php foreach ($component['cameras'] as $camera): ?>
                        <li class="component-item">
                            <div class="component-info">
                                <div class="component-name"><?php echo htmlspecialchars($camera['name']); ?></div>
                                <div class="component-message"><?php echo htmlspecialchars($camera['message']); ?></div>
                                <?php if (isset($camera['lastChanged']) && $camera['lastChanged'] > 0): ?>
                                <div class="component-timestamp">
                                    Last changed: <?php echo formatRelativeTime($camera['lastChanged']); ?>
                                    <span style="color: #ccc;"> • </span>
                                    <?php echo formatAbsoluteTime($camera['lastChanged']); ?>
                                </div>
                                <?php endif; ?>
                            </div>
                            <div class="component-status">
                                <span class="status-indicator <?php echo getStatusColor($camera['status']); ?>">
                                    <?php echo getStatusIcon($camera['status']); ?>
                                </span>
                                <?php echo ucfirst($camera['status']); ?>
                            </div>
                        </li>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <!-- Other components - show with status indicator -->
                        <li class="component-item">
                            <div class="component-info">
                                <div class="component-name"><?php echo htmlspecialchars($component['name']); ?></div>
                                <div class="component-message"><?php echo htmlspecialchars($component['message']); ?></div>
                                <?php if (isset($component['lastChanged']) && $component['lastChanged'] > 0): ?>
                                <div class="component-timestamp">
                                    Last changed: <?php echo formatRelativeTime($component['lastChanged']); ?>
                                    <span style="color: #ccc;"> • </span>
                                    <?php echo formatAbsoluteTime($component['lastChanged']); ?>
                                </div>
                                <?php endif; ?>
                            </div>
                            <div class="component-status">
                                <span class="status-indicator <?php echo getStatusColor($component['status']); ?>">
                                    <?php echo getStatusIcon($component['status']); ?>
                                </span>
                                <?php echo ucfirst($component['status']); ?>
                            </div>
                        </li>
                    <?php endif; ?>
                    <?php endforeach; ?>
                    
                    <?php 
                    // Get usage metrics for this airport (use already loaded $airportPeriodMetrics from header)
                    $webcamMetrics = $airportPeriodMetrics['webcams'] ?? [];
                    $weeklyAirportMetrics = $usageMetrics['airports'][$airportIdLower] ?? null;
                    
                    // Calculate webcam totals
                    $totalWebcam = 0;
                    $formatTotals = ['jpg' => 0, 'webp' => 0, 'avif' => 0];
                    $sizeTotals = ['primary' => 0, 'thumb' => 0, 'small' => 0, 'medium' => 0, 'large' => 0, 'full' => 0];
                    foreach ($webcamMetrics as $camData) {
                        foreach ($camData['by_format'] ?? [] as $fmt => $count) {
                            $formatTotals[$fmt] += $count;
                            $totalWebcam += $count;
                        }
                        foreach ($camData['by_size'] ?? [] as $sz => $count) {
                            $sizeTotals[$sz] += $count;
                        }
                    }
                    
                    // Get weather requests (default to 0)
                    $weatherRequests = $weeklyAirportMetrics['weather_requests'] ?? 0;
                    ?>
                    
                    <li class="component-item">
                        <div class="component-info">
                            <div class="component-name">Requests (7d)</div>
                            <div class="component-message usage-metrics-block">
                                <div class="metrics-line">
                                    <span class="metric-group">
                                        <span class="metric-label">Weather:</span>
                                        <span class="metric-value"><?php echo number_format($weatherRequests); ?></span>
                                    </span>
                                    <span class="metric-group">
                                        <span class="metric-label">Webcam:</span>
                                        <span class="metric-value"><?php echo number_format($totalWebcam); ?></span>
                                    </span>
                                </div>
                                <?php if ($totalWebcam > 0): ?>
                                <div class="metrics-line">
                                    <span class="metric-group">
                                        <span class="metric-label">Formats:</span>
                                        <span class="metric-breakdown">
                                            <?php
                                            $formatParts = [];
                                            foreach ($formatTotals as $fmt => $count) {
                                                if ($count > 0) {
                                                    $pct = round(($count / $totalWebcam) * 100);
                                                    $formatParts[] = strtoupper($fmt) . " {$pct}%";
                                                }
                                            }
                                            echo implode(' · ', $formatParts);
                                            ?>
                                        </span>
                                    </span>
                                </div>
                                <div class="metrics-line">
                                    <span class="metric-group">
                                        <span class="metric-label">Sizes:</span>
                                        <span class="metric-breakdown">
                                            <?php
                                            $sizeParts = [];
                                            $sizeTotal = array_sum($sizeTotals);
                                            if ($sizeTotal > 0) {
                                                foreach ($sizeTotals as $sz => $count) {
                                                    if ($count > 0) {
                                                        $pct = round(($count / $sizeTotal) * 100);
                                                        $sizeParts[] = ucfirst($sz) . " {$pct}%";
                                                    }
                                                }
                                            }
                                            echo implode(' · ', $sizeParts);
                                            ?>
                                        </span>
                                    </span>
                                </div>
                                <?php endif; ?>
                            </div>
                        </div>
                    </li>
                </ul>
            </div>
        </div>
        <?php endforeach; ?>
        <?php endif; ?>
        
        <div class="footer">
            <p>
                &copy; <?= date('Y') ?> <a href="https://aviationwx.org">AviationWX.org</a> • 
                <a href="https://guides.aviationwx.org">Guides</a> • 
                <a href="https://aviationwx.org#about-the-project">Built for pilots, by pilots</a> • 
                <a href="https://github.com/alexwitherspoon/aviationwx.org" target="_blank" rel="noopener">Open Source<?php $gitSha = getGitSha(); echo $gitSha ? ' - ' . htmlspecialchars($gitSha) : ''; ?></a> • 
                <a href="https://terms.aviationwx.org">Terms of Service</a> • 
                <a href="https://api.aviationwx.org">API</a> • 
                <a href="https://status.aviationwx.org">Status</a>
            </p>
            <p style="margin-top: 0.5rem; font-size: 0.75rem; color: #999;">
                Last updated: <?php echo date('Y-m-d H:i:s T'); ?>
            </p>
        </div>
    </div>
    
    <script>
        (function() {
            'use strict';
            
            function toggleAirport(airportId) {
                const header = document.querySelector(`[onclick="toggleAirport('${airportId}')"]`);
                const body = document.getElementById(`airport-${airportId}-body`);
                
                if (header && body) {
                    const isExpanded = header.classList.contains('expanded');
                    
                    if (isExpanded) {
                        header.classList.remove('expanded');
                        body.classList.remove('expanded');
                        body.classList.add('collapsed');
                    } else {
                        header.classList.add('expanded');
                        body.classList.remove('collapsed');
                        body.classList.add('expanded');
                    }
                }
            }
            
            // Expose to global scope for onclick handlers
            window.toggleAirport = toggleAirport;
        })();
    </script>
</body>
</html>


