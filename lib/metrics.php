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
 * - Per-worker spill JSON under cache/metrics/spill/ (shutdown on PHP-FPM workers)
 * - Scheduler merges spills into hourly/*.json via CLI (singleton lock; soft caps)
 * - Hourly, daily, weekly aggregation buckets
 * - 14-day retention with automatic cleanup
 */

require_once __DIR__ . '/cache-paths.php';
require_once __DIR__ . '/constants.php';
require_once __DIR__ . '/internal-http-url.php';
require_once __DIR__ . '/logger.php';
require_once __DIR__ . '/circuit-breaker.php';
require_once __DIR__ . '/metrics-apply-counters.php';

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
 * Determine whether a rate-limited warning should be logged
 * 
 * Uses a small timestamp file in the system temp directory to coordinate
 * rate limiting across PHP processes. This avoids log spam when certain
 * conditions persist.
 * 
 * @param string $reasonKey Short identifier for the specific warning type
 * @param int $intervalSeconds How long to wait between logs (default: 3600 = 1 hour)
 * @return bool True if we should log now, false if within cooldown period
 */
function metrics_should_log_warning(string $reasonKey, int $intervalSeconds = 3600): bool {
    $tmpDir = sys_get_temp_dir();
    
    // If we cannot write to temp dir, fallback to always logging
    // (better to log than silently suppress in safety-critical application)
    if ($tmpDir === '' || !is_writable($tmpDir)) {
        return true;
    }
    
    $filename = rtrim($tmpDir, DIRECTORY_SEPARATOR)
        . DIRECTORY_SEPARATOR
        . 'aviationwx_metrics_warning_' . $reasonKey . '.logstamp';
    
    $now = time();
    
    if (file_exists($filename)) {
        $mtime = @filemtime($filename);
        if ($mtime !== false && ($now - $mtime) < $intervalSeconds) {
            // Last log was within cooldown period; skip logging
            return false;
        }
    }
    
    // Update/create the timestamp file
    // Ignore errors - they should not prevent logging in a safety-critical application
    @touch($filename);
    
    return true;
}

/**
 * Check if APCu is available and log if unavailable
 * 
 * Uses cross-request rate limiting via temp file to avoid log spam
 * (logs once per hour max per warning type, per host) when APCu is
 * not available or not enabled.
 * 
 * @return bool True if APCu is available
 */
function metrics_is_apcu_available(): bool {
    static $apcuAvailable = null;
    
    // Cache result within request to avoid repeated checks
    if ($apcuAvailable !== null) {
        return $apcuAvailable;
    }
    
    if (!function_exists('apcu_fetch')) {
        if (metrics_should_log_warning('apcu_missing')) {
            aviationwx_log('error', 'metrics: APCu not available, metrics collection disabled', [
                'reason' => 'apcu_fetch function not found'
            ], 'app');
        }
        $apcuAvailable = false;
        return false;
    }
    
    // Check if APCu is actually functional (enabled in ini)
    if (!@apcu_enabled()) {
        if (metrics_should_log_warning('apcu_disabled')) {
            aviationwx_log('error', 'metrics: APCu not enabled in PHP configuration', [
                'reason' => 'apcu.enabled is off'
            ], 'app');
        }
        $apcuAvailable = false;
        return false;
    }
    
    $apcuAvailable = true;
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
// STATUS BUNDLE MIRROR (APCu, best-effort cache; telemetry only)
// =============================================================================

/**
 * Try to read a fresh status bundle mirror from APCu.
 *
 * @return array|null Same shape as metrics_get_status_bundle(), or null if miss or stale
 */
function metrics_try_get_status_bundle_mirror(): ?array {
    if (!function_exists('apcu_fetch')) {
        return null;
    }
    $raw = @apcu_fetch(METRICS_STATUS_BUNDLE_MIRROR_APCU_KEY);
    if (!is_array($raw) || !isset($raw['bundle'], $raw['generated_at'], $raw['today_bucket_id'])) {
        return null;
    }
    $now = time();
    if (($now - (int)$raw['generated_at']) > METRICS_STATUS_BUNDLE_MIRROR_TTL_SECONDS) {
        return null;
    }
    if ($raw['today_bucket_id'] !== gmdate('Y-m-d', $now)) {
        return null;
    }
    $bundle = is_array($raw['bundle']) ? $raw['bundle'] : null;
    if ($bundle === null) {
        return null;
    }
    // Invalidate mirrors from before hourly_profile / multiPeriod were embedded
    if (!isset($bundle['hourly_profile']['hours']) || !is_array($bundle['hourly_profile']['hours'])) {
        return null;
    }
    if ((int) ($bundle['hourly_profile']['schema_version'] ?? 0) < METRICS_STATUS_HOURLY_PROFILE_SCHEMA_VERSION) {
        return null;
    }
    if (!array_key_exists('multiPeriod', $bundle) || !is_array($bundle['multiPeriod'])) {
        return null;
    }

    return $bundle;
}

/**
 * Drop the status bundle APCu mirror so the next metrics_get_status_bundle() rebuilds from disk.
 *
 * @return void
 */
function metrics_invalidate_status_bundle_mirror(): void {
    if (!function_exists('apcu_delete')) {
        return;
    }
    @apcu_delete(METRICS_STATUS_BUNDLE_MIRROR_APCU_KEY);
}

/**
 * Store status bundle snapshot in APCu for fast status page reads (telemetry only).
 *
 * @param array $bundle Same shape as metrics_get_status_bundle() return value (includes hourly_profile)
 * @return void
 */
function metrics_store_status_bundle_mirror(array $bundle): void {
    if (!function_exists('apcu_store')) {
        return;
    }
    $now = time();
    $payload = [
        'generated_at' => $now,
        'today_bucket_id' => gmdate('Y-m-d', $now),
        'bundle' => $bundle,
    ];
    $stored = @apcu_store(
        METRICS_STATUS_BUNDLE_MIRROR_APCU_KEY,
        $payload,
        METRICS_STATUS_BUNDLE_MIRROR_TTL_SECONDS
    );
    if ($stored === false && metrics_should_log_warning('metrics_status_bundle_mirror_apcu_store', 3600)) {
        aviationwx_log('warning', 'metrics: APCu status bundle mirror store failed', [], 'app');
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
 * Whether $hourId is a valid UTC calendar hour in metrics format (YYYY-MM-DD-HH).
 *
 * Rejects malformed strings, invalid calendar dates, and hour outside 00--23.
 *
 * @param string $hourId Candidate id (e.g. directory name under the spill root)
 * @return bool True when the string is a real UTC calendar hour in metrics format
 */
function metrics_hour_id_is_valid(string $hourId): bool {
    if (!preg_match('/^(\d{4})-(\d{2})-(\d{2})-(\d{2})$/', $hourId, $m)) {
        return false;
    }

    $year = (int) $m[1];
    $month = (int) $m[2];
    $day = (int) $m[3];
    $hour = (int) $m[4];

    if ($hour > 23) {
        return false;
    }

    return checkdate($month, $day, $year);
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
 * UTC hour window [start, end) for a metrics hour bucket id.
 *
 * @param string $hourId Bucket id from metrics_get_hour_id() (e.g. 2026-05-08-14)
 * @return array{0:int,1:int} Unix timestamps for bucket_start and bucket_end
 *
 * @throws InvalidArgumentException When $hourId is not a valid UTC metrics hour id
 */
function metrics_hour_bucket_bounds_from_hour_id(string $hourId): array {
    if (!preg_match('/^(\d{4})-(\d{2})-(\d{2})-(\d{2})$/', $hourId, $m)) {
        throw new InvalidArgumentException('Invalid UTC metrics hour id: ' . $hourId);
    }

    $year = (int) $m[1];
    $month = (int) $m[2];
    $day = (int) $m[3];
    $hour = (int) $m[4];

    if ($hour > 23 || !checkdate($month, $day, $year)) {
        throw new InvalidArgumentException('Invalid UTC metrics hour id: ' . $hourId);
    }

    $ts = gmmktime($hour, 0, 0, $month, $day, $year);
    if ($ts === false) {
        throw new InvalidArgumentException('Invalid UTC metrics hour id (conversion): ' . $hourId);
    }

    return [$ts, $ts + 3600];
}

/**
 * New empty hourly metrics bucket for disk (hourly JSON file).
 *
 * @param string $hourId Hour identifier (metrics_get_hour_id format)
 *
 * @throws InvalidArgumentException When $hourId is not a valid UTC metrics hour id
 *
 * @return array Hourly bucket structure
 */
function metrics_new_empty_hour_bucket(string $hourId): array {
    [$start, $end] = metrics_hour_bucket_bounds_from_hour_id($hourId);

    return [
        'bucket_type' => 'hourly',
        'bucket_id' => $hourId,
        'bucket_start' => $start,
        'bucket_end' => $end,
        'airports' => [],
        'webcams' => [],
        'webcam_uploads' => [],
        'webcam_images' => [],
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
            'size_served' => [],
            'browser_support' => ['webp' => 0, 'jpg_only' => 0],
            'cache' => ['hits' => 0, 'misses' => 0],
        ],
    ];
}

/**
 * Overlay partial or legacy hourly JSON onto a canonical empty bucket so merge paths never fatals on missing nested keys.
 *
 * @param array<string, mixed> $hourData Loaded or partial hour bucket (replaced in place)
 * @param string               $hourId   Canonical UTC hour id for this merge (directory name)
 *
 * @throws InvalidArgumentException When $hourId is not a valid UTC metrics hour id
 *
 * @return void
 */
function metrics_normalize_hour_bucket_for_merge(array &$hourData, string $hourId): void {
    $base = metrics_new_empty_hour_bucket($hourId);
    $merged = array_replace_recursive($base, $hourData);
    [$start, $end] = metrics_hour_bucket_bounds_from_hour_id($hourId);
    $merged['bucket_type'] = 'hourly';
    $merged['bucket_id'] = $hourId;
    $merged['bucket_start'] = $start;
    $merged['bucket_end'] = $end;
    $hourData = $merged;
}

/**
 * Ensure hourly bucket data has all nested keys (legacy or partial JSON).
 *
 * Prefer {@see metrics_normalize_hour_bucket_for_merge()} when the UTC hour id is known (spill aggregator).
 *
 * @param array $hourData Hour bucket (modified in place)
 * @return void
 */
function metrics_fill_hour_data_defaults(array &$hourData): void {
    if (!isset($hourData['webcam_images'])) {
        $hourData['webcam_images'] = [];
    }
    if (!isset($hourData['global']['webcam_images_verified'])) {
        $hourData['global']['webcam_images_verified'] = 0;
    }
    if (!isset($hourData['global']['webcam_images_rejected'])) {
        $hourData['global']['webcam_images_rejected'] = 0;
    }

    if (!isset($hourData['global']['webcam_requests'])) {
        $hourData['global']['webcam_requests'] = 0;
    }

    if (!isset($hourData['global']['tiles_served'])) {
        $hourData['global']['tiles_served'] = 0;
    }
    if (!isset($hourData['global']['tiles_by_source'])) {
        $hourData['global']['tiles_by_source'] = ['openweathermap' => 0, 'rainviewer' => 0];
    }
}

/**
 * Flush variant health APCu counters to cache via HTTP (PHP-FPM context).
 *
 * APCu is process-isolated: CLI cannot see counters incremented by PHP-FPM workers.
 * Call this from the scheduler instead of variant_health_flush() directly.
 *
 * @return bool True on success
 */
function variant_health_flush_via_http(): bool {
    $ch = curl_init();

    $url = getInternalApacheBaseUrl() . '/health/variant-health-flush.php';

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
    $curlError = curl_error($ch);
    curl_close($ch);

    $data = is_string($response) ? json_decode($response, true) : null;
    if ($httpCode === 200 && is_array($data) && ($data['success'] ?? false) === true) {
        return true;
    }

    static $lastLogTime = 0;
    $now = time();
    $responseResults = is_array($data) && isset($data['results']) && is_array($data['results'])
        ? $data['results']
        : [];
    $endpointErr = $responseResults['flush_endpoint_error'] ?? null;
    $exceptionErr = $responseResults['error'] ?? null;
    $variantFlushOk = $responseResults['variant_health_flush'] ?? null;

    if (($now - $lastLogTime) >= 300) {
        $hasDiag = ($endpointErr !== null && $endpointErr !== '')
            || ($exceptionErr !== null && $exceptionErr !== '');
        $level = $hasDiag ? 'error' : 'warning';
        aviationwx_log($level, 'variant health: HTTP flush failed', [
            'http_code' => $httpCode,
            'curl_error' => $curlError ?: 'unknown',
            'flush_endpoint_error' => $endpointErr,
            'exception_message' => $exceptionErr,
            'variant_health_flush' => $variantFlushOk,
            'response' => substr($response ?: '', 0, 200),
            'url' => $url,
        ], 'app');
        $lastLogTime = $now;
    }

    return false;
}

/**
 * Rebuild status bundle from disk and store APCu mirror (PHP-FPM context).
 *
 * Used by the scheduler after spill merges so status page reads stay hot without waiting for TTL.
 *
 * @return bool True on HTTP 200 with success
 */
function metrics_status_bundle_mirror_refresh_via_http(): bool {
    $ch = curl_init();

    $url = getInternalApacheBaseUrl() . '/health/status-bundle-mirror-refresh.php';

    curl_setopt_array($ch, [
        CURLOPT_URL => $url,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 15,
        CURLOPT_CONNECTTIMEOUT => 2,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_HTTPHEADER => ['X-Scheduler-Request: 1'],
    ]);

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlError = curl_error($ch);
    curl_close($ch);

    $data = is_string($response) ? json_decode($response, true) : null;
    if ($httpCode === 200 && is_array($data) && ($data['success'] ?? false) === true) {
        return true;
    }

    static $lastLogTime = 0;
    $now = time();
    if (($now - $lastLogTime) >= 300) {
        aviationwx_log('warning', 'metrics: status bundle mirror HTTP refresh failed', [
            'http_code' => $httpCode,
            'curl_error' => $curlError ?: 'unknown',
            'response' => substr($response ?: '', 0, 200),
            'url' => $url,
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
 * @param array|null $liveHourOverride If set, use this instead of calling metrics_get_current_hour()
 *        for today's live portion (keeps rolling aligned with a shared snapshot).
 * @param int|null $atTimestamp Unix time for period bounds and file reads; default now.
 *        Use one shared value with metrics_get_today() and metrics_get_current_hour() when aggregating together.
 * @return array Aggregated metrics
 */
function metrics_get_rolling(int $days = 7, ?array $liveHourOverride = null, ?int $atTimestamp = null): array {
    $now = $atTimestamp ?? time();
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
        if (metrics_should_log_warning('missing_daily_files', 300)) { // 5 minutes
            aviationwx_log('warning', 'metrics: missing daily files in rolling window', [
                'missing_days' => $missingDays,
                'requested_days' => $days,
                'files_found' => $days - count($missingDays)
            ], 'app');
        }
    }
    
    // Current UTC day: committed hours from files, then live current hour (file + APCu)
    $todayId = gmdate('Y-m-d', $now);
    $currentHour = (int)gmdate('H', $now);
    for ($h = 0; $h < $currentHour; $h++) {
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

    $liveHour = $liveHourOverride ?? metrics_get_current_hour($now);
    metrics_merge_airports($result['airports'], $liveHour['airports'] ?? []);
    metrics_merge_webcams($result['webcams'], $liveHour['webcams'] ?? []);
    metrics_merge_global($result['global'], $liveHour['global'] ?? []);

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
 * @param int|null $atTimestamp Unix time for period bounds and for the live hour bucket; default now.
 *        Use one value for the whole aggregation so the current hour matches historical hour file ids.
 * @return array Aggregated metrics with period_hours instead of period_days
 */
function metrics_get_rolling_hours(int $hours = 24, ?int $atTimestamp = null): array {
    $now = $atTimestamp ?? time();
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
    $currentHourId = metrics_get_hour_id($now);

    for ($h = 0; $h < $hours; $h++) {
        $hourTimestamp = $now - ($h * 3600);
        $hourId = gmdate('Y-m-d-H', $hourTimestamp);

        if ($hourId === $currentHourId) {
            $live = metrics_get_current_hour($now);
            metrics_merge_airports($result['airports'], $live['airports'] ?? []);
            metrics_merge_webcams($result['webcams'], $live['webcams'] ?? []);
            metrics_merge_global($result['global'], $live['global'] ?? []);
            continue;
        }

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
 * @param int|null $atTimestamp Unix time for bucket id and hourly file selection; default now.
 *        Pass the same value as metrics_get_today() / metrics_get_rolling() for aligned boundaries.
 * @return array Hourly metrics
 */
function metrics_get_current_hour(?int $atTimestamp = null): array {
    $now = $atTimestamp ?? time();
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
 * Merges committed hourly files for UTC hours [0, currentHour) plus the live current hour
 * (hourly file + unflushed APCu) so totals match /hour on status.
 *
 * @param array|null $liveHourOverride If set, use this instead of metrics_get_current_hour() for the live merge.
 * @param int|null $atTimestamp Unix time for today bucket and prior-hour file reads; default now.
 *        Use one shared value with metrics_get_rolling() and metrics_get_current_hour() when aggregating together.
 * @return array Today's metrics
 */
function metrics_get_today(?array $liveHourOverride = null, ?int $atTimestamp = null): array {
    $now = $atTimestamp ?? time();
    $todayId = gmdate('Y-m-d', $now);

    $result = [
        'bucket_type' => 'today',
        'bucket_id' => $todayId,
        'airports' => [],
        'webcams' => [],
        'global' => metrics_get_empty_global(),
        'generated_at' => $now
    ];

    $currentHour = (int)gmdate('H', $now);
    for ($h = 0; $h < $currentHour; $h++) {
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

    $liveHour = $liveHourOverride ?? metrics_get_current_hour($now);
    metrics_merge_airports($result['airports'], $liveHour['airports'] ?? []);
    metrics_merge_webcams($result['webcams'], $liveHour['webcams'] ?? []);
    metrics_merge_global($result['global'], $liveHour['global'] ?? []);

    return $result;
}

/**
 * Empty hourly profile shape (matches metrics_get_status_hourly_profile() keys).
 *
 * @param int|null $generatedAt Unix time for generated_at (default now)
 * @return array{generated_at:int,current_hour_id:string,window_completed_hours:int,schema_version:int,hours:array}
 */
function metrics_get_empty_hourly_profile(?int $generatedAt = null): array {
    $t = $generatedAt ?? time();

    return [
        'generated_at' => $t,
        'current_hour_id' => '',
        'window_completed_hours' => (int) METRICS_STATUS_HOURLY_PROFILE_COMPLETED_HOURS,
        'schema_version' => METRICS_STATUS_HOURLY_PROFILE_SCHEMA_VERSION,
        'hours' => [],
    ];
}

/**
 * Empty status bundle shape for cold cache when web requests skip synchronous aggregation.
 *
 * @return array{
 *   rolling7: array,
 *   rolling1: array,
 *   today: array,
 *   hourly_profile: array,
 *   multiPeriod: array
 * }
 */
function metrics_get_empty_status_bundle(): array {
    $now = time();
    $todayId = gmdate('Y-m-d', $now);

    return [
        'rolling7' => [
            'period_days' => 7,
            'period_start' => $now - (7 * 86400),
            'period_end' => $now,
            'airports' => [],
            'webcams' => [],
            'global' => metrics_get_empty_global(),
            'generated_at' => $now,
        ],
        'rolling1' => [
            'period_days' => 1,
            'period_start' => $now - 86400,
            'period_end' => $now,
            'airports' => [],
            'webcams' => [],
            'global' => metrics_get_empty_global(),
            'generated_at' => $now,
        ],
        'today' => [
            'bucket_type' => 'today',
            'bucket_id' => $todayId,
            'airports' => [],
            'webcams' => [],
            'global' => metrics_get_empty_global(),
            'generated_at' => $now,
        ],
        'hourly_profile' => metrics_get_empty_hourly_profile($now),
        'multiPeriod' => [],
    ];
}

/**
 * Get status page metrics bundle - reads metrics files and attaches hourly_profile
 *
 * Returns rolling7, rolling1, today from disk aggregation, plus sparse UTC hourly_profile (completed
 * hours from disk, current hour file + APCu). Uses one Unix snapshot for live hour merges.
 * `multiPeriod` is built from those same merged `$today` / `$rolling7` / `$liveHour` structures (no second file pass).
 * An APCu mirror may short-circuit file reads when fresh.
 *
 * @return array {
 *   rolling7: array,
 *   rolling1: array,
 *   today: array,
 *   hourly_profile: array,
 *   multiPeriod: array
 * }
 */
function metrics_get_status_bundle(): array {
    $mirrored = metrics_try_get_status_bundle_mirror();
    if ($mirrored !== null) {
        return $mirrored;
    }

    $now = time();
    $todayId = gmdate('Y-m-d', $now);
    $currentHour = (int)gmdate('H', $now);

    $rolling7 = [
        'period_days' => 7,
        'period_start' => $now - (7 * 86400),
        'period_end' => $now,
        'airports' => [],
        'webcams' => [],
        'global' => metrics_get_empty_global(),
        'generated_at' => $now
    ];
    $rolling1 = [
        'period_days' => 1,
        'period_start' => $now - 86400,
        'period_end' => $now,
        'airports' => [],
        'webcams' => [],
        'global' => metrics_get_empty_global(),
        'generated_at' => $now
    ];
    $today = [
        'bucket_type' => 'today',
        'bucket_id' => $todayId,
        'airports' => [],
        'webcams' => [],
        'global' => metrics_get_empty_global(),
        'generated_at' => $now
    ];

    /** @var array<string,array<string,int>> $hourSparseDiskCache */
    $hourSparseDiskCache = [];

    for ($d = 1; $d <= 7; $d++) {
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
        metrics_merge_airports($rolling7['airports'], $dailyData['airports'] ?? []);
        metrics_merge_webcams($rolling7['webcams'], $dailyData['webcams'] ?? []);
        metrics_merge_global($rolling7['global'], $dailyData['global'] ?? []);
        if ($d === 1) {
            metrics_merge_airports($rolling1['airports'], $dailyData['airports'] ?? []);
            metrics_merge_webcams($rolling1['webcams'], $dailyData['webcams'] ?? []);
            metrics_merge_global($rolling1['global'], $dailyData['global'] ?? []);
        }
    }

    for ($h = 0; $h < $currentHour; $h++) {
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
        $hourSparseDiskCache[$hourId] = metrics_sparse_page_views_from_hour_aggregate($hourData);
        metrics_merge_airports($rolling7['airports'], $hourData['airports'] ?? []);
        metrics_merge_webcams($rolling7['webcams'], $hourData['webcams'] ?? []);
        metrics_merge_global($rolling7['global'], $hourData['global'] ?? []);
        metrics_merge_airports($rolling1['airports'], $hourData['airports'] ?? []);
        metrics_merge_webcams($rolling1['webcams'], $hourData['webcams'] ?? []);
        metrics_merge_global($rolling1['global'], $hourData['global'] ?? []);
        metrics_merge_airports($today['airports'], $hourData['airports'] ?? []);
        metrics_merge_webcams($today['webcams'], $hourData['webcams'] ?? []);
        metrics_merge_global($today['global'], $hourData['global'] ?? []);
    }

    $liveHour = metrics_get_current_hour($now);
    metrics_merge_airports($rolling7['airports'], $liveHour['airports'] ?? []);
    metrics_merge_webcams($rolling7['webcams'], $liveHour['webcams'] ?? []);
    metrics_merge_global($rolling7['global'], $liveHour['global'] ?? []);
    metrics_merge_airports($rolling1['airports'], $liveHour['airports'] ?? []);
    metrics_merge_webcams($rolling1['webcams'], $liveHour['webcams'] ?? []);
    metrics_merge_global($rolling1['global'], $liveHour['global'] ?? []);
    metrics_merge_airports($today['airports'], $liveHour['airports'] ?? []);
    metrics_merge_webcams($today['webcams'], $liveHour['webcams'] ?? []);
    metrics_merge_global($today['global'], $liveHour['global'] ?? []);

    // Reuse aggregates merged above; avoids re-reading the same daily/hour files for multiPeriod.
    $multiPeriod = metrics_build_multi_period_from_periods($liveHour, $today, $rolling7);

    $bundle = [
        'rolling7' => $rolling7,
        'rolling1' => $rolling1,
        'today' => $today,
        'hourly_profile' => metrics_get_status_hourly_profile($now, $liveHour, $hourSparseDiskCache),
        'multiPeriod' => $multiPeriod,
    ];
    metrics_store_status_bundle_mirror($bundle);

    return $bundle;
}

/**
 * Build multi-period per-airport metrics from hour / today / weekly aggregates.
 *
 * Single implementation shared by the status page and tests. Callers should pass aggregates
 * produced with the same Unix snapshot across metrics_get_current_hour(), metrics_get_today(),
 * and metrics_get_rolling() when combining hour, today, and rolling windows.
 *
 * @param array $hourly Current hour snapshot (metrics_get_current_hour() output shape)
 * @param array $daily Today bucket (files for prior UTC hours plus live hour merge)
 * @param array $weekly Rolling calendar window (metrics_get_rolling() output shape)
 * @return array Multi-period metrics indexed by airport ID
 */
function metrics_build_multi_period_from_periods(array $hourly, array $daily, array $weekly): array {
    $result = [];
    $allAirports = array_unique(array_merge(
        array_keys($hourly['airports'] ?? []),
        array_keys($daily['airports'] ?? []),
        array_keys($weekly['airports'] ?? [])
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
            'webcams' => [],
        ];
        foreach ($weekly['webcams'] ?? [] as $webcamKey => $webcamData) {
            if (strpos($webcamKey, $airportId . '_') === 0) {
                $camIndex = (int)substr($webcamKey, strlen($airportId) + 1);
                $result[$airportId]['webcams'][$camIndex] = $webcamData;
            }
        }
    }

    return $result;
}

/**
 * Build multi-period metrics for the status page (same result as metrics_get_multi_period() when bundle is fresh).
 *
 * When {@see metrics_get_status_bundle()} populated multiPeriod, returns it so hour/day/week stay aligned
 * with hourly_profile.generated_at and the same live hour merge.
 *
 * @param array $_bundle Ignored unless it contains multiPeriod (pass metrics_get_status_bundle() output)
 * @return array Multi-period metrics indexed by airport
 */
function metrics_build_multi_period_from_bundle(array $_bundle): array {
    if (array_key_exists('multiPeriod', $_bundle) && is_array($_bundle['multiPeriod'])) {
        return $_bundle['multiPeriod'];
    }

    return metrics_get_multi_period();
}

/**
 * Get multi-period metrics for all airports (hour, day, 7-day)
 *
 * Returns metrics organized by airport with all three time periods.
 * Uses one Unix snapshot for metrics_get_current_hour(), metrics_get_today(), and metrics_get_rolling()
 * so UTC day/hour boundaries and live merges stay aligned within the request.
 *
 * @return array Multi-period metrics indexed by airport
 */
function metrics_get_multi_period(): array {
    $snapshot = time();
    $live = metrics_get_current_hour($snapshot);

    return metrics_build_multi_period_from_periods(
        $live,
        metrics_get_today($live, $snapshot),
        metrics_get_rolling(METRICS_STATUS_PAGE_DAYS, $live, $snapshot)
    );
}

/**
 * Sparse airport_id => page_views for status hourly profile JSON (non-zero only).
 *
 * @param array $hourAggregate Hour bucket with optional `airports` map
 * @return array<string,int> Lowercase airport id => page views
 */
function metrics_sparse_page_views_from_hour_aggregate(array $hourAggregate): array {
    $out = [];
    foreach ($hourAggregate['airports'] ?? [] as $airportId => $row) {
        if (!is_array($row)) {
            continue;
        }
        $pv = (int) ($row['page_views'] ?? 0);
        if ($pv > 0) {
            $out[strtolower((string) $airportId)] = $pv;
        }
    }

    return $out;
}

/**
 * UTC hourly profile for status page local-calendar presentation.
 *
 * Includes METRICS_STATUS_HOURLY_PROFILE_COMPLETED_HOURS completed UTC hours read from disk only,
 * plus the current UTC hour from {@see metrics_get_current_hour()} (hourly file + APCu) so the
 * partial hour matches `/hour` and today UTC totals.
 *
 * @param int|null                       $atTimestamp        Single Unix snapshot for ids (default now)
 * @param array|null                     $liveHourOverride   Output of metrics_get_current_hour($atTimestamp) when
 *                                                          already computed (avoids duplicate APCu merge when building bundle)
 * @param array<string,array<string,int>> $diskSparseByHourId Optional hour_id => sparse page_views map for
 *                                                          hours already read from disk (e.g. today's hours during bundle build).
 * @return array{
 *   generated_at:int,
 *   current_hour_id:string,
 *   window_completed_hours:int,
 *   schema_version:int,
 *   hours:list<array{hour_id:string,complete:bool,views:array<string,int>}>
 * }
 */
function metrics_get_status_hourly_profile(
    ?int $atTimestamp = null,
    ?array $liveHourOverride = null,
    array $diskSparseByHourId = []
): array {
    $now = $atTimestamp ?? time();
    $completed = (int) METRICS_STATUS_HOURLY_PROFILE_COMPLETED_HOURS;
    $live = $liveHourOverride ?? metrics_get_current_hour($now);
    $currentHourId = metrics_get_hour_id($now);

    $hours = [];
    for ($offset = $completed; $offset >= 1; $offset--) {
        $t = $now - ($offset * 3600);
        $hourId = metrics_get_hour_id($t);
        $views = [];
        if (array_key_exists($hourId, $diskSparseByHourId)) {
            $views = $diskSparseByHourId[$hourId];
        } else {
            $path = getMetricsHourlyPath($hourId);
            if (file_exists($path)) {
                $content = @file_get_contents($path);
                if ($content !== false) {
                    $hourData = @json_decode($content, true);
                    if (is_array($hourData)) {
                        $views = metrics_sparse_page_views_from_hour_aggregate($hourData);
                    }
                }
            }
        }
        $hours[] = [
            'hour_id' => $hourId,
            'complete' => true,
            'views' => $views,
        ];
    }

    $hours[] = [
        'hour_id' => $currentHourId,
        'complete' => false,
        'views' => metrics_sparse_page_views_from_hour_aggregate($live),
    ];

    return [
        'generated_at' => $now,
        'current_hour_id' => $currentHourId,
        'window_completed_hours' => $completed,
        'schema_version' => METRICS_STATUS_HOURLY_PROFILE_SCHEMA_VERSION,
        'hours' => $hours,
    ];
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
            $health['errors'][] = sprintf(
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

// =============================================================================
// METRICS SPILL (per-worker snapshot for aggregator merge)
// =============================================================================

/**
 * Persist pending APCu counters to a spill file and reset counters on success.
 *
 * Uses a unique filename per call so successive request shutdowns do not overwrite unconsumed shards.
 * Each file holds counters accumulated since the last successful spill for this worker (delta shard).
 *
 * Spill path: CACHE_METRICS_SPILL_DIR/{hourId}/{pid}_{uniq}.json (see cache-paths.php).
 *
 * Never throws: path generation uses CSPRNG (may throw under extreme failure); shutdown hooks must stay safe.
 *
 * @return bool True if nothing to write, or spill written and APCu reset
 */
function metrics_write_spill_snapshot_and_reset_counters(): bool {
    if (!metrics_is_apcu_available()) {
        return true;
    }

    $counters = metrics_get_all();
    if ($counters === []) {
        return true;
    }

    try {
        $hourId = metrics_get_hour_id();
        $pid = getmypid();
        $target = getMetricsSpillSnapshotPath($hourId, $pid);
        $dir = dirname($target);
        if (!ensureCacheDir($dir)) {
            aviationwx_log('warning', 'metrics spill: could not create spill directory', ['dir' => $dir], 'app');
            return false;
        }

        $payload = [
            'schema_version' => METRICS_SPILL_FILE_SCHEMA_VERSION,
            'generated_at' => time(),
            'hour_id' => $hourId,
            'pid' => $pid,
            'counters' => $counters,
        ];

        $json = json_encode($payload);
        if ($json === false) {
            aviationwx_log('warning', 'metrics spill: json_encode failed', ['error' => json_last_error_msg()], 'app');
            return false;
        }

        $tmpFile = $target . '.tmp.' . $pid;
        $written = @file_put_contents($tmpFile, $json, LOCK_EX);
        if ($written === false) {
            @unlink($tmpFile);
            aviationwx_log('warning', 'metrics spill: temp write failed', ['path' => $tmpFile], 'app');
            return false;
        }

        if (!@rename($tmpFile, $target)) {
            @unlink($tmpFile);
            aviationwx_log('warning', 'metrics spill: rename failed', ['path' => $target], 'app');
            return false;
        }

        metrics_reset_all();

        return true;
    } catch (Throwable $e) {
        aviationwx_log('warning', 'metrics spill: unexpected failure', [
            'error' => $e->getMessage(),
        ], 'app');
        return false;
    }
}

/**
 * Shutdown hook: spill metrics when running under web SAPI (Apache worker).
 *
 * Runs after each HTTP request ends (PHP lifecycle), not only when an FPM worker process exits.
 *
 * @return void
 */
function metrics_shutdown_spill_if_needed(): void {
    metrics_write_spill_snapshot_and_reset_counters();
}

if (PHP_SAPI !== 'cli') {
    register_shutdown_function(static function (): void {
        metrics_shutdown_spill_if_needed();
    });
}

