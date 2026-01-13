<?php
/**
 * Webcam Rejection Logger
 * 
 * Saves rejected webcam images and diagnostic information for analysis.
 * Creates rejections directory per camera with timestamped files.
 */

require_once __DIR__ . '/logger.php';
require_once __DIR__ . '/constants.php';

/**
 * Save rejected webcam image and diagnostic log
 * 
 * Saves rejected image to camera's rejections directory with timestamp.
 * Creates comprehensive diagnostic log with rejection reason and metadata.
 * Uses EXIF DateTimeOriginal if available, falls back to file mtime.
 * 
 * @param string $imagePath Path to rejected image file
 * @param string $airportId Airport ID (e.g., 'kspb')
 * @param int $camIndex Camera index (0-based)
 * @param string $rejectionReason Rejection reason (e.g., 'error_frame', 'pixelated')
 * @param array $diagnosticData Additional diagnostic data to log
 * @return bool True if saved successfully, false otherwise
 */
function saveRejectedWebcam(string $imagePath, string $airportId, int $camIndex, string $rejectionReason, array $diagnosticData = []): bool {
    if (!file_exists($imagePath) || !is_readable($imagePath)) {
        aviationwx_log('warning', 'cannot save rejected webcam - file not readable', [
            'file' => $imagePath,
            'airport' => $airportId,
            'cam' => $camIndex
        ], 'app');
        return false;
    }
    
    // Get cache base directory
    $cacheBase = getCacheDirectory();
    if (!$cacheBase) {
        aviationwx_log('warning', 'cannot save rejected webcam - no cache directory', [
            'airport' => $airportId,
            'cam' => $camIndex
        ], 'app');
        return false;
    }
    
    // Create rejections directory: cache/webcams/{airport}/{cam}/rejections/
    $rejectionsDir = $cacheBase . '/webcams/' . strtolower($airportId) . '/' . $camIndex . '/rejections';
    if (!is_dir($rejectionsDir)) {
        if (!@mkdir($rejectionsDir, 0755, true)) {
            aviationwx_log('warning', 'cannot create rejections directory', [
                'dir' => $rejectionsDir,
                'airport' => $airportId,
                'cam' => $camIndex
            ], 'app');
            return false;
        }
    }
    
    // Get timestamp for filename (prefer EXIF, fallback to mtime)
    $timestamp = getImageTimestamp($imagePath);
    
    // Get file extension
    $ext = strtolower(pathinfo($imagePath, PATHINFO_EXTENSION));
    if (!in_array($ext, ['jpg', 'jpeg', 'png', 'webp'])) {
        $ext = 'jpg'; // Default fallback
    }
    
    // Create unique filenames with timestamp
    $basename = $timestamp . '_rejected';
    $imageFile = $rejectionsDir . '/' . $basename . '.' . $ext;
    $logFile = $rejectionsDir . '/' . $basename . '.log';
    
    // If files already exist, append counter to make unique
    $counter = 1;
    while (file_exists($imageFile) || file_exists($logFile)) {
        $basename = $timestamp . '_rejected_' . $counter;
        $imageFile = $rejectionsDir . '/' . $basename . '.' . $ext;
        $logFile = $rejectionsDir . '/' . $basename . '.log';
        $counter++;
        
        // Safety: Don't create infinite loop
        if ($counter > 100) {
            aviationwx_log('warning', 'too many rejections with same timestamp', [
                'timestamp' => $timestamp,
                'airport' => $airportId,
                'cam' => $camIndex
            ], 'app');
            return false;
        }
    }
    
    // Copy image file
    if (!@copy($imagePath, $imageFile)) {
        aviationwx_log('warning', 'failed to copy rejected image', [
            'source' => $imagePath,
            'dest' => $imageFile,
            'airport' => $airportId,
            'cam' => $camIndex
        ], 'app');
        return false;
    }
    
    // Build diagnostic log content
    $logContent = buildRejectionLog($imagePath, $imageFile, $airportId, $camIndex, $rejectionReason, $diagnosticData);
    
    // Write log file
    if (@file_put_contents($logFile, $logContent) === false) {
        aviationwx_log('warning', 'failed to write rejection log', [
            'file' => $logFile,
            'airport' => $airportId,
            'cam' => $camIndex
        ], 'app');
        // Don't return false - we at least saved the image
    }
    
    aviationwx_log('info', 'saved rejected webcam image', [
        'image' => basename($imageFile),
        'log' => basename($logFile),
        'reason' => $rejectionReason,
        'airport' => $airportId,
        'cam' => $camIndex
    ], 'app');
    
    return true;
}

/**
 * Get image timestamp from EXIF or file mtime
 * 
 * Attempts to read EXIF DateTimeOriginal, falls back to file modification time.
 * Returns Unix timestamp suitable for filenames.
 * 
 * @param string $imagePath Path to image file
 * @return int Unix timestamp
 */
function getImageTimestamp(string $imagePath): int {
    // Try EXIF first if exiftool is available
    if (function_exists('isExiftoolAvailable') && isExiftoolAvailable()) {
        if (function_exists('getExifTimestamp')) {
            $exifTimestamp = getExifTimestamp($imagePath);
            if ($exifTimestamp !== null && $exifTimestamp > 0) {
                return $exifTimestamp;
            }
        }
    }
    
    // Fallback to file modification time
    $mtime = @filemtime($imagePath);
    return ($mtime !== false) ? $mtime : time();
}

/**
 * Build comprehensive rejection log
 * 
 * Creates detailed diagnostic log with all relevant information about rejection.
 * Includes file metadata, rejection reason, diagnostic data, and system context.
 * 
 * @param string $originalPath Original path to rejected file
 * @param string $savedPath Path where rejected file was saved
 * @param string $airportId Airport ID
 * @param int $camIndex Camera index
 * @param string $rejectionReason Rejection reason code
 * @param array $diagnosticData Additional diagnostic data
 * @return string Formatted log content
 */
function buildRejectionLog(string $originalPath, string $savedPath, string $airportId, int $camIndex, string $rejectionReason, array $diagnosticData): string {
    $timestamp = date('Y-m-d H:i:s T');
    $log = [];
    
    $log[] = "================================================================================";
    $log[] = "WEBCAM REJECTION LOG";
    $log[] = "================================================================================";
    $log[] = "Generated: $timestamp";
    $log[] = "";
    
    // Basic Information
    $log[] = "CAMERA INFORMATION:";
    $log[] = "  Airport ID:     $airportId";
    $log[] = "  Camera Index:   $camIndex";
    $log[] = "  Rejection Time: $timestamp";
    $log[] = "";
    
    // Rejection Details
    $log[] = "REJECTION DETAILS:";
    $log[] = "  Reason:         $rejectionReason";
    $log[] = "  Original Path:  $originalPath";
    $log[] = "  Saved Path:     $savedPath";
    $log[] = "";
    
    // File Metadata
    $log[] = "FILE METADATA:";
    if (file_exists($originalPath)) {
        $filesize = @filesize($originalPath);
        $mtime = @filemtime($originalPath);
        $mime = @mime_content_type($originalPath);
        
        $log[] = "  Size:           " . ($filesize !== false ? number_format($filesize) . " bytes" : "unknown");
        $log[] = "  MIME Type:      " . ($mime ?: "unknown");
        $log[] = "  Modified Time:  " . ($mtime !== false ? date('Y-m-d H:i:s T', $mtime) : "unknown");
        
        // Get image dimensions if possible
        $imageInfo = @getimagesize($originalPath);
        if ($imageInfo !== false) {
            $log[] = "  Dimensions:     {$imageInfo[0]}x{$imageInfo[1]}";
            $log[] = "  Type:           " . image_type_to_mime_type($imageInfo[2]);
        }
    } else {
        $log[] = "  (File no longer accessible)";
    }
    $log[] = "";
    
    // EXIF Data (if available)
    if (function_exists('isExiftoolAvailable') && isExiftoolAvailable() && function_exists('getExifTimestamp')) {
        $exifTimestamp = getExifTimestamp($originalPath);
        $log[] = "EXIF DATA:";
        if ($exifTimestamp !== null && $exifTimestamp > 0) {
            $log[] = "  DateTimeOriginal: " . date('Y-m-d H:i:s T', $exifTimestamp);
        } else {
            $log[] = "  DateTimeOriginal: (not found or invalid)";
        }
        $log[] = "";
    }
    
    // Diagnostic Data
    if (!empty($diagnosticData)) {
        $log[] = "DIAGNOSTIC DATA:";
        foreach ($diagnosticData as $key => $value) {
            if (is_array($value) || is_object($value)) {
                $log[] = "  $key:";
                $jsonValue = json_encode($value, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
                foreach (explode("\n", $jsonValue) as $line) {
                    $log[] = "    " . $line;
                }
            } else {
                $log[] = "  $key: " . $value;
            }
        }
        $log[] = "";
    }
    
    // System Context
    $log[] = "SYSTEM CONTEXT:";
    $log[] = "  PHP Version:    " . PHP_VERSION;
    $log[] = "  Server Time:    " . date('Y-m-d H:i:s T');
    $log[] = "  Process ID:     " . getmypid();
    
    // Memory usage
    $memUsage = memory_get_usage(true);
    $memPeak = memory_get_peak_usage(true);
    $log[] = "  Memory Usage:   " . number_format($memUsage / 1024 / 1024, 2) . " MB";
    $log[] = "  Memory Peak:    " . number_format($memPeak / 1024 / 1024, 2) . " MB";
    
    // Disk space
    $diskFree = @disk_free_space(dirname($savedPath));
    $diskTotal = @disk_total_space(dirname($savedPath));
    if ($diskFree !== false && $diskTotal !== false) {
        $diskUsedPercent = 100 - (($diskFree / $diskTotal) * 100);
        $log[] = "  Disk Free:      " . number_format($diskFree / 1024 / 1024 / 1024, 2) . " GB";
        $log[] = "  Disk Used:      " . number_format($diskUsedPercent, 1) . "%";
    }
    
    $log[] = "";
    $log[] = "================================================================================";
    $log[] = "END OF REJECTION LOG";
    $log[] = "================================================================================";
    $log[] = "";
    
    return implode("\n", $log);
}

/**
 * Get cache directory path
 * 
 * Returns the cache base directory, checking multiple locations.
 * Handles both Docker container and host environments.
 * 
 * @return string|null Cache directory path or null if not found
 */
function getCacheDirectory(): ?string {
    // Check common cache locations in order of preference
    $locations = [
        '/tmp/aviationwx-cache',           // Docker production/dev
        dirname(__DIR__) . '/cache',        // Local dev (relative to lib/)
        '/var/www/html/cache'               // Container fallback
    ];
    
    foreach ($locations as $location) {
        if (is_dir($location) && is_writable($location)) {
            return $location;
        }
    }
    
    return null;
}

/**
 * Clean up old rejected webcam files
 * 
 * Removes rejected images and logs older than the specified age.
 * Runs across all camera rejection directories.
 * 
 * @param int $maxAgeDays Maximum age in days (default: 7)
 * @return array Statistics about cleanup operation
 */
function cleanupOldRejections(int $maxAgeDays = 7): array {
    $stats = [
        'cameras_checked' => 0,
        'files_removed' => 0,
        'bytes_freed' => 0,
        'errors' => []
    ];
    
    $cacheBase = getCacheDirectory();
    if (!$cacheBase) {
        $stats['errors'][] = 'Cache directory not found';
        return $stats;
    }
    
    $webcamsDir = $cacheBase . '/webcams';
    if (!is_dir($webcamsDir)) {
        return $stats; // No webcams directory yet
    }
    
    $maxAge = time() - ($maxAgeDays * 86400);
    
    // Iterate through all airport directories
    $airports = glob($webcamsDir . '/*', GLOB_ONLYDIR);
    foreach ($airports as $airportDir) {
        // Iterate through all camera directories
        $cameras = glob($airportDir . '/*', GLOB_ONLYDIR);
        foreach ($cameras as $cameraDir) {
            $rejectionsDir = $cameraDir . '/rejections';
            if (!is_dir($rejectionsDir)) {
                continue;
            }
            
            $stats['cameras_checked']++;
            
            // Find all files in rejections directory
            $files = glob($rejectionsDir . '/*');
            foreach ($files as $file) {
                if (!is_file($file)) {
                    continue;
                }
                
                $mtime = @filemtime($file);
                if ($mtime === false) {
                    continue;
                }
                
                // Remove if older than max age
                if ($mtime < $maxAge) {
                    $size = @filesize($file);
                    if (@unlink($file)) {
                        $stats['files_removed']++;
                        if ($size !== false) {
                            $stats['bytes_freed'] += $size;
                        }
                    } else {
                        $stats['errors'][] = 'Failed to remove: ' . basename($file);
                    }
                }
            }
        }
    }
    
    return $stats;
}
