<?php
/**
 * EXIF Utilities for Webcam Pipeline
 * 
 * Provides functions for reading, writing, and validating EXIF timestamps.
 * Requires exiftool to be installed (libimage-exiftool-perl).
 * 
 * All webcam images must have valid EXIF DateTimeOriginal before acceptance.
 * EXIF is added immediately after capture for server-generated images (RTSP/MJPEG).
 * EXIF is validated for all images before caching.
 * 
 * Fail closed: Images without valid EXIF are rejected.
 */

require_once __DIR__ . '/constants.php';
require_once __DIR__ . '/logger.php';
require_once __DIR__ . '/config.php';

/**
 * Check if exiftool is available
 * 
 * Caches result for performance. Required for pipeline operation.
 * In test mode, assumes exiftool is available to allow testing without it installed.
 * 
 * @return bool True if available
 */
function isExiftoolAvailable(): bool {
    static $available = null;
    
    if ($available === null) {
        // In test mode, assume exiftool is available to allow testing
        if (function_exists('isTestMode') && isTestMode()) {
            $available = true;
        } else {
            exec('which exiftool 2>/dev/null', $output, $exitCode);
            $available = ($exitCode === 0);
        }
    }
    
    return $available;
}

/**
 * Require exiftool to be available
 * 
 * Throws RuntimeException if exiftool is not installed.
 * Call at startup of scripts that need EXIF functionality.
 * In test mode, requirement is waived.
 * 
 * @return bool Always returns true
 * @throws RuntimeException If exiftool not found
 */
function requireExiftool(): bool {
    // In test mode, waive the requirement
    if (function_exists('isTestMode') && isTestMode()) {
        return true;
    }
    
    if (!isExiftoolAvailable()) {
        throw new RuntimeException(
            'exiftool is required but not available. ' .
            'Install libimage-exiftool-perl package.'
        );
    }
    
    return true;
}

/**
 * Get EXIF DateTimeOriginal from image
 * 
 * Tries PHP's native exif_read_data first for speed, then falls back to
 * exiftool for images where PHP can't read the EXIF (e.g., MJPEG frames
 * where exiftool added the timestamp after capture).
 * 
 * @param string $filePath Path to image
 * @return int Unix timestamp, or 0 if not found/invalid
 */
function getExifTimestamp(string $filePath): int {
    if (!file_exists($filePath)) {
        return 0;
    }
    
    // Try PHP's native exif_read_data first (faster)
    if (function_exists('exif_read_data')) {
        $exif = @exif_read_data($filePath, 'EXIF', true);
        if ($exif !== false) {
            // Check various locations where DateTimeOriginal might be stored
            $dateTime = $exif['EXIF']['DateTimeOriginal'] 
                     ?? $exif['DateTimeOriginal'] 
                     ?? $exif['IFD0']['DateTime']
                     ?? null;
            
            if ($dateTime !== null) {
                // Parse EXIF format: "2024:12:26 15:30:45"
                // Convert to: "2024-12-26 15:30:45"
                // Our pipeline writes EXIF in UTC (using gmdate), so parse as UTC
                $formatted = str_replace(':', '-', substr($dateTime, 0, 10)) . substr($dateTime, 10);
                $timestamp = @strtotime($formatted . ' UTC');
                
                if ($timestamp !== false && $timestamp > 0) {
                    return $timestamp;
                }
            }
        }
    }
    
    // Fallback to exiftool for images where PHP can't read EXIF
    // (e.g., MJPEG frames where we added timestamp via exiftool)
    $cmd = sprintf(
        'exiftool -s3 -DateTimeOriginal %s 2>/dev/null',
        escapeshellarg($filePath)
    );
    $output = @exec($cmd, $outputLines, $exitCode);
    
    if ($exitCode === 0 && !empty($output)) {
        // exiftool -s3 returns just the value: "2024:12:26 15:30:45"
        // Our pipeline writes EXIF in UTC (using gmdate), so parse as UTC
        $formatted = str_replace(':', '-', substr($output, 0, 10)) . substr($output, 10);
        $timestamp = @strtotime($formatted . ' UTC');
        
        if ($timestamp !== false && $timestamp > 0) {
            return $timestamp;
        }
    }
    
    return 0;
}

/**
 * Check if image has EXIF DateTimeOriginal
 * 
 * @param string $filePath Path to image
 * @return bool True if EXIF timestamp exists and is parseable
 */
function hasExifTimestamp(string $filePath): bool {
    return getExifTimestamp($filePath) > 0;
}

/**
 * Add EXIF DateTimeOriginal to image using exiftool
 * 
 * For server-generated images (RTSP/MJPEG), uses file mtime as capture time.
 * Must be called immediately after capture (within 1 second) to ensure
 * accuracy of timestamp.
 * 
 * @param string $filePath Path to image
 * @param int|null $timestamp Unix timestamp to set (default: file mtime)
 * @return bool True on success
 * @throws RuntimeException If exiftool not available (unless in test mode)
 */
function addExifTimestamp(string $filePath, ?int $timestamp = null): bool {
    // In test mode without exiftool, simulate success
    if (function_exists('isTestMode') && isTestMode()) {
        exec('which exiftool 2>/dev/null', $output, $exitCode);
        if ($exitCode !== 0) {
            return true; // Simulate success in test mode
        }
    }
    
    requireExiftool();
    
    if (!file_exists($filePath)) {
        aviationwx_log('error', 'addExifTimestamp: file not found', [
            'file' => $filePath
        ], 'app');
        return false;
    }
    
    // Use file mtime if no timestamp provided (preserves capture time)
    if ($timestamp === null) {
        $timestamp = @filemtime($filePath);
        if ($timestamp === false) {
            $timestamp = time();
        }
    }
    
    // Format for EXIF: "2024:12:26 15:30:45"
    // Always use gmdate() to write UTC time, matching JavaScript parser expectations
    $exifDateTime = gmdate('Y:m:d H:i:s', $timestamp);
    
    // Build exiftool command
    // -overwrite_original: Don't create backup files
    // -q: Quiet mode (less output)
    // -P: Preserve file modification time
    $cmd = sprintf(
        'exiftool -overwrite_original -q -P -DateTimeOriginal=%s %s 2>&1',
        escapeshellarg($exifDateTime),
        escapeshellarg($filePath)
    );
    
    exec($cmd, $output, $exitCode);
    
    if ($exitCode !== 0) {
        aviationwx_log('error', 'addExifTimestamp: exiftool failed', [
            'file' => basename($filePath),
            'exit_code' => $exitCode,
            'output' => implode("\n", array_slice($output, 0, 5))
        ], 'app');
        return false;
    }
    
    return true;
}

/**
 * Ensure image has EXIF DateTimeOriginal
 * 
 * If EXIF exists, returns true. If not, adds EXIF using the best available
 * timestamp source:
 * 1. Timestamp parsed from filename (most accurate for IP cameras)
 * 2. File modification time (fallback)
 * 
 * Use for server-generated images (RTSP/MJPEG/static fetch) and push camera uploads.
 * 
 * @param string $filePath Path to image
 * @return bool True if EXIF now exists
 */
function ensureExifTimestamp(string $filePath): bool {
    // Already has valid EXIF? Done.
    if (hasExifTimestamp($filePath)) {
        return true;
    }
    
    // Get best available timestamp (filename parsing → file mtime fallback)
    $timestamp = getTimestampForExif($filePath);
    
    // Add EXIF with determined timestamp
    return addExifTimestamp($filePath, $timestamp);
}

/**
 * Add AviationWX.org attribution metadata to webcam image
 * 
 * Adds IPTC and XMP metadata for attribution, copyright, and location.
 * Uses XMP for cross-format compatibility (JPEG, WebP).
 * Uses IPTC for additional richness on JPEG files.
 * 
 * @param string $filePath Path to image
 * @param string $airportId Airport identifier (e.g., 'kspb', '56s')
 * @param int $camIndex Camera index (0-based)
 * @param string|null $airportName Optional human-readable airport name
 * @return bool True on success
 */
function addAviationWxMetadata(string $filePath, string $airportId, int $camIndex, ?string $airportName = null): bool {
    // In test mode without exiftool, simulate success
    if (function_exists('isTestMode') && isTestMode()) {
        exec('which exiftool 2>/dev/null', $output, $exitCode);
        if ($exitCode !== 0) {
            return true; // Simulate success in test mode
        }
    }
    
    if (!file_exists($filePath)) {
        return false;
    }
    
    $airportUpper = strtoupper($airportId);
    $camNumber = $camIndex + 1; // Human-readable (1-based)
    $displayName = $airportName ? "$airportName ($airportUpper)" : $airportUpper;
    
    // Build metadata arguments
    // XMP fields work across JPEG and WebP
    $metaArgs = [
        '-XMP:Credit=AviationWX.org',
        '-XMP:Rights=Webcam imagery provided by AviationWX.org',
        '-XMP:Source=AviationWX.org Webcam Network',
        sprintf('-XMP:Description=Live webcam from %s - Camera %d', $displayName, $camNumber),
    ];
    
    // Detect file extension for IPTC support
    $ext = strtolower(pathinfo($filePath, PATHINFO_EXTENSION));
    $supportsIptc = in_array($ext, ['jpg', 'jpeg']);
    
    if ($supportsIptc) {
        // IPTC fields provide richer metadata for JPEG
        $metaArgs[] = '-IPTC:Credit=AviationWX.org';
        $metaArgs[] = '-IPTC:CopyrightNotice=Webcam imagery provided by AviationWX.org';
        $metaArgs[] = '-IPTC:Source=AviationWX.org Webcam Network';
        $metaArgs[] = sprintf('-IPTC:Sub-location=%s', $airportUpper);
        $metaArgs[] = sprintf('-IPTC:Caption-Abstract=Live webcam from %s - Camera %d', $displayName, $camNumber);
    }
    
    // Build single exiftool command with all metadata
    $cmd = sprintf(
        'exiftool -overwrite_original -q -P %s %s 2>&1',
        implode(' ', array_map('escapeshellarg', $metaArgs)),
        escapeshellarg($filePath)
    );
    
    exec($cmd, $output, $exitCode);
    
    if ($exitCode !== 0) {
        aviationwx_log('warning', 'addAviationWxMetadata: exiftool failed', [
            'file' => basename($filePath),
            'airport' => $airportId,
            'cam' => $camIndex,
            'exit_code' => $exitCode
        ], 'app');
        return false;
    }
    
    return true;
}

/**
 * Copy EXIF metadata from source to destination image
 * 
 * Used when generating WebP from source JPEG.
 * Copies all EXIF/IPTC/XMP metadata to maintain clean dataset.
 * 
 * @param string $sourceFile Source image with EXIF
 * @param string $destFile Destination image (must already exist)
 * @return bool True on success
 */
function copyExifMetadata(string $sourceFile, string $destFile): bool {
    // In test mode without exiftool, simulate success
    if (function_exists('isTestMode') && isTestMode()) {
        exec('which exiftool 2>/dev/null', $output, $exitCode);
        if ($exitCode !== 0) {
            return true; // Simulate success in test mode
        }
    }
    
    requireExiftool();
    
    if (!file_exists($sourceFile)) {
        aviationwx_log('error', 'copyExifMetadata: source file not found', [
            'source' => $sourceFile
        ], 'app');
        return false;
    }
    
    if (!file_exists($destFile)) {
        aviationwx_log('error', 'copyExifMetadata: destination file not found', [
            'dest' => $destFile
        ], 'app');
        return false;
    }
    
    // Copy all metadata from source to destination
    // -overwrite_original: Don't create backup files
    // -q: Quiet mode
    // -P: Preserve file modification time
    // -all:all: Copy all metadata groups
    $cmd = sprintf(
        'exiftool -overwrite_original -q -P -TagsFromFile %s -all:all %s 2>&1',
        escapeshellarg($sourceFile),
        escapeshellarg($destFile)
    );
    
    exec($cmd, $output, $exitCode);
    
    if ($exitCode !== 0) {
        // Log but don't fail - some formats may not support all metadata
        aviationwx_log('warning', 'copyExifMetadata: exiftool returned non-zero', [
            'source' => basename($sourceFile),
            'dest' => basename($destFile),
            'exit_code' => $exitCode
        ], 'app');
    }
    
    return true;
}

/**
 * Validate image EXIF timestamp
 * 
 * Checks that:
 * 1. EXIF DateTimeOriginal exists
 * 2. Year is reasonable (not garbage data)
 * 3. Timestamp is not in future (beyond threshold)
 * 4. Timestamp is not too old (stale)
 * 
 * Fail closed: Returns invalid for any issue.
 * 
 * @param string $filePath Path to image
 * @return array {
 *   'valid' => bool,
 *   'timestamp' => int,
 *   'reason' => string|null
 * }
 */
function validateExifTimestamp(string $filePath): array {
    $timestamp = getExifTimestamp($filePath);
    $now = time();
    
    // No EXIF timestamp - REJECT
    if ($timestamp === 0) {
        return [
            'valid' => false,
            'timestamp' => 0,
            'reason' => 'no_exif_timestamp'
        ];
    }
    
    // Garbage year (too old) - REJECT
    $year = (int)date('Y', $timestamp);
    if ($year < WEBCAM_EXIF_MIN_VALID_YEAR) {
        return [
            'valid' => false,
            'timestamp' => $timestamp,
            'reason' => sprintf('year_too_old_%d', $year)
        ];
    }
    
    // Garbage year (too far future) - REJECT
    if ($year > WEBCAM_EXIF_MAX_VALID_YEAR) {
        return [
            'valid' => false,
            'timestamp' => $timestamp,
            'reason' => sprintf('year_too_future_%d', $year)
        ];
    }
    
    // Future timestamp (clock misconfiguration) - REJECT
    $futureThreshold = $now + WEBCAM_EXIF_MAX_FUTURE_SECONDS;
    if ($timestamp > $futureThreshold) {
        $deltaSeconds = $timestamp - $now;
        return [
            'valid' => false,
            'timestamp' => $timestamp,
            'reason' => sprintf('future_by_%d_seconds', $deltaSeconds)
        ];
    }
    
    // Stale timestamp (old image) - REJECT
    $staleThreshold = $now - WEBCAM_EXIF_MAX_AGE_SECONDS;
    if ($timestamp < $staleThreshold) {
        $ageHours = round(($now - $timestamp) / 3600, 1);
        return [
            'valid' => false,
            'timestamp' => $timestamp,
            'reason' => sprintf('stale_by_%.1f_hours', $ageHours)
        ];
    }
    
    // All checks passed
    return [
        'valid' => true,
        'timestamp' => $timestamp,
        'reason' => null
    ];
}

/**
 * Format EXIF validation result for logging
 * 
 * @param array $result Result from validateExifTimestamp()
 * @return string Human-readable description
 */
function formatExifValidationResult(array $result): string {
    if ($result['valid']) {
        return sprintf('valid (timestamp: %s)', date('Y-m-d H:i:s', $result['timestamp']));
    }
    
    $reason = $result['reason'] ?? 'unknown';
    if ($result['timestamp'] > 0) {
        return sprintf('invalid: %s (timestamp: %s)', $reason, date('Y-m-d H:i:s', $result['timestamp']));
    }
    
    return sprintf('invalid: %s', $reason);
}

/**
 * Parse timestamp from filename
 * 
 * Attempts to extract capture timestamp from common filename patterns used by
 * IP cameras (e.g., Reolink, Hikvision). Validates that detected patterns are
 * actually valid timestamps and not coincidental numeric sequences.
 * 
 * Supported patterns:
 * - YYYYMMDDHHmmss (e.g., 20251229210421 → 2025-12-29 21:04:21)
 * - YYYY-MM-DD_HH-MM-SS (e.g., 2025-12-29_21-04-21)
 * - YYYY_MM_DD_HH_MM_SS (e.g., 2025_12_29_21_04_21)
 * - YYYYMMDDTHHmmss (ISO-like, e.g., 20251229T210421)
 * - Unix timestamps (10 digits)
 * 
 * Validation uses tight rolling windows to prevent false matches:
 * - Year: current year only (±1 year at year boundaries for timezone edge cases)
 * - Unix timestamp: current time ±31 days
 * - All timestamps must be within ±24 hours of file mtime
 * 
 * @param string $filePath Path to file (uses basename for parsing)
 * @return array {
 *   'found' => bool,
 *   'timestamp' => int (Unix timestamp or 0),
 *   'pattern' => string|null (matched pattern description),
 *   'timezone_hint' => string|null (detected timezone if any)
 * }
 */
function parseFilenameTimestamp(string $filePath): array {
    $filename = basename($filePath);
    $filenameNoExt = pathinfo($filename, PATHINFO_FILENAME);
    
    $result = [
        'found' => false,
        'timestamp' => 0,
        'pattern' => null,
        'timezone_hint' => null
    ];
    
    // Get file mtime for sanity check (timestamp should be within 24 hours of mtime)
    $fileMtime = @filemtime($filePath);
    if ($fileMtime === false) {
        $fileMtime = time();
    }
    
    // Pattern 1: YYYYMMDDHHmmss (14 consecutive digits) - most common for IP cameras
    // Example: KCZK-01_00_20251229210421.jpg → 20251229210421
    if (preg_match('/(\d{14})/', $filenameNoExt, $matches)) {
        $ts = $matches[1];
        $parsed = parseTimestampComponents(
            substr($ts, 0, 4),   // year
            substr($ts, 4, 2),   // month
            substr($ts, 6, 2),   // day
            substr($ts, 8, 2),   // hour
            substr($ts, 10, 2),  // minute
            substr($ts, 12, 2)   // second
        );
        
        if ($parsed !== null && isTimestampReasonable($parsed, $fileMtime)) {
            $result['found'] = true;
            $result['timestamp'] = $parsed;
            $result['pattern'] = 'YYYYMMDDHHmmss';
            return $result;
        }
    }
    
    // Pattern 2: YYYY-MM-DD_HH-MM-SS or YYYY-MM-DD-HH-MM-SS
    // Example: cam_2025-12-29_21-04-21.jpg
    if (preg_match('/(\d{4})-(\d{2})-(\d{2})[_-](\d{2})-(\d{2})-(\d{2})/', $filenameNoExt, $matches)) {
        $parsed = parseTimestampComponents($matches[1], $matches[2], $matches[3], $matches[4], $matches[5], $matches[6]);
        
        if ($parsed !== null && isTimestampReasonable($parsed, $fileMtime)) {
            $result['found'] = true;
            $result['timestamp'] = $parsed;
            $result['pattern'] = 'YYYY-MM-DD_HH-MM-SS';
            return $result;
        }
    }
    
    // Pattern 3: YYYY_MM_DD_HH_MM_SS (underscore separated)
    // Example: cam_2025_12_29_21_04_21.jpg
    if (preg_match('/(\d{4})_(\d{2})_(\d{2})_(\d{2})_(\d{2})_(\d{2})/', $filenameNoExt, $matches)) {
        $parsed = parseTimestampComponents($matches[1], $matches[2], $matches[3], $matches[4], $matches[5], $matches[6]);
        
        if ($parsed !== null && isTimestampReasonable($parsed, $fileMtime)) {
            $result['found'] = true;
            $result['timestamp'] = $parsed;
            $result['pattern'] = 'YYYY_MM_DD_HH_MM_SS';
            return $result;
        }
    }
    
    // Pattern 4: YYYYMMDDTHHmmss (ISO-like with T separator)
    // Example: 20251229T210421.jpg
    if (preg_match('/(\d{4})(\d{2})(\d{2})T(\d{2})(\d{2})(\d{2})/', $filenameNoExt, $matches)) {
        $parsed = parseTimestampComponents($matches[1], $matches[2], $matches[3], $matches[4], $matches[5], $matches[6]);
        
        if ($parsed !== null && isTimestampReasonable($parsed, $fileMtime)) {
            $result['found'] = true;
            $result['timestamp'] = $parsed;
            $result['pattern'] = 'YYYYMMDDTHHmmss';
            return $result;
        }
    }
    
    // Pattern 5: Unix timestamp (10 digits within tight rolling window)
    // Example: webcam_1767072037.jpg
    // Use tight window: current time ±31 days (covers month boundary edge cases)
    // Use lookbehind/lookahead instead of word boundary to handle underscores
    if (preg_match('/(?<![0-9])(\d{10})(?![0-9])/', $filenameNoExt, $matches)) {
        $unixTs = intval($matches[1]);
        $thirtyOneDays = 31 * 24 * 60 * 60;
        $minUnix = time() - $thirtyOneDays;
        $maxUnix = time() + $thirtyOneDays;
        
        if ($unixTs >= $minUnix && $unixTs <= $maxUnix && isTimestampReasonable($unixTs, $fileMtime)) {
            $result['found'] = true;
            $result['timestamp'] = $unixTs;
            $result['pattern'] = 'unix_timestamp';
            return $result;
        }
    }
    
    return $result;
}

/**
 * Parse and validate timestamp components
 * 
 * Uses tight year validation with smart edge-case handling:
 * - Primarily accepts current year only
 * - In January: also accepts previous year (Dec→Jan boundary)
 * - In December: also accepts next year (timezone ahead of server)
 * 
 * This prevents false matches on random 14-digit sequences while handling
 * legitimate year boundary cases from timezone differences.
 * 
 * @param string $year Year (4 digits)
 * @param string $month Month (2 digits)
 * @param string $day Day (2 digits)
 * @param string $hour Hour (2 digits)
 * @param string $minute Minute (2 digits)
 * @param string $second Second (2 digits)
 * @return int|null Unix timestamp or null if invalid
 */
function parseTimestampComponents(string $year, string $month, string $day, string $hour, string $minute, string $second): ?int {
    $y = intval($year);
    $m = intval($month);
    $d = intval($day);
    $h = intval($hour);
    $i = intval($minute);
    $s = intval($second);
    
    // Tight year validation with edge-case handling
    $currentYear = intval(date('Y'));
    $currentMonth = intval(date('n'));
    
    // Build allowed years list
    $allowedYears = [$currentYear];
    
    // In January, also allow previous year (handles Dec 31 → Jan 1 boundary)
    if ($currentMonth === 1) {
        $allowedYears[] = $currentYear - 1;
    }
    
    // In December, also allow next year (handles timezone ahead of server)
    if ($currentMonth === 12) {
        $allowedYears[] = $currentYear + 1;
    }
    
    // Validate year is in allowed list
    if (!in_array($y, $allowedYears, true)) {
        return null;
    }
    
    // Validate other ranges
    if ($m < 1 || $m > 12) return null;
    if ($d < 1 || $d > 31) return null;
    if ($h < 0 || $h > 23) return null;
    if ($i < 0 || $i > 59) return null;
    if ($s < 0 || $s > 59) return null;
    
    // Use checkdate for accurate day validation (handles Feb 29, etc.)
    if (!checkdate($m, $d, $y)) {
        return null;
    }
    
    // Create timestamp (assumes camera local time, which may differ from server)
    $timestamp = mktime($h, $i, $s, $m, $d, $y);
    
    if ($timestamp === false) {
        return null;
    }
    
    return $timestamp;
}

/**
 * Check if parsed timestamp is reasonable compared to file mtime
 * 
 * A timestamp from a filename should be close to the file's modification time.
 * Allows ±24 hours to account for timezone differences and upload delays.
 * 
 * @param int $timestamp Parsed timestamp
 * @param int $fileMtime File modification time
 * @return bool True if timestamp is reasonable
 */
function isTimestampReasonable(int $timestamp, int $fileMtime): bool {
    // Must be positive
    if ($timestamp <= 0) {
        return false;
    }
    
    // Allow ±24 hours difference (86400 seconds) to account for:
    // - Timezone differences between camera and server
    // - Upload delays
    // - Clock drift
    $maxDifference = 86400;
    $difference = abs($timestamp - $fileMtime);
    
    return $difference <= $maxDifference;
}

/**
 * Get timestamp for EXIF from filename or file mtime
 * 
 * Tries to parse timestamp from filename first (more accurate for IP cameras),
 * falls back to file modification time if no valid timestamp found in filename.
 * 
 * Logs when filename timestamp differs significantly from mtime (>5 minutes),
 * which may indicate timezone configuration issues.
 * 
 * @param string $filePath Path to image file
 * @return int Unix timestamp for EXIF
 */
function getTimestampForExif(string $filePath): int {
    // Get file mtime for comparison
    $mtime = @filemtime($filePath);
    if ($mtime === false) {
        $mtime = time();
    }
    
    // Try to extract from filename first
    $filenameTs = parseFilenameTimestamp($filePath);
    
    if ($filenameTs['found']) {
        $difference = abs($filenameTs['timestamp'] - $mtime);
        
        // Log when using filename timestamp
        aviationwx_log('debug', 'using filename timestamp for EXIF', [
            'file' => basename($filePath),
            'pattern' => $filenameTs['pattern'],
            'timestamp' => date('Y-m-d H:i:s', $filenameTs['timestamp']),
            'mtime' => date('Y-m-d H:i:s', $mtime),
            'difference_seconds' => $difference
        ], 'app');
        
        // Warn if filename timestamp differs from mtime by more than 5 minutes
        // This could indicate timezone misconfiguration between camera and server
        if ($difference > 300) {
            $diffMinutes = round($difference / 60, 1);
            $diffHours = round($difference / 3600, 1);
            
            aviationwx_log('warning', 'filename timestamp differs significantly from file mtime', [
                'file' => basename($filePath),
                'filename_timestamp' => date('Y-m-d H:i:s', $filenameTs['timestamp']),
                'file_mtime' => date('Y-m-d H:i:s', $mtime),
                'difference_minutes' => $diffMinutes,
                'difference_hours' => $diffHours,
                'pattern' => $filenameTs['pattern'],
                'note' => 'This may indicate timezone misconfiguration between camera and server'
            ], 'app');
        }
        
        return $filenameTs['timestamp'];
    }
    
    return $mtime;
}


