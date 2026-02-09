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
 * @return mixed Cached or freshly computed data
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
    
    // Store in APCu for fast access
    if (function_exists('apcu_store')) {
        @apcu_store($fullKey, $data, $ttl);
    }
    
    // Store in file for persistence across restarts
    if ($file_path !== null) {
        $cacheDir = dirname($file_path);
        if (!is_dir($cacheDir)) {
            @mkdir($cacheDir, 0755, true);
        }
        
        $fileData = [
            'cached_at' => time(),
            'ttl' => $ttl,
            'key' => $apcu_key,
            'data' => $data
        ];
        
        $tmpFile = $file_path . '.tmp.' . getmypid();
        $written = @file_put_contents($tmpFile, json_encode($fileData, JSON_PRETTY_PRINT), LOCK_EX);
        
        if ($written !== false) {
            @rename($tmpFile, $file_path);
        } else {
            @unlink($tmpFile);
        }
    }
    
    return $data;
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
