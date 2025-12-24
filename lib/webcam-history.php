<?php
/**
 * Webcam History Management
 * 
 * Handles storing and retrieving historical webcam frames for time-lapse playback.
 * Frames are stored in timestamped directories per camera with automatic cleanup.
 */

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/logger.php';

/**
 * Get history directory path for a camera
 * 
 * @param string $airportId Airport ID (e.g., 'kspb')
 * @param int $camIndex Camera index (0-based)
 * @return string Path to history directory
 */
function getWebcamHistoryDir(string $airportId, int $camIndex): string {
    return __DIR__ . '/../cache/webcams/' . $airportId . '_' . $camIndex . '_history';
}

/**
 * Save frame to history
 * 
 * Called after successful webcam update. Copies the current frame to the
 * history directory with a timestamp-based filename.
 * 
 * @param string $sourceFile Path to current webcam image
 * @param string $airportId Airport ID (e.g., 'kspb')
 * @param int $camIndex Camera index (0-based)
 * @return bool True on success, false if history disabled or on error
 */
function saveFrameToHistory(string $sourceFile, string $airportId, int $camIndex): bool {
    // Check if history enabled for this airport
    if (!isWebcamHistoryEnabledForAirport($airportId)) {
        return false;
    }
    
    if (!file_exists($sourceFile) || !is_readable($sourceFile)) {
        return false;
    }
    
    $historyDir = getWebcamHistoryDir($airportId, $camIndex);
    
    // Create history directory if needed
    if (!is_dir($historyDir)) {
        if (!@mkdir($historyDir, 0755, true)) {
            aviationwx_log('error', 'webcam history: failed to create directory', [
                'airport' => $airportId,
                'cam' => $camIndex,
                'dir' => $historyDir
            ], 'app');
            return false;
        }
    }
    
    // Get capture timestamp (EXIF or filemtime)
    $timestamp = getHistoryImageCaptureTime($sourceFile);
    if ($timestamp <= 0) {
        $timestamp = time();
    }
    
    $destFile = $historyDir . '/' . $timestamp . '.jpg';
    
    // Don't overwrite existing frame with same timestamp
    if (file_exists($destFile)) {
        return true;
    }
    
    // Copy (not move) - source file is still needed as current
    if (!@copy($sourceFile, $destFile)) {
        aviationwx_log('error', 'webcam history: failed to copy frame', [
            'airport' => $airportId,
            'cam' => $camIndex,
            'source' => $sourceFile,
            'dest' => $destFile
        ], 'app');
        return false;
    }
    
    aviationwx_log('debug', 'webcam history: saved frame', [
        'airport' => $airportId,
        'cam' => $camIndex,
        'timestamp' => $timestamp
    ], 'app');
    
    // Cleanup old frames
    cleanupHistoryFrames($airportId, $camIndex);
    
    return true;
}

/**
 * Get list of available history frames
 * 
 * Returns an array of frames sorted by timestamp (oldest first).
 * 
 * @param string $airportId Airport ID (e.g., 'kspb')
 * @param int $camIndex Camera index (0-based)
 * @return array Array of frames: [['timestamp' => int, 'filename' => string], ...]
 */
function getHistoryFrames(string $airportId, int $camIndex): array {
    $historyDir = getWebcamHistoryDir($airportId, $camIndex);
    
    if (!is_dir($historyDir)) {
        return [];
    }
    
    $files = glob($historyDir . '/*.jpg');
    if ($files === false) {
        return [];
    }
    
    $frames = [];
    
    foreach ($files as $file) {
        $basename = basename($file, '.jpg');
        if (is_numeric($basename)) {
            $frames[] = [
                'timestamp' => (int)$basename,
                'filename' => basename($file)
            ];
        }
    }
    
    // Sort by timestamp ascending (oldest first for playback)
    usort($frames, fn($a, $b) => $a['timestamp'] - $b['timestamp']);
    
    return $frames;
}

/**
 * Remove frames exceeding max count
 * 
 * Keeps the most recent N frames based on airport configuration.
 * 
 * @param string $airportId Airport ID (e.g., 'kspb')
 * @param int $camIndex Camera index (0-based)
 * @return void
 */
function cleanupHistoryFrames(string $airportId, int $camIndex): void {
    $maxFrames = getWebcamHistoryMaxFrames($airportId);
    $historyDir = getWebcamHistoryDir($airportId, $camIndex);
    
    if (!is_dir($historyDir)) {
        return;
    }
    
    $files = glob($historyDir . '/*.jpg');
    if ($files === false || count($files) <= $maxFrames) {
        return;
    }
    
    // Sort by timestamp (filename) ascending - oldest first
    usort($files, fn($a, $b) => basename($a) <=> basename($b));
    
    // Remove oldest files to stay within limit
    $toRemove = array_slice($files, 0, count($files) - $maxFrames);
    $removedCount = 0;
    
    foreach ($toRemove as $file) {
        if (@unlink($file)) {
            $removedCount++;
        }
    }
    
    if ($removedCount > 0) {
        aviationwx_log('debug', 'webcam history: cleaned up old frames', [
            'airport' => $airportId,
            'cam' => $camIndex,
            'removed' => $removedCount,
            'max_frames' => $maxFrames
        ], 'app');
    }
}

/**
 * Get capture time from image
 * 
 * Attempts to read EXIF DateTimeOriginal, falls back to file modification time.
 * 
 * @param string $filePath Path to image file
 * @return int Unix timestamp, or 0 if unable to determine
 */
function getHistoryImageCaptureTime(string $filePath): int {
    if (!file_exists($filePath)) {
        return 0;
    }
    
    // Try EXIF data first
    if (function_exists('exif_read_data')) {
        $exif = @exif_read_data($filePath, 'EXIF', true);
        
        if ($exif !== false) {
            // Check EXIF.DateTimeOriginal
            if (isset($exif['EXIF']['DateTimeOriginal'])) {
                $dateTime = $exif['EXIF']['DateTimeOriginal'];
                $timestamp = @strtotime(str_replace(':', '-', substr($dateTime, 0, 10)) . ' ' . substr($dateTime, 11));
                if ($timestamp !== false && $timestamp > 0) {
                    return $timestamp;
                }
            }
            
            // Check root DateTimeOriginal
            if (isset($exif['DateTimeOriginal'])) {
                $dateTime = $exif['DateTimeOriginal'];
                $timestamp = @strtotime(str_replace(':', '-', substr($dateTime, 0, 10)) . ' ' . substr($dateTime, 11));
                if ($timestamp !== false && $timestamp > 0) {
                    return $timestamp;
                }
            }
        }
    }
    
    // Fall back to file modification time
    $mtime = @filemtime($filePath);
    return $mtime !== false ? (int)$mtime : 0;
}

/**
 * Get total size of history for an airport camera
 * 
 * Useful for monitoring disk usage.
 * 
 * @param string $airportId Airport ID
 * @param int $camIndex Camera index
 * @return int Total size in bytes
 */
function getHistoryDiskUsage(string $airportId, int $camIndex): int {
    $historyDir = getWebcamHistoryDir($airportId, $camIndex);
    
    if (!is_dir($historyDir)) {
        return 0;
    }
    
    $files = glob($historyDir . '/*.jpg');
    if ($files === false) {
        return 0;
    }

    return array_sum(array_map(function($file) {
        $size = @filesize($file);
        return $size !== false ? $size : 0;
    }, $files));
}

/**
 * Check if JPEG file is complete (has end marker)
 * 
 * JPEG files should end with FF D9. Truncated uploads will be missing this.
 * Cost: ~0.1ms (reads only last 2 bytes)
 * 
 * @param string $file Path to JPEG file
 * @return bool True if file appears complete
 */
function isJpegComplete(string $file): bool {
    $size = @filesize($file);
    if ($size === false || $size < 10) {
        return false;
    }
    
    $handle = @fopen($file, 'rb');
    if (!$handle) {
        return false;
    }
    
    // Seek to last 2 bytes
    if (@fseek($handle, -2, SEEK_END) !== 0) {
        @fclose($handle);
        return false;
    }
    
    $footer = @fread($handle, 2);
    @fclose($handle);
    
    // JPEG end marker: FF D9
    return $footer === "\xFF\xD9";
}

/**
 * Check if PNG file is complete (has IEND chunk)
 * 
 * PNG files should end with the IEND chunk followed by CRC.
 * The sequence is: 00 00 00 00 49 45 4E 44 AE 42 60 82
 * We just look for "IEND" in the last 12 bytes.
 * Cost: ~0.1ms (reads only last 12 bytes)
 * 
 * @param string $file Path to PNG file
 * @return bool True if file appears complete
 */
function isPngComplete(string $file): bool {
    $size = @filesize($file);
    if ($size === false || $size < 50) {
        return false;
    }
    
    $handle = @fopen($file, 'rb');
    if (!$handle) {
        return false;
    }
    
    // Seek to last 12 bytes (IEND chunk is 12 bytes total)
    if (@fseek($handle, -12, SEEK_END) !== 0) {
        @fclose($handle);
        return false;
    }
    
    $footer = @fread($handle, 12);
    @fclose($handle);
    
    // Look for IEND marker (ASCII: 49 45 4E 44)
    return strpos($footer, 'IEND') !== false;
}

/**
 * Check if WebP file is complete (file size matches RIFF header)
 * 
 * WebP uses RIFF container. Bytes 4-7 contain file size - 8.
 * Cost: ~0.1ms (reads header and checks file size)
 * 
 * @param string $file Path to WebP file
 * @return bool True if file appears complete
 */
function isWebpComplete(string $file): bool {
    $actualSize = @filesize($file);
    if ($actualSize === false || $actualSize < 12) {
        return false;
    }
    
    $handle = @fopen($file, 'rb');
    if (!$handle) {
        return false;
    }
    
    $header = @fread($handle, 12);
    @fclose($handle);
    
    if (strlen($header) < 12) {
        return false;
    }
    
    // Verify RIFF header
    if (substr($header, 0, 4) !== 'RIFF') {
        return false;
    }
    
    // Verify WEBP marker
    if (substr($header, 8, 4) !== 'WEBP') {
        return false;
    }
    
    // Get declared size from RIFF header (little-endian, bytes 4-7)
    // RIFF size = file size - 8 (doesn't include "RIFF" and size bytes)
    $declaredSize = unpack('V', substr($header, 4, 4))[1];
    $expectedFileSize = $declaredSize + 8;
    
    // Allow 1 byte tolerance for padding
    return abs($actualSize - $expectedFileSize) <= 1;
}

/**
 * Check if image file is complete and not truncated
 * 
 * Performs format-specific end-of-file validation to catch truncated uploads.
 * Cost: ~0.1ms per file (reads only a few bytes from end)
 * 
 * @param string $file Path to image file
 * @param string|null $format Image format (jpg, png, webp, avif) or null to auto-detect
 * @return bool True if file appears complete
 */
function isImageComplete(string $file, ?string $format = null): bool {
    if (!file_exists($file) || !is_readable($file)) {
        return false;
    }
    
    // Auto-detect format if not provided
    if ($format === null) {
        if (function_exists('detectImageFormat')) {
            $format = detectImageFormat($file);
        } else {
            // Fallback: use extension
            $format = strtolower(pathinfo($file, PATHINFO_EXTENSION));
            if ($format === 'jpeg') {
                $format = 'jpg';
            }
        }
    }
    
    switch ($format) {
        case 'jpg':
        case 'jpeg':
            return isJpegComplete($file);
            
        case 'png':
            return isPngComplete($file);
            
        case 'webp':
            return isWebpComplete($file);
            
        case 'avif':
            // AVIF uses ISOBMFF container (complex)
            // For now, trust header validation only
            // A truncated AVIF will likely fail on decode anyway
            return filesize($file) > 100;
            
        default:
            // Unknown format - trust that it passed header validation
            return true;
    }
}

/**
 * Validate image is complete and suitable for history
 * 
 * Combines standard validation with end-of-file completeness check.
 * Use this for harvesting push camera uploads.
 * 
 * @param string $file Image file path
 * @param array|null $pushConfig Push config for validation limits
 * @return bool True if valid and complete
 */
function validateImageForHistory(string $file, ?array $pushConfig = null): bool {
    // Use standard validation if available (from process-push-webcams.php)
    if (function_exists('validateImageFile')) {
        if (!validateImageFile($file, $pushConfig)) {
            return false;
        }
    } else {
        // Fallback: basic checks
        if (!file_exists($file) || !is_readable($file)) {
            return false;
        }
        $size = @filesize($file);
        if ($size === false || $size < 100 || $size > 100 * 1024 * 1024) {
            return false;
        }
    }
    
    // Additional check: verify file is complete (not truncated)
    return isImageComplete($file);
}
