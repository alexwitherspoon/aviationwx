<?php
/**
 * Webcam Image Quarantine System
 * 
 * Preserves rejected images with metadata for debugging
 */

require_once __DIR__ . '/constants.php';
require_once __DIR__ . '/logger.php';

/**
 * Move rejected image to quarantine with metadata log
 * 
 * @param string $sourceFile Path to rejected image file
 * @param string $airportId Airport identifier
 * @param int $camIndex Camera index (0-based)
 * @param string $reason Rejection reason (e.g., 'no_exif', 'invalid_dimensions', 'too_old')
 * @param array $context Additional context for debugging
 * @return bool True if quarantined successfully, false otherwise
 */
function quarantineImage(string $sourceFile, string $airportId, int $camIndex, string $reason, array $context = []): bool {
    require_once __DIR__ . '/cache-paths.php';
    $quarantineDir = getWebcamQuarantineDir($airportId, $camIndex);
    
    if (!is_dir($quarantineDir)) {
        if (!@mkdir($quarantineDir, 0755, true)) {
            aviationwx_log('error', 'quarantine: failed to create directory', [
                'dir' => $quarantineDir
            ], 'app');
            return false;
        }
    }
    
    $timestamp = time();
    $basename = basename($sourceFile);
    $quarantinePath = $quarantineDir . '/' . $timestamp . '_' . $basename;
    $logPath = $quarantinePath . '.log';
    
    // Move image to quarantine
    if (!@rename($sourceFile, $quarantinePath)) {
        if (@copy($sourceFile, $quarantinePath)) {
            @unlink($sourceFile);
        } else {
            return false;
        }
    }
    
    // Write metadata log
    $logData = [
        'timestamp' => $timestamp,
        'timestamp_iso' => gmdate('Y-m-d\TH:i:s\Z', $timestamp),
        'airport' => $airportId,
        'cam' => $camIndex,
        'original_file' => $sourceFile,
        'reason' => $reason,
        'context' => $context,
        'file_size' => file_exists($quarantinePath) ? filesize($quarantinePath) : 0,
        'file_mtime' => file_exists($quarantinePath) ? filemtime($quarantinePath) : 0
    ];
    
    file_put_contents($logPath, json_encode($logData, JSON_PRETTY_PRINT));
    
    aviationwx_log('info', 'quarantine: image quarantined', [
        'airport' => $airportId,
        'cam' => $camIndex,
        'file' => basename($quarantinePath),
        'reason' => $reason
    ], 'app');
    
    return true;
}

/**
 * Get quarantine statistics for a camera
 * 
 * @param string $airportId Airport identifier
 * @param int $camIndex Camera index
 * @return array Statistics: ['total' => int, 'by_reason' => array]
 */
function getQuarantineStats(string $airportId, int $camIndex): array {
    require_once __DIR__ . '/cache-paths.php';
    $quarantineDir = getWebcamQuarantineDir($airportId, $camIndex);
    
    if (!is_dir($quarantineDir)) {
        return ['total' => 0, 'by_reason' => []];
    }
    
    $files = glob($quarantineDir . '/*.log');
    $stats = ['total' => count($files), 'by_reason' => []];
    
    foreach ($files as $logFile) {
        $data = json_decode(file_get_contents($logFile), true);
        $reason = $data['reason'] ?? 'unknown';
        $stats['by_reason'][$reason] = ($stats['by_reason'][$reason] ?? 0) + 1;
    }
    
    return $stats;
}

/**
 * Clean up old quarantined files
 * 
 * @param int $maxAgeDays Maximum age in days (default: 7)
 * @return array Cleanup summary: ['deleted' => int, 'errors' => int]
 */
function cleanupQuarantine(int $maxAgeDays = 7): array {
    require_once __DIR__ . '/cache-paths.php';
    $quarantineBaseDir = CACHE_WEBCAMS_DIR . '/quarantine';
    $maxAge = time() - ($maxAgeDays * 86400);
    $deleted = 0;
    $errors = 0;
    
    if (!is_dir($quarantineBaseDir)) {
        return ['deleted' => 0, 'errors' => 0];
    }
    
    // Find all files (images + logs) older than maxAge
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($quarantineBaseDir, RecursiveDirectoryIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST
    );
    
    foreach ($iterator as $file) {
        if ($file->isFile() && $file->getMTime() < $maxAge) {
            if (@unlink($file->getPathname())) {
                $deleted++;
            } else {
                $errors++;
            }
        }
    }
    
    // Clean up empty directories
    foreach ($iterator as $dir) {
        if ($dir->isDir()) {
            @rmdir($dir->getPathname()); // Will only succeed if empty
        }
    }
    
    aviationwx_log('info', 'quarantine: cleanup complete', [
        'max_age_days' => $maxAgeDays,
        'deleted' => $deleted,
        'errors' => $errors
    ], 'app');
    
    return ['deleted' => $deleted, 'errors' => $errors];
}
