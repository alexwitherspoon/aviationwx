<?php
/**
 * Webcam Format Generation Library
 * 
 * Generates image variants and formats (WebP, JPEG) from source images.
 * 
 * Workflow:
 * 1. Write source to .tmp staging file
 * 2. Generate variants and formats as .tmp files (parallel)
 * 3. Wait for completion (or timeout)
 * 4. Atomically promote successful .tmp files to final locations
 * 
 * All generation functions sync mtime to match source file's capture time.
 */

require_once __DIR__ . '/constants.php';
require_once __DIR__ . '/logger.php';
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/exif-utils.php';
require_once __DIR__ . '/cache-paths.php';
require_once __DIR__ . '/variant-health.php';
require_once __DIR__ . '/webcam-image-metrics.php';

/**
 * Detect image format from file headers
 * 
 * Reads file headers to determine image format.
 * 
 * @param string $filePath Path to image file
 * @return string|null Format: 'jpg', 'png', 'webp', or null if unknown
 */
function detectImageFormat($filePath) {
    $handle = @fopen($filePath, 'rb');
    if (!$handle) {
        return null;
    }
    
    $header = @fread($handle, 12);
    if (!$header || strlen($header) < 12) {
        @fclose($handle);
        return null;
    }
    
    // JPEG: FF D8 FF
    if (substr($header, 0, 3) === "\xFF\xD8\xFF") {
        @fclose($handle);
        return 'jpg';
    }
    
    // PNG: 89 50 4E 47 0D 0A 1A 0A
    if (substr($header, 0, 8) === "\x89\x50\x4E\x47\x0D\x0A\x1A\x0A") {
        @fclose($handle);
        return 'png';
    }
    
    // WebP: RIFF...WEBP
    if (substr($header, 0, 4) === 'RIFF') {
        @fseek($handle, 8);
        $more = @fread($handle, 4);
        @fclose($handle);
        if ($more && strpos($more, 'WEBP') !== false) {
            return 'webp';
        }
        return null;
    }
    
    @fclose($handle);
    return null;
}

/**
 * Get image dimensions from file
 * 
 * Uses ffprobe to detect image width and height.
 * Falls back to getimagesize() if ffprobe unavailable.
 * 
 * @param string $filePath Path to image file
 * @return array|null Array with 'width' and 'height' keys, or null on failure
 */
function getImageDimensions(string $filePath): ?array {
    if (!file_exists($filePath) || !is_readable($filePath)) {
        return null;
    }
    
    // Try ffprobe first (more reliable for all formats)
    $cmd = sprintf(
        "ffprobe -v error -select_streams v:0 -show_entries stream=width,height -of json %s 2>/dev/null",
        escapeshellarg($filePath)
    );
    
    $output = @shell_exec($cmd);
    if ($output !== null) {
        $data = @json_decode($output, true);
        if (isset($data['streams'][0]['width']) && isset($data['streams'][0]['height'])) {
            return [
                'width' => (int)$data['streams'][0]['width'],
                'height' => (int)$data['streams'][0]['height']
            ];
        }
    }
    
    // Fallback to getimagesize() (works for JPEG, PNG, WebP)
    if (function_exists('getimagesize')) {
        $info = @getimagesize($filePath);
        if ($info !== false && isset($info[0]) && isset($info[1])) {
            return [
                'width' => (int)$info[0],
                'height' => (int)$info[1]
            ];
        }
    }
    
    return null;
}

/**
 * Parse resolution string to width and height
 * 
 * Parses strings like "1920x1080" into width and height values.
 * 
 * @param string $resolution Resolution string (e.g., "1920x1080")
 * @return array|null Array with 'width' and 'height' keys, or null on failure
 */
function parseResolutionString(string $resolution): ?array {
    if (preg_match('/^(\d+)x(\d+)$/i', trim($resolution), $matches)) {
        return [
            'width' => (int)$matches[1],
            'height' => (int)$matches[2]
        ];
    }
    return null;
}

/**
 * Get image resolution configuration
 * 
 * Returns parsed resolution config values with defaults.
 * 
 * @return array Array with 'primary', 'max', 'aspect_ratio', 'variants' keys
 */
function getImageResolutionConfig(): array {
    require_once __DIR__ . '/config.php';
    
    $primaryStr = getImagePrimarySize();
    $maxStr = getImageMaxResolution();
    $aspectRatio = getImageAspectRatio();
    $variants = getImageVariants();
    
    return [
        'primary' => parseResolutionString($primaryStr),
        'max' => parseResolutionString($maxStr),
        'aspect_ratio' => $aspectRatio,
        'variants' => $variants
    ];
}

/**
 * Check if variant should be generated
 * 
 * Returns true if variant size is less than or equal to actual primary size.
 * 
 * @param array $variantSize Array with 'width' and 'height' keys
 * @param array $actualPrimary Array with 'width' and 'height' keys
 * @return bool True if variant should be generated
 */
function shouldGenerateVariant(array $variantSize, array $actualPrimary): bool {
    $variantPixels = $variantSize['width'] * $variantSize['height'];
    $primaryPixels = $actualPrimary['width'] * $actualPrimary['height'];
    return $variantPixels <= $primaryPixels;
}

/**
 * Get the source capture timestamp from a file in UTC.
 * 
 * Priority order:
 * 1. GPS DateStamp + TimeStamp (UTC) - added by our pipeline via exiftool
 * 2. File modification time (fallback)
 * 
 * IMPORTANT: DateTimeOriginal is kept in camera's local time for audit purposes.
 * All application logic MUST use the UTC timestamp from GPS fields.
 * 
 * @param string $filePath Path to source image file
 * @return int Unix timestamp (UTC), or 0 if unavailable
 */
function getSourceCaptureTime($filePath) {
    if (!file_exists($filePath)) {
        return 0;
    }
    
    // Use centralized EXIF timestamp function (reads GPS UTC first, then DateTimeOriginal)
    $timestamp = getExifTimestamp($filePath);
    if ($timestamp > 0) {
        return $timestamp;
    }
    
    // Fallback to filemtime (already in UTC on Unix systems)
    $mtime = @filemtime($filePath);
    return $mtime !== false ? (int)$mtime : 0;
}

/**
 * Convert PNG to JPEG
 * 
 * PNG is always converted to JPEG (we don't serve PNG).
 * Uses GD library for fast conversion.
 * 
 * @param string $pngFile Source PNG file path
 * @param string $jpegFile Target JPEG file path
 * @return bool True on success, false on failure
 */
function convertPngToJpeg($pngFile, $jpegFile) {
    if (!function_exists('imagecreatefrompng') || !function_exists('imagejpeg')) {
        return false;
    }
    
    $img = @imagecreatefrompng($pngFile);
    if (!$img) {
        return false;
    }
    
    // Create temporary file for atomic write
    // Pattern matches getUniqueTmpFile() from fetch-webcam.php
    $tmpFile = $jpegFile . '.tmp.' . getmypid() . '.' . time() . '.' . mt_rand(1000, 9999);
    
    if (!@imagejpeg($img, $tmpFile, 85)) {
        return false;
    }
    
    
    // Atomic rename
    if (@rename($tmpFile, $jpegFile)) {
        return true;
    }
    
    @unlink($tmpFile);
    return false;
}

/**
 * Get format generation timeout in seconds
 * 
 * Uses half of the worker timeout to leave headroom for fetch, validation, etc.
 * 
 * @return int Timeout in seconds (default: 45)
 */
function getFormatGenerationTimeout(): int {
    return (int)(getWorkerTimeout() / 2);
}

/**
 * Get staging file path for a format
 * 
 * Staging files are stored in airport/camera-specific directories.
 * Format: cache/webcams/{airportId}/{camIndex}/staging_{variant}.{format}.tmp
 * 
 * @param string $airportId Airport ID (e.g., 'kspb')
 * @param int $camIndex Camera index (0-based)
 * @param string $format Format extension (jpg, webp)
 * @param string|int $variant Variant: 'original' or height in pixels (e.g., 720, 360)
 * @return string Staging file path with .tmp suffix
 */
function getStagingFilePath(string $airportId, int $camIndex, string $format, string|int $variant = 'original'): string {
    $cacheDir = getWebcamCacheDir($airportId, $camIndex);
    return $cacheDir . '/staging_' . $variant . '.' . $format . '.tmp';
}

/**
 * Get cache directory for a specific airport and camera
 * 
 * Creates directory structure: cache/webcams/{airportId}/{camIndex}/
 * This prevents cross-contamination between airports and cameras.
 * 
 * @param string $airportId Airport ID (e.g., 'kspb')
 * @param int $camIndex Camera index (0-based)
 * @return string Cache directory path
 */
function getWebcamCacheDir(string $airportId, int $camIndex): string {
    $dir = getWebcamCameraDir($airportId, $camIndex);
    
    // Ensure directory exists
    if (!is_dir($dir)) {
        @mkdir($dir, 0755, true);
    }
    
    return $dir;
}

/**
 * Get timestamp-based cache file path
 *
 * Files stored in date/hour subdirs: {airportId}/{camIndex}/{YYYY-MM-DD}/{HH}/{timestamp}_{variant}.{format}
 *
 * @param string $airportId Airport ID (e.g., 'kspb')
 * @param int $camIndex Camera index (0-based)
 * @param int $timestamp Unix timestamp for the image
 * @param string $format Format extension (jpg, webp)
 * @param string|int $variant Variant: 'original' or height in pixels (e.g., 720, 360)
 * @return string Timestamp-based cache file path
 */
function getTimestampCacheFilePath(string $airportId, int $camIndex, int $timestamp, string $format, string|int $variant = 'original'): string {
    $framesDir = getWebcamFramesDir($airportId, $camIndex, $timestamp);
    return $framesDir . '/' . $timestamp . '_' . $variant . '.' . $format;
}

// getCacheSymlinkPath() is now defined in cache-paths.php

/**
 * Get cache file path for a webcam image
 * 
 * Resolves symlinks and handles both original and height-based variant files.
 * For 'original' variant, uses original.{format} symlink.
 * For height variants (e.g., 720, 360), uses current.{format} symlink or constructs path.
 * 
 * @param string $airportId Airport ID (e.g., 'kspb')
 * @param int $camIndex Camera index (0-based)
 * @param string $format Format: 'jpg' or 'webp'
 * @param string|int $variant Variant: 'original' or height in pixels (e.g., 720, 360)
 * @return string Cache file path
 */
function getCacheFile(string $airportId, int $camIndex, string $format, string|int $variant = 'original'): string {
    $cacheDir = getWebcamCacheDir($airportId, $camIndex);
    
    // Normalize variant to string
    $variant = (string)$variant;
    
    // For 'original' variant, use original.{format} symlink
    if ($variant === 'original') {
        $symlinkPath = $cacheDir . '/original.' . $format;
        
        // Resolve symlink to actual timestamp-based file
        // If symlink exists, readlink() returns the target
        if (is_link($symlinkPath)) {
            $target = readlink($symlinkPath);
            if ($target !== false) {
                // If relative path, resolve it
                if ($target[0] !== '/') {
                    $target = dirname($symlinkPath) . '/' . $target;
                }
                return $target;
            }
        }
        
        // Symlink doesn't exist - check if we can find timestamp-based file from original.jpg symlink
        // This handles cases where format generation created the file but symlink wasn't created yet
        $origJpgSymlinkPath = $cacheDir . '/original.jpg';
        if (is_link($origJpgSymlinkPath)) {
            $jpgTarget = readlink($origJpgSymlinkPath);
            if ($jpgTarget !== false) {
                $jpgBasename = basename($jpgTarget);
                if (preg_match('/^(\d+)_original\.(jpg|jpeg)$/', $jpgBasename, $matches)) {
                    $ts = (int)$matches[1];
                    $timestampFile = getWebcamOriginalTimestampedPath($airportId, $camIndex, $ts, $format);
                    if (file_exists($timestampFile)) {
                        return $timestampFile;
                    }
                }
            }
        }
        
        // Fallback: return symlink path (for backward compatibility or if symlink doesn't exist)
        return $symlinkPath;
    }
    
    // For height-based variants, resolve from original JPG file to get timestamp
    $jpgSymlinkPath = $cacheDir . '/current.jpg';
    $jpgFile = null;
    
    // Try symlink first
    if (is_link($jpgSymlinkPath)) {
        $jpgTarget = readlink($jpgSymlinkPath);
        if ($jpgTarget !== false) {
            // If relative path, resolve it
            if ($jpgTarget[0] !== '/') {
                $jpgTarget = dirname($jpgSymlinkPath) . '/' . $jpgTarget;
            }
            $jpgFile = $jpgTarget;
        }
    } elseif (file_exists($jpgSymlinkPath)) {
        // Not a symlink, but file exists - could be timestamp-based or old naming
        $jpgFile = $jpgSymlinkPath;
    }
    
    // Extract timestamp from JPG filename (resolved path may be in date/hour subdir)
    if ($jpgFile !== null) {
        $jpgBasename = basename($jpgFile);
        // Extract timestamp from filename (supports both old and new naming)
        // Old formats: "1766944401_primary.jpg" (legacy) or "1766944401.jpg" (very old)
        // New: "1766944401_original.jpg" or "1766944401_720.jpg"
        if (preg_match('/^(\d+)(?:_(?:primary|original|\d+))?\.(jpg|jpeg)$/', $jpgBasename, $matches)) {
            $ts = (int)$matches[1];
            $variantFile = getWebcamVariantPath($airportId, $camIndex, $ts, (int)$variant, $format);
            if (file_exists($variantFile)) {
                return $variantFile;
            }
            // Legacy: try old naming for non-numeric variants
            if (!is_numeric($variant)) {
                $variantFile = getWebcamFramesDir($airportId, $camIndex, $ts) . '/' . $ts . '_' . $variant . '.' . $format;
                if (file_exists($variantFile)) {
                    return $variantFile;
                }
            }
        }
    }
    
    // Fallback: scan date/hour subdirs for this variant (uses getWebcamImageFiles)
    $extPattern = ($format === 'jpg') ? '{jpg,jpeg}' : $format;
    $pattern = '*_' . $variant . '.' . $extPattern;
    $files = getWebcamImageFiles($airportId, $camIndex, $pattern);
    if (!empty($files)) {
        // Sort by filename (timestamp) descending, return most recent
        rsort($files);
        return $files[0];
    }
    
    // Final fallback: return non-existent path in correct structure (will be handled by caller)
    $ts = time();
    return is_numeric($variant)
        ? getWebcamVariantPath($airportId, $camIndex, $ts, (int)$variant, $format)
        : getWebcamFramesDir($airportId, $camIndex, $ts) . '/' . $ts . '_' . $variant . '.' . $format;
}

/**
 * Get final cache file path for a format (timestamp-based)
 * 
 * @param string $airportId Airport ID (e.g., 'kspb')
 * @param int $camIndex Camera index (0-based)
 * @param string $format Format extension (jpg, webp)
 * @param int $timestamp Unix timestamp for the image
 * @param string|int $variant Variant: 'original' or height in pixels (e.g., 720, 360)
 * @return string Final timestamp-based cache file path
 */
function getFinalFilePath(string $airportId, int $camIndex, string $format, int $timestamp, string|int $variant = 'original'): string {
    return getTimestampCacheFilePath($airportId, $camIndex, $timestamp, $format, $variant);
}

/**
 * Cleanup stale staging files for a camera
 * 
 * Called at start of processing to clean up orphaned .tmp files from crashed workers.
 * Also called on failure to clean up partial staging files.
 * 
 * @param string $airportId Airport ID (e.g., 'kspb')
 * @param int $camIndex Camera index (0-based)
 * @return int Number of files cleaned up
 */
function cleanupStagingFiles(string $airportId, int $camIndex): int {
    $cacheDir = getWebcamCacheDir($airportId, $camIndex);
    $pattern = $cacheDir . '/staging_*.tmp';
    
    $files = glob($pattern);
    if ($files === false || empty($files)) {
        return 0;
    }
    
    $cleaned = 0;
    foreach ($files as $file) {
        if (@unlink($file)) {
            $cleaned++;
        }
    }
    
    if ($cleaned > 0) {
        aviationwx_log('debug', 'webcam staging cleanup', [
            'airport' => $airportId,
            'cam' => $camIndex,
            'files_removed' => $cleaned
        ], 'app');
    }
    
    return $cleaned;
}

/**
 * Cleanup old timestamp-based cache files
 * 
 * Uses time-based retention with a frame count safety limit.
 * Primary cleanup: Delete frames older than retention period.
 * Safety cleanup: If still over frame limit, delete oldest until under limit.
 * 
 * Symlinks are preserved (they'll point to latest).
 * Only removes files that are not targets of active symlinks.
 * 
 * Retention behavior:
 * - retention_hours = 0: Only latest image kept, history player disabled
 * - retention_hours > 0: History player enabled with frames within retention period
 * 
 * @param string $airportId Airport ID (e.g., 'kspb')
 * @param int $camIndex Camera index (0-based)
 * @param int|null $keepCount Legacy override for max frames (null = use time-based config)
 * @return int Number of files cleaned up
 */
function cleanupOldTimestampFiles(string $airportId, int $camIndex, ?int $keepCount = null): int {
    $cacheDir = getWebcamCacheDir($airportId, $camIndex);
    
    // Get retention configuration
    $retentionHours = getWebcamHistoryRetentionHours($airportId);
    
    // Get refresh rate for this camera (for safety limit calculation)
    $config = loadConfig();
    $refreshSeconds = 60; // default
    if ($config !== null) {
        $airport = $config['airports'][$airportId] ?? [];
        $webcams = $airport['webcams'] ?? [];
        if (isset($webcams[$camIndex]['refresh_seconds'])) {
            $refreshSeconds = (int)$webcams[$camIndex]['refresh_seconds'];
        }
    }
    
    // Calculate cutoff timestamp and safety limit
    $cutoffTimestamp = time() - (int)($retentionHours * 3600);
    $maxFrames = $keepCount ?? calculateImplicitMaxFrames($retentionHours, $refreshSeconds);
    
    // Ensure at least 1 frame is kept (the current image)
    $maxFrames = max(1, $maxFrames);
    
    // Get all timestamp-based files from date/hour subdirs
    $allFiles = [];
    $dateDirs = glob($cacheDir . '/????-??-??', GLOB_ONLYDIR);
    if ($dateDirs !== false) {
        foreach ($dateDirs as $dateDir) {
            $hourDirs = glob($dateDir . '/[0-2][0-9]', GLOB_ONLYDIR);
            if ($hourDirs !== false) {
                foreach ($hourDirs as $hourDir) {
                    $files = glob($hourDir . '/*.{jpg,webp}', GLOB_BRACE);
                    if ($files !== false) {
                        $allFiles = array_merge($allFiles, $files);
                    }
                }
            }
        }
    }
    if (empty($allFiles)) {
        return 0;
    }
    
    // Filter out symlinks and only keep timestamp-based files
    $timestampFiles = [];
    foreach ($allFiles as $file) {
        // Skip symlinks (they're not timestamp files themselves)
        if (is_link($file)) {
            continue;
        }
        
        $basename = basename($file);
        // Match timestamp-based filename: "1703700000_original.jpg" or "1703700000_720.jpg"
        if (preg_match('/^(\d+)(?:_[^_]+)?\.(jpg|webp)$/', $basename, $matches)) {
            $timestamp = (int)$matches[1];
            if (!isset($timestampFiles[$timestamp])) {
                $timestampFiles[$timestamp] = [];
            }
            $timestampFiles[$timestamp][] = $file;
        }
    }
    
    if (empty($timestampFiles)) {
        return 0;
    }
    
    // Get symlink targets to protect from deletion (targets are in date/hour subdirs)
    $symlinkTargets = [];
    $symlinks = glob($cacheDir . '/current.*');
    if ($symlinks !== false) {
        foreach ($symlinks as $symlink) {
            if (is_link($symlink)) {
                $target = readlink($symlink);
                if ($target !== false) {
                    $targetPath = $target[0] === '/' ? $target : dirname($symlink) . '/' . $target;
                    $realTarget = realpath($targetPath);
                    if ($realTarget !== false) {
                        $symlinkTargets[basename($realTarget)] = true;
                    }
                }
            }
        }
    }
    
    $cleaned = 0;
    $initialCount = count($timestampFiles);
    
    // Step 1: Time-based cleanup (primary)
    // Delete frames older than retention period
    $timestampsToRemoveByTime = [];
    foreach ($timestampFiles as $timestamp => $files) {
        if ($timestamp < $cutoffTimestamp) {
            $timestampsToRemoveByTime[] = $timestamp;
        }
    }
    
    foreach ($timestampsToRemoveByTime as $timestamp) {
        foreach ($timestampFiles[$timestamp] as $file) {
            $basename = basename($file);
            if (isset($symlinkTargets[$basename])) {
                continue;
            }
            if (@unlink($file)) {
                $cleaned++;
            }
        }
        $manifestFile = getWebcamFramesDir($airportId, $camIndex, $timestamp) . '/' . $timestamp . '_manifest.json';
        @unlink($manifestFile);
        unset($timestampFiles[$timestamp]);
    }
    
    // Step 2: Frame count safety check
    // If still over max frames, delete oldest until under limit
    if (count($timestampFiles) > $maxFrames) {
        // Sort timestamps ascending (oldest first)
        ksort($timestampFiles);
        
        $excess = count($timestampFiles) - $maxFrames;
        $timestampsToRemoveBySafety = array_slice(array_keys($timestampFiles), 0, $excess);
        
        foreach ($timestampsToRemoveBySafety as $timestamp) {
            foreach ($timestampFiles[$timestamp] as $file) {
                $basename = basename($file);
                if (isset($symlinkTargets[$basename])) {
                    continue;
                }
                if (@unlink($file)) {
                    $cleaned++;
                }
            }
            $manifestFile = getWebcamFramesDir($airportId, $camIndex, $timestamp) . '/' . $timestamp . '_manifest.json';
            @unlink($manifestFile);
            unset($timestampFiles[$timestamp]);
        }
        
        // Log warning when safety limit is triggered
        if (!empty($timestampsToRemoveBySafety)) {
            aviationwx_log('warning', 'History frame safety limit triggered', [
                'airport' => $airportId,
                'cam' => $camIndex,
                'frames_before' => $initialCount,
                'frames_after' => count($timestampFiles),
                'max_frames' => $maxFrames,
                'retention_hours' => $retentionHours
            ]);
        }
    }
    
    if ($cleaned > 0) {
        $timestampsRemoved = count($timestampsToRemoveByTime) + count($timestampsToRemoveBySafety ?? []);
        aviationwx_log('debug', 'webcam timestamp cleanup', [
            'airport' => $airportId,
            'cam' => $camIndex,
            'files_removed' => $cleaned,
            'timestamps_removed' => $timestampsRemoved,
            'keep_count' => $keepCount
        ], 'app');
    }
    
    return $cleaned;
}

/**
 * Get variant dimensions from height
 * 
 * Returns the dimensions for a given variant height or 'original'.
 * Width is calculated from the configured aspect ratio (default 16:9).
 * 
 * @param string|int $variant Variant height (e.g., 720, 360) or 'original'
 * @param array|null $actualDimensions Actual source dimensions (for 'original' variant)
 * @return array|null Array with 'width' and 'height' keys, or null if invalid variant
 */
function getVariantDimensions(string|int $variant, ?array $actualDimensions = null): ?array {
    // Handle 'original' variant - use actual source dimensions
    if ($variant === 'original') {
        return $actualDimensions;
    }
    
    // Handle numeric height
    $height = is_numeric($variant) ? (int)$variant : null;
    if ($height === null || $height <= 0) {
        return null;
    }
    
    // Calculate width from aspect ratio (default 16:9)
    $aspectRatio = getImageAspectRatio();
    $parts = explode(':', $aspectRatio);
    $ratioWidth = (int)($parts[0] ?? 16);
    $ratioHeight = (int)($parts[1] ?? 9);
    
    if ($ratioHeight <= 0) {
        $ratioHeight = 9;
    }
    
    $width = (int)round($height * $ratioWidth / $ratioHeight);
    
    return ['width' => $width, 'height' => $height];
}

/**
 * Build ffmpeg resize command with optional letterboxing
 * 
 * Resizes image to target dimensions. If letterboxing is enabled,
 * adds black bars to maintain 16:9 aspect ratio.
 * 
 * @param string $sourceFile Source image file path
 * @param string $destFile Destination file path
 * @param int $targetWidth Target width
 * @param int $targetHeight Target height
 * @param bool $letterbox Whether to letterbox to 16:9 (default: false)
 * @return string Shell command string
 */
/**
 * Build ffmpeg command for variant generation (resize + format)
 * 
 * Combines resize and format conversion into a single command.
 * destFile should already have the correct extension for the target format.
 * 
 * @param string $sourceFile Source image file path
 * @param string $destFile Destination file path (with correct extension)
 * @param string $variant Variant name ('original' or height in pixels like 720, 360)
 * @param string $format Target format (webp, jpg)
 * @param array $variantDimensions Target dimensions for variant
 * @param bool $letterbox Whether to letterbox (for 'original' variant only)
 * @param int $captureTime Source capture time for mtime sync
 * @return string Shell command string
 */
function buildVariantCommand(string $sourceFile, string $destFile, string $variant, string $format, array $variantDimensions, bool $letterbox, int $captureTime): string {
    $targetWidth = $variantDimensions['width'];
    $targetHeight = $variantDimensions['height'];
    
    // Build scale filter
    $scaleFilter = sprintf("scale=%d:%d:force_original_aspect_ratio=decrease", $targetWidth, $targetHeight);
    
    // Add letterboxing if needed
    if ($letterbox) {
        $scaleFilter .= sprintf(",pad=%d:%d:(ow-iw)/2:(oh-ih)/2:color=black", $targetWidth, $targetHeight);
    }
    
    // Build command based on target format
    switch ($format) {
        case 'jpg':
        case 'jpeg':
            $cmd = sprintf(
                "nice -n 10 ffmpeg -hide_banner -loglevel error -y -i %s -vf \"%s\" -frames:v 1 -f image2 -q:v %d %s",
                escapeshellarg($sourceFile),
                $scaleFilter,
                getWebcamJpegQuality(),
                escapeshellarg($destFile)
            );
            break;
            
        case 'webp':
            $cmd = sprintf(
                "nice -n 10 ffmpeg -hide_banner -loglevel error -y -i %s -vf \"%s\" -frames:v 1 -f webp -q:v %d -compression_level %d -preset default %s",
                escapeshellarg($sourceFile),
                $scaleFilter,
                getWebcamWebpQuality(),
                WEBCAM_WEBP_COMPRESSION_LEVEL,
                escapeshellarg($destFile)
            );
            break;
            
        default:
            // Fallback for unknown formats - treat as JPEG
            $cmd = sprintf(
                "nice -n 10 ffmpeg -hide_banner -loglevel error -y -i %s -vf \"%s\" -frames:v 1 -f image2 -q:v %d %s",
                escapeshellarg($sourceFile),
                $scaleFilter,
                getWebcamJpegQuality(),
                escapeshellarg($destFile)
            );
            break;
    }
    
    // Chain mtime sync after generation (only if capture time available)
    if ($captureTime > 0) {
        $dateStr = date('YmdHi.s', $captureTime);
        $cmdSync = sprintf("touch -t %s %s", $dateStr, escapeshellarg($destFile));
        $cmd = $cmd . ' && ' . $cmdSync;
    }
    
    // Chain EXIF copy to preserve metadata in generated format (if exiftool available)
    // EXIF copy failure must fail variant generation (fail closed)
    if (isExiftoolAvailable()) {
        $cmdExif = sprintf(
            "exiftool -overwrite_original -q -P -TagsFromFile %s -all:all %s",
            escapeshellarg($sourceFile),
            escapeshellarg($destFile)
        );
        $cmd = $cmd . ' && ' . $cmdExif;
    }

    return $cmd;
}

/**
 * Build ffmpeg command for format generation (without background execution)
 * 
 * @param string $sourceFile Source image file path
 * @param string $destFile Destination file path
 * @param string $format Target format (webp, jpg)
 * @param int $captureTime Source capture time for mtime sync
 * @return string Shell command string
 */
function buildFormatCommand(string $sourceFile, string $destFile, string $format, int $captureTime): string {
    switch ($format) {
        case 'webp':
            // Explicitly specify format with -f webp (required when using .tmp extension)
            $cmd = sprintf(
                "nice -n 10 ffmpeg -hide_banner -loglevel error -y -i %s -frames:v 1 -f webp -q:v %d -compression_level %d -preset default %s",
                escapeshellarg($sourceFile),
                getWebcamWebpQuality(),
                WEBCAM_WEBP_COMPRESSION_LEVEL,
                escapeshellarg($destFile)
            );
            break;
            
        case 'jpg':
        default:
            $cmd = sprintf(
                "ffmpeg -hide_banner -loglevel error -y -i %s -q:v %d %s",
                escapeshellarg($sourceFile),
                getWebcamJpegQuality(),
                escapeshellarg($destFile)
            );
            break;
    }
    
    // Chain mtime sync after generation (only if capture time available)
    if ($captureTime > 0) {
        $dateStr = date('YmdHi.s', $captureTime); // Format: YYYYMMDDhhmm.ss (touch -t requires dot before seconds)
        $cmdSync = sprintf("touch -t %s %s", $dateStr, escapeshellarg($destFile));
        $cmd = $cmd . ' && ' . $cmdSync;
    }
    
    // Chain EXIF copy to preserve metadata in generated format (if exiftool available)
    // EXIF copy failure must fail format generation (fail closed)
    if (isExiftoolAvailable()) {
        $cmdExif = sprintf(
            "exiftool -overwrite_original -q -P -TagsFromFile %s -all:all %s",
            escapeshellarg($sourceFile),
            escapeshellarg($destFile)
        );
        $cmd = $cmd . ' && ' . $cmdExif;
    }

    return $cmd;
}

/**
 * Generate all enabled formats synchronously in parallel
 * 
 * Spawns format generation processes in parallel, waits for all to complete
 * (or timeout), then returns results for each format.
 * 
 * @param string $sourceFile Source image file path (staging .tmp file)
 * @param string $airportId Airport ID (e.g., 'kspb')
 * @param int $camIndex Camera index (0-based)
 * @param string $sourceFormat Format of source file (jpg, webp)
 * @return array Results: ['format' => bool success, ...]
 */
function generateFormatsSync(string $sourceFile, string $airportId, int $camIndex, string $sourceFormat): array {
    $timeout = getFormatGenerationTimeout();
    $deadline = time() + $timeout;
    $captureTime = getSourceCaptureTime($sourceFile);
    
    $results = [];
    $processes = [];
    
    // Determine which formats to generate
    $formatsToGenerate = [];
    
    // Always need JPG (if source isn't JPG)
    if ($sourceFormat !== 'jpg') {
        $formatsToGenerate[] = 'jpg';
    }
    
    // WebP if enabled and source isn't WebP
    if (isWebpGenerationEnabled() && $sourceFormat !== 'webp') {
        $formatsToGenerate[] = 'webp';
    }
    
    // If no formats to generate, return early
    if (empty($formatsToGenerate)) {
        return $results;
    }
    
    aviationwx_log('info', 'webcam format generation starting', [
        'airport' => $airportId,
        'cam' => $camIndex,
        'source_format' => $sourceFormat,
        'source_exists' => file_exists($sourceFile),
        'source_size' => file_exists($sourceFile) ? filesize($sourceFile) : 0,
        'formats_to_generate' => $formatsToGenerate,
        'webp_enabled' => isWebpGenerationEnabled(),
        'timeout_seconds' => $timeout,
        'capture_time' => $captureTime
    ], 'app');
    
    // Start all format generation processes in parallel
    foreach ($formatsToGenerate as $format) {
        $destFile = getStagingFilePath($airportId, $camIndex, $format, 'original');
        $cmd = buildFormatCommand($sourceFile, $destFile, $format, $captureTime);
        
        // Redirect stderr to stdout for capture
        $cmd = $cmd . ' 2>&1';
        
        $descriptorSpec = [
            0 => ['pipe', 'r'], // stdin
            1 => ['pipe', 'w'], // stdout
            2 => ['pipe', 'w'], // stderr
        ];
        
        $process = @proc_open($cmd, $descriptorSpec, $pipes);
        
        if (is_resource($process)) {
            // Close stdin immediately
            @fclose($pipes[0]);
            
            // Set stdout to non-blocking for polling
            stream_set_blocking($pipes[1], false);
            stream_set_blocking($pipes[2], false);
            
            $processes[$format] = [
                'handle' => $process,
                'pipes' => $pipes,
                'dest' => $destFile,
                'started' => microtime(true)
            ];
            
            aviationwx_log('debug', 'webcam format process started', [
                'airport' => $airportId,
                'cam' => $camIndex,
                'format' => $format
            ], 'app');
        } else {
            $results[$format] = false;
            aviationwx_log('error', 'webcam format process failed to start', [
                'airport' => $airportId,
                'cam' => $camIndex,
                'format' => $format
            ], 'app');
        }
    }
    
    // Wait for all processes to complete (or timeout)
    while (!empty($processes) && time() < $deadline) {
        foreach ($processes as $format => $proc) {
            $status = @proc_get_status($proc['handle']);
            
            if (!$status['running']) {
                // Process finished
                $elapsed = round((microtime(true) - $proc['started']) * 1000, 2);
                $exitCode = $status['exitcode'];
                
                // Read any remaining output
                $stdout = @stream_get_contents($proc['pipes'][1]);
                $stderr = @stream_get_contents($proc['pipes'][2]);
                
                // Close pipes
                @fclose($proc['pipes'][1]);
                @fclose($proc['pipes'][2]);
                @proc_close($proc['handle']);
                
                // Check success: exit code 0 and file exists with size > 0
                $success = ($exitCode === 0 && file_exists($proc['dest']) && filesize($proc['dest']) > 0);
                $results[$format] = $success;
                
                if ($success) {
                    aviationwx_log('info', 'webcam format generation complete', [
                        'airport' => $airportId,
                        'cam' => $camIndex,
                        'format' => $format,
                        'duration_ms' => $elapsed,
                        'size_bytes' => filesize($proc['dest']),
                        'dest_file' => basename($proc['dest'])
                    ], 'app');
                } else {
                    $logContext = [
                        'airport' => $airportId,
                        'cam' => $camIndex,
                        'format' => $format,
                        'exit_code' => $exitCode,
                        'duration_ms' => $elapsed,
                        'file_exists' => file_exists($proc['dest']),
                        'file_size' => file_exists($proc['dest']) ? filesize($proc['dest']) : 0,
                        'dest_file' => $proc['dest'],
                        'stderr_preview' => substr($stderr, 0, 500),
                    ];
                    if ($format === 'webp') {
                        $logContext['exiftool_version'] = getExiftoolVersion() ?? 'unknown';
                        $logContext['note'] = 'WebP EXIF copy may fail with older exiftool';
                    }
                    aviationwx_log('warning', 'webcam format generation failed', $logContext, 'app');
                    
                    // Clean up failed staging file
                    if (file_exists($proc['dest'])) {
                        @unlink($proc['dest']);
                    }
                }
                
                unset($processes[$format]);
            }
        }
        
        // Small sleep to avoid busy-waiting
        if (!empty($processes)) {
            usleep(50000); // 50ms
        }
    }
    
    // Handle any remaining processes (timed out)
    foreach ($processes as $format => $proc) {
        aviationwx_log('warning', 'webcam format generation timeout', [
            'airport' => $airportId,
            'cam' => $camIndex,
            'format' => $format,
            'timeout_seconds' => $timeout
        ], 'app');
        
        // Terminate the process
        @proc_terminate($proc['handle'], SIGTERM);
        usleep(100000); // 100ms grace period
        
        $status = @proc_get_status($proc['handle']);
        if ($status['running']) {
            @proc_terminate($proc['handle'], SIGKILL);
        }
        
        // Close pipes
        @fclose($proc['pipes'][1]);
        @fclose($proc['pipes'][2]);
        @proc_close($proc['handle']);
        
        // Clean up partial file
        if (file_exists($proc['dest'])) {
            @unlink($proc['dest']);
        }
        
        $results[$format] = false;
    }
    
    return $results;
}

/**
 * Create or update symlink to point to timestamp-based file
 * 
 * Atomically updates symlink by creating new symlink then renaming.
 * 
 * @param string $symlinkPath Path to symlink (e.g., kspb_0.jpg)
 * @param string $targetPath Path to target file (e.g., 1703700000.jpg)
 * @return bool True on success, false on failure
 */
function updateCacheSymlink(string $symlinkPath, string $targetPath): bool {
    // Remove regular file if it exists (should be symlink)
    if (file_exists($symlinkPath) && !is_link($symlinkPath) && is_file($symlinkPath)) {
        $basename = basename($symlinkPath);
        $logContext = [];
        
        if (preg_match('/^([^_]+)_(\d+)\.(jpg|webp)$/', $basename, $matches)) {
            $logContext = [
                'airport' => $matches[1],
                'cam' => (int)$matches[2],
                'format' => $matches[3],
                'old_file_size' => filesize($symlinkPath),
                'old_file_mtime' => filemtime($symlinkPath)
            ];
        } else {
            $logContext = ['symlink_path' => $basename];
        }
        
        if (@unlink($symlinkPath)) {
            aviationwx_log('warning', 'webcam regular file removed for symlink', $logContext, 'app');
        } else {
            aviationwx_log('error', 'webcam file deletion failed', array_merge($logContext, [
                'error' => error_get_last()['message'] ?? 'unknown'
            ]), 'app');
            return false;
        }
    }
    
    // Create temporary symlink then rename (atomic)
    $tempSymlink = $symlinkPath . '.tmp';
    
    // Remove temp symlink if it exists
    if (file_exists($tempSymlink)) {
        @unlink($tempSymlink);
    }
    
    // Symlink target: relative path from camera dir to file in date/hour subdir
    $targetBasename = basename($targetPath);
    if (preg_match('/^(\d+)_/', $targetBasename, $m)) {
        $relativeTarget = getWebcamFramesSubdir((int)$m[1]) . '/' . $targetBasename;
    } else {
        $relativeTarget = $targetBasename;
    }
    
    if (!@symlink($relativeTarget, $tempSymlink)) {
        return false;
    }
    
    // Atomically replace old symlink
    if (file_exists($symlinkPath)) {
        if (!@rename($tempSymlink, $symlinkPath)) {
            @unlink($tempSymlink);
            return false;
        }
    } else {
        // Rename temp to final
        if (!@rename($tempSymlink, $symlinkPath)) {
            @unlink($tempSymlink);
            return false;
        }
    }
    
    return true;
}

/**
 * Promote staging files to final cache location (timestamp-based with symlinks)
 * 
 * Atomically renames .tmp files to timestamp-based filenames and creates/updates
 * symlinks for easy lookup. Only promotes formats that generated successfully.
 * 
 * @param string $airportId Airport ID (e.g., 'kspb')
 * @param int $camIndex Camera index (0-based)
 * @param array $formatResults Results from generateFormatsSync: ['format' => bool, ...]
 * @param string $sourceFormat The original source format (always promoted)
 * @param int $timestamp Unix timestamp for the image (0 to auto-detect from source file)
 * @return array Promoted formats: ['jpg', 'webp', ...]
 */
function promoteFormats(string $airportId, int $camIndex, array $formatResults, string $sourceFormat, int $timestamp = 0): array {
    $promoted = [];
    
    // Get timestamp from source file if not provided
    if ($timestamp <= 0) {
        $sourceStagingFile = getStagingFilePath($airportId, $camIndex, $sourceFormat, 'original');
        if (file_exists($sourceStagingFile)) {
            $timestamp = getSourceCaptureTime($sourceStagingFile);
        }
        if ($timestamp <= 0) {
            $timestamp = time();
        }
    }
    
    $sourceStagingFile = getStagingFilePath($airportId, $camIndex, $sourceFormat);
    $sourceTimestampFile = getFinalFilePath($airportId, $camIndex, $sourceFormat, $timestamp);
    $sourceSymlink = getCacheSymlinkPath($airportId, $camIndex, $sourceFormat);
    
    if (file_exists($sourceStagingFile)) {
        ensureCacheDir(getWebcamFramesDir($airportId, $camIndex, $timestamp));
        if (@rename($sourceStagingFile, $sourceTimestampFile)) {
            // Track successful image verification (centralized for all camera types)
            trackWebcamImageVerified($airportId, $camIndex);
            
            // Create/update symlink
            if (updateCacheSymlink($sourceSymlink, $sourceTimestampFile)) {
                $promoted[] = $sourceFormat;
            } else {
                aviationwx_log('error', 'webcam source symlink failed', [
                    'airport' => $airportId,
                    'cam' => $camIndex,
                    'format' => $sourceFormat,
                    'error' => error_get_last()['message'] ?? 'unknown'
                ], 'app');
                // File promoted but symlink failed - still count as promoted
                $promoted[] = $sourceFormat;
            }
        } else {
            aviationwx_log('error', 'webcam source promotion failed', [
                'airport' => $airportId,
                'cam' => $camIndex,
                'format' => $sourceFormat,
                'error' => error_get_last()['message'] ?? 'unknown'
            ], 'app');
        }
    }
    
    // Promote generated formats
    foreach ($formatResults as $format => $success) {
        if (!$success) {
            continue;
        }
        
        $stagingFile = getStagingFilePath($airportId, $camIndex, $format, 'original');
        $timestampFile = getFinalFilePath($airportId, $camIndex, $format, $timestamp);
        $symlink = getCacheSymlinkPath($airportId, $camIndex, $format);
        
        if (file_exists($stagingFile)) {
            // Rename staging file to timestamp-based file
            if (@rename($stagingFile, $timestampFile)) {
                // Copy EXIF metadata from source format to generated format (ensures timestamps are preserved)
                if (file_exists($sourceTimestampFile) && !copyExifMetadata($sourceTimestampFile, $timestampFile)) {
                    aviationwx_log('warning', 'webcam format promotion: EXIF copy failed', [
                        'airport' => $airportId,
                        'cam' => $camIndex,
                        'format' => $format,
                        'file' => basename($timestampFile)
                    ], 'app');
                }
                
                // Create/update symlink
                if (updateCacheSymlink($symlink, $timestampFile)) {
                    $promoted[] = $format;
                } else {
                    aviationwx_log('error', 'webcam format symlink failed', [
                        'airport' => $airportId,
                        'cam' => $camIndex,
                        'format' => $format,
                        'error' => error_get_last()['message'] ?? 'unknown'
                    ], 'app');
                    // File promoted but symlink failed - still count as promoted
                    $promoted[] = $format;
                }
            } else {
                aviationwx_log('error', 'webcam format promotion failed', [
                    'airport' => $airportId,
                    'cam' => $camIndex,
                    'format' => $format,
                    'error' => error_get_last()['message'] ?? 'unknown'
                ], 'app');
            }
        }
    }
    
    // Log promotion result
    $allFormats = array_merge([$sourceFormat], array_keys(array_filter($formatResults)));
    $failedFormats = array_diff($allFormats, $promoted);
    
    if (empty($failedFormats)) {
        aviationwx_log('info', 'webcam formats promoted successfully', [
            'airport' => $airportId,
            'cam' => $camIndex,
            'formats' => $promoted,
            'timestamp' => $timestamp
        ], 'app');
    } elseif (!empty($promoted)) {
        aviationwx_log('warning', 'webcam partial format promotion', [
            'airport' => $airportId,
            'cam' => $camIndex,
            'promoted' => $promoted,
            'failed' => $failedFormats,
            'timestamp' => $timestamp
        ], 'app');
    } else {
        aviationwx_log('error', 'webcam format promotion failed completely', [
            'airport' => $airportId,
            'cam' => $camIndex,
            'formats_attempted' => $allFormats,
            'timestamp' => $timestamp
        ], 'app');
    }
    
    return $promoted;
}

/**
 * Generate variants from original image
 * 
 * Preserves the original image and generates height-based variants.
 * Variants preserve aspect ratio and are capped at 3840px width for ultra-wide cameras.
 * Only generates variants with height ≤ original height.
 * 
 * @param string $sourceFile Path to source image file (staging file)
 * @param string $airportId Airport identifier
 * @param int $camIndex Camera index (0-based)
 * @param int $timestamp Unix timestamp for the image
 * @return array Results array with 'original', 'variants', and 'metadata' keys
 */
function generateVariantsFromOriginal(string $sourceFile, string $airportId, int $camIndex, int $timestamp): array {
    require_once __DIR__ . '/webcam-metadata.php';
    
    $timeout = getFormatGenerationTimeout();
    $deadline = time() + $timeout;
    
    // Get input dimensions
    $inputDimensions = getImageDimensions($sourceFile);
    if ($inputDimensions === null) {
        aviationwx_log('error', 'generateVariantsFromOriginal: unable to detect input dimensions', [
            'airport' => $airportId,
            'cam' => $camIndex,
            'source_file' => $sourceFile
        ], 'app');
        return ['original' => null, 'variants' => [], 'metadata' => null];
    }
    
    $originalWidth = $inputDimensions['width'];
    $originalHeight = $inputDimensions['height'];
    $aspectRatio = $originalWidth / $originalHeight;
    
    // Detect source format
    $sourceFormat = detectImageFormat($sourceFile);
    if ($sourceFormat === null) {
        $sourceFormat = 'jpg'; // Default fallback
    }
    
    require_once __DIR__ . '/webcam-metadata.php';
    $variantHeights = getVariantHeights($airportId, $camIndex);
    
    // Only generate variants ≤ original height
    $variantHeights = array_filter($variantHeights, function($h) use ($originalHeight) {
        return $h <= $originalHeight;
    });
    
    if (empty($variantHeights)) {
        aviationwx_log('warning', 'generateVariantsFromOriginal: no valid variant heights', [
            'airport' => $airportId,
            'cam' => $camIndex,
            'original_height' => $originalHeight,
            'configured_heights' => getVariantHeights($airportId, $camIndex)
        ], 'app');
    }
    
    $enabledFormats = getEnabledWebcamFormats();
    if (!in_array($sourceFormat, $enabledFormats)) {
        $enabledFormats[] = $sourceFormat;
    }
    $originalPath = getWebcamOriginalTimestampedPath($airportId, $camIndex, $timestamp, $sourceFormat);
    $originalPreserved = false;
    
    if (!file_exists($originalPath)) {
        ensureCacheDir(getWebcamFramesDir($airportId, $camIndex, $timestamp));
        // Move source to original location (preserves EXIF automatically)
        // rename() is atomic and preserves all metadata including EXIF
        if (@rename($sourceFile, $originalPath)) {
            // Sync mtime to capture time
            $captureTime = getSourceCaptureTime($originalPath);
            if ($captureTime > 0) {
                $dateStr = date('YmdHi.s', $captureTime);
                @exec("touch -t {$dateStr} " . escapeshellarg($originalPath) . " 2>/dev/null");
            }
            
            // Defensive validation: Verify EXIF was preserved
            require_once __DIR__ . '/exif-utils.php';
            if (!hasExifTimestamp($originalPath)) {
                aviationwx_log('error', 'generateVariantsFromOriginal: EXIF lost after move to original', [
                    'airport' => $airportId,
                    'cam' => $camIndex,
                    'file' => basename($originalPath),
                    'note' => 'This indicates a filesystem or code bug - EXIF should be preserved by rename()'
                ], 'app');
                require_once __DIR__ . '/webcam-quarantine.php';
                quarantineImage($originalPath, $airportId, $camIndex, 'exif_lost_after_move', [
                    'file' => basename($originalPath),
                    'note' => 'EXIF lost after rename() - filesystem bug'
                ]);
                return ['original' => null, 'variants' => [], 'metadata' => null];
            }
            
            $originalPreserved = true;
        } else {
            // Fallback: rename() failed, try copy() with explicit EXIF preservation
            aviationwx_log('warning', 'generateVariantsFromOriginal: rename() failed, falling back to copy()', [
                'airport' => $airportId,
                'cam' => $camIndex,
                'source' => basename($sourceFile),
                'dest' => basename($originalPath),
                'note' => 'This may indicate permission or filesystem issues'
            ], 'app');
            
            if (@copy($sourceFile, $originalPath)) {
                // Sync mtime to capture time
                $captureTime = getSourceCaptureTime($sourceFile);
                if ($captureTime > 0) {
                    $dateStr = date('YmdHi.s', $captureTime);
                    @exec("touch -t {$dateStr} " . escapeshellarg($originalPath) . " 2>/dev/null");
                }
                
                // Explicitly copy EXIF since copy() doesn't preserve it
                require_once __DIR__ . '/exif-utils.php';
                if (isExiftoolAvailable() && hasExifTimestamp($sourceFile)) {
                    if (!copyExifMetadata($sourceFile, $originalPath)) {
                        aviationwx_log('error', 'generateVariantsFromOriginal: EXIF copy failed after fallback', [
                            'airport' => $airportId,
                            'cam' => $camIndex,
                            'file' => basename($originalPath)
                        ], 'app');
                        require_once __DIR__ . '/webcam-quarantine.php';
                        quarantineImage($originalPath, $airportId, $camIndex, 'exif_copy_failed', [
                            'file' => basename($originalPath),
                            'note' => 'copy() fallback succeeded but EXIF copy failed'
                        ]);
                        return ['original' => null, 'variants' => [], 'metadata' => null];
                    }
                }
                
                $originalPreserved = true;
            } else {
                aviationwx_log('error', 'generateVariantsFromOriginal: failed to preserve original (both rename and copy failed)', [
                    'airport' => $airportId,
                    'cam' => $camIndex,
                    'source' => $sourceFile,
                    'dest' => $originalPath
                ], 'app');
            }
        }
    } else {
        $originalPreserved = true; // Already exists
    }
    
    if (!$originalPreserved) {
        return ['original' => null, 'variants' => [], 'metadata' => null];
    }
    
    // Update metadata cache
    updateWebcamMetadata($airportId, $camIndex, $originalPath);
    
    $results = [];
    $processes = [];
    $captureTime = getSourceCaptureTime($sourceFile);
    $maxWidth = 3840; // Cap width for ultra-wide cameras
    
    foreach ($variantHeights as $targetHeight) {
        $targetWidth = (int)round($targetHeight * $aspectRatio);
        
        // Cap width for ultra-wide cameras, recalculate height if needed
        if ($targetWidth > $maxWidth) {
            $targetWidth = $maxWidth;
            $targetHeight = (int)round($targetWidth / $aspectRatio);
        }
        
        foreach ($enabledFormats as $format) {
            $cacheDir = getWebcamCameraDir($airportId, $camIndex);
            $stagingFile = $cacheDir . '/staging_' . $targetHeight . '_' . $format . '.tmp';
            
            $scaleFilter = sprintf("scale=%d:%d:force_original_aspect_ratio=decrease", $targetWidth, $targetHeight);
            
            $cmd = buildVariantCommandByFormat($originalPath, $stagingFile, $format, $targetWidth, $targetHeight, $scaleFilter, $captureTime);
            
            if ($cmd === null) {
                continue;
            }
            
            $descriptorSpec = [
                0 => ['pipe', 'r'],
                1 => ['pipe', 'w'],
                2 => ['pipe', 'w'],
            ];
            
            $process = @proc_open($cmd, $descriptorSpec, $pipes);
            
            $key = $targetHeight . '_' . $format;
            
            if (is_resource($process)) {
                @fclose($pipes[0]);
                stream_set_blocking($pipes[1], false);
                stream_set_blocking($pipes[2], false);
                
                $processes[$key] = [
                    'handle' => $process,
                    'pipes' => $pipes,
                    'dest' => $stagingFile,
                    'width' => $targetWidth,
                    'format' => $format,
                    'started' => microtime(true)
                ];
            } else {
                $results[$key] = false;
            }
        }
    }
    
    // Wait for all processes to complete
    while (!empty($processes) && time() < $deadline) {
        foreach ($processes as $key => $proc) {
            $status = @proc_get_status($proc['handle']);
            
            if (!$status['running']) {
                $elapsed = round((microtime(true) - $proc['started']) * 1000, 2);
                $exitCode = $status['exitcode'];
                
                $stdout = @stream_get_contents($proc['pipes'][1]);
                $stderr = @stream_get_contents($proc['pipes'][2]);
                @fclose($proc['pipes'][1]);
                @fclose($proc['pipes'][2]);
                @proc_close($proc['handle']);
                
                if ($exitCode === 0 && file_exists($proc['dest']) && filesize($proc['dest']) > 0) {
                    $results[$key] = $proc['dest'];
                } else {
                    $results[$key] = false;
                    @unlink($proc['dest']);
                }
                
                unset($processes[$key]);
            }
        }
        
        if (!empty($processes)) {
            usleep(100000); // 100ms
        }
    }
    
    // Timeout: kill remaining processes
    foreach ($processes as $key => $proc) {
        @proc_terminate($proc['handle'], SIGTERM);
        @proc_close($proc['handle']);
        @fclose($proc['pipes'][1]);
        @fclose($proc['pipes'][2]);
        @unlink($proc['dest']);
        $results[$key] = false;
    }
    
    $promoted = [];
    foreach ($results as $key => $stagingFile) {
        if ($stagingFile === false) {
            continue;
        }
        
        list($height, $format) = explode('_', $key, 2);
        $finalPath = getWebcamVariantPath($airportId, $camIndex, $timestamp, (int)$height, $format);
        
        if (@rename($stagingFile, $finalPath)) {
            if ($captureTime > 0) {
                $dateStr = date('YmdHi.s', $captureTime);
                @exec("touch -t {$dateStr} " . escapeshellarg($finalPath) . " 2>/dev/null");
            }
            // Copy EXIF metadata from original to variant (ensures timestamps are preserved)
            if (file_exists($originalPath)) {
                if (!copyExifMetadata($originalPath, $finalPath)) {
                    aviationwx_log('warning', 'generateVariantsFromOriginal: EXIF copy failed for variant', [
                        'airport' => $airportId,
                        'cam' => $camIndex,
                        'variant' => $height . '_' . $format,
                        'file' => basename($finalPath),
                        'note' => 'exiftool failed - variant will fail browser verification'
                    ], 'app');
                    require_once __DIR__ . '/webcam-quarantine.php';
                    quarantineImage($finalPath, $airportId, $camIndex, 'variant_missing_exif', [
                        'variant' => $height . '_' . $format,
                        'file' => basename($finalPath)
                    ]);
                    continue;
                }

                // Defensive validation: Verify EXIF was copied successfully
                // This is critical for browser-side verification
                if (!hasExifTimestamp($finalPath)) {
                    aviationwx_log('warning', 'generateVariantsFromOriginal: EXIF copy failed for variant', [
                        'airport' => $airportId,
                        'cam' => $camIndex,
                        'variant' => $height . '_' . $format,
                        'file' => basename($finalPath),
                        'note' => 'Variant generated but lacks EXIF - will fail browser verification'
                    ], 'app');
                    // Don't promote this variant - quarantine it
                    require_once __DIR__ . '/webcam-quarantine.php';
                    quarantineImage($finalPath, $airportId, $camIndex, 'variant_missing_exif', [
                        'variant' => $height . '_' . $format,
                        'file' => basename($finalPath)
                    ]);
                    continue;
                }
            }
            $promoted[$height][$format] = $finalPath;
        } else {
            @unlink($stagingFile);
        }
    }
    
    updateWebcamSymlinks($airportId, $camIndex, $timestamp, $promoted, $originalPath, $sourceFormat);
    
    // Track variant health metrics
    $totalAttempted = count($results);
    $successCount = count(array_filter($results, function($v) { return $v !== false; }));
    $promotedCount = 0;
    foreach ($promoted as $heightFormats) {
        $promotedCount += count($heightFormats);
    }
    
    variant_health_track_generation($airportId, $camIndex, $successCount, $totalAttempted);
    variant_health_track_promotion($airportId, $camIndex, $promotedCount > 0, $promotedCount, $successCount);
    
    // Track variant generation in main metrics system for homepage hero metric
    if ($successCount > 0) {
        require_once __DIR__ . '/metrics.php';
        metrics_increment('global_variants_generated', $successCount);
    }
    
    return [
        'original' => $originalPath,
        'variants' => $promoted,
        'metadata' => [
            'width' => $originalWidth,
            'height' => $originalHeight,
            'aspect_ratio' => $aspectRatio,
            'format' => $sourceFormat,
            'timestamp' => $timestamp
        ]
    ];
}

/**
 * Build variant generation command by format
 * 
 * @param string $sourceFile Source image file
 * @param string $destFile Destination file
 * @param string $format Target format (jpg, webp)
 * @param int $targetWidth Target width
 * @param int $targetHeight Target height
 * @param string $scaleFilter FFmpeg scale filter
 * @param int $captureTime Capture timestamp
 * @return string|null Command string or null on error
 */
function buildVariantCommandByFormat(string $sourceFile, string $destFile, string $format, int $targetWidth, int $targetHeight, string $scaleFilter, int $captureTime): ?string {
    switch ($format) {
        case 'jpg':
        case 'jpeg':
            $cmd = sprintf(
                "nice -n 10 ffmpeg -hide_banner -loglevel error -y -i %s -vf \"%s\" -frames:v 1 -f image2 -q:v %d %s",
                escapeshellarg($sourceFile),
                $scaleFilter,
                getWebcamJpegQuality(),
                escapeshellarg($destFile)
            );
            break;
            
        case 'webp':
            $cmd = sprintf(
                "nice -n 10 ffmpeg -hide_banner -loglevel error -y -i %s -vf \"%s\" -frames:v 1 -f webp -q:v %d -compression_level %d -preset default %s",
                escapeshellarg($sourceFile),
                $scaleFilter,
                getWebcamWebpQuality(),
                WEBCAM_WEBP_COMPRESSION_LEVEL,
                escapeshellarg($destFile)
            );
            break;
            
        default:
            return null;
    }
    
    // Chain mtime sync if capture time available
    if ($captureTime > 0) {
        $dateStr = date('YmdHi.s', $captureTime);
        $cmd .= sprintf(" && touch -t %s %s", $dateStr, escapeshellarg($destFile));
    }
    
    return $cmd;
}

/**
 * Update webcam symlinks
 * 
 * Updates current.{format} symlinks to point to highest resolution newest image.
 * Updates original.{format} symlink to point to latest original.
 * 
 * @param string $airportId Airport identifier
 * @param int $camIndex Camera index
 * @param int $timestamp Image timestamp
 * @param array $promoted Promoted variants array [width][format] => path
 * @param string $originalPath Path to original image
 * @param string $sourceFormat Source format
 * @return void
 */
function updateWebcamSymlinks(string $airportId, int $camIndex, int $timestamp, array $promoted, string $originalPath, string $sourceFormat): void {
    $framesSubdir = getWebcamFramesSubdir($timestamp);
    
    $originalSymlink = getWebcamOriginalSymlinkPath($airportId, $camIndex, $sourceFormat);
    if (file_exists($originalSymlink) || is_link($originalSymlink)) {
        @unlink($originalSymlink);
    }
    $originalTarget = $framesSubdir . '/' . basename($originalPath);
    @symlink($originalTarget, $originalSymlink);
    
    foreach ($promoted as $height => $formats) {
        foreach ($formats as $format => $path) {
            $currentSymlink = getWebcamCurrentPath($airportId, $camIndex, $format);
            
            $shouldUpdate = false;
            if (is_link($currentSymlink)) {
                $currentTarget = readlink($currentSymlink);
                if ($currentTarget !== false) {
                    // Match date/hour path: {date}/{hour}/{timestamp}_{height}.{format}
                    if (preg_match('#\d{4}-\d{2}-\d{2}/\d{2}/(\d+)_(\d+)\.' . preg_quote($format, '/') . '$#', $currentTarget, $matches)) {
                        $currentTimestamp = (int)$matches[1];
                        $currentHeight = (int)$matches[2];
                        if ($timestamp > $currentTimestamp || ($timestamp === $currentTimestamp && $height > $currentHeight)) {
                            $shouldUpdate = true;
                        }
                    } else {
                        $shouldUpdate = true;
                    }
                } else {
                    $shouldUpdate = true;
                }
            } else {
                $shouldUpdate = true;
            }
            
            if ($shouldUpdate) {
                if (file_exists($currentSymlink) || is_link($currentSymlink)) {
                    @unlink($currentSymlink);
                }
                $target = $framesSubdir . '/' . basename($path);
                @symlink($target, $currentSymlink);
            }
        }
    }
}

