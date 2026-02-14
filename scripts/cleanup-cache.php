#!/usr/bin/env php
<?php
/**
 * Cache Cleanup Script
 * 
 * Two-layer cleanup strategy (belt and suspenders):
 * 
 * LAYER 1 - Primary cleanup for files WITHOUT automatic cleanup:
 * - Rate limit files older than 1 hour
 * - Webcam error files older than 7 days
 * - Outage state files older than 30 days
 * 
 * LAYER 2 - Backup cleanup for files WITH automatic cleanup:
 * More generous thresholds as a safety net in case primary cleanup fails.
 * - Weather history: 48 hours (primary: 24h)
 * - Webcam history frames: 7 days (primary: frame count)
 * - Peak gusts/temp extremes entries: 7 days (primary: 2 days)
 * - Weather cache: 7 days (should be continuously updated)
 * - NOTAM cache: 24 hours (primary: 1 hour refresh)
 * - Webcam images: 7 days (should be continuously updated)
 * 
 * LAYER 3 - Orphan cleanup:
 * Files for airports no longer in configuration.
 * 
 * Usage:
 *   php cleanup-cache.php [--dry-run] [--verbose] [--force]
 * 
 * Options:
 *   --dry-run   Show what would be deleted without actually deleting
 *   --verbose   Show detailed information about each file
 *   --force     Skip confirmation prompts
 * 
 * Designed to run daily via cron (recommended: 4 AM local time)
 */

// Ensure we're running from CLI
if (php_sapi_name() !== 'cli') {
    http_response_code(403);
    die('This script must be run from the command line');
}

// Parse command line options
$options = getopt('', ['dry-run', 'verbose', 'force', 'help']);
$dryRun = isset($options['dry-run']);
$verbose = isset($options['verbose']);
$force = isset($options['force']);

if (isset($options['help'])) {
    echo <<<HELP
Cache Cleanup Script - Belt and Suspenders Approach

Usage: php cleanup-cache.php [OPTIONS]

Options:
  --dry-run   Show what would be deleted without actually deleting
  --verbose   Show detailed information about each file
  --force     Skip confirmation prompts
  --help      Show this help message

Cleanup Layers:
  Layer 1: Primary cleanup for files without automatic cleanup
  Layer 2: Backup cleanup for files with automatic cleanup (safety net)
  Layer 3: Orphan cleanup for removed airports

HELP;
    exit(0);
}

// Change to project root
chdir(__DIR__ . '/..');

// Load required files
require_once __DIR__ . '/../lib/logger.php';
require_once __DIR__ . '/../lib/config.php';
require_once __DIR__ . '/../lib/cache-paths.php';

// ============================================================================
// CONFIGURATION - Cleanup Thresholds (in seconds)
// ============================================================================

// Layer 1: Primary cleanup (files without automatic cleanup)
define('CLEANUP_RATE_LIMIT_AGE', 86400);          // 24 hours
define('CLEANUP_WEBCAM_ERROR_AGE', 604800);       // 7 days
define('CLEANUP_OUTAGE_STATE_AGE', 2592000);      // 30 days

// Layer 2: Backup cleanup (more generous - safety net)
define('CLEANUP_WEATHER_HISTORY_AGE', 172800);    // 48 hours (primary: 24h)
define('CLEANUP_WEBCAM_HISTORY_AGE', 604800);     // 7 days (primary: frame count)
define('CLEANUP_PEAK_GUST_AGE', 604800);          // 7 days (primary: 2 days)
define('CLEANUP_TEMP_EXTREMES_AGE', 604800);      // 7 days (primary: 2 days)
define('CLEANUP_WEATHER_CACHE_AGE', 604800);      // 7 days (should be updated continuously)
define('CLEANUP_NOTAM_CACHE_AGE', 86400);         // 24 hours (primary: 1 hour refresh)
define('CLEANUP_WEBCAM_IMAGE_AGE', 604800);       // 7 days (should be updated continuously)
define('CLEANUP_BACKOFF_ENTRY_AGE', 604800);      // 7 days for stale entries

// Layer 3: Orphan cleanup (airports not in config)
define('CLEANUP_ORPHAN_AGE', 2592000);            // 30 days

// External data caches (generous thresholds)
define('CLEANUP_OURAIRPORTS_AGE', 2592000);       // 30 days (primary: 7 days)
define('CLEANUP_ICAO_AIRPORTS_AGE', 5184000);     // 60 days (primary: 30 days)
define('CLEANUP_MAPPING_CACHE_AGE', 5184000);     // 60 days

// Disk usage warning thresholds
define('DISK_USAGE_WARNING_PERCENT', 80);
define('DISK_USAGE_CRITICAL_PERCENT', 90);

// ============================================================================
// MAIN EXECUTION
// ============================================================================

$stats = [
    'files_checked' => 0,
    'files_deleted' => 0,
    'bytes_freed' => 0,
    'errors' => 0,
    'start_time' => microtime(true),
];

echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n";
echo "Cache Cleanup Script - " . date('Y-m-d H:i:s') . "\n";
echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n";

if ($dryRun) {
    echo "🔍 DRY RUN MODE - No files will be deleted\n";
}
echo "\n";

// Check disk usage first
$cacheDir = CACHE_BASE_DIR;
$apiCacheDir = __DIR__ . '/../api/cache';
$scriptsCacheDir = __DIR__ . '/../scripts/cache';
checkDiskUsage($cacheDir);

// Get configured airports for orphan detection
$configuredAirports = getConfiguredAirportIds();
if ($verbose) {
    echo "📋 Found " . count($configuredAirports) . " configured airports\n\n";
}

// ============================================================================
// LAYER 1: Primary cleanup (files without automatic cleanup)
// ============================================================================

echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n";
echo "LAYER 1: Primary Cleanup (files without automatic cleanup)\n";
echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n\n";

// Rate limit files (1 hour)
cleanupFilesByPattern(
    $cacheDir . '/rate_limit_*.json',
    CLEANUP_RATE_LIMIT_AGE,
    'Rate limit files',
    $stats, $dryRun, $verbose
);

// Webcam error files (7 days)
// New structure: webcams/{airportId}/{camIndex}/*.error.json
cleanupFilesByPattern(
    CACHE_WEBCAMS_DIR . '/*/*/*.error.json',
    CLEANUP_WEBCAM_ERROR_AGE,
    'Webcam error files',
    $stats, $dryRun, $verbose
);

// Outage state files (30 days)
cleanupFilesByPattern(
    $cacheDir . '/outage_*.json',
    CLEANUP_OUTAGE_STATE_AGE,
    'Outage state files',
    $stats, $dryRun, $verbose
);

// Quarantined webcam images (7 days)
echo "Cleaning quarantined webcam images...\n";
require_once __DIR__ . '/../lib/webcam-quarantine.php';
if (!$dryRun) {
    $quarantineResult = cleanupQuarantine(7);
    $stats['files_deleted'] += $quarantineResult['deleted'];
    $stats['errors'] += $quarantineResult['errors'];
    echo "  ✓ Deleted {$quarantineResult['deleted']} quarantined files";
    if ($quarantineResult['errors'] > 0) {
        echo " ({$quarantineResult['errors']} errors)";
    }
    echo "\n";
} else {
    echo "  [DRY RUN] Would clean quarantine directory (7 days old)\n";
}
echo "\n";

// Public API rate limit files (1 hour)
cleanupFilesByPattern(
    $cacheDir . '/public_api_rate_*.json',
    CLEANUP_RATE_LIMIT_AGE,
    'Public API rate limit files',
    $stats, $dryRun, $verbose
);

// ============================================================================
// LAYER 2: Backup cleanup (safety net for files with automatic cleanup)
// ============================================================================

echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n";
echo "LAYER 2: Backup Cleanup (safety net)\n";
echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n\n";

// Weather history files (48 hours - primary is 24h)
// New structure: cache/weather/history/{airport}.json
cleanupFilesByPattern(
    CACHE_WEATHER_HISTORY_DIR . '/*.json',
    CLEANUP_WEATHER_HISTORY_AGE,
    'Weather history files (backup)',
    $stats, $dryRun, $verbose
);

// Webcam history frames (7 days - primary is frame count)
cleanupWebcamHistoryFrames(
    CACHE_WEBCAMS_DIR,
    CLEANUP_WEBCAM_HISTORY_AGE,
    $stats, $dryRun, $verbose
);

// Weather cache files (7 days - should be updated continuously)
// New structure: cache/weather/{airport}.json
cleanupFilesByPattern(
    CACHE_WEATHER_DIR . '/*.json',
    CLEANUP_WEATHER_CACHE_AGE,
    'Weather cache files (backup)',
    $stats, $dryRun, $verbose
);

// NOTAM cache files (24 hours - primary is 1 hour refresh)
cleanupFilesByPattern(
    CACHE_NOTAM_DIR . '/*.json',
    CLEANUP_NOTAM_CACHE_AGE,
    'NOTAM cache files (backup)',
    $stats, $dryRun, $verbose
);

// Webcam images (7 days - should be updated continuously)
// Structure: cache/webcams/{airportId}/{camIndex}/*.{format}
cleanupFilesByPattern(
    CACHE_WEBCAMS_DIR . '/*/*/*.jpg',
    CLEANUP_WEBCAM_IMAGE_AGE,
    'Webcam images (backup)',
    $stats, $dryRun, $verbose
);
cleanupFilesByPattern(
    CACHE_WEBCAMS_DIR . '/*/*/*.webp',
    CLEANUP_WEBCAM_IMAGE_AGE,
    'Webcam WebP images (backup)',
    $stats, $dryRun, $verbose
);

// Map tiles (cached for 15min-1hr, clean up after 7 days)
// Structure: cache/map_tiles/{layer}/{z}_{x}_{y}.png
// Includes both OpenWeatherMap and RainViewer tiles
cleanupFilesByPattern(
    CACHE_MAP_TILES_DIR . '/*/*.png',
    604800, // 7 days
    'Map tiles (OpenWeatherMap + RainViewer cache)',
    $stats, $dryRun, $verbose
);

// Clean stale entries in per-airport tracking files (primary layout)
foreach ([CACHE_PEAK_GUSTS_DIR, CACHE_TEMP_EXTREMES_DIR] as $dir) {
    if (!is_dir($dir)) {
        continue;
    }
    $files = glob($dir . '/*.json') ?: [];
    $label = strpos($dir, 'peak_gusts') !== false ? 'Peak gusts' : 'Temperature extremes';
    foreach ($files as $file) {
        cleanupDailyTrackingEntries(
            $file,
            strpos($dir, 'peak_gusts') !== false ? CLEANUP_PEAK_GUST_AGE : CLEANUP_TEMP_EXTREMES_AGE,
            $label . ' entries (' . basename($file) . ')',
            $stats, $dryRun, $verbose
        );
    }
}
// Legacy single-file format (migration period)
cleanupDailyTrackingEntries(
    CACHE_PEAK_GUSTS_FILE,
    CLEANUP_PEAK_GUST_AGE,
    'Peak gusts entries (legacy)',
    $stats, $dryRun, $verbose
);
cleanupDailyTrackingEntries(
    CACHE_TEMP_EXTREMES_FILE,
    CLEANUP_TEMP_EXTREMES_AGE,
    'Temperature extremes entries (legacy)',
    $stats, $dryRun, $verbose
);
// Also check api/cache dir (used by some scripts)
cleanupDailyTrackingEntries(
    $apiCacheDir . '/peak_gusts.json',
    CLEANUP_PEAK_GUST_AGE,
    'Peak gusts entries (api cache)',
    $stats, $dryRun, $verbose
);
cleanupDailyTrackingEntries(
    $apiCacheDir . '/temp_extremes.json',
    CLEANUP_TEMP_EXTREMES_AGE,
    'Temperature extremes entries (api cache)',
    $stats, $dryRun, $verbose
);

// Clean stale entries in backoff.json
cleanupBackoffEntries(
    CACHE_BACKOFF_FILE,
    CLEANUP_BACKOFF_ENTRY_AGE,
    $stats, $dryRun, $verbose
);
// Also check api/cache and scripts/cache dirs
foreach ([$apiCacheDir, $scriptsCacheDir] as $dir) {
    cleanupBackoffEntries(
        $dir . '/backoff.json',
        CLEANUP_BACKOFF_ENTRY_AGE,
        $stats, $dryRun, $verbose
    );
}

// External data caches (generous thresholds)
cleanupFilesByAge(
    CACHE_OURAIRPORTS_FILE,
    CLEANUP_OURAIRPORTS_AGE,
    'OurAirports data cache',
    $stats, $dryRun, $verbose
);
cleanupFilesByAge(
    CACHE_ICAO_AIRPORTS_FILE,
    CLEANUP_ICAO_AIRPORTS_AGE,
    'ICAO airports cache',
    $stats, $dryRun, $verbose
);
cleanupFilesByAge(
    CACHE_IATA_MAPPING_FILE,
    CLEANUP_MAPPING_CACHE_AGE,
    'IATA to ICAO mapping cache',
    $stats, $dryRun, $verbose
);
cleanupFilesByAge(
    CACHE_FAA_MAPPING_FILE,
    CLEANUP_MAPPING_CACHE_AGE,
    'FAA to ICAO mapping cache',
    $stats, $dryRun, $verbose
);

// ============================================================================
// LAYER 3: Orphan cleanup (files for airports not in config)
// ============================================================================

echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n";
echo "LAYER 3: Orphan Cleanup (removed airports)\n";
echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n\n";

cleanupOrphanedAirportFiles(
    CACHE_BASE_DIR,
    $configuredAirports,
    CLEANUP_ORPHAN_AGE,
    $stats, $dryRun, $verbose
);

// ============================================================================
// LAYER 4: Empty directory cleanup
// ============================================================================

echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n";
echo "LAYER 4: Empty Directory Cleanup\n";
echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n\n";

cleanupEmptyDirectories(CACHE_WEBCAMS_DIR, $stats, $dryRun, $verbose);
// Note: We intentionally do NOT clean up empty directories in CACHE_UPLOADS_DIR or CACHE_SFTP_DIR
// Push webcam upload directories must exist even when empty (waiting for FTP/SFTP uploads)
// The vsftpd per-user config points to FTP directories, and SFTP users are chrooted to SFTP directories
cleanupEmptyDirectoriesExcludingUploads(CACHE_UPLOADS_DIR, $configuredAirports, $stats, $dryRun, $verbose);
cleanupEmptyDirectoriesExcludingSftp(CACHE_SFTP_DIR, $configuredAirports, $stats, $dryRun, $verbose);
cleanupEmptyDirectories(CACHE_WEATHER_DIR, $stats, $dryRun, $verbose);
cleanupEmptyDirectories(CACHE_WEATHER_HISTORY_DIR, $stats, $dryRun, $verbose);
cleanupEmptyDirectories($cacheDir . '/notam', $stats, $dryRun, $verbose);
cleanupEmptyDirectories($cacheDir . '/rate_limits', $stats, $dryRun, $verbose);
cleanupEmptyDirectories($cacheDir . '/map_tiles', $stats, $dryRun, $verbose);

// ============================================================================
// LAYER 5: Ensure push webcam upload directories exist (safety net)
// ============================================================================

echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n";
echo "LAYER 5: Ensure Push Webcam Directories Exist\n";
echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n\n";

ensurePushWebcamDirectories($stats, $dryRun, $verbose);

// ============================================================================
// LAYER 6: Clean Up Old Memory Metrics
// ============================================================================

cleanupMemoryMetrics(1, $dryRun, $verbose);

// ============================================================================
// SUMMARY
// ============================================================================

$elapsed = microtime(true) - $stats['start_time'];

echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n";
echo "SUMMARY\n";
echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n";
echo "Files checked:  " . number_format($stats['files_checked']) . "\n";
echo "Files deleted:  " . number_format($stats['files_deleted']) . "\n";
echo "Space freed:    " . formatBytes($stats['bytes_freed']) . "\n";
echo "Errors:         " . $stats['errors'] . "\n";
echo "Duration:       " . round($elapsed, 2) . " seconds\n";
echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n";

if ($dryRun) {
    echo "\n🔍 DRY RUN - No files were actually deleted\n";
}

// Log summary
aviationwx_log('info', 'cache cleanup completed', [
    'files_checked' => $stats['files_checked'],
    'files_deleted' => $stats['files_deleted'],
    'bytes_freed' => $stats['bytes_freed'],
    'errors' => $stats['errors'],
    'duration_seconds' => round($elapsed, 2),
    'dry_run' => $dryRun,
], 'app');

exit($stats['errors'] > 0 ? 1 : 0);

// ============================================================================
// HELPER FUNCTIONS
// ============================================================================

/**
 * Check disk usage and warn if approaching limits
 */
function checkDiskUsage(string $path): void {
    $total = @disk_total_space($path);
    $free = @disk_free_space($path);
    
    if ($total === false || $free === false) {
        echo "⚠️  Could not determine disk usage for: {$path}\n\n";
        return;
    }
    
    $used = $total - $free;
    $usedPercent = ($used / $total) * 100;
    
    echo "💾 Disk Usage: " . formatBytes($used) . " / " . formatBytes($total);
    echo " (" . round($usedPercent, 1) . "%)\n";
    
    if ($usedPercent >= DISK_USAGE_CRITICAL_PERCENT) {
        echo "🚨 CRITICAL: Disk usage is above " . DISK_USAGE_CRITICAL_PERCENT . "%!\n";
        aviationwx_log('error', 'disk usage critical', [
            'path' => $path,
            'used_percent' => round($usedPercent, 1),
            'free_bytes' => $free,
        ], 'app');
    } elseif ($usedPercent >= DISK_USAGE_WARNING_PERCENT) {
        echo "⚠️  WARNING: Disk usage is above " . DISK_USAGE_WARNING_PERCENT . "%\n";
        aviationwx_log('warning', 'disk usage high', [
            'path' => $path,
            'used_percent' => round($usedPercent, 1),
            'free_bytes' => $free,
        ], 'app');
    } else {
        echo "✅ Disk usage is healthy\n";
    }
    echo "\n";
}

/**
 * Format bytes to human readable
 */
function formatBytes(int $bytes): string {
    $units = ['B', 'KB', 'MB', 'GB', 'TB'];
    $i = 0;
    while ($bytes >= 1024 && $i < count($units) - 1) {
        $bytes /= 1024;
        $i++;
    }
    return round($bytes, 2) . ' ' . $units[$i];
}

/**
 * Get list of configured airport IDs
 */
function getConfiguredAirportIds(): array {
    $config = loadConfig(false); // Don't use cache to get fresh data
    if ($config === null || !isset($config['airports']) || !is_array($config['airports'])) {
        return [];
    }
    return array_keys($config['airports']);
}

/**
 * Cleanup files matching a glob pattern older than max age
 */
function cleanupFilesByPattern(
    string $pattern,
    int $maxAge,
    string $description,
    array &$stats,
    bool $dryRun,
    bool $verbose,
    array $excludePatterns = []
): void {
    $files = glob($pattern);
    if ($files === false || empty($files)) {
        if ($verbose) {
            echo "  {$description}: No files found\n";
        }
        return;
    }
    
    $now = time();
    $deleted = 0;
    $bytesFreed = 0;
    
    foreach ($files as $file) {
        // Check exclusions
        $excluded = false;
        foreach ($excludePatterns as $excludePattern) {
            if (fnmatch($excludePattern, basename($file))) {
                $excluded = true;
                break;
            }
        }
        if ($excluded) {
            continue;
        }
        
        $stats['files_checked']++;
        $mtime = @filemtime($file);
        
        if ($mtime === false) {
            continue;
        }
        
        $age = $now - $mtime;
        if ($age > $maxAge) {
            $size = @filesize($file) ?: 0;
            
            if ($verbose) {
                echo "  🗑️  " . basename($file) . " (age: " . formatAge($age) . ", size: " . formatBytes($size) . ")\n";
            }
            
            if (!$dryRun) {
                if (@unlink($file)) {
                    $deleted++;
                    $bytesFreed += $size;
                } else {
                    $stats['errors']++;
                    echo "  ❌ Failed to delete: {$file}\n";
                }
            } else {
                $deleted++;
                $bytesFreed += $size;
            }
        }
    }
    
    $stats['files_deleted'] += $deleted;
    $stats['bytes_freed'] += $bytesFreed;
    
    if ($deleted > 0 || $verbose) {
        $action = $dryRun ? 'Would delete' : 'Deleted';
        echo "  {$description}: {$action} {$deleted} files (" . formatBytes($bytesFreed) . ")\n";
    }
}

/**
 * Cleanup a single file if older than max age
 */
function cleanupFilesByAge(
    string $file,
    int $maxAge,
    string $description,
    array &$stats,
    bool $dryRun,
    bool $verbose
): void {
    if (!file_exists($file)) {
        if ($verbose) {
            echo "  {$description}: File not found\n";
        }
        return;
    }
    
    $stats['files_checked']++;
    $now = time();
    $mtime = @filemtime($file);
    
    if ($mtime === false) {
        return;
    }
    
    $age = $now - $mtime;
    if ($age > $maxAge) {
        $size = @filesize($file) ?: 0;
        
        if ($verbose) {
            echo "  🗑️  " . basename($file) . " (age: " . formatAge($age) . ", size: " . formatBytes($size) . ")\n";
        }
        
        if (!$dryRun) {
            if (@unlink($file)) {
                $stats['files_deleted']++;
                $stats['bytes_freed'] += $size;
                echo "  {$description}: Deleted (" . formatBytes($size) . ")\n";
            } else {
                $stats['errors']++;
                echo "  ❌ Failed to delete: {$file}\n";
            }
        } else {
            $stats['files_deleted']++;
            $stats['bytes_freed'] += $size;
            echo "  {$description}: Would delete (" . formatBytes($size) . ")\n";
        }
    } elseif ($verbose) {
        echo "  {$description}: OK (age: " . formatAge($age) . ")\n";
    }
}

/**
 * Cleanup webcam history frame directories
 */
function cleanupWebcamHistoryFrames(
    string $webcamsDir,
    int $maxAge,
    array &$stats,
    bool $dryRun,
    bool $verbose
): void {
    // New structure: cache/webcams/{airportId}/{camIndex}/history/
    // Find all history directories using the new nested structure
    $historyDirs = glob($webcamsDir . '/*/*/history');
    if ($historyDirs === false || empty($historyDirs)) {
        if ($verbose) {
            echo "  Webcam history frames: No history directories found\n";
        }
        return;
    }
    
    $now = time();
    $deleted = 0;
    $bytesFreed = 0;
    
    foreach ($historyDirs as $dir) {
        // Clean up all image formats (jpg, webp) and variants
        $files = array_merge(
            glob($dir . '/*.jpg') ?: [],
            glob($dir . '/*.webp') ?: []
        );
        
        if (empty($files)) {
            continue;
        }
        
        foreach ($files as $file) {
            $stats['files_checked']++;
            $mtime = @filemtime($file);
            
            if ($mtime === false) {
                continue;
            }
            
            $age = $now - $mtime;
            if ($age > $maxAge) {
                $size = @filesize($file) ?: 0;
                
                if ($verbose) {
                    // Show airport/cam/history/filename for clarity
                    $parts = explode('/', $dir);
                    $camIndex = $parts[count($parts) - 2] ?? '?';
                    $airportId = $parts[count($parts) - 3] ?? '?';
                    echo "  🗑️  {$airportId}/{$camIndex}/history/" . basename($file) . " (age: " . formatAge($age) . ")\n";
                }
                
                if (!$dryRun) {
                    if (@unlink($file)) {
                        $deleted++;
                        $bytesFreed += $size;
                    } else {
                        $stats['errors']++;
                    }
                } else {
                    $deleted++;
                    $bytesFreed += $size;
                }
            }
        }
    }
    
    $stats['files_deleted'] += $deleted;
    $stats['bytes_freed'] += $bytesFreed;
    
    if ($deleted > 0 || $verbose) {
        $action = $dryRun ? 'Would delete' : 'Deleted';
        echo "  Webcam history frames (backup): {$action} {$deleted} files (" . formatBytes($bytesFreed) . ")\n";
    }
}

/**
 * Cleanup stale entries in daily tracking files
 *
 * Works with both per-airport files (cache/peak_gusts/{airport}.json,
 * cache/temp_extremes/{airport}.json) and legacy single-file format.
 */
function cleanupDailyTrackingEntries(
    string $file,
    int $maxAge,
    string $description,
    array &$stats,
    bool $dryRun,
    bool $verbose
): void {
    if (!file_exists($file)) {
        if ($verbose) {
            echo "  {$description}: File not found\n";
        }
        return;
    }
    
    $content = @file_get_contents($file);
    if ($content === false) {
        return;
    }
    
    $data = @json_decode($content, true);
    if (!is_array($data)) {
        return;
    }
    
    $now = time();
    $cutoffDate = date('Y-m-d', $now - $maxAge);
    $removed = 0;
    $modified = false;
    
    foreach ($data as $key => $value) {
        // Check if key is a date (YYYY-MM-DD format)
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $key)) {
            if ($key < $cutoffDate) {
                unset($data[$key]);
                $removed++;
                $modified = true;
                if ($verbose) {
                    echo "  🗑️  Entry: {$key}\n";
                }
            }
        }
    }
    
    if ($modified && !$dryRun) {
        @file_put_contents($file, json_encode($data, JSON_PRETTY_PRINT), LOCK_EX);
    }
    
    if ($removed > 0 || $verbose) {
        $action = $dryRun ? 'Would remove' : 'Removed';
        echo "  {$description}: {$action} {$removed} entries\n";
    }
}

/**
 * Cleanup stale entries in backoff.json
 */
function cleanupBackoffEntries(
    string $file,
    int $maxAge,
    array &$stats,
    bool $dryRun,
    bool $verbose
): void {
    if (!file_exists($file)) {
        if ($verbose) {
            echo "  Backoff entries: File not found\n";
        }
        return;
    }
    
    $content = @file_get_contents($file);
    if ($content === false) {
        return;
    }
    
    $data = @json_decode($content, true);
    if (!is_array($data)) {
        return;
    }
    
    $now = time();
    $removed = 0;
    $modified = false;
    
    foreach ($data as $key => $entry) {
        if (!is_array($entry)) {
            continue;
        }
        
        // Check last_attempt timestamp
        $lastAttempt = $entry['last_attempt'] ?? 0;
        $nextAllowed = $entry['next_allowed_time'] ?? 0;
        
        // Remove if both are old and circuit is not open
        if ($lastAttempt > 0 && ($now - $lastAttempt) > $maxAge && $nextAllowed <= $now) {
            unset($data[$key]);
            $removed++;
            $modified = true;
            if ($verbose) {
                echo "  🗑️  Backoff entry: {$key} (last attempt: " . formatAge($now - $lastAttempt) . " ago)\n";
            }
        }
    }
    
    if ($modified && !$dryRun) {
        @file_put_contents($file, json_encode($data, JSON_PRETTY_PRINT), LOCK_EX);
    }
    
    if ($removed > 0 || $verbose) {
        $action = $dryRun ? 'Would remove' : 'Removed';
        echo "  Backoff entries (backup): {$action} {$removed} entries\n";
    }
}

/**
 * Cleanup files for airports no longer in configuration
 */
function cleanupOrphanedAirportFiles(
    string $cacheDir,
    array $configuredAirports,
    int $maxAge,
    array &$stats,
    bool $dryRun,
    bool $verbose
): void {
    if (empty($configuredAirports)) {
        echo "  ⚠️  No configured airports found, skipping orphan cleanup\n";
        return;
    }
    
    $now = time();
    $orphanedDeleted = 0;
    $orphanedBytes = 0;
    
    // Patterns that include airport ID with their directories
    $patterns = [
        // New structure: cache/weather/{airport}.json
        [CACHE_WEATHER_DIR . '/*.json', '/\/([a-z0-9]+)\.json$/i'],
        // New structure: cache/weather/history/{airport}.json
        [CACHE_WEATHER_HISTORY_DIR . '/*.json', '/\/([a-z0-9]+)\.json$/i'],
        // Outage files in cache root
        [CACHE_BASE_DIR . '/outage_*.json', '/outage_([a-z0-9]+)\.json$/i'],
        // NOTAM files
        [CACHE_NOTAM_DIR . '/*.json', '/\/([a-z0-9]+)\.json$/i'],
    ];
    
    foreach ($patterns as [$globPattern, $regex]) {
        $files = glob($globPattern);
        if ($files === false) {
            continue;
        }
        
        foreach ($files as $file) {
            $stats['files_checked']++;
            
            if (preg_match($regex, $file, $matches)) {
                $airportId = strtolower($matches[1]);
                
                // Skip if airport is configured
                if (in_array($airportId, array_map('strtolower', $configuredAirports))) {
                    continue;
                }
                
                // Check age - only delete if older than threshold
                $mtime = @filemtime($file);
                if ($mtime === false) {
                    continue;
                }
                
                $age = $now - $mtime;
                if ($age > $maxAge) {
                    $size = @filesize($file) ?: 0;
                    
                    if ($verbose) {
                        echo "  🗑️  Orphaned: " . basename($file) . " (airport: {$airportId}, age: " . formatAge($age) . ")\n";
                    }
                    
                    if (!$dryRun) {
                        if (@unlink($file)) {
                            $orphanedDeleted++;
                            $orphanedBytes += $size;
                        } else {
                            $stats['errors']++;
                        }
                    } else {
                        $orphanedDeleted++;
                        $orphanedBytes += $size;
                    }
                }
            }
        }
    }
    
    // Check webcam directories for orphaned airports
    // New structure: cache/webcams/{airportId}/{camIndex}/
    $webcamDirs = glob($cacheDir . '/webcams/*');
    if ($webcamDirs !== false) {
        foreach ($webcamDirs as $dir) {
            if (!is_dir($dir)) {
                continue;
            }
            
            // Extract airport ID from directory name (format: {airportId})
            $basename = basename($dir);
            if (preg_match('/^[a-z0-9]+$/i', $basename)) {
                $airportId = strtolower($basename);
                
                if (!in_array($airportId, array_map('strtolower', $configuredAirports))) {
                    // Get directory age from newest file
                    $newestMtime = getNewestFileMtime($dir);
                    if ($newestMtime === false) {
                        continue;
                    }
                    
                    $age = $now - $newestMtime;
                    if ($age > $maxAge) {
                        $dirSize = getDirectorySize($dir);
                        
                        if ($verbose) {
                            echo "  🗑️  Orphaned webcam dir: {$basename} (airport: {$airportId}, age: " . formatAge($age) . ")\n";
                        }
                        
                        if (!$dryRun) {
                            if (deleteDirectory($dir)) {
                                $orphanedDeleted++;
                                $orphanedBytes += $dirSize;
                            } else {
                                $stats['errors']++;
                            }
                        } else {
                            $orphanedDeleted++;
                            $orphanedBytes += $dirSize;
                        }
                    }
                }
            }
        }
    }
    
    $stats['files_deleted'] += $orphanedDeleted;
    $stats['bytes_freed'] += $orphanedBytes;
    
    if ($orphanedDeleted > 0 || $verbose) {
        $action = $dryRun ? 'Would delete' : 'Deleted';
        echo "  Orphaned airport files: {$action} {$orphanedDeleted} items (" . formatBytes($orphanedBytes) . ")\n";
    }
}

/**
 * Cleanup empty directories (recursive, depth-first)
 * 
 * Recursively scans directories and removes empty ones from the bottom up.
 * This ensures that nested empty directories are cleaned in a single pass.
 * For example: webcams/kspb/0/history/ -> webcams/kspb/0/ -> webcams/kspb/
 * 
 * @param string $path Root path to scan
 * @param array &$stats Statistics array
 * @param bool $dryRun If true, don't actually delete
 * @param bool $verbose If true, print detailed output
 * @param bool $isRoot If true, this is the top-level call (don't delete the root itself)
 */
function cleanupEmptyDirectories(
    string $path,
    array &$stats,
    bool $dryRun,
    bool $verbose,
    bool $isRoot = true
): void {
    if (!is_dir($path)) {
        return;
    }
    
    static $removed = 0;
    if ($isRoot) {
        $removed = 0;
    }
    
    $dirs = glob($path . '/*', GLOB_ONLYDIR);
    
    if ($dirs === false) {
        return;
    }
    
    // Depth-first: recurse into subdirectories first
    foreach ($dirs as $dir) {
        cleanupEmptyDirectories($dir, $stats, $dryRun, $verbose, false);
    }
    
    // Now check all entries (files and remaining directories)
    foreach ($dirs as $dir) {
        if (!is_dir($dir)) {
            continue; // Already removed in recursion
        }
        
        $files = @scandir($dir);
        if ($files === false) {
            continue;
        }
        
        // Remove . and ..
        $files = array_diff($files, ['.', '..']);
        
        if (empty($files)) {
            if ($verbose) {
                // Show relative path from cache root for clarity
                $relativePath = str_replace(CACHE_BASE_DIR . '/', '', $dir);
                echo "  🗑️  Empty directory: {$relativePath}\n";
            }
            
            if (!$dryRun) {
                if (@rmdir($dir)) {
                    $removed++;
                } else {
                    $stats['errors']++;
                }
            } else {
                $removed++;
            }
        }
    }
    
    if ($isRoot && $removed > 0) {
        $action = $dryRun ? 'Would remove' : 'Removed';
        echo "  Empty directories in " . basename($path) . ": {$action} {$removed}\n";
    }
}

/**
 * Cleanup empty directories in uploads, but preserve push webcam directories
 * 
 * Push webcam upload directories (e.g., /cache/ftp/keul/keulcam1/) must exist
 * even when empty because vsftpd per-user configs point to them. Deleting them
 * breaks FTP uploads until the directory is recreated.
 * 
 * This function only removes directories for airports NOT in the configuration,
 * preserving all directories for configured airports (which may have push webcams).
 */
function cleanupEmptyDirectoriesExcludingUploads(
    string $path,
    array $configuredAirports,
    array &$stats,
    bool $dryRun,
    bool $verbose
): void {
    if (!is_dir($path)) {
        return;
    }
    
    $removed = 0;
    $configuredLower = array_map('strtolower', $configuredAirports);
    
    // Get airport-level directories (e.g., /cache/ftp/keul/)
    $airportDirs = glob($path . '/*', GLOB_ONLYDIR);
    if ($airportDirs === false) {
        return;
    }
    
    foreach ($airportDirs as $airportDir) {
        $airportId = strtolower(basename($airportDir));
        
        // Skip directories for configured airports - these may have push webcams
        if (in_array($airportId, $configuredLower)) {
            if ($verbose) {
                echo "  ℹ️  Preserving upload dir for configured airport: {$airportId}\n";
            }
            continue;
        }
        
        // For unconfigured airports, remove if empty (orphan cleanup)
        $files = @scandir($airportDir);
        if ($files === false) {
            continue;
        }
        $files = array_diff($files, ['.', '..']);
        
        if (empty($files)) {
            if ($verbose) {
                echo "  🗑️  Empty orphan upload directory: {$airportId}\n";
            }
            
            if (!$dryRun) {
                if (@rmdir($airportDir)) {
                    $removed++;
                } else {
                    $stats['errors']++;
                }
            } else {
                $removed++;
            }
        } else {
            // Recurse into camera subdirectories for unconfigured airports
            $camDirs = glob($airportDir . '/*', GLOB_ONLYDIR);
            if ($camDirs !== false) {
                foreach ($camDirs as $camDir) {
                    $camFiles = @scandir($camDir);
                    if ($camFiles === false) {
                        continue;
                    }
                    $camFiles = array_diff($camFiles, ['.', '..']);
                    
                    if (empty($camFiles)) {
                        if ($verbose) {
                            echo "  🗑️  Empty orphan upload cam directory: {$airportId}/" . basename($camDir) . "\n";
                        }
                        
                        if (!$dryRun) {
                            if (@rmdir($camDir)) {
                                $removed++;
                            } else {
                                $stats['errors']++;
                            }
                        } else {
                            $removed++;
                        }
                    }
                }
            }
        }
    }
    
    if ($removed > 0 || $verbose) {
        $action = $dryRun ? 'Would remove' : 'Removed';
        echo "  Empty orphan upload directories: {$action} {$removed}\n";
    }
}

/**
 * Cleanup empty directories in SFTP, but preserve push webcam directories
 * 
 * SFTP directories are at /var/sftp/{username}/ and must exist for chroot to work.
 * Only remove directories for usernames not associated with any configured push webcam.
 */
function cleanupEmptyDirectoriesExcludingSftp(
    string $path,
    array $configuredAirports,
    array &$stats,
    bool $dryRun,
    bool $verbose
): void {
    if (!is_dir($path)) {
        return;
    }
    
    $removed = 0;
    
    // Build list of configured push webcam usernames
    $configuredUsernames = [];
    $config = loadConfig(false);
    if ($config !== null && isset($config['airports']) && is_array($config['airports'])) {
        foreach ($config['airports'] as $airportId => $airport) {
            if (!is_array($airport)) continue;
            $webcams = $airport['webcams'] ?? [];
            if (!is_array($webcams)) continue;
            foreach ($webcams as $webcam) {
                if (!is_array($webcam)) continue;
                if (($webcam['type'] ?? '') === 'push') {
                    $username = $webcam['push_config']['username'] ?? null;
                    if ($username) {
                        $configuredUsernames[] = strtolower($username);
                    }
                }
            }
        }
    }
    
    // Get user-level directories (e.g., /var/sftp/kspbcam1/)
    $userDirs = glob($path . '/*', GLOB_ONLYDIR);
    if ($userDirs === false) {
        return;
    }
    
    foreach ($userDirs as $userDir) {
        $username = strtolower(basename($userDir));
        
        // Skip directories for configured push webcam usernames
        if (in_array($username, $configuredUsernames)) {
            if ($verbose) {
                echo "  ℹ️  Preserving SFTP dir for configured user: {$username}\n";
            }
            continue;
        }
        
        // For unconfigured users, check if entirely empty (including files/ subdir)
        $filesDir = $userDir . '/files';
        $filesEmpty = true;
        if (is_dir($filesDir)) {
            $files = @scandir($filesDir);
            if ($files !== false) {
                $files = array_diff($files, ['.', '..']);
                $filesEmpty = empty($files);
            }
        }
        
        if ($filesEmpty) {
            if ($verbose) {
                echo "  🗑️  Empty orphan SFTP directory: {$username}\n";
            }
            
            if (!$dryRun) {
                // Remove files/ first, then the user dir
                if (is_dir($filesDir)) {
                    @rmdir($filesDir);
                }
                if (@rmdir($userDir)) {
                    $removed++;
                } else {
                    $stats['errors']++;
                }
            } else {
                $removed++;
            }
        }
    }
    
    if ($removed > 0 || $verbose) {
        $action = $dryRun ? 'Would remove' : 'Removed';
        echo "  Empty orphan SFTP directories: {$action} {$removed}\n";
    }
}

/**
 * Format age in human-readable format
 */
function formatAge(int $seconds): string {
    if ($seconds < 60) {
        return "{$seconds}s";
    } elseif ($seconds < 3600) {
        return round($seconds / 60) . "m";
    } elseif ($seconds < 86400) {
        return round($seconds / 3600, 1) . "h";
    } else {
        return round($seconds / 86400, 1) . "d";
    }
}

/**
 * Get newest file modification time in a directory
 */
function getNewestFileMtime(string $dir): int|false {
    $newest = 0;
    $files = glob($dir . '/*');
    
    if ($files === false || empty($files)) {
        return false;
    }
    
    foreach ($files as $file) {
        $mtime = @filemtime($file);
        if ($mtime !== false && $mtime > $newest) {
            $newest = $mtime;
        }
    }
    
    return $newest > 0 ? $newest : false;
}

/**
 * Get total size of a directory
 */
function getDirectorySize(string $dir): int {
    $size = 0;
    $files = glob($dir . '/*');
    
    if ($files === false) {
        return 0;
    }
    
    foreach ($files as $file) {
        if (is_file($file)) {
            $size += @filesize($file) ?: 0;
        } elseif (is_dir($file)) {
            $size += getDirectorySize($file);
        }
    }
    
    return $size;
}

/**
 * Recursively delete a directory
 */
function deleteDirectory(string $dir): bool {
    if (!is_dir($dir)) {
        return false;
    }
    
    $files = glob($dir . '/*');
    if ($files !== false) {
        foreach ($files as $file) {
            if (is_dir($file)) {
                deleteDirectory($file);
            } else {
                @unlink($file);
            }
        }
    }
    
    return @rmdir($dir);
}

/**
 * Ensure upload directories exist for all configured push webcams
 * 
 * This is a safety net that runs after cleanup to ensure push webcam
 * upload directories exist. These directories are created by sync-push-config.php
 * on container startup, but this ensures they're recreated if something
 * accidentally deletes them.
 * 
 * Handles both FTP directories (/cache/ftp/{airport}/{username}/)
 * and SFTP directories (/var/sftp/{username}/files/).
 * 
 * Runs as www-data, so can only create directories where www-data has write access.
 * SFTP directories require root to create (done by sync-push-config.php).
 * 
 * @param array &$stats Statistics array
 * @param bool $dryRun If true, don't actually create
 * @param bool $verbose If true, print detailed output
 */
function ensurePushWebcamDirectories(array &$stats, bool $dryRun, bool $verbose): void {
    $config = loadConfig(false); // Don't use cache to get fresh data
    if ($config === null || !isset($config['airports']) || !is_array($config['airports'])) {
        if ($verbose) {
            echo "  ⚠️  No airports configured, skipping push webcam directory check\n";
        }
        return;
    }
    
    $ftpCreated = 0;
    $sftpMissing = 0;
    $errors = 0;
    
    foreach ($config['airports'] as $airportId => $airport) {
        if (!is_array($airport)) {
            continue;
        }
        
        $webcams = $airport['webcams'] ?? [];
        if (!is_array($webcams)) {
            continue;
        }
        
        foreach ($webcams as $camIndex => $webcam) {
            if (!is_array($webcam)) {
                continue;
            }
            
            // Only process push webcams
            if (($webcam['type'] ?? '') !== 'push') {
                continue;
            }
            
            $pushConfig = $webcam['push_config'] ?? [];
            $username = $pushConfig['username'] ?? null;
            
            if (!$username) {
                continue;
            }
            
            // Check FTP upload directory
            // Note: Do NOT create here - cleanup-cache runs as www-data and cannot chown to ftp:www-data.
            // vsftpd requires ftp:www-data ownership for uploads. Use sync-push-config.php (runs as root).
            $ftpDir = getWebcamFtpUploadDir($airportId, $username);
            if (!is_dir($ftpDir)) {
                $ftpCreated++;
                if ($verbose) {
                    echo "  📁 Missing FTP directory: " . strtolower($airportId) . "/{$username}\n";
                    echo "     Run: php scripts/sync-push-config.php (as root)\n";
                }
                if (!$dryRun) {
                    aviationwx_log('warning', 'missing push webcam FTP directory (run sync-push-config.php as root)', [
                        'airport' => $airportId,
                        'cam' => $camIndex,
                        'username' => $username,
                        'path' => $ftpDir
                    ], 'app');
                }
            } elseif ($verbose) {
                echo "  ✅ FTP directory exists: " . strtolower($airportId) . "/{$username}\n";
            }
            
            // Check SFTP upload directory (can't create - requires root)
            $sftpDir = getWebcamSftpUploadDir($username);
            if (!is_dir($sftpDir)) {
                $sftpMissing++;
                if ($verbose) {
                    echo "  ⚠️  Missing SFTP directory: {$username}/files/ (requires sync-push-config.php)\n";
                }
            } elseif ($verbose) {
                echo "  ✅ SFTP directory exists: {$username}/files/\n";
            }
        }
    }
    
    if ($ftpCreated > 0) {
        echo "  FTP directories: {$ftpCreated} missing (run sync-push-config.php as root to create)\n";
        
        if (!$dryRun) {
            aviationwx_log('warning', 'missing push webcam FTP directories (run sync-push-config.php as root)', [
                'count' => $ftpCreated
            ], 'app');
        }
    } elseif ($verbose) {
        echo "  FTP directories: All directories exist\n";
    }
    
    if ($sftpMissing > 0) {
        echo "  ⚠️  SFTP directories: {$sftpMissing} missing (run sync-push-config.php as root to create)\n";
        aviationwx_log('warning', 'missing SFTP directories detected', [
            'count' => $sftpMissing,
            'action' => 'run sync-push-config.php as root'
        ], 'app');
    } elseif ($verbose) {
        echo "  SFTP directories: All directories exist\n";
    }
}

// =============================================================================
// MEMORY METRICS CLEANUP
// =============================================================================

/**
 * Clean up old memory metrics hourly files
 * 
 * @param int $daysToKeep Number of days to retain
 * @param bool $dryRun If true, only show what would be deleted
 * @param bool $verbose Verbose output
 * @return void
 */
function cleanupMemoryMetrics(int $daysToKeep, bool $dryRun, bool $verbose): void {
    require_once __DIR__ . '/../lib/memory-metrics.php';
    
    echo "\n";
    echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n";
    echo "Memory Metrics Cleanup\n";
    echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n";
    
    if (!$dryRun) {
        $deleted = memory_metrics_cleanup($daysToKeep);
        echo "  Deleted {$deleted} old memory metrics files (>{$daysToKeep} days)\n";
    } else {
        // Dry run - count files that would be deleted
        $memoryDir = CACHE_BASE_DIR . '/metrics/memory';
        if (!is_dir($memoryDir)) {
            echo "  Memory metrics directory doesn't exist yet\n";
            return;
        }
        
        $cutoff = time() - ($daysToKeep * 86400);
        $count = 0;
        
        $files = glob($memoryDir . '/*.json');
        if ($files !== false) {
            foreach ($files as $file) {
                $mtime = @filemtime($file);
                if ($mtime !== false && $mtime < $cutoff) {
                    $count++;
                    if ($verbose) {
                        echo "  Would delete: " . basename($file) . "\n";
                    }
                }
            }
        }
        
        echo "  Would delete {$count} old memory metrics files (>{$daysToKeep} days)\n";
    }
}

