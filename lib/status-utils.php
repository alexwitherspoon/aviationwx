<?php
/**
 * Status Page Utility Functions
 * 
 * Pure utility functions for formatting, displaying, and calculating status data.
 * Extracted from pages/status.php for reusability.
 */

/**
 * Determine status color
 * 
 * @param string $status Status level: 'operational', 'degraded', 'down', 'maintenance', or other
 * @return string Color name: 'green', 'yellow', 'red', 'orange', or 'gray'
 */
function getStatusColor(string $status): string {
    switch ($status) {
        case 'operational': return 'green';
        case 'degraded': return 'yellow';
        case 'down': return 'red';
        case 'maintenance': return 'orange';
        default: return 'gray';
    }
}

/**
 * Get status icon
 * 
 * @param string $status Status level: 'operational', 'degraded', 'down', 'maintenance', or other
 * @return string Icon character: '●' for status states, '🚧' for maintenance, '○' for unknown
 */
function getStatusIcon(string $status): string {
    switch ($status) {
        case 'operational': return '●';
        case 'degraded': return '●';
        case 'down': return '●';
        case 'maintenance': return '🚧';
        default: return '○';
    }
}

/**
 * Format relative time (e.g., "5 minutes ago", "2 hours ago")
 * 
 * @param int $timestamp Unix timestamp
 * @return string Formatted relative time string or 'Unknown' if invalid
 */
function formatRelativeTime(int $timestamp): string {
    if (!$timestamp || $timestamp <= 0) return 'Unknown';
    $diff = time() - $timestamp;
    
    if ($diff < 60) return 'Just now';
    if ($diff < 3600) return floor($diff / 60) . ' minutes ago';
    if ($diff < 86400) return floor($diff / 3600) . ' hours ago';
    if ($diff < 604800) return floor($diff / 86400) . ' days ago';
    if ($diff < 2592000) return floor($diff / 604800) . ' weeks ago';
    return floor($diff / 2592000) . ' months ago';
}

/**
 * Format absolute timestamp with timezone
 * 
 * @param int $timestamp Unix timestamp
 * @return string Formatted timestamp string or 'Unknown' if invalid
 */
function formatAbsoluteTime(int $timestamp): string {
    if (!$timestamp || $timestamp <= 0) return 'Unknown';
    return date('Y-m-d H:i:s T', $timestamp);
}

/**
 * Format bytes into human-readable format
 * 
 * @param int|null $bytes Number of bytes
 * @param int $precision Decimal precision
 * @return string Formatted string like "1.5 GB" or "-"
 */
function formatBytes(?int $bytes, int $precision = 1): string {
    if ($bytes === null || $bytes < 0) {
        return '-';
    }
    
    $units = ['B', 'KB', 'MB', 'GB', 'TB'];
    $bytes = max($bytes, 0);
    $pow = floor(($bytes ? log($bytes) : 0) / log(1024));
    $pow = min($pow, count($units) - 1);
    $bytes /= pow(1024, $pow);
    
    return round($bytes, $precision) . ' ' . $units[$pow];
}

/**
 * Calculate memory averages from historical snapshots
 * 
 * Stores periodic memory snapshots and calculates rolling averages
 * similar to CPU load averages (1min, 5min, 15min).
 * 
 * @param int $currentMemory Current memory usage in bytes
 * @return array {
 *   '1min' => float|null,
 *   '5min' => float|null,
 *   '15min' => float|null
 * }
 */
function calculateMemoryAverages(int $currentMemory): array {
    $cacheFile = __DIR__ . '/../cache/memory_history.json';
    $cacheDir = dirname($cacheFile);
    $now = time();
    
    // Ensure cache directory exists
    if (!is_dir($cacheDir)) {
        @mkdir($cacheDir, 0755, true);
    }
    
    // Load existing snapshots
    // Use @ to suppress errors for non-critical file operations
    // We handle failures explicitly with fallback mechanisms below
    $snapshots = [];
    if (file_exists($cacheFile)) {
        $content = @file_get_contents($cacheFile);
        if ($content !== false) {
            $data = @json_decode($content, true);
            if (is_array($data) && isset($data['snapshots']) && is_array($data['snapshots'])) {
                $snapshots = $data['snapshots'];
            }
        }
    }
    
    // Sample every 5 seconds to avoid excessive writes
    $shouldAddSnapshot = true;
    if (!empty($snapshots)) {
        $lastSnapshot = end($snapshots);
        $lastTime = $lastSnapshot['timestamp'] ?? 0;
        if (($now - $lastTime) < 5) {
            $shouldAddSnapshot = false;
        }
    }
    
    if ($shouldAddSnapshot) {
        $snapshots[] = [
            'timestamp' => $now,
            'memory' => $currentMemory
        ];
        
        // Keep only last 15 minutes of data (900 seconds / 5 second samples = 180 snapshots)
        $cutoff = $now - 900;
        $snapshots = array_filter($snapshots, function($s) use ($cutoff) {
            return $s['timestamp'] >= $cutoff;
        });
        $snapshots = array_values($snapshots); // Re-index
        
        // Save to file (best-effort, non-critical)
        $tmpFile = $cacheFile . '.tmp.' . getmypid();
        $saved = @file_put_contents($tmpFile, json_encode(['snapshots' => $snapshots], JSON_PRETTY_PRINT), LOCK_EX);
        if ($saved !== false) {
            @rename($tmpFile, $cacheFile);
        } else {
            @unlink($tmpFile);
        }
    }
    
    // Calculate averages
    $averages = [
        '1min' => null,
        '5min' => null,
        '15min' => null
    ];
    
    if (empty($snapshots)) {
        return $averages;
    }
    
    // 1-minute average
    $cutoff1m = $now - 60;
    $samples1m = array_filter($snapshots, function($s) use ($cutoff1m) {
        return $s['timestamp'] >= $cutoff1m;
    });
    if (!empty($samples1m)) {
        $sum = array_sum(array_column($samples1m, 'memory'));
        $averages['1min'] = $sum / count($samples1m);
    }
    
    // 5-minute average
    $cutoff5m = $now - 300;
    $samples5m = array_filter($snapshots, function($s) use ($cutoff5m) {
        return $s['timestamp'] >= $cutoff5m;
    });
    if (!empty($samples5m)) {
        $sum = array_sum(array_column($samples5m, 'memory'));
        $averages['5min'] = $sum / count($samples5m);
    }
    
    // 15-minute average
    $cutoff15m = $now - 900;
    $samples15m = array_filter($snapshots, function($s) use ($cutoff15m) {
        return $s['timestamp'] >= $cutoff15m;
    });
    if (!empty($samples15m)) {
        $sum = array_sum(array_column($samples15m, 'memory'));
        $averages['15min'] = $sum / count($samples15m);
    }
    
    return $averages;
}

/**
 * Get node performance metrics from container perspective
 * 
 * @return array {
 *   'cpu_load' => array{
 *     '1min' => float|null,
 *     '5min' => float|null,
 *     '15min' => float|null
 *   },
 *   'memory_used_bytes' => int|null,
 *   'memory_average' => array{
 *     '1min' => float|null,
 *     '5min' => float|null,
 *     '15min' => float|null
 *   },
 *   'storage_used_bytes' => int,
 *   'storage_breakdown' => array{
 *     'cache' => int,
 *     'uploads' => int,
 *     'logs' => int
 *   }
 * }
 */
function getNodePerformance(): array {
    $performance = [
        'cpu_load' => [
            '1min' => null,
            '5min' => null,
            '15min' => null
        ],
        'memory_used_bytes' => null,
        'memory_average' => [
            '1min' => null,
            '5min' => null,
            '15min' => null
        ],
        'storage_used_bytes' => null
    ];
    
    // CPU Load Averages - sys_getloadavg() works on Linux/macOS
    if (function_exists('sys_getloadavg')) {
        $load = sys_getloadavg();
        if (is_array($load) && count($load) >= 3) {
            $performance['cpu_load']['1min'] = round($load[0], 2);
            $performance['cpu_load']['5min'] = round($load[1], 2);
            $performance['cpu_load']['15min'] = round($load[2], 2);
        }
    }
    
    // Memory Usage - Prefer RSS (Resident Set Size) to match htop, fallback to total cgroup memory
    // RSS represents actual physical memory used, excluding page cache
    // This is more comparable to what htop shows for process memory
    $memoryUsed = null;
    
    // Try to get RSS from cgroups v2 (modern Docker)
    // RSS is reported as "anon" in cgroups v2 memory.stat
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
    
    // Fallback: Use total cgroup memory (includes cache, less ideal but better than nothing)
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
    
    // Last resort: /proc/meminfo (host view, not container-specific, but works)
    // Note: This shows system-wide memory, not container memory, so won't match htop process view
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
    
    $performance['memory_used_bytes'] = $memoryUsed;
    
    if ($memoryUsed !== null) {
        $memoryAverages = calculateMemoryAverages($memoryUsed);
        $performance['memory_average'] = $memoryAverages;
    }
    
    // Calculate storage usage (cache, uploads, logs)
    $performance['storage_used_bytes'] = 0;
    $performance['storage_breakdown'] = [
        'cache' => 0,
        'uploads' => 0,
        'logs' => 0
    ];
    
    $calculateDirSize = function($path) {
        if (!is_dir($path)) {
            return 0;
        }
        $size = 0;
        try {
            $iterator = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($path, RecursiveDirectoryIterator::SKIP_DOTS),
                RecursiveIteratorIterator::LEAVES_ONLY
            );
            foreach ($iterator as $file) {
                if ($file->isFile()) {
                    $fileSize = @$file->getSize();
                    if ($fileSize !== false) {
                        $size += $fileSize;
                    }
                }
            }
        } catch (Exception $e) {
            // Directory not accessible, return 0
        }
        return $size;
    };
    
    $cachePath = __DIR__ . '/../cache';
    $performance['storage_breakdown']['cache'] = $calculateDirSize($cachePath);
    
    $uploadsPath = __DIR__ . '/../uploads';
    $performance['storage_breakdown']['uploads'] = $calculateDirSize($uploadsPath);
    
    // Check both the defined log directory and common locations
    $logPaths = [
        defined('AVIATIONWX_LOG_DIR') ? AVIATIONWX_LOG_DIR : '/var/log/aviationwx',
        '/var/log/aviationwx'
    ];
    foreach (array_unique($logPaths) as $logPath) {
        $logSize = $calculateDirSize($logPath);
        if ($logSize > 0) {
            $performance['storage_breakdown']['logs'] = $logSize;
            break;
        }
    }
    
    $performance['storage_used_bytes'] = array_sum($performance['storage_breakdown']);
    
    return $performance;
}
