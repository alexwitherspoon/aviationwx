<?php
/**
 * Push Webcam File Processor
 * Processes uploaded images from push cameras
 * Runs via cron every minute
 */

require_once __DIR__ . '/../lib/config.php';
require_once __DIR__ . '/../lib/logger.php';

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
 * @return string|null Path to valid image file or null
 */
function findNewestValidImage($uploadDir, $maxWaitSeconds, $lastProcessedTime = null, $pushConfig = null) {
    if (!is_dir($uploadDir)) {
        return null;
    }
    
    $files = glob($uploadDir . '*.{jpg,jpeg,png}', GLOB_BRACE);
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
            // Validate image (with per-camera limits if provided)
            if (validateImageFile($file, $pushConfig)) {
                return $file;
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
 * 
 * @param string $file File path to validate
 * @param array|null $pushConfig Optional push_config for per-camera validation limits
 *   - max_file_size_mb: Maximum file size in MB (default: 100)
 *   - allowed_extensions: Array of allowed extensions (default: all)
 * @return bool True if file is valid image, false otherwise
 */
function validateImageFile($file, $pushConfig = null) {
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
    $validMimes = ['image/jpeg', 'image/jpg', 'image/png'];
    if (!in_array($mime, $validMimes)) {
        return false;
    }
    
    // Check image headers
    $handle = @fopen($file, 'rb');
    if (!$handle) {
        return false;
    }
    
    $header = @fread($handle, 12);
    @fclose($handle);
    
    if (!$header) {
        return false;
    }
    
    // JPEG: FF D8 FF
    if (substr($header, 0, 3) === "\xFF\xD8\xFF") {
        return true;
    }
    
    // PNG: 89 50 4E 47 0D 0A 1A 0A
    if (substr($header, 0, 8) === "\x89\x50\x4E\x47\x0D\x0A\x1A\x0A") {
        return true;
    }
    
    return false;
}

/**
 * Move image to cache
 * 
 * Atomically moves a validated image file from upload directory to cache directory.
 * Validates source file before moving and triggers WEBP generation in background.
 * 
 * @param string $sourceFile Source file path in upload directory
 * @param string $airportId Airport ID (e.g., 'kspb')
 * @param int $camIndex Camera index (0-based)
 * @return string|false Cache file path on success, false on failure
 */
function moveToCache($sourceFile, $airportId, $camIndex) {
    // Validate source file exists and is readable
    if (!file_exists($sourceFile) || !is_readable($sourceFile)) {
        aviationwx_log('error', 'moveToCache: source file invalid', [
            'source' => $sourceFile,
            'airport' => $airportId,
            'cam' => $camIndex
        ], 'app');
        return false;
    }
    
    // Validate file size is reasonable
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
    
    // Validate cache directory is writable
    if (!is_writable($cacheDir)) {
        aviationwx_log('error', 'moveToCache: cache directory not writable', [
            'dir' => $cacheDir
        ], 'app');
        return false;
    }
    
    $cacheFile = $cacheDir . '/' . $airportId . '_' . $camIndex . '.jpg';
    
    // Atomic move
    if (@rename($sourceFile, $cacheFile)) {
        // Verify move succeeded
        if (file_exists($cacheFile) && filesize($cacheFile) > 0) {
            // Generate WEBP in background (non-blocking)
            generateWebp($cacheFile, $airportId, $camIndex);
            return $cacheFile;
        } else {
            aviationwx_log('error', 'moveToCache: move appeared to succeed but file invalid', [
                'cache_file' => $cacheFile
            ], 'app');
            return false;
        }
    } else {
        $error = error_get_last();
        aviationwx_log('error', 'moveToCache: rename failed', [
            'source' => $sourceFile,
            'dest' => $cacheFile,
            'error' => $error['message'] ?? 'unknown'
        ], 'app');
    }
    
    return false;
}

/**
 * Generate WEBP version of image (non-blocking)
 * 
 * Converts JPG cache file to WEBP format using ffmpeg. Runs in background
 * when possible to avoid blocking. Falls back to synchronous generation with
 * timeout if exec() is unavailable.
 * 
 * @param string $cacheFile JPG cache file path
 * @param string $airportId Airport ID (e.g., 'kspb')
 * @param int $camIndex Camera index (0-based)
 * @return bool True if WEBP generation started/succeeded, false on failure
 */
function generateWebp($cacheFile, $airportId, $camIndex) {
    // Validate source file exists and is readable
    if (!file_exists($cacheFile) || !is_readable($cacheFile)) {
        return false;
    }
    
    $cacheDir = __DIR__ . '/../cache/webcams';
    if (!is_dir($cacheDir) || !is_writable($cacheDir)) {
        return false;
    }
    
    $cacheWebp = $cacheDir . '/' . $airportId . '_' . $camIndex . '.webp';
    
    // Build ffmpeg command
    $cmdWebp = sprintf(
        "ffmpeg -hide_banner -loglevel error -y -i %s -frames:v 1 -q:v 30 -compression_level 6 -preset default %s",
        escapeshellarg($cacheFile),
        escapeshellarg($cacheWebp)
    );
    
    // Run in background (non-blocking)
    if (function_exists('exec')) {
        $cmd = $cmdWebp . ' > /dev/null 2>&1 &';
        @exec($cmd);
        return true;
    }
    
    // Fallback: synchronous with timeout
    $processWebp = @proc_open($cmdWebp . ' 2>&1', [
        0 => ['pipe', 'r'],
        1 => ['pipe', 'w'],
        2 => ['pipe', 'w']
    ], $pipesWebp);
    
    if (is_resource($processWebp)) {
        fclose($pipesWebp[0]);
        $startTime = microtime(true);
        $timeout = 8; // 8 second timeout
        
        while (true) {
            $status = proc_get_status($processWebp);
            if (!$status['running']) {
                proc_close($processWebp);
                if (file_exists($cacheWebp) && filesize($cacheWebp) > 0) {
                    return true;
                }
                return false;
            }
            if ((microtime(true) - $startTime) > $timeout) {
                @proc_terminate($processWebp);
                @proc_close($processWebp);
                return false;
            }
            usleep(50000); // 50ms
        }
    }
    
    return false;
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
    // Files uploaded directly to chroot root directory; fall back to incoming/ for backward compatibility
    $chrootDir = __DIR__ . '/../uploads/webcams/' . $airportId . '_' . $camIndex;
    $incomingDir = $chrootDir . '/incoming/';
    
    $uploadDir = is_dir($chrootDir) && is_writable($chrootDir) ? $chrootDir . '/' : $incomingDir;
    
    // Quick check: if directory doesn't exist or has no files, exit immediately
    if (!is_dir($uploadDir)) {
        return false; // No directory - exit immediately
    }
    
    // Quick check: if no image files exist, exit immediately (no waiting)
    $hasFiles = !empty(glob($uploadDir . '*.{jpg,jpeg,png}', GLOB_BRACE));
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
    
    // Find newest valid image (with per-camera limits)
    // Pass lastProcessed time to quickly skip old files
    $newestFile = findNewestValidImage($uploadDir, $maxWaitSeconds, $lastProcessed, $pushConfig);
    
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

