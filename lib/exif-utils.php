<?php
/**
 * EXIF Utilities for Webcam Pipeline
 * 
 * Provides functions for reading, writing, and validating EXIF timestamps.
 * EXIF handling is native and in-process; no external tool is required.
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
require_once __DIR__ . '/exif/read.php';
require_once __DIR__ . '/exif/write.php';

// EXIF Timestamp Normalization Constants
// Used for timezone detection and validation of push camera uploads
const EXIF_ACCEPTABLE_TIME_DRIFT_SECONDS = 300;     // 5 minutes (upload delay, clock drift, processing)
const EXIF_TIMEZONE_DETECTION_VARIANCE = 600;       // 10 minutes (tolerance around round hour offsets)




/**
 * Get UTC timestamp from image EXIF data
 * 
 * Priority order:
 * 1. GPS DateStamp + TimeStamp (always UTC per EXIF spec) - added by our pipeline
 * 2. DateTimeOriginal (local time) - only if GPS fields not present
 * 
 * IMPORTANT: DateTimeOriginal is kept in camera's local time for audit purposes.
 * This function returns UTC timestamp for all application logic (age calculations,
 * filename timestamps, etc.).
 * 
 * @param string $filePath Path to image
 * @return int Unix timestamp (UTC), or 0 if not found/invalid
 */
function getExifTimestamp(string $filePath): int {
    $result = readExifTimestamp($filePath);
    return $result['timestamp'];
}

/**
 * Read EXIF timestamp, distinguishing a failed read from an absent field.
 *
 * @param string $filePath Path to image
 * @return array{status: string, timestamp: int} status is 'ok', 'absent', or 'error'
 */
function readExifTimestamp(string $filePath): array {
    $gpsRead = readGpsUtcTimestampFromExif($filePath);
    if ($gpsRead['status'] === 'ok') {
        return resolveExifTimestampStatus($gpsRead, null);
    }
    $dtRead = readDateTimeOriginalFromExif($filePath);
    return resolveExifTimestampStatus($gpsRead, $dtRead);
}

/**
 * Resolve the final tri-state from GPS and DateTimeOriginal read results.
 *
 * Pure so the fallback logic is unit-testable.
 *
 * GPS is the authoritative UTC source. When GPS is absent, DateTimeOriginal
 * is a valid fallback. When GPS read failed (transient), a DateTimeOriginal
 * fallback would be local time and could fail the UTC-based future-time check,
 * so the result is always 'error' to trigger retry.
 *
 * @param array{status: string, timestamp: int} $gpsRead GPS read result
 * @param array{status: string, timestamp: int}|null $dtRead DateTimeOriginal result, null when GPS was ok
 * @return array{status: string, timestamp: int}
 */
function resolveExifTimestampStatus(array $gpsRead, ?array $dtRead): array {
    if ($gpsRead['status'] === 'ok') {
        return ['status' => 'ok', 'timestamp' => $gpsRead['timestamp']];
    }
    if ($gpsRead['status'] === 'absent') {
        if ($dtRead !== null) {
            return $dtRead['status'] === 'ok'
                ? ['status' => 'ok', 'timestamp' => $dtRead['timestamp']]
                : $dtRead;
        }
        return ['status' => 'absent', 'timestamp' => 0];
    }
    return ['status' => 'error', 'timestamp' => 0];
}

/**
 * Read DateTimeOriginal timestamp from EXIF, distinguishing a failed read from a missing field.
 *
 * @param string $filePath Path to image file
 * @return array{status: string, timestamp: int} status is 'ok', 'absent', or 'error'
 */
function readDateTimeOriginalFromExif(string $filePath): array {
    if (!file_exists($filePath) || !is_readable($filePath)) {
        // A file that exists but is unreadable is a transient I/O error
        // (permissions, NFS hiccup). Classify as 'error' so callers preserve the
        // upload for retry instead of quarantining it as 'absent'.
        return ['status' => 'error', 'timestamp' => 0];
    }

    $native = exifNativeRead($filePath);
    if ($native['status'] === 'error') {
        return ['status' => 'error', 'timestamp' => 0];
    }
    if (($native['status'] ?? '') !== 'ok' || !isset($native['DateTimeOriginal'])) {
        return ['status' => 'absent', 'timestamp' => 0];
    }

    $timestamp = exifNativeDateTimeOriginalTimestamp($native['DateTimeOriginal']);
    return $timestamp > 0
        ? ['status' => 'ok', 'timestamp' => $timestamp]
        : ['status' => 'absent', 'timestamp' => 0];
}

/**
 * Get DateTimeOriginal timestamp from EXIF (local time, legacy fallback)
 * 
 * WARNING: This returns the camera's local time interpreted as UTC.
 * Only use this as a fallback when GPS fields are not available.
 * 
 * Note: This function returns 0 for both missing EXIF data and read errors
 * (unreadable file). Callers that need to distinguish these outcomes should
 * use readDateTimeOriginalFromExif() instead.
 * 
 * @param string $filePath Path to image
 * @return int Unix timestamp, or 0 if not found/invalid
 */
function getDateTimeOriginalTimestamp(string $filePath): int {
    $result = readDateTimeOriginalFromExif($filePath);
    return $result['timestamp'];
}


/**
 * Read the GPS UTC timestamp, distinguishing a failed read from a missing field.
 *
 * Consumers react differently to these two outcomes. A read error is transient
 * (unreadable file, corrupt header, a crash) and must be retried or preserved;
 * an absent field is a genuine rejection of the image. The integer-only read
 * collapsed both to 0 and let callers delete valid data on a transient blip.
 *
 * @param string $filePath Path to image file
 * @return array{status: string, timestamp: int} status is 'ok', 'absent', or 'error'
 */
function readGpsUtcTimestampFromExif(string $filePath): array {
    if (!file_exists($filePath) || !is_readable($filePath)) {
        return ['status' => 'error', 'timestamp' => 0];
    }

    $native = exifNativeRead($filePath);
    if ($native['status'] === 'error') {
        return ['status' => 'error', 'timestamp' => 0];
    }
    if (($native['status'] ?? '') !== 'ok'
        || !isset($native['GpsDateStamp'])
        || !isset($native['GpsTimeComponents'])) {
        return ['status' => 'absent', 'timestamp' => 0];
    }

    $timestamp = exifNativeGpsUtcTimestamp($native['GpsDateStamp'], $native['GpsTimeComponents']);
    return $timestamp > 0
        ? ['status' => 'ok', 'timestamp' => $timestamp]
        : ['status' => 'absent', 'timestamp' => 0];
}



/**
 * Check if image has EXIF DateTimeOriginal
 * 
 * Returns true when a valid timestamp is present (status 'ok' with timestamp > 0)
 * OR when a transient error occurred (status 'error'). Returns false only when
 * the timestamp is genuinely absent (status 'absent').
 * 
 * Callers that need to distinguish transient errors from missing EXIF should
 * use readExifTimestamp() directly and check the status field.
 * 
 * @param string $filePath Path to image
 * @return bool True if EXIF timestamp exists and is parseable, or transient error
 */
function hasExifTimestamp(string $filePath): bool {
    $result = readExifTimestamp($filePath);
    return $result['status'] !== 'absent';
}

/**
 * Check whether an image carries a complete capture-time EXIF payload: both the
 * UTC GPS timestamp and the local DateTimeOriginal.
 *
 * A healthy AviationWX source writes both on every processed image, so a
 * federated frame missing either field is malformed and must not be trusted.
 *
 * @param string $filePath Path to image
 * @return bool True when both the GPS UTC and DateTimeOriginal fields are present
 */
function hasCompleteWebcamExif(string $filePath): bool {
    $gpsRead = readGpsUtcTimestampFromExif($filePath);
    $dtRead = readDateTimeOriginalFromExif($filePath);
    return $gpsRead['status'] === 'ok' && $gpsRead['timestamp'] > 0
        && $dtRead['status'] === 'ok' && $dtRead['timestamp'] > 0;
}

/**
 * Add EXIF DateTimeOriginal to image.
 *
 * Follows EXIF standard:
 * - DateTimeOriginal: Local time at the location (camera's timezone)
 * - GPS fields: UTC (Zulu time)
 *
 * Backed by the native EXIF writer; no external tool is used.
 *
 * @param string $filePath Path to image file
 * @param int|null $timestamp Unix timestamp to set (default: file mtime)
 * @param string $timezone IANA timezone (e.g., 'America/Los_Angeles') for DateTimeOriginal
 * @param bool $preserveOriginalDateTime If true, keep existing DateTimeOriginal (only update GPS)
 * @param array<string, mixed>|null $context Optional context for error logs (airport_id, cam_index, source_type)
 * @return bool True on success
 */
function addExifTimestamp(string $filePath, ?int $timestamp = null, string $timezone = 'UTC', bool $preserveOriginalDateTime = false, ?array $context = null): bool {
    if (!file_exists($filePath)) {
        $logData = array_merge(['file' => $filePath], (array)$context);
        aviationwx_log('error', 'addExifTimestamp: file not found', $logData, 'app');
        return false;
    }

    // Invalid timezones fall back to UTC.
    try {
        new DateTimeZone($timezone);
    } catch (Exception $e) {
        aviationwx_log('warning', 'addExifTimestamp: invalid timezone, using UTC', [
            'timezone' => $timezone,
            'file' => basename($filePath)
        ], 'app');
        $timezone = 'UTC';
    }

    // The native stamp preserves Make, Model, and Orientation and writes all
    // capture fields. The preserve flag is honored upstream by passing the
    // embedded capture time in as $timestamp.
    return exifNativeStamp($filePath, $timestamp, $timezone);
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
 * @param string $timezone IANA timezone for DateTimeOriginal
 * @return bool True if EXIF now exists
 */
function ensureExifTimestamp(string $filePath, string $timezone = 'UTC'): bool {
    // Already has valid EXIF? Done.
    if (hasExifTimestamp($filePath)) {
        return true;
    }
    
    // Get best available timestamp (filename parsing → file mtime fallback)
    $timestamp = getTimestampForExif($filePath);
    
    // Add EXIF with determined timestamp
    return addExifTimestamp($filePath, $timestamp, $timezone);
}

/**
 * Normalize EXIF for push camera uploads (EXIF standard compliant)
 * 
 * Many cameras write EXIF DateTimeOriginal in local time without timezone info.
 * This is ENCOURAGED for operators (matches their clocks and security footage).
 * 
 * This function:
 * 1. Validates the camera's DateTimeOriginal is reasonable
 * 2. Detects timezone offset by comparing EXIF vs file mtime
 * 3. Adds GPS UTC fields for client-side verification
 * 4. PRESERVES the original DateTimeOriginal (local time per EXIF standard)
 * 
 * @param string $filePath Path to uploaded image
 * @param string $airportId Airport identifier (for history retention lookup)
 * @param int $camIndex Camera index (for logging)
 * @param string $timezone Airport timezone (for adding GPS fields with correct UTC conversion)
 * @return bool True if image timestamp is reliable and GPS fields added
 */
function normalizeExifToUtc(string $filePath, string $airportId, int $camIndex, string $timezone = 'UTC'): bool {
    $context = ['airport_id' => $airportId, 'cam_index' => $camIndex, 'source_type' => 'push'];

    // Get server file modification time (UTC, set on upload completion)
    $mtime = filemtime($filePath);
    if ($mtime === false) {
        aviationwx_log('error', 'normalizeExifToUtc: Cannot read file mtime', [
            'file' => basename($filePath),
            'airport' => $airportId,
            'cam' => $camIndex
        ]);
        logWebcamRejection($airportId, $camIndex, 'system_error', 'Cannot read file mtime', $filePath);
        return false;
    }
    
    // Extract camera's EXIF timestamp (most accurate, but may be local time)
    $exifRead = readExifTimestamp($filePath);
    
    // Case A: No EXIF or unparseable → REJECT
    // If read failed (transient), skip this image for retry; don't consume it
    if ($exifRead['status'] === 'error') {
        return false; // Return false to allow retry, don't consume the upload
    }
    if ($exifRead['status'] === 'absent' || $exifRead['timestamp'] === 0) {
        aviationwx_log('warn', 'Push camera image rejected: no valid EXIF timestamp', [
            'file' => basename($filePath),
            'airport' => $airportId,
            'cam' => $camIndex
        ]);
        logWebcamRejection($airportId, $camIndex, 'no_exif', 'No valid EXIF DateTimeOriginal found', $filePath);
        return false;
    }
    
    $exifTimestamp = $exifRead['timestamp'];
    
    // Calculate time difference
    $diff = $mtime - $exifTimestamp;
    $absDiff = abs($diff);
    
    // Case B: EXIF is in future (beyond acceptable drift)
    // Allows small future times for clock drift, NTP delays
    if ($diff < -EXIF_ACCEPTABLE_TIME_DRIFT_SECONDS) {
        aviationwx_log('warn', 'Push camera image rejected: EXIF timestamp in future', [
            'file' => basename($filePath),
            'airport' => $airportId,
            'cam' => $camIndex,
            'exif' => gmdate('Y-m-d H:i:s', $exifTimestamp) . ' UTC',
            'mtime' => gmdate('Y-m-d H:i:s', $mtime) . ' UTC',
            'diff_seconds' => $diff
        ]);
        logWebcamRejection($airportId, $camIndex, 'timestamp_future', 
            sprintf('EXIF %d seconds in future (camera clock ahead)', -$diff), $filePath);
        return false;
    }
    
    // Case C: EXIF ≈ mtime (already UTC, within acceptable drift)
    // This handles: camera already writes UTC, upload delays, clock drift
    if ($absDiff <= EXIF_ACCEPTABLE_TIME_DRIFT_SECONDS) {
        aviationwx_log('debug', 'Push camera EXIF already UTC, converting to local time', [
            'file' => basename($filePath),
            'airport' => $airportId,
            'cam' => $camIndex,
            'exif_utc' => gmdate('Y-m-d H:i:s', $exifTimestamp) . ' UTC',
            'timezone' => $timezone,
            'diff_seconds' => $diff
        ]);
        // Camera writes UTC - convert DateTimeOriginal to local time and set correct offset
        // Pass preserveOriginalDateTime=false to rewrite DateTimeOriginal to local time
        if (!addExifTimestamp($filePath, $exifTimestamp, $timezone, false, $context)) {
            logWebcamRejection($airportId, $camIndex, 'exif_rewrite_failed',
                'failed to add GPS fields', $filePath);
            return false;
        }
        return true;
    }
    
    // Case D: Detect timezone offset
    // EXIF differs by more than drift tolerance - likely timezone issue (expected!)
    $detectedOffset = detectTimezoneOffset($diff, EXIF_TIMEZONE_DETECTION_VARIANCE);
    
    if ($detectedOffset !== null) {
        // Found a plausible timezone offset
        $utcTimestamp = $exifTimestamp + $detectedOffset;
        $correctedDiff = $mtime - $utcTimestamp;
        
        // Validate corrected timestamp is within acceptable drift
        if (abs($correctedDiff) <= EXIF_ACCEPTABLE_TIME_DRIFT_SECONDS) {
            // Timezone correction successful, but check age
            $imageAge = time() - $utcTimestamp;
            $maxAge = getHistoryRetentionSeconds($airportId);
            
            if ($imageAge > $maxAge) {
                aviationwx_log('warn', 'Push camera image rejected: too old after timezone correction', [
                    'file' => basename($filePath),
                    'airport' => $airportId,
                    'cam' => $camIndex,
                    'image_age_hours' => round($imageAge / 3600, 2),
                    'max_age_hours' => round($maxAge / 3600, 2),
                    'detected_offset_hours' => $detectedOffset / 3600
                ]);
                logWebcamRejection($airportId, $camIndex, 'timestamp_too_old', 
                    sprintf('Image age %.1f hours exceeds retention %.1f hours', 
                        $imageAge / 3600, $maxAge / 3600), $filePath);
                return false;
            }

            // Add GPS UTC fields, preserve original DateTimeOriginal (local time)
            // The GPS fields will be used by client for verification, not DateTimeOriginal
            if (!addExifTimestamp($filePath, $utcTimestamp, $timezone, true, $context)) {
                aviationwx_log('error', 'Failed to add GPS UTC fields', [
                    'file' => basename($filePath),
                    'airport' => $airportId,
                    'cam' => $camIndex
                ]);
                logWebcamRejection($airportId, $camIndex, 'exif_rewrite_failed', 
                    'failed to update timestamp', $filePath);
                return false;
            }
            
            aviationwx_log('info', 'Push camera EXIF normalized to UTC', [
                'file' => basename($filePath),
                'airport' => $airportId,
                'cam' => $camIndex,
                'original_exif' => gmdate('Y-m-d H:i:s', $exifTimestamp),
                'detected_offset_hours' => $detectedOffset / 3600,
                'corrected_utc' => gmdate('Y-m-d H:i:s', $utcTimestamp),
                'upload_delay_sec' => $correctedDiff,
                'image_age_minutes' => round($imageAge / 60, 1)
            ]);
            
            return true;
        }
    }
    
    // Case E: Cannot reliably determine capture time → REJECT
    // Reasons: not a timezone offset, too old even after correction, clock completely wrong
    $reason = $detectedOffset === null 
        ? sprintf('Timestamp diff %d seconds not a valid timezone offset', $diff)
        : sprintf('After timezone correction (+%dh), drift %d seconds exceeds tolerance', 
            $detectedOffset / 3600, abs($mtime - ($exifTimestamp + $detectedOffset)));
    
    aviationwx_log('warn', 'Push camera image rejected: unreliable timestamp', [
        'file' => basename($filePath),
        'airport' => $airportId,
        'cam' => $camIndex,
        'exif' => gmdate('Y-m-d H:i:s', $exifTimestamp),
        'mtime' => gmdate('Y-m-d H:i:s', $mtime),
        'diff_seconds' => $diff,
        'detected_offset' => $detectedOffset === null ? 'none' : ($detectedOffset / 3600) . 'h'
    ]);
    
    logWebcamRejection($airportId, $camIndex, 'timestamp_unreliable', $reason, $filePath);
    
    return false;
}

/**
 * Detect timezone offset from time difference
 * 
 * Checks if difference matches a known timezone offset (full or half hours).
 * Allows variance for upload delays and clock drift.
 * 
 * @param int $diff Time difference in seconds (mtime - exif)
 * @param int $variance Allowed variance in seconds (default: 600 = 10 minutes)
 * @return int|null Detected offset in seconds, or null if not a timezone
 */
function detectTimezoneOffset(int $diff, int $variance = 600): ?int {
    // Check full hour offsets (UTC-12 to UTC+14)
    for ($hours = -12; $hours <= 14; $hours++) {
        if ($hours == 0) continue; // Already handled in main function
        
        $offset = $hours * 3600;
        $offsetVariance = abs($diff - $offset);
        
        // Within variance of round hour offset?
        if ($offsetVariance <= $variance) {
            return $offset;
        }
    }
    
    // Check half-hour and quarter-hour offsets
    // Examples: India +5:30, Nepal +5:45, Newfoundland -3:30
    $fractionalOffsets = [
        -12.75, -12.5, -9.5, -3.5,           // Americas, Pacific
        3.5, 4.5, 5.5, 5.75, 6.5,            // Middle East, South Asia
        9.5, 10.5, 12.75                     // Australia, Pacific
    ];
    
    foreach ($fractionalOffsets as $offsetHours) {
        $offset = (int)($offsetHours * 3600);
        $offsetVariance = abs($diff - $offset);
        
        if ($offsetVariance <= $variance) {
            return $offset;
        }
    }
    
    return null; // Not a recognizable timezone offset
}

/**
 * Get history retention duration in seconds for an airport
 * 
 * Uses airport-specific setting if configured, otherwise global default.
 * This defines how old an image can be and still be considered useful.
 * 
 * @param string $airportId Airport identifier
 * @return int Maximum acceptable age in seconds
 */
function getHistoryRetentionSeconds(string $airportId): int {
    static $cache = [];
    
    if (isset($cache[$airportId])) {
        return $cache[$airportId];
    }
    
    $config = loadConfig();
    
    // Try airport-specific setting first
    if (isset($config['airports'][$airportId]['history_retention_hours'])) {
        $hours = (int)$config['airports'][$airportId]['history_retention_hours'];
    } 
    // Fall back to global default
    else if (isset($config['global']['history_retention_hours'])) {
        $hours = (int)$config['global']['history_retention_hours'];
    }
    // Ultimate fallback: 24 hours
    else {
        $hours = 24;
    }
    
    $seconds = $hours * 3600;
    $cache[$airportId] = $seconds;
    
    return $seconds;
}

/**
 * Log webcam image rejection to dedicated log file
 * 
 * Provides operational visibility into camera issues and rejection patterns.
 * Log file: /var/log/aviationwx/webcam-rejections.log
 * 
 * @param string $airportId Airport identifier
 * @param int $camIndex Camera index
 * @param string $reason Machine-readable rejection reason code
 * @param string $details Human-readable details
 * @param string|null $filePath Optional file path
 */
function logWebcamRejection(string $airportId, int $camIndex, string $reason, string $details, ?string $filePath = null): void {
    $logDir = '/var/log/aviationwx';
    $logFile = $logDir . '/webcam-rejections.log';
    
    // Ensure log directory exists
    if (!is_dir($logDir)) {
        @mkdir($logDir, 0755, true);
    }
    
    // Format: [timestamp] airport=X cam=Y reason=Z details="..." file=...
    $timestamp = gmdate('Y-m-d H:i:s');
    $fileName = $filePath ? basename($filePath) : 'unknown';
    
    $logEntry = sprintf(
        "[%s] airport=%s cam=%d reason=%s details=\"%s\" file=%s\n",
        $timestamp,
        $airportId,
        $camIndex,
        $reason,
        str_replace('"', "'", $details), // Escape quotes in details
        $fileName
    );
    
    // Write to log file (append mode)
    @file_put_contents($logFile, $logEntry, FILE_APPEND | LOCK_EX);
}


/**
 * Copy EXIF metadata from source to destination image.
 *
 * Backed by the native EXIF copy, which transplants the EXIF block across jpg,
 * png, and webp in either direction. XMP and ICC are deliberately not copied.
 *
 * @param string $sourceFile Source image with EXIF
 * @param string $destFile Destination image (must already exist)
 * @return bool True on success, false when the copy cannot be made
 */
function copyExifMetadata(string $sourceFile, string $destFile): bool {
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

    return exifNativeCopy($sourceFile, $destFile);
}

/**
 * Copy a pre-extracted EXIF TIFF block into a destination image.
 *
 * The same native copy as above, minus the source read. Callers that stamp the
 * same original onto many variants extract the TIFF once and reuse it here.
 *
 * @param string $tiff TIFF bytes extracted from the source image
 * @param string $destFile Destination image (must already exist)
 * @return bool True on success, false when the copy cannot be made
 */
function copyExifMetadataFromTiff(string $tiff, string $destFile): bool {
    if ($tiff === '') {
        aviationwx_log('error', 'copyExifMetadataFromTiff: empty TIFF block', [], 'app');
        return false;
    }

    if (!file_exists($destFile)) {
        aviationwx_log('error', 'copyExifMetadataFromTiff: destination file not found', [
            'dest' => $destFile
        ], 'app');
        return false;
    }

    return exifNativeCopyTiff($tiff, $destFile);
}

/**
 * Write credit tags onto a static rendered image.
 *
 * Backed by the native credit writer: a credit-only EXIF block with no capture
 * time. Preserves mtime like exiftool -P.
 *
 * @param string $filePath Image path
 * @param array{imageDescription?: string, artist?: string, copyright?: string, userComment?: string} $credit
 * @return bool True on success
 */
function writeImageCredit(string $filePath, array $credit): bool {
    if (!file_exists($filePath)) {
        aviationwx_log('error', 'writeImageCredit: file not found', [
            'file' => $filePath
        ], 'app');
        return false;
    }

    return exifNativeWriteCredit($filePath, $credit);
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
    return validateExifTimestampValue($timestamp);
}

/**
 * Validate an already-read EXIF timestamp value against the range checks.
 *
 * Kept separate from validateExifTimestamp() so callers that already hold the
 * capture time (a tri-state GPS read, for example) can run the same checks
 * without a second read racing the first.
 *
 * @param int $timestamp Unix timestamp to validate
 * @param string $readStatus The read status from readExifTimestamp(): 'ok', 'absent', or 'error'
 * @return array {
 *   'valid' => bool,
 *   'timestamp' => int,
 *   'reason' => string|null,
 *   'read_status' => string
 * }
 */
function validateExifTimestampValue(int $timestamp, string $readStatus = 'ok'): array {
    $now = time();
    
    if ($readStatus === 'error') {
        // Transient read error - return special code
        return [
            'valid' => false,
            'timestamp' => 0,
            'reason' => 'exif_read_error',
            'read_status' => 'error'
        ];
    }
    if ($readStatus === 'absent') {
        // Genuine absence - reject
        return [
            'valid' => false,
            'timestamp' => 0,
            'reason' => 'no_exif_timestamp',
            'read_status' => 'absent'
        ];
    }
    
    // No EXIF timestamp - REJECT
    if ($timestamp === 0) {
        return [
            'valid' => false,
            'timestamp' => 0,
            'reason' => 'no_exif_timestamp',
            'read_status' => 'absent'
        ];
    }
    
    // Garbage year (too old) - REJECT
    $year = (int)date('Y', $timestamp);
    if ($year < WEBCAM_EXIF_MIN_VALID_YEAR) {
        return [
            'valid' => false,
            'timestamp' => $timestamp,
            'reason' => sprintf('year_too_old_%d', $year),
            'read_status' => 'ok'
        ];
    }
    
    // Garbage year (too far future) - REJECT
    if ($year > WEBCAM_EXIF_MAX_VALID_YEAR) {
        return [
            'valid' => false,
            'timestamp' => $timestamp,
            'reason' => sprintf('year_too_future_%d', $year),
            'read_status' => 'ok'
        ];
    }
    
    // Future timestamp (clock misconfiguration) - REJECT
    $futureThreshold = $now + WEBCAM_EXIF_MAX_FUTURE_SECONDS;
    if ($timestamp > $futureThreshold) {
        $deltaSeconds = $timestamp - $now;
        return [
            'valid' => false,
            'timestamp' => $timestamp,
            'reason' => sprintf('future_by_%d_seconds', $deltaSeconds),
            'read_status' => 'ok'
        ];
    }
    
    // Stale timestamp (old image) - REJECT
    $staleThreshold = $now - WEBCAM_EXIF_MAX_AGE_SECONDS;
    if ($timestamp < $staleThreshold) {
        $ageHours = round(($now - $timestamp) / 3600, 1);
        return [
            'valid' => false,
            'timestamp' => $timestamp,
            'reason' => sprintf('stale_by_%.1f_hours', $ageHours),
            'read_status' => 'ok'
        ];
    }
    
    // All checks passed
    return [
        'valid' => true,
        'timestamp' => $timestamp,
        'reason' => null,
        'read_status' => 'ok'
    ];
}

/**
 * Validate image EXIF timestamp, distinguishing a failed read from an absent field.
 *
 * @param string $filePath Path to image
 * @return array {
 *   'valid' => bool,
 *   'timestamp' => int,
 *   'reason' => string|null,
 *   'read_status' => string
 * }
 */
function validateExifTimestampWithStatus(string $filePath): array {
    $result = readExifTimestamp($filePath);
    return validateExifTimestampValue($result['timestamp'], $result['status']);
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
 * - All timestamps must be within FILENAME_TIMESTAMP_MTIME_WINDOW_HOURS (default 12h) of file mtime
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
    // Delimiter (?:^|[-_. ]) - at start or after underscore/dash/dot/space (rejects SN2025...)
    // (?![0-9]) - non-digit after prevents extraction from longer numbers (2025...00123)
    // When multiple matches exist, prefer the one closest to file mtime
    $pattern = '(?:^|[-_. ])(\d{14})(?![0-9])';
    if (preg_match_all('/' . $pattern . '/', $filenameNoExt, $matches, PREG_SET_ORDER)) {
        $bestParsed = null;
        $bestDiff = PHP_INT_MAX;
        foreach ($matches as $m) {
            $ts = $m[1];
            $parsed = parseTimestampComponents(
                substr($ts, 0, 4),
                substr($ts, 4, 2),
                substr($ts, 6, 2),
                substr($ts, 8, 2),
                substr($ts, 10, 2),
                substr($ts, 12, 2)
            );
            if ($parsed !== null && isTimestampReasonable($parsed, $fileMtime)) {
                $diff = abs($parsed - $fileMtime);
                if ($diff < $bestDiff) {
                    $bestDiff = $diff;
                    $bestParsed = $parsed;
                }
            }
        }
        if ($bestParsed !== null) {
            $result['found'] = true;
            $result['timestamp'] = $bestParsed;
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
    
    // Pattern 6: Folder-based patterns /YYYY/MM/DD/HH/MM/
    // Example: /uploads/keul/2026/01/13/14/30/image.jpg
    if (preg_match('#/(\d{4})/(\d{2})/(\d{2})/(\d{2})/(\d{2})/#', $filePath, $matches)) {
        $parsed = parseTimestampComponents($matches[1], $matches[2], $matches[3], $matches[4], $matches[5], '00');
        
        if ($parsed !== null && isTimestampReasonable($parsed, $fileMtime)) {
            $result['found'] = true;
            $result['timestamp'] = $parsed;
            $result['pattern'] = 'folder_YYYY_MM_DD_HH_MM';
            return $result;
        }
    }
    
    // Pattern 7: Folder-based patterns /YYYY/MM/DD/
    // Example: /uploads/keul/2026/01/13/image.jpg
    // Use file mtime for time-of-day component
    if (preg_match('#/(\d{4})/(\d{2})/(\d{2})/#', $filePath, $matches)) {
        $year = $matches[1];
        $month = $matches[2];
        $day = $matches[3];
        
        // Create date at midnight
        $dateStr = "{$year}-{$month}-{$day} 00:00:00";
        $dayStart = strtotime($dateStr);
        
        if ($dayStart !== false && isTimestampReasonable($dayStart, $fileMtime)) {
            // Use mtime's time-of-day with folder's date
            $timeOfDay = $fileMtime % 86400; // Seconds since midnight
            $timestamp = $dayStart + $timeOfDay;
            
            if (isTimestampReasonable($timestamp, $fileMtime)) {
                $result['found'] = true;
                $result['timestamp'] = $timestamp;
                $result['pattern'] = 'folder_YYYY_MM_DD';
                return $result;
            }
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
 * Uses FILENAME_TIMESTAMP_MTIME_WINDOW_HOURS (default 12h) to reduce false positives.
 *
 * @param int $timestamp Parsed timestamp
 * @param int $fileMtime File modification time
 * @return bool True if timestamp is reasonable
 */
function isTimestampReasonable(int $timestamp, int $fileMtime): bool
{
    if ($timestamp <= 0) {
        return false;
    }

    $hours = defined('FILENAME_TIMESTAMP_MTIME_WINDOW_HOURS')
        ? FILENAME_TIMESTAMP_MTIME_WINDOW_HOURS
        : 12;
    $maxDifference = $hours * 3600;
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

/**
 * Whether a caller-provided capture time should overwrite an embedded
 * DateTimeOriginal.
 *
 * A live pull's fetch time is the authoritative capture time because the camera
 * clock is not trusted. When one is provided it overwrites the embedded value
 * so the stored and embedded times agree; without one the embedded value is
 * preserved.
 *
 * @param int $fallbackTimestamp Caller-provided capture time, or 0 when unknown
 * @return bool True when the caller's time should overwrite the embedded tag
 */
function exifCaptureTimeOverridesEmbedded(int $fallbackTimestamp): bool
{
    return $fallbackTimestamp > 0;
}

/**
 * Ensure image has valid EXIF timestamp (defensive entry point)
 *
 * Priority order:
 * 1. Caller-provided capture time (a pull's fetch time) is authoritative: it
 *    overwrites a frame's embedded DateTimeOriginal so the stored and embedded
 *    times agree.
 * 2. Existing EXIF DateTimeOriginal (if valid), when no caller time was passed -
 *    preserved, with GPS fields added if missing.
 * 3. Filename/folder timestamp pattern, then file mtime - for images without
 *    EXIF (a provided capture time is still preferred over mtime there).
 *
 * @param string $filePath Path to image file
 * @param int|null $fallbackTimestamp Explicit capture timestamp, preferred over file mtime (null = derive from filename/mtime)
 * @param string $timezone IANA timezone for EXIF
 * @param array<string, mixed>|null $context Optional context for error logs (airport_id, cam_index, source_type)
 * @return bool True if image has valid EXIF, false otherwise
 */
function ensureImageHasExif(string $filePath, ?int $fallbackTimestamp = null, string $timezone = 'UTC', ?array $context = null): bool {
    if (!file_exists($filePath)) {
        return false;
    }
    
    // Already has valid EXIF with DateTimeOriginal?
    if (hasExifTimestamp($filePath)) {
        if (exifCaptureTimeOverridesEmbedded((int) $fallbackTimestamp)) {
            // A caller-provided capture time (a pull's fetch time) is
            // authoritative over an embedded camera-clock value, which is not
            // trusted. Stamp it so the stored and embedded times agree.
            return addExifTimestamp($filePath, (int) $fallbackTimestamp, $timezone, false, $context);
        }
        // Ensure GPS UTC fields exist (for old images processed before GPS requirement)
        // Use existing EXIF timestamp, not mtime, to preserve the original capture time
        $timestamp = getExifTimestamp($filePath);
        if ($timestamp > 0) {
            if (!addExifTimestamp($filePath, $timestamp, $timezone, true, $context)) {
                return false;
            }
        }
        return true;
    }

    // Priority 1: capture timestamp from the camera's own filename
    $filenameResult = parseFilenameTimestamp($filePath);
    $timestamp = $filenameResult['found'] ? $filenameResult['timestamp'] : 0;

    // Priority 2: explicit capture timestamp the caller already knows (pull passes fetch time)
    if ($timestamp <= 0 && $fallbackTimestamp > 0) {
        $timestamp = $fallbackTimestamp;
    }

    // Priority 3: file mtime
    if ($timestamp <= 0) {
        $timestamp = @filemtime($filePath) ?: 0;
    }
    
    // No valid timestamp found
    if ($timestamp <= 0) {
        return false;
    }
    
    // Add EXIF with timestamp (local time in DateTimeOriginal, UTC in GPS fields)
    return addExifTimestamp($filePath, $timestamp, $timezone, false, $context);
}


