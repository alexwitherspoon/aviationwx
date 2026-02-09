<?php
/**
 * Memory Metrics - Time-series memory sampling and aggregation
 * 
 * Provides CPU-load-style rolling averages for memory usage by sampling
 * every 5 seconds via background cron job and storing in hourly buckets.
 * 
 * Unlike the original memory_history.json approach which depended on page loads,
 * this system samples continuously and persists data in the same pattern as
 * the existing metrics system (hourly buckets, JSON files).
 * 
 * @package AviationWX
 */

require_once __DIR__ . '/constants.php';
require_once __DIR__ . '/logger.php';
require_once __DIR__ . '/cache-paths.php';

// Memory metrics directory constant
if (!defined('CACHE_MEMORY_DIR')) {
    define('CACHE_MEMORY_DIR', CACHE_BASE_DIR . '/metrics/memory');
}

// =============================================================================
// DIRECTORY SETUP
// =============================================================================

/**
 * Ensure memory metrics directory exists
 * 
 * @return bool True if directory exists or was created
 */
function memory_metrics_ensure_dir(): bool {
    if (!is_dir(CACHE_MEMORY_DIR)) {
        return @mkdir(CACHE_MEMORY_DIR, 0755, true);
    }
    return true;
}

// =============================================================================
// BUCKET ID FUNCTIONS
// =============================================================================

/**
 * Get current hour bucket ID for memory metrics
 * 
 * @param int|null $timestamp Unix timestamp (default: now)
 * @return string Hour ID (e.g., '2026-02-09-20')
 */
function memory_metrics_get_hour_id(?int $timestamp = null): string {
    $timestamp = $timestamp ?? time();
    return gmdate('Y-m-d-H', $timestamp);
}

/**
 * Get memory metrics hourly file path
 * 
 * @param string $hourId Hour bucket ID
 * @return string Full path to hourly metrics file
 */
function memory_metrics_get_hourly_path(string $hourId): string {
    return CACHE_MEMORY_DIR . '/' . $hourId . '.json';
}

// =============================================================================
// SAMPLING FUNCTIONS
// =============================================================================

/**
 * Get current memory usage in bytes
 * 
 * Reads RSS (Resident Set Size) from cgroup memory.stat, with fallbacks.
 * This matches the logic in getNodePerformance() for consistency.
 * 
 * Note: This function is designed for Linux/Docker environments with cgroup support.
 * On macOS/BSD, it will return null (no cgroups available). This is expected -
 * memory metrics are only functional in production (Docker) environment.
 * 
 * @return int|null Memory usage in bytes, or null if unavailable
 */
function memory_metrics_get_current(): ?int {
    $memoryUsed = null;
    
    // Try to get RSS from cgroups v2 (modern Docker)
    $cgroupV2Stat = '/sys/fs/cgroup/memory.stat';
    if (file_exists($cgroupV2Stat) && is_readable($cgroupV2Stat)) {
        $statContent = @file_get_contents($cgroupV2Stat);
        if ($statContent !== false && preg_match('/anon\s+(\d+)/', $statContent, $m)) {
            $memoryUsed = (int) $m[1];
        }
    }
    
    // Fallback: Try RSS from cgroups v1
    if ($memoryUsed === null) {
        $cgroupV1Stat = '/sys/fs/cgroup/memory/memory.stat';
        if (file_exists($cgroupV1Stat) && is_readable($cgroupV1Stat)) {
            $statContent = @file_get_contents($cgroupV1Stat);
            if ($statContent !== false && preg_match('/rss\s+(\d+)/', $statContent, $m)) {
                $memoryUsed = (int) $m[1];
            }
        }
    }
    
    // Fallback: Use total cgroup memory (includes cache, less ideal)
    if ($memoryUsed === null) {
        $cgroupV2Current = '/sys/fs/cgroup/memory.current';
        if (file_exists($cgroupV2Current) && is_readable($cgroupV2Current)) {
            $memoryUsed = (int) trim(@file_get_contents($cgroupV2Current));
        }
    }
    
    if ($memoryUsed === null) {
        $cgroupV1Usage = '/sys/fs/cgroup/memory/memory.usage_in_bytes';
        if (file_exists($cgroupV1Usage) && is_readable($cgroupV1Usage)) {
            $memoryUsed = (int) trim(@file_get_contents($cgroupV1Usage));
        }
    }
    
    // Last resort: /proc/meminfo (host view, not container-specific)
    // Only use if we're on Linux (has /proc/meminfo)
    if ($memoryUsed === null && file_exists('/proc/meminfo')) {
        $meminfo = @file_get_contents('/proc/meminfo');
        if ($meminfo) {
            $total = 0;
            $available = 0;
            if (preg_match('/MemTotal:\s+(\d+)\s+kB/', $meminfo, $m)) {
                $total = (int) $m[1] * 1024;
            }
            if (preg_match('/MemAvailable:\s+(\d+)\s+kB/', $meminfo, $m)) {
                $available = (int) $m[1] * 1024;
            }
            if ($total > 0) {
                $memoryUsed = $total - $available;
            }
        }
    }
    
    return $memoryUsed;
}

/**
 * Record a memory sample in the current hourly bucket
 * 
 * Appends a new sample to the current hour's JSON file. Samples are stored
 * with timestamps for later aggregation into rolling averages.
 * 
 * @param int $memoryBytes Memory usage in bytes
 * @param int|null $timestamp Unix timestamp (default: now)
 * @return bool True on success
 */
function memory_metrics_record_sample(int $memoryBytes, ?int $timestamp = null): bool {
    $timestamp = $timestamp ?? time();
    $hourId = memory_metrics_get_hour_id($timestamp);
    $hourFile = memory_metrics_get_hourly_path($hourId);
    
    if (!memory_metrics_ensure_dir()) {
        aviationwx_log('error', 'memory_metrics: failed to create directory', [
            'dir' => CACHE_MEMORY_DIR
        ], 'app');
        return false;
    }
    
    // Load existing hourly data
    $hourData = [
        'bucket_type' => 'memory_5s',
        'bucket_id' => $hourId,
        'bucket_start' => strtotime($hourId . ':00:00 UTC'),
        'bucket_end' => strtotime($hourId . ':59:59 UTC') + 1,
        'samples' => []
    ];
    
    if (file_exists($hourFile)) {
        $content = @file_get_contents($hourFile);
        if ($content !== false) {
            $data = @json_decode($content, true);
            if (is_array($data) && isset($data['samples']) && is_array($data['samples'])) {
                $hourData = $data;
            }
        }
    }
    
    // Add new sample
    $hourData['samples'][] = [
        'timestamp' => $timestamp,
        'memory_bytes' => $memoryBytes
    ];
    
    // Keep only last 15 minutes of samples per hour file (for rollover period)
    // 15 minutes * 60 seconds / 5 second samples = 180 samples
    $cutoff = $timestamp - 900;
    $hourData['samples'] = array_filter($hourData['samples'], function($s) use ($cutoff) {
        return $s['timestamp'] >= $cutoff;
    });
    $hourData['samples'] = array_values($hourData['samples']); // Re-index
    
    // Write atomically using temp file + rename
    $tmpFile = $hourFile . '.tmp.' . getmypid();
    $json = json_encode($hourData, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    
    if (@file_put_contents($tmpFile, $json, LOCK_EX) === false) {
        @unlink($tmpFile);
        aviationwx_log('error', 'memory_metrics: failed to write sample', [
            'file' => $hourFile,
            'memory_mb' => round($memoryBytes / 1024 / 1024, 1)
        ], 'app');
        return false;
    }
    
    if (!@rename($tmpFile, $hourFile)) {
        @unlink($tmpFile);
        aviationwx_log('error', 'memory_metrics: failed to rename sample file', [
            'file' => $hourFile
        ], 'app');
        return false;
    }
    
    return true;
}

// =============================================================================
// AGGREGATION FUNCTIONS
// =============================================================================

/**
 * Load memory samples from hourly files within time range
 * 
 * @param int $startTime Start timestamp (inclusive)
 * @param int $endTime End timestamp (inclusive)
 * @return array Array of samples [{timestamp, memory_bytes}, ...]
 */
function memory_metrics_load_samples(int $startTime, int $endTime): array {
    $samples = [];
    
    // Determine which hour buckets we need to load
    $currentHour = (int)gmdate('H', $startTime);
    $endHour = (int)gmdate('H', $endTime);
    $currentDay = gmdate('Y-m-d', $startTime);
    $endDay = gmdate('Y-m-d', $endTime);
    
    // Load current hour
    $currentHourId = memory_metrics_get_hour_id($startTime);
    $currentFile = memory_metrics_get_hourly_path($currentHourId);
    if (file_exists($currentFile)) {
        $content = @file_get_contents($currentFile);
        if ($content !== false) {
            $data = @json_decode($content, true);
            if (is_array($data) && isset($data['samples']) && is_array($data['samples'])) {
                foreach ($data['samples'] as $sample) {
                    if ($sample['timestamp'] >= $startTime && $sample['timestamp'] <= $endTime) {
                        $samples[] = $sample;
                    }
                }
            }
        }
    }
    
    // If time range spans multiple hours, load previous hour too
    if ($currentDay !== $endDay || $currentHour !== $endHour) {
        $prevHourId = memory_metrics_get_hour_id($startTime - 3600);
        $prevFile = memory_metrics_get_hourly_path($prevHourId);
        if (file_exists($prevFile)) {
            $content = @file_get_contents($prevFile);
            if ($content !== false) {
                $data = @json_decode($content, true);
                if (is_array($data) && isset($data['samples']) && is_array($data['samples'])) {
                    foreach ($data['samples'] as $sample) {
                        if ($sample['timestamp'] >= $startTime && $sample['timestamp'] <= $endTime) {
                            $samples[] = $sample;
                        }
                    }
                }
            }
        }
    }
    
    // Sort by timestamp
    usort($samples, function($a, $b) {
        return $a['timestamp'] <=> $b['timestamp'];
    });
    
    return $samples;
}

/**
 * Calculate memory rolling averages (1min, 5min, 15min)
 * 
 * Returns CPU-load-style rolling averages by aggregating samples from
 * hourly bucket files. Mimics sys_getloadavg() behavior for memory.
 * 
 * @return array{1min: float|null, 5min: float|null, 15min: float|null}
 */
function memory_metrics_get_averages(): array {
    $now = time();
    $averages = [
        '1min' => null,
        '5min' => null,
        '15min' => null
    ];
    
    // 1-minute average
    $samples1m = memory_metrics_load_samples($now - 60, $now);
    if (!empty($samples1m)) {
        $sum = array_sum(array_column($samples1m, 'memory_bytes'));
        $averages['1min'] = $sum / count($samples1m);
    }
    
    // 5-minute average
    $samples5m = memory_metrics_load_samples($now - 300, $now);
    if (!empty($samples5m)) {
        $sum = array_sum(array_column($samples5m, 'memory_bytes'));
        $averages['5min'] = $sum / count($samples5m);
    }
    
    // 15-minute average
    $samples15m = memory_metrics_load_samples($now - 900, $now);
    if (!empty($samples15m)) {
        $sum = array_sum(array_column($samples15m, 'memory_bytes'));
        $averages['15min'] = $sum / count($samples15m);
    }
    
    return $averages;
}

// =============================================================================
// CLEANUP FUNCTIONS
// =============================================================================

/**
 * Clean up old memory metrics files
 * 
 * Removes hourly files older than specified number of days.
 * Called periodically by scheduler to prevent unbounded growth.
 * 
 * @param int $daysToKeep Number of days to retain (default: 1)
 * @return int Number of files deleted
 */
function memory_metrics_cleanup(int $daysToKeep = 1): int {
    if (!is_dir(CACHE_MEMORY_DIR)) {
        return 0;
    }
    
    $cutoff = time() - ($daysToKeep * 86400);
    $deleted = 0;
    
    $files = glob(CACHE_MEMORY_DIR . '/*.json');
    if ($files === false) {
        return 0;
    }
    
    foreach ($files as $file) {
        $mtime = @filemtime($file);
        if ($mtime !== false && $mtime < $cutoff) {
            if (@unlink($file)) {
                $deleted++;
            }
        }
    }
    
    if ($deleted > 0) {
        aviationwx_log('info', 'memory_metrics: cleaned up old files', [
            'deleted' => $deleted,
            'days' => $daysToKeep
        ], 'app');
    }
    
    return $deleted;
}
