<?php
/**
 * Cached Data Loader
 * 
 * Provides a consistent pattern for loading data with APCu + optional file persistence.
 * This is the standard pattern for any data that should be:
 * - Fast (APCu cache)
 * - Survive restarts (optional file persistence)
 * - Fresh enough (configurable TTL)
 * 
 * Pattern: APCu first → File second (if provided) → Compute last
 * 
 * Usage:
 * ```php
 * $data = getCachedData(
 *     fn() => expensiveComputation(),
 *     'my_cache_key',
 *     '/path/to/cache.json',  // Optional file persistence
 *     60                       // TTL in seconds
 * );
 * ```
 */

require_once __DIR__ . '/logger.php';

/**
 * Persist a cache payload to APCu and an optional JSON envelope file (atomic write).
 *
 * @param string $apcu_key Cache key without the cached_ prefix
 * @param string|null $file_path Optional path for on-disk envelope (same shape as getCachedData)
 * @param mixed $data Payload to store
 * @param int $ttl APCu entry TTL in seconds
 * @return void
 */
function persistCachedDataEnvelope(string $apcu_key, ?string $file_path, mixed $data, int $ttl): void {
    $fullKey = 'cached_' . $apcu_key;
    if (function_exists('apcu_store')) {
        @apcu_store($fullKey, $data, $ttl);
    }
    if ($file_path === null) {
        return;
    }
    $cacheDir = dirname($file_path);
    if (!is_dir($cacheDir)) {
        @mkdir($cacheDir, 0755, true);
    }
    $fileData = [
        'cached_at' => time(),
        'ttl' => $ttl,
        'key' => $apcu_key,
        'data' => $data,
    ];
    $tmpFile = $file_path . '.tmp.' . getmypid();
    $written = @file_put_contents($tmpFile, json_encode($fileData, JSON_PRETTY_PRINT), LOCK_EX);
    if ($written !== false) {
        @rename($tmpFile, $file_path);
    } else {
        @unlink($tmpFile);
    }
}

/**
 * Get cached data with APCu + optional file persistence
 * 
 * Implements a three-tier caching strategy:
 * 1. APCu cache (fastest - microseconds)
 * 2. File cache (fast - milliseconds, survives restarts)
 * 3. Compute fresh data (slowest - varies)
 * 
 * @param callable $computeFunc Function to compute fresh data
 * @param string $apcu_key APCu cache key (prefixed with 'cached_' automatically)
 * @param string|null $file_path Optional file path for persistence (survives restarts)
 * @param int $ttl Cache TTL in seconds (default: 60)
 * @return mixed Fresh or cached payload from tier 1–3
 */
function getCachedData(callable $computeFunc, string $apcu_key, ?string $file_path = null, int $ttl = 60) {
    $fullKey = 'cached_' . $apcu_key;
    
    // Tier 1: Try APCu first (fastest - microseconds)
    if (function_exists('apcu_fetch')) {
        $cached = @apcu_fetch($fullKey, $success);
        if ($success) {
            return $cached;
        }
    }
    
    // Tier 2: Try file cache if provided (fast - milliseconds, survives restarts)
    if ($file_path !== null && file_exists($file_path)) {
        $content = @file_get_contents($file_path);
        if ($content !== false) {
            $fileData = @json_decode($content, true);
            if (is_array($fileData) && isset($fileData['cached_at']) && isset($fileData['data'])) {
                // Check if file cache is still valid
                if ((time() - $fileData['cached_at']) < $ttl) {
                    $data = $fileData['data'];
                    
                    // Warm up APCu cache for next request
                    if (function_exists('apcu_store')) {
                        @apcu_store($fullKey, $data, $ttl);
                    }
                    
                    return $data;
                }
            }
        }
    }
    
    // Tier 3: Compute fresh data (slowest - varies)
    $data = $computeFunc();
    persistCachedDataEnvelope($apcu_key, $file_path, $data, $ttl);

    return $data;
}

/**
 * Whether synchronous cache computation is allowed (CLI, tests, or explicit override).
 *
 * Production web requests should return false so expensive work runs only in scheduler workers.
 *
 * @return bool True when synchronous compute is allowed (CLI, APP_ENV=testing, or STATUS_PAGE_SYNC_CACHE_COMPUTE=1)
 */
function shouldAllowSynchronousStatusCacheCompute(): bool {
    if (getenv('STATUS_PAGE_SYNC_CACHE_COMPUTE') === '1') {
        return true;
    }
    if (php_sapi_name() === 'cli') {
        return true;
    }
    if (defined('APP_ENV') && APP_ENV === 'testing') {
        return true;
    }

    return false;
}

/**
 * Status page cache: APCu → fresh file → stale file (no blocking compute on web) → sync compute or default.
 *
 * Scheduler scripts populate JSON; production FPM reads only APCu/disk. Expired files still serve data
 * (stale-while-revalidate) until the next background refresh.
 *
 * @param callable $computeFunc Runs when synchronous compute is allowed and cache is empty
 * @param string $apcu_key APCu key without cached_ prefix
 * @param string|null $file_path Optional JSON cache path (getCachedData envelope format)
 * @param int $ttl Freshness window for the file tier; stale files still returned when sync disabled
 * @param mixed $defaultWhenNoCache Fallback when sync is disabled and APCu and disk are empty (production web)
 * @return mixed APCu hit, fresh file, stale file, sync-computed payload, or $defaultWhenNoCache
 */
function getCachedDataBackgroundFirst(
    callable $computeFunc,
    string $apcu_key,
    ?string $file_path = null,
    int $ttl = 60,
    mixed $defaultWhenNoCache = null
): mixed {
    $fullKey = 'cached_' . $apcu_key;

    if (function_exists('apcu_fetch')) {
        $cached = @apcu_fetch($fullKey, $success);
        if ($success) {
            return $cached;
        }
    }

    $staleData = null;
    if ($file_path !== null && file_exists($file_path)) {
        $content = @file_get_contents($file_path);
        if ($content !== false) {
            $fileData = @json_decode($content, true);
            if (is_array($fileData) && isset($fileData['cached_at'], $fileData['data'])) {
                $data = $fileData['data'];
                $age = time() - (int) $fileData['cached_at'];
                if ($age < $ttl) {
                    if (function_exists('apcu_store')) {
                        @apcu_store($fullKey, $data, $ttl);
                    }

                    return $data;
                }
                $staleData = $data;
            }
        }
    }

    if ($staleData !== null) {
        if (function_exists('apcu_store')) {
            @apcu_store($fullKey, $staleData, $ttl);
        }

        return $staleData;
    }

    if (shouldAllowSynchronousStatusCacheCompute()) {
        $data = $computeFunc();
        persistCachedDataEnvelope($apcu_key, $file_path, $data, $ttl);

        return $data;
    }

    if ($defaultWhenNoCache !== null) {
        aviationwx_log(
            'info',
            'Status page cache miss: serving placeholder until scheduler warms APCu/file',
            [
                'apcu_key' => $apcu_key,
                'cache_file' => $file_path,
            ],
            'app',
            false
        );
    } else {
        aviationwx_log(
            'warning',
            'Status page cache miss: no placeholder default and sync compute disabled',
            [
                'apcu_key' => $apcu_key,
                'cache_file' => $file_path,
            ],
            'app',
            false
        );
    }

    return $defaultWhenNoCache;
}

/**
 * Invalidate cached data (both APCu and file)
 * 
 * Removes data from both APCu and file cache, forcing fresh computation
 * on next access.
 * 
 * @param string $apcu_key APCu cache key (without 'cached_' prefix)
 * @param string|null $file_path Optional file path to remove
 * @return void
 */
function invalidateCachedData(string $apcu_key, ?string $file_path = null): void {
    $fullKey = 'cached_' . $apcu_key;
    
    // Remove from APCu
    if (function_exists('apcu_delete')) {
        @apcu_delete($fullKey);
    }
    
    // Remove file cache
    if ($file_path !== null && file_exists($file_path)) {
        @unlink($file_path);
    }
}
