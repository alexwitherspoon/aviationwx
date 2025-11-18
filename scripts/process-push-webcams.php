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
 */
function getCameraRefreshSeconds($cam, $airport) {
    if (isset($cam['refresh_seconds'])) {
        return max(60, intval($cam['refresh_seconds']));
    }
    if (isset($airport['webcam_refresh_seconds'])) {
        return max(60, intval($airport['webcam_refresh_seconds']));
    }
    $default = getenv('WEBCAM_REFRESH_DEFAULT') ? intval(getenv('WEBCAM_REFRESH_DEFAULT')) : 300;
    return max(60, $default);
}

/**
 * Check if directory is writable
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
 * Check disk space
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
 */
function findNewestValidImage($uploadDir, $maxWaitSeconds) {
    if (!is_dir($uploadDir)) {
        return null;
    }
    
    $files = glob($uploadDir . '*.{jpg,jpeg,png}', GLOB_BRACE);
    if (empty($files)) {
        return null;
    }
    
    // Sort by mtime (newest first)
    usort($files, function($a, $b) {
        return filemtime($b) - filemtime($a);
    });
    
    $startTime = time();
    foreach ($files as $file) {
        // Check if file is fully written
        if (isFileFullyWritten($file, $maxWaitSeconds, $startTime)) {
            // Validate image
            if (validateImageFile($file)) {
                return $file;
            }
        }
    }
    
    return null;
}

/**
 * Check if file is fully written
 */
function isFileFullyWritten($file, $maxWaitSeconds, $startTime) {
    $maxWait = time() - $startTime >= $maxWaitSeconds;
    
    // Check file size stability
    $size1 = @filesize($file);
    if ($size1 === false) {
        return false;
    }
    
    // Wait a bit and check again
    usleep(500000); // 0.5 seconds
    
    $size2 = @filesize($file);
    if ($size2 === false) {
        return false;
    }
    
    // If sizes match and we've waited enough or hit max wait, consider it stable
    if ($size1 === $size2) {
        // Check mtime - file should be at least 1 second old (not currently being written)
        $mtime = filemtime($file);
        $age = time() - $mtime;
        
        if ($age >= 1 || $maxWait) {
            return true;
        }
    }
    
    return false;
}

/**
 * Validate image file
 */
function validateImageFile($file) {
    if (!file_exists($file) || !is_readable($file)) {
        return false;
    }
    
    // Check file size (max 10MB default, but will be checked per camera)
    $size = filesize($file);
    if ($size < 100) { // Too small to be a valid image
        return false;
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
 */
function moveToCache($sourceFile, $airportId, $camIndex) {
    $cacheDir = __DIR__ . '/../cache/webcams';
    if (!is_dir($cacheDir)) {
        @mkdir($cacheDir, 0755, true);
    }
    
    $cacheFile = $cacheDir . '/' . $airportId . '_' . $camIndex . '.jpg';
    
    // Atomic move
    if (@rename($sourceFile, $cacheFile)) {
        return $cacheFile;
    }
    
    return false;
}

/**
 * Clean up upload directory
 */
function cleanupUploadDirectory($uploadDir, $keepFile = null) {
    $files = glob($uploadDir . '*');
    
    foreach ($files as $file) {
        if (is_file($file)) {
            $basename = basename($file);
            // Keep the processed file if specified
            if ($keepFile && $basename === basename($keepFile)) {
                continue;
            }
            // Delete all other files
            @unlink($file);
        }
    }
}

/**
 * Process a single push camera
 */
function processPushCamera($airportId, $camIndex, $cam, $airport) {
    $uploadDir = __DIR__ . '/../uploads/webcams/' . $airportId . '_' . $camIndex . '/incoming/';
    
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
    
    // Find newest valid image
    $newestFile = findNewestValidImage($uploadDir, $maxWaitSeconds);
    
    if (!$newestFile) {
        aviationwx_log('info', 'no valid image found', [
            'airport' => $airportId,
            'cam' => $camIndex
        ], 'app');
        return false;
    }
    
    // Move to cache
    $cacheFile = moveToCache($newestFile, $airportId, $camIndex);
    
    if ($cacheFile) {
        // Clean up upload directory (delete all files)
        cleanupUploadDirectory($uploadDir);
        
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
        aviationwx_log('error', 'cannot create lock file', ['lock_file' => $lockFile], 'app');
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

// Run processor
processPushWebcams();

