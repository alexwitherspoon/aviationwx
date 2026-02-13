<?php
/**
 * Lightweight Metrics Collection
 * 
 * Privacy-first metrics using APCu for fast in-memory counting with periodic flush to JSON files.
 * Only stores aggregate counts - no PII, no individual request tracking.
 * 
 * Metrics tracked:
 * - Airport page views, weather requests, webcam requests
 * - Webcam serves by format (jpg, webp)
 * - Webcam serves by size (height-based: 720, 360, 1080, original)
 * - Webcam image processing (variants_generated, verified, rejected)
 * - Map tile serves by source (openweathermap, rainviewer)
 * - Cache hit/miss rates
 * - Browser format support (WebP vs JPG-only)
 * 
 * Storage:
 * - APCu for real-time counters (microsecond increment)
 * - JSON files flushed every 5 minutes
 * - Hourly, daily, weekly aggregation buckets
 * - 14-day retention with automatic cleanup
 */

require_once __DIR__ . '/cache-paths.php';
require_once __DIR__ . '/constants.php';
require_once __DIR__ . '/logger.php';

// =============================================================================
// SCHEMA DEFINITIONS
// =============================================================================

/**
 * Get empty global metrics structure
 * 
 * Centralized schema definition ensures consistency across hourly, daily,
 * weekly, and rolling aggregations.
 * 
 * @return array Empty global metrics with all fields initialized
 */
function metrics_get_empty_global(): array {
    return [
        'page_views' => 0,
        'weather_requests' => 0,
        'webcam_requests' => 0,
        'webcam_serves' => 0,
        'webcam_uploads_accepted' => 0,
        'webcam_uploads_rejected' => 0,
        'webcam_images_verified' => 0,
        'webcam_images_rejected' => 0,
        'variants_generated' => 0,
        'tiles_served' => 0,
        'tiles_by_source' => ['openweathermap' => 0, 'rainviewer' => 0],
        'format_served' => ['jpg' => 0, 'webp' => 0],
        'size_served' => [],
        'browser_support' => ['webp' => 0, 'jpg_only' => 0],
        'cache' => ['hits' => 0, 'misses' => 0]
    ];
}

/**
 * Get empty airport metrics structure
 * 
 * @return array Empty airport metrics
 */
function metrics_get_empty_airport(): array {
    return [
        'page_views' => 0,
        'weather_requests' => 0,
        'webcam_requests' => 0
    ];
}

/**
 * Get empty webcam metrics structure
 * 
 * @return array Empty webcam metrics
 */
function metrics_get_empty_webcam(): array {
    return [
        'requests' => 0,
        'by_format' => ['jpg' => 0, 'webp' => 0],
        'by_size' => []
    ];
}

// =============================================================================
// MERGE HELPER FUNCTIONS
// =============================================================================

/**
 * Merge global metrics from source into target
 * 
 * @param array &$target Target global metrics array (modified in place)
 * @param array $source Source global metrics to merge
 * @return void
 */
function metrics_merge_global(array &$target, array $source): void {
    // Simple counters
    $target['page_views'] += $source['page_views'] ?? 0;
    $target['weather_requests'] += $source['weather_requests'] ?? 0;
    $target['webcam_requests'] += $source['webcam_requests'] ?? 0;
    $target['webcam_serves'] += $source['webcam_serves'] ?? 0;
    $target['webcam_uploads_accepted'] += $source['webcam_uploads_accepted'] ?? 0;
    $target['webcam_uploads_rejected'] += $source['webcam_uploads_rejected'] ?? 0;
    $target['webcam_images_verified'] += $source['webcam_images_verified'] ?? 0;
    $target['webcam_images_rejected'] += $source['webcam_images_rejected'] ?? 0;
    $target['variants_generated'] += $source['variants_generated'] ?? 0;
    $target['tiles_served'] += $source['tiles_served'] ?? 0;
    
    // Nested counters: tiles_by_source
    foreach ($source['tiles_by_source'] ?? [] as $src => $count) {
        if (!isset($target['tiles_by_source'][$src])) {
            $target['tiles_by_source'][$src] = 0;
        }
        $target['tiles_by_source'][$src] += $count;
    }
    
    // Nested counters: format_served
    foreach ($source['format_served'] ?? [] as $fmt => $count) {
        if (!isset($target['format_served'][$fmt])) {
            $target['format_served'][$fmt] = 0;
        }
        $target['format_served'][$fmt] += $count;
    }
    
    // Nested counters: size_served (dynamic keys)
    foreach ($source['size_served'] ?? [] as $size => $count) {
        if (!isset($target['size_served'][$size])) {
            $target['size_served'][$size] = 0;
        }
        $target['size_served'][$size] += $count;
    }
    
    // Nested counters: browser_support
    foreach ($source['browser_support'] ?? [] as $type => $count) {
        if (!isset($target['browser_support'][$type])) {
            $target['browser_support'][$type] = 0;
        }
        $target['browser_support'][$type] += $count;
    }
    
    // Nested counters: cache
    $target['cache']['hits'] += $source['cache']['hits'] ?? 0;
    $target['cache']['misses'] += $source['cache']['misses'] ?? 0;
}

/**
 * Merge airports metrics from source into target
 * 
 * @param array &$target Target airports array (modified in place)
 * @param array $source Source airports to merge
 * @return void
 */
function metrics_merge_airports(array &$target, array $source): void {
    foreach ($source as $airportId => $airportData) {
        if (!isset($target[$airportId])) {
            $target[$airportId] = metrics_get_empty_airport();
        }
        $target[$airportId]['page_views'] += $airportData['page_views'] ?? 0;
        $target[$airportId]['weather_requests'] += $airportData['weather_requests'] ?? 0;
        $target[$airportId]['webcam_requests'] += $airportData['webcam_requests'] ?? 0;
    }
}

/**
 * Merge webcams metrics from source into target
 * 
 * @param array &$target Target webcams array (modified in place)
 * @param array $source Source webcams to merge
 * @return void
 */
function metrics_merge_webcams(array &$target, array $source): void {
    foreach ($source as $webcamKey => $webcamData) {
        if (!isset($target[$webcamKey])) {
            $target[$webcamKey] = metrics_get_empty_webcam();
        }
        
        $target[$webcamKey]['requests'] += $webcamData['requests'] ?? 0;
        
        foreach ($webcamData['by_format'] ?? [] as $fmt => $count) {
            if (!isset($target[$webcamKey]['by_format'][$fmt])) {
                $target[$webcamKey]['by_format'][$fmt] = 0;
            }
            $target[$webcamKey]['by_format'][$fmt] += $count;
        }
        
        foreach ($webcamData['by_size'] ?? [] as $size => $count) {
            if (!isset($target[$webcamKey]['by_size'][$size])) {
                $target[$webcamKey]['by_size'][$size] = 0;
            }
            $target[$webcamKey]['by_size'][$size] += $count;
        }
    }
}

// =============================================================================
// APCu COUNTER FUNCTIONS
// =============================================================================

/**
 * Check if APCu is available and log if unavailable
 * 
 * Uses rate limiting to avoid log spam (logs once per hour max).
 * 
 * @return bool True if APCu is available
 */
function metrics_is_apcu_available(): bool {
    static $lastLogTime = 0;
    
    if (!function_exists('apcu_fetch')) {
        $now = time();
        if (($now - $lastLogTime) >= 3600) {
            aviationwx_log('error', 'metrics: APCu not available, metrics collection disabled', [
                'reason' => 'apcu_fetch function not found'
            ], 'app');
            $lastLogTime = $now;
        }
        return false;
    }
    
    // Check if APCu is actually functional (enabled in ini)
    if (!@apcu_enabled()) {
        $now = time();
        if (($now - $lastLogTime) >= 3600) {
            aviationwx_log('error', 'metrics: APCu not enabled in PHP configuration', [
                'reason' => 'apcu.enabled is off'
            ], 'app');
            $lastLogTime = $now;
        }
        return false;
    }
    
    return true;
}

/**
 * Increment a metric counter in APCu
 * 
 * Fast (~1μs) atomic increment. Counters are flushed to disk periodically.
 * 
 * @param string $key Metric key (e.g., 'airport_kspb_views')
 * @param int $amount Amount to increment (default: 1)
 * @return void
 */
function metrics_increment(string $key, int $amount = 1): void {
    if (!metrics_is_apcu_available()) {
        return;
    }
    
    $fullKey = 'metrics_' . $key;
    
    // Try to increment existing counter
    $result = @apcu_inc($fullKey, $amount);
    if ($result === false) {
        // Counter doesn't exist, create it
        // Use add() to avoid race condition (fails if key exists)
        if (!@apcu_add($fullKey, $amount, 0)) {
            // Key was created by another process, try increment again
            @apcu_inc($fullKey, $amount);
        }
    }
}

/**
 * Get current value of a metric counter from APCu
 * 
 * @param string $key Metric key
 * @return int Current count (0 if not set)
 */
function metrics_get(string $key): int {
    if (!metrics_is_apcu_available()) {
        return 0;
    }
    
    $value = @apcu_fetch('metrics_' . $key);
    return $value !== false ? (int)$value : 0;
}

/**
 * Get all metric counters from APCu
 * 
 * @return array Associative array of metric key => value
 */
function metrics_get_all(): array {
    if (!metrics_is_apcu_available()) {
        return [];
    }
    
    $metrics = [];
    
    // Get APCu cache info (false = include cache_list with all entries)
    $info = @apcu_cache_info(false);
    if (!is_array($info) || !isset($info['cache_list'])) {
        return [];
    }
    
    foreach ($info['cache_list'] as $entry) {
        $key = $entry['info'] ?? $entry['key'] ?? null;
        if ($key !== null && strpos($key, 'metrics_') === 0) {
            $shortKey = substr($key, 8); // Remove 'metrics_' prefix
            $value = @apcu_fetch($key);
            if ($value !== false) {
                $metrics[$shortKey] = (int)$value;
            }
        }
    }
    
    return $metrics;
}

/**
 * Reset all metric counters in APCu (after flush)
 * 
 * @return void
 */
function metrics_reset_all(): void {
    if (!metrics_is_apcu_available()) {
        return;
    }
    
    // Get APCu cache info (false = include cache_list with all entries)
    $info = @apcu_cache_info(false);
    if (!is_array($info) || !isset($info['cache_list'])) {
        return;
    }
    
    foreach ($info['cache_list'] as $entry) {
        $key = $entry['info'] ?? $entry['key'] ?? null;
        if ($key !== null && strpos($key, 'metrics_') === 0) {
            @apcu_delete($key);
        }
    }
}

// =============================================================================
// CONVENIENCE INCREMENT FUNCTIONS
// =============================================================================

/**
 * Track an airport page view
 * 
 * @param string $airportId Airport identifier (e.g., 'kspb')
 * @return void
 */
function metrics_track_page_view(string $airportId): void {
    $airportId = strtolower($airportId);
    metrics_increment("airport_{$airportId}_views");
    metrics_increment('global_page_views');
}

/**
 * Track a weather API request
 * 
 * @param string $airportId Airport identifier
 * @return void
 */
function metrics_track_weather_request(string $airportId): void {
    $airportId = strtolower($airportId);
    metrics_increment("airport_{$airportId}_weather");
    metrics_increment('global_weather_requests');
}

/**
 * Track a webcam API request (all requests, regardless of cache outcome)
 * 
 * Called early in the webcam API flow to count all incoming requests,
 * unlike metrics_track_webcam_serve() which only counts successful image serves.
 * This provides visibility into total demand vs actual server load.
 * 
 * @param string $airportId Airport identifier
 * @param int $camIndex Camera index
 * @return void
 */
function metrics_track_webcam_request(string $airportId, int $camIndex): void {
    $airportId = strtolower($airportId);
    metrics_increment("airport_{$airportId}_webcam_requests");
    metrics_increment("webcam_{$airportId}_{$camIndex}_requests");
    metrics_increment('global_webcam_requests');
}

/**
 * Track a webcam serve
 * 
 * @param string $airportId Airport identifier
 * @param int $camIndex Camera index
 * @param string $format Image format (jpg, webp)
 * @param string|int $size Image size variant (height like 720, 360, 1080 or 'original')
 * @return void
 */
function metrics_track_webcam_serve(string $airportId, int $camIndex, string $format, string|int $size): void {
    $airportId = strtolower($airportId);
    $format = strtolower($format);
    $size = strtolower((string)$size);
    
    // Track per-camera by format
    metrics_increment("webcam_{$airportId}_{$camIndex}_{$format}");
    
    // Track per-camera by size
    metrics_increment("webcam_{$airportId}_{$camIndex}_size_{$size}");
    
    // Track global format breakdown
    metrics_increment("format_{$format}_served");
    
    // Track global size breakdown
    metrics_increment("size_{$size}_served");
    
    // Track total webcam serves
    metrics_increment('global_webcam_serves');
}

/**
 * Track browser format support (based on Accept header)
 * 
 * @param bool $supportsWebp Browser supports WebP
 * @return void
 */
function metrics_track_format_support(bool $supportsWebp): void {
    if ($supportsWebp) {
        metrics_increment('browser_webp_support');
    } else {
        metrics_increment('browser_jpg_only');
    }
}

/**
 * Track cache hit/miss
 * 
 * @param bool $hit True for cache hit, false for miss
 * @return void
 */
function metrics_track_cache(bool $hit): void {
    if ($hit) {
        metrics_increment('cache_hits');
    } else {
        metrics_increment('cache_misses');
    }
}

/**
 * Track map tile serve
 * 
 * @param string $source Tile source ('openweathermap' or 'rainviewer')
 * @return void
 */
function metrics_track_tile_serve(string $source): void {
    $source = strtolower($source);
    
    // Track per-source
    metrics_increment("tiles_{$source}_served");
    
    // Track global total
    metrics_increment('global_tiles_served');
}

// =============================================================================
// FLUSH FUNCTIONS
// =============================================================================

/**
 * Get current hour bucket ID
 * 
 * @param int|null $timestamp Unix timestamp (default: now)
 * @return string Hour ID (e.g., '2025-12-31-14')
 */
function metrics_get_hour_id(?int $timestamp = null): string {
    $timestamp = $timestamp ?? time();
    return gmdate('Y-m-d-H', $timestamp);
}

/**
 * Get current day bucket ID
 * 
 * @param int|null $timestamp Unix timestamp (default: now)
 * @return string Date ID (e.g., '2025-12-31')
 */
function metrics_get_day_id(?int $timestamp = null): string {
    $timestamp = $timestamp ?? time();
    return gmdate('Y-m-d', $timestamp);
}

/**
 * Get current week bucket ID
 * 
 * @param int|null $timestamp Unix timestamp (default: now)
 * @return string Week ID (e.g., '2025-W01')
 */
function metrics_get_week_id(?int $timestamp = null): string {
    $timestamp = $timestamp ?? time();
    return gmdate('Y-\WW', $timestamp);
}

/**
 * Flush APCu counters to hourly JSON file
 * 
 * Called periodically by scheduler. Reads current counters, adds to hourly bucket,
 * and resets counters.
 * 
 * @return bool True on success
 */
function metrics_flush(): bool {
    // Ensure directories exist
    ensureCacheDir(CACHE_METRICS_DIR);
    ensureCacheDir(CACHE_METRICS_HOURLY_DIR);
    ensureCacheDir(CACHE_METRICS_DAILY_DIR);
    ensureCacheDir(CACHE_METRICS_WEEKLY_DIR);
    
    // Get current counters
    $counters = metrics_get_all();
    if (empty($counters)) {
        return true; // Nothing to flush
    }
    
    $now = time();
    $hourId = metrics_get_hour_id($now);
    $hourFile = getMetricsHourlyPath($hourId);
    
    // Load existing hourly data
    $hourData = [];
    if (file_exists($hourFile)) {
        $content = @file_get_contents($hourFile);
        if ($content !== false) {
            $hourData = @json_decode($content, true) ?: [];
        }
    }
    
    // Initialize structure if needed
    if (!isset($hourData['bucket_type'])) {
        $hourData = [
            'bucket_type' => 'hourly',
            'bucket_id' => $hourId,
            'bucket_start' => strtotime(gmdate('Y-m-d H:00:00', $now) . ' UTC'),
            'bucket_end' => strtotime(gmdate('Y-m-d H:00:00', $now) . ' UTC') + 3600,
            'airports' => [],
            'webcams' => [],
            'webcam_uploads' => [], // Track accepted/rejected uploads per camera (legacy)
            'webcam_images' => [],  // Track verified/rejected images per camera (used by status page)
            'global' => [
                'page_views' => 0,
                'weather_requests' => 0,
                'webcam_requests' => 0,
                'webcam_serves' => 0,
                'webcam_uploads_accepted' => 0,
                'webcam_uploads_rejected' => 0,
                'webcam_images_verified' => 0,
                'webcam_images_rejected' => 0,
                'variants_generated' => 0,
                'tiles_served' => 0,
                'tiles_by_source' => ['openweathermap' => 0, 'rainviewer' => 0],
                'format_served' => ['jpg' => 0, 'webp' => 0],
                'size_served' => [], // Dynamic: height-based variants like '720', '360', 'original'
                'browser_support' => ['webp' => 0, 'jpg_only' => 0],
                'cache' => ['hits' => 0, 'misses' => 0]
            ]
        ];
    }
    
    // Ensure webcam_images structure exists for older data
    if (!isset($hourData['webcam_images'])) {
        $hourData['webcam_images'] = [];
    }
    if (!isset($hourData['global']['webcam_images_verified'])) {
        $hourData['global']['webcam_images_verified'] = 0;
    }
    if (!isset($hourData['global']['webcam_images_rejected'])) {
        $hourData['global']['webcam_images_rejected'] = 0;
    }
    
    // Ensure webcam_requests field exists for older data structures
    if (!isset($hourData['global']['webcam_requests'])) {
        $hourData['global']['webcam_requests'] = 0;
    }
    
    // Ensure tile tracking fields exist for older data structures
    if (!isset($hourData['global']['tiles_served'])) {
        $hourData['global']['tiles_served'] = 0;
    }
    if (!isset($hourData['global']['tiles_by_source'])) {
        $hourData['global']['tiles_by_source'] = ['openweathermap' => 0, 'rainviewer' => 0];
    }
    
    // Merge counters into hourly data
    foreach ($counters as $key => $value) {
        // Parse key to determine where to store
        if (preg_match('/^airport_([a-z0-9]+)_views$/', $key, $m)) {
            $airportId = $m[1];
            if (!isset($hourData['airports'][$airportId])) {
                $hourData['airports'][$airportId] = ['page_views' => 0, 'weather_requests' => 0, 'webcam_requests' => 0];
            }
            $hourData['airports'][$airportId]['page_views'] += $value;
        } elseif (preg_match('/^airport_([a-z0-9]+)_weather$/', $key, $m)) {
            $airportId = $m[1];
            if (!isset($hourData['airports'][$airportId])) {
                $hourData['airports'][$airportId] = ['page_views' => 0, 'weather_requests' => 0, 'webcam_requests' => 0];
            }
            $hourData['airports'][$airportId]['weather_requests'] += $value;
        } elseif (preg_match('/^airport_([a-z0-9]+)_webcam_requests$/', $key, $m)) {
            $airportId = $m[1];
            if (!isset($hourData['airports'][$airportId])) {
                $hourData['airports'][$airportId] = ['page_views' => 0, 'weather_requests' => 0, 'webcam_requests' => 0];
            }
            if (!isset($hourData['airports'][$airportId]['webcam_requests'])) {
                $hourData['airports'][$airportId]['webcam_requests'] = 0;
            }
            $hourData['airports'][$airportId]['webcam_requests'] += $value;
        } elseif (preg_match('/^webcam_([a-z0-9]+)_(\d+)_requests$/', $key, $m)) {
            // Per-camera request tracking (stored under webcams)
            $webcamKey = $m[1] . '_' . $m[2];
            if (!isset($hourData['webcams'][$webcamKey])) {
                $hourData['webcams'][$webcamKey] = [
                    'requests' => 0,
                    'by_format' => ['jpg' => 0, 'webp' => 0],
                    'by_size' => [] // Dynamic: height-based variants like '720', '360', 'original'
                ];
            }
            if (!isset($hourData['webcams'][$webcamKey]['requests'])) {
                $hourData['webcams'][$webcamKey]['requests'] = 0;
            }
            $hourData['webcams'][$webcamKey]['requests'] += $value;
        } elseif (preg_match('/^webcam_([a-z0-9]+)_(\d+)_(jpg|webp)$/', $key, $m)) {
            $webcamKey = $m[1] . '_' . $m[2];
            $format = $m[3];
            if (!isset($hourData['webcams'][$webcamKey])) {
                $hourData['webcams'][$webcamKey] = [
                    'requests' => 0,
                    'by_format' => ['jpg' => 0, 'webp' => 0],
                    'by_size' => []
                ];
            }
            $hourData['webcams'][$webcamKey]['by_format'][$format] += $value;
        } elseif (preg_match('/^webcam_([a-z0-9]+)_(\d+)_size_(\w+)$/', $key, $m)) {
            // Match both height-based (720, 360, 1080) and named (original) sizes
            $webcamKey = $m[1] . '_' . $m[2];
            $size = $m[3];
            if (!isset($hourData['webcams'][$webcamKey])) {
                $hourData['webcams'][$webcamKey] = [
                    'requests' => 0,
                    'by_format' => ['jpg' => 0, 'webp' => 0],
                    'by_size' => []
                ];
            }
            if (!isset($hourData['webcams'][$webcamKey]['by_size'][$size])) {
                $hourData['webcams'][$webcamKey]['by_size'][$size] = 0;
            }
            $hourData['webcams'][$webcamKey]['by_size'][$size] += $value;
        } elseif (preg_match('/^format_(jpg|webp)_served$/', $key, $m)) {
            $hourData['global']['format_served'][$m[1]] += $value;
        } elseif (preg_match('/^size_(\w+)_served$/', $key, $m)) {
            // Match both height-based and named sizes
            $size = $m[1];
            if (!isset($hourData['global']['size_served'][$size])) {
                $hourData['global']['size_served'][$size] = 0;
            }
            $hourData['global']['size_served'][$size] += $value;
        } elseif ($key === 'global_page_views') {
            $hourData['global']['page_views'] += $value;
        } elseif ($key === 'global_weather_requests') {
            $hourData['global']['weather_requests'] += $value;
        } elseif ($key === 'global_webcam_requests') {
            $hourData['global']['webcam_requests'] += $value;
        } elseif ($key === 'global_webcam_serves') {
            $hourData['global']['webcam_serves'] += $value;
        } elseif ($key === 'global_variants_generated') {
            if (!isset($hourData['global']['variants_generated'])) {
                $hourData['global']['variants_generated'] = 0;
            }
            $hourData['global']['variants_generated'] += $value;
        } elseif ($key === 'global_tiles_served') {
            $hourData['global']['tiles_served'] += $value;
        } elseif (preg_match('/^tiles_(openweathermap|rainviewer)_served$/', $key, $m)) {
            $source = $m[1];
            if (!isset($hourData['global']['tiles_by_source'][$source])) {
                $hourData['global']['tiles_by_source'][$source] = 0;
            }
            $hourData['global']['tiles_by_source'][$source] += $value;
        } elseif ($key === 'browser_webp_support') {
            $hourData['global']['browser_support']['webp'] += $value;
        } elseif ($key === 'browser_jpg_only') {
            $hourData['global']['browser_support']['jpg_only'] += $value;
        } elseif ($key === 'cache_hits') {
            $hourData['global']['cache']['hits'] += $value;
        } elseif ($key === 'cache_misses') {
            $hourData['global']['cache']['misses'] += $value;
        } elseif (preg_match('/^webcam_([a-z0-9]+)_(\d+)_uploads_accepted$/', $key, $m)) {
            // Track accepted uploads per camera
            $webcamKey = "webcam_{$m[1]}_{$m[2]}";
            if (!isset($hourData['webcam_uploads'][$webcamKey])) {
                $hourData['webcam_uploads'][$webcamKey] = [
                    'accepted' => 0,
                    'rejected' => 0,
                    'rejection_reasons' => []
                ];
            }
            $hourData['webcam_uploads'][$webcamKey]['accepted'] += $value;
            $hourData['global']['webcam_uploads_accepted'] += $value;
        } elseif (preg_match('/^webcam_([a-z0-9]+)_(\d+)_uploads_rejected$/', $key, $m)) {
            // Track rejected uploads per camera
            $webcamKey = "webcam_{$m[1]}_{$m[2]}";
            if (!isset($hourData['webcam_uploads'][$webcamKey])) {
                $hourData['webcam_uploads'][$webcamKey] = [
                    'accepted' => 0,
                    'rejected' => 0,
                    'rejection_reasons' => []
                ];
            }
            $hourData['webcam_uploads'][$webcamKey]['rejected'] += $value;
            $hourData['global']['webcam_uploads_rejected'] += $value;
        } elseif (preg_match('/^webcam_([a-z0-9]+)_(\d+)_rejection_(.+)$/', $key, $m)) {
            // Track rejection reasons per camera
            $webcamKey = "webcam_{$m[1]}_{$m[2]}";
            $reason = $m[3];
            if (!isset($hourData['webcam_uploads'][$webcamKey])) {
                $hourData['webcam_uploads'][$webcamKey] = [
                    'accepted' => 0,
                    'rejected' => 0,
                    'rejection_reasons' => []
                ];
            }
            if (!isset($hourData['webcam_uploads'][$webcamKey]['rejection_reasons'][$reason])) {
                $hourData['webcam_uploads'][$webcamKey]['rejection_reasons'][$reason] = 0;
            }
            $hourData['webcam_uploads'][$webcamKey]['rejection_reasons'][$reason] += $value;
        } elseif ($key === 'webcam_uploads_accepted_global') {
            $hourData['global']['webcam_uploads_accepted'] += $value;
        } elseif ($key === 'webcam_uploads_rejected_global') {
            $hourData['global']['webcam_uploads_rejected'] += $value;
        } elseif (preg_match('/^webcam_([a-z0-9]+)_(\d+)_images_verified$/', $key, $m)) {
            // Track verified images per camera (from webcam-image-metrics.php)
            $webcamKey = "webcam_{$m[1]}_{$m[2]}";
            if (!isset($hourData['webcam_images'][$webcamKey])) {
                $hourData['webcam_images'][$webcamKey] = [
                    'verified' => 0,
                    'rejected' => 0,
                    'rejection_reasons' => []
                ];
            }
            $hourData['webcam_images'][$webcamKey]['verified'] += $value;
            $hourData['global']['webcam_images_verified'] += $value;
        } elseif (preg_match('/^webcam_([a-z0-9]+)_(\d+)_images_rejected$/', $key, $m)) {
            // Track rejected images per camera (from webcam-image-metrics.php)
            $webcamKey = "webcam_{$m[1]}_{$m[2]}";
            if (!isset($hourData['webcam_images'][$webcamKey])) {
                $hourData['webcam_images'][$webcamKey] = [
                    'verified' => 0,
                    'rejected' => 0,
                    'rejection_reasons' => []
                ];
            }
            $hourData['webcam_images'][$webcamKey]['rejected'] += $value;
            $hourData['global']['webcam_images_rejected'] += $value;
        } elseif ($key === 'webcam_images_verified_global') {
            $hourData['global']['webcam_images_verified'] += $value;
        } elseif ($key === 'webcam_images_rejected_global') {
            $hourData['global']['webcam_images_rejected'] += $value;
        } elseif (preg_match('/^webcam_rejection_reason_(.+)_global$/', $key, $m)) {
            // Global rejection reason tracking (informational)
            // Just increment global counter, per-camera reasons are tracked separately
        }
    }
    
    $hourData['last_flush'] = $now;
    
    // Write hourly data atomically
    $tmpFile = $hourFile . '.tmp.' . getmypid();
    $written = @file_put_contents($tmpFile, json_encode($hourData, JSON_PRETTY_PRINT), LOCK_EX);
    if ($written === false) {
        @unlink($tmpFile);
        return false;
    }
    
    if (!@rename($tmpFile, $hourFile)) {
        @unlink($tmpFile);
        return false;
    }
    
    // Reset APCu counters
    metrics_reset_all();
    
    return true;
}

/**
 * Flush metrics via HTTP to PHP-FPM context
 * 
 * APCu is process-isolated: CLI processes (like the scheduler) cannot access
 * counters incremented by PHP-FPM workers. This function calls an internal
 * endpoint via localhost to trigger the flush within PHP-FPM context.
 * 
 * Use this from CLI scripts instead of metrics_flush() directly.
 * 
 * @return bool True on success
 */
function metrics_flush_via_http(): bool {
    $ch = curl_init();
    
    // Call Apache directly on port 8080 (skips nginx, works in both prod and local)
    // Endpoint in health/ directory to avoid .htaccess blocking of admin/
    $url = 'http://127.0.0.1:8080/health/metrics-flush.php';
    
    curl_setopt_array($ch, [
        CURLOPT_URL => $url,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 5,
        CURLOPT_CONNECTTIMEOUT => 2,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_HTTPHEADER => ['X-Scheduler-Request: 1'],
    ]);
    
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error = curl_error($ch);
    curl_close($ch);
    
    if ($httpCode === 200 && $response !== false) {
        $data = json_decode($response, true);
        if (isset($data['success']) && $data['success']) {
            return true;
        }
    }
    
    // Log failure with rate limiting to avoid log spam
    static $lastLogTime = 0;
    $now = time();
    if (($now - $lastLogTime) >= 300) {
        aviationwx_log('warning', 'metrics: HTTP flush failed', [
            'http_code' => $httpCode,
            'error' => $error ?: 'unknown',
            'response' => substr($response ?: '', 0, 200)
        ], 'app');
        $lastLogTime = $now;
    }
    
    return false;
}

/**
 * Aggregate hourly buckets into daily bucket
 * 
 * Reads all 24 hourly files for a given date and combines them into
 * a single daily aggregate file.
 * 
 * @param string $dateId Date to aggregate (e.g., '2025-12-31')
 * @return bool True on success
 */
function metrics_aggregate_daily(string $dateId): bool {
    ensureCacheDir(CACHE_METRICS_DAILY_DIR);
    
    $dailyData = [
        'bucket_type' => 'daily',
        'bucket_id' => $dateId,
        'bucket_start' => strtotime($dateId . ' 00:00:00 UTC'),
        'bucket_end' => strtotime($dateId . ' 00:00:00 UTC') + 86400,
        'airports' => [],
        'webcams' => [],
        'global' => metrics_get_empty_global(),
        'generated_at' => time()
    ];
    
    // Track which hourly files were found
    $hoursFound = 0;
    $hoursMissing = [];
    $hoursCorrupted = [];
    
    // Find all hourly files for this date
    for ($hour = 0; $hour < 24; $hour++) {
        $hourId = $dateId . '-' . sprintf('%02d', $hour);
        $hourFile = getMetricsHourlyPath($hourId);
        
        if (!file_exists($hourFile)) {
            $hoursMissing[] = $hour;
            continue;
        }
        
        $content = @file_get_contents($hourFile);
        if ($content === false) {
            aviationwx_log('warning', 'metrics: failed to read hourly file during daily aggregation', [
                'date' => $dateId,
                'hour' => $hour,
                'file' => $hourFile
            ], 'app');
            continue;
        }
        
        $hourData = json_decode($content, true);
        if (!is_array($hourData)) {
            $hoursCorrupted[] = $hour;
            aviationwx_log('warning', 'metrics: corrupted JSON in hourly file during daily aggregation', [
                'date' => $dateId,
                'hour' => $hour,
                'file' => $hourFile,
                'json_error' => json_last_error_msg()
            ], 'app');
            continue;
        }
        
        $hoursFound++;
        
        // Use helper functions for consistent merging
        metrics_merge_airports($dailyData['airports'], $hourData['airports'] ?? []);
        metrics_merge_webcams($dailyData['webcams'], $hourData['webcams'] ?? []);
        metrics_merge_global($dailyData['global'], $hourData['global'] ?? []);
    }
    
    // Log summary of aggregation
    if ($hoursFound < 24) {
        aviationwx_log('warning', 'metrics: incomplete daily aggregation', [
            'date' => $dateId,
            'hours_found' => $hoursFound,
            'hours_missing' => count($hoursMissing),
            'hours_corrupted' => count($hoursCorrupted),
            'missing_hours' => $hoursMissing,
            'corrupted_hours' => $hoursCorrupted
        ], 'app');
    }
    
    // Write daily file
    $dailyFile = getMetricsDailyPath($dateId);
    $tmpFile = $dailyFile . '.tmp.' . getmypid();
    $written = @file_put_contents($tmpFile, json_encode($dailyData, JSON_PRETTY_PRINT), LOCK_EX);
    if ($written === false) {
        aviationwx_log('error', 'metrics: failed to write daily aggregation file', [
            'date' => $dateId,
            'file' => $dailyFile,
            'tmp_file' => $tmpFile,
            'disk_free' => disk_free_space(dirname($dailyFile))
        ], 'app');
        @unlink($tmpFile);
        return false;
    }
    
    $renamed = @rename($tmpFile, $dailyFile);
    if (!$renamed) {
        aviationwx_log('error', 'metrics: failed to rename daily aggregation temp file', [
            'date' => $dateId,
            'tmp_file' => $tmpFile,
            'target_file' => $dailyFile
        ], 'app');
        @unlink($tmpFile);
        return false;
    }
    
    return true;
}

/**
 * Aggregate daily buckets into weekly bucket
 * 
 * Reads all 7 daily files for a given ISO week and combines them into
 * a single weekly aggregate file.
 * 
 * @param string $weekId Week to aggregate (e.g., '2025-W01')
 * @return bool True on success
 */
function metrics_aggregate_weekly(string $weekId): bool {
    ensureCacheDir(CACHE_METRICS_WEEKLY_DIR);
    
    // Parse week ID to get date range
    $year = (int)substr($weekId, 0, 4);
    $week = (int)substr($weekId, 6);
    
    // Get first day of week (Monday)
    $dto = new DateTime();
    $dto->setISODate($year, $week, 1);
    $weekStart = $dto->format('Y-m-d');
    
    $weeklyData = [
        'bucket_type' => 'weekly',
        'bucket_id' => $weekId,
        'bucket_start' => strtotime($weekStart . ' 00:00:00 UTC'),
        'bucket_end' => strtotime($weekStart . ' 00:00:00 UTC') + (7 * 86400),
        'airports' => [],
        'webcams' => [],
        'global' => metrics_get_empty_global(),
        'generated_at' => time()
    ];
    
    // Aggregate 7 days
    for ($day = 0; $day < 7; $day++) {
        $dto->setISODate($year, $week, $day + 1);
        $dateId = $dto->format('Y-m-d');
        $dailyFile = getMetricsDailyPath($dateId);
        
        if (!file_exists($dailyFile)) {
            continue;
        }
        
        $content = @file_get_contents($dailyFile);
        if ($content === false) {
            continue;
        }
        
        $dailyData = @json_decode($content, true);
        if (!is_array($dailyData)) {
            continue;
        }
        
        // Use helper functions for consistent merging
        metrics_merge_airports($weeklyData['airports'], $dailyData['airports'] ?? []);
        metrics_merge_webcams($weeklyData['webcams'], $dailyData['webcams'] ?? []);
        metrics_merge_global($weeklyData['global'], $dailyData['global'] ?? []);
    }
    
    // Write weekly file
    $weeklyFile = getMetricsWeeklyPath($weekId);
    $tmpFile = $weeklyFile . '.tmp.' . getmypid();
    $written = @file_put_contents($tmpFile, json_encode($weeklyData, JSON_PRETTY_PRINT), LOCK_EX);
    if ($written === false) {
        @unlink($tmpFile);
        return false;
    }
    
    return @rename($tmpFile, $weeklyFile);
}

// =============================================================================
// READ FUNCTIONS
// =============================================================================

/**
 * Get aggregated metrics for a rolling time period (calendar-day based)
 * 
 * Reads daily files for complete past days plus today's hourly data.
 * Note: This is calendar-day based, not a true rolling window.
 * For exact hour-based rolling windows, use metrics_get_rolling_hours().
 * 
 * @param int $days Number of days to aggregate (default: 7)
 * @return array Aggregated metrics
 */
function metrics_get_rolling(int $days = 7): array {
    $now = time();
    $result = [
        'period_days' => $days,
        'period_start' => $now - ($days * 86400),
        'period_end' => $now,
        'airports' => [],
        'webcams' => [],
        'global' => metrics_get_empty_global(),
        'generated_at' => $now
    ];
    
    // Read daily files for complete days
    $missingDays = [];
    for ($d = 1; $d <= $days; $d++) {
        $dateId = gmdate('Y-m-d', $now - ($d * 86400));
        $dailyFile = getMetricsDailyPath($dateId);
        
        if (!file_exists($dailyFile)) {
            $missingDays[] = $dateId;
            continue;
        }
        
        $content = @file_get_contents($dailyFile);
        if ($content === false) {
            continue;
        }
        
        $dailyData = @json_decode($content, true);
        if (!is_array($dailyData)) {
            continue;
        }
        
        metrics_merge_airports($result['airports'], $dailyData['airports'] ?? []);
        metrics_merge_webcams($result['webcams'], $dailyData['webcams'] ?? []);
        metrics_merge_global($result['global'], $dailyData['global'] ?? []);
    }
    
    // Log if daily files are missing (helps detect aggregation failures)
    // Rate-limit this warning to avoid noisy logs from frequently called request paths
    if (!empty($missingDays)) {
        static $lastMissingDailyLog = 0;
        $logIntervalSeconds = 300; // 5 minutes

        if ($lastMissingDailyLog === 0 || ($now - $lastMissingDailyLog) >= $logIntervalSeconds) {
            aviationwx_log('warning', 'metrics: missing daily files in rolling window', [
                'missing_days' => $missingDays,
                'requested_days' => $days,
                'files_found' => $days - count($missingDays)
            ], 'app');
            $lastMissingDailyLog = $now;
        }
    }
    
    // Add current day's hourly data
    $todayHours = (int)gmdate('H') + 1;
    for ($h = 0; $h < $todayHours; $h++) {
        $hourId = gmdate('Y-m-d', $now) . '-' . sprintf('%02d', $h);
        $hourFile = getMetricsHourlyPath($hourId);
        
        if (!file_exists($hourFile)) {
            continue;
        }
        
        $content = @file_get_contents($hourFile);
        if ($content === false) {
            continue;
        }
        
        $hourData = @json_decode($content, true);
        if (!is_array($hourData)) {
            continue;
        }
        
        metrics_merge_airports($result['airports'], $hourData['airports'] ?? []);
        metrics_merge_webcams($result['webcams'], $hourData['webcams'] ?? []);
        metrics_merge_global($result['global'], $hourData['global'] ?? []);
    }
    
    return $result;
}

/**
 * Get aggregated metrics for a true rolling hour window
 * 
 * Reads hourly files directly for the exact number of hours specified,
 * crossing calendar-day boundaries as needed. This provides a true
 * rolling window (e.g., exactly 24 hours ago to now).
 * 
 * @param int $hours Number of hours to aggregate (default: 24)
 * @return array Aggregated metrics with period_hours instead of period_days
 */
function metrics_get_rolling_hours(int $hours = 24): array {
    $now = time();
    $result = [
        'period_hours' => $hours,
        'period_start' => $now - ($hours * 3600),
        'period_end' => $now,
        'airports' => [],
        'webcams' => [],
        'global' => metrics_get_empty_global(),
        'generated_at' => $now
    ];
    
    // Calculate the starting hour timestamp and iterate through each hour
    // We go backwards from the current hour to include partial current hour data
    $currentHour = (int)gmdate('H', $now);
    $currentDate = gmdate('Y-m-d', $now);
    
    for ($h = 0; $h < $hours; $h++) {
        // Calculate timestamp for this hour bucket (going backwards)
        $hourTimestamp = $now - ($h * 3600);
        $hourId = gmdate('Y-m-d-H', $hourTimestamp);
        $hourFile = getMetricsHourlyPath($hourId);
        
        if (!file_exists($hourFile)) {
            continue;
        }
        
        $content = @file_get_contents($hourFile);
        if ($content === false) {
            continue;
        }
        
        $hourData = @json_decode($content, true);
        if (!is_array($hourData)) {
            continue;
        }
        
        metrics_merge_airports($result['airports'], $hourData['airports'] ?? []);
        metrics_merge_webcams($result['webcams'], $hourData['webcams'] ?? []);
        metrics_merge_global($result['global'], $hourData['global'] ?? []);
    }
    
    return $result;
}

/**
 * Get metrics for a specific airport (7-day rolling)
 * 
 * @param string $airportId Airport identifier
 * @return array Airport-specific metrics
 */
function metrics_get_airport(string $airportId): array {
    $airportId = strtolower($airportId);
    $rolling = metrics_get_rolling(METRICS_STATUS_PAGE_DAYS);
    
    $result = [
        'airport_id' => $airportId,
        'period_days' => METRICS_STATUS_PAGE_DAYS,
        'page_views' => 0,
        'weather_requests' => 0,
        'webcams' => []
    ];
    
    // Get airport-level metrics
    if (isset($rolling['airports'][$airportId])) {
        $result['page_views'] = $rolling['airports'][$airportId]['page_views'] ?? 0;
        $result['weather_requests'] = $rolling['airports'][$airportId]['weather_requests'] ?? 0;
    }
    
    // Get webcam metrics for this airport
    foreach ($rolling['webcams'] as $webcamKey => $webcamData) {
        if (strpos($webcamKey, $airportId . '_') === 0) {
            $camIndex = (int)substr($webcamKey, strlen($airportId) + 1);
            $result['webcams'][$camIndex] = $webcamData;
        }
    }
    
    return $result;
}

/**
 * Get metrics for the current hour from APCu + latest hourly file
 * 
 * Combines unflushed APCu counters with already-flushed hourly file data
 * to provide real-time current hour metrics.
 * 
 * @return array Hourly metrics
 */
function metrics_get_current_hour(): array {
    $now = time();
    $result = [
        'bucket_type' => 'current_hour',
        'bucket_id' => metrics_get_hour_id($now),
        'airports' => [],
        'webcams' => [],
        'global' => metrics_get_empty_global(),
        'generated_at' => $now
    ];
    
    // Read current hour's file if it exists (already flushed data)
    $hourId = metrics_get_hour_id($now);
    $hourFile = getMetricsHourlyPath($hourId);
    if (file_exists($hourFile)) {
        $content = @file_get_contents($hourFile);
        if ($content !== false) {
            $hourData = @json_decode($content, true);
            if (is_array($hourData)) {
                metrics_merge_airports($result['airports'], $hourData['airports'] ?? []);
                metrics_merge_webcams($result['webcams'], $hourData['webcams'] ?? []);
                metrics_merge_global($result['global'], $hourData['global'] ?? []);
            }
        }
    }
    
    // Add current APCu counters (not yet flushed)
    // These are parsed individually since they're raw counter keys, not structured data
    $counters = metrics_get_all();
    foreach ($counters as $key => $value) {
        if (preg_match('/^airport_([a-z0-9]+)_views$/', $key, $m)) {
            $airportId = $m[1];
            if (!isset($result['airports'][$airportId])) {
                $result['airports'][$airportId] = metrics_get_empty_airport();
            }
            $result['airports'][$airportId]['page_views'] += $value;
            $result['global']['page_views'] += $value;
        } elseif (preg_match('/^airport_([a-z0-9]+)_weather$/', $key, $m)) {
            $airportId = $m[1];
            if (!isset($result['airports'][$airportId])) {
                $result['airports'][$airportId] = metrics_get_empty_airport();
            }
            $result['airports'][$airportId]['weather_requests'] += $value;
            $result['global']['weather_requests'] += $value;
        } elseif (preg_match('/^airport_([a-z0-9]+)_webcam_requests$/', $key, $m)) {
            $airportId = $m[1];
            if (!isset($result['airports'][$airportId])) {
                $result['airports'][$airportId] = metrics_get_empty_airport();
            }
            $result['airports'][$airportId]['webcam_requests'] += $value;
            $result['global']['webcam_requests'] += $value;
        } elseif ($key === 'global_webcam_serves') {
            $result['global']['webcam_serves'] += $value;
        } elseif ($key === 'global_variants_generated') {
            $result['global']['variants_generated'] += $value;
        }
    }
    
    return $result;
}

/**
 * Get metrics for today (all hours so far)
 * 
 * Aggregates all hourly files for the current UTC day. Always reads
 * hourly files directly to ensure fresh data (daily files may be stale).
 * 
 * @return array Today's metrics
 */
function metrics_get_today(): array {
    $now = time();
    $todayId = gmdate('Y-m-d', $now);
    
    $result = [
        'bucket_type' => 'today',
        'bucket_id' => $todayId,
        'airports' => [],
        'webcams' => [],
        'global' => metrics_get_empty_global(),
        'generated_at' => $now
    ];
    
    // Aggregate hourly files for today (hours 0 through current hour)
    $currentHour = (int)gmdate('H', $now);
    for ($h = 0; $h <= $currentHour; $h++) {
        $hourId = $todayId . '-' . sprintf('%02d', $h);
        $hourFile = getMetricsHourlyPath($hourId);
        
        if (!file_exists($hourFile)) {
            continue;
        }
        
        $content = @file_get_contents($hourFile);
        if ($content === false) {
            continue;
        }
        
        $hourData = @json_decode($content, true);
        if (!is_array($hourData)) {
            continue;
        }
        
        metrics_merge_airports($result['airports'], $hourData['airports'] ?? []);
        metrics_merge_webcams($result['webcams'], $hourData['webcams'] ?? []);
        metrics_merge_global($result['global'], $hourData['global'] ?? []);
    }
    
    return $result;
}

/**
 * Get multi-period metrics for all airports (hour, day, 7-day)
 * 
 * Returns metrics organized by airport with all three time periods.
 * Optimized for status page display.
 * 
 * @return array Multi-period metrics indexed by airport
 */
function metrics_get_multi_period(): array {
    $hourly = metrics_get_current_hour();
    $daily = metrics_get_today();
    $weekly = metrics_get_rolling(METRICS_STATUS_PAGE_DAYS);
    
    $result = [];
    
    // Collect all airport IDs from all periods
    $allAirports = array_unique(array_merge(
        array_keys($hourly['airports']),
        array_keys($daily['airports'] ?? []),
        array_keys($weekly['airports'])
    ));
    
    foreach ($allAirports as $airportId) {
        $result[$airportId] = [
            'hour' => [
                'page_views' => $hourly['airports'][$airportId]['page_views'] ?? 0,
                'weather_requests' => $hourly['airports'][$airportId]['weather_requests'] ?? 0,
                'webcam_requests' => $hourly['airports'][$airportId]['webcam_requests'] ?? 0,
            ],
            'day' => [
                'page_views' => $daily['airports'][$airportId]['page_views'] ?? 0,
                'weather_requests' => $daily['airports'][$airportId]['weather_requests'] ?? 0,
                'webcam_requests' => $daily['airports'][$airportId]['webcam_requests'] ?? 0,
            ],
            'week' => [
                'page_views' => $weekly['airports'][$airportId]['page_views'] ?? 0,
                'weather_requests' => $weekly['airports'][$airportId]['weather_requests'] ?? 0,
                'webcam_requests' => $weekly['airports'][$airportId]['webcam_requests'] ?? 0,
            ],
            'webcams' => []
        ];
        
        // Get webcam metrics from weekly data
        foreach ($weekly['webcams'] as $webcamKey => $webcamData) {
            if (strpos($webcamKey, $airportId . '_') === 0) {
                $camIndex = (int)substr($webcamKey, strlen($airportId) + 1);
                $result[$airportId]['webcams'][$camIndex] = $webcamData;
            }
        }
    }
    
    return $result;
}

// =============================================================================
// CLEANUP FUNCTIONS
// =============================================================================

/**
 * Clean up old metrics files beyond retention period
 * 
 * Also cleans up orphaned temporary files older than 1 hour.
 * 
 * @return int Number of files deleted
 */
function metrics_cleanup(): int {
    $deleted = 0;
    $retentionSeconds = METRICS_RETENTION_DAYS * 86400;
    $cutoff = time() - $retentionSeconds;
    $tmpFileCutoff = time() - 3600; // 1 hour for temp files
    
    // Clean hourly files
    if (is_dir(CACHE_METRICS_HOURLY_DIR)) {
        $files = glob(CACHE_METRICS_HOURLY_DIR . '/*.json');
        foreach ($files as $file) {
            // Parse date from filename (YYYY-MM-DD-HH.json)
            $basename = basename($file, '.json');
            $timestamp = strtotime(substr($basename, 0, 10) . ' ' . substr($basename, 11, 2) . ':00:00 UTC');
            if ($timestamp !== false && $timestamp < $cutoff) {
                if (@unlink($file)) {
                    $deleted++;
                }
            }
        }
        
        // Clean orphaned tmp files in hourly dir
        $tmpFiles = glob(CACHE_METRICS_HOURLY_DIR . '/*.tmp.*');
        foreach ($tmpFiles as $tmpFile) {
            $mtime = @filemtime($tmpFile);
            if ($mtime !== false && $mtime < $tmpFileCutoff) {
                if (@unlink($tmpFile)) {
                    $deleted++;
                    aviationwx_log('info', 'metrics: cleaned up orphaned temp file', [
                        'file' => basename($tmpFile),
                        'age_hours' => round((time() - $mtime) / 3600, 1)
                    ], 'app');
                }
            }
        }
    }
    
    // Clean daily files
    if (is_dir(CACHE_METRICS_DAILY_DIR)) {
        $files = glob(CACHE_METRICS_DAILY_DIR . '/*.json');
        foreach ($files as $file) {
            $basename = basename($file, '.json');
            $timestamp = strtotime($basename . ' 00:00:00 UTC');
            // Compare end of day to cutoff to avoid deleting files on the boundary day
            if ($timestamp !== false && ($timestamp + 86400) <= $cutoff) {
                if (@unlink($file)) {
                    $deleted++;
                }
            }
        }
        
        // Clean orphaned tmp files in daily dir
        $tmpFiles = glob(CACHE_METRICS_DAILY_DIR . '/*.tmp.*');
        foreach ($tmpFiles as $tmpFile) {
            $mtime = @filemtime($tmpFile);
            if ($mtime !== false && $mtime < $tmpFileCutoff) {
                if (@unlink($tmpFile)) {
                    $deleted++;
                    aviationwx_log('info', 'metrics: cleaned up orphaned temp file', [
                        'file' => basename($tmpFile),
                        'age_hours' => round((time() - $mtime) / 3600, 1)
                    ], 'app');
                }
            }
        }
    }
    
    // Clean weekly files
    if (is_dir(CACHE_METRICS_WEEKLY_DIR)) {
        $files = glob(CACHE_METRICS_WEEKLY_DIR . '/*.json');
        foreach ($files as $file) {
            $basename = basename($file, '.json');
            // Parse ISO week (YYYY-Www)
            $year = (int)substr($basename, 0, 4);
            $week = (int)substr($basename, 6);
            $dto = new DateTime();
            $dto->setISODate($year, $week, 1);
            $timestamp = $dto->getTimestamp();
            if ($timestamp < $cutoff) {
                if (@unlink($file)) {
                    $deleted++;
                }
            }
        }
        
        // Clean orphaned tmp files in weekly dir
        $tmpFiles = glob(CACHE_METRICS_WEEKLY_DIR . '/*.tmp.*');
        foreach ($tmpFiles as $tmpFile) {
            $mtime = @filemtime($tmpFile);
            if ($mtime !== false && $mtime < $tmpFileCutoff) {
                if (@unlink($tmpFile)) {
                    $deleted++;
                    aviationwx_log('info', 'metrics: cleaned up orphaned temp file', [
                        'file' => basename($tmpFile),
                        'age_hours' => round((time() - $mtime) / 3600, 1)
                    ], 'app');
                }
            }
        }
    }
    
    return $deleted;
}

// =============================================================================
// HEALTH MONITORING
// =============================================================================

/**
 * Get APCu memory usage information
 * 
 * @return array|null Memory info or null if APCu unavailable
 */
function metrics_get_apcu_memory_info(): ?array {
    if (!metrics_is_apcu_available()) {
        return null;
    }
    
    $sma = @apcu_sma_info();
    if (!is_array($sma)) {
        return null;
    }
    
    $totalMem = $sma['num_seg'] * $sma['seg_size'];
    $availMem = $sma['avail_mem'];
    $usedMem = $totalMem - $availMem;
    $usedPercent = $totalMem > 0 ? ($usedMem / $totalMem) * 100 : 0;
    
    return [
        'total_bytes' => $totalMem,
        'used_bytes' => $usedMem,
        'available_bytes' => $availMem,
        'used_percent' => round($usedPercent, 2),
        'is_full' => $usedPercent > 90
    ];
}

/**
 * Get disk space information for metrics directory
 * 
 * @return array Disk space info
 */
function metrics_get_disk_space_info(): array {
    $metricsDir = CACHE_METRICS_DIR;
    $totalSpace = @disk_total_space($metricsDir);
    $freeSpace = @disk_free_space($metricsDir);
    
    if ($totalSpace === false || $freeSpace === false) {
        return [
            'total_bytes' => 0,
            'free_bytes' => 0,
            'used_bytes' => 0,
            'used_percent' => 0,
            'is_low' => false,
            'is_critical' => false,
            'error' => 'Unable to determine disk space'
        ];
    }
    
    $usedSpace = $totalSpace - $freeSpace;
    $usedPercent = $totalSpace > 0 ? ($usedSpace / $totalSpace) * 100 : 0;
    
    return [
        'total_bytes' => $totalSpace,
        'free_bytes' => $freeSpace,
        'used_bytes' => $usedSpace,
        'used_percent' => round($usedPercent, 2),
        'is_low' => $usedPercent > 90,
        'is_critical' => $usedPercent > 95
    ];
}

/**
 * Get metrics system health status
 * 
 * @return array Health check results
 */
function metrics_get_health_status(): array {
    $health = [
        'healthy' => true,
        'warnings' => [],
        'errors' => []
    ];
    
    // Check APCu availability
    if (!metrics_is_apcu_available()) {
        $health['healthy'] = false;
        $health['errors'][] = 'APCu is not available';
    } else {
        $memInfo = metrics_get_apcu_memory_info();
        if ($memInfo && $memInfo['is_full']) {
            $health['healthy'] = false;
            $health['warnings'][] = sprintf(
                'APCu memory %.1f%% full (%s used of %s)',
                $memInfo['used_percent'],
                format_bytes($memInfo['used_bytes']),
                format_bytes($memInfo['total_bytes'])
            );
        }
    }
    
    // Check disk space
    $diskInfo = metrics_get_disk_space_info();
    if ($diskInfo['is_critical']) {
        $health['healthy'] = false;
        $health['errors'][] = sprintf(
            'Disk space critical: %.1f%% used',
            $diskInfo['used_percent']
        );
    } elseif ($diskInfo['is_low']) {
        $health['warnings'][] = sprintf(
            'Disk space low: %.1f%% used',
            $diskInfo['used_percent']
        );
    }
    
    // Check timezone
    $tz = date_default_timezone_get();
    if ($tz !== 'UTC') {
        $health['warnings'][] = sprintf(
            'Timezone is %s, expected UTC',
            $tz
        );
    }
    
    return $health;
}

/**
 * Format bytes as human-readable string
 * 
 * @param int $bytes Bytes to format
 * @return string Formatted string
 */
function format_bytes(int $bytes): string {
    $units = ['B', 'KB', 'MB', 'GB', 'TB'];
    $i = 0;
    while ($bytes >= 1024 && $i < count($units) - 1) {
        $bytes /= 1024;
        $i++;
    }
    return round($bytes, 2) . ' ' . $units[$i];
}

/**
 * Report metrics health to Sentry
 * 
 * Sends custom metrics and health warnings to Sentry for monitoring.
 * Called periodically by scheduler (every 5 minutes).
 * 
 * @return void
 */
function metrics_report_to_sentry(): void {
    if (!defined('SENTRY_INITIALIZED') || !SENTRY_INITIALIZED) {
        return;
    }
    
    // APCu Memory Pressure
    $memInfo = metrics_get_apcu_memory_info();
    if ($memInfo && $memInfo['used_percent'] > 80) {
        $severity = $memInfo['used_percent'] > 90 ? \Sentry\Severity::error() : \Sentry\Severity::warning();
        
        \Sentry\withScope(function (\Sentry\State\Scope $scope) use ($memInfo, $severity): void {
            $scope->setContext('apcu_memory', $memInfo);
            $scope->setTag('resource_type', 'memory');
            
            \Sentry\captureMessage(
                "APCu memory pressure: {$memInfo['used_percent']}% used",
                $severity
            );
        });
    }
    
    // Disk Space Monitoring
    $diskInfo = metrics_get_disk_space_info();
    if ($diskInfo['is_critical']) {
        \Sentry\withScope(function (\Sentry\State\Scope $scope) use ($diskInfo): void {
            $scope->setContext('disk_space', $diskInfo);
            $scope->setTag('resource_type', 'disk');
            
            \Sentry\captureMessage(
                "Disk space critical: {$diskInfo['used_percent']}% used ({$diskInfo['free_bytes']} bytes free)",
                \Sentry\Severity::fatal()
            );
        });
    } elseif ($diskInfo['is_low']) {
        \Sentry\withScope(function (\Sentry\State\Scope $scope) use ($diskInfo): void {
            $scope->setContext('disk_space', $diskInfo);
            $scope->setTag('resource_type', 'disk');
            
            \Sentry\captureMessage(
                "Disk space low: {$diskInfo['used_percent']}% used",
                \Sentry\Severity::warning()
            );
        });
    }
    
    // Check for recent aggregation issues
    // Look for incomplete aggregations in recent daily files
    $yesterday = gmdate('Y-m-d', time() - 86400);
    $dailyFile = getMetricsDailyPath($yesterday);
    
    if (file_exists($dailyFile)) {
        $content = @file_get_contents($dailyFile);
        if ($content !== false) {
            $data = @json_decode($content, true);
            
            // Check if aggregation was incomplete (less than 20 hours indicates issues)
            if (is_array($data) && isset($data['airports'])) {
                $totalViews = $data['global']['page_views'] ?? 0;
                
                // If we have almost no data, something went wrong
                if ($totalViews < 10 && gmdate('Y-m-d') !== $yesterday) {
                    \Sentry\withScope(function (\Sentry\State\Scope $scope) use ($yesterday, $totalViews): void {
                        $scope->setTag('date', $yesterday);
                        $scope->setContext('aggregation', [
                            'date' => $yesterday,
                            'total_views' => $totalViews,
                        ]);
                        
                        \Sentry\captureMessage(
                            "Suspiciously low metrics for {$yesterday}: {$totalViews} total views",
                            \Sentry\Severity::warning()
                        );
                    });
                }
            }
        }
    }
    
    // ADDITIONAL CUSTOM METRICS - Send operational statistics to Sentry
    metrics_report_operational_stats();
}

/**
 * Report operational statistics to Sentry
 * 
 * Sends additional custom metrics about system performance and health.
 * Called by metrics_report_to_sentry() every 5 minutes.
 * 
 * @return void
 */
function metrics_report_operational_stats(): void {
    if (!defined('SENTRY_INITIALIZED') || !SENTRY_INITIALIZED) {
        return;
    }
    
    // Get 24-hour rolling metrics
    $rolling24h = metrics_get_rolling_hours(24);
    
    // Report image processing performance
    if (file_exists(__DIR__ . '/performance-metrics.php')) {
        require_once __DIR__ . '/performance-metrics.php';
        $imagePerf = getImageProcessingMetrics();
        
        // Alert if p95 is > 2 seconds (image processing slowdown)
        if ($imagePerf['p95_ms'] > 2000) {
            \Sentry\withScope(function (\Sentry\State\Scope $scope) use ($imagePerf): void {
                $scope->setContext('image_processing', $imagePerf);
                $scope->setTag('metric_type', 'performance');
                
                \Sentry\captureMessage(
                    "Slow image processing: p95={$imagePerf['p95_ms']}ms (threshold: 2000ms)",
                    \Sentry\Severity::warning()
                );
            });
        }
        
        // Alert if page render p95 is > 500ms (UX degradation)
        $pagePerf = getPageRenderMetrics();
        if ($pagePerf['p95_ms'] > 500) {
            \Sentry\withScope(function (\Sentry\State\Scope $scope) use ($pagePerf): void {
                $scope->setContext('page_render', $pagePerf);
                $scope->setTag('metric_type', 'performance');
                
                \Sentry\captureMessage(
                    "Slow page renders: p95={$pagePerf['p95_ms']}ms (threshold: 500ms)",
                    \Sentry\Severity::warning()
                );
            });
        }
    }
    
    // Report circuit breaker status
    if (file_exists(__DIR__ . '/circuit-breaker.php')) {
        require_once __DIR__ . '/circuit-breaker.php';
        
        // Check weather circuit breakers
        $config = loadConfig();
        if ($config && isset($config['airports'])) {
            $openBreakers = [];
            foreach ($config['airports'] as $airportId => $airport) {
                $weatherStatus = circuitBreakerStatus('weather', $airportId);
                if ($weatherStatus['state'] === 'open') {
                    $openBreakers[] = $airportId;
                }
            }
            
            // Alert if > 10% of airports have open weather breakers
            $totalAirports = count($config['airports']);
            $openPercent = ($totalAirports > 0) ? (count($openBreakers) / $totalAirports) * 100 : 0;
            
            if ($openPercent > 10) {
                \Sentry\withScope(function (\Sentry\State\Scope $scope) use ($openBreakers, $openPercent): void {
                    $scope->setContext('circuit_breakers', [
                        'open_count' => count($openBreakers),
                        'open_percent' => round($openPercent, 1),
                        'airports' => array_slice($openBreakers, 0, 10), // First 10
                    ]);
                    $scope->setTag('metric_type', 'reliability');
                    
                    \Sentry\captureMessage(
                        "Multiple weather circuit breakers open: " . round($openPercent, 1) . "% of airports",
                        \Sentry\Severity::warning()
                    );
                });
            }
        }
    }
    
    // Report variant health (image pipeline success rate)
    if (file_exists(__DIR__ . '/variant-health.php')) {
        require_once __DIR__ . '/variant-health.php';
        $variantHealth = variant_health_get_stats();
        
        // Calculate success rate
        $totalAttempts = ($variantHealth['success'] ?? 0) + ($variantHealth['failures'] ?? 0);
        if ($totalAttempts > 0) {
            $successRate = (($variantHealth['success'] ?? 0) / $totalAttempts) * 100;
            
            // Alert if success rate < 90% (image pipeline issues)
            if ($successRate < 90) {
                \Sentry\withScope(function (\Sentry\State\Scope $scope) use ($variantHealth, $successRate): void {
                    $scope->setContext('variant_health', $variantHealth);
                    $scope->setTag('metric_type', 'reliability');
                    
                    \Sentry\captureMessage(
                        "Low image variant generation success rate: " . round($successRate, 1) . "%",
                        \Sentry\Severity::warning()
                    );
                });
            }
        }
    }
    
    // Report scheduler health
    $schedulerLockFile = '/tmp/scheduler.lock';
    if (file_exists($schedulerLockFile)) {
        $lockContent = @file_get_contents($schedulerLockFile);
        if ($lockContent) {
            $lockData = @json_decode($lockContent, true);
            if ($lockData && isset($lockData['health'])) {
                // Alert if scheduler is unhealthy
                if ($lockData['health'] !== 'healthy') {
                    \Sentry\withScope(function (\Sentry\State\Scope $scope) use ($lockData): void {
                        $scope->setContext('scheduler', $lockData);
                        $scope->setTag('metric_type', 'system_health');
                        
                        \Sentry\captureMessage(
                            "Scheduler unhealthy: {$lockData['health']} - {$lockData['last_error']}",
                            \Sentry\Severity::error()
                        );
                    });
                }
                
                // Alert if scheduler loop is very slow (>10s per iteration)
                if (isset($lockData['loop_count']) && isset($lockData['uptime']) && $lockData['uptime'] > 0) {
                    $loopRate = $lockData['loop_count'] / $lockData['uptime']; // loops per second
                    $loopDuration = 1 / $loopRate; // seconds per loop
                    
                    if ($loopDuration > 10) {
                        \Sentry\withScope(function (\Sentry\State\Scope $scope) use ($lockData, $loopDuration): void {
                            $scope->setContext('scheduler_performance', [
                                'loop_duration_seconds' => round($loopDuration, 2),
                                'loop_count' => $lockData['loop_count'],
                                'uptime' => $lockData['uptime'],
                            ]);
                            $scope->setTag('metric_type', 'performance');
                            
                            \Sentry\captureMessage(
                                "Slow scheduler loops: " . round($loopDuration, 2) . "s per iteration (threshold: 10s)",
                                \Sentry\Severity::warning()
                            );
                        });
                    }
                }
            }
        }
    }
}

// =============================================================================
// PROMETHEUS EXPORT
// =============================================================================

/**
 * Get metrics in Prometheus format
 * 
 * Uses true 24-hour rolling window for accurate metrics export.
 * 
 * @return array Array of metric lines
 */
function metrics_prometheus_export(): array {
    $lines = [];
    $rolling = metrics_get_rolling_hours(24);
    
    // Airport metrics
    foreach ($rolling['airports'] as $airportId => $data) {
        $lines[] = sprintf('aviationwx_airport_views_total{airport="%s"} %d', $airportId, $data['page_views']);
        $lines[] = sprintf('aviationwx_weather_requests_total{airport="%s"} %d', $airportId, $data['weather_requests']);
        $lines[] = sprintf('aviationwx_webcam_requests_total{airport="%s"} %d', $airportId, $data['webcam_requests']);
    }
    
    // Webcam serves by format and size
    foreach ($rolling['webcams'] as $webcamKey => $data) {
        $parts = explode('_', $webcamKey);
        if (count($parts) >= 2) {
            $airport = $parts[0];
            $cam = $parts[1];
            
            // Webcam requests
            $lines[] = sprintf('aviationwx_webcam_cam_requests_total{airport="%s",cam="%s"} %d', 
                $airport, $cam, $data['requests'] ?? 0);
            
            foreach ($data['by_format'] ?? [] as $format => $count) {
                $lines[] = sprintf('aviationwx_webcam_serves_total{airport="%s",cam="%s",format="%s"} %d', 
                    $airport, $cam, $format, $count);
            }
            
            foreach ($data['by_size'] ?? [] as $size => $count) {
                $lines[] = sprintf('aviationwx_webcam_serves_by_size_total{airport="%s",cam="%s",size="%s"} %d', 
                    $airport, $cam, $size, $count);
            }
        }
    }
    
    // Global format support
    foreach ($rolling['global']['browser_support'] ?? [] as $type => $count) {
        $lines[] = sprintf('aviationwx_browser_format_support_total{format="%s"} %d', $type, $count);
    }
    
    // Cache performance
    $lines[] = sprintf('aviationwx_cache_hits_total %d', $rolling['global']['cache']['hits'] ?? 0);
    $lines[] = sprintf('aviationwx_cache_misses_total %d', $rolling['global']['cache']['misses'] ?? 0);
    
    // Global totals
    $lines[] = sprintf('aviationwx_page_views_total %d', $rolling['global']['page_views'] ?? 0);
    $lines[] = sprintf('aviationwx_weather_requests_total %d', $rolling['global']['weather_requests'] ?? 0);
    $lines[] = sprintf('aviationwx_webcam_requests_total %d', $rolling['global']['webcam_requests'] ?? 0);
    $lines[] = sprintf('aviationwx_webcam_serves_total %d', $rolling['global']['webcam_serves'] ?? 0);
    
    // Image processing metrics
    $lines[] = sprintf('aviationwx_variants_generated_total %d', $rolling['global']['variants_generated'] ?? 0);
    $lines[] = sprintf('aviationwx_webcam_images_verified_total %d', $rolling['global']['webcam_images_verified'] ?? 0);
    $lines[] = sprintf('aviationwx_webcam_images_rejected_total %d', $rolling['global']['webcam_images_rejected'] ?? 0);
    $lines[] = sprintf('aviationwx_webcam_uploads_accepted_total %d', $rolling['global']['webcam_uploads_accepted'] ?? 0);
    $lines[] = sprintf('aviationwx_webcam_uploads_rejected_total %d', $rolling['global']['webcam_uploads_rejected'] ?? 0);
    
    // Map tile metrics
    $lines[] = sprintf('aviationwx_map_tiles_served_total %d', $rolling['global']['tiles_served'] ?? 0);
    foreach ($rolling['global']['tiles_by_source'] ?? [] as $source => $count) {
        $lines[] = sprintf('aviationwx_map_tiles_served_by_source_total{source="%s"} %d', $source, $count);
    }
    
    return $lines;
}

