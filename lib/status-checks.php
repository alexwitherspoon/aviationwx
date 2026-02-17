<?php
/**
 * Status Page Health Checks
 * 
 * All health check functions for the status page system.
 * Extracted from pages/status.php for reusability and maintainability.
 * 
 * These functions can be used by:
 * - Status page (pages/status.php)
 * - CLI monitoring tools
 * - External health check scripts
 * - Prometheus exporters
 */

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/constants.php';
require_once __DIR__ . '/logger.php';
require_once __DIR__ . '/cache-paths.php';
require_once __DIR__ . '/webcam-metadata.php';
require_once __DIR__ . '/webcam-variant-manifest.php';
require_once __DIR__ . '/webcam-image-metrics.php';
require_once __DIR__ . '/weather/utils.php';
require_once __DIR__ . '/circuit-breaker.php';
require_once __DIR__ . '/runways.php';
require_once __DIR__ . '/variant-health.php';
require_once __DIR__ . '/weather-health.php';
require_once __DIR__ . '/weather/outage-detection.php';

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
    
    $configPath = getenv('CONFIG_PATH') ?: __DIR__ . '/../config/airports.json';
    $configReadable = file_exists($configPath) && is_readable($configPath);
    $config = null;
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
    
    $cacheDir = CACHE_BASE_DIR;
    $webcamCacheDir = CACHE_WEBCAMS_DIR;
    $cacheExists = is_dir($cacheDir);
    $cacheWritable = $cacheExists && is_writable($cacheDir);
    $webcamCacheExists = is_dir($webcamCacheDir);
    $webcamCacheWritable = $webcamCacheExists && is_writable($webcamCacheDir);
    
    $cacheStatus = ($cacheExists && $cacheWritable && $webcamCacheExists && $webcamCacheWritable) ? 'operational' : 'down';
    
    $latestCacheMtime = 0;
    $webcamImageCount = 0;
    $webcamSizeBytes = 0;
    $weatherCacheCount = 0;
    $weatherSizeBytes = 0;
    
    if ($cacheExists) {
        $latestCacheMtime = filemtime($cacheDir);
        
        // Count webcam images (new directory structure: cache/webcams/{airport}/{cam}/*.jpg)
        if ($webcamCacheExists) {
            $webcamMtime = filemtime($webcamCacheDir);
            if ($webcamMtime > $latestCacheMtime) {
                $latestCacheMtime = $webcamMtime;
            }
            
            // Scan all airport directories
            $airportDirs = glob($webcamCacheDir . '/*', GLOB_ONLYDIR);
            foreach ($airportDirs as $airportDir) {
                $camDirs = glob($airportDir . '/*', GLOB_ONLYDIR);
                foreach ($camDirs as $camDir) {
                    $files = glob($camDir . '/*.{jpg,webp}', GLOB_BRACE);
                    if ($files) {
                        foreach ($files as $file) {
                            // Skip symlinks to avoid double-counting
                            if (!is_link($file) && file_exists($file)) {
                                $size = @filesize($file);
                                $mtime = @filemtime($file);
                                if ($size === false || $mtime === false) {
                                    continue;
                                }
                                $webcamImageCount++;
                                $webcamSizeBytes += $size;
                                if ($mtime > $latestCacheMtime) {
                                    $latestCacheMtime = $mtime;
                                }
                            }
                        }
                    }
                }
            }
        }
        
        // Count weather cache files
        $weatherFiles = glob(CACHE_WEATHER_DIR . '/*.json');
        if ($weatherFiles) {
            foreach ($weatherFiles as $file) {
                $weatherCacheCount++;
                $weatherSizeBytes += filesize($file);
                $mtime = filemtime($file);
                if ($mtime > $latestCacheMtime) {
                    $latestCacheMtime = $mtime;
                }
            }
        }
    }
    
    // Format sizes
    $webcamSizeMB = round($webcamSizeBytes / (1024 * 1024), 1);
    $weatherSizeKB = round($weatherSizeBytes / 1024, 1);
    $totalSizeMB = round(($webcamSizeBytes + $weatherSizeBytes) / (1024 * 1024), 1);
    
    // Build detailed message
    $cacheMessage = '';
    if ($cacheStatus === 'operational') {
        $parts = [];
        if ($webcamImageCount > 0) {
            $parts[] = "{$webcamImageCount} webcam images ({$webcamSizeMB} MB)";
        }
        if ($weatherCacheCount > 0) {
            $parts[] = "{$weatherCacheCount} weather files ({$weatherSizeKB} KB)";
        }
        
        if (!empty($parts)) {
            $cacheMessage = implode(' • ', $parts);
        } else {
            $cacheMessage = 'Cache directories accessible';
        }
    } else {
        $cacheMessage = 'Cache directories missing or not writable';
    }
    
    $health['components']['cache'] = [
        'name' => 'Cache System',
        'status' => $cacheStatus,
        'message' => $cacheMessage,
        'lastChanged' => $latestCacheMtime > 0 ? $latestCacheMtime : 0,
        'details' => [
            'webcam_images' => $webcamImageCount,
            'webcam_size_mb' => $webcamSizeMB,
            'weather_files' => $weatherCacheCount,
            'weather_size_kb' => $weatherSizeKB,
            'total_size_mb' => $totalSizeMB
        ]
    ];
    
    $apcuAvailable = function_exists('apcu_fetch');
    // APCu status doesn't change, so use 0 for lastChanged
    $health['components']['apcu'] = [
        'name' => 'APCu Cache',
        'status' => $apcuAvailable ? 'operational' : 'degraded',
        'message' => $apcuAvailable ? 'APCu available' : 'APCu not available (performance may be reduced)',
        'lastChanged' => 0
    ];
    
    $logFile = AVIATIONWX_APP_LOG_FILE;
    $logDir = dirname($logFile);
    $logMtime = file_exists($logFile) ? filemtime($logFile) : 0;
    $hasRecentLogs = false;
    if ($logMtime > 0) {
        $hasRecentLogs = (time() - $logMtime) < STATUS_RECENT_LOG_THRESHOLD_SECONDS;
    }
    
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
    
    // Check internal error rate (excludes external data source failures)
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
    
    $ftpSftpHealth = checkFtpSftpServices();
    $health['components']['ftp_sftp'] = $ftpSftpHealth;
    
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
    
    $variantHealth = checkVariantGenerationHealth();
    $health['components']['variant_generation'] = $variantHealth;
    
    // Uses cached data from weather-health.php
    $weatherDataHealth = weather_health_get_status();
    
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
    
    // Runway cache (FAA/OurAirports)
    $runwayCacheHealth = checkRunwayCacheHealth($config);
    $health['components']['runway_cache'] = $runwayCacheHealth;
    
    // Magnetic declination (NOAA geomag) - only when geomag_api_key is configured
    $geomagKey = getGlobalConfig('geomag_api_key');
    if ($geomagKey !== null && $geomagKey !== '' && trim((string) $geomagKey) !== '') {
        $geomagHealth = checkMagneticDeclinationHealth();
        $health['components']['magnetic_declination'] = $geomagHealth;
    }
    
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
    return variant_health_get_status();
}

/**
 * Check runway cache health (FAA/OurAirports)
 *
 * @param array|null $config Full config (for airport count and missing list)
 * @return array Component health array
 */
function checkRunwayCacheHealth(?array $config): array {
    $path = CACHE_RUNWAYS_DATA_FILE;
    if (!file_exists($path)) {
        return [
            'name' => 'Runway Cache',
            'status' => 'down',
            'message' => 'Runway cache missing (run fetch-runways.php)',
            'lastChanged' => 0,
        ];
    }
    $mtime = filemtime($path);
    $age = time() - $mtime;
    $needsRefresh = runwaysCacheNeedsRefresh();
    $data = @json_decode((string) file_get_contents($path), true);
    $airportCount = isset($data['airports']) && is_array($data['airports']) ? count($data['airports']) : 0;
    $fetchedAt = $data['fetched_at'] ?? $mtime;
    $status = $needsRefresh ? 'degraded' : 'operational';
    $message = "{$airportCount} airports in cache";
    if ($needsRefresh) {
        $message .= ' • Stale (refresh recommended)';
    } else {
        $message .= ' • Up to date';
    }
    $details = ['airports_in_cache' => $airportCount, 'age_days' => (int) round($age / 86400)];
    $airports = $config['airports'] ?? [];
    if (!empty($airports)) {
        $missing = [];
        $identsToTry = static function ($airportId, $airport) {
            $id = strtoupper($airportId);
            $icao = isset($airport['icao']) ? strtoupper((string) $airport['icao']) : null;
            $faa = isset($airport['faa']) ? strtoupper((string) $airport['faa']) : null;
            return array_values(array_unique(array_filter([$icao, $faa, $id])));
        };
        $cacheAirports = $data['airports'] ?? [];
        foreach ($airports as $airportId => $airport) {
            if (!is_array($airport) || (!empty($airport['runways']) && is_array($airport['runways']))) {
                continue;
            }
            $idents = $identsToTry($airportId, $airport);
            $found = false;
            foreach ($idents as $ident) {
                if (isset($cacheAirports[$ident]['segments']) && !empty($cacheAirports[$ident]['segments'])) {
                    $found = true;
                    break;
                }
            }
            if (!$found) {
                $missing[] = $airportId;
            }
        }
        if (!empty($missing)) {
            $details['airports_missing_runways'] = $missing;
            $message .= ' • ' . count($missing) . ' configured airports without runway data';
        }
    }
    return [
        'name' => 'Runway Cache',
        'status' => $status,
        'message' => $message,
        'lastChanged' => $fetchedAt,
        'details' => $details,
    ];
}

/**
 * Check magnetic declination (geomag) health when API key is configured
 *
 * @return array Component health array
 */
function checkMagneticDeclinationHealth(): array {
    $breaker = checkGeomagCircuitBreaker();
    $geomagDir = CACHE_GEOMAG_DIR;
    $cacheExists = is_dir($geomagDir);
    $cacheFiles = $cacheExists ? glob($geomagDir . '/*.json') : [];
    $cacheCount = is_array($cacheFiles) ? count($cacheFiles) : 0;
    if ($breaker['skip']) {
        return [
            'name' => 'Magnetic Declination',
            'status' => 'degraded',
            'message' => 'Circuit breaker open: ' . ($breaker['last_failure_reason'] ?? $breaker['reason'] ?? 'API temporarily disabled'),
            'lastChanged' => 0,
            'details' => ['cached_locations' => $cacheCount],
        ];
    }
    $status = 'operational';
    $message = 'NOAA geomag API available';
    if ($cacheCount > 0) {
        $message .= " • {$cacheCount} locations cached";
    }
    return [
        'name' => 'Magnetic Declination',
        'status' => $status,
        'message' => $message,
        'lastChanged' => 0,
        'details' => ['cached_locations' => $cacheCount],
    ];
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
    
    // Use @ to suppress errors for non-critical process checks
    $vsftpdRunning = false;
    if (function_exists('exec')) {
        @exec('pgrep -x vsftpd 2>/dev/null', $output, $code);
        $vsftpdRunning = ($code === 0 && !empty($output));
    }
    $services['vsftpd']['running'] = $vsftpdRunning;
    
    $sshdRunning = false;
    if (function_exists('exec')) {
        @exec('pgrep -x sshd 2>/dev/null', $output, $code);
        $sshdRunning = ($code === 0 && !empty($output));
    }
    $services['sshd']['running'] = $sshdRunning;
    
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
        'lastChanged' => 0,
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
 *   'components' => array<string, array>,
 *   'limited_availability' => bool,
 *   'all_sources_down' => bool  True when every local source is down (for limited_availability display)
 * }
 */
function checkAirportHealth(string $airportId, array $airport): array {
    $health = [
        'id' => strtoupper($airportId),
        'status' => 'operational',
        'components' => []
    ];
    
    $sourceTimestamps = getSourceTimestamps($airportId, $airport);
    
    $weatherRefresh = isset($airport['weather_refresh_seconds']) 
        ? intval($airport['weather_refresh_seconds']) 
        : getDefaultWeatherRefresh();
    
    // 3-tier staleness model: warning (operational), error (degraded), failclosed (down)
    // Prevents showing stale data to pilots while allowing graceful degradation
    $warningSeconds = getStaleWarningSeconds($airport);
    $errorSeconds = getStaleErrorSeconds($airport);
    $failclosedSeconds = getStaleFailclosedSeconds($airport);
    $metarFailclosedSeconds = getMetarStaleFailclosedSeconds();
    
    $weatherSources = [];
    
    // Helper function to get HTTP error code and failure reason from backoff state
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
        $failureReason = isset($state['last_failure_reason']) ? $state['last_failure_reason'] : null;
        
        // Return HTTP code only for recent errors or active backoff to avoid stale error display
        if ($httpCode !== null && $httpCode >= 400 && $httpCode < 600) {
            $now = time();
            $inBackoff = $nextAllowed > $now;
            $errorAge = $errorTime > 0 ? ($now - $errorTime) : 0;
            $oneHour = 3600;
            
            if ($inBackoff || $errorAge < $oneHour) {
                return [
                    'http_code' => $httpCode,
                    'error_time' => $errorTime,
                    'in_backoff' => $inBackoff,
                    'failure_reason' => $failureReason
                ];
            }
        }
        
        // Also return failure reason if in backoff, even without HTTP code
        $now = time();
        $inBackoff = $nextAllowed > $now;
        if ($inBackoff && $failureReason !== null) {
            return [
                'http_code' => null,
                'error_time' => $errorTime,
                'in_backoff' => true,
                'failure_reason' => $failureReason
            ];
        }
        
        return null;
    };
    
    // Check primary weather source (first non-backup source from sources array)
    $sourceType = getPrimaryWeatherSourceType($airport);
    $primaryIsMetar = $sourceType === 'metar';
    
    // Handle primary source - either METAR (using metar timestamps) or other source (using primary timestamps)
    $primaryTimestamps = $primaryIsMetar ? $sourceTimestamps['metar'] : $sourceTimestamps['primary'];
    if ($sourceType && $primaryTimestamps['available']) {
        $sourceName = getWeatherSourceDisplayName($sourceType);
        $primaryStatus = 'down';
        $primaryMessage = 'No data available';
        $primaryLastChanged = $primaryTimestamps['timestamp'];
        
        if ($primaryLastChanged > 0) {
            $primaryAge = $primaryTimestamps['age'];
            
            // Apply 3-tier staleness model: operational (fresh/warning) -> degraded (error) -> down (failclosed)
            if ($sourceType === 'metar') {
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
        
        $httpErrorInfo = $getHttpErrorInfo($airportId, 'primary');
        if ($httpErrorInfo !== null && ($primaryStatus === 'down' || $primaryStatus === 'degraded' || $httpErrorInfo['in_backoff'])) {
            if ($httpErrorInfo['http_code'] !== null) {
                $primaryMessage .= ' - HTTP ' . $httpErrorInfo['http_code'];
            }
            if (isset($httpErrorInfo['failure_reason']) && $httpErrorInfo['failure_reason'] !== null) {
                $primaryMessage .= ' (' . $httpErrorInfo['failure_reason'] . ')';
            }
        }
        
        $weatherSources[] = [
            'name' => $sourceName,
            'status' => $primaryStatus,
            'message' => $primaryMessage,
            'lastChanged' => $primaryLastChanged
        ];
    }
    
    $isMetarEnabled = isMetarEnabled($airport);
    $isMetarPrimary = $primaryIsMetar;
    
    // METAR shown separately if primary or configured as supplement
    if ($isMetarPrimary) {
        // Already added above
    } elseif ($isMetarEnabled && $sourceTimestamps['metar']['available']) {
        $metarStatus = 'down';
        $metarMessage = 'No data available';
        $metarLastChanged = $sourceTimestamps['metar']['timestamp'];
        
        if ($metarLastChanged > 0) {
            $metarAge = $sourceTimestamps['metar']['age'];
            
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
        
        $httpErrorInfo = $getHttpErrorInfo($airportId, 'metar');
        if ($httpErrorInfo !== null && ($metarStatus === 'down' || $metarStatus === 'degraded' || $httpErrorInfo['in_backoff'])) {
            if ($httpErrorInfo['http_code'] !== null) {
                $metarMessage .= ' - HTTP ' . $httpErrorInfo['http_code'];
            }
            if (isset($httpErrorInfo['failure_reason']) && $httpErrorInfo['failure_reason'] !== null) {
                $metarMessage .= ' (' . $httpErrorInfo['failure_reason'] . ')';
            }
        }
        
        $weatherSources[] = [
            'name' => 'NOAA Aviation Weather',
            'status' => $metarStatus,
            'message' => $metarMessage,
            'lastChanged' => $metarLastChanged
        ];
    }
    
    // Check for backup sources (sources with backup: true)
    $backupSourceType = null;
    if (isset($airport['weather_sources']) && is_array($airport['weather_sources'])) {
        foreach ($airport['weather_sources'] as $source) {
            if (!empty($source['backup']) && !empty($source['type'])) {
                $backupSourceType = $source['type'];
                break;
            }
        }
    }
    if ($backupSourceType && $sourceTimestamps['backup']['available']) {
        $backupSourceName = getWeatherSourceDisplayName($backupSourceType) . ' (Backup)';
        $backupStatus = 'standby';
        $backupMessage = 'Standby';
        $backupLastChanged = $sourceTimestamps['backup']['timestamp'];
        
        // Determine if backup is actively providing data (vs standby)
        // Backup is active when it has fresh data and primary source is stale
        $backupActive = false;
        $weatherCacheFile = getWeatherCachePath($airportId);
        if (file_exists($weatherCacheFile)) {
            $weatherData = @json_decode(@file_get_contents($weatherCacheFile), true);
            if (is_array($weatherData)) {
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
                    $backupActive = ($backupAge < $warningSeconds) && ($primaryAge >= $warningSeconds);
                }
            }
        }
        
        if ($backupLastChanged > 0) {
            $backupAge = $sourceTimestamps['backup']['age'];
            
            $backupCircuit = checkWeatherCircuitBreaker($airportId, 'backup');
            
            if ($backupCircuit['skip']) {
                $backupStatus = 'failed';
                $failureReason = $backupCircuit['last_failure_reason'] ?? null;
                $backupMessage = $failureReason ? 'Circuit breaker open: ' . $failureReason : 'Circuit breaker open';
            } elseif ($backupActive) {
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
            $backupCircuit = checkWeatherCircuitBreaker($airportId, 'backup');
            if ($backupCircuit['skip']) {
                $backupStatus = 'failed';
                $failureReason = $backupCircuit['last_failure_reason'] ?? null;
                $backupMessage = $failureReason ? 'Circuit breaker open: ' . $failureReason : 'Circuit breaker open';
            } else {
                $backupStatus = $backupActive ? 'operational' : 'standby';
                $backupMessage = $backupActive ? 'Active (no timestamp)' : 'Standby (no timestamp)';
            }
        }
        
        // Check for HTTP error code to append to message
        $httpErrorInfo = $getHttpErrorInfo($airportId, 'backup');
        if ($httpErrorInfo !== null && ($backupStatus === 'down' || $backupStatus === 'degraded' || $backupStatus === 'failed' || $httpErrorInfo['in_backoff'])) {
            if ($httpErrorInfo['http_code'] !== null) {
                $backupMessage .= ' - HTTP ' . $httpErrorInfo['http_code'];
            }
            if (isset($httpErrorInfo['failure_reason']) && $httpErrorInfo['failure_reason'] !== null) {
                $backupMessage .= ' (' . $httpErrorInfo['failure_reason'] . ')';
            }
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
    $totalCams = 0;
    
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
            // Use current.jpg/current.webp symlinks which are the standard serving endpoints
            // These symlinks are created by the scheduler and point to the latest 720p variant
            $cacheDir = getWebcamCameraDir($airportId, $idx);
            $cacheJpg = $cacheDir . '/current.jpg';
            $cacheWebp = $cacheDir . '/current.webp';
            
            // Check if cache files exist and are readable
            $cacheExists = (@file_exists($cacheJpg) && @is_readable($cacheJpg)) 
                        || (@file_exists($cacheWebp) && @is_readable($cacheWebp));
            
            // Check variant availability (height-based variants)
            
            // Get last completed timestamp to avoid race conditions
            // (latest may still be generating variants)
            $lastCompletedTimestamp = getLastCompletedImageTimestamp($airportId, $idx);
            $variantCoverage = null;
            
            if ($lastCompletedTimestamp > 0) {
                // Use manifest-based coverage (dynamic based on actual generation)
                $variantCoverage = getVariantCoverage($airportId, $idx, $lastCompletedTimestamp);
            }
            
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
                
                // Apply 3-tier staleness model (same as weather) for safety-critical data freshness
                $warningThreshold = getStaleWarningSeconds($airport);
                $errorThreshold = getStaleErrorSeconds($airport);
                $failclosedThreshold = getStaleFailclosedSeconds($airport);
                
                $errorFile = $cacheJpg . '.error.json';
                $hasError = !$isPush && file_exists($errorFile);
                
                // Check backoff state (pull cameras only)
                $inBackoff = false;
                $failureReason = null;
                if (!$isPush) {
                    $backoffData = getCachedBackoffData();
                    $key = $airportId . '_' . $idx;
                    if (isset($backoffData[$key])) {
                        $backoffUntil = $backoffData[$key]['next_allowed_time'] ?? 0;
                        $inBackoff = $backoffUntil > time();
                        if ($inBackoff) {
                            $failureReason = $backoffData[$key]['last_failure_reason'] ?? null;
                        }
                    }
                }
                
                // Prioritize error/backoff status over staleness (circuit breaker takes precedence)
                if ($hasError || $inBackoff) {
                    $camStatus = 'degraded';
                    if ($inBackoff && $failureReason) {
                        $camMessage = 'In backoff: ' . $failureReason;
                    } else {
                        $camMessage = $hasError ? 'Has errors' : 'In backoff';
                    }
                } elseif ($cacheAge < $warningThreshold) {
                    $camStatus = 'operational';
                    $camMessage = '';  // No status message needed when healthy - green indicator + timestamp suffice
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
            
            // Get image metrics for this camera
            $imageMetrics = getWebcamImageMetrics($airportId, $idx);
            $verified = $imageMetrics['verified'] ?? 0;
            $rejected = $imageMetrics['rejected'] ?? 0;
            
            // Build detailed message: [Status •] Verified/Rejected • Variants
            $messageParts = [];
            
            // Add status message only if there's an issue (not needed when healthy)
            if (!empty($camMessage)) {
                $messageParts[] = $camMessage;
            }
            
            // Add verification metrics for all cameras (24h rolling window)
            $messageParts[] = "Verified {$verified} / Rejected {$rejected} (24h)";
            
            // Add variant coverage
            if ($cacheExists && isset($variantCoverage)) {
                $coveragePercent = round($variantCoverage * 100);
                if ($variantCoverage >= 0.9) {
                    $messageParts[] = "{$coveragePercent}% variants available";
                } elseif ($variantCoverage >= 0.5) {
                    $messageParts[] = "{$coveragePercent}% variants (degraded)";
                    if ($camStatus === 'operational') {
                        $camStatus = 'degraded';
                    }
                } else {
                    $messageParts[] = "{$coveragePercent}% variants (low coverage)";
                    if ($camStatus === 'operational') {
                        $camStatus = 'degraded';
                    }
                }
            }
            
            $detailedMessage = implode(' • ', $messageParts);
            
            // Add per-camera component
            $webcamComponent = [
                'name' => "{$camName} ({$cameraType})",
                'status' => $camStatus,
                'message' => $detailedMessage,
                'lastChanged' => $camLastChanged,
                'variant_coverage' => isset($variantCoverage) ? round($variantCoverage * 100, 1) : null,
                'available_variants' => isset($availableVariants) ? $availableVariants : null,
                'total_variants' => isset($totalVariants) ? $totalVariants : null,
                'image_metrics' => $imageMetrics
            ];
            
            $webcamComponents[] = $webcamComponent;
            
            // Track issues for aggregate message
            if ($camStatus === 'down') {
                $webcamIssues[] = "{$camName}: {$camMessage}";
            } elseif ($camStatus === 'degraded') {
                if (empty($webcamIssues)) {
                    $webcamIssues[] = "{$camName}: {$camMessage}";
                }
            }
        }
        
        // Determine aggregate status: any down = down, any degraded = degraded, else operational
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
    
    // Calculate cache statistics for this airport
    $airportCacheStats = [
        'total_images' => 0,
        'total_size_bytes' => 0,
        'oldest_image_time' => 0,
        'newest_image_time' => 0,
        'images_verified' => 0,
        'images_rejected' => 0
    ];
    
    // Aggregate upload metrics from all cameras (24-hour accepted/rejected counts)
    foreach ($webcamComponents as $comp) {
        if (isset($comp['image_metrics'])) {
            $airportCacheStats['images_verified'] += $comp['image_metrics']['verified'] ?? 0;
            $airportCacheStats['images_rejected'] += $comp['image_metrics']['rejected'] ?? 0;
        }
    }
    
    if ($totalCams > 0) {
        $airportWebcamDir = CACHE_WEBCAMS_DIR . '/' . $airportId;
        if (is_dir($airportWebcamDir)) {
            $camDirs = glob($airportWebcamDir . '/*', GLOB_ONLYDIR);
            foreach ($camDirs as $camDir) {
                $files = glob($camDir . '/*.{jpg,webp}', GLOB_BRACE);
                if ($files) {
                    foreach ($files as $file) {
                        // Skip symlinks to avoid double-counting; skip missing (staging files can be deleted mid-scan)
                        if (!is_link($file) && file_exists($file)) {
                            $size = @filesize($file);
                            $mtime = @filemtime($file);
                            if ($size === false || $mtime === false) {
                                continue;
                            }
                            $airportCacheStats['total_images']++;
                            $airportCacheStats['total_size_bytes'] += $size;
                            
                            if ($airportCacheStats['oldest_image_time'] === 0 || $mtime < $airportCacheStats['oldest_image_time']) {
                                $airportCacheStats['oldest_image_time'] = $mtime;
                            }
                            if ($mtime > $airportCacheStats['newest_image_time']) {
                                $airportCacheStats['newest_image_time'] = $mtime;
                            }
                        }
                    }
                }
            }
        }
    }
    
    // Format cache summary message
    $cacheSummary = '';
    if ($airportCacheStats['total_images'] > 0) {
        $sizeMB = round($airportCacheStats['total_size_bytes'] / (1024 * 1024), 1);
        $imagesPerCam = round($airportCacheStats['total_images'] / $totalCams);
        $cacheSummary = "{$airportCacheStats['total_images']} cached images ({$sizeMB} MB) • ~{$imagesPerCam} per camera";
    } else {
        $cacheSummary = 'No cached images';
    }
    
    // Store webcam cameras - only if we have cameras
    if (!empty($webcamComponents)) {
        $health['components']['webcams'] = [
            'name' => 'Webcams',
            'status' => $webcamStatus,
            'message' => $webcamMessage,
            'lastChanged' => $webcamLastChanged,
            'cameras' => $webcamComponents, // Per-camera details
            'cache_summary' => $cacheSummary,
            'cache_stats' => $airportCacheStats
        ];
    }
    
    // Determine overall airport status: any component down = down, any degraded = degraded
    // Check all components including nested sources/cameras for complete status picture
    $hasDown = false;
    $hasDegraded = false;
    foreach ($health['components'] as $comp) {
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
    
    // Maintenance mode overrides all other statuses
    if (isAirportInMaintenance($airport)) {
        $health['status'] = 'maintenance';
    }
    
    $health['limited_availability'] = isAirportLimitedAvailability($airport);
    
    // limited_availability: show special banner only when all local sources down (one down = normal outage)
    $health['all_sources_down'] = false;
    if ($health['limited_availability'] && $hasDown) {
        $outageStatus = checkDataOutageStatus($airportId, $airport);
        $health['all_sources_down'] = ($outageStatus !== null);
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
    
    foreach ($endpoints as $endpoint => $name) {
        $result = performPublicApiHealthCheck($endpoint);
        
        $health['endpoints'][] = [
            'name' => $name,
            'endpoint' => $endpoint,
            'status' => $result['status'],
            'message' => $result['message'],
            'response_time_ms' => $result['response_time_ms']
        ];
        
        if ($result['status'] === 'down') {
            $hasDown = true;
        } elseif ($result['status'] === 'degraded') {
            $hasDegraded = true;
        }
    }
    
    // Determine overall API health
    if ($hasDown) {
        $health['status'] = 'down';
    } elseif ($hasDegraded) {
        $health['status'] = 'degraded';
    } else {
        $health['status'] = 'operational';
    }
    
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
    
    // Check HTTP response code (use http_get_last_response_headers() on PHP 8.4+)
    $httpCode = 0;
    $headers = (function_exists('http_get_last_response_headers'))
        ? (http_get_last_response_headers() ?? [])
        : ($http_response_header ?? []);
    if (is_array($headers)) {
        foreach ($headers as $header) {
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

/**
 * Get cached backoff data
 * 
 * Uses static variable to cache backoff data within request lifecycle.
 * APCu not needed as backoff file is small and read-once per request.
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
