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
    return __DIR__ . '/../cache/webcams/' . $airportId . '/' . $camIndex . '/history';
}

/**
 * Save frame to history (single format - legacy)
 * 
 * Called after successful webcam update. Copies the current frame to the
 * history directory with a timestamp-based filename.
 * 
 * @param string $sourceFile Path to current webcam image
 * @param string $airportId Airport ID (e.g., 'kspb')
 * @param int $camIndex Camera index (0-based)
 * @return bool True on success, false if history disabled or on error
 * @deprecated Use saveAllFormatsToHistory() for multi-format support
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
    
    // Detect if this is a bridge upload for logging
    $isBridgeUpload = isBridgeUpload($sourceFile);
    
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
    
    // Log with source indicator (bridge or direct camera)
    if ($isBridgeUpload) {
        aviationwx_log('debug', 'webcam history: saved bridge frame (UTC)', [
            'airport' => $airportId,
            'cam' => $camIndex,
            'timestamp' => $timestamp,
            'source' => 'bridge'
        ], 'app');
    } else {
        aviationwx_log('debug', 'webcam history: saved frame', [
            'airport' => $airportId,
            'cam' => $camIndex,
            'timestamp' => $timestamp
        ], 'app');
    }
    
    // Cleanup old frames
    cleanupHistoryFrames($airportId, $camIndex);
    
    return true;
}

/**
 * Save all promoted formats to history
 * 
 * Called after successful format generation and promotion. Copies all promoted
 * format files to the history directory with timestamp-based filenames.
 * 
 * With timestamp-based cache files, we can copy directly from cache using the timestamp.
 * Storage is cheap, CPU is expensive - we save all formats to avoid
 * regenerating them later for time-lapse or other features.
 * 
 * @param string $airportId Airport ID (e.g., 'kspb')
 * @param int $camIndex Camera index (0-based)
 * @param array $promotedFormats Array of promoted format extensions: ['jpg', 'webp', 'avif']
 * @param int $timestamp Unix timestamp for the image (0 to auto-detect)
 * @return array Results: ['format' => bool success, ...]
 */
function saveAllFormatsToHistory(string $airportId, int $camIndex, array $promotedFormats, int $timestamp = 0): array {
    $results = [];
    
    // Check if history enabled for this airport
    if (!isWebcamHistoryEnabledForAirport($airportId)) {
        return $results;
    }
    
    if (empty($promotedFormats)) {
        return $results;
    }
    
    $historyDir = getWebcamHistoryDir($airportId, $camIndex);
    $cacheDir = __DIR__ . '/../cache/webcams';
    
    // Create history directory if needed
    if (!is_dir($historyDir)) {
        if (!@mkdir($historyDir, 0755, true)) {
            aviationwx_log('error', 'webcam history: failed to create directory', [
                'airport' => $airportId,
                'cam' => $camIndex,
                'dir' => $historyDir
            ], 'app');
            return $results;
        }
    }
    
    // Get timestamp if not provided (from symlink target or file mtime)
    if ($timestamp <= 0) {
        foreach ($promotedFormats as $format) {
            // Try to resolve symlink to get timestamp file
            $symlinkPath = $cacheDir . '/' . $airportId . '_' . $camIndex . '.' . $format;
            if (is_link($symlinkPath)) {
                $target = readlink($symlinkPath);
                if ($target !== false) {
                    // Extract timestamp from filename (e.g., "1703700000.jpg" -> 1703700000)
                    if (preg_match('/^(\d+)\.' . preg_quote($format, '/') . '$/', basename($target), $matches)) {
                        $timestamp = (int)$matches[1];
                        break;
                    }
                }
            }
            // Fallback: get from file mtime
            $sourceFile = is_link($symlinkPath) ? ($cacheDir . '/' . basename(readlink($symlinkPath))) : $symlinkPath;
            if (file_exists($sourceFile)) {
                $timestamp = getHistoryImageCaptureTime($sourceFile);
                if ($timestamp > 0) {
                    break;
                }
            }
        }
    }
    
    if ($timestamp <= 0) {
        $timestamp = time();
    }
    
    // Detect if this is a bridge upload for logging (check any format)
    $isBridgeUpload = false;
    foreach ($promotedFormats as $format) {
        $timestampFile = $cacheDir . '/' . $timestamp . '.' . $format;
        if (file_exists($timestampFile) && isBridgeUpload($timestampFile)) {
            $isBridgeUpload = true;
            break;
        }
    }
    
    $savedFormats = [];
    
    // Save each promoted format to history (copy from timestamp-based cache files)
    foreach ($promotedFormats as $format) {
        $sourceFile = $cacheDir . '/' . $timestamp . '.' . $format;
        $destFile = $historyDir . '/' . $timestamp . '.' . $format;
        
        // Skip if source doesn't exist
        if (!file_exists($sourceFile) || !is_readable($sourceFile)) {
            $results[$format] = false;
            continue;
        }
        
        // Don't overwrite existing frame with same timestamp
        if (file_exists($destFile)) {
            $results[$format] = true;
            $savedFormats[] = $format;
            continue;
        }
        
        // Copy (not move) - source file is still needed as current cache
        if (@copy($sourceFile, $destFile)) {
            $results[$format] = true;
            $savedFormats[] = $format;
        } else {
            aviationwx_log('error', 'webcam history: failed to copy format', [
                'airport' => $airportId,
                'cam' => $camIndex,
                'format' => $format,
                'source' => $sourceFile,
                'dest' => $destFile
            ], 'app');
            $results[$format] = false;
        }
    }
    
    // Log with source indicator
    if (!empty($savedFormats)) {
        if ($isBridgeUpload) {
            aviationwx_log('debug', 'webcam history: saved bridge frames (UTC)', [
                'airport' => $airportId,
                'cam' => $camIndex,
                'timestamp' => $timestamp,
                'formats' => $savedFormats,
                'source' => 'bridge'
            ], 'app');
        } else {
            aviationwx_log('debug', 'webcam history: saved frames', [
                'airport' => $airportId,
                'cam' => $camIndex,
                'timestamp' => $timestamp,
                'formats' => $savedFormats
            ], 'app');
        }
    }
    
    // Cleanup old frames (all formats)
    cleanupHistoryFramesAllFormats($airportId, $camIndex);
    
    return $results;
}

/**
 * Save all variants to history
 * 
 * Saves all generated variants × formats to history directory.
 * Variants are stored with naming: {timestamp}_{variant}.{format}
 * 
 * @param string $airportId Airport ID (e.g., 'kspb')
 * @param int $camIndex Camera index (0-based)
 * @param array $promotedVariants Array: ['variant' => ['format1', 'format2', ...], ...]
 * @param int $timestamp Unix timestamp for the image
 * @return array Results: ['variant_format' => bool success, ...]
 */
function saveAllVariantsToHistory(string $airportId, int $camIndex, array $promotedVariants, int $timestamp = 0): array {
    $results = [];
    
    // Check if history enabled for this airport
    if (!isWebcamHistoryEnabledForAirport($airportId)) {
        return $results;
    }
    
    if (empty($promotedVariants)) {
        return $results;
    }
    
    $historyDir = getWebcamHistoryDir($airportId, $camIndex);
    $cacheDir = __DIR__ . '/../cache/webcams';
    
    // Create history directory if needed
    if (!is_dir($historyDir)) {
        if (!@mkdir($historyDir, 0755, true)) {
            aviationwx_log('error', 'webcam history: failed to create directory', [
                'airport' => $airportId,
                'cam' => $camIndex,
                'dir' => $historyDir
            ], 'app');
            return $results;
        }
    }
    
    // Get timestamp if not provided
    if ($timestamp <= 0) {
        $timestamp = time();
    }
    
    require_once __DIR__ . '/webcam-format-generation.php';
    
    // Copy each variant × format to history
    foreach ($promotedVariants as $variant => $formats) {
        foreach ($formats as $format) {
            // Source: cache file with variant naming
            $sourceFile = getTimestampCacheFilePath($airportId, $camIndex, $timestamp, $format, $variant);
            
            if (!file_exists($sourceFile)) {
                continue;
            }
            
            // Destination: history file with same naming
            $destFile = $historyDir . '/' . $timestamp . '_' . $variant . '.' . $format;
            
            // Don't overwrite existing frame with same timestamp
            if (file_exists($destFile)) {
                $results[$variant . '_' . $format] = true;
                continue;
            }
            
            // Copy to history
            if (@copy($sourceFile, $destFile)) {
                $results[$variant . '_' . $format] = true;
                
                aviationwx_log('debug', 'webcam history: saved variant', [
                    'airport' => $airportId,
                    'cam' => $camIndex,
                    'timestamp' => $timestamp,
                    'variant' => $variant,
                    'format' => $format
                ], 'app');
            } else {
                $results[$variant . '_' . $format] = false;
                aviationwx_log('error', 'webcam history: failed to copy variant', [
                    'airport' => $airportId,
                    'cam' => $camIndex,
                    'timestamp' => $timestamp,
                    'variant' => $variant,
                    'format' => $format,
                    'source' => $sourceFile,
                    'dest' => $destFile
                ], 'app');
            }
        }
    }
    
    return $results;
}

/**
 * Get list of available history frames
 * 
 * Returns an array of frames sorted by timestamp (oldest first).
 * Each frame includes all available formats for that timestamp.
 * 
 * @param string $airportId Airport ID (e.g., 'kspb')
 * @param int $camIndex Camera index (0-based)
 * @return array Array of frames: [['timestamp' => int, 'filename' => string, 'formats' => ['jpg', 'webp']], ...]
 */
function getHistoryFrames(string $airportId, int $camIndex): array {
    $historyDir = getWebcamHistoryDir($airportId, $camIndex);
    
    if (!is_dir($historyDir)) {
        return [];
    }
    
    // Get all image files (supports both old and new naming)
    $allFiles = glob($historyDir . '/*.{jpg,webp,avif}', GLOB_BRACE);
    if ($allFiles === false) {
        return [];
    }
    
    // Group by timestamp and variant
    $timestampGroups = [];
    
    foreach ($allFiles as $file) {
        $basename = basename($file);
        // Match both old format (timestamp.format) and new format (timestamp_variant.format)
        if (preg_match('/^(\d+)(?:_([^\.]+))?\.(jpg|webp|avif)$/', $basename, $matches)) {
            $timestamp = (int)$matches[1];
            $variant = $matches[2] ?? 'primary'; // Default to primary for old naming
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
    
    // Build frames array
    $frames = [];
    foreach ($timestampGroups as $timestamp => $data) {
        $formats = $data['formats'];
        $variants = $data['variants'];
        
        // Primary filename is always JPG if available, otherwise first format
        $primaryFormat = in_array('jpg', $formats) ? 'jpg' : $formats[0];
        
        $frames[] = [
            'timestamp' => $timestamp,
            'filename' => $timestamp . '_primary.' . $primaryFormat,
            'formats' => $formats,
            'variants' => array_keys($variants) // List of available variants
        ];
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
 * Remove frames exceeding max count (all formats)
 * 
 * Keeps the most recent N frame sets based on airport configuration.
 * A frame set is all formats for a single timestamp.
 * 
 * @param string $airportId Airport ID (e.g., 'kspb')
 * @param int $camIndex Camera index (0-based)
 * @return void
 */
function cleanupHistoryFramesAllFormats(string $airportId, int $camIndex): void {
    $maxFrames = getWebcamHistoryMaxFrames($airportId);
    $historyDir = getWebcamHistoryDir($airportId, $camIndex);
    
    if (!is_dir($historyDir)) {
        return;
    }
    
    // Get all files in history directory
    $allFiles = glob($historyDir . '/*.*');
    if ($allFiles === false || empty($allFiles)) {
        return;
    }
    
    // Group files by timestamp (extract timestamp from filename)
    $timestampGroups = [];
    foreach ($allFiles as $file) {
        $basename = basename($file);
        // Parse timestamp from filename: "1703700000.jpg" or "1703700000.webp" etc
        if (preg_match('/^(\d+)\.(jpg|webp|avif)$/', $basename, $matches)) {
            $timestamp = (int)$matches[1];
            if (!isset($timestampGroups[$timestamp])) {
                $timestampGroups[$timestamp] = [];
            }
            $timestampGroups[$timestamp][] = $file;
        }
    }
    
    // If we have fewer timestamps than max, nothing to clean
    if (count($timestampGroups) <= $maxFrames) {
        return;
    }
    
    // Sort timestamps ascending (oldest first)
    ksort($timestampGroups);
    
    // Remove oldest timestamp groups to stay within limit
    $toRemove = array_slice(array_keys($timestampGroups), 0, count($timestampGroups) - $maxFrames);
    $removedCount = 0;
    
    foreach ($toRemove as $timestamp) {
        foreach ($timestampGroups[$timestamp] as $file) {
            if (@unlink($file)) {
                $removedCount++;
            }
        }
    }
    
    if ($removedCount > 0) {
        aviationwx_log('debug', 'webcam history: cleaned up old frame sets', [
            'airport' => $airportId,
            'cam' => $camIndex,
            'timestamps_removed' => count($toRemove),
            'files_removed' => $removedCount,
            'max_frames' => $maxFrames
        ], 'app');
    }
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
 * Get total size of history for an airport camera
 * 
 * Includes all format files (jpg, webp, avif).
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
    
    $files = glob($historyDir . '/*.{jpg,webp,avif}', GLOB_BRACE);
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

