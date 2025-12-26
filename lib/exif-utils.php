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
                $formatted = str_replace(':', '-', substr($dateTime, 0, 10)) . substr($dateTime, 10);
                $timestamp = @strtotime($formatted);
                
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
        $formatted = str_replace(':', '-', substr($output, 0, 10)) . substr($output, 10);
        $timestamp = @strtotime($formatted);
        
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
    $exifDateTime = date('Y:m:d H:i:s', $timestamp);
    
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
 * If EXIF exists, returns true. If not, adds EXIF from file mtime.
 * Use for server-generated images (RTSP/MJPEG/static fetch).
 * 
 * @param string $filePath Path to image
 * @return bool True if EXIF now exists
 */
function ensureExifTimestamp(string $filePath): bool {
    // Already has valid EXIF? Done.
    if (hasExifTimestamp($filePath)) {
        return true;
    }
    
    // Add EXIF from file mtime
    return addExifTimestamp($filePath);
}

/**
 * Add AviationWX.org attribution metadata to webcam image
 * 
 * Adds IPTC and XMP metadata for attribution, copyright, and location.
 * Uses XMP for cross-format compatibility (JPEG, WebP, AVIF).
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
    // XMP fields work across JPEG, WebP, and AVIF
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
 * Used when generating WebP/AVIF from source JPEG.
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


