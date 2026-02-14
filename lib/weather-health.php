<?php
/**
 * Weather Health Tracking
 * 
 * Tracks weather fetch success/failure rates by writing directly to cache file.
 * Uses file locking for thread safety across PHP-FPM workers.
 * 
 * Health status is recomputed only during scheduler flush (every 60s), not on
 * every tracking call, to avoid impacting weather fetch performance.
 * 
 * Usage:
 *   weather_health_track_fetch('kspb', 'tempest', true, 200);   // success
 *   weather_health_track_fetch('kspb', 'metar', false, 503);    // failure
 *   weather_health_flush();  // Called by scheduler every 60s
 */

require_once __DIR__ . '/cache-paths.php';
require_once __DIR__ . '/logger.php';

// Cache file for weather health status
if (!defined('WEATHER_HEALTH_CACHE_FILE')) {
    define('WEATHER_HEALTH_CACHE_FILE', CACHE_BASE_DIR . '/weather_health.json');
}

// =============================================================================
// TRACKING FUNCTIONS (called by UnifiedFetcher.php)
// =============================================================================

/**
 * Track a weather fetch event
 * 
 * Writes directly to cache file using file locking for thread safety.
 * 
 * @param string $airportId Airport identifier
 * @param string $sourceType Source type (tempest, ambient, metar, etc.)
 * @param bool $success Whether fetch succeeded
 * @param int|null $httpCode HTTP status code (optional)
 * @return void
 */
function weather_health_track_fetch(string $airportId, string $sourceType, bool $success, ?int $httpCode = null): void {
    $now = time();
    $currentHour = gmdate('Y-m-d-H', $now);
    
    // Build the counters to increment
    $counters = [
        "attempts_{$sourceType}" => 1,
        "airport_attempts_{$airportId}" => 1,
        'total_attempts' => 1
    ];
    
    if ($success) {
        $counters["successes_{$sourceType}"] = 1;
        $counters["airport_successes_{$airportId}"] = 1;
        $counters['total_successes'] = 1;
    } else {
        $counters["failures_{$sourceType}"] = 1;
        $counters["airport_failures_{$airportId}"] = 1;
        $counters['total_failures'] = 1;
        
        if ($httpCode !== null) {
            if ($httpCode >= 400 && $httpCode < 500) {
                $counters["http_4xx_{$sourceType}"] = 1;
            } elseif ($httpCode >= 500) {
                $counters["http_5xx_{$sourceType}"] = 1;
            }
        }
    }
    
    // Atomic update to file
    weather_health_atomic_update($currentHour, $counters, $now);
}

/**
 * Track a circuit breaker open event
 * 
 * @param string $airportId Airport identifier
 * @param string $sourceType Source type
 * @return void
 */
function weather_health_track_circuit_open(string $airportId, string $sourceType): void {
    $now = time();
    $currentHour = gmdate('Y-m-d-H', $now);
    
    $counters = [
        'circuit_open_events' => 1,
        "circuit_open_{$sourceType}" => 1
    ];
    
    weather_health_atomic_update($currentHour, $counters, $now);
}

/**
 * Track a fallback activation event
 * 
 * @param string $airportId Airport identifier
 * @return void
 */
function weather_health_track_fallback(string $airportId): void {
    $now = time();
    $currentHour = gmdate('Y-m-d-H', $now);
    
    $counters = [
        'fallback_activations' => 1,
        "fallback_{$airportId}" => 1
    ];
    
    weather_health_atomic_update($currentHour, $counters, $now);
}

// =============================================================================
// FILE I/O FUNCTIONS
// =============================================================================

/**
 * Atomically update the weather health cache file
 * 
 * Uses file locking to safely update from multiple PHP-FPM workers.
 * Only updates counters - health status is recomputed during scheduler flush
 * to avoid performance impact on weather fetches.
 * 
 * @param string $currentHour Current hour bucket key (Y-m-d-H)
 * @param array $counters Counters to increment
 * @param int $now Current timestamp
 * @return bool True on success
 */
function weather_health_atomic_update(string $currentHour, array $counters, int $now): bool {
    // Ensure cache directory exists
    $cacheDir = dirname(WEATHER_HEALTH_CACHE_FILE);
    if (!is_dir($cacheDir)) {
        if (!@mkdir($cacheDir, 0755, true)) {
            aviationwx_log('warning', 'weather_health: failed to create cache directory', [
                'dir' => $cacheDir
            ], 'app');
            return false;
        }
    }
    
    // Open file for read/write (create if doesn't exist)
    $fp = @fopen(WEATHER_HEALTH_CACHE_FILE, 'c+');
    if ($fp === false) {
        aviationwx_log('warning', 'weather_health: failed to open cache file', [
            'file' => WEATHER_HEALTH_CACHE_FILE
        ], 'app');
        return false;
    }
    
    // Acquire exclusive lock (non-blocking to avoid stalling weather fetches)
    if (!@flock($fp, LOCK_EX | LOCK_NB)) {
        @fclose($fp);
        // Skip silently - another process is updating, our counters will be in next flush
        return false;
    }
    
    // Read existing data
    $content = @stream_get_contents($fp);
    $data = [];
    if ($content !== false && $content !== '') {
        $data = @json_decode($content, true) ?: [];
    }
    
    // Initialize structure if needed
    if (!isset($data['hourly_buckets'])) {
        $data['hourly_buckets'] = [];
    }
    
    // Get or create current hour bucket
    if (!isset($data['hourly_buckets'][$currentHour])) {
        $data['hourly_buckets'][$currentHour] = [];
    }
    
    // Increment counters
    foreach ($counters as $key => $value) {
        if (!isset($data['hourly_buckets'][$currentHour][$key])) {
            $data['hourly_buckets'][$currentHour][$key] = 0;
        }
        $data['hourly_buckets'][$currentHour][$key] += $value;
    }
    
    // Update timestamps (but don't recompute health - scheduler does that)
    $data['last_fetch'] = $now;
    $data['last_update'] = $now;
    $data['current_hour'] = $currentHour;
    
    // Prune old buckets (keep last 2 hours)
    $twoHoursAgo = gmdate('Y-m-d-H', $now - 7200);
    foreach (array_keys($data['hourly_buckets']) as $hourKey) {
        if ($hourKey < $twoHoursAgo) {
            unset($data['hourly_buckets'][$hourKey]);
        }
    }
    
    // Write updated data
    @ftruncate($fp, 0);
    @fseek($fp, 0);
    $written = @fwrite($fp, json_encode($data, JSON_PRETTY_PRINT));
    
    // Release lock and close
    @flock($fp, LOCK_UN);
    @fclose($fp);
    
    if ($written === false) {
        aviationwx_log('warning', 'weather_health: failed to write cache file', [
            'file' => WEATHER_HEALTH_CACHE_FILE
        ], 'app');
        return false;
    }
    
    return true;
}

/**
 * Flush weather health data (prune old buckets)
 * 
 * Called by scheduler periodically to clean up old data.
 * Can also be used to force a recompute of health status.
 * 
 * @return bool True on success
 */
function weather_health_flush(): bool {
    $now = time();
    
    if (!file_exists(WEATHER_HEALTH_CACHE_FILE)) {
        // Create initial structure
        $data = [
            'hourly_buckets' => [],
            'current_hour' => gmdate('Y-m-d-H', $now),
            'last_flush' => $now,
            'health' => weather_health_compute_status([]),
            'sources' => []
        ];
        
        return @file_put_contents(WEATHER_HEALTH_CACHE_FILE, json_encode($data, JSON_PRETTY_PRINT), LOCK_EX) !== false;
    }
    
    // Open and lock file
    $fp = @fopen(WEATHER_HEALTH_CACHE_FILE, 'c+');
    if ($fp === false) {
        return false;
    }
    
    if (!@flock($fp, LOCK_EX)) {
        @fclose($fp);
        return false;
    }
    
    // Read existing data
    $content = @stream_get_contents($fp);
    $data = [];
    if ($content !== false && $content !== '') {
        $data = @json_decode($content, true) ?: [];
    }
    
    // Prune old buckets
    $twoHoursAgo = gmdate('Y-m-d-H', $now - 7200);
    if (isset($data['hourly_buckets'])) {
        foreach (array_keys($data['hourly_buckets']) as $hourKey) {
            if ($hourKey < $twoHoursAgo) {
                unset($data['hourly_buckets'][$hourKey]);
            }
        }
    }
    
    // Update metadata
    $data['last_flush'] = $now;
    $data['current_hour'] = gmdate('Y-m-d-H', $now);
    
    // Recompute health
    $data['health'] = weather_health_compute_status($data);
    $data['sources'] = weather_health_compute_source_status($data);
    
    // Write updated data
    @ftruncate($fp, 0);
    @fseek($fp, 0);
    @fwrite($fp, json_encode($data, JSON_PRETTY_PRINT));
    
    @flock($fp, LOCK_UN);
    @fclose($fp);
    
    return true;
}

// =============================================================================
// HEALTH STATUS FUNCTIONS
// =============================================================================

/**
 * Compute overall health status from aggregated data
 * 
 * @param array $data Aggregated weather health data
 * @return array Health status array
 */
function weather_health_compute_status(array $data): array {
    $health = [
        'name' => 'Weather Data Fetching',
        'status' => 'operational',
        'message' => 'All weather sources responding',
        'lastChanged' => $data['last_fetch'] ?? 0,
        'metrics' => []
    ];
    
    // Sum up last hour of data
    $oneHourAgo = gmdate('Y-m-d-H', time() - 3600);
    $totals = [
        'total_attempts' => 0,
        'total_successes' => 0,
        'total_failures' => 0,
        'circuit_open_events' => 0,
        'fallback_activations' => 0
    ];
    
    foreach ($data['hourly_buckets'] ?? [] as $hourKey => $bucket) {
        if ($hourKey >= $oneHourAgo) {
            foreach ($totals as $key => &$value) {
                $value += $bucket[$key] ?? 0;
            }
        }
    }
    
    // Calculate success rate
    $successRate = $totals['total_attempts'] > 0 
        ? $totals['total_successes'] / $totals['total_attempts'] 
        : 1.0;
    
    // Determine status
    if ($totals['total_attempts'] === 0) {
        $health['status'] = 'degraded';
        $health['message'] = 'No recent weather fetch activity';
    } elseif ($successRate < 0.5) {
        $health['status'] = 'down';
        $health['message'] = sprintf('Low success rate: %.1f%%', $successRate * 100);
    } elseif ($successRate < 0.8) {
        $health['status'] = 'degraded';
        $health['message'] = sprintf('Degraded success rate: %.1f%%', $successRate * 100);
    } elseif ($totals['circuit_open_events'] > 0) {
        $health['status'] = 'degraded';
        $health['message'] = sprintf('%d circuit breaker event(s) in last hour', $totals['circuit_open_events']);
    } elseif ($totals['fallback_activations'] > 0) {
        $health['status'] = 'operational';
        $health['message'] = sprintf('Operational with %d fallback(s)', $totals['fallback_activations']);
    }
    
    $health['metrics'] = [
        'success_rate' => round($successRate * 100, 1),
        'total_attempts_last_hour' => $totals['total_attempts'],
        'total_successes_last_hour' => $totals['total_successes'],
        'total_failures_last_hour' => $totals['total_failures'],
        'circuit_open_events_last_hour' => $totals['circuit_open_events'],
        'fallback_activations_last_hour' => $totals['fallback_activations']
    ];
    
    return $health;
}

/**
 * Compute per-source health status
 * 
 * @param array $data Aggregated weather health data
 * @return array Source health array keyed by source type
 */
function weather_health_compute_source_status(array $data): array {
    $sources = [];
    $oneHourAgo = gmdate('Y-m-d-H', time() - 3600);
    
    // Known source types
    $sourceTypes = ['tempest', 'ambient', 'metar', 'synopticdata', 'weatherlink_v2', 'weatherlink_v1', 'pwsweather', 'awosnet'];
    
    foreach ($sourceTypes as $sourceType) {
        $attempts = 0;
        $successes = 0;
        $failures = 0;
        $http4xx = 0;
        $http5xx = 0;
        
        foreach ($data['hourly_buckets'] ?? [] as $hourKey => $bucket) {
            if ($hourKey >= $oneHourAgo) {
                $attempts += $bucket["attempts_{$sourceType}"] ?? 0;
                $successes += $bucket["successes_{$sourceType}"] ?? 0;
                $failures += $bucket["failures_{$sourceType}"] ?? 0;
                $http4xx += $bucket["http_4xx_{$sourceType}"] ?? 0;
                $http5xx += $bucket["http_5xx_{$sourceType}"] ?? 0;
            }
        }
        
        // Only include sources that have activity
        if ($attempts === 0) {
            continue;
        }
        
        $successRate = $successes / $attempts;
        
        $status = 'operational';
        $message = 'Responding normally';
        
        if ($successRate < 0.5) {
            $status = 'down';
            $message = sprintf('%.0f%% success rate', $successRate * 100);
        } elseif ($successRate < 0.8) {
            $status = 'degraded';
            $message = sprintf('%.0f%% success rate', $successRate * 100);
        } elseif ($http5xx > 0) {
            $status = 'degraded';
            $message = sprintf('%d server errors', $http5xx);
        }
        
        $sources[$sourceType] = [
            'status' => $status,
            'message' => $message,
            'metrics' => [
                'success_rate' => round($successRate * 100, 1),
                'attempts' => $attempts,
                'successes' => $successes,
                'failures' => $failures,
                'http_4xx' => $http4xx,
                'http_5xx' => $http5xx
            ]
        ];
    }
    
    return $sources;
}

/**
 * Get weather health status (for status page)
 * 
 * Reads from cache file - fast, no circuit breaker or file checks needed.
 * 
 * @return array Health status
 */
function weather_health_get_status(): array {
    $default = [
        'name' => 'Weather Data Fetching',
        'status' => 'operational',
        'message' => 'No data available',
        'lastChanged' => 0,
        'metrics' => []
    ];
    
    if (!file_exists(WEATHER_HEALTH_CACHE_FILE)) {
        return $default;
    }
    
    $content = @file_get_contents(WEATHER_HEALTH_CACHE_FILE);
    if ($content === false) {
        return $default;
    }
    
    $data = @json_decode($content, true);
    if (!is_array($data) || !isset($data['health'])) {
        return $default;
    }
    
    return $data['health'];
}

/**
 * Get per-source health status (for status page)
 * 
 * @return array Source health array keyed by source type
 */
function weather_health_get_sources(): array {
    if (!file_exists(WEATHER_HEALTH_CACHE_FILE)) {
        return [];
    }
    
    $content = @file_get_contents(WEATHER_HEALTH_CACHE_FILE);
    if ($content === false) {
        return [];
    }
    
    $data = @json_decode($content, true);
    if (!is_array($data) || !isset($data['sources'])) {
        return [];
    }
    
    return $data['sources'];
}

