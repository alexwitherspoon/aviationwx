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
 * Get last processed time for a camera
 * 
 * Retrieves the timestamp of the last successfully processed image for a camera.
 * Used to skip already-processed files and prevent duplicate processing.
 * 
 * @param string $airportId Airport ID (e.g., 'kspb')
 * @param int $camIndex Camera index (0-based)
 * @return int Unix timestamp of last processed file, or 0 if none
 */
function getLastProcessedTime($airportId, $camIndex) {
    $trackDir = __DIR__ . '/../cache/push_webcams';
    $trackFile = $trackDir . '/last_processed.json';
    
    if (!file_exists($trackFile)) {
        return 0;
    }
    
    $data = @json_decode(@file_get_contents($trackFile), true);
    if (!is_array($data)) {
        return 0;
    }
    
    $key = $airportId . '_' . $camIndex;
    return isset($data[$key]) ? intval($data[$key]) : 0;
}

/**
 * Update last processed time for a camera
 * 
 * Updates the timestamp of the last successfully processed image for a camera.
 * Uses file locking to ensure atomic updates in concurrent environments.
 * 
 * @param string $airportId Airport ID (e.g., 'kspb')
 * @param int $camIndex Camera index (0-based)
 * @return void
 */
function updateLastProcessedTime($airportId, $camIndex) {
    $trackDir = __DIR__ . '/../cache/push_webcams';
    $trackFile = $trackDir . '/last_processed.json';
    
    if (!is_dir($trackDir)) {
        @mkdir($trackDir, 0755, true);
    }
    
    // Use file locking for atomic update
    $fp = @fopen($trackFile, 'c+');
    if (!$fp) {
        return;
    }
    
    if (@flock($fp, LOCK_EX)) {
        $data = [];
        if (filesize($trackFile) > 0) {
            $content = @stream_get_contents($fp);
            if ($content) {
                $data = @json_decode($content, true) ?: [];
            }
        }
        
        $key = $airportId . '_' . $camIndex;
        $data[$key] = time();
        
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
 * Optimized to exit quickly if no new files are found
 * 
 * @param string $uploadDir Upload directory path
 * @param int $maxWaitSeconds Maximum wait time for file to be fully written
 * @param int|null $lastProcessedTime Timestamp of last processed file (null = no filter)
 * @param array|null $pushConfig Optional push_config for per-camera validation
 * @param array|null $airport Optional airport config for phase-aware pixelation detection
 * @return string|null Path to valid image file or null
 */
function findNewestValidImage($uploadDir, $maxWaitSeconds, $lastProcessedTime = null, $pushConfig = null, $airport = null) {
    if (!is_dir($uploadDir)) {
        return null;
    }
    
    $files = glob($uploadDir . '*.{jpg,jpeg,png,webp,avif}', GLOB_BRACE);
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
    
    $startTime = time();
    foreach ($files as $file) {
        // Check if file is fully written (only wait if we have a candidate)
        if (isFileFullyWritten($file, $maxWaitSeconds, $startTime)) {
            // #region agent log
            $debugLogPath = '/var/www/html/.cursor/debug.log';
            @mkdir(dirname($debugLogPath), 0755, true);
            $logEntry = json_encode(['location' => 'process-push-webcams.php:findNewestValidImage', 'message' => 'validating file', 'data' => ['file' => basename($file)], 'timestamp' => time() * 1000, 'sessionId' => 'debug-session', 'hypothesisId' => 'H1-EXIF']) . "\n";
            @file_put_contents($debugLogPath, $logEntry, FILE_APPEND);
            // #endregion
            
            // Push cameras may not have EXIF - add from file mtime before validation
            // Server-fetched webcams have EXIF added after capture, but push cameras bypass that step
            $hadExif = hasExifTimestamp($file);
            if (!$hadExif) {
                $exifAdded = ensureExifTimestamp($file);
                // #region agent log
                $logEntry = json_encode(['location' => 'process-push-webcams.php:findNewestValidImage', 'message' => 'added EXIF timestamp', 'data' => ['file' => basename($file), 'had_exif' => $hadExif, 'exif_added' => $exifAdded], 'timestamp' => time() * 1000, 'sessionId' => 'debug-session', 'hypothesisId' => 'H1-EXIF']) . "\n";
                @file_put_contents($debugLogPath, $logEntry, FILE_APPEND);
                // #endregion
            }
            
            // Validate image (with per-camera limits and airport for phase-aware detection)
            if (validateImageFile($file, $pushConfig, $airport)) {
                // #region agent log
                $logEntry = json_encode(['location' => 'process-push-webcams.php:findNewestValidImage', 'message' => 'validation PASSED', 'data' => ['file' => basename($file)], 'timestamp' => time() * 1000, 'sessionId' => 'debug-session', 'hypothesisId' => 'H1-EXIF']) . "\n";
                @file_put_contents($debugLogPath, $logEntry, FILE_APPEND);
                // #endregion
                return $file;
            } else {
                // #region agent log
                $logEntry = json_encode(['location' => 'process-push-webcams.php:findNewestValidImage', 'message' => 'validation FAILED', 'data' => ['file' => basename($file)], 'timestamp' => time() * 1000, 'sessionId' => 'debug-session', 'hypothesisId' => 'H1-EXIF']) . "\n";
                @file_put_contents($debugLogPath, $logEntry, FILE_APPEND);
                // #endregion
            }
        }
    }
    
    return null;
}

/**
 * Check if file is fully written
 * 
 * Determines if a file has finished being written by checking size stability.
 * Optimized to quickly return true for old files (>= 2 seconds old).
 * For newer files, waits and checks if size remains stable.
 * 
 * @param string $file File path to check
 * @param int $maxWaitSeconds Maximum wait time in seconds
 * @param int $startTime Start timestamp (for calculating elapsed time)
 * @return bool True if file appears fully written, false otherwise
 */
function isFileFullyWritten($file, $maxWaitSeconds, $startTime) {
    $maxWait = time() - $startTime >= $maxWaitSeconds;
    
    // Quick check: if file is old (more than 2 seconds), assume it's stable
    $mtime = @filemtime($file);
    if ($mtime !== false) {
        $age = time() - $mtime;
        if ($age >= 2) {
            // File is at least 2 seconds old, very likely fully written
            // Still do a quick size check to be sure
            $size = @filesize($file);
            if ($size !== false && $size > 0) {
                return true;
            }
        }
    }
    
    // For newer files, check size stability
    $size1 = @filesize($file);
    if ($size1 === false) {
        return false;
    }
    
    // Wait a bit and check again (only for files that might still be writing)
    usleep(500000); // 0.5 seconds
    
    $size2 = @filesize($file);
    if ($size2 === false) {
        return false;
    }
    
    // If sizes match and we've waited enough or hit max wait, consider it stable
    if ($size1 === $size2) {
        // Check mtime - file should be at least 1 second old (not currently being written)
        if ($mtime === false) {
            $mtime = @filemtime($file);
        }
        if ($mtime !== false) {
            $age = time() - $mtime;
            if ($age >= 1 || $maxWait) {
                return true;
            }
        } else {
            // Can't get mtime, but sizes match - assume stable
            return $maxWait;
        }
    }
    
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
 * @return bool True if file is valid image, false otherwise
 */
function validateImageFile($file, $pushConfig = null, $airport = null) {
    if (!file_exists($file) || !is_readable($file)) {
        return false;
    }
    
    $size = filesize($file);
    
    // Check minimum size (too small to be a valid image)
    if ($size < 100) {
        return false;
    }
    
    // Check maximum size (per-camera limit if provided, otherwise default 100MB)
    $maxSizeBytes = 100 * 1024 * 1024; // Default 100MB
    if ($pushConfig && isset($pushConfig['max_file_size_mb'])) {
        $maxSizeBytes = intval($pushConfig['max_file_size_mb']) * 1024 * 1024;
    }
    if ($size > $maxSizeBytes) {
        return false;
    }
    
    // Check file extension if allowed_extensions specified
    if ($pushConfig && isset($pushConfig['allowed_extensions']) && is_array($pushConfig['allowed_extensions'])) {
        $ext = strtolower(pathinfo($file, PATHINFO_EXTENSION));
        $allowed = array_map('strtolower', $pushConfig['allowed_extensions']);
        if (!in_array($ext, $allowed)) {
            return false;
        }
    }
    
    // Check MIME type
    $mime = @mime_content_type($file);
    $validMimes = ['image/jpeg', 'image/jpg', 'image/png', 'image/webp', 'image/avif'];
    if (!in_array($mime, $validMimes)) {
        return false;
    }
    
    // Check image headers using shared format detection
    $format = detectImageFormat($file);
    if ($format === null) {
        return false;
    }
    
    // Validate image content (error frame, uniform color, pixelation, etc.)
    // Only for JPEG which detectErrorFrame supports
    // Pass airport for phase-aware pixelation thresholds
    if ($format === 'jpeg') {
        $errorCheck = detectErrorFrame($file, $airport);
        // #region agent log
        $debugLogPath = '/var/www/html/.cursor/debug.log';
        @mkdir(dirname($debugLogPath), 0755, true);
        $logEntry = json_encode(['location' => 'process-push-webcams.php:validateImageFile', 'message' => 'error frame check', 'data' => ['file' => basename($file), 'is_error' => $errorCheck['is_error'], 'confidence' => $errorCheck['confidence'], 'reasons' => $errorCheck['reasons'] ?? []], 'timestamp' => time() * 1000, 'sessionId' => 'debug-session', 'hypothesisId' => 'H2-pixelation']) . "\n";
        @file_put_contents($debugLogPath, $logEntry, FILE_APPEND);
        // #endregion
        if ($errorCheck['is_error']) {
            aviationwx_log('warning', 'push webcam error frame detected, rejecting', [
                'file' => basename($file),
                'confidence' => $errorCheck['confidence'],
                'reasons' => $errorCheck['reasons']
            ], 'app');
            return false;
        }
    }
    
    // Validate EXIF timestamp (push cameras must have camera-provided EXIF)
    $exifCheck = validateExifTimestamp($file);
    // #region agent log
    $logEntry = json_encode(['location' => 'process-push-webcams.php:validateImageFile', 'message' => 'EXIF timestamp check', 'data' => ['file' => basename($file), 'valid' => $exifCheck['valid'], 'reason' => $exifCheck['reason'] ?? 'ok', 'timestamp' => $exifCheck['timestamp'] > 0 ? date('Y-m-d H:i:s', $exifCheck['timestamp']) : 'none'], 'timestamp' => time() * 1000, 'sessionId' => 'debug-session', 'hypothesisId' => 'H1-EXIF']) . "\n";
    @file_put_contents($debugLogPath, $logEntry, FILE_APPEND);
    // #endregion
    if (!$exifCheck['valid']) {
        aviationwx_log('warning', 'push webcam EXIF timestamp invalid, rejecting', [
            'file' => basename($file),
            'reason' => $exifCheck['reason'],
            'timestamp' => $exifCheck['timestamp'] > 0 ? date('Y-m-d H:i:s', $exifCheck['timestamp']) : 'none'
        ], 'app');
        return false;
    }
    
    return true;
}

/**
 * Harvest all valid images from upload directory for history
 * 
 * For push cameras, multiple images may accumulate between processing intervals.
 * When history is enabled, save all valid images to history before processing.
 * This captures intermediate frames that would otherwise be discarded.
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
    
    $files = glob($uploadDir . '*.{jpg,jpeg,png,webp,avif}', GLOB_BRACE);
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
        
        // Save to history (uses EXIF or mtime for timestamp)
        if (saveFrameToHistory($file, $airportId, $camIndex)) {
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
    
    $cacheDir = __DIR__ . '/../cache/webcams';
    if (!is_dir($cacheDir)) {
        if (!@mkdir($cacheDir, 0755, true)) {
            aviationwx_log('error', 'moveToCache: cache directory creation failed', [
                'dir' => $cacheDir
            ], 'app');
            return false;
        }
    }
    
    if (!is_writable($cacheDir)) {
        aviationwx_log('error', 'moveToCache: cache directory not writable', [
            'dir' => $cacheDir
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
        // Keep original format (JPEG, WebP, or AVIF)
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
        saveAllFormatsToHistory($airportId, $camIndex, $promotedFormats, $timestamp);
    } else {
        // Generate all variants and formats in parallel (synchronous wait)
        // All variants × formats are written to staging files (.tmp)
        $variantResult = generateVariantsSync($stagingFile, $airportId, $camIndex, $primaryFormat, $inputDimensions);
        
        // Promote all successful staging files to final cache location (with timestamp for filename)
        $promotedVariants = promoteVariants(
            $airportId,
            $camIndex,
            $variantResult['results'],
            $primaryFormat,
            $timestamp,
            $variantResult['delete_original'],
            $variantResult['delete_original'] ? $stagingFile : null
        );
        
        if (empty($promotedVariants)) {
            aviationwx_log('error', 'moveToCache: no variants promoted', [
                'airport' => $airportId,
                'cam' => $camIndex,
                'primary_format' => $primaryFormat
            ], 'app');
            cleanupStagingFiles($airportId, $camIndex);
            return false;
        }
        
        // Save all promoted variants to history (if enabled for this airport)
        saveAllVariantsToHistory($airportId, $camIndex, $promotedVariants, $timestamp);
        
        // Convert for logging compatibility
        $promotedFormats = [];
        foreach ($promotedVariants as $variant => $formats) {
            $promotedFormats = array_merge($promotedFormats, $formats);
        }
        $promotedFormats = array_unique($promotedFormats);
    }
    
    // Cleanup old timestamp files (keep only recent ones to prevent disk space issues)
    cleanupOldTimestampFiles($airportId, $camIndex, 5);
    
    // Log final result
    $allRequestedFormats = [$primaryFormat];
    if ($primaryFormat !== 'jpg') $allRequestedFormats[] = 'jpg';
    if (isWebpGenerationEnabled() && $primaryFormat !== 'webp') $allRequestedFormats[] = 'webp';
    if (isAvifGenerationEnabled() && $primaryFormat !== 'avif') $allRequestedFormats[] = 'avif';
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
 * Removes old files from upload directory. Only deletes files with modification time
 * older than or equal to the specified cutoff time. This prevents race conditions where
 * new files might be deleted while still being uploaded.
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
    
    $files = glob($uploadDir . '*');
    if ($files === false) {
        aviationwx_log('warning', 'cleanupUploadDirectory: glob failed', [
            'dir' => $uploadDir
        ], 'app');
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
    
    if ($deletedCount > 0 || $skippedCount > 0) {
        aviationwx_log('debug', 'cleanupUploadDirectory: cleanup completed', [
            'dir' => $uploadDir,
            'deleted' => $deletedCount,
            'skipped_newer' => $skippedCount,
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
    
    $baseDir = __DIR__ . '/../cache/webcam/uploads/';
    $chrootDir = $baseDir . $airportId . '/' . $username;
    $uploadDir = $chrootDir . '/';
    
    // Quick check: if directory doesn't exist or has no files, exit immediately
    if (!is_dir($uploadDir)) {
        return false; // No directory - exit immediately
    }
    
    // Quick check: if no image files exist, exit immediately (no waiting)
    $hasFiles = !empty(glob($uploadDir . '*.{jpg,jpeg,png,webp,avif}', GLOB_BRACE));
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
    
    $maxWaitSeconds = intval($refreshSeconds / 2);
    
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
    
    // Find newest valid image (with per-camera limits and airport for phase-aware detection)
    // Pass lastProcessed time to quickly skip old files
    $newestFile = findNewestValidImage($uploadDir, $maxWaitSeconds, $lastProcessed, $pushConfig, $airport);
    
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

