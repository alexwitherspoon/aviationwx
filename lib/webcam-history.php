<?php
/**
 * Webcam History Management
 * 
 * Handles retrieving historical webcam frames for time-lapse playback.
 * 
 * Storage Architecture (Unified):
 * - All webcam images are stored directly in the camera cache directory
 * - No separate history subfolder - storage is unified
 * - Retention is controlled by webcam_history_max_frames config
 * - cleanup is handled by cleanupOldTimestampFiles() in webcam-format-generation.php
 * 
 * History Behavior:
 * - max_frames = 1: Only latest image kept, history player disabled
 * - max_frames >= 2: History player enabled with N frames available
 */

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/logger.php';
require_once __DIR__ . '/cache-paths.php';

/**
 * Get list of available history frames
 * 
 * Returns an array of frames sorted by timestamp (oldest first).
 * Each frame includes all available formats for that timestamp.
 * Limits results to the configured max_frames count and time window.
 * 
 * History frames are stored directly in the camera cache directory
 * (no separate history subfolder). This function reads timestamp-based
 * files and excludes symlinks.
 * 
 * @param string $airportId Airport ID (e.g., 'kspb')
 * @param int $camIndex Camera index (0-based)
 * @return array Array of frames: [['timestamp' => int, 'filename' => string, 'formats' => ['jpg', 'webp']], ...]
 */
function getHistoryFrames(string $airportId, int $camIndex): array {
    // Get max_frames config - if < 2, history is disabled
    $maxFrames = getWebcamHistoryMaxFrames($airportId);
    if ($maxFrames < 2) {
        return [];
    }
    
    // Read from camera cache directory (unified storage)
    $cacheDir = getWebcamCameraDir($airportId, $camIndex);
    
    if (!is_dir($cacheDir)) {
        return [];
    }
    
    // Get all image files (excludes symlinks, staging files, etc.)
    $allFiles = glob($cacheDir . '/*.{jpg,webp}', GLOB_BRACE);
    if ($allFiles === false || empty($allFiles)) {
        return [];
    }
    
    // Group by timestamp and variant (exclude symlinks)
    $timestampGroups = [];
    
    foreach ($allFiles as $file) {
        // Skip symlinks (current.jpg, current.webp, etc.)
        if (is_link($file)) {
            continue;
        }
        
        $basename = basename($file);
        // Match: {timestamp}_original.{format} or {timestamp}_{height}.{format}
        if (preg_match('/^(\d+)_(original|\d+)\.(jpg|webp)$/', $basename, $matches)) {
            $timestamp = (int)$matches[1];
            $variant = $matches[2];
            $format = $matches[3];
            
            if (!isset($timestampGroups[$timestamp])) {
                $timestampGroups[$timestamp] = [
                    'formats' => [],
                    'variants' => []
                ];
            }
            
            if (!in_array($format, $timestampGroups[$timestamp]['formats'])) {
                $timestampGroups[$timestamp]['formats'][] = $format;
            }
            
            if (!isset($timestampGroups[$timestamp]['variants'][$variant])) {
                $timestampGroups[$timestamp]['variants'][$variant] = [];
            }
            if (!in_array($format, $timestampGroups[$timestamp]['variants'][$variant])) {
                $timestampGroups[$timestamp]['variants'][$variant][] = $format;
            }
        }
    }
    
    $frames = [];
    foreach ($timestampGroups as $timestamp => $data) {
        $formats = $data['formats'];
        $variants = $data['variants'];
        
        $variantManifest = [];
        foreach ($variants as $variant => $variantFormats) {
            $variantManifest[$variant] = $variantFormats;
        }
        
        $frames[] = [
            'timestamp' => $timestamp,
            'formats' => $formats,
            'variants' => $variantManifest
        ];
    }
    
    // Sort by timestamp ascending
    usort($frames, fn($a, $b) => $a['timestamp'] - $b['timestamp']);
    
    // Get refresh interval for this airport (used to calculate time window)
    $config = loadConfig();
    $refreshInterval = getDefaultWebcamRefresh();
    if ($config !== null && isset($config['airports'][$airportId])) {
        $airport = $config['airports'][$airportId];
        if (isset($airport['webcam_refresh_seconds'])) {
            $refreshInterval = max(60, intval($airport['webcam_refresh_seconds']));
        }
    }
    
    // Calculate time cutoff: max_frames * refresh_interval seconds ago
    // This ensures we don't show frames older than the configured retention window
    $now = time();
    $timeCutoff = $now - ($maxFrames * $refreshInterval);
    
    // Filter frames: must be within time window
    $frames = array_filter($frames, function($frame) use ($timeCutoff) {
        return $frame['timestamp'] >= $timeCutoff;
    });
    
    // Re-index array after filtering
    $frames = array_values($frames);
    
    // Limit to max_frames count (keep most recent N frames)
    if (count($frames) > $maxFrames) {
        $frames = array_slice($frames, -$maxFrames);
    }
    
    return $frames;
}

/**
 * Check if history is available for a camera
 * 
 * Returns true if:
 * - max_frames >= 2 (history is configured)
 * - At least 2 frames are available
 * 
 * @param string $airportId Airport ID (e.g., 'kspb')
 * @param int $camIndex Camera index (0-based)
 * @return array Status: ['enabled' => bool, 'available' => bool, 'frame_count' => int, 'max_frames' => int]
 */
function getHistoryStatus(string $airportId, int $camIndex): array {
    $maxFrames = getWebcamHistoryMaxFrames($airportId);
    $enabled = $maxFrames >= 2;
    
    if (!$enabled) {
        return [
            'enabled' => false,
            'available' => false,
            'frame_count' => 0,
            'max_frames' => $maxFrames
        ];
    }
    
    $frames = getHistoryFrames($airportId, $camIndex);
    $frameCount = count($frames);
    
    return [
        'enabled' => true,
        'available' => $frameCount >= 2,
        'frame_count' => $frameCount,
        'max_frames' => $maxFrames
    ];
}

/**
 * Get capture time from image
 * 
 * Handles both:
 * - Bridge uploads: EXIF is UTC, marked with "AviationWX-Bridge" in UserComment
 * - Direct camera uploads: EXIF is local time (existing behavior)
 * 
 * Detection order:
 * 1. Check for AviationWX Bridge marker → interpret DateTimeOriginal as UTC
 * 2. Check for EXIF 2.31 OffsetTimeOriginal → use specified offset
 * 3. Check GPS timestamp → always UTC per EXIF spec
 * 4. DateTimeOriginal without marker → assume local time (backward compatible)
 * 5. Fall back to file mtime
 * 
 * @param string $filePath Path to image file
 * @return int Unix timestamp, or 0 if unable to determine
 */
function getHistoryImageCaptureTime(string $filePath): int {
    if (!file_exists($filePath)) {
        return 0;
    }
    
    // Try EXIF data
    if (!function_exists('exif_read_data')) {
        $mtime = @filemtime($filePath);
        return $mtime !== false ? (int)$mtime : 0;
    }
    
    // Read EXIF with all sections we need
    $exif = @exif_read_data($filePath, 'EXIF,GPS,COMMENT,IFD0', true);
    
    if ($exif === false) {
        $mtime = @filemtime($filePath);
        return $mtime !== false ? (int)$mtime : 0;
    }
    
    // Get DateTimeOriginal from various possible locations
    $dateTime = null;
    if (isset($exif['EXIF']['DateTimeOriginal'])) {
        $dateTime = $exif['EXIF']['DateTimeOriginal'];
    } elseif (isset($exif['DateTimeOriginal'])) {
        $dateTime = $exif['DateTimeOriginal'];
    } elseif (isset($exif['IFD0']['DateTime'])) {
        $dateTime = $exif['IFD0']['DateTime'];
    }
    
    if ($dateTime === null) {
        $mtime = @filemtime($filePath);
        return $mtime !== false ? (int)$mtime : 0;
    }
    
    // Check for AviationWX Bridge marker (primary detection)
    $isBridgeUpload = false;
    $userComment = $exif['COMMENT']['UserComment']
                ?? $exif['EXIF']['UserComment']
                ?? null;
    
    if ($userComment !== null && strpos($userComment, 'AviationWX-Bridge') !== false) {
        $isBridgeUpload = true;
    }
    
    // Parse EXIF datetime: "2024:12:25 16:30:45" → "2024-12-25 16:30:45"
    $formatted = str_replace(':', '-', substr($dateTime, 0, 10)) . substr($dateTime, 10);
    
    // Bridge upload - DateTimeOriginal is already UTC
    if ($isBridgeUpload) {
        $timestamp = @strtotime($formatted . ' UTC');
        if ($timestamp !== false && $timestamp > 0) {
            return $timestamp;
        }
    }
    
    // Check for EXIF 2.31 offset field (explicit timezone)
    $offset = $exif['EXIF']['OffsetTimeOriginal'] ?? null;
    if ($offset !== null && preg_match('/^[+-]\d{2}:\d{2}$/', $offset)) {
        if ($offset === '+00:00' || $offset === '-00:00') {
            // Explicit UTC
            $timestamp = @strtotime($formatted . ' UTC');
        } else {
            // Has explicit offset - parse with it
            $dt = DateTime::createFromFormat('Y-m-d H:i:s P', $formatted . ' ' . $offset);
            if ($dt !== false) {
                return $dt->getTimestamp();
            }
        }
        if (isset($timestamp) && $timestamp !== false && $timestamp > 0) {
            return $timestamp;
        }
    }
    
    // Check GPS timestamp (always UTC per EXIF spec)
    if (isset($exif['GPS']['GPSDateStamp']) && isset($exif['GPS']['GPSTimeStamp'])) {
        $gpsTimestamp = parseGPSTimestamp($exif['GPS']);
        if ($gpsTimestamp > 0) {
            return $gpsTimestamp;
        }
    }
    
    // No bridge marker, no offset, no GPS - assume local time
    // This maintains backward compatibility with direct camera uploads
    $timestamp = @strtotime($formatted);
    if ($timestamp !== false && $timestamp > 0) {
        return $timestamp;
    }
    
    // Last resort: file mtime
    $mtime = @filemtime($filePath);
    return $mtime !== false ? (int)$mtime : 0;
}

/**
 * Check if image is from AviationWX Bridge
 * 
 * Bridge uploads have "AviationWX-Bridge" in the EXIF UserComment field.
 * 
 * @param string $filePath Path to image file
 * @return bool True if this is a bridge upload
 */
function isBridgeUpload(string $filePath): bool {
    if (!file_exists($filePath) || !function_exists('exif_read_data')) {
        return false;
    }
    
    $exif = @exif_read_data($filePath, 'EXIF,COMMENT', true);
    if ($exif === false) {
        return false;
    }
    
    $userComment = $exif['COMMENT']['UserComment']
                ?? $exif['EXIF']['UserComment']
                ?? null;
    
    return $userComment !== null && strpos($userComment, 'AviationWX-Bridge') !== false;
}

/**
 * Parse GPS timestamp from EXIF GPS data
 * 
 * @param array $gps GPS EXIF section
 * @return int Unix timestamp, or 0 if unable to parse
 */
function parseGPSTimestamp(array $gps): int {
    if (!isset($gps['GPSDateStamp']) || !isset($gps['GPSTimeStamp'])) {
        return 0;
    }
    
    $date = $gps['GPSDateStamp'];  // "2024:12:25"
    $time = $gps['GPSTimeStamp'];  // Array of rationals
    
    if (!is_array($time) || count($time) < 3) {
        return 0;
    }
    
    // Parse rational values (may be array with num/den or just numbers)
    $h = is_array($time[0]) ? ($time[0]['num'] / max(1, $time[0]['den'])) : (float)$time[0];
    $m = is_array($time[1]) ? ($time[1]['num'] / max(1, $time[1]['den'])) : (float)$time[1];
    $s = is_array($time[2]) ? ($time[2]['num'] / max(1, $time[2]['den'])) : (float)$time[2];
    
    $formatted = str_replace(':', '-', $date) . sprintf(' %02d:%02d:%02d', (int)$h, (int)$m, (int)$s);
    $timestamp = @strtotime($formatted . ' UTC');
    
    return ($timestamp !== false && $timestamp > 0) ? $timestamp : 0;
}

/**
 * Get total size of webcam cache for an airport camera
 * 
 * Includes all format files (jpg, webp) in the camera cache directory.
 * Useful for monitoring disk usage.
 * 
 * @param string $airportId Airport ID
 * @param int $camIndex Camera index
 * @return int Total size in bytes
 */
function getHistoryDiskUsage(string $airportId, int $camIndex): int {
    $cacheDir = getWebcamCameraDir($airportId, $camIndex);
    
    if (!is_dir($cacheDir)) {
        return 0;
    }
    
    $files = glob($cacheDir . '/*.{jpg,webp}', GLOB_BRACE);
    if ($files === false) {
        return 0;
    }

    return array_sum(array_map(function($file) {
        // Skip symlinks
        if (is_link($file)) {
            return 0;
        }
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
 * @param string|null $format Image format (jpg, png, webp) or null to auto-detect
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
            
        default:
            // Unknown format - trust that it passed header validation
            return true;
    }
}

/**
 * Validate image is complete and suitable for processing
 * 
 * Combines standard validation with end-of-file completeness check.
 * Use this for validating push camera uploads.
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
