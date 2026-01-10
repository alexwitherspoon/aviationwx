<?php
/**
 * Push Webcam File Processor
 * Processes uploaded images from push cameras
 * Runs via cron every minute
 */

require_once __DIR__ . '/../lib/config.php';
require_once __DIR__ . '/../lib/logger.php';
require_once __DIR__ . '/../lib/webcam-format-generation.php';
require_once __DIR__ . '/../lib/webcam-history.php';
require_once __DIR__ . '/../lib/exif-utils.php';
require_once __DIR__ . '/../lib/webcam-error-detector.php';
require_once __DIR__ . '/../lib/cache-paths.php';
require_once __DIR__ . '/../lib/webcam-upload-metrics.php';

// Verify exiftool is available at startup (required for EXIF validation)
try {
    requireExiftool();
} catch (RuntimeException $e) {
    $errorMsg = "FATAL: " . $e->getMessage();
    if (php_sapi_name() === 'cli') {
        fwrite(STDERR, $errorMsg . "\n");
    } else {
        echo "<p style='color:red;'>$errorMsg</p>";
    }
    exit(1);
}

// Set up invocation tracking
$invocationId = aviationwx_get_invocation_id();
$triggerInfo = aviationwx_detect_trigger_type();

aviationwx_log('info', 'push-webcam processor started', [
    'invocation_id' => $invocationId,
    'trigger' => $triggerInfo['trigger'],
    'context' => $triggerInfo['context']
], 'app');

/**
 * Get camera refresh seconds (with minimum of 60)
 * 
 * Determines refresh interval for a camera, checking camera-specific setting,
 * airport-level setting, or default. Enforces minimum of 60 seconds.
 * 
 * @param array $cam Camera configuration array
 * @param array $airport Airport configuration array
 * @return int Refresh interval in seconds (minimum 60)
 */
function getCameraRefreshSeconds($cam, $airport) {
    if (isset($cam['refresh_seconds'])) {
        return max(60, intval($cam['refresh_seconds']));
    }
    if (isset($airport['webcam_refresh_seconds'])) {
        return max(60, intval($airport['webcam_refresh_seconds']));
    }
    $default = getDefaultWebcamRefresh();
    return max(60, $default);
}

/**
 * Check if directory is writable
 * 
 * Verifies directory exists and is writable by attempting to create a test file.
 * More reliable than is_writable() alone, which may have permission issues.
 * 
 * @param string $dir Directory path to check
 * @return bool True if directory exists and is writable, false otherwise
 */
function isDirectoryWritable($dir) {
    if (!is_dir($dir)) {
        return false;
    }
    
    if (!is_writable($dir)) {
        return false;
    }
    
    // Test write by creating temp file
    $testFile = $dir . '/.write_test_' . time();
    $test = @file_put_contents($testFile, 'test');
    if ($test !== false) {
        @unlink($testFile);
        return true;
    }
    
    return false;
}

/**
 * Check disk space availability
 * 
 * Checks free disk space and returns status based on percentage free.
 * Used to prevent processing when disk is nearly full.
 * 
 * @param string $path Path to check disk space for
 * @return array {
 *   'status' => string ('ok', 'warning', 'critical', or 'unknown'),
 *   'percent' => float|null (percentage free, null if unknown)
 * }
 */
function checkDiskSpace($path) {
    $freeBytes = @disk_free_space($path);
    $totalBytes = @disk_total_space($path);
    
    if ($freeBytes === false || $totalBytes === false) {
        return ['status' => 'unknown', 'percent' => null];
    }
    
    $freePercent = ($freeBytes / $totalBytes) * 100;
    
    if ($freePercent < 5) {
        return ['status' => 'critical', 'percent' => $freePercent];
    } elseif ($freePercent < 10) {
        return ['status' => 'warning', 'percent' => $freePercent];
    }
    
    return ['status' => 'ok', 'percent' => $freePercent];
}

/**
 * Check inode usage
 * 
 * Checks inode (file system entry) usage to detect when filesystem is running
 * out of inodes (even if disk space is available). Critical for systems with
 * many small files.
 * 
 * @param string $path Path to check inode usage for
 * @return array {
 *   'status' => string ('ok', 'warning', 'critical', or 'unknown'),
 *   'percent' => float|null (percentage used, null if unknown)
 * }
 */
function checkInodeUsage($path) {
    if (function_exists('statvfs')) {
        $inodeInfo = @statvfs($path);
        if ($inodeInfo) {
            $freeInodes = $inodeInfo['ffree'];
            $totalInodes = $inodeInfo['files'];
            if ($totalInodes > 0) {
                $usedPercent = (($totalInodes - $freeInodes) / $totalInodes) * 100;
                
                if ($usedPercent > 95) {
                    return ['status' => 'critical', 'percent' => $usedPercent];
                } elseif ($usedPercent > 90) {
                    return ['status' => 'warning', 'percent' => $usedPercent];
                }
                
                return ['status' => 'ok', 'percent' => $usedPercent];
            }
        }
    }
    
    return ['status' => 'unknown', 'percent' => null];
}

/**
 * Recursively find all image files in a directory and its subdirectories
 * 
 * Handles FTP/camera uploads that create date-based subfolder structures
 * like /2026/01/06/image.jpg. Includes depth limit to prevent runaway recursion.
 * 
 * @param string $dir Base directory to search
 * @param int $maxDepth Maximum recursion depth (default 10, handles year/month/day structures)
 * @param int $currentDepth Current recursion depth (internal use)
 * @return array List of image file paths found
 */
function recursiveGlobImages($dir, $maxDepth = 10, $currentDepth = 0) {
    $files = [];
    
    if (!is_dir($dir) || $currentDepth > $maxDepth) {
        return $files;
    }
    
    // Normalize directory path to ensure trailing slash
    $dir = rtrim($dir, '/') . '/';
    
    // Find images in current directory
    $images = glob($dir . '*.{jpg,jpeg,png,webp}', GLOB_BRACE);
    if ($images !== false) {
        $files = array_merge($files, $images);
    }
    
    // Recurse into subdirectories
    $subdirs = glob($dir . '*', GLOB_ONLYDIR);
    if ($subdirs !== false) {
        foreach ($subdirs as $subdir) {
            $files = array_merge($files, recursiveGlobImages($subdir, $maxDepth, $currentDepth + 1));
        }
    }
    
    return $files;
}

/**
 * Recursively clean up empty directories
 * 
 * Removes empty subdirectories from bottom-up. Used after cleaning files
 * to prevent orphaned empty folder structures from date-based uploads.
 * 
 * @param string $dir Directory to clean
 * @param string $stopAt Stop cleaning at this directory (don't delete it or parents)
 * @return int Number of directories removed
 */
function cleanupEmptyDirectories($dir, $stopAt) {
    $removed = 0;
    
    if (!is_dir($dir)) {
        return $removed;
    }
    
    // Normalize paths for comparison using realpath
    // realpath() returns false if path doesn't exist, so handle gracefully
    $realDir = realpath($dir);
    $realStopAt = realpath($stopAt);
    
    if ($realDir === false || $realStopAt === false) {
        // Can't resolve paths - skip to avoid accidental deletion
        return $removed;
    }
    
    $realDir = rtrim($realDir, '/');
    $realStopAt = rtrim($realStopAt, '/');
    
    // Don't delete the stop directory itself
    if ($realDir === $realStopAt) {
        return $removed;
    }
    
    // Don't delete parent directories of stopAt
    // If stopAt starts with dir/, then dir is a parent of stopAt
    if (strpos($realStopAt, $realDir . '/') === 0) {
        return $removed;
    }
    
    // First, recurse into subdirectories (depth-first to clean children before parents)
    $subdirs = glob($realDir . '/*', GLOB_ONLYDIR);
    if ($subdirs !== false) {
        foreach ($subdirs as $subdir) {
            $removed += cleanupEmptyDirectories($subdir, $stopAt);
        }
    }
    
    // Check if directory is now empty (no files, no subdirs)
    $contents = @scandir($realDir);
    if ($contents !== false) {
        // Filter out . and ..
        $contents = array_diff($contents, ['.', '..']);
        if (empty($contents)) {
            // Directory is empty - remove it
            if (@rmdir($realDir)) {
                $removed++;
                aviationwx_log('debug', 'removed empty directory', ['dir' => $realDir], 'app');
            }
        }
    }
    
    return $removed;
}

/**
 * Get maximum age for upload files before abandonment
 * 
 * Files older than this are considered stuck/abandoned and will be deleted.
 * Prevents worker from repeatedly checking files that will never complete.
 * 
 * @param array|null $cam Camera config
 * @return int Max age in seconds
 */
function getUploadFileMaxAge($cam) {
    // Allow per-camera override
    if ($cam && isset($cam['push_config']['upload_file_max_age_seconds'])) {
        $override = intval($cam['push_config']['upload_file_max_age_seconds']);
        return max(
            MIN_UPLOAD_FILE_MAX_AGE_SECONDS,
            min(MAX_UPLOAD_FILE_MAX_AGE_SECONDS, $override)
        );
    }
    
    return UPLOAD_FILE_MAX_AGE_SECONDS;
}

/**
 * Get stability check timeout for this camera
 * 
 * How long to wait in the stability checking loop before giving up.
 * This is NOT the overall worker timeout - just for the upload stability check.
 * 
 * @param array|null $cam Camera config
 * @return int Timeout in seconds
 */
function getStabilityCheckTimeout($cam) {
    // Allow per-camera override
    if ($cam && isset($cam['push_config']['stability_check_timeout_seconds'])) {
        $override = intval($cam['push_config']['stability_check_timeout_seconds']);
        return max(
            MIN_STABILITY_CHECK_TIMEOUT_SECONDS,
            min(MAX_STABILITY_CHECK_TIMEOUT_SECONDS, $override)
        );
    }
    
    return DEFAULT_STABILITY_CHECK_TIMEOUT_SECONDS;
}

/**
 * Get required stable checks based on historical performance
 * 
 * Uses P95 of stability times from last N uploads to determine
 * how many consecutive stable checks are needed.
 * Adjusts based on rejection rate (higher rejections = more conservative).
 * 
 * Metrics are persisted to disk and loaded on startup to survive restarts.
 * 
 * @param string $airportId Airport ID
 * @param int $camIndex Camera index
 * @return int Number of required consecutive stable checks
 */
function getRequiredStableChecks($airportId, $camIndex) {
    $key = "stability_metrics_{$airportId}_{$camIndex}";
    $metrics = apcu_fetch($key);
    
    // If not in APCu, try loading from disk
    if (!$metrics) {
        $metrics = loadStabilityMetricsFromDisk($airportId, $camIndex);
        if ($metrics) {
            // Restore to APCu for faster access
            apcu_store($key, $metrics, 7 * 86400);
        }
    }
    
    // Cold start: use conservative default
    if (!$metrics || count($metrics['stability_times']) < MIN_SAMPLES_FOR_OPTIMIZATION) {
        return DEFAULT_STABLE_CHECKS;
    }
    
    // Calculate rejection rate
    $totalUploads = $metrics['accepted'] + $metrics['rejected'];
    $rejectionRate = $totalUploads > 0 ? ($metrics['rejected'] / $totalUploads) : 0;
    
    // If rejection rate is high, be more conservative
    if ($rejectionRate > REJECTION_RATE_THRESHOLD_HIGH) {
        aviationwx_log('info', 'high rejection rate, using conservative checks', [
            'airport' => $airportId,
            'cam' => $camIndex,
            'rejection_rate' => round($rejectionRate * 100, 1) . '%',
            'required_checks' => DEFAULT_STABLE_CHECKS
        ], 'app');
        return DEFAULT_STABLE_CHECKS;
    }
    
    // Calculate P95 of stability times
    $times = $metrics['stability_times'];
    sort($times);
    $p95Index = (int) ceil(0.95 * count($times)) - 1;
    $p95Time = $times[max(0, $p95Index)];
    
    // Convert P95 time to number of checks with safety margin
    $checksNeeded = ceil(($p95Time / (STABILITY_CHECK_INTERVAL_MS / 1000)) * P95_SAFETY_MARGIN);
    
    // Apply bounds
    $requiredChecks = max(MIN_STABLE_CHECKS, min(MAX_STABLE_CHECKS, $checksNeeded));
    
    aviationwx_log('debug', 'calculated required stability checks', [
        'airport' => $airportId,
        'cam' => $camIndex,
        'samples' => count($times),
        'p95_time' => round($p95Time, 2),
        'required_checks' => $requiredChecks,
        'rejection_rate' => round($rejectionRate * 100, 1) . '%'
    ], 'app');
    
    return $requiredChecks;
}

/**
 * Load stability metrics from disk
 * 
 * Provides persistence across PHP restarts (APCu is cleared on restart).
 * Metrics are stored per-camera to allow granular management.
 * 
 * @param string $airportId Airport ID
 * @param int $camIndex Camera index
 * @return array|false Metrics array or false if not found
 */
function loadStabilityMetricsFromDisk($airportId, $camIndex) {
    $cacheDir = __DIR__ . '/../cache/stability_metrics';
    if (!is_dir($cacheDir)) {
        return false;
    }
    
    $file = $cacheDir . "/{$airportId}_{$camIndex}.json";
    if (!file_exists($file)) {
        return false;
    }
    
    $data = @file_get_contents($file);
    if ($data === false) {
        return false;
    }
    
    $metrics = @json_decode($data, true);
    if (!is_array($metrics)) {
        return false;
    }
    
    // Validate structure
    if (!isset($metrics['stability_times']) || !isset($metrics['accepted']) || !isset($metrics['rejected'])) {
        return false;
    }
    
    return $metrics;
}

/**
 * Save stability metrics to disk
 * 
 * Persists metrics to survive PHP restarts.
 * Uses atomic write (tmp + rename) to prevent corruption.
 * 
 * @param string $airportId Airport ID
 * @param int $camIndex Camera index
 * @param array $metrics Metrics data to save
 * @return bool True on success, false on failure
 */
function saveStabilityMetricsToDisk($airportId, $camIndex, $metrics) {
    $cacheDir = __DIR__ . '/../cache/stability_metrics';
    
    // Ensure directory exists
    if (!is_dir($cacheDir)) {
        if (!@mkdir($cacheDir, 0755, true)) {
            return false;
        }
    }
    
    $file = $cacheDir . "/{$airportId}_{$camIndex}.json";
    $tmpFile = $file . '.tmp.' . getmypid() . '.' . mt_rand();
    
    // Write to temp file
    $json = json_encode($metrics, JSON_PRETTY_PRINT);
    if (@file_put_contents($tmpFile, $json) === false) {
        return false;
    }
    
    // Atomic rename
    if (!@rename($tmpFile, $file)) {
        @unlink($tmpFile);
        return false;
    }
    
    return true;
}

/**
 * Record stability metrics for a camera upload
 * 
 * IMPORTANT: Records rejection counts for ALL uploads (accepted + rejected).
 * Only records stability times for accepted uploads (prevents data poisoning).
 * Uses rolling window (last N samples).
 * Persists to disk to survive PHP restarts (APCu is cleared on restart).
 * 
 * Why track rejections even when validation fails?
 * - Rejection rate is used to adjust required_stable_checks
 * - High rejection rate triggers more conservative behavior
 * - Helps detect cameras with consistent issues
 * 
 * Why NOT record stability times for rejected uploads?
 * - Rejected uploads might be partial/incomplete
 * - Including their stability times would poison the P95 calculation
 * - We only want to learn from successful, complete uploads
 * 
 * @param string $airportId Airport ID
 * @param int $camIndex Camera index
 * @param float $stabilityTime Time in seconds to achieve stability
 * @param bool $accepted Whether upload was accepted (passed all validation)
 * @return void
 */
function recordStabilityMetrics($airportId, $camIndex, $stabilityTime, $accepted) {
    $key = "stability_metrics_{$airportId}_{$camIndex}";
    
    // Fetch existing metrics or initialize
    $metrics = apcu_fetch($key);
    if (!$metrics) {
        // Try loading from disk first (might have persisted data)
        $metrics = loadStabilityMetricsFromDisk($airportId, $camIndex);
    }
    if (!$metrics) {
        $metrics = [
            'stability_times' => [],
            'accepted' => 0,
            'rejected' => 0,
            'last_updated' => time()
        ];
    }
    
    // ALWAYS update counts (even for rejected uploads)
    if ($accepted) {
        $metrics['accepted']++;
        
        // Only record stability time for accepted uploads
        // This prevents poisoning data with partial uploads
        $metrics['stability_times'][] = $stabilityTime;
        
        // Keep only last N samples (rolling window)
        $metrics['stability_times'] = array_slice(
            $metrics['stability_times'],
            -STABILITY_SAMPLES_TO_KEEP
        );
    } else {
        // Track rejection even though validation failed
        // This is critical for the feedback loop
        $metrics['rejected']++;
    }
    
    $metrics['last_updated'] = time();
    
    // Store in APCu with 7 day TTL
    apcu_store($key, $metrics, 7 * 86400);
    
    // Persist to disk (survives PHP restarts)
    // Only save every Nth update to reduce I/O (save on every 5th update)
    static $updateCounter = [];
    $cameraKey = "{$airportId}_{$camIndex}";
    if (!isset($updateCounter[$cameraKey])) {
        $updateCounter[$cameraKey] = 0;
    }
    $updateCounter[$cameraKey]++;
    
    if ($updateCounter[$cameraKey] % 5 === 0) {
        saveStabilityMetricsToDisk($airportId, $camIndex, $metrics);
    }
    
    aviationwx_log('debug', 'recorded stability metrics', [
        'airport' => $airportId,
        'cam' => $camIndex,
        'stability_time' => round($stabilityTime, 2),
        'accepted' => $accepted,
        'total_samples' => count($metrics['stability_times']),
        'total_accepted' => $metrics['accepted'],
        'total_rejected' => $metrics['rejected']
    ], 'app');
}

/**
 * Get last processed time for a camera
 * 
 * Retrieves the timestamp of the last successfully processed image for a camera.
 * Used to skip already-processed files and prevent duplicate processing.
 * State is stored per-camera in the webcams directory structure.
 * 
 * @param string $airportId Airport ID (e.g., 'kspb')
 * @param int $camIndex Camera index (0-based)
 * @return int Unix timestamp of last processed file, or 0 if none
 */
function getLastProcessedTime($airportId, $camIndex) {
    $stateFile = getWebcamStatePath($airportId, $camIndex);
    
    if (!file_exists($stateFile)) {
        return 0;
    }
    
    $data = @json_decode(@file_get_contents($stateFile), true);
    if (!is_array($data)) {
        return 0;
    }
    
    return isset($data['last_processed']) ? intval($data['last_processed']) : 0;
}

/**
 * Update last processed time for a camera
 * 
 * Updates the timestamp of the last successfully processed image for a camera.
 * Uses file locking to ensure atomic updates in concurrent environments.
 * State is stored per-camera in the webcams directory structure.
 * 
 * @param string $airportId Airport ID (e.g., 'kspb')
 * @param int $camIndex Camera index (0-based)
 * @return void
 */
function updateLastProcessedTime($airportId, $camIndex) {
    $stateFile = getWebcamStatePath($airportId, $camIndex);
    $stateDir = dirname($stateFile);
    
    if (!is_dir($stateDir)) {
        @mkdir($stateDir, 0755, true);
    }
    
    // Use file locking for atomic update
    $fp = @fopen($stateFile, 'c+');
    if (!$fp) {
        return;
    }
    
    if (@flock($fp, LOCK_EX)) {
        $data = [];
        $size = @filesize($stateFile);
        if ($size !== false && $size > 0) {
            $content = @stream_get_contents($fp);
            if ($content) {
                $data = @json_decode($content, true) ?: [];
            }
        }
        
        $data['last_processed'] = time();
        
        @ftruncate($fp, 0);
        @rewind($fp);
        @fwrite($fp, json_encode($data, JSON_PRETTY_PRINT));
        @fflush($fp);
        @flock($fp, LOCK_UN);
    }
    
    @fclose($fp);
}

/**
 * Find newest valid image in upload directory
 * 
 * Optimized to exit quickly if no new files are found.
 * Supports recursive search for date-based subfolder structures (year/month/day).
 * Uses adaptive stability checking based on camera's historical performance.
 * Enforces file age limits to prevent processing stuck/abandoned uploads.
 * 
 * @param string $uploadDir Upload directory path
 * @param int $stabilityTimeout Maximum time to spend in stability checking loop
 * @param int|null $lastProcessedTime Timestamp of last processed file (null = no filter)
 * @param array|null $pushConfig Optional push_config for per-camera validation
 * @param array|null $airport Optional airport config for phase-aware pixelation detection  
 * @param array|null $cam Optional full camera config for getting max file age
 * @param string|null $airportId Optional airport ID for metrics tracking
 * @param int|null $camIndex Optional camera index for metrics tracking
 * @return string|null Path to valid image file or null
 */
function findNewestValidImage($uploadDir, $stabilityTimeout, $lastProcessedTime = null, $pushConfig = null, $airport = null, $cam = null, $airportId = null, $camIndex = null) {
    if (!is_dir($uploadDir)) {
        return null;
    }
    
    // Use recursive search to find images in subfolders (handles date-based folder structures)
    $files = recursiveGlobImages($uploadDir);
    if (empty($files)) {
        return null; // No files - exit immediately
    }
    
    // Quick check: if we have last processed time, filter files by mtime first
    // This avoids waiting on old files that were already processed
    if ($lastProcessedTime !== null && $lastProcessedTime > 0) {
        $filteredFiles = [];
        foreach ($files as $file) {
            $mtime = @filemtime($file);
            if ($mtime !== false && $mtime > $lastProcessedTime) {
                $filteredFiles[] = $file;
            }
        }
        
        // If no files newer than last processed, exit immediately
        if (empty($filteredFiles)) {
            return null;
        }
        
        $files = $filteredFiles;
    }
    
    // Sort by mtime (newest first)
    usort($files, function($a, $b) {
        $mtimeA = @filemtime($a);
        $mtimeB = @filemtime($b);
        if ($mtimeA === false) return 1;
        if ($mtimeB === false) return -1;
        return $mtimeB - $mtimeA;
    });
    
    // Get required stability checks for this camera (adaptive based on history)
    $requiredStableChecks = getRequiredStableChecks($airportId, $camIndex);
    
    // Get max file age for this camera (fail-closed protection)
    $maxFileAge = getUploadFileMaxAge($cam);
    
    foreach ($files as $file) {
        // Check file age (must be between MIN and MAX)
        $fileAge = time() - @filemtime($file);
        
        // Too new - don't check yet (MIN_FILE_AGE_SECONDS = 3)
        if ($fileAge < 3) {
            aviationwx_log('debug', 'file too new, skipping', [
                'file' => basename($file),
                'age' => $fileAge,
                'min_age' => 3
            ], 'app');
            
            // This is the newest file and it's too new
            // Skip to next file (might have older valid files)
            continue;
        }
        
        // Too old - abandoned/stuck upload, clean it up
        if ($fileAge > $maxFileAge) {
            aviationwx_log('warning', 'upload file too old (abandoned/stuck), deleting', [
                'file' => basename($file),
                'age_seconds' => $fileAge,
                'max_age' => $maxFileAge,
                'airport' => $airportId,
                'cam' => $camIndex
            ], 'app');
            
            // Track as rejection (unhealthy camera / failed upload)
            trackWebcamUploadRejected($airportId, $camIndex, 'file_too_old');
            recordStabilityMetrics($airportId, $camIndex, 0, false); // No stability time, rejected
            
            // Attempt to delete the file (cleanup job is defensive backup)
            if (@unlink($file)) {
                aviationwx_log('info', 'deleted abandoned upload file', [
                    'file' => basename($file),
                    'airport' => $airportId,
                    'cam' => $camIndex
                ], 'app');
            } else {
                aviationwx_log('error', 'failed to delete abandoned upload file', [
                    'file' => basename($file),
                    'airport' => $airportId,
                    'cam' => $camIndex
                ], 'app');
            }
            
            // Try next file (iterate through all files to clean up state)
            // There should only be one file uploading at a time per camera,
            // so other files should be valid or also need cleanup
            continue;
        }
        
        // File age is OK (between MIN and MAX), proceed with stability checks
        
        // Check stability (limited by stabilityTimeout, not overall worker time)
        $stabilityTime = 0;
        $isStable = isFileStable($file, $requiredStableChecks, $stabilityTimeout, $stabilityTime);
        
        if (!$isStable) {
            // File still being written OR timeout reached - skip and try next file
            // Worker will come back later and try again
            aviationwx_log('info', 'file not stable, skipping (will retry next worker run)', [
                'file' => basename($file),
                'stability_time' => round($stabilityTime, 2),
                'required_checks' => $requiredStableChecks,
                'timeout' => $stabilityTimeout
            ], 'app');
            continue; // Try next file
        }
        
        // File is stable - now validate it
        
        // Push cameras may not have EXIF - add from file mtime before validation
        // Server-fetched webcams have EXIF added after capture, but push cameras bypass that step
        if (!hasExifTimestamp($file)) {
            ensureExifTimestamp($file);
        }
        
        // Validate image (with per-camera limits and airport for phase-aware detection)
        if (!validateImageFile($file, $pushConfig, $airport, $airportId, $camIndex)) {
            trackWebcamUploadRejected($airportId, $camIndex, 'validation_failed');
            recordStabilityMetrics($airportId, $camIndex, $stabilityTime, false);
            continue; // Try next file
        }
        
        // Normalize EXIF timestamp to UTC
        // Local time is ENCOURAGED (helps operators match their clocks)
        // This detects timezone offset and rewrites EXIF to UTC for client verification
        // Rejects images with unreliable timestamps (safety-critical)
        if (!normalizeExifToUtc($file, $airportId, $camIndex)) {
            trackWebcamUploadRejected($airportId, $camIndex, 'invalid_exif_timestamp');
            recordStabilityMetrics($airportId, $camIndex, $stabilityTime, false);
            continue; // Skip this image, try next
        }
        
        // SUCCESS - file is stable and valid
        trackWebcamUploadAccepted($airportId, $camIndex);
        recordStabilityMetrics($airportId, $camIndex, $stabilityTime, true);
        return $file;
    }
    
    return null;
}

/**
 * Check if file has achieved stability
 * 
 * Performs stability checks (size + mtime) for up to stabilityTimeout seconds.
 * Uses adaptive number of required checks based on camera's historical performance.
 * Returns immediately if required stable checks achieved.
 * 
 * @param string $file File path
 * @param int $requiredStableChecks Number of consecutive stable checks needed
 * @param int $stabilityTimeout Maximum time to spend checking in this loop (not overall worker timeout)
 * @param float &$stabilityTime OUT: Time spent achieving stability (seconds)
 * @return bool True if stable, false if timeout
 */
function isFileStable($file, $requiredStableChecks, $stabilityTimeout, &$stabilityTime) {
    $startTime = microtime(true);
    $maxWaitTime = $startTime + $stabilityTimeout;
    
    $lastSize = null;
    $lastMtime = null;
    $stableChecks = 0;
    $totalChecks = 0;
    
    while (microtime(true) < $maxWaitTime) {
        $totalChecks++;
        
        // Get current state
        $currentSize = @filesize($file);
        $currentMtime = @filemtime($file);
        
        if ($currentSize === false || $currentMtime === false) {
            // File disappeared or became unreadable
            $stabilityTime = microtime(true) - $startTime;
            return false;
        }
        
        // Check if size AND mtime are stable
        if ($lastSize !== null && $lastMtime !== null) {
            if ($currentSize === $lastSize && $currentMtime === $lastMtime) {
                $stableChecks++;
                
                if ($stableChecks >= $requiredStableChecks) {
                    // SUCCESS: File is stable
                    $stabilityTime = microtime(true) - $startTime;
                    
                    aviationwx_log('debug', 'file stability achieved', [
                        'file' => basename($file),
                        'required_checks' => $requiredStableChecks,
                        'total_checks' => $totalChecks,
                        'stability_time' => round($stabilityTime, 2),
                        'final_size' => $currentSize
                    ], 'app');
                    
                    return true;
                }
            } else {
                // Size or mtime changed - reset counter
                if ($stableChecks > 0) {
                    aviationwx_log('debug', 'file stability reset', [
                        'file' => basename($file),
                        'had_stable_checks' => $stableChecks,
                        'size_changed' => ($currentSize !== $lastSize),
                        'mtime_changed' => ($currentMtime !== $lastMtime)
                    ], 'app');
                }
                $stableChecks = 0;
            }
        }
        
        $lastSize = $currentSize;
        $lastMtime = $currentMtime;
        
        // Wait before next check (unless this is the last possible check)
        if (microtime(true) + (STABILITY_CHECK_INTERVAL_MS / 1000) < $maxWaitTime) {
            usleep(STABILITY_CHECK_INTERVAL_MS * 1000);
        }
    }
    
    // TIMEOUT: Ran out of time in stability checking loop
    // This doesn't mean the upload failed - just that we need to come back later
    $stabilityTime = microtime(true) - $startTime;
    
    aviationwx_log('info', 'file stability timeout (will retry next worker run)', [
        'file' => basename($file),
        'required_checks' => $requiredStableChecks,
        'achieved_checks' => $stableChecks,
        'total_checks' => $totalChecks,
        'timeout_seconds' => $stabilityTimeout,
        'actual_time' => round($stabilityTime, 2)
    ], 'app');
    
    return false;
}

/**
 * Validate image file
 * 
 * Validates that a file is a valid image meeting size, extension, and MIME type
 * requirements. Checks file headers to ensure it's actually a JPEG or PNG.
 * Also performs content validation (error frame, pixelation, EXIF).
 * 
 * @param string $file File path to validate
 * @param array|null $pushConfig Optional push_config for per-camera validation limits
 *   - max_file_size_mb: Maximum file size in MB (default: 100)
 *   - allowed_extensions: Array of allowed extensions (default: all)
 * @param array|null $airport Optional airport config for phase-aware pixelation detection
 * @param string|null $airportId Optional airport ID for metrics tracking
 * @param int|null $camIndex Optional camera index for metrics tracking
 * @return bool True if file is valid image, false otherwise
 */
function validateImageFile($file, $pushConfig = null, $airport = null, $airportId = null, $camIndex = null) {
    if (!file_exists($file) || !is_readable($file)) {
        if ($airportId !== null && $camIndex !== null) {
            trackWebcamUploadRejected($airportId, $camIndex, 'file_not_readable');
        }
        return false;
    }
    
    $size = filesize($file);
    
    // Check minimum size (too small to be a valid image)
    if ($size < 100) {
        if ($airportId !== null && $camIndex !== null) {
            trackWebcamUploadRejected($airportId, $camIndex, 'size_too_small');
        }
        return false;
    }
    
    // Check maximum size (per-camera limit if provided, otherwise default 100MB)
    $maxSizeBytes = 100 * 1024 * 1024; // Default 100MB
    if ($pushConfig && isset($pushConfig['max_file_size_mb'])) {
        $maxSizeBytes = intval($pushConfig['max_file_size_mb']) * 1024 * 1024;
    }
    if ($size > $maxSizeBytes) {
        if ($airportId !== null && $camIndex !== null) {
            trackWebcamUploadRejected($airportId, $camIndex, 'size_limit_exceeded');
        }
        return false;
    }
    
    // Check file extension if allowed_extensions specified
    if ($pushConfig && isset($pushConfig['allowed_extensions']) && is_array($pushConfig['allowed_extensions'])) {
        $ext = strtolower(pathinfo($file, PATHINFO_EXTENSION));
        $allowed = array_map('strtolower', $pushConfig['allowed_extensions']);
        if (!in_array($ext, $allowed)) {
            if ($airportId !== null && $camIndex !== null) {
                trackWebcamUploadRejected($airportId, $camIndex, 'extension_not_allowed');
            }
            return false;
        }
    }
    
    // Check MIME type
    $mime = @mime_content_type($file);
    $validMimes = ['image/jpeg', 'image/jpg', 'image/png', 'image/webp'];
    if (!in_array($mime, $validMimes)) {
        if ($airportId !== null && $camIndex !== null) {
            trackWebcamUploadRejected($airportId, $camIndex, 'invalid_mime_type');
        }
        return false;
    }
    
    // Check image headers using shared format detection
    $format = detectImageFormat($file);
    if ($format === null) {
        if ($airportId !== null && $camIndex !== null) {
            trackWebcamUploadRejected($airportId, $camIndex, 'invalid_format');
        }
        return false;
    }
    
    // Check for truncated/incomplete uploads
    // Slow/interrupted uploads can result in partial files with missing end markers
    // This causes decoder artifacts (solid color blocks, green bars, corruption)
    require_once __DIR__ . '/../lib/webcam-history.php';
    
    $isComplete = false;
    if ($format === 'jpeg') {
        $isComplete = isJpegComplete($file);
    } elseif ($format === 'png') {
        $isComplete = isPngComplete($file);
    } elseif ($format === 'webp') {
        $isComplete = isWebpComplete($file);
    } else {
        // Unknown format passed format detection - shouldn't happen, but allow
        $isComplete = true;
    }
    
    if (!$isComplete) {
        aviationwx_log('warning', 'push webcam incomplete upload detected, rejecting', [
            'file' => basename($file),
            'format' => $format,
            'size' => $size
        ], 'app');
        if ($airportId !== null && $camIndex !== null) {
            trackWebcamUploadRejected($airportId, $camIndex, 'incomplete_upload');
        }
        return false;
    }
    
    // Validate GD can parse the image (catches corruption that passes structure checks)
    // This is critical for push cameras as we can't re-fetch a corrupt upload
    if (function_exists('imagecreatefromstring')) {
        $imageData = @file_get_contents($file);
        if ($imageData === false) {
            aviationwx_log('warning', 'push webcam file read failed during GD validation', [
                'file' => basename($file)
            ], 'app');
            if ($airportId !== null && $camIndex !== null) {
                trackWebcamUploadRejected($airportId, $camIndex, 'file_read_error');
            }
            return false;
        }
        
        $testImg = @imagecreatefromstring($imageData);
        if ($testImg === false) {
            aviationwx_log('warning', 'push webcam GD parsing failed, image corrupt', [
                'file' => basename($file),
                'format' => $format,
                'size' => $size
            ], 'app');
            if ($airportId !== null && $camIndex !== null) {
                trackWebcamUploadRejected($airportId, $camIndex, 'image_corrupt');
            }
            return false;
        }
        imagedestroy($testImg);
        unset($imageData); // Free memory
    }
    
    // Validate image content (error frame, uniform color, pixelation, etc.)
    // Only for JPEG which detectErrorFrame supports
    // Pass airport for phase-aware pixelation thresholds
    if ($format === 'jpeg') {
        $errorCheck = detectErrorFrame($file, $airport);
        if ($errorCheck['is_error']) {
            aviationwx_log('warning', 'push webcam error frame detected, rejecting', [
                'file' => basename($file),
                'confidence' => $errorCheck['confidence'],
                'reasons' => $errorCheck['reasons']
            ], 'app');
            if ($airportId !== null && $camIndex !== null) {
                trackWebcamUploadRejected($airportId, $camIndex, 'error_frame');
            }
            return false;
        }
    }
    
    // Validate EXIF timestamp (push cameras must have camera-provided EXIF)
    $exifCheck = validateExifTimestamp($file);
    if (!$exifCheck['valid']) {
        aviationwx_log('warning', 'push webcam EXIF timestamp invalid, rejecting', [
            'file' => basename($file),
            'reason' => $exifCheck['reason'],
            'timestamp' => $exifCheck['timestamp'] > 0 ? date('Y-m-d H:i:s', $exifCheck['timestamp']) : 'none'
        ], 'app');
        if ($airportId !== null && $camIndex !== null) {
            trackWebcamUploadRejected($airportId, $camIndex, 'exif_invalid');
        }
        return false;
    }
    
    return true;
}

/**
 * Process a single history frame with full variant generation
 * 
 * Generates all variants (thumb, small, medium, large, primary) × formats (jpg, webp)
 * for a single image file and saves them to history. Does NOT update current symlinks.
 * 
 * @param string $sourceFile Source file path
 * @param string $airportId Airport ID (e.g., 'kspb')
 * @param int $camIndex Camera index (0-based)
 * @return bool True on success, false on failure
 */
function processHistoryFrame($sourceFile, $airportId, $camIndex) {
    if (!file_exists($sourceFile) || !is_readable($sourceFile)) {
        return false;
    }
    
    // Detect format
    $format = detectImageFormat($sourceFile);
    if ($format === null) {
        aviationwx_log('debug', 'processHistoryFrame: unable to detect format', [
            'airport' => $airportId,
            'cam' => $camIndex,
            'file' => basename($sourceFile)
        ], 'app');
        return false;
    }
    
    // Get timestamp from EXIF or file mtime
    $timestamp = getSourceCaptureTime($sourceFile);
    if ($timestamp <= 0) {
        $timestamp = @filemtime($sourceFile) ?: time();
    }
    
    // Determine primary format (PNG converts to JPEG)
    $primaryFormat = ($format === 'png') ? 'jpg' : $format;
    
    // Create a temporary staging file for this frame
    $stagingFile = getWebcamCameraDir($airportId, $camIndex) . '/history_staging_' . $timestamp . '.' . $primaryFormat . '.tmp';
    
    // Ensure camera directory exists
    $cameraDir = getWebcamCameraDir($airportId, $camIndex);
    if (!ensureCacheDir($cameraDir)) {
        return false;
    }
    
    // Convert PNG or copy to staging
    if ($format === 'png') {
        if (!convertPngToJpeg($sourceFile, $stagingFile)) {
            aviationwx_log('debug', 'processHistoryFrame: PNG conversion failed', [
                'airport' => $airportId,
                'cam' => $camIndex,
                'file' => basename($sourceFile)
            ], 'app');
            @unlink($stagingFile);
            return false;
        }
    } else {
        if (!@copy($sourceFile, $stagingFile)) {
            return false;
        }
    }
    
    // Generate variants from original
    require_once __DIR__ . '/../lib/performance-metrics.php';
    $perfStart = perfStart();
    $variantResult = generateVariantsFromOriginal($stagingFile, $airportId, $camIndex, $timestamp);
    $processingTimeMs = perfEnd($perfStart);
    
    // Track image processing performance
    trackImageProcessingTime($processingTimeMs, $airportId, $camIndex);
    
    // Cleanup staging file (original is now preserved)
    @unlink($stagingFile);
    
    // Cleanup any remaining staging files
    foreach (glob($cameraDir . '/staging*.tmp') as $stageFile) {
        @unlink($stageFile);
    }
    
    $success = $variantResult['original'] !== null && !empty($variantResult['variants']);
    
    if ($success) {
        // Store variant manifest for status reporting
        require_once __DIR__ . '/../lib/webcam-variant-manifest.php';
        storeVariantManifest($airportId, $camIndex, $timestamp, $variantResult);
        
        $variantCount = 0;
        foreach ($variantResult['variants'] as $formats) {
            $variantCount += count($formats);
        }
        aviationwx_log('debug', 'processHistoryFrame: generated variants', [
            'airport' => $airportId,
            'cam' => $camIndex,
            'timestamp' => $timestamp,
            'variants' => $variantCount
        ], 'app');
    }
    
    return $success;
}

/**
 * Harvest all valid images from upload directory for history
 * 
 * For push cameras, multiple images may accumulate between processing intervals.
 * When history is enabled, process all valid images with full variant generation.
 * This captures intermediate frames with all size variants for high-quality timelapse.
 * Supports recursive search for date-based subfolder structures (year/month/day).
 * 
 * @param string $uploadDir Upload directory path
 * @param string $airportId Airport ID (e.g., 'kspb')
 * @param int $camIndex Camera index (0-based)
 * @param array|null $pushConfig Push config for validation limits
 * @param int|null $lastProcessedTime Only harvest files newer than this timestamp
 * @return int Number of frames harvested
 */
function harvestHistoryFrames($uploadDir, $airportId, $camIndex, $pushConfig = null, $lastProcessedTime = null) {
    // Check if history is enabled for this airport
    if (!isWebcamHistoryEnabledForAirport($airportId)) {
        return 0;
    }
    
    if (!is_dir($uploadDir)) {
        return 0;
    }
    
    // Use recursive search to find images in subfolders (handles date-based folder structures)
    $files = recursiveGlobImages($uploadDir);
    if (empty($files)) {
        return 0;
    }
    
    // Filter to files newer than last processed (if specified)
    if ($lastProcessedTime !== null && $lastProcessedTime > 0) {
        $files = array_filter($files, function($file) use ($lastProcessedTime) {
            $mtime = @filemtime($file);
            return $mtime !== false && $mtime > $lastProcessedTime;
        });
        
        if (empty($files)) {
            return 0;
        }
    }
    
    // Sort by modification time (oldest first for chronological history)
    usort($files, function($a, $b) {
        $mtimeA = @filemtime($a);
        $mtimeB = @filemtime($b);
        if ($mtimeA === false) return 1;
        if ($mtimeB === false) return -1;
        return $mtimeA - $mtimeB;
    });
    
    $harvested = 0;
    
    foreach ($files as $file) {
        // Validate image is complete and not corrupted
        if (!validateImageForHistory($file, $pushConfig)) {
            aviationwx_log('debug', 'harvest: skipping invalid/incomplete image', [
                'airport' => $airportId,
                'cam' => $camIndex,
                'file' => basename($file)
            ], 'app');
            continue;
        }
        
        // Process with full variant generation (generates all sizes × formats)
        if (processHistoryFrame($file, $airportId, $camIndex)) {
            $harvested++;
        }
    }
    
    return $harvested;
}

/**
 * Move image to cache and generate missing formats
 * 
 * Uses staging workflow: writes to .tmp files, generates all formats in parallel,
 * then promotes all successful formats atomically.
 * 
 * Keeps original format as primary, generates missing formats.
 * PNG is always converted to JPEG (we don't serve PNG).
 * 
 * @param string $sourceFile Source file path in upload directory
 * @param string $airportId Airport ID (e.g., 'kspb')
 * @param int $camIndex Camera index (0-based)
 * @return string|false Primary cache file path on success, false on failure
 */
function moveToCache($sourceFile, $airportId, $camIndex) {
    // Cleanup any stale staging files from crashed workers
    cleanupStagingFiles($airportId, $camIndex);
    
    // Validate source file
    if (!file_exists($sourceFile) || !is_readable($sourceFile)) {
        aviationwx_log('error', 'moveToCache: source file invalid', [
            'source' => $sourceFile,
            'airport' => $airportId,
            'cam' => $camIndex
        ], 'app');
        return false;
    }
    
    $fileSize = filesize($sourceFile);
    if ($fileSize === false || $fileSize === 0) {
        aviationwx_log('error', 'moveToCache: source file has invalid size', [
            'source' => $sourceFile,
            'size' => $fileSize
        ], 'app');
        return false;
    }
    
    if (!ensureCacheDir(CACHE_WEBCAMS_DIR)) {
        aviationwx_log('error', 'moveToCache: cache directory creation failed', [
            'dir' => CACHE_WEBCAMS_DIR
        ], 'app');
        return false;
    }
    
    if (!is_writable(CACHE_WEBCAMS_DIR)) {
        aviationwx_log('error', 'moveToCache: cache directory not writable', [
            'dir' => CACHE_WEBCAMS_DIR
        ], 'app');
        return false;
    }
    
    // Detect format
    $format = detectImageFormat($sourceFile);
    if ($format === null) {
        aviationwx_log('error', 'moveToCache: unable to detect image format', [
            'source' => $sourceFile,
            'airport' => $airportId,
            'cam' => $camIndex
        ], 'app');
        return false;
    }
    
    // Determine primary format and staging file
    // PNG is always converted to JPEG (we don't serve PNG)
    if ($format === 'png') {
        $primaryFormat = 'jpg';
        $stagingFile = getStagingFilePath($airportId, $camIndex, 'jpg');
        
        // Convert PNG to JPEG in staging
        if (!convertPngToJpeg($sourceFile, $stagingFile)) {
            aviationwx_log('error', 'moveToCache: PNG to JPEG conversion failed', [
                'source' => $sourceFile,
                'airport' => $airportId,
                'cam' => $camIndex
            ], 'app');
            cleanupStagingFiles($airportId, $camIndex);
            return false;
        }
        
        // Remove source PNG after successful conversion
        @unlink($sourceFile);
    } else {
        // Keep original format (JPEG or WebP)
        $primaryFormat = $format;
        $stagingFile = getStagingFilePath($airportId, $camIndex, $primaryFormat);
        
        // Move to staging
        if (!@rename($sourceFile, $stagingFile)) {
            $error = error_get_last();
            aviationwx_log('error', 'moveToCache: rename to staging failed', [
                'source' => $sourceFile,
                'dest' => $stagingFile,
                'error' => $error['message'] ?? 'unknown'
            ], 'app');
            cleanupStagingFiles($airportId, $camIndex);
            return false;
        }
    }
    
    // Verify staging file exists
    if (!file_exists($stagingFile) || filesize($stagingFile) === 0) {
        aviationwx_log('error', 'moveToCache: staging file invalid', [
            'staging_file' => $stagingFile
        ], 'app');
        cleanupStagingFiles($airportId, $camIndex);
        return false;
    }
    
    // Get timestamp from staging file (for timestamp-based filenames)
    $timestamp = getSourceCaptureTime($stagingFile);
    if ($timestamp <= 0) {
        $timestamp = time();
    }
    
    // Get input dimensions for variant generation
    $inputDimensions = getImageDimensions($stagingFile);
    if ($inputDimensions === null) {
        aviationwx_log('warning', 'moveToCache: unable to detect image dimensions, falling back to format-only generation', [
            'airport' => $airportId,
            'cam' => $camIndex
        ], 'app');
        // Fallback to old format-only generation
        $formatResults = generateFormatsSync($stagingFile, $airportId, $camIndex, $primaryFormat);
        $promotedFormats = promoteFormats($airportId, $camIndex, $formatResults, $primaryFormat, $timestamp);
        if (empty($promotedFormats)) {
            aviationwx_log('error', 'moveToCache: no formats promoted', [
                'airport' => $airportId,
                'cam' => $camIndex,
                'primary_format' => $primaryFormat
            ], 'app');
            cleanupStagingFiles($airportId, $camIndex);
            return false;
        }
    } else {
        $variantResult = generateVariantsFromOriginal($stagingFile, $airportId, $camIndex, $timestamp);
        
        if ($variantResult['original'] === null || empty($variantResult['variants'])) {
            aviationwx_log('error', 'moveToCache: variant generation failed', [
                'airport' => $airportId,
                'cam' => $camIndex,
                'primary_format' => $primaryFormat,
                'has_original' => $variantResult['original'] !== null,
                'variant_count' => count($variantResult['variants'])
            ], 'app');
            cleanupStagingFiles($airportId, $camIndex);
            return false;
        }
        
        // Store variant manifest for status reporting
        require_once __DIR__ . '/../lib/webcam-variant-manifest.php';
        storeVariantManifest($airportId, $camIndex, $timestamp, $variantResult);
        
        $promotedFormats = [];
        foreach ($variantResult['variants'] as $height => $formats) {
            $promotedFormats = array_merge($promotedFormats, array_keys($formats));
        }
        $promotedFormats = array_unique($promotedFormats);
    }
    
    // Cleanup old timestamp files (uses webcam_history_max_frames config for retention)
    cleanupOldTimestampFiles($airportId, $camIndex);
    
    // Log final result
    $allRequestedFormats = [$primaryFormat];
    if ($primaryFormat !== 'jpg') $allRequestedFormats[] = 'jpg';
    if (isWebpGenerationEnabled() && $primaryFormat !== 'webp') $allRequestedFormats[] = 'webp';
    $failedFormats = array_diff($allRequestedFormats, $promotedFormats);
    
    if (!empty($failedFormats)) {
        aviationwx_log('warning', 'moveToCache: partial format generation', [
            'airport' => $airportId,
            'cam' => $camIndex,
            'formats_promoted' => $promotedFormats,
            'formats_failed' => array_values($failedFormats)
        ], 'app');
    }
    
    // Return primary cache file path (primary variant)
    return getFinalFilePath($airportId, $camIndex, $primaryFormat, $timestamp, 'primary');
}


/**
 * Clean up upload directory
 * 
 * Removes old files from upload directory and its subdirectories. Only deletes files 
 * with modification time older than or equal to the specified cutoff time. This prevents 
 * race conditions where new files might be deleted while still being uploaded.
 * Also cleans up empty subdirectories left after file deletion (handles date-based folders).
 * 
 * @param string $uploadDir Upload directory path
 * @param string|null $keepFile File to keep by name (optional, legacy parameter)
 * @param int|null $maxMtime Maximum modification time for files to delete (optional)
 *                           Files with mtime > maxMtime will be preserved
 * @return void
 */
function cleanupUploadDirectory($uploadDir, $keepFile = null, $maxMtime = null) {
    // Validate directory exists and is writable
    if (!is_dir($uploadDir)) {
        aviationwx_log('warning', 'cleanupUploadDirectory: directory does not exist', [
            'dir' => $uploadDir
        ], 'app');
        return;
    }
    
    if (!is_writable($uploadDir)) {
        aviationwx_log('warning', 'cleanupUploadDirectory: directory not writable', [
            'dir' => $uploadDir
        ], 'app');
        return;
    }
    
    // Use recursive search to find all files including in date-based subfolders
    $files = recursiveGlobImages($uploadDir);
    // Also get any other files (not just images) in the top-level directory
    $topLevelFiles = glob($uploadDir . '*');
    if ($topLevelFiles !== false) {
        foreach ($topLevelFiles as $file) {
            if (is_file($file) && !in_array($file, $files)) {
                $files[] = $file;
            }
        }
    }
    
    if (empty($files)) {
        // No files, but check for empty directories to clean up
        $dirsRemoved = cleanupEmptyDirectories($uploadDir, $uploadDir);
        if ($dirsRemoved > 0) {
            aviationwx_log('debug', 'cleanupUploadDirectory: removed empty directories', [
                'dir' => $uploadDir,
                'dirs_removed' => $dirsRemoved
            ], 'app');
        }
        return;
    }
    
    $keepBasename = $keepFile ? basename($keepFile) : null;
    $deletedCount = 0;
    $skippedCount = 0;
    
    foreach ($files as $file) {
        if (!is_file($file)) {
            continue; // Skip directories and symlinks
        }
        
        $basename = basename($file);
        // Keep the processed file if specified (legacy behavior)
        if ($keepBasename && $basename === $keepBasename) {
            continue;
        }
        
        // If maxMtime is specified, only delete files with mtime <= maxMtime
        // This prevents deleting files that started uploading after processing began
        if ($maxMtime !== null) {
            // Use @ to suppress errors for non-critical file operations
            // We handle failures explicitly by preserving files when mtime can't be read
            $fileMtime = @filemtime($file);
            if ($fileMtime !== false && $fileMtime > $maxMtime) {
                // File is newer than processed file - skip deletion
                $skippedCount++;
                continue;
            }
            // If filemtime() returns false, we can't determine file age
            // Conservatively preserve the file to avoid deleting files that might be new
            if ($fileMtime === false) {
                $skippedCount++;
                continue;
            }
        }
        
        // Delete file with error handling
        if (!@unlink($file)) {
            $error = error_get_last();
            aviationwx_log('warning', 'cleanupUploadDirectory: failed to delete file', [
                'file' => $file,
                'error' => $error['message'] ?? 'unknown'
            ], 'app');
        } else {
            $deletedCount++;
        }
    }
    
    // Clean up empty directories left after file deletion
    $dirsRemoved = cleanupEmptyDirectories($uploadDir, $uploadDir);
    
    if ($deletedCount > 0 || $skippedCount > 0 || $dirsRemoved > 0) {
        aviationwx_log('debug', 'cleanupUploadDirectory: cleanup completed', [
            'dir' => $uploadDir,
            'files_deleted' => $deletedCount,
            'files_skipped_newer' => $skippedCount,
            'dirs_removed' => $dirsRemoved,
            'max_mtime' => $maxMtime
        ], 'app');
    }
}

/**
 * Process a single push camera
 * 
 * Processes uploaded images for a push camera. Finds newest valid image,
 * validates it, moves to cache, generates WEBP, and cleans up upload directory.
 * Updates last processed timestamp on success.
 * 
 * @param string $airportId Airport ID (e.g., 'kspb')
 * @param int $camIndex Camera index (0-based)
 * @param array $cam Camera configuration array
 * @param array $airport Airport configuration array
 * @return void
 */
function processPushCamera($airportId, $camIndex, $cam, $airport) {
    // Airport-scoped directory: /uploads/{airport}/{username}/
    $username = $cam['push_config']['username'] ?? null;
    
    if (!$username) {
        return false; // No username configured
    }
    
    $uploadDir = getWebcamUploadDir($airportId, $username) . '/';
    
    // Quick check: if directory doesn't exist or has no files, exit immediately
    if (!is_dir($uploadDir)) {
        return false; // No directory - exit immediately
    }
    
    // Quick check: if no image files exist (including in subfolders), exit immediately
    // Use recursive search to handle date-based folder structures from FTP uploads
    $hasFiles = !empty(recursiveGlobImages($uploadDir));
    if (!$hasFiles) {
        return false; // No files - exit immediately
    }
    
    // Check if directory is writable
    if (!isDirectoryWritable($uploadDir)) {
        aviationwx_log('warning', 'upload directory not writable', [
            'airport' => $airportId,
            'cam' => $camIndex,
            'directory' => $uploadDir
        ], 'app');
        return false;
    }
    
    $refreshSeconds = getCameraRefreshSeconds($cam, $airport);
    $lastProcessed = getLastProcessedTime($airportId, $camIndex);
    $timeSinceLastProcess = time() - $lastProcessed;
    
    // Check if due for processing
    if ($timeSinceLastProcess < $refreshSeconds) {
        return false; // Not due yet
    }
    
    // Get stability check timeout (just for stability checking loop)
    $stabilityTimeout = getStabilityCheckTimeout($cam);
    
    // Get push_config for per-camera validation limits
    $pushConfig = $cam['push_config'] ?? null;
    
    // Harvest all valid images for history before processing
    // This captures intermediate frames that accumulated since last processing
    $harvestedCount = harvestHistoryFrames($uploadDir, $airportId, $camIndex, $pushConfig, $lastProcessed);
    if ($harvestedCount > 0) {
        aviationwx_log('info', 'harvested frames for history', [
            'airport' => $airportId,
            'cam' => $camIndex,
            'frames' => $harvestedCount
        ], 'app');
    }
    
    // Find newest valid image with adaptive stability checking
    // Pass lastProcessed time to quickly skip old files
    // Pass cam for getting max file age configuration
    $newestFile = findNewestValidImage($uploadDir, $stabilityTimeout, $lastProcessed, $pushConfig, $airport, $cam, $airportId, $camIndex);
    
    if (!$newestFile) {
        aviationwx_log('info', 'no valid image found', [
            'airport' => $airportId,
            'cam' => $camIndex
        ], 'app');
        return false;
    }
    
    // Capture processed file's modification time before moving it
    // This ensures cleanup only deletes files older than or equal to the processed file
    // Use @ to suppress errors for non-critical file operations
    // We handle failures explicitly with fallback mechanism below
    $processedFileMtime = @filemtime($newestFile);
    if ($processedFileMtime === false) {
        // Fallback to current time if mtime unavailable
        // This is conservative - preserves files if we can't determine their age
        $processedFileMtime = time();
    }
    
    // Move to cache
    $cacheFile = moveToCache($newestFile, $airportId, $camIndex);
    
    if ($cacheFile) {
        // Track successful upload
        trackWebcamUploadAccepted($airportId, $camIndex);
        
        // Clean up upload directory (delete only files older than or equal to processed file)
        // This prevents deleting files that started uploading after processing began
        cleanupUploadDirectory($uploadDir, null, $processedFileMtime);
        
        // Update last processed time
        updateLastProcessedTime($airportId, $camIndex);
        
        aviationwx_log('info', 'push webcam processed successfully', [
            'airport' => $airportId,
            'cam' => $camIndex,
            'cache_file' => $cacheFile
        ], 'app');
        
        return true;
    }
    
    return false;
}

/**
 * Main processing function
 * 
 * Main entry point for processing push webcam uploads. Acquires file lock to prevent
 * concurrent execution, loads configuration, processes all configured push cameras,
 * and synchronizes SFTP/FTP user accounts. Handles errors gracefully and logs activity.
 * 
 * @return void
 */
function processPushWebcams() {
    $lockFile = '/tmp/process-push-webcams.lock';
    
    // Clean up stale locks (older than 5 minutes)
    if (file_exists($lockFile)) {
        $lockAge = time() - filemtime($lockFile);
        if ($lockAge > 300) {
            @unlink($lockFile);
        }
    }
    
    // Acquire lock
    $fp = @fopen($lockFile, 'c+');
    if (!$fp) {
        aviationwx_log('error', 'cannot create lock file', ['lock_file' => $lockFile], 'app', true);
        exit(1);
    }
    
    if (!@flock($fp, LOCK_EX | LOCK_NB)) {
        // Another instance running
        @fclose($fp);
        exit(0);
    }
    
    // Write PID to lock file
    @fwrite($fp, getmypid());
    @fflush($fp);
    
    try {
        // Check disk space
        $uploadBaseDir = __DIR__ . '/../uploads';
        $diskCheck = checkDiskSpace($uploadBaseDir);
        if ($diskCheck['status'] === 'critical') {
            aviationwx_log('error', 'disk space critical, skipping processing', [
                'percent_free' => $diskCheck['percent']
            ], 'app');
            @flock($fp, LOCK_UN);
            @fclose($fp);
            exit(1);
        } elseif ($diskCheck['status'] === 'warning') {
            aviationwx_log('warning', 'disk space low', [
                'percent_free' => $diskCheck['percent']
            ], 'app');
        }
        
        // Check inode usage
        $inodeCheck = checkInodeUsage($uploadBaseDir);
        if ($inodeCheck['status'] === 'critical') {
            aviationwx_log('error', 'inode usage critical, skipping processing', [
                'percent_used' => $inodeCheck['percent']
            ], 'app');
            @flock($fp, LOCK_UN);
            @fclose($fp);
            exit(1);
        } elseif ($inodeCheck['status'] === 'warning') {
            aviationwx_log('warning', 'inode usage high', [
                'percent_used' => $inodeCheck['percent']
            ], 'app');
        }
        
        // Load config (no cache for processor)
        $config = loadConfig(false);
        if (!$config) {
            aviationwx_log('error', 'config load failed', [], 'app');
            @flock($fp, LOCK_UN);
            @fclose($fp);
            exit(1);
        }
        
        // Process each push camera
        $processed = 0;
        foreach ($config['airports'] ?? [] as $airportId => $airport) {
            if (!isset($airport['webcams']) || !is_array($airport['webcams'])) {
                continue;
            }
            
            foreach ($airport['webcams'] as $camIndex => $cam) {
                // Check if push camera
                $isPush = (isset($cam['type']) && $cam['type'] === 'push') 
                       || isset($cam['push_config']);
                
                if (!$isPush) {
                    continue;
                }
                
                if (processPushCamera($airportId, $camIndex, $cam, $airport)) {
                    $processed++;
                }
            }
        }
        
        aviationwx_log('info', 'push-webcam processor completed', [
            'processed' => $processed
        ], 'app');
        
        // Flush variant health counters to cache file
        // CLI APCu is process-isolated from PHP-FPM, so we must flush here
        // before the process exits (scheduler's HTTP flush reads PHP-FPM APCu)
        if ($processed > 0) {
            require_once __DIR__ . '/../lib/variant-health.php';
            variant_health_flush();
        }
        
    } finally {
        @flock($fp, LOCK_UN);
        @fclose($fp);
    }
}

// Run processor (only when executed directly, not when included)
if (php_sapi_name() === 'cli') {
    $scriptName = $_SERVER['PHP_SELF'] ?? $_SERVER['SCRIPT_NAME'] ?? '';
    if (basename($scriptName) === basename(__FILE__) || $scriptName === __FILE__) {
        processPushWebcams();
    }
}

