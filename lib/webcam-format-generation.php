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
 * Classify jpg, png, or webp from magic bytes.
 *
 * @param string $header At least 12 bytes of the file, or the full body
 * @return string|null jpg, png, or webp
 */
function detectImageFormatFromHeader(string $header): ?string
{
    if (strlen($header) < 12) {
        return null;
    }

    if (substr($header, 0, 3) === "\xFF\xD8\xFF") {
        return 'jpg';
    }

    if (substr($header, 0, 8) === "\x89\x50\x4E\x47\x0D\x0A\x1A\x0A") {
        return 'png';
    }

    if (substr($header, 0, 4) === 'RIFF' && substr($header, 8, 4) === 'WEBP') {
        return 'webp';
    }

    return null;
}

/**
 * Detect image format from file headers.
 *
 * @param string $filePath Path to image file
 * @return string|null jpg, png, webp, or null if the bytes are not one of those types
 */
function detectImageFormat($filePath): ?string
{
    // @: missing or unreadable files are treated as unknown type
    $handle = @fopen($filePath, 'rb');
    if (!$handle) {
        return null;
    }

    // @: short or unreadable files are unknown type
    $header = @fread($handle, 12);
    @fclose($handle);
    if (!$header) {
        return null;
    }

    return detectImageFormatFromHeader($header);
}

/**
 * Formats allowed for native originals and Public API image bodies.
 *
 * @return list<string>
 */
function getSupportedWebcamSourceFormats(): array
{
    return ['jpg', 'png', 'webp'];
}

/**
 * File extensions stored for a format name (jpeg files are jpg).
 *
 * @param string $format Format name from getSupportedWebcamSourceFormats()
 * @return list<string>
 */
function webcamSourceFormatFileExtensions(string $format): array
{
    if (!in_array($format, getSupportedWebcamSourceFormats(), true)) {
        return [];
    }

    if ($format === 'jpg') {
        return ['jpg', 'jpeg'];
    }

    return [$format];
}

/**
 * Extensions scanned for native originals (jpg, jpeg, png, webp).
 *
 * @return list<string>
 */
function webcamSupportedOriginalFileExtensions(): array
{
    $exts = [];
    foreach (getSupportedWebcamSourceFormats() as $format) {
        foreach (webcamSourceFormatFileExtensions($format) as $ext) {
            $exts[] = $ext;
        }
    }

    return $exts;
}

/**
 * Normalize a format query to a supported name, or null if unknown.
 *
 * @param string $format Raw fmt value
 * @return string|null jpg, png, or webp
 */
function normalizeWebcamFormatName(string $format): ?string
{
    $format = strtolower(trim($format));
    if ($format === 'jpeg') {
        $format = 'jpg';
    }

    if (!in_array($format, getSupportedWebcamSourceFormats(), true)) {
        return null;
    }

    return $format;
}

/**
 * Header-detected format if it is on the serve allowlist.
 *
 * @param string $path Existing image path
 * @return string|null jpg, png, or webp
 */
function detectServableWebcamImageFormat(string $path): ?string
{
    $detected = detectImageFormat($path);
    if ($detected === null || !in_array($detected, getSupportedWebcamSourceFormats(), true)) {
        return null;
    }

    return $detected;
}

/**
 * @param string $bytes File contents
 * @return string|null jpg, png, or webp
 */
function detectServableWebcamImageFormatFromBytes(string $bytes): ?string
{
    $detected = detectImageFormatFromHeader($bytes);
    if ($detected === null || !in_array($detected, getSupportedWebcamSourceFormats(), true)) {
        return null;
    }

    return $detected;
}

/**
 * Explicit fmt= must match file magic. Native original (no fmt) uses the detected type.
 */
function webcamExplicitFmtMatchesFile(bool $explicitRequest, string $requestedFormat, string $detectedFormat): bool
{
    return !$explicitRequest || $requestedFormat === $detectedFormat;
}

/**
 * Content-Type for a supported webcam image format.
 *
 * @param string $format jpg, png, or webp
 * @return string|null Null when the format must not be served
 */
function webcamImageContentType(string $format): ?string
{
    return match ($format) {
        'jpg' => 'image/jpeg',
        'png' => 'image/png',
        'webp' => 'image/webp',
        default => null,
    };
}

/**
 * @param string $bytes File contents
 * @return array{format: string, content_type: string}|null
 */
function webcamServableImageContentFromBytes(string $bytes): ?array
{
    $format = detectServableWebcamImageFormatFromBytes($bytes);
    if ($format === null) {
        return null;
    }

    $contentType = webcamImageContentType($format);
    if ($contentType === null) {
        return null;
    }

    return ['format' => $format, 'content_type' => $contentType];
}

/**
 * Type from path using a 12-byte header read (not the body that will be sent).
 *
 * @param string $path Existing file path
 * @return array{format: string, content_type: string}|null
 */
function webcamServableImageContent(string $path): ?array
{
    $format = detectServableWebcamImageFormat($path);
    if ($format === null) {
        return null;
    }

    $contentType = webcamImageContentType($format);
    if ($contentType === null) {
        return null;
    }

    return ['format' => $format, 'content_type' => $contentType];
}

/**
 * Read a file and classify the same buffer that will be sent.
 *
 * @param string $path Existing file path
 * @return array{format: string, content_type: string, bytes: string}|null
 */
function webcamReadServableImage(string $path): ?array
{
    // @: file can vanish between existence check and read
    $bytes = @file_get_contents($path);
    if ($bytes === false || $bytes === '') {
        return null;
    }

    $served = webcamServableImageContentFromBytes($bytes);
    if ($served === null) {
        return null;
    }

    $served['bytes'] = $bytes;

    return $served;
}

/**
 * Open and classify a webcam image without reopening its path.
 *
 * @param string $path Existing file path
 * @return array{handle: resource, format: string, content_type: string, size: int, mtime: int}|null
 */
function openServableWebcamImage(string $path): ?array
{
    $handle = @fopen($path, 'rb');
    if ($handle === false) {
        return null;
    }

    $stat = @fstat($handle);
    if ($stat === false || ($stat['size'] ?? 0) <= 0) {
        fclose($handle);
        return null;
    }

    $prefix = @fread($handle, 12);
    if ($prefix === false || !@rewind($handle)) {
        fclose($handle);
        return null;
    }

    $served = webcamServableImageContentFromBytes($prefix);
    if ($served === null) {
        fclose($handle);
        return null;
    }

    return [
        'handle' => $handle,
        'format' => $served['format'],
        'content_type' => $served['content_type'],
        'size' => (int) $stat['size'],
        'mtime' => (int) ($stat['mtime'] ?? time()),
    ];
}

/**
 * Classify a file from its 12-byte header and stat it for a streamed send.
 *
 * Magic-byte type does not require the body in PHP memory. Callers hash and
 * stream from the file descriptor path instead of buffering the admission-sized
 * original.
 *
 * @return array{format: string, content_type: string, size: int, mtime: int}|null
 */
function webcamServableImageFileMeta(string $path): ?array
{
    $served = webcamServableImageContent($path);
    if ($served === null) {
        return null;
    }

    // @: file can vanish between classify and stat
    $size = @filesize($path);
    $mtime = @filemtime($path);
    if ($size === false || $size <= 0 || $mtime === false) {
        return null;
    }

    return [
        'format' => $served['format'],
        'content_type' => $served['content_type'],
        'size' => (int) $size,
        'mtime' => (int) $mtime,
    ];
}

/**
 * Brace glob of extensions for native originals and timestamp scans.
 *
 * @return string e.g. {jpg,jpeg,png,webp}
 */
function webcamSupportedOriginalGlobBrace(): string
{
    return '{' . implode(',', webcamSupportedOriginalFileExtensions()) . '}';
}

/**
 * Alternation for basename matching of original and variant files.
 *
 * @return string e.g. jpg|jpeg|png|webp
 */
function webcamSupportedOriginalExtensionPattern(): string
{
    $quoted = [];
    foreach (webcamSupportedOriginalFileExtensions() as $ext) {
        $quoted[] = preg_quote($ext, '/');
    }

    return implode('|', $quoted);
}

/**
 * Timestamped image files in a date/hour frames directory.
 *
 * @param string $hourDir Absolute path to a YYYY-MM-DD/HH frames directory
 * @return list<string>
 */
function globWebcamHourDirImageFiles(string $hourDir): array
{
    $files = glob($hourDir . '/*.' . webcamSupportedOriginalGlobBrace(), GLOB_BRACE);
    if ($files === false) {
        return [];
    }

    return $files;
}

/**
 * Capture timestamp for a servable original, or null if it must not be used.
 *
 * Basename `{timestamp}_original.*` wins over mtime so a leftover file cannot
 * outrank a newer frame when mtimes are equal or rewritten.
 *
 * @param string $path Existing original file path
 * @return int|null Unix timestamp rank
 */
function webcamServableOriginalCaptureRank(string $path): ?int
{
    if (!file_exists($path) || detectServableWebcamImageFormat($path) === null) {
        return null;
    }

    if (preg_match('/^(\d+)_original\./', basename($path), $matches) === 1) {
        return (int) $matches[1];
    }

    $mtime = filemtime($path);
    return $mtime !== false ? $mtime : 0;
}

/**
 * Get image dimensions from file.
 *
 * getimagesize is in-process and covers every advertised input, so no
 * subprocess is needed.
 *
 * @param string $filePath Path to image file
 * @return array|null Array with 'width' and 'height' keys, or null on failure
 */
function getImageDimensions(string $filePath): ?array {
    if (!file_exists($filePath) || !is_readable($filePath)) {
        return null;
    }

    $info = @getimagesize($filePath);
    if ($info !== false && isset($info[0]) && isset($info[1])) {
        return [
            'width' => (int)$info[0],
            'height' => (int)$info[1]
        ];
    }

    return null;
}

/**
 * Get the source capture timestamp from a file in UTC.
 * 
 * Priority order:
 * 1. GPS DateStamp + TimeStamp (UTC) - added by our pipeline
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
 * Resolve timestamped files protected by live webcam symlinks.
 *
 * @param string $cacheDir Camera cache directory
 * @return array<string, true> Real target path set
 */
function getWebcamLiveSymlinkTargets(string $cacheDir): array
{
    $targets = [];
    $symlinks = array_merge(
        glob($cacheDir . '/current.*') ?: [],
        glob($cacheDir . '/original.*') ?: []
    );
    foreach ($symlinks as $symlink) {
        if (!is_link($symlink)) {
            continue;
        }
        $target = readlink($symlink);
        if ($target === false) {
            continue;
        }
        $targetPath = $target[0] === '/' ? $target : dirname($symlink) . '/' . $target;
        $realTarget = realpath($targetPath);
        if ($realTarget !== false) {
            $targets[$realTarget] = true;
        }
    }

    return $targets;
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
                    $allFiles = array_merge($allFiles, globWebcamHourDirImageFiles($hourDir));
                }
            }
        }
    }
    if (empty($allFiles)) {
        return 0;
    }
    
    $timestampFiles = [];
    $extPattern = webcamSupportedOriginalExtensionPattern();
    foreach ($allFiles as $file) {
        if (is_link($file)) {
            continue;
        }

        $basename = basename($file);
        if (preg_match('/^(\d+)(?:_[^_]+)?\.(' . $extPattern . ')$/', $basename, $matches)) {
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
    
    $symlinkTargets = getWebcamLiveSymlinkTargets($cacheDir);
    
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
            $realFile = realpath($file);
            if ($realFile !== false && isset($symlinkTargets[$realFile])) {
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
                $realFile = realpath($file);
                if ($realFile !== false && isset($symlinkTargets[$realFile])) {
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
 * @param array|null $inputDimensions Known width/height, to skip the probe when
 *        the caller already decoded the image (as the pipeline does via GD)
 * @return array Results array with 'original', 'variants', and 'metadata' keys
 */
function generateVariantsFromOriginal(string $sourceFile, string $airportId, int $camIndex, int $timestamp, ?array $inputDimensions = null): array {
    require_once __DIR__ . '/webcam-metadata.php';
    
    $timeout = getFormatGenerationTimeout();
    $deadline = time() + $timeout;

    // Detect the container before sizing; never assume JPEG.
    $sourceFormat = detectServableWebcamImageFormat($sourceFile);
    if ($sourceFormat === null) {
        aviationwx_log('error', 'generateVariantsFromOriginal: unsupported source format', [
            'airport' => $airportId,
            'cam' => $camIndex,
            'source_file' => $sourceFile
        ], 'app');
        return ['original' => null, 'variants' => [], 'metadata' => null];
    }
    
    // Get input dimensions
    if ($inputDimensions === null) {
        $inputDimensions = getImageDimensions($sourceFile);
    }
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
                @touch($originalPath, $captureTime);
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
                    @touch($originalPath, $captureTime);
                }
                
                // Explicitly copy EXIF since copy() doesn't preserve it
                require_once __DIR__ . '/exif-utils.php';
                if (hasExifTimestamp($sourceFile)) {
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
    // Read capture time from the original's in-place path. The staging source
    // may already have been renamed away, which would silently zero it.
    $captureTime = getSourceCaptureTime($originalPath);
    $maxWidth = 3840; // Cap width for ultra-wide cameras

    // Enumerate the variant outputs for a single ffmpeg pass. Width follows
    // each height, recalculated when the aspect-ratio width exceeds the cap.
    $outputs = [];
    foreach ($variantHeights as $targetHeight) {
        $targetWidth = (int)round($targetHeight * $aspectRatio);
        if ($targetWidth > $maxWidth) {
            $targetWidth = $maxWidth;
            $targetHeight = (int)round($targetWidth / $aspectRatio);
        }
        $cacheDir = getWebcamCameraDir($airportId, $camIndex);
        foreach ($enabledFormats as $format) {
            // Only jpg and webp are served as variants. A png source keeps its
            // native format in the original only; never emit jpeg bytes under
            // a png name.
            if ($format !== 'jpg' && $format !== 'webp') {
                continue;
            }
            $outputs[$targetHeight . '_' . $format] = [
                'dest' => $cacheDir . '/staging_' . $targetHeight . '_' . $format . '.tmp',
                'format' => $format,
                'width' => $targetWidth,
                'height' => $targetHeight,
            ];
        }
    }

    // One ffmpeg invocation for the whole source: decode once and write every
    // output. The argv spawn runs ffmpeg directly (no shell), so the proc pid
    // is ffmpeg and the stall detector can read its CPU time.
    if (!empty($outputs)) {
        $args = buildVariantArgs($originalPath, $outputs);
        $descriptorSpec = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];
        $process = @proc_open($args, $descriptorSpec, $pipes);

        if (is_resource($process)) {
            @fclose($pipes[0]);
            stream_set_blocking($pipes[1], false);
            stream_set_blocking($pipes[2], false);

            $stderr = '';
            $aborted = false;
            $cpuSeen = null;
            $cpuSince = time();

            while (true) {
                $status = @proc_get_status($process);
                if (!$status['running']) {
                    break;
                }
                $stderr .= (string) @stream_get_contents($pipes[2]);
                if (time() >= $deadline) {
                    $aborted = true;
                    break;
                }
                // Evidence of work: ffmpeg CPU ticks must keep advancing. A
                // live process with frozen ticks is blocked, so cut it short
                // instead of waiting out the deadline. On non-Linux the read
                // returns null and the deadline alone governs.
                $cpu = variantCpuTicks((int) $status['pid']);
                if ($cpu !== null) {
                    if ($cpuSeen === null || $cpu > $cpuSeen) {
                        $cpuSeen = $cpu;
                        $cpuSince = time();
                    } elseif (time() - $cpuSince >= VARIANT_FFMPEG_STALL_GRACE_SECONDS) {
                        $aborted = true;
                        break;
                    }
                }
                usleep(100000);
            }

            $exitCode = $status['exitcode'] ?? -1;
            if ($aborted) {
                // Killed for the deadline or a stall; outputs are suspect, take none.
                @proc_terminate($process, SIGTERM);
                usleep(100000);
                $status = @proc_get_status($process);
                if ($status && $status['running']) {
                    @proc_terminate($process, SIGKILL);
                }
            } else {
                $stderr .= (string) @stream_get_contents($pipes[2]);
            }
            @fclose($pipes[1]);
            @fclose($pipes[2]);
            @proc_close($process);

            foreach ($outputs as $key => $out) {
                if (!$aborted && file_exists($out['dest']) && filesize($out['dest']) > 0) {
                    // A single ffmpeg write can flush some outputs before an
                    // error. Promote the complete files; the EXIF gate below is
                    // what actually qualifies them for serving.
                    $results[$key] = $out['dest'];
                } else {
                    $results[$key] = false;
                    @unlink($out['dest']);
                }
            }

            if ($aborted || $exitCode !== 0) {
                aviationwx_log('warning', 'webcam variant ffmpeg issue', [
                    'airport' => $airportId,
                    'cam' => $camIndex,
                    'aborted' => $aborted,
                    'exit_code' => $exitCode,
                    'outputs_ok' => count(array_filter($results, function ($v) {
                        return $v !== false;
                    })),
                    'stderr_preview' => substr($stderr, 0, 500),
                ], 'app');
            }
        } else {
            foreach ($outputs as $key => $out) {
                $results[$key] = false;
            }
        }
    }
    
    $promoted = [];
    require_once __DIR__ . '/exif-utils.php';
    // Extract the source TIFF once and stamp every variant from it, instead of
    // re-reading the original once per variant.
    $sourceTiff = file_exists($originalPath) ? exifNativeExtractTiffBytes($originalPath) : null;
    foreach ($results as $key => $stagingFile) {
        if ($stagingFile === false) {
            continue;
        }
        
        list($height, $format) = explode('_', $key, 2);
        $finalPath = getWebcamVariantPath($airportId, $camIndex, $timestamp, (int)$height, $format);
        
        // Stamp and verify the staging file before it is renamed into place, so
        // a variant is only promoted once it is known to carry EXIF.
        $exifOk = $sourceTiff !== null
            && copyExifMetadataFromTiff($sourceTiff, $stagingFile)
            && hasExifTimestamp($stagingFile);
        if (!$exifOk) {
            aviationwx_log('warning', 'generateVariantsFromOriginal: EXIF copy failed for variant', [
                'airport' => $airportId,
                'cam' => $camIndex,
                'variant' => $height . '_' . $format,
                'file' => basename($stagingFile),
                'note' => 'variant lacks EXIF and would fail browser verification'
            ], 'app');
            require_once __DIR__ . '/webcam-quarantine.php';
            quarantineImage($stagingFile, $airportId, $camIndex, 'variant_missing_exif', [
                'variant' => $height . '_' . $format,
                'file' => basename($stagingFile)
            ]);
            @unlink($stagingFile);
            continue;
        }

        if ($captureTime > 0) {
            @touch($stagingFile, $captureTime);
        }

        if (@rename($stagingFile, $finalPath)) {
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
 * Build the argv for one ffmpeg pass that writes every variant output.
 *
 * The source is decoded once; each output block maps the video stream through
 * its own scale filter and format settings. An argv array means proc_open runs
 * ffmpeg directly, with no shell in between.
 *
 * @param string $sourceFile Source image path
 * @param array<string, array{dest: string, format: string, width: int, height: int}> $outputs
 * @return list<string>
 */
function buildVariantArgs(string $sourceFile, array $outputs): array
{
    $args = ['ffmpeg', '-hide_banner', '-loglevel', 'error', '-y', '-i', $sourceFile];
    foreach ($outputs as $out) {
        $args = array_merge($args, [
            '-map', '0:v',
            '-vf', sprintf('scale=%d:%d:force_original_aspect_ratio=decrease', $out['width'], $out['height']),
            '-frames:v', '1',
        ]);
        if ($out['format'] === 'webp') {
            $args = array_merge($args, [
                '-f', 'webp',
                '-q:v', (string) getWebcamWebpQuality(),
                '-compression_level', (string) WEBCAM_WEBP_COMPRESSION_LEVEL,
                $out['dest'],
            ]);
        } else {
            $args = array_merge($args, [
                '-f', 'image2',
                '-q:v', (string) getWebcamJpegQuality(),
                $out['dest'],
            ]);
        }
    }
    return $args;
}

/**
 * Read cumulative CPU ticks for a process on Linux.
 *
 * Returns null when /proc is unavailable, so callers fall back to a plain
 * deadline with no stall detection.
 *
 * @param int $pid Process id
 * @return int|null utime plus stime in clock ticks
 */
function variantCpuTicks(int $pid): ?int
{
    $stat = @file_get_contents('/proc/' . $pid . '/stat');
    if ($stat === false) {
        return null;
    }
    $close = strrpos($stat, ')');
    if ($close === false) {
        return null;
    }
    $parts = preg_split('/\s+/', trim(substr($stat, $close + 1)));
    if ($parts === false || count($parts) < 13) {
        return null;
    }
    return (int) $parts[11] + (int) $parts[12];
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

