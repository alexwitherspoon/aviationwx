<?php
/**
 * Status Page
 * Displays system health status for AviationWX.org
 */

require_once __DIR__ . '/config-utils.php';
require_once __DIR__ . '/logger.php';

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
    $configPath = getenv('CONFIG_PATH') ?: __DIR__ . '/airports.json';
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
    $cacheDir = __DIR__ . '/cache';
    $webcamCacheDir = __DIR__ . '/cache/webcams';
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
        $canWriteStdout = @fwrite(STDOUT, '') !== false || @is_resource(STDOUT);
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
    
    // Check error rate
    $errorRate = aviationwx_error_rate_last_hour();
    $errorRateStatus = $errorRate === 0 ? 'operational' : ($errorRate < 10 ? 'degraded' : 'down');
    
    // Get last error timestamp
    $lastErrorTime = 0;
    if (function_exists('apcu_fetch')) {
        $errorEvents = apcu_fetch('aviationwx_error_events');
        if (is_array($errorEvents) && !empty($errorEvents)) {
            $lastErrorTime = max($errorEvents);
        }
    }
    
    $health['components']['error_rate'] = [
        'name' => 'Error Rate',
        'status' => $errorRateStatus,
        'message' => $errorRate === 0 ? 'No errors in the last hour' : "{$errorRate} errors in the last hour",
        'lastChanged' => $lastErrorTime > 0 ? $lastErrorTime : ($errorRate === 0 ? time() : 0)
    ];
    
    return $health;
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
    
    // Check weather cache
    $weatherCacheFile = __DIR__ . '/cache/weather_' . $airportId . '.json';
    $weatherCacheExists = file_exists($weatherCacheFile);
    
    if ($weatherCacheExists) {
        $weatherAge = time() - filemtime($weatherCacheFile);
        $weatherRefresh = isset($airport['weather_refresh_seconds']) 
            ? intval($airport['weather_refresh_seconds']) 
            : (getenv('WEATHER_REFRESH_DEFAULT') ? intval(getenv('WEATHER_REFRESH_DEFAULT')) : 60);
        
        // Check if data is fresh, stale, or expired
        $maxStaleHours = 3;
        $maxStaleSeconds = $maxStaleHours * 3600;
        
        if ($weatherAge < $weatherRefresh) {
            $weatherStatus = 'operational';
            $weatherMessage = 'Weather data fresh';
        } elseif ($weatherAge < $maxStaleSeconds) {
            $weatherStatus = 'degraded';
            $weatherMessage = 'Weather data stale (but usable)';
        } else {
            $weatherStatus = 'down';
            $weatherMessage = 'Weather data expired';
        }
        
        // Try to read cache to check if it's valid JSON
        $weatherData = @json_decode(file_get_contents($weatherCacheFile), true);
        if (!is_array($weatherData)) {
            $weatherStatus = 'down';
            $weatherMessage = 'Weather cache corrupted';
        }
    } else {
        $weatherStatus = 'down';
        $weatherMessage = 'No weather cache available';
    }
    
    $weatherLastChanged = $weatherCacheExists ? filemtime($weatherCacheFile) : 0;
    $health['components']['weather'] = [
        'name' => 'Weather API',
        'status' => $weatherStatus,
        'message' => $weatherMessage,
        'lastChanged' => $weatherLastChanged
    ];
    
    // Check webcam caches
    $webcamCacheDir = __DIR__ . '/cache/webcams';
    $webcams = $airport['webcams'] ?? [];
    $webcamStatus = 'operational';
    $webcamIssues = [];
    
    if (empty($webcams)) {
        $webcamStatus = 'degraded';
        $webcamIssues[] = 'No webcams configured';
    } else {
        $healthyCams = 0;
        $totalCams = count($webcams);
        
        foreach ($webcams as $idx => $cam) {
            $cacheJpg = $webcamCacheDir . '/' . $airportId . '_' . $idx . '.jpg';
            $cacheWebp = $webcamCacheDir . '/' . $airportId . '_' . $idx . '.webp';
            $cacheExists = file_exists($cacheJpg) || file_exists($cacheWebp);
            
            if ($cacheExists) {
                $cacheFile = file_exists($cacheJpg) ? $cacheJpg : $cacheWebp;
                $cacheAge = time() - filemtime($cacheFile);
                $webcamRefresh = isset($cam['refresh_seconds']) 
                    ? intval($cam['refresh_seconds']) 
                    : (isset($airport['webcam_refresh_seconds']) 
                        ? intval($airport['webcam_refresh_seconds']) 
                        : (getenv('WEBCAM_REFRESH_DEFAULT') ? intval(getenv('WEBCAM_REFRESH_DEFAULT')) : 60));
                
                // Check for error files
                $errorFile = $cacheJpg . '.error.json';
                $hasError = file_exists($errorFile);
                
                // Check backoff state
                $backoffFile = __DIR__ . '/cache/backoff.json';
                $inBackoff = false;
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
                
                if ($hasError || $inBackoff) {
                    // Webcam has issues but not necessarily down
                    if ($healthyCams === 0) {
                        $webcamIssues[] = "Webcam {$idx}: Has errors or in backoff";
                    }
                } elseif ($cacheAge < $webcamRefresh) {
                    $healthyCams++;
                } elseif ($cacheAge < 3600) {
                    // Stale but recent (within hour)
                    $healthyCams++;
                }
            } else {
                if (empty($webcamIssues)) {
                    $webcamIssues[] = "Webcam {$idx}: No cache available";
                }
            }
        }
        
        if ($healthyCams === 0 && $totalCams > 0) {
            $webcamStatus = 'down';
        } elseif ($healthyCams < $totalCams) {
            $webcamStatus = 'degraded';
        }
    }
    
    $webcamMessage = empty($webcamIssues) 
        ? ($totalCams > 0 ? "All {$totalCams} webcam(s) operational" : 'No webcams configured')
        : implode(', ', array_slice($webcamIssues, 0, 2)); // Show max 2 issues
    
    // Find most recent webcam cache file modification time
    $webcamLastChanged = 0;
    foreach ($webcams as $idx => $cam) {
        $cacheJpg = $webcamCacheDir . '/' . $airportId . '_' . $idx . '.jpg';
        $cacheWebp = $webcamCacheDir . '/' . $airportId . '_' . $idx . '.webp';
        if (file_exists($cacheJpg)) {
            $mtime = filemtime($cacheJpg);
            if ($mtime > $webcamLastChanged) {
                $webcamLastChanged = $mtime;
            }
        }
        if (file_exists($cacheWebp)) {
            $mtime = filemtime($cacheWebp);
            if ($mtime > $webcamLastChanged) {
                $webcamLastChanged = $mtime;
            }
        }
    }
    
    $health['components']['webcams'] = [
        'name' => 'Webcams',
        'status' => $webcamStatus,
        'message' => $webcamMessage,
        'lastChanged' => $webcamLastChanged
    ];
    
    // Determine overall airport status
    $hasDown = false;
    $hasDegraded = false;
    foreach ($health['components'] as $comp) {
        if ($comp['status'] === 'down') {
            $hasDown = true;
            break;
        } elseif ($comp['status'] === 'degraded') {
            $hasDegraded = true;
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
            color: #666;
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
        
        .status-card-header h2 {
            font-size: 1.25rem;
            font-weight: 600;
            color: #1a1a1a;
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
            color: #666;
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
            color: #666;
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
        <div class="status-card">
            <div class="status-card-header">
                <h2><?php echo htmlspecialchars($airport['id']); ?></h2>
                <span class="status-badge">
                    <span class="status-indicator <?php echo getStatusColor($airport['status']); ?>">
                        <?php echo getStatusIcon($airport['status']); ?>
                    </span>
                    <?php echo ucfirst($airport['status']); ?>
                </span>
            </div>
            <div class="status-card-body">
                <ul class="component-list">
                    <?php foreach ($airport['components'] as $component): ?>
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
</body>
</html>

