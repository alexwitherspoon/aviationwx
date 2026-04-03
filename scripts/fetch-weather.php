<?php
/**
 * Safe Weather Data Fetcher
 * Refreshes weather cache for all airports via cron
 * Supports process pool for parallel execution
 * 
 * Usage:
 *   Normal mode: php fetch-weather.php
 *   Worker mode:  php fetch-weather.php --worker <airport_id>
 */

require_once __DIR__ . '/../lib/config.php';
require_once __DIR__ . '/../lib/internal-http-url.php';
require_once __DIR__ . '/../lib/logger.php';
require_once __DIR__ . '/../lib/process-pool.php';

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
 * Get weather refresh base URL
 *
 * Delegates to getInternalApacheBaseUrl() so scheduler traffic uses the same
 * internal Apache base as metrics flush (WEATHER_REFRESH_URL in production).
 *
 * @return string Base URL (without trailing slash)
 */
function getWeatherBaseUrl(): string {
    return getInternalApacheBaseUrl();
}

/**
 * Process single airport weather refresh
 *
 * Makes HTTP request to weather API endpoint to trigger cache refresh.
 * Logs success/failure and returns status for worker process tracking.
 * When expectFailures is true (maintenance or unlisted/commissioning), failures
 * are logged at info level so process pool does not treat as errors.
 *
 * @param string $airportId Airport ID (e.g., 'kspb')
 * @param string $baseUrl Base URL for weather API (e.g., 'http://localhost')
 * @param string $invocationId Invocation ID for log correlation
 * @param string $triggerType Trigger type ('cron_job', 'web_request', 'manual_cli')
 * @param bool $expectFailures True if failures are expected (maintenance or commissioning/unlisted)
 * @return bool True on success, false on failure
 */
function processAirportWeather(string $airportId, string $baseUrl, string $invocationId, string $triggerType, bool $expectFailures = false): bool
{
    $weatherUrl = $baseUrl . '/weather.php?airport=' . urlencode($airportId);
    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL => $weatherUrl,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 45, // Match weather.php timeout
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_USERAGENT => 'AviationWX Weather Cron Bot',
        CURLOPT_HTTPHEADER => [
            'Accept: application/json',
        ],
    ]);
    
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error = curl_error($ch);
    
    $success = false;
    
    if ($httpCode === 200 && $response !== false) {
        $data = json_decode($response, true);
        if ($data !== null && isset($data['success']) && $data['success'] === true) {
            $stale = isset($data['stale']) && $data['stale'] === true;
            $lastUpdated = isset($data['weather']['last_updated']) ? $data['weather']['last_updated'] : null;
            
            if ($stale) {
                aviationwx_log('info', 'weather refresh triggered (stale cache)', [
                    'invocation_id' => $invocationId,
                    'trigger' => $triggerType,
                    'airport' => $airportId,
                    'last_updated' => $lastUpdated
                ], 'app');
            } else {
                aviationwx_log('info', 'weather refresh triggered (fresh cache)', [
                    'invocation_id' => $invocationId,
                    'trigger' => $triggerType,
                    'airport' => $airportId,
                    'last_updated' => $lastUpdated
                ], 'app');
            }
            $success = true;
        } else {
            $logLevel = $expectFailures ? 'info' : 'warning';
            aviationwx_log($logLevel, 'weather refresh returned invalid response', [
                'invocation_id' => $invocationId,
                'trigger' => $triggerType,
                'airport' => $airportId,
                'http_code' => $httpCode
            ], 'app');
            $success = false;
        }
    } else {
        // Parse error response to determine log level
        $data = json_decode($response, true);
        $errorMessage = $data['error'] ?? null;
        
        // If not configured, log as info (expected state), not error
        if ($httpCode === 503 && $errorMessage === 'Weather source not configured') {
            aviationwx_log('info', 'weather refresh skipped (not configured)', [
                'invocation_id' => $invocationId,
                'trigger' => $triggerType,
                'airport' => $airportId
            ], 'app');
        } else {
            $logLevel = $expectFailures ? 'info' : 'error';
            aviationwx_log($logLevel, 'weather refresh failed', [
                'invocation_id' => $invocationId,
                'trigger' => $triggerType,
                'airport' => $airportId,
                'http_code' => $httpCode,
                'error' => $error ?: $errorMessage
            ], 'app');
        }
        $success = false;
    }
    
    return $success;
}

if ($isWorkerMode) {
    // Initialize self-timeout to prevent zombie workers
    // Worker will terminate itself before ProcessPool's hard kill
    require_once __DIR__ . '/../lib/worker-timeout.php';
    initWorkerTimeout(null, "weather_{$workerAirportId}");
    
    $config = loadConfig(false);
    if ($config === null || !isset($config['airports'][$workerAirportId])) {
        aviationwx_log('error', 'worker mode: airport not found', [
            'airport' => $workerAirportId
        ], 'app');
        exit(1);
    }
    
    if (!validateAirportId($workerAirportId)) {
        aviationwx_log('error', 'worker mode: invalid airport ID', [
            'airport' => $workerAirportId
        ], 'app');
        exit(1);
    }
    
    $airport = $config['airports'][$workerAirportId];
    if (!is_array($airport)) {
        aviationwx_log('error', 'worker mode: malformed airport config', [
            'airport' => $workerAirportId
        ], 'app');
        exit(1);
    }
    // Downgrade errors for maintenance (repairs) or unlisted (commissioning - new airport setup)
    $expectFailures = isAirportInMaintenance($airport) || isAirportUnlisted($airport);

    $baseUrl = getWeatherBaseUrl();
    $invocationId = aviationwx_get_invocation_id();
    $triggerInfo = aviationwx_detect_trigger_type();
    $triggerType = $triggerInfo['trigger'];

    $success = processAirportWeather($workerAirportId, $baseUrl, $invocationId, $triggerType, $expectFailures);

    // Exit 2 = skip (process pool treats as non-failure); expected when commissioning or in maintenance
    if (!$success && $expectFailures) {
        exit(2);
    }
    exit($success ? 0 : 1);
}

$config = loadConfig(false);

if ($config === null || !is_array($config)) {
    die("Error: Could not load configuration\n");
}

if (!isset($config['airports']) || !is_array($config['airports'])) {
    die("Error: No airports configured\n");
}

$isWeb = !empty($_SERVER['REQUEST_METHOD']);
$scriptStartTime = microtime(true);
$invocationId = aviationwx_get_invocation_id();
$triggerInfo = aviationwx_detect_trigger_type();
$triggerType = $triggerInfo['trigger'];
$triggerContext = $triggerInfo['context'];
$poolSize = getWeatherWorkerPoolSize();
$workerTimeout = getWorkerTimeout();

aviationwx_log('info', 'weather fetch script started', [
    'invocation_id' => $invocationId,
    'trigger' => $triggerType,
    'trigger_context' => $triggerContext,
    'airports_count' => count($config['airports'] ?? []),
    'pool_size' => $poolSize,
    'worker_timeout' => $workerTimeout
], 'app');

if ($isWeb) {
    header('Content-Type: text/html; charset=utf-8');
    echo "<!DOCTYPE html><html><head><title>AviationWX Weather Fetcher</title>";
    echo "<style>
        body { font-family: monospace; padding: 20px; background: #f5f5f5; }
        .header { background: #333; color: #fff; padding: 10px; margin: -20px -20px 20px -20px; }
        .airport { background: #fff; padding: 15px; margin-bottom: 15px; border-left: 4px solid #007bff; }
        .success { color: #28a745; font-weight: bold; }
        .error { color: #dc3545; font-weight: bold; }
        .info { color: #666; }
    </style></head><body>";
    echo "<div class='header'><h1>AviationWX Weather Fetcher</h1></div>";
    echo "<p>Processing " . count($config['airports']) . " airports with {$poolSize} workers...</p>";
} else {
    // Write progress to stderr for CLI/cron visibility
    @fwrite(STDERR, "AviationWX Weather Fetcher\n");
    @fwrite(STDERR, "========================\n\n");
    @fwrite(STDERR, "Processing " . count($config['airports']) . " airports with {$poolSize} workers...\n\n");
}

$pool = new ProcessPool($poolSize, $workerTimeout, basename(__FILE__), $invocationId);
register_shutdown_function(function() use ($pool) {
    $pool->cleanup();
});

$skipped = 0;
foreach ($config['airports'] as $airportId => $airport) {
    // Only process enabled airports; skip malformed entries
    if (!is_array($airport) || !isAirportEnabled($airport)) {
        continue;
    }
    
    if (!$pool->addJob([$airportId])) {
        $skipped++;
    }
}

$stats = $pool->waitForAll();

if ($isWeb) {
    echo "<div style='margin-top: 20px; padding: 15px; background: #fff; border-left: 4px solid #28a745;'>";
    echo "<strong>✓ Done!</strong> Weather cache refreshed.<br>";
    echo "Completed: {$stats['completed']}, Failed: {$stats['failed']}, Timed out: {$stats['timed_out']}";
    if ($skipped > 0) {
        echo ", Skipped (already running): {$skipped}";
    }
    echo "</body></html>";
} else {
    // Write progress to stderr for CLI/cron visibility
    @fwrite(STDERR, "\nDone! Weather cache refreshed.\n");
    $statsLine = "Completed: {$stats['completed']}, Failed: {$stats['failed']}, Timed out: {$stats['timed_out']}";
    if ($skipped > 0) {
        $statsLine .= ", Skipped (already running): {$skipped}";
    }
    @fwrite(STDERR, $statsLine . "\n");
}

$scriptDuration = round((microtime(true) - $scriptStartTime) * 1000, 2);
$totalAirports = count($config['airports'] ?? []);

aviationwx_log('info', 'weather fetch script completed', [
    'invocation_id' => $invocationId,
    'trigger' => $triggerType,
    'trigger_context' => $triggerContext,
    'duration_ms' => $scriptDuration,
    'airports_processed' => $totalAirports,
    'stats' => $stats
], 'app');

aviationwx_maybe_log_alert();
