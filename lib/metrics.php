<?php
/**
 * Lightweight Metrics Collection
 * 
 * Privacy-first metrics using APCu for fast in-memory counting with periodic flush to JSON files.
 * Only stores aggregate counts - no PII, no individual request tracking.
 * 
 * Metrics tracked:
 * - Airport page views
 * - Weather API requests per airport
 * - Webcam serves by format (jpg, webp, avif)
 * - Webcam serves by size (thumb, small, medium, large, primary, full)
 * - Cache hit/miss rates
 * - Browser format support
 * 
 * Storage:
 * - APCu for real-time counters (microsecond increment)
 * - JSON files flushed every 5 minutes
 * - Hourly, daily, weekly aggregation buckets
 * - 14-day retention with automatic cleanup
 */

require_once __DIR__ . '/cache-paths.php';
require_once __DIR__ . '/constants.php';

// =============================================================================
// APCu COUNTER FUNCTIONS
// =============================================================================

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
    if (!function_exists('apcu_fetch')) {
        return; // APCu not available, skip silently
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
    if (!function_exists('apcu_fetch')) {
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
    if (!function_exists('apcu_fetch')) {
        return [];
    }
    
    $metrics = [];
    
    // Get APCu cache info
    $info = @apcu_cache_info(true);
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
    if (!function_exists('apcu_fetch')) {
        return;
    }
    
    $info = @apcu_cache_info(true);
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
 * Track a webcam serve
 * 
 * @param string $airportId Airport identifier
 * @param int $camIndex Camera index
 * @param string $format Image format (jpg, webp, avif)
 * @param string $size Image size variant (thumb, small, medium, large, primary, full)
 * @return void
 */
function metrics_track_webcam_serve(string $airportId, int $camIndex, string $format, string $size): void {
    $airportId = strtolower($airportId);
    $format = strtolower($format);
    $size = strtolower($size);
    
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
 * @param bool $supportsAvif Browser supports AVIF
 * @param bool $supportsWebp Browser supports WebP
 * @return void
 */
function metrics_track_format_support(bool $supportsAvif, bool $supportsWebp): void {
    if ($supportsAvif) {
        metrics_increment('browser_avif_support');
    } elseif ($supportsWebp) {
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
            'global' => [
                'page_views' => 0,
                'weather_requests' => 0,
                'webcam_serves' => 0,
                'format_served' => ['jpg' => 0, 'webp' => 0, 'avif' => 0],
                'size_served' => ['thumb' => 0, 'small' => 0, 'medium' => 0, 'large' => 0, 'primary' => 0, 'full' => 0],
                'browser_support' => ['avif' => 0, 'webp' => 0, 'jpg_only' => 0],
                'cache' => ['hits' => 0, 'misses' => 0]
            ]
        ];
    }
    
    // Merge counters into hourly data
    foreach ($counters as $key => $value) {
        // Parse key to determine where to store
        if (preg_match('/^airport_([a-z0-9]+)_views$/', $key, $m)) {
            $airportId = $m[1];
            if (!isset($hourData['airports'][$airportId])) {
                $hourData['airports'][$airportId] = ['page_views' => 0, 'weather_requests' => 0];
            }
            $hourData['airports'][$airportId]['page_views'] += $value;
        } elseif (preg_match('/^airport_([a-z0-9]+)_weather$/', $key, $m)) {
            $airportId = $m[1];
            if (!isset($hourData['airports'][$airportId])) {
                $hourData['airports'][$airportId] = ['page_views' => 0, 'weather_requests' => 0];
            }
            $hourData['airports'][$airportId]['weather_requests'] += $value;
        } elseif (preg_match('/^webcam_([a-z0-9]+)_(\d+)_(jpg|webp|avif)$/', $key, $m)) {
            $webcamKey = $m[1] . '_' . $m[2];
            $format = $m[3];
            if (!isset($hourData['webcams'][$webcamKey])) {
                $hourData['webcams'][$webcamKey] = [
                    'by_format' => ['jpg' => 0, 'webp' => 0, 'avif' => 0],
                    'by_size' => ['thumb' => 0, 'small' => 0, 'medium' => 0, 'large' => 0, 'primary' => 0, 'full' => 0]
                ];
            }
            $hourData['webcams'][$webcamKey]['by_format'][$format] += $value;
        } elseif (preg_match('/^webcam_([a-z0-9]+)_(\d+)_size_(thumb|small|medium|large|primary|full)$/', $key, $m)) {
            $webcamKey = $m[1] . '_' . $m[2];
            $size = $m[3];
            if (!isset($hourData['webcams'][$webcamKey])) {
                $hourData['webcams'][$webcamKey] = [
                    'by_format' => ['jpg' => 0, 'webp' => 0, 'avif' => 0],
                    'by_size' => ['thumb' => 0, 'small' => 0, 'medium' => 0, 'large' => 0, 'primary' => 0, 'full' => 0]
                ];
            }
            $hourData['webcams'][$webcamKey]['by_size'][$size] += $value;
        } elseif (preg_match('/^format_(jpg|webp|avif)_served$/', $key, $m)) {
            $hourData['global']['format_served'][$m[1]] += $value;
        } elseif (preg_match('/^size_(thumb|small|medium|large|primary|full)_served$/', $key, $m)) {
            $hourData['global']['size_served'][$m[1]] += $value;
        } elseif ($key === 'global_page_views') {
            $hourData['global']['page_views'] += $value;
        } elseif ($key === 'global_weather_requests') {
            $hourData['global']['weather_requests'] += $value;
        } elseif ($key === 'global_webcam_serves') {
            $hourData['global']['webcam_serves'] += $value;
        } elseif ($key === 'browser_avif_support') {
            $hourData['global']['browser_support']['avif'] += $value;
        } elseif ($key === 'browser_webp_support') {
            $hourData['global']['browser_support']['webp'] += $value;
        } elseif ($key === 'browser_jpg_only') {
            $hourData['global']['browser_support']['jpg_only'] += $value;
        } elseif ($key === 'cache_hits') {
            $hourData['global']['cache']['hits'] += $value;
        } elseif ($key === 'cache_misses') {
            $hourData['global']['cache']['misses'] += $value;
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
 * Aggregate hourly buckets into daily bucket
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
        'global' => [
            'page_views' => 0,
            'weather_requests' => 0,
            'webcam_serves' => 0,
            'format_served' => ['jpg' => 0, 'webp' => 0, 'avif' => 0],
            'size_served' => ['thumb' => 0, 'small' => 0, 'medium' => 0, 'large' => 0, 'primary' => 0, 'full' => 0],
            'browser_support' => ['avif' => 0, 'webp' => 0, 'jpg_only' => 0],
            'cache' => ['hits' => 0, 'misses' => 0]
        ],
        'generated_at' => time()
    ];
    
    // Find all hourly files for this date
    for ($hour = 0; $hour < 24; $hour++) {
        $hourId = $dateId . '-' . sprintf('%02d', $hour);
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
        
        // Merge airports
        foreach ($hourData['airports'] ?? [] as $airportId => $airportData) {
            if (!isset($dailyData['airports'][$airportId])) {
                $dailyData['airports'][$airportId] = ['page_views' => 0, 'weather_requests' => 0];
            }
            $dailyData['airports'][$airportId]['page_views'] += $airportData['page_views'] ?? 0;
            $dailyData['airports'][$airportId]['weather_requests'] += $airportData['weather_requests'] ?? 0;
        }
        
        // Merge webcams
        foreach ($hourData['webcams'] ?? [] as $webcamKey => $webcamData) {
            if (!isset($dailyData['webcams'][$webcamKey])) {
                $dailyData['webcams'][$webcamKey] = [
                    'by_format' => ['jpg' => 0, 'webp' => 0, 'avif' => 0],
                    'by_size' => ['thumb' => 0, 'small' => 0, 'medium' => 0, 'large' => 0, 'primary' => 0, 'full' => 0]
                ];
            }
            foreach ($webcamData['by_format'] ?? [] as $fmt => $count) {
                $dailyData['webcams'][$webcamKey]['by_format'][$fmt] += $count;
            }
            foreach ($webcamData['by_size'] ?? [] as $sz => $count) {
                $dailyData['webcams'][$webcamKey]['by_size'][$sz] += $count;
            }
        }
        
        // Merge global
        $global = $hourData['global'] ?? [];
        $dailyData['global']['page_views'] += $global['page_views'] ?? 0;
        $dailyData['global']['weather_requests'] += $global['weather_requests'] ?? 0;
        $dailyData['global']['webcam_serves'] += $global['webcam_serves'] ?? 0;
        
        foreach ($global['format_served'] ?? [] as $fmt => $count) {
            $dailyData['global']['format_served'][$fmt] += $count;
        }
        foreach ($global['size_served'] ?? [] as $sz => $count) {
            $dailyData['global']['size_served'][$sz] += $count;
        }
        foreach ($global['browser_support'] ?? [] as $type => $count) {
            $dailyData['global']['browser_support'][$type] += $count;
        }
        $dailyData['global']['cache']['hits'] += $global['cache']['hits'] ?? 0;
        $dailyData['global']['cache']['misses'] += $global['cache']['misses'] ?? 0;
    }
    
    // Write daily file
    $dailyFile = getMetricsDailyPath($dateId);
    $tmpFile = $dailyFile . '.tmp.' . getmypid();
    $written = @file_put_contents($tmpFile, json_encode($dailyData, JSON_PRETTY_PRINT), LOCK_EX);
    if ($written === false) {
        @unlink($tmpFile);
        return false;
    }
    
    return @rename($tmpFile, $dailyFile);
}

/**
 * Aggregate daily buckets into weekly bucket
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
        'global' => [
            'page_views' => 0,
            'weather_requests' => 0,
            'webcam_serves' => 0,
            'format_served' => ['jpg' => 0, 'webp' => 0, 'avif' => 0],
            'size_served' => ['thumb' => 0, 'small' => 0, 'medium' => 0, 'large' => 0, 'primary' => 0, 'full' => 0],
            'browser_support' => ['avif' => 0, 'webp' => 0, 'jpg_only' => 0],
            'cache' => ['hits' => 0, 'misses' => 0]
        ],
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
        
        // Merge airports
        foreach ($dailyData['airports'] ?? [] as $airportId => $airportData) {
            if (!isset($weeklyData['airports'][$airportId])) {
                $weeklyData['airports'][$airportId] = ['page_views' => 0, 'weather_requests' => 0];
            }
            $weeklyData['airports'][$airportId]['page_views'] += $airportData['page_views'] ?? 0;
            $weeklyData['airports'][$airportId]['weather_requests'] += $airportData['weather_requests'] ?? 0;
        }
        
        // Merge webcams
        foreach ($dailyData['webcams'] ?? [] as $webcamKey => $webcamData) {
            if (!isset($weeklyData['webcams'][$webcamKey])) {
                $weeklyData['webcams'][$webcamKey] = [
                    'by_format' => ['jpg' => 0, 'webp' => 0, 'avif' => 0],
                    'by_size' => ['thumb' => 0, 'small' => 0, 'medium' => 0, 'large' => 0, 'primary' => 0, 'full' => 0]
                ];
            }
            foreach ($webcamData['by_format'] ?? [] as $fmt => $count) {
                $weeklyData['webcams'][$webcamKey]['by_format'][$fmt] += $count;
            }
            foreach ($webcamData['by_size'] ?? [] as $sz => $count) {
                $weeklyData['webcams'][$webcamKey]['by_size'][$sz] += $count;
            }
        }
        
        // Merge global
        $global = $dailyData['global'] ?? [];
        $weeklyData['global']['page_views'] += $global['page_views'] ?? 0;
        $weeklyData['global']['weather_requests'] += $global['weather_requests'] ?? 0;
        $weeklyData['global']['webcam_serves'] += $global['webcam_serves'] ?? 0;
        
        foreach ($global['format_served'] ?? [] as $fmt => $count) {
            $weeklyData['global']['format_served'][$fmt] += $count;
        }
        foreach ($global['size_served'] ?? [] as $sz => $count) {
            $weeklyData['global']['size_served'][$sz] += $count;
        }
        foreach ($global['browser_support'] ?? [] as $type => $count) {
            $weeklyData['global']['browser_support'][$type] += $count;
        }
        $weeklyData['global']['cache']['hits'] += $global['cache']['hits'] ?? 0;
        $weeklyData['global']['cache']['misses'] += $global['cache']['misses'] ?? 0;
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
 * Get aggregated metrics for a rolling time period
 * 
 * Reads hourly and daily buckets to build aggregated metrics.
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
        'global' => [
            'page_views' => 0,
            'weather_requests' => 0,
            'webcam_serves' => 0,
            'format_served' => ['jpg' => 0, 'webp' => 0, 'avif' => 0],
            'size_served' => ['thumb' => 0, 'small' => 0, 'medium' => 0, 'large' => 0, 'primary' => 0, 'full' => 0],
            'browser_support' => ['avif' => 0, 'webp' => 0, 'jpg_only' => 0],
            'cache' => ['hits' => 0, 'misses' => 0]
        ],
        'generated_at' => $now
    ];
    
    // Read daily files for complete days
    for ($d = 1; $d <= $days; $d++) {
        $dateId = gmdate('Y-m-d', $now - ($d * 86400));
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
        
        // Merge airports
        foreach ($dailyData['airports'] ?? [] as $airportId => $airportData) {
            if (!isset($result['airports'][$airportId])) {
                $result['airports'][$airportId] = ['page_views' => 0, 'weather_requests' => 0];
            }
            $result['airports'][$airportId]['page_views'] += $airportData['page_views'] ?? 0;
            $result['airports'][$airportId]['weather_requests'] += $airportData['weather_requests'] ?? 0;
        }
        
        // Merge webcams
        foreach ($dailyData['webcams'] ?? [] as $webcamKey => $webcamData) {
            if (!isset($result['webcams'][$webcamKey])) {
                $result['webcams'][$webcamKey] = [
                    'by_format' => ['jpg' => 0, 'webp' => 0, 'avif' => 0],
                    'by_size' => ['thumb' => 0, 'small' => 0, 'medium' => 0, 'large' => 0, 'primary' => 0, 'full' => 0]
                ];
            }
            foreach ($webcamData['by_format'] ?? [] as $fmt => $count) {
                $result['webcams'][$webcamKey]['by_format'][$fmt] += $count;
            }
            foreach ($webcamData['by_size'] ?? [] as $sz => $count) {
                $result['webcams'][$webcamKey]['by_size'][$sz] += $count;
            }
        }
        
        // Merge global
        $global = $dailyData['global'] ?? [];
        $result['global']['page_views'] += $global['page_views'] ?? 0;
        $result['global']['weather_requests'] += $global['weather_requests'] ?? 0;
        $result['global']['webcam_serves'] += $global['webcam_serves'] ?? 0;
        
        foreach ($global['format_served'] ?? [] as $fmt => $count) {
            $result['global']['format_served'][$fmt] += $count;
        }
        foreach ($global['size_served'] ?? [] as $sz => $count) {
            $result['global']['size_served'][$sz] += $count;
        }
        foreach ($global['browser_support'] ?? [] as $type => $count) {
            $result['global']['browser_support'][$type] += $count;
        }
        $result['global']['cache']['hits'] += $global['cache']['hits'] ?? 0;
        $result['global']['cache']['misses'] += $global['cache']['misses'] ?? 0;
    }
    
    // Add current day's hourly data
    $todayHours = (int)gmdate('H') + 1; // Hours elapsed today (1-24)
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
        
        // Merge using same logic as daily
        foreach ($hourData['airports'] ?? [] as $airportId => $airportData) {
            if (!isset($result['airports'][$airportId])) {
                $result['airports'][$airportId] = ['page_views' => 0, 'weather_requests' => 0];
            }
            $result['airports'][$airportId]['page_views'] += $airportData['page_views'] ?? 0;
            $result['airports'][$airportId]['weather_requests'] += $airportData['weather_requests'] ?? 0;
        }
        
        foreach ($hourData['webcams'] ?? [] as $webcamKey => $webcamData) {
            if (!isset($result['webcams'][$webcamKey])) {
                $result['webcams'][$webcamKey] = [
                    'by_format' => ['jpg' => 0, 'webp' => 0, 'avif' => 0],
                    'by_size' => ['thumb' => 0, 'small' => 0, 'medium' => 0, 'large' => 0, 'primary' => 0, 'full' => 0]
                ];
            }
            foreach ($webcamData['by_format'] ?? [] as $fmt => $count) {
                $result['webcams'][$webcamKey]['by_format'][$fmt] += $count;
            }
            foreach ($webcamData['by_size'] ?? [] as $sz => $count) {
                $result['webcams'][$webcamKey]['by_size'][$sz] += $count;
            }
        }
        
        $global = $hourData['global'] ?? [];
        $result['global']['page_views'] += $global['page_views'] ?? 0;
        $result['global']['weather_requests'] += $global['weather_requests'] ?? 0;
        $result['global']['webcam_serves'] += $global['webcam_serves'] ?? 0;
        
        foreach ($global['format_served'] ?? [] as $fmt => $count) {
            $result['global']['format_served'][$fmt] += $count;
        }
        foreach ($global['size_served'] ?? [] as $sz => $count) {
            $result['global']['size_served'][$sz] += $count;
        }
        foreach ($global['browser_support'] ?? [] as $type => $count) {
            $result['global']['browser_support'][$type] += $count;
        }
        $result['global']['cache']['hits'] += $global['cache']['hits'] ?? 0;
        $result['global']['cache']['misses'] += $global['cache']['misses'] ?? 0;
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
 * @return array Hourly metrics
 */
function metrics_get_current_hour(): array {
    $result = [
        'airports' => [],
        'webcams' => [],
        'global' => [
            'page_views' => 0,
            'weather_requests' => 0,
            'webcam_serves' => 0
        ]
    ];
    
    // Get current APCu counters (not yet flushed)
    $counters = metrics_get_all();
    foreach ($counters as $key => $value) {
        if (preg_match('/^airport_([a-z0-9]+)_views$/', $key, $m)) {
            $airportId = $m[1];
            if (!isset($result['airports'][$airportId])) {
                $result['airports'][$airportId] = ['page_views' => 0, 'weather_requests' => 0];
            }
            $result['airports'][$airportId]['page_views'] += $value;
            $result['global']['page_views'] += $value;
        } elseif (preg_match('/^airport_([a-z0-9]+)_weather$/', $key, $m)) {
            $airportId = $m[1];
            if (!isset($result['airports'][$airportId])) {
                $result['airports'][$airportId] = ['page_views' => 0, 'weather_requests' => 0];
            }
            $result['airports'][$airportId]['weather_requests'] += $value;
            $result['global']['weather_requests'] += $value;
        } elseif ($key === 'global_webcam_serves') {
            $result['global']['webcam_serves'] += $value;
        }
    }
    
    // Also read current hour's file if it exists (already flushed data)
    $hourId = metrics_get_hour_id();
    $hourFile = getMetricsHourlyPath($hourId);
    if (file_exists($hourFile)) {
        $content = @file_get_contents($hourFile);
        if ($content !== false) {
            $hourData = @json_decode($content, true);
            if (is_array($hourData)) {
                foreach ($hourData['airports'] ?? [] as $airportId => $data) {
                    if (!isset($result['airports'][$airportId])) {
                        $result['airports'][$airportId] = ['page_views' => 0, 'weather_requests' => 0];
                    }
                    $result['airports'][$airportId]['page_views'] += $data['page_views'] ?? 0;
                    $result['airports'][$airportId]['weather_requests'] += $data['weather_requests'] ?? 0;
                }
                $result['global']['page_views'] += $hourData['global']['page_views'] ?? 0;
                $result['global']['weather_requests'] += $hourData['global']['weather_requests'] ?? 0;
                $result['global']['webcam_serves'] += $hourData['global']['webcam_serves'] ?? 0;
            }
        }
    }
    
    return $result;
}

/**
 * Get metrics for today (all hours so far)
 * 
 * @return array Today's metrics
 */
function metrics_get_today(): array {
    $now = time();
    $todayId = gmdate('Y-m-d', $now);
    
    $result = [
        'airports' => [],
        'webcams' => [],
        'global' => [
            'page_views' => 0,
            'weather_requests' => 0,
            'webcam_serves' => 0
        ]
    ];
    
    // First check for a daily file (if aggregation has run)
    $dailyFile = getMetricsDailyPath($todayId);
    if (file_exists($dailyFile)) {
        $content = @file_get_contents($dailyFile);
        if ($content !== false) {
            $data = @json_decode($content, true);
            if (is_array($data)) {
                return $data;
            }
        }
    }
    
    // Otherwise aggregate hourly files for today
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
        
        // Merge airports
        foreach ($hourData['airports'] ?? [] as $airportId => $data) {
            if (!isset($result['airports'][$airportId])) {
                $result['airports'][$airportId] = ['page_views' => 0, 'weather_requests' => 0];
            }
            $result['airports'][$airportId]['page_views'] += $data['page_views'] ?? 0;
            $result['airports'][$airportId]['weather_requests'] += $data['weather_requests'] ?? 0;
        }
        
        // Merge webcams
        foreach ($hourData['webcams'] ?? [] as $webcamKey => $webcamData) {
            if (!isset($result['webcams'][$webcamKey])) {
                $result['webcams'][$webcamKey] = [
                    'by_format' => ['jpg' => 0, 'webp' => 0, 'avif' => 0],
                    'by_size' => ['thumb' => 0, 'small' => 0, 'medium' => 0, 'large' => 0, 'primary' => 0, 'full' => 0]
                ];
            }
            foreach ($webcamData['by_format'] ?? [] as $fmt => $count) {
                $result['webcams'][$webcamKey]['by_format'][$fmt] += $count;
            }
            foreach ($webcamData['by_size'] ?? [] as $sz => $count) {
                $result['webcams'][$webcamKey]['by_size'][$sz] += $count;
            }
        }
        
        // Merge global
        $global = $hourData['global'] ?? [];
        $result['global']['page_views'] += $global['page_views'] ?? 0;
        $result['global']['weather_requests'] += $global['weather_requests'] ?? 0;
        $result['global']['webcam_serves'] += $global['webcam_serves'] ?? 0;
    }
    
    // Add current APCu counters (not yet flushed)
    $currentHourMetrics = metrics_get_current_hour();
    foreach ($currentHourMetrics['airports'] as $airportId => $data) {
        if (!isset($result['airports'][$airportId])) {
            $result['airports'][$airportId] = ['page_views' => 0, 'weather_requests' => 0];
        }
        // Only add APCu data (file data already included above via current hour file)
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
            ],
            'day' => [
                'page_views' => $daily['airports'][$airportId]['page_views'] ?? 0,
                'weather_requests' => $daily['airports'][$airportId]['weather_requests'] ?? 0,
            ],
            'week' => [
                'page_views' => $weekly['airports'][$airportId]['page_views'] ?? 0,
                'weather_requests' => $weekly['airports'][$airportId]['weather_requests'] ?? 0,
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
 * @return int Number of files deleted
 */
function metrics_cleanup(): int {
    $deleted = 0;
    $retentionSeconds = METRICS_RETENTION_DAYS * 86400;
    $cutoff = time() - $retentionSeconds;
    
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
    }
    
    // Clean daily files
    if (is_dir(CACHE_METRICS_DAILY_DIR)) {
        $files = glob(CACHE_METRICS_DAILY_DIR . '/*.json');
        foreach ($files as $file) {
            $basename = basename($file, '.json');
            $timestamp = strtotime($basename . ' 00:00:00 UTC');
            if ($timestamp !== false && $timestamp < $cutoff) {
                if (@unlink($file)) {
                    $deleted++;
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
    }
    
    return $deleted;
}

// =============================================================================
// PROMETHEUS EXPORT
// =============================================================================

/**
 * Get metrics in Prometheus format
 * 
 * @return array Array of metric lines
 */
function metrics_prometheus_export(): array {
    $lines = [];
    $rolling = metrics_get_rolling(1); // Last 24 hours for Prometheus
    
    // Airport page views
    foreach ($rolling['airports'] as $airportId => $data) {
        $lines[] = sprintf('aviationwx_airport_views_total{airport="%s"} %d', $airportId, $data['page_views']);
        $lines[] = sprintf('aviationwx_weather_requests_total{airport="%s"} %d', $airportId, $data['weather_requests']);
    }
    
    // Webcam serves by format and size
    foreach ($rolling['webcams'] as $webcamKey => $data) {
        // Parse webcam key (airport_camindex)
        $parts = explode('_', $webcamKey);
        if (count($parts) >= 2) {
            $airport = $parts[0];
            $cam = $parts[1];
            
            foreach ($data['by_format'] as $format => $count) {
                $lines[] = sprintf('aviationwx_webcam_serves_total{airport="%s",cam="%s",format="%s"} %d', 
                    $airport, $cam, $format, $count);
            }
            
            foreach ($data['by_size'] as $size => $count) {
                $lines[] = sprintf('aviationwx_webcam_serves_by_size_total{airport="%s",cam="%s",size="%s"} %d', 
                    $airport, $cam, $size, $count);
            }
        }
    }
    
    // Global format support
    foreach ($rolling['global']['browser_support'] as $type => $count) {
        $lines[] = sprintf('aviationwx_browser_format_support_total{format="%s"} %d', $type, $count);
    }
    
    // Cache performance
    $lines[] = sprintf('aviationwx_cache_hits_total %d', $rolling['global']['cache']['hits']);
    $lines[] = sprintf('aviationwx_cache_misses_total %d', $rolling['global']['cache']['misses']);
    
    // Global totals
    $lines[] = sprintf('aviationwx_page_views_total %d', $rolling['global']['page_views']);
    $lines[] = sprintf('aviationwx_weather_requests_total %d', $rolling['global']['weather_requests']);
    $lines[] = sprintf('aviationwx_webcam_serves_total %d', $rolling['global']['webcam_serves']);
    
    return $lines;
}

