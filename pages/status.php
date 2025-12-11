<?php
/**
 * Status Page
 * Displays system health status for AviationWX.org
 */

require_once __DIR__ . '/../lib/config.php';
require_once __DIR__ . '/../lib/logger.php';
require_once __DIR__ . '/../lib/seo.php';

// VPN routing is optional - only required if VPN features are used
// The checkVpnStatus function below doesn't actually use any functions from vpn-routing.php
// It just reads the cache file directly, so we don't need to require it

// Prevent caching
header('Content-Type: text/html; charset=utf-8');
header('Cache-Control: no-cache, no-store, must-revalidate');
header('Pragma: no-cache');
header('Expires: 0');

/**
 * Determine status color (Green/Yellow/Red)
 */
function getStatusColor($status) {
    switch ($status) {
        case 'operational': return 'green';
        case 'degraded': return 'yellow';
        case 'down': return 'red';
        default: return 'gray';
    }
}

/**
 * Get status icon
 */
function getStatusIcon($status) {
    switch ($status) {
        case 'operational': return '●';
        case 'degraded': return '●';
        case 'down': return '●';
        default: return '○';
    }
}

/**
 * Format relative time (e.g., "5 minutes ago", "2 hours ago")
 */
function formatRelativeTime($timestamp) {
    if (!$timestamp || $timestamp <= 0) return 'Unknown';
    $diff = time() - $timestamp;
    if ($diff < 60) return 'Just now';
    if ($diff < 3600) return floor($diff / 60) . ' minute' . (floor($diff / 60) == 1 ? '' : 's') . ' ago';
    if ($diff < 86400) return floor($diff / 3600) . ' hour' . (floor($diff / 3600) == 1 ? '' : 's') . ' ago';
    if ($diff < 604800) return floor($diff / 86400) . ' day' . (floor($diff / 86400) == 1 ? '' : 's') . ' ago';
    return floor($diff / 604800) . ' week' . (floor($diff / 604800) == 1 ? '' : 's') . ' ago';
}

/**
 * Format absolute timestamp with timezone
 */
function formatAbsoluteTime($timestamp) {
    if (!$timestamp || $timestamp <= 0) return 'Unknown';
    return date('Y-m-d H:i:s T', $timestamp);
}

/**
 * Check system health
 */
function checkSystemHealth() {
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
    $cacheDir = __DIR__ . '/../cache';
    $webcamCacheDir = __DIR__ . '/../cache/webcams';
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
        // Check weather cache files
        $weatherFiles = glob($cacheDir . '/weather_*.json');
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
    
    // Check logging system
    $logToStdout = defined('AVIATIONWX_LOG_TO_STDOUT') && AVIATIONWX_LOG_TO_STDOUT;
    
    if ($logToStdout) {
        // Docker logging via stdout/stderr - check if we can write to stdout
        // In CLI, STDOUT is defined; in web context, use php://stdout
        if (php_sapi_name() === 'cli' && defined('STDOUT')) {
            $canWriteStdout = @fwrite(STDOUT, '') !== false || @is_resource(STDOUT);
        } else {
            // Web context: try to open php://stdout
            $testStream = @fopen('php://stdout', 'a');
            $canWriteStdout = $testStream !== false;
            if ($testStream !== false) {
                @fclose($testStream);
            }
        }
        $loggingStatus = $canWriteStdout ? 'operational' : 'degraded';
        $loggingMessage = $canWriteStdout 
            ? 'Logging to Docker (stdout/stderr) - view with docker compose logs' 
            : 'Cannot write to stdout/stderr';
        $logMtime = time(); // Use current time since we can't check file mtime
    } else {
        // File-based logging - check log file existence and activity
        $logFile = AVIATIONWX_APP_LOG_FILE;
        $logDir = dirname($logFile);
        $logMtime = file_exists($logFile) ? filemtime($logFile) : 0;
        $hasRecentLogs = false;
        if ($logMtime > 0) {
            $hasRecentLogs = (time() - $logMtime) < 3600; // Within last hour
        }
        
        // Check if log directory is writable (for local development)
        $logDirWritable = is_dir($logDir) && is_writable($logDir);
        
        // If log directory is writable but no recent logs, show as operational (local dev is fine)
        // If log file exists and has recent activity, show as operational
        // If log directory not writable or log file doesn't exist and can't be created, show as degraded
        $loggingStatus = 'operational';
        $loggingMessage = 'Recent log activity detected';
        if ($hasRecentLogs) {
            $loggingStatus = 'operational';
            $loggingMessage = 'Recent log activity detected';
        } elseif ($logDirWritable) {
            $loggingStatus = 'operational';
            $loggingMessage = 'Logging ready (no recent activity)';
        } elseif (file_exists($logFile)) {
            $loggingStatus = 'degraded';
            $loggingMessage = 'No recent log activity';
        } else {
            $loggingStatus = 'degraded';
            $loggingMessage = 'Log file not accessible';
        }
    }
    
    $health['components']['logging'] = [
        'name' => 'Logging',
        'status' => $loggingStatus,
        'message' => $loggingMessage,
        'lastChanged' => $logMtime > 0 ? $logMtime : (isset($logDir) && is_dir($logDir) ? filemtime($logDir) : 0)
    ];
    
    // Check internal error rate (system errors only, not external data source failures)
    $errorRate = aviationwx_error_rate_last_hour();
    $errorRateStatus = $errorRate === 0 ? 'operational' : ($errorRate < 10 ? 'degraded' : 'down');
    
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
    
    return $health;
}

/**
 * Check FTP/SFTP service health
 */
function checkFtpSftpServices() {
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
    $vsftpdRunning = false;
    if (function_exists('exec')) {
        @exec('pgrep -x vsftpd 2>/dev/null', $output, $code);
        $vsftpdRunning = ($code === 0 && !empty($output));
    }
    $services['vsftpd']['running'] = $vsftpdRunning;
    
    // Check sshd process
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
 * Check VPN status for airport
 */
function checkVpnStatus($airportId, $airport) {
    $vpn = $airport['vpn'] ?? null;
    
    if (!$vpn || !($vpn['enabled'] ?? false)) {
        return null; // No VPN, don't show status
    }
    
    $statusFile = __DIR__ . '/../cache/vpn-status.json';
    if (!file_exists($statusFile)) {
        return [
            'name' => 'VPN Connection',
            'status' => 'down',
            'message' => 'VPN status unavailable',
            'lastChanged' => 0
        ];
    }
    
    $statusData = @json_decode(file_get_contents($statusFile), true);
    $connectionName = $vpn['connection_name'] ?? "{$airportId}_vpn";
    $connStatus = $statusData['connections'][$connectionName] ?? null;
    
    if (!$connStatus) {
        return [
            'name' => 'VPN Connection',
            'status' => 'down',
            'message' => 'VPN connection not found',
            'lastChanged' => 0
        ];
    }
    
    $status = $connStatus['status'] === 'up' ? 'operational' : 'down';
    $lastConnected = $connStatus['last_connected'] ?? 0;
    $message = $status === 'operational' 
        ? 'VPN connected' 
        : 'VPN disconnected';
    
    if ($lastConnected > 0) {
        $message .= ' (last connected: ' . formatRelativeTime($lastConnected) . ')';
    }
    
    return [
        'name' => 'VPN Connection',
        'status' => $status,
        'message' => $message,
        'lastChanged' => $lastConnected
    ];
}

/**
 * Check airport health
 */
function checkAirportHealth($airportId, $airport) {
    $health = [
        'id' => strtoupper($airportId),
        'status' => 'operational',
        'components' => []
    ];
    
    // Check weather sources - per-source status
    // Cache is at root level, not in pages directory
    $weatherCacheFile = __DIR__ . '/../cache/weather_' . $airportId . '.json';
    $weatherCacheExists = file_exists($weatherCacheFile);
    $weatherData = null;
    
    if ($weatherCacheExists) {
        $weatherData = @json_decode(file_get_contents($weatherCacheFile), true);
        if (!is_array($weatherData)) {
            $weatherData = null; // Treat as missing if corrupted
        }
    }
    
    $weatherRefresh = isset($airport['weather_refresh_seconds']) 
        ? intval($airport['weather_refresh_seconds']) 
        : getDefaultWeatherRefresh();
    $maxStaleHours = getMaxStaleHours();
    $maxStaleSeconds = $maxStaleHours * 3600;
    $maxStaleSecondsMetar = MAX_STALE_HOURS_METAR * 3600;
    
    $weatherSources = [];
    
    // Helper function to get source name from type
    $getSourceName = function($sourceType) {
        switch ($sourceType) {
            case 'tempest': return 'Tempest Weather';
            case 'ambient': return 'Ambient Weather';
            case 'weatherlink': return 'Davis WeatherLink';
            case 'metar': return 'Aviation Weather';
            default: return 'Unknown Source';
        }
    };
    
    // Check primary weather source
    $sourceType = $airport['weather_source']['type'] ?? null;
    if ($sourceType) {
        $sourceName = $getSourceName($sourceType);
        $primaryStatus = 'down';
        $primaryMessage = 'No data available';
        $primaryLastChanged = 0;
        
        if ($weatherData !== null) {
            // Check primary source timestamps
            $primaryTimestamp = $weatherData['obs_time_primary'] ?? $weatherData['last_updated_primary'] ?? null;
            
            if ($primaryTimestamp && $primaryTimestamp > 0) {
                $primaryAge = time() - $primaryTimestamp;
                $primaryLastChanged = $primaryTimestamp;
                
                if ($primaryAge < $weatherRefresh) {
                    $primaryStatus = 'operational';
                    $primaryMessage = 'Fresh';
                } elseif ($primaryAge < $maxStaleSeconds) {
                    $primaryStatus = 'degraded';
                    $primaryMessage = 'Stale (but usable)';
                } else {
                    $primaryStatus = 'down';
                    $primaryMessage = 'Expired';
                }
            } else {
                $primaryStatus = 'down';
                $primaryMessage = 'No timestamp available';
            }
        }
        
        $weatherSources[] = [
            'name' => $sourceName,
            'status' => $primaryStatus,
            'message' => $primaryMessage,
            'lastChanged' => $primaryLastChanged
        ];
    }
    
    // Check METAR source (if configured separately or as supplement)
    require_once __DIR__ . '/../lib/weather/utils.php';
    $isMetarEnabled = isMetarEnabled($airport);
    $isMetarPrimary = ($sourceType === 'metar');
    
    // METAR is shown separately if:
    // 1. It's the primary source (already added above), OR
    // 2. It's configured as supplement (metar_station set and primary is not metar)
    if ($isMetarPrimary) {
        // Already added above, skip
    } elseif ($isMetarEnabled) {
        $metarStatus = 'down';
        $metarMessage = 'No data available';
        $metarLastChanged = 0;
        
        if ($weatherData !== null) {
            // Check METAR source timestamps
            $metarTimestamp = $weatherData['obs_time_metar'] ?? $weatherData['last_updated_metar'] ?? null;
            
            if ($metarTimestamp && $metarTimestamp > 0) {
                $metarAge = time() - $metarTimestamp;
                $metarLastChanged = $metarTimestamp;
                
                if ($metarAge < $weatherRefresh) {
                    $metarStatus = 'operational';
                    $metarMessage = 'Fresh';
                } elseif ($metarAge < $maxStaleSecondsMetar) {
                    $metarStatus = 'degraded';
                    $metarMessage = 'Stale (but usable)';
                } else {
                    $metarStatus = 'down';
                    $metarMessage = 'Expired';
                }
            } else {
                $metarStatus = 'down';
                $metarMessage = 'No timestamp available';
            }
        }
        
        $weatherSources[] = [
            'name' => 'Aviation Weather',
            'status' => $metarStatus,
            'message' => $metarMessage,
            'lastChanged' => $metarLastChanged
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
    // Cache is at root level, not in pages directory
    // Use same path resolution as webcam API
    $webcamCacheDir = __DIR__ . '/../cache/webcams';
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
            
            // Use resolved cache directory path for file checks
            $cacheJpg = $webcamCacheDirResolved . '/' . $airportId . '_' . $idx . '.jpg';
            $cacheWebp = $webcamCacheDirResolved . '/' . $airportId . '_' . $idx . '.webp';
            
            // Check if cache files exist and are readable
            // Use same path resolution as webcam API (normalize path to handle symlinks)
            $cacheJpgResolved = @realpath($cacheJpg) ?: $cacheJpg;
            $cacheWebpResolved = @realpath($cacheWebp) ?: $cacheWebp;
            $cacheExists = (@file_exists($cacheJpgResolved) && @is_readable($cacheJpgResolved)) 
                        || (@file_exists($cacheWebpResolved) && @is_readable($cacheWebpResolved));
            
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
                $cacheFile = (@file_exists($cacheJpgResolved) && @is_readable($cacheJpgResolved)) 
                           ? $cacheJpgResolved 
                           : $cacheWebpResolved;
                $cacheAge = time() - @filemtime($cacheFile);
                $camLastChanged = @filemtime($cacheFile) ?: 0;
                
                // Unified staleness logic: 5x for warning, 10x for error
                $warningThreshold = $webcamRefresh * 5;
                $errorThreshold = $webcamRefresh * 10;
                
                // Check for error files (pull cameras only)
                $errorFile = $cacheJpg . '.error.json';
                $hasError = !$isPush && file_exists($errorFile);
                
                // Check backoff state (pull cameras only)
                $inBackoff = false;
                if (!$isPush) {
                    $backoffFile = __DIR__ . '/../cache/backoff.json';
                    if (file_exists($backoffFile)) {
                        $backoffData = @json_decode(file_get_contents($backoffFile), true);
                        if (is_array($backoffData)) {
                            $key = $airportId . '_' . $idx;
                            if (isset($backoffData[$key])) {
                                $backoffUntil = $backoffData[$key]['next_allowed_time'] ?? 0;
                                $inBackoff = $backoffUntil > time();
                            }
                        }
                    }
                }
                
                if ($hasError || $inBackoff) {
                    $camStatus = 'degraded';
                    $camMessage = $hasError ? 'Has errors' : 'In backoff';
                } elseif ($cacheAge < $webcamRefresh) {
                    $camStatus = 'operational';
                    $camMessage = 'Fresh';
                    $healthyCams++;
                } elseif ($cacheAge < $warningThreshold) {
                    $camStatus = 'operational';
                    $camMessage = 'Stale but usable';
                    $healthyCams++;
                } elseif ($cacheAge < $errorThreshold) {
                    $camStatus = 'degraded';
                    $camMessage = 'Stale (warning)';
                } else {
                    $camStatus = 'down';
                    $camMessage = 'Stale (error)';
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
            
            // Add per-camera component
            $webcamComponents[] = [
                'name' => "{$camName} ({$cameraType})",
                'status' => $camStatus,
                'message' => $camMessage,
                'lastChanged' => $camLastChanged
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
    
    // Check VPN status if VPN is enabled
    $vpnStatus = checkVpnStatus($airportId, $airport);
    if ($vpnStatus !== null) {
        $health['components']['vpn'] = $vpnStatus;
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
        // Check other components (VPN, etc.) that have direct status
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
    
    return $health;
}

// Load configuration
$config = loadConfig();
if ($config === null) {
    http_response_code(503);
    die('Service Unavailable: Configuration cannot be loaded');
}

// Get system health
$systemHealth = checkSystemHealth();

// Get airport health for each configured airport
$airportHealth = [];
if (isset($config['airports']) && is_array($config['airports'])) {
    foreach ($config['airports'] as $airportId => $airport) {
        $airportHealth[] = checkAirportHealth($airportId, $airport);
    }
}

// Sort airports by status (down first, then degraded, then operational)
usort($airportHealth, function($a, $b) {
    $statusOrder = ['down' => 0, 'degraded' => 1, 'operational' => 2];
    return $statusOrder[$a['status']] <=> $statusOrder[$b['status']];
});

?>
<!DOCTYPE html>
<html lang="en">
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
            padding: 2rem 1rem;
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
            transition: max-height 0.3s ease-out;
        }
        
        .airport-card-body.collapsed {
            max-height: 0;
        }
        
        .airport-card-body.expanded {
            max-height: 5000px;
            transition: max-height 0.5s ease-in;
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
        
        @media (max-width: 768px) {
            .component-item {
                flex-direction: column;
                gap: 0.5rem;
            }
            
            .component-status {
                margin-left: 0;
            }
        }
    </style>
</head>
<body>
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
                <ul class="component-list">
                    <?php foreach ($systemHealth['components'] as $component): ?>
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
                    <?php endforeach; ?>
                </ul>
            </div>
        </div>
        
        <!-- Airport Status Cards -->
        <?php if (!empty($airportHealth)): ?>
        <h2 style="font-size: 1.5rem; font-weight: 600; margin-bottom: 1rem; color: #1a1a1a;">Airport Status</h2>
        <?php foreach ($airportHealth as $airport): ?>
        <?php 
        // Determine if airport should be expanded by default (not operational = expanded)
        $isExpanded = ($airport['status'] !== 'operational');
        ?>
        <div class="status-card">
            <div class="status-card-header airport-card-header <?php echo $isExpanded ? 'expanded' : ''; ?>" 
                 onclick="toggleAirport('<?php echo htmlspecialchars($airport['id']); ?>')">
                <h2>
                    <span class="expand-icon">▶</span>
                    <?php echo htmlspecialchars($airport['id']); ?>
                </h2>
                <span class="status-badge">
                    <span class="status-indicator <?php echo getStatusColor($airport['status']); ?>">
                        <?php echo getStatusIcon($airport['status']); ?>
                    </span>
                    <?php echo ucfirst($airport['status']); ?>
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
                        <!-- Other components (VPN, etc.) - show with status indicator -->
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
                </ul>
            </div>
        </div>
        <?php endforeach; ?>
        <?php endif; ?>
        
        <div class="footer">
            <p>
                &copy; <?= date('Y') ?> <a href="https://aviationwx.org">AviationWX.org</a> | 
                <a href="https://aviationwx.org#about-the-project">Built for pilots, by pilots</a> | 
                <a href="https://github.com/alexwitherspoon/aviationwx.org" target="_blank" rel="noopener">Open Source<?php $gitSha = getGitSha(); echo $gitSha ? ' - ' . htmlspecialchars($gitSha) : ''; ?></a> | 
                <a href="https://status.aviationwx.org">Status</a>
            </p>
            <p style="margin-top: 0.5rem; font-size: 0.75rem; color: #999;">
                Last updated: <?php echo date('Y-m-d H:i:s T'); ?>
            </p>
        </div>
    </div>
    
    <script>
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
    </script>
</body>
</html>


