<?php
/**
 * Variant Health Tracking
 * 
 * Tracks webcam variant generation and promotion health using APCu counters.
 * Much faster than parsing logs - events are recorded as they happen.
 * 
 * Usage:
 *   variant_health_track_generation('kspb', 0, 5, 5);  // 5 of 5 succeeded
 *   variant_health_track_promotion('kspb', 0, true);   // promotion succeeded
 *   variant_health_flush();  // Called by scheduler every 60s
 */

require_once __DIR__ . '/cache-paths.php';

// Cache file for variant health status
if (!defined('VARIANT_HEALTH_CACHE_FILE')) {
    define('VARIANT_HEALTH_CACHE_FILE', CACHE_BASE_DIR . '/variant_health.json');
}

// =============================================================================
// APCu COUNTER FUNCTIONS
// =============================================================================

/**
 * Increment a variant health counter in APCu
 * 
 * @param string $key Counter key
 * @param int $amount Amount to increment
 * @return void
 */
function variant_health_increment(string $key, int $amount = 1): void {
    if (!function_exists('apcu_fetch') || $amount <= 0) {
        return;
    }
    
    $fullKey = 'variant_health_' . $key;
    
    $result = @apcu_inc($fullKey, $amount);
    if ($result === false) {
        if (!@apcu_add($fullKey, $amount, 0)) {
            @apcu_inc($fullKey, $amount);
        }
    }
}

/**
 * Get current value of a variant health counter
 * 
 * @param string $key Counter key
 * @return int Current count
 */
function variant_health_get(string $key): int {
    if (!function_exists('apcu_fetch')) {
        return 0;
    }
    
    $value = @apcu_fetch('variant_health_' . $key);
    return $value !== false ? (int)$value : 0;
}

/**
 * Get all variant health counters from APCu
 * 
 * @return array Associative array of counters
 */
function variant_health_get_all(): array {
    if (!function_exists('apcu_fetch')) {
        return [];
    }
    
    $counters = [];
    // Get APCu cache info (false = include cache_list with all entries)
    $info = @apcu_cache_info(false);
    if (!is_array($info) || !isset($info['cache_list'])) {
        return [];
    }
    
    foreach ($info['cache_list'] as $entry) {
        $key = $entry['info'] ?? $entry['key'] ?? null;
        if ($key !== null && strpos($key, 'variant_health_') === 0) {
            $shortKey = substr($key, 15); // Remove 'variant_health_' prefix
            $value = @apcu_fetch($key);
            if ($value !== false) {
                $counters[$shortKey] = (int)$value;
            }
        }
    }
    
    return $counters;
}

/**
 * Reset all variant health counters in APCu
 * 
 * @return void
 */
function variant_health_reset_all(): void {
    if (!function_exists('apcu_fetch')) {
        return;
    }
    
    // Get APCu cache info (false = include cache_list with all entries)
    $info = @apcu_cache_info(false);
    if (!is_array($info) || !isset($info['cache_list'])) {
        return;
    }
    
    foreach ($info['cache_list'] as $entry) {
        $key = $entry['info'] ?? $entry['key'] ?? null;
        if ($key !== null && strpos($key, 'variant_health_') === 0) {
            @apcu_delete($key);
        }
    }
}

// =============================================================================
// TRACKING FUNCTIONS (called by webcam-format-generation.php)
// =============================================================================

/**
 * Track a variant generation event
 * 
 * @param string $airportId Airport identifier
 * @param int $camIndex Camera index
 * @param int $successCount Number of formats successfully generated
 * @param int $totalCount Total formats attempted
 * @return void
 */
function variant_health_track_generation(string $airportId, int $camIndex, int $successCount, int $totalCount): void {
    variant_health_increment('generation_attempts', $totalCount);
    variant_health_increment('generation_successes', $successCount);
    variant_health_increment('generation_events', 1);
    
    if ($successCount < $totalCount) {
        variant_health_increment('generation_failures', $totalCount - $successCount);
    }
    
    // Track last activity timestamp
    if (function_exists('apcu_store')) {
        @apcu_store('variant_health_last_generation', time(), 0);
    }
}

/**
 * Track a variant promotion event
 * 
 * @param string $airportId Airport identifier
 * @param int $camIndex Camera index
 * @param bool $success Whether promotion succeeded
 * @param int $promotedCount Number of formats promoted (optional)
 * @param int $attemptedCount Number of formats attempted (optional)
 * @return void
 */
function variant_health_track_promotion(string $airportId, int $camIndex, bool $success, int $promotedCount = 0, int $attemptedCount = 0): void {
    variant_health_increment('promotion_events', 1);
    
    if ($success) {
        variant_health_increment('promotion_successes', 1);
        if ($promotedCount > 0) {
            variant_health_increment('promotion_formats', $promotedCount);
        }
    } else {
        variant_health_increment('promotion_failures', 1);
    }
    
    // Track partial promotions
    if ($attemptedCount > 0 && $promotedCount < $attemptedCount) {
        variant_health_increment('promotion_partial', 1);
    }
    
    // Track last activity timestamp
    if (function_exists('apcu_store')) {
        @apcu_store('variant_health_last_promotion', time(), 0);
    }
}

// =============================================================================
// FLUSH AND READ FUNCTIONS
// =============================================================================

/**
 * Flush variant health counters to cache file
 * 
 * Called by scheduler every 60 seconds. Merges current counters with
 * existing data in file, maintaining rolling 1-hour window.
 * 
 * @return bool True on success
 */
function variant_health_flush(): bool {
    $counters = variant_health_get_all();
    $now = time();
    
    // Get last generation/promotion timestamps
    $lastGeneration = 0;
    $lastPromotion = 0;
    if (function_exists('apcu_fetch')) {
        $lastGeneration = @apcu_fetch('variant_health_last_generation') ?: 0;
        $lastPromotion = @apcu_fetch('variant_health_last_promotion') ?: 0;
    }
    
    // Read existing data
    $data = [];
    if (file_exists(VARIANT_HEALTH_CACHE_FILE)) {
        $content = @file_get_contents(VARIANT_HEALTH_CACHE_FILE);
        if ($content !== false) {
            $data = @json_decode($content, true) ?: [];
        }
    }
    
    // Initialize if needed
    if (!isset($data['hourly_buckets'])) {
        $data = [
            'hourly_buckets' => [],
            'current_hour' => gmdate('Y-m-d-H'),
            'last_flush' => $now
        ];
    }
    
    $currentHour = gmdate('Y-m-d-H');
    
    // Get or create current hour bucket
    if (!isset($data['hourly_buckets'][$currentHour])) {
        $data['hourly_buckets'][$currentHour] = [
            'generation_events' => 0,
            'generation_attempts' => 0,
            'generation_successes' => 0,
            'generation_failures' => 0,
            'promotion_events' => 0,
            'promotion_successes' => 0,
            'promotion_failures' => 0,
            'promotion_partial' => 0,
            'promotion_formats' => 0
        ];
    }
    
    // Add current counters to bucket
    $bucket = &$data['hourly_buckets'][$currentHour];
    foreach ($counters as $key => $value) {
        if (isset($bucket[$key])) {
            $bucket[$key] += $value;
        }
    }
    
    // Update timestamps
    if ($lastGeneration > 0) {
        $data['last_generation'] = $lastGeneration;
    }
    if ($lastPromotion > 0) {
        $data['last_promotion'] = $lastPromotion;
    }
    $data['last_flush'] = $now;
    $data['current_hour'] = $currentHour;
    
    // Prune buckets older than 2 hours
    $twoHoursAgo = gmdate('Y-m-d-H', $now - 7200);
    foreach (array_keys($data['hourly_buckets']) as $hourKey) {
        if ($hourKey < $twoHoursAgo) {
            unset($data['hourly_buckets'][$hourKey]);
        }
    }
    
    // Compute current health status
    $data['health'] = variant_health_compute_status($data);
    
    // Write atomically
    $tmpFile = VARIANT_HEALTH_CACHE_FILE . '.tmp.' . getmypid();
    $written = @file_put_contents($tmpFile, json_encode($data, JSON_PRETTY_PRINT), LOCK_EX);
    if ($written === false) {
        @unlink($tmpFile);
        return false;
    }
    
    if (!@rename($tmpFile, VARIANT_HEALTH_CACHE_FILE)) {
        @unlink($tmpFile);
        return false;
    }
    
    // Reset APCu counters after successful flush
    variant_health_reset_all();
    
    return true;
}

/**
 * Compute health status from aggregated data
 * 
 * @param array $data Aggregated variant health data
 * @return array Health status array
 */
function variant_health_compute_status(array $data): array {
    $health = [
        'name' => 'Webcam Variant Generation',
        'status' => 'operational',
        'message' => 'All variants generating successfully',
        'lastChanged' => max($data['last_generation'] ?? 0, $data['last_promotion'] ?? 0),
        'metrics' => []
    ];
    
    $now = time();
    $lastGeneration = $data['last_generation'] ?? 0;
    $lastPromotion = $data['last_promotion'] ?? 0;
    $hasRecentActivity = ($lastGeneration > 0 && ($now - $lastGeneration) < 3600) 
                      || ($lastPromotion > 0 && ($now - $lastPromotion) < 3600);
    
    // Sum up last hour of data
    $oneHourAgo = gmdate('Y-m-d-H', $now - 3600);
    $totals = [
        'generation_events' => 0,
        'generation_attempts' => 0,
        'generation_successes' => 0,
        'promotion_events' => 0,
        'promotion_successes' => 0,
        'promotion_failures' => 0
    ];
    
    foreach ($data['hourly_buckets'] ?? [] as $hourKey => $bucket) {
        if ($hourKey >= $oneHourAgo) {
            foreach ($totals as $key => &$value) {
                $value += $bucket[$key] ?? 0;
            }
        }
    }
    
    // Also check current APCu counters (not yet flushed to buckets)
    if (function_exists('apcu_fetch')) {
        $currentGenEvents = variant_health_get('generation_events');
        $currentPromEvents = variant_health_get('promotion_events');
        $currentGenAttempts = variant_health_get('generation_attempts');
        $currentGenSuccesses = variant_health_get('generation_successes');
        $currentPromSuccesses = variant_health_get('promotion_successes');
        
        if ($currentGenEvents > 0 || $currentPromEvents > 0) {
            $totals['generation_events'] += $currentGenEvents;
            $totals['promotion_events'] += $currentPromEvents;
            $totals['generation_attempts'] += $currentGenAttempts;
            $totals['generation_successes'] += $currentGenSuccesses;
            $totals['promotion_successes'] += $currentPromSuccesses;
        }
    }
    
    // Calculate rates
    $genRate = $totals['generation_attempts'] > 0 
        ? $totals['generation_successes'] / $totals['generation_attempts'] 
        : 1.0;
    $promoRate = $totals['promotion_events'] > 0 
        ? $totals['promotion_successes'] / $totals['promotion_events'] 
        : 1.0;
    
    // Determine status
    // If no events in buckets but we have recent activity (from timestamp or APCu), show operational
    if ($totals['generation_events'] === 0 && $totals['promotion_events'] === 0) {
        if ($hasRecentActivity) {
            // Recent activity detected via timestamp, but not yet in buckets (flush pending)
            $health['status'] = 'operational';
            $health['message'] = 'Variants generating (data pending flush)';
        } else {
            $health['status'] = 'degraded';
            $health['message'] = 'No recent variant generation activity';
        }
    } elseif ($genRate < 0.5) {
        $health['status'] = 'down';
        $health['message'] = sprintf('Low generation success rate: %.1f%%', $genRate * 100);
    } elseif ($promoRate < 0.5) {
        $health['status'] = 'down';
        $health['message'] = sprintf('Low promotion success rate: %.1f%%', $promoRate * 100);
    } elseif ($genRate < 0.8 || $promoRate < 0.8) {
        $health['status'] = 'degraded';
        $health['message'] = 'Some variants failing';
    } elseif ($totals['promotion_failures'] > 0) {
        $health['status'] = 'degraded';
        $health['message'] = sprintf('%d recent promotion failure(s)', $totals['promotion_failures']);
    }
    
    $health['metrics'] = [
        'generation_success_rate' => round($genRate * 100, 1),
        'promotion_success_rate' => round($promoRate * 100, 1),
        'total_generations_last_hour' => $totals['generation_events'],
        'total_promotions_last_hour' => $totals['promotion_events']
    ];
    
    return $health;
}

/**
 * Get variant health status (for status page)
 * 
 * Reads from cache file - fast, no log parsing needed.
 * Also checks APCu counters directly as fallback if cache is stale.
 * 
 * @return array Health status
 */
function variant_health_get_status(): array {
    $default = [
        'name' => 'Webcam Variant Generation',
        'status' => 'operational',
        'message' => 'No data available',
        'lastChanged' => 0,
        'metrics' => []
    ];
    
    // Try to read from cache file first
    $cachedHealth = null;
    $data = [];
    if (file_exists(VARIANT_HEALTH_CACHE_FILE)) {
        $content = @file_get_contents(VARIANT_HEALTH_CACHE_FILE);
        if ($content !== false) {
            $data = @json_decode($content, true) ?: [];
            if (is_array($data) && isset($data['health'])) {
                $cachedHealth = $data['health'];
            }
        }
    }
    
    // If we have cached health, use it (but enhance with current APCu data if available)
    if ($cachedHealth !== null) {
        $health = $cachedHealth;
        
        // Check if cache is stale (older than 2 minutes) and we have current APCu activity
        $cacheAge = isset($data['last_flush']) ? (time() - $data['last_flush']) : 999999;
        if ($cacheAge > 120 && function_exists('apcu_fetch')) {
            $currentGenEvents = variant_health_get('generation_events');
            $currentPromEvents = variant_health_get('promotion_events');
            $lastGen = @apcu_fetch('variant_health_last_generation') ?: 0;
            $lastProm = @apcu_fetch('variant_health_last_promotion') ?: 0;
            
            // If we have recent APCu activity, update the health status
            $now = time();
            $hasRecentAPCuActivity = ($lastGen > 0 && ($now - $lastGen) < 3600) 
                                  || ($lastProm > 0 && ($now - $lastProm) < 3600);
            
            if ($hasRecentAPCuActivity && ($currentGenEvents > 0 || $currentPromEvents > 0)) {
                // Update metrics with current APCu counters
                $health['metrics']['total_generations_last_hour'] = 
                    ($health['metrics']['total_generations_last_hour'] ?? 0) + $currentGenEvents;
                $health['metrics']['total_promotions_last_hour'] = 
                    ($health['metrics']['total_promotions_last_hour'] ?? 0) + $currentPromEvents;
                
                // If status was "no activity" but we have APCu activity, upgrade to operational
                if ($health['status'] === 'degraded' && 
                    strpos($health['message'], 'No recent') !== false) {
                    $health['status'] = 'operational';
                    $health['message'] = sprintf(
                        '%d generated, %d promoted (1h)',
                        $health['metrics']['total_generations_last_hour'],
                        $health['metrics']['total_promotions_last_hour']
                    );
                }
                
                // Update lastChanged if APCu timestamp is newer
                $health['lastChanged'] = max(
                    $health['lastChanged'] ?? 0,
                    max($lastGen, $lastProm)
                );
            }
        }
        
        return $health;
    }
    
    // Fallback: check APCu directly if cache file doesn't exist
    if (function_exists('apcu_fetch')) {
        $lastGen = @apcu_fetch('variant_health_last_generation') ?: 0;
        $lastProm = @apcu_fetch('variant_health_last_promotion') ?: 0;
        $currentGenEvents = variant_health_get('generation_events');
        $currentPromEvents = variant_health_get('promotion_events');
        $currentGenAttempts = variant_health_get('generation_attempts');
        $currentGenSuccesses = variant_health_get('generation_successes');
        $currentPromSuccesses = variant_health_get('promotion_successes');
        
        $now = time();
        $hasRecentActivity = ($lastGen > 0 && ($now - $lastGen) < 3600) 
                          || ($lastProm > 0 && ($now - $lastProm) < 3600);
        
        if ($hasRecentActivity || $currentGenEvents > 0 || $currentPromEvents > 0) {
            $genRate = $currentGenAttempts > 0 
                ? ($currentGenSuccesses / $currentGenAttempts) 
                : 1.0;
            $promoRate = $currentPromEvents > 0 
                ? ($currentPromSuccesses / $currentPromEvents) 
                : 1.0;
            
            return [
                'name' => 'Webcam Variant Generation',
                'status' => 'operational',
                'message' => sprintf(
                    '%d generated, %d promoted (1h)',
                    $currentGenEvents,
                    $currentPromEvents
                ),
                'lastChanged' => max($lastGen, $lastProm),
                'metrics' => [
                    'generation_success_rate' => round($genRate * 100, 1),
                    'promotion_success_rate' => round($promoRate * 100, 1),
                    'total_generations_last_hour' => $currentGenEvents,
                    'total_promotions_last_hour' => $currentPromEvents
                ]
            ];
        }
    }
    
    return $default;
}

