<?php
/**
 * Webcam Format Generation Library
 * 
 * Shared functions for generating image formats (WebP, AVIF, JPEG) from source images.
 * Used by both push webcam processing and fetched webcam processing.
 * 
 * Generation modes:
 * - Async (legacy): Run in background with exec() &
 * - Sync parallel: Generate all formats in parallel, wait for completion, then promote atomically
 * 
 * All generation functions:
 * - Automatically sync mtime to match source file's capture time
 * - Support any source format (JPEG, PNG, WebP, AVIF)
 * 
 * Staging workflow (new):
 * 1. Write source to .tmp staging file
 * 2. Generate all enabled formats as .tmp files (parallel)
 * 3. Wait for all to complete (or timeout)
 * 4. Atomically promote all successful .tmp files to final
 * 5. Save all promoted formats to history
 */

require_once __DIR__ . '/constants.php';
require_once __DIR__ . '/logger.php';
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/exif-utils.php';
require_once __DIR__ . '/cache-paths.php';

/**
 * Detect image format from file headers
 * 
 * Reads file headers to determine image format.
 * 
 * @param string $filePath Path to image file
 * @return string|null Format: 'jpg', 'png', 'webp', 'avif', or null if unknown
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
    
    // AVIF: ftyp box with avif/avis
    // AVIF structure: [4 bytes size][4 bytes 'ftyp'][4 bytes major brand][...]
    // Major brand at bytes 8-11 should be 'avif' or 'avis'
    if (substr($header, 4, 4) === 'ftyp') {
        // Check major brand at bytes 8-11 (already read in header)
        $majorBrand = substr($header, 8, 4);
        @fclose($handle);
        if ($majorBrand === 'avif' || $majorBrand === 'avis') {
            return 'avif';
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
 * Get image capture time from source file
 * 
 * Extracts EXIF DateTimeOriginal if available, otherwise uses filemtime.
 * Used for syncing generated format files' mtime.
 * 
 * @param string $filePath Path to source image file
 * @return int Unix timestamp, or 0 if unavailable
 */
function getSourceCaptureTime($filePath) {
    // Try EXIF first (for JPEG)
    if (function_exists('exif_read_data') && file_exists($filePath)) {
        $exif = @exif_read_data($filePath, 'EXIF', true);
        if ($exif !== false) {
            if (isset($exif['EXIF']['DateTimeOriginal'])) {
                $dateTime = $exif['EXIF']['DateTimeOriginal'];
                $timestamp = @strtotime(str_replace(':', '-', substr($dateTime, 0, 10)) . ' ' . substr($dateTime, 11));
                if ($timestamp !== false && $timestamp > 0) {
                    return (int)$timestamp;
                }
            } elseif (isset($exif['DateTimeOriginal'])) {
                $dateTime = $exif['DateTimeOriginal'];
                $timestamp = @strtotime(str_replace(':', '-', substr($dateTime, 0, 10)) . ' ' . substr($dateTime, 11));
                if ($timestamp !== false && $timestamp > 0) {
                    return (int)$timestamp;
                }
            }
        }
    }
    
    // Fallback to filemtime
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
        imagedestroy($img);
        return false;
    }
    
    imagedestroy($img);
    
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
 * @param string $format Format extension (jpg, webp, avif)
 * @param string $variant Variant name (thumb, small, medium, large, primary, full)
 * @return string Staging file path with .tmp suffix
 */
function getStagingFilePath(string $airportId, int $camIndex, string $format, string $variant = 'primary'): string {
    $cacheDir = getWebcamCacheDir($airportId, $camIndex);
    // Match naming convention used in generateVariantsSync: staging_primary_variant.format.tmp
    return $cacheDir . '/staging_primary_' . $variant . '.' . $format . '.tmp';
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
 * Files are stored in airport/camera-specific directories to prevent collisions.
 * Format: cache/webcams/{airportId}/{camIndex}/{timestamp}_{variant}.{format}
 * 
 * @param string $airportId Airport ID (e.g., 'kspb')
 * @param int $camIndex Camera index (0-based)
 * @param int $timestamp Unix timestamp for the image
 * @param string $format Format extension (jpg, webp, avif)
 * @param string $variant Variant name (thumb, small, medium, large, primary, full)
 * @return string Timestamp-based cache file path
 */
function getTimestampCacheFilePath(string $airportId, int $camIndex, int $timestamp, string $format, string $variant = 'primary'): string {
    $cacheDir = getWebcamCacheDir($airportId, $camIndex);
    return $cacheDir . '/' . $timestamp . '_' . $variant . '.' . $format;
}

/**
 * Get symlink path for current cache file
 * 
 * Symlink points to latest timestamp-based file for easy lookup.
 * Stored in airport/camera-specific directory.
 * Format: cache/webcams/{airportId}/{camIndex}/current.{format}
 * 
 * @param string $airportId Airport ID (e.g., 'kspb')
 * @param int $camIndex Camera index (0-based)
 * @param string $format Format extension (jpg, webp, avif)
 * @return string Symlink path
 */
function getCacheSymlinkPath(string $airportId, int $camIndex, string $format): string {
    $cacheDir = getWebcamCacheDir($airportId, $camIndex);
    return $cacheDir . '/current.' . $format;
}

/**
 * Get cache file path for a webcam image
 * 
 * Resolves symlinks and handles both primary and variant files.
 * For primary variant, resolves symlink to actual timestamp-based file.
 * For non-primary variants, extracts timestamp from primary JPG and constructs variant path.
 * 
 * @param string $airportId Airport ID (e.g., 'kspb')
 * @param int $camIndex Camera index (0-based)
 * @param string $format Format: 'jpg', 'webp', or 'avif'
 * @param string $variant Variant name (thumb, small, medium, large, primary, full)
 * @return string Cache file path
 */
function getCacheFile(string $airportId, int $camIndex, string $format, string $variant = 'primary'): string {
    $cacheDir = getWebcamCacheDir($airportId, $camIndex);
    
    // For primary variant, use symlink
    if ($variant === 'primary') {
        $symlinkPath = $cacheDir . '/current.' . $format;
        
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
        
        // Symlink doesn't exist - check if we can find timestamp-based file from JPG symlink
        // This handles cases where format generation created the file but symlink wasn't created yet
        $jpgSymlinkPath = $cacheDir . '/current.jpg';
        if (is_link($jpgSymlinkPath)) {
            $jpgTarget = readlink($jpgSymlinkPath);
            if ($jpgTarget !== false) {
                // Extract timestamp from JPG filename (e.g., "1766944401_primary.jpg" or "1766944401.jpg")
                $jpgBasename = basename($jpgTarget);
                if (preg_match('/^(\d+)(?:_primary)?\.jpg$/', $jpgBasename, $matches)) {
                    $timestamp = $matches[1];
                    $timestampFile = $cacheDir . '/' . $timestamp . '_primary.' . $format;
                    if (file_exists($timestampFile)) {
                        return $timestampFile;
                    }
                    // Fallback to old naming (no variant)
                    $timestampFile = $cacheDir . '/' . $timestamp . '.' . $format;
                    if (file_exists($timestampFile)) {
                        return $timestampFile;
                    }
                }
            }
        }
        
        // Fallback: return symlink path (for backward compatibility or if symlink doesn't exist)
        return $symlinkPath;
    }
    
    // For non-primary variants, resolve from primary JPG file to get timestamp
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
    
    // Extract timestamp from JPG filename
    if ($jpgFile !== null) {
        $jpgBasename = basename($jpgFile);
        // Extract timestamp from filename (supports both old and new naming)
        if (preg_match('/^(\d+)(?:_primary)?\.jpg$/', $jpgBasename, $matches)) {
            $timestamp = $matches[1];
            $variantFile = $cacheDir . '/' . $timestamp . '_' . $variant . '.' . $format;
            if (file_exists($variantFile)) {
                return $variantFile;
            }
        }
    }
    
    // Fallback: try to find any timestamp file with this variant in this directory
    $pattern = $cacheDir . '/*_' . $variant . '.' . $format;
    $files = glob($pattern);
    if (!empty($files)) {
        // Sort by filename (timestamp) descending, return most recent
        rsort($files);
        return $files[0];
    }
    
    // Final fallback: return non-existent path (will be handled by caller)
    return $cacheDir . '/' . time() . '_' . $variant . '.' . $format;
}

/**
 * Get final cache file path for a format (timestamp-based)
 * 
 * @param string $airportId Airport ID (e.g., 'kspb')
 * @param int $camIndex Camera index (0-based)
 * @param string $format Format extension (jpg, webp, avif)
 * @param int $timestamp Unix timestamp for the image
 * @param string $variant Variant name (thumb, small, medium, large, primary, full)
 * @return string Final timestamp-based cache file path
 */
function getFinalFilePath(string $airportId, int $camIndex, string $format, int $timestamp, string $variant = 'primary'): string {
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
 * Keeps only the most recent N timestamp files to prevent disk space issues.
 * Old timestamp files are removed, but symlinks are preserved (they'll point to latest).
 * Only removes files that are not targets of active symlinks.
 * 
 * @param string $airportId Airport ID (e.g., 'kspb') - used for logging only
 * @param int $camIndex Camera index (0-based) - used for logging only
 * @param int $keepCount Number of recent timestamp files to keep (default: 5)
 * @return int Number of files cleaned up
 */
function cleanupOldTimestampFiles(string $airportId, int $camIndex, int $keepCount = 5): int {
    $cacheDir = getWebcamCacheDir($airportId, $camIndex);
    
    // Get all timestamp-based files (format: {timestamp}_{variant}.{format})
    // Exclude symlinks and staging files
    $allFiles = glob($cacheDir . '/*.{jpg,webp,avif}', GLOB_BRACE);
    if ($allFiles === false || empty($allFiles)) {
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
        // Match timestamp-based filename: "1703700000_primary.jpg" or "1703700000.jpg"
        if (preg_match('/^(\d+)(?:_[^_]+)?\.(jpg|webp|avif)$/', $basename, $matches)) {
            $timestamp = (int)$matches[1];
            if (!isset($timestampFiles[$timestamp])) {
                $timestampFiles[$timestamp] = [];
            }
            $timestampFiles[$timestamp][] = $file;
        }
    }
    
    // If we have fewer timestamps than keepCount, nothing to clean
    if (count($timestampFiles) <= $keepCount) {
        return 0;
    }
    
    // Get all symlink targets to protect them from deletion
    $symlinkTargets = [];
    $symlinks = glob($cacheDir . '/current.*');
    if ($symlinks !== false) {
        foreach ($symlinks as $symlink) {
            if (is_link($symlink)) {
                $target = readlink($symlink);
                if ($target !== false) {
                    // Resolve relative path
                    $targetPath = $target[0] === '/' ? $target : dirname($symlink) . '/' . $target;
                    $realTarget = realpath($targetPath);
                    if ($realTarget !== false) {
                        $symlinkTargets[basename($realTarget)] = true;
                    }
                }
            }
        }
    }
    
    // Sort timestamps descending (newest first)
    krsort($timestampFiles);
    
    // Get timestamps to keep (most recent N)
    $timestampsToKeep = array_slice(array_keys($timestampFiles), 0, $keepCount);
    $timestampsToRemove = array_diff(array_keys($timestampFiles), $timestampsToKeep);
    
    $cleaned = 0;
    foreach ($timestampsToRemove as $timestamp) {
        foreach ($timestampFiles[$timestamp] as $file) {
            // Don't remove if it's the target of a symlink
            $basename = basename($file);
            if (isset($symlinkTargets[$basename])) {
                continue;
            }
            
            if (@unlink($file)) {
                $cleaned++;
            }
        }
    }
    
    if ($cleaned > 0) {
        aviationwx_log('debug', 'webcam timestamp cleanup', [
            'airport' => $airportId,
            'cam' => $camIndex,
            'files_removed' => $cleaned,
            'timestamps_removed' => count($timestampsToRemove),
            'keep_count' => $keepCount
        ], 'app');
    }
    
    return $cleaned;
}

/**
 * Get variant dimensions
 * 
 * Returns the dimensions for a given variant name.
 * 
 * @param string $variant Variant name (thumb, small, medium, large, primary, full)
 * @param array|null $actualPrimary Actual primary dimensions (for primary/full variants)
 * @return array|null Array with 'width' and 'height' keys, or null if invalid variant
 */
function getVariantDimensions(string $variant, ?array $actualPrimary = null): ?array {
    $fixedVariants = [
        'thumb' => ['width' => 160, 'height' => 90],
        'small' => ['width' => 320, 'height' => 180],
        'medium' => ['width' => 640, 'height' => 360],
        'large' => ['width' => 1280, 'height' => 720],
    ];
    
    if (isset($fixedVariants[$variant])) {
        return $fixedVariants[$variant];
    }
    
    if ($variant === 'primary' || $variant === 'full') {
        return $actualPrimary;
    }
    
    return null;
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
function buildResizeCommand(string $sourceFile, string $destFile, int $targetWidth, int $targetHeight, bool $letterbox = false): string {
    if ($letterbox) {
        // Letterbox to 16:9 using pad filter
        // Calculate padding to center image
        $cmd = sprintf(
            "ffmpeg -hide_banner -loglevel error -y -i %s -vf \"scale=%d:%d:force_original_aspect_ratio=decrease,pad=%d:%d:(ow-iw)/2:(oh-ih)/2:color=black\" -frames:v 1 %s",
            escapeshellarg($sourceFile),
            $targetWidth,
            $targetHeight,
            $targetWidth,
            $targetHeight,
            escapeshellarg($destFile)
        );
    } else {
        // Simple resize
        $cmd = sprintf(
            "ffmpeg -hide_banner -loglevel error -y -i %s -vf \"scale=%d:%d:force_original_aspect_ratio=decrease\" -frames:v 1 %s",
            escapeshellarg($sourceFile),
            $targetWidth,
            $targetHeight,
            escapeshellarg($destFile)
        );
    }
    
    return $cmd;
}

/**
 * Build ffmpeg command for variant generation (resize + format)
 * 
 * Combines resize and format conversion into a single command.
 * destFile should already have the correct extension for the target format.
 * 
 * @param string $sourceFile Source image file path
 * @param string $destFile Destination file path (with correct extension)
 * @param string $variant Variant name (thumb, small, medium, large, primary, full)
 * @param string $format Target format (webp, avif, jpg)
 * @param array $variantDimensions Target dimensions for variant
 * @param bool $letterbox Whether to letterbox (for primary/full only)
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
            
        case 'avif':
            $cmd = sprintf(
                "nice -n 10 ffmpeg -hide_banner -loglevel error -y -i %s -vf \"%s\" -frames:v 1 -f avif -c:v libaom-av1 -crf %d -b:v 0 -cpu-used %d %s",
                escapeshellarg($sourceFile),
                $scaleFilter,
                getWebcamAvifCrf(),
                WEBCAM_AVIF_CPU_USED,
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
    if (isExiftoolAvailable()) {
        $cmdExif = sprintf(
            "exiftool -overwrite_original -q -P -TagsFromFile %s -all:all %s || true",
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
 * @param string $format Target format (webp, avif, jpg)
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
            
        case 'avif':
            // Explicitly specify format with -f avif (required when using .tmp extension)
            $cmd = sprintf(
                "nice -n 10 ffmpeg -hide_banner -loglevel error -y -i %s -frames:v 1 -f avif -c:v libaom-av1 -crf %d -b:v 0 -cpu-used %d %s",
                escapeshellarg($sourceFile),
                getWebcamAvifCrf(),
                WEBCAM_AVIF_CPU_USED,
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
    if (isExiftoolAvailable()) {
        $cmdExif = sprintf(
            "exiftool -overwrite_original -q -P -TagsFromFile %s -all:all %s || true",
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
 * @param string $sourceFormat Format of source file (jpg, webp, avif)
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
    
    // AVIF if enabled and source isn't AVIF
    if (isAvifGenerationEnabled() && $sourceFormat !== 'avif') {
        $formatsToGenerate[] = 'avif';
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
        'avif_enabled' => isAvifGenerationEnabled(),
        'timeout_seconds' => $timeout,
        'capture_time' => $captureTime
    ], 'app');
    
    // Start all format generation processes in parallel
    foreach ($formatsToGenerate as $format) {
        $destFile = getStagingFilePath($airportId, $camIndex, $format, 'primary');
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
                    aviationwx_log('warning', 'webcam format generation failed', [
                        'airport' => $airportId,
                        'cam' => $camIndex,
                        'format' => $format,
                        'exit_code' => $exitCode,
                        'duration_ms' => $elapsed,
                        'file_exists' => file_exists($proc['dest']),
                        'file_size' => file_exists($proc['dest']) ? filesize($proc['dest']) : 0,
                        'dest_file' => $proc['dest'],
                        'stderr_preview' => substr($stderr, 0, 500)
                    ], 'app');
                    
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
 * Generate all variants and formats synchronously in parallel
 * 
 * Main function for variant generation. Handles:
 * - Input dimension detection
 * - Resolution capping (downscale if > max)
 * - Variant size calculation
 * - Letterboxing for primary/full
 * - Parallel generation of all variants × formats
 * 
 * @param string $sourceFile Source image file path (staging .tmp file)
 * @param string $airportId Airport ID (e.g., 'kspb')
 * @param int $camIndex Camera index (0-based)
 * @param string $sourceFormat Format of source file (jpg, webp, avif, png)
 * @param array|null $inputDimensions Optional input dimensions (detected if null)
 * @return array Results: ['variant_format' => bool success, ...] and metadata
 */
function generateVariantsSync(string $sourceFile, string $airportId, int $camIndex, string $sourceFormat, ?array $inputDimensions = null): array {
    $timeout = getFormatGenerationTimeout();
    $deadline = time() + $timeout;
    $captureTime = getSourceCaptureTime($sourceFile);
    
    // Get input dimensions if not provided
    if ($inputDimensions === null) {
        $inputDimensions = getImageDimensions($sourceFile);
        if ($inputDimensions === null) {
            aviationwx_log('error', 'webcam variant generation: unable to detect input dimensions', [
                'airport' => $airportId,
                'cam' => $camIndex,
                'source_file' => $sourceFile
            ], 'app');
            return ['results' => [], 'actual_primary' => null, 'actual_full' => null, 'delete_original' => false];
        }
    }
    
    // Get resolution config
    $config = getImageResolutionConfig();
    if ($config['primary'] === null || $config['max'] === null) {
        aviationwx_log('error', 'webcam variant generation: invalid resolution config', [
            'airport' => $airportId,
            'cam' => $camIndex,
            'config' => $config
        ], 'app');
        return ['results' => [], 'actual_primary' => null, 'actual_full' => null, 'delete_original' => false];
    }
    
    $primaryConfig = $config['primary'];
    $maxConfig = $config['max'];
    $variantsToGenerate = $config['variants'];
    
    // Calculate actual sizes (min of input, config)
    $inputPixels = $inputDimensions['width'] * $inputDimensions['height'];
    $maxPixels = $maxConfig['width'] * $maxConfig['height'];
    $primaryPixels = $primaryConfig['width'] * $primaryConfig['height'];
    
    // Determine actual_full and actual_primary
    $deleteOriginal = false;
    if ($inputPixels > $maxPixels) {
        // Input exceeds max - downscale to max
        $actualFull = $maxConfig;
        $deleteOriginal = true;
    } else {
        // Input is within max - use input as full
        $actualFull = $inputDimensions;
    }
    
    // Primary is min of actual_full and configured primary
    if ($actualFull['width'] * $actualFull['height'] <= $primaryPixels) {
        $actualPrimary = $actualFull;
    } else {
        $actualPrimary = $primaryConfig;
    }
    
    // Determine which variants to generate
    $variantsToProcess = [];
    
    // Always generate primary and full (if different)
    $variantsToProcess[] = 'primary';
    if ($actualPrimary['width'] !== $actualFull['width'] || $actualPrimary['height'] !== $actualFull['height']) {
        $variantsToProcess[] = 'full';
    }
    
    // Add configured variants if they're smaller than actual_primary
    foreach ($variantsToGenerate as $variant) {
        $variantDims = getVariantDimensions($variant);
        if ($variantDims !== null && shouldGenerateVariant($variantDims, $actualPrimary)) {
            $variantsToProcess[] = $variant;
        }
    }
    
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
    
    // AVIF if enabled and source isn't AVIF
    if (isAvifGenerationEnabled() && $sourceFormat !== 'avif') {
        $formatsToGenerate[] = 'avif';
    }
    
    // Always include source format (we'll copy it for variants)
    if (!in_array($sourceFormat, $formatsToGenerate)) {
        $formatsToGenerate[] = $sourceFormat;
    }
    
    // If no variants or formats to generate, return early
    if (empty($variantsToProcess) || empty($formatsToGenerate)) {
        return [
            'results' => [],
            'actual_primary' => $actualPrimary,
            'actual_full' => $actualFull,
            'delete_original' => $deleteOriginal
        ];
    }
    
    aviationwx_log('info', 'webcam variant generation starting', [
        'airport' => $airportId,
        'cam' => $camIndex,
        'source_format' => $sourceFormat,
        'input_dimensions' => $inputDimensions,
        'actual_primary' => $actualPrimary,
        'actual_full' => $actualFull,
        'variants_to_process' => $variantsToProcess,
        'formats_to_generate' => $formatsToGenerate,
        'delete_original' => $deleteOriginal,
        'timeout_seconds' => $timeout,
        'capture_time' => $captureTime
    ], 'app');
    
    $results = [];
    $processes = [];
    
    // Start all variant × format generation processes in parallel
    foreach ($variantsToProcess as $variant) {
        $variantDims = getVariantDimensions($variant, $actualPrimary);
        if ($variantDims === null) {
            continue;
        }
        
        // Letterboxing only for primary and full
        $letterbox = ($variant === 'primary' || $variant === 'full');
        
        foreach ($formatsToGenerate as $format) {
            // Create staging file path with variant and format
            $stagingFile = getStagingFilePath($airportId, $camIndex, $format, $variant);
            
            $cmd = buildVariantCommand($sourceFile, $stagingFile, $variant, $format, $variantDims, $letterbox, $captureTime);
            
            // Redirect stderr to stdout for capture
            $cmd = $cmd . ' 2>&1';
            
            $descriptorSpec = [
                0 => ['pipe', 'r'], // stdin
                1 => ['pipe', 'w'], // stdout
                2 => ['pipe', 'w'], // stderr
            ];
            
            $process = @proc_open($cmd, $descriptorSpec, $pipes);
            
            $key = $variant . '_' . $format;
            
            if (is_resource($process)) {
                // Close stdin immediately
                @fclose($pipes[0]);
                
                // Set stdout to non-blocking for polling
                stream_set_blocking($pipes[1], false);
                stream_set_blocking($pipes[2], false);
                
                $processes[$key] = [
                    'handle' => $process,
                    'pipes' => $pipes,
                    'dest' => $stagingFile,
                    'variant' => $variant,
                    'format' => $format,
                    'started' => microtime(true)
                ];
                
                aviationwx_log('debug', 'webcam variant process started', [
                    'airport' => $airportId,
                    'cam' => $camIndex,
                    'variant' => $variant,
                    'format' => $format
                ], 'app');
            } else {
                $results[$key] = false;
                aviationwx_log('error', 'webcam variant process failed to start', [
                    'airport' => $airportId,
                    'cam' => $camIndex,
                    'variant' => $variant,
                    'format' => $format
                ], 'app');
            }
        }
    }
    
    // Wait for all processes to complete (or timeout)
    while (!empty($processes) && time() < $deadline) {
        foreach ($processes as $key => $proc) {
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
                
                // Check success: file exists with size > 0
                // Accept exit codes: 0 (success), 1 (EXIF copy may have failed but image generated), 234 (output same as input - no resize needed)
                $fileExists = file_exists($proc['dest']);
                $fileSize = $fileExists ? filesize($proc['dest']) : 0;
                $success = $fileExists && $fileSize > 0 && ($exitCode === 0 || $exitCode === 1 || $exitCode === 234);
                $results[$key] = $success;
                
                if ($success) {
                    aviationwx_log('info', 'webcam variant generation complete', [
                        'airport' => $airportId,
                        'cam' => $camIndex,
                        'variant' => $proc['variant'],
                        'format' => $proc['format'],
                        'duration_ms' => $elapsed,
                        'size_bytes' => filesize($proc['dest']),
                        'dest_file' => basename($proc['dest']),
                        'dest_full_path' => $proc['dest'],
                        'result_key' => $key
                    ], 'app');
                } else {
                    aviationwx_log('warning', 'webcam variant generation failed', [
                        'airport' => $airportId,
                        'cam' => $camIndex,
                        'variant' => $proc['variant'],
                        'format' => $proc['format'],
                        'exit_code' => $exitCode,
                        'duration_ms' => $elapsed,
                        'file_exists' => file_exists($proc['dest']),
                        'file_size' => file_exists($proc['dest']) ? filesize($proc['dest']) : 0,
                        'dest_file' => $proc['dest'],
                        'result_key' => $key,
                        'stderr_preview' => substr($stderr, 0, 500),
                        'stdout_preview' => substr($stdout, 0, 200)
                    ], 'app');
                    
                    // Only clean up staging file if it truly failed (no file or zero size)
                    // Don't delete files that were created but had non-zero exit codes (EXIF copy failures, etc.)
                    $fileExists = file_exists($proc['dest']);
                    $fileSize = $fileExists ? filesize($proc['dest']) : 0;
                    if (!$fileExists || $fileSize === 0) {
                        if (file_exists($proc['dest'])) {
                            @unlink($proc['dest']);
                        }
                    } else {
                        // File exists and has content - log as partial success and mark as success
                        aviationwx_log('info', 'webcam variant generation partial success (non-zero exit but file created)', [
                            'airport' => $airportId,
                            'cam' => $camIndex,
                            'variant' => $proc['variant'],
                            'format' => $proc['format'],
                            'exit_code' => $exitCode,
                            'file_size' => $fileSize,
                            'dest_file' => basename($proc['dest']),
                            'result_key' => $key
                        ], 'app');
                        // Mark as success since file was created
                        $results[$key] = true;
                    }
                }
                
                unset($processes[$key]);
            }
        }
        
        // Small sleep to avoid busy-waiting
        if (!empty($processes)) {
            usleep(50000); // 50ms
        }
    }
    
    // Handle any remaining processes (timed out)
    foreach ($processes as $key => $proc) {
        aviationwx_log('warning', 'webcam variant generation timeout', [
            'airport' => $airportId,
            'cam' => $camIndex,
            'variant' => $proc['variant'],
            'format' => $proc['format'],
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
        
        $results[$key] = false;
    }
    
    // Log final results summary
    $successCount = count(array_filter($results, function($v) { return $v === true; }));
    $totalCount = count($results);
    aviationwx_log('info', 'webcam variant generation completed', [
        'airport' => $airportId,
        'cam' => $camIndex,
        'success_count' => $successCount,
        'total_count' => $totalCount,
        'results' => $results,
        'result_keys' => array_keys($results),
        'actual_primary' => $actualPrimary,
        'actual_full' => $actualFull,
        'delete_original' => $deleteOriginal
    ], 'app');
    
    return [
        'results' => $results,
        'actual_primary' => $actualPrimary,
        'actual_full' => $actualFull,
        'delete_original' => $deleteOriginal
    ];
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
    // Check if symlink path exists as a regular file (old system migration)
    if (file_exists($symlinkPath) && !is_link($symlinkPath) && is_file($symlinkPath)) {
        // Extract airport, cam, and format from path for logging
        // Format: {airport}_{camIndex}.{format} (e.g., kspb_0.jpg)
        $basename = basename($symlinkPath);
        $logContext = [];
        
        if (preg_match('/^([^_]+)_(\d+)\.(jpg|webp|avif)$/', $basename, $matches)) {
            $logContext = [
                'airport' => $matches[1],
                'cam' => (int)$matches[2],
                'format' => $matches[3],
                'old_file_size' => filesize($symlinkPath),
                'old_file_mtime' => filemtime($symlinkPath)
            ];
        } else {
            $logContext = [
                'symlink_path' => $basename
            ];
        }
        
        // Delete old regular file to allow symlink creation
        if (@unlink($symlinkPath)) {
            aviationwx_log('warning', 'webcam old system file removed for symlink migration', $logContext, 'app');
        } else {
            aviationwx_log('error', 'webcam old system file deletion failed', array_merge($logContext, [
                'error' => error_get_last()['message'] ?? 'unknown'
            ]), 'app');
            return false;
        }
    }
    
    // Create temporary symlink first (atomic operation)
    $tempSymlink = $symlinkPath . '.tmp';
    
    // Remove temp symlink if it exists
    if (file_exists($tempSymlink)) {
        @unlink($tempSymlink);
    }
    
    // Create symlink to target (relative path for portability)
    $targetBasename = basename($targetPath);
    $symlinkDir = dirname($symlinkPath);
    $relativeTarget = $targetBasename;
    
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
        // No existing symlink, just rename temp to final
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
        $sourceStagingFile = getStagingFilePath($airportId, $camIndex, $sourceFormat, 'primary');
        if (file_exists($sourceStagingFile)) {
            $timestamp = getSourceCaptureTime($sourceStagingFile);
        }
        if ($timestamp <= 0) {
            $timestamp = time();
        }
    }
    
    // Always try to promote the source format first
    $sourceStagingFile = getStagingFilePath($airportId, $camIndex, $sourceFormat);
    $sourceTimestampFile = getFinalFilePath($airportId, $camIndex, $sourceFormat, $timestamp);
    $sourceSymlink = getCacheSymlinkPath($airportId, $camIndex, $sourceFormat);
    
    if (file_exists($sourceStagingFile)) {
        // Rename staging file to timestamp-based file
        if (@rename($sourceStagingFile, $sourceTimestampFile)) {
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
        
        $stagingFile = getStagingFilePath($airportId, $camIndex, $format, 'primary');
        $timestampFile = getFinalFilePath($airportId, $camIndex, $format, $timestamp);
        $symlink = getCacheSymlinkPath($airportId, $camIndex, $format);
        
        if (file_exists($stagingFile)) {
            // Rename staging file to timestamp-based file
            if (@rename($stagingFile, $timestampFile)) {
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
 * Promote variant staging files to final cache location (timestamp-based with symlinks)
 * 
 * Atomically renames .tmp files to timestamp-based filenames with variant names
 * and creates/updates symlinks for easy lookup. Only promotes variants that generated successfully.
 * Deletes original file if it exceeded max resolution.
 * 
 * @param string $airportId Airport ID (e.g., 'kspb')
 * @param int $camIndex Camera index (0-based)
 * @param array $variantResults Results from generateVariantsSync: ['variant_format' => bool, ...]
 * @param string $sourceFormat The original source format
 * @param int $timestamp Unix timestamp for the image (0 to auto-detect from source file)
 * @param bool $deleteOriginal Whether to delete the original source file (if it exceeded max resolution)
 * @param string|null $originalSourceFile Path to original source file to delete (if deleteOriginal is true)
 * @return array Promoted variants: ['variant' => ['format1', 'format2', ...], ...]
 */
function promoteVariants(string $airportId, int $camIndex, array $variantResults, string $sourceFormat, int $timestamp = 0, bool $deleteOriginal = false, ?string $originalSourceFile = null): array {
    $promoted = [];
    
    // Log promotion attempt with full context
    aviationwx_log('debug', 'webcam variant promotion starting', [
        'airport' => $airportId,
        'cam' => $camIndex,
        'variant_results_count' => count($variantResults),
        'variant_results_keys' => array_keys($variantResults),
        'variant_results' => $variantResults,
        'source_format' => $sourceFormat,
        'timestamp' => $timestamp,
        'delete_original' => $deleteOriginal
    ], 'app');
    
    // Get timestamp from source file if not provided
    if ($timestamp <= 0) {
        // Try to get from any staging file using new directory structure
        $cacheDir = getWebcamCacheDir($airportId, $camIndex);
        $stagingPattern = $cacheDir . '/staging_*.tmp';
        $stagingFiles = glob($stagingPattern);
        if (!empty($stagingFiles)) {
            $timestamp = getSourceCaptureTime($stagingFiles[0]);
        }
        if ($timestamp <= 0) {
            $timestamp = time();
        }
    }
    
    // Group results by variant
    $variantsByFormat = [];
    foreach ($variantResults as $key => $success) {
        if (!$success) {
            aviationwx_log('debug', 'webcam variant promotion: skipping failed result', [
                'airport' => $airportId,
                'cam' => $camIndex,
                'key' => $key,
                'success' => $success
            ], 'app');
            continue;
        }
        
        // Key format: "variant_format" (e.g., "primary_jpg", "thumb_webp")
        if (preg_match('/^(.+)_(.+)$/', $key, $matches)) {
            $variant = $matches[1];
            $format = $matches[2];
            
            if (!isset($variantsByFormat[$variant])) {
                $variantsByFormat[$variant] = [];
            }
            $variantsByFormat[$variant][] = $format;
        } else {
            aviationwx_log('warning', 'webcam variant promotion: invalid result key format', [
                'airport' => $airportId,
                'cam' => $camIndex,
                'key' => $key
            ], 'app');
        }
    }
    
    aviationwx_log('debug', 'webcam variant promotion: grouped by variant', [
        'airport' => $airportId,
        'cam' => $camIndex,
        'variants_by_format' => $variantsByFormat
    ], 'app');
    
    // Check what staging files actually exist before promotion
    $cacheDir = getWebcamCacheDir($airportId, $camIndex);
    $existingStagingFiles = glob($cacheDir . '/staging_*.tmp*');
    aviationwx_log('debug', 'webcam variant promotion: staging files check', [
        'airport' => $airportId,
        'cam' => $camIndex,
        'cache_dir' => $cacheDir,
        'existing_staging_files' => array_map('basename', $existingStagingFiles),
        'staging_file_count' => count($existingStagingFiles)
    ], 'app');
    
    // Promote each variant's formats
    foreach ($variantsByFormat as $variant => $formats) {
        $promotedFormats = [];
        
        foreach ($formats as $format) {
            // Get staging file path (with variant in name)
            $stagingFile = getStagingFilePath($airportId, $camIndex, $format, $variant);
            
            // Get final file path
            $finalFile = getFinalFilePath($airportId, $camIndex, $format, $timestamp, $variant);
            
            aviationwx_log('debug', 'webcam variant promotion: attempting promotion', [
                'airport' => $airportId,
                'cam' => $camIndex,
                'variant' => $variant,
                'format' => $format,
                'staging_file' => $stagingFile,
                'staging_exists' => file_exists($stagingFile),
                'staging_size' => file_exists($stagingFile) ? filesize($stagingFile) : 0,
                'final_file' => $finalFile,
                'final_exists' => file_exists($finalFile)
            ], 'app');
            
            if (file_exists($stagingFile)) {
                // Rename staging file to timestamp-based file
                if (@rename($stagingFile, $finalFile)) {
                    $promotedFormats[] = $format;
                    
                    // Update symlink for primary variant only
                    if ($variant === 'primary') {
                        $symlink = getCacheSymlinkPath($airportId, $camIndex, $format);
                        if (!updateCacheSymlink($symlink, $finalFile)) {
                            aviationwx_log('error', 'webcam variant symlink failed', [
                                'airport' => $airportId,
                                'cam' => $camIndex,
                                'variant' => $variant,
                                'format' => $format,
                                'error' => error_get_last()['message'] ?? 'unknown'
                            ], 'app');
                        }
                    }
                } else {
                    $error = error_get_last();
                    aviationwx_log('error', 'webcam variant promotion failed', [
                        'airport' => $airportId,
                        'cam' => $camIndex,
                        'variant' => $variant,
                        'format' => $format,
                        'staging_file' => $stagingFile,
                        'final_file' => $finalFile,
                        'staging_exists' => file_exists($stagingFile),
                        'final_exists' => file_exists($finalFile),
                        'staging_readable' => is_readable($stagingFile),
                        'final_dir_writable' => is_writable(dirname($finalFile)),
                        'error' => $error['message'] ?? 'unknown',
                        'error_file' => $error['file'] ?? null,
                        'error_line' => $error['line'] ?? null
                    ], 'app');
                }
            } else {
                aviationwx_log('warning', 'webcam variant promotion: staging file not found', [
                    'airport' => $airportId,
                    'cam' => $camIndex,
                    'variant' => $variant,
                    'format' => $format,
                    'staging_file' => $stagingFile,
                    'staging_file_dir' => dirname($stagingFile),
                    'staging_dir_exists' => is_dir(dirname($stagingFile)),
                    'staging_dir_readable' => is_readable(dirname($stagingFile)),
                    'all_staging_files' => array_map('basename', glob(dirname($stagingFile) . '/staging_*.tmp*'))
                ], 'app');
            }
        }
        
        if (!empty($promotedFormats)) {
            $promoted[$variant] = $promotedFormats;
        }
    }
    
    // Delete original file if it exceeded max resolution
    if ($deleteOriginal && $originalSourceFile !== null && file_exists($originalSourceFile)) {
        if (@unlink($originalSourceFile)) {
            aviationwx_log('info', 'webcam original file deleted (exceeded max resolution)', [
                'airport' => $airportId,
                'cam' => $camIndex,
                'original_file' => basename($originalSourceFile)
            ], 'app');
        } else {
            aviationwx_log('warning', 'webcam original file deletion failed', [
                'airport' => $airportId,
                'cam' => $camIndex,
                'original_file' => $originalSourceFile,
                'error' => error_get_last()['message'] ?? 'unknown'
            ], 'app');
        }
    }
    
    // Log promotion result
    $totalPromoted = array_sum(array_map('count', $promoted));
    $totalAttempted = count($variantResults);
    
    if ($totalPromoted === $totalAttempted) {
        aviationwx_log('info', 'webcam variants promoted successfully', [
            'airport' => $airportId,
            'cam' => $camIndex,
            'variants' => array_keys($promoted),
            'total_formats' => $totalPromoted,
            'timestamp' => $timestamp,
            'original_deleted' => $deleteOriginal
        ], 'app');
    } elseif ($totalPromoted > 0) {
        aviationwx_log('warning', 'webcam partial variant promotion', [
            'airport' => $airportId,
            'cam' => $camIndex,
            'promoted' => $promoted,
            'promoted_count' => $totalPromoted,
            'attempted_count' => $totalAttempted,
            'timestamp' => $timestamp
        ], 'app');
    } else {
        aviationwx_log('error', 'webcam variant promotion failed completely', [
            'airport' => $airportId,
            'cam' => $camIndex,
            'attempted_count' => $totalAttempted,
            'timestamp' => $timestamp
        ], 'app');
    }
    
    return $promoted;
}

/**
 * Generate WEBP version of image (non-blocking with mtime sync)
 * 
 * Converts source image to WEBP format using ffmpeg. Runs in background
 * and automatically syncs mtime to match source file's capture time.
 * 
 * @param string $sourceFile Source image file path (any format)
 * @param string $airportId Airport ID (e.g., 'kspb')
 * @param int $camIndex Camera index (0-based)
 * @return bool True if WEBP generation started, false on failure
 */
function generateWebp($sourceFile, $airportId, $camIndex) {
    // Check if format generation is enabled
    if (!isWebpGenerationEnabled()) {
        return false; // Format disabled, don't generate
    }
    
    if (!file_exists($sourceFile) || !is_readable($sourceFile)) {
        return false;
    }
    
    $cacheDir = getWebcamCacheDir($airportId, $camIndex);
    if (!is_dir($cacheDir) || !is_writable($cacheDir)) {
        return false;
    }
    
    $cacheWebp = getCacheSymlinkPath($airportId, $camIndex, 'webp');
    
    // Log job start
    aviationwx_log('info', 'webcam format generation job started', [
        'airport' => $airportId,
        'cam' => $camIndex,
        'format' => 'webp',
        'source_file' => basename($sourceFile),
        'source_size' => filesize($sourceFile),
        'timestamp' => time()
    ], 'app');
    
    // Get source capture time before starting generation
    $captureTime = getSourceCaptureTime($sourceFile);
    
    // Build ffmpeg command with nice 10 (low priority to avoid interfering with normal operations)
    $cmdWebp = sprintf(
        "nice -n 10 ffmpeg -hide_banner -loglevel error -y -i %s -frames:v 1 -q:v %d -compression_level %d -preset default %s",
        escapeshellarg($sourceFile),
        getWebcamWebpQuality(),
        WEBCAM_WEBP_COMPRESSION_LEVEL,
        escapeshellarg($cacheWebp)
    );
    
    // Chain mtime sync after generation (only if capture time available)
    if ($captureTime > 0) {
        $dateStr = date('YmdHi.s', $captureTime); // Format: YYYYMMDDhhmm.ss (touch -t requires dot before seconds)
        $cmdSync = sprintf("touch -t %s %s", $dateStr, escapeshellarg($cacheWebp));
        $cmd = $cmdWebp . ' && ' . $cmdSync;
    } else {
        $cmd = $cmdWebp;
    }
    
    // Chain EXIF copy to preserve metadata in generated format (if exiftool available)
    if (isExiftoolAvailable()) {
        $cmdExif = sprintf(
            "exiftool -overwrite_original -q -P -TagsFromFile %s -all:all %s || true",
            escapeshellarg($sourceFile),
            escapeshellarg($cacheWebp)
        );
        $cmd = $cmd . ' && ' . $cmdExif;
    }
    
    // Run in background (non-blocking)
    // Result will be logged when format status is checked (in getFormatStatus or isFormatGenerating)
    if (function_exists('exec')) {
        $cmd = $cmd . ' > /dev/null 2>&1 &';
        @exec($cmd);
        return true;
    }
    
    // Log failure if exec not available
    aviationwx_log('error', 'webcam format generation job failed - exec not available', [
        'airport' => $airportId,
        'cam' => $camIndex,
        'format' => 'webp',
        'error' => 'exec_function_not_available',
        'troubleshooting' => 'PHP exec() function is disabled or not available'
    ], 'app');
    
    return false;
}

/**
 * Generate AVIF version of image (non-blocking with mtime sync)
 * 
 * Converts source image to AVIF format using ffmpeg. Runs in background
 * and automatically syncs mtime to match source file's capture time.
 * 
 * @param string $sourceFile Source image file path (any format)
 * @param string $airportId Airport ID (e.g., 'kspb')
 * @param int $camIndex Camera index (0-based)
 * @return bool True if AVIF generation started, false on failure
 */
function generateAvif($sourceFile, $airportId, $camIndex) {
    // Check if format generation is enabled
    if (!isAvifGenerationEnabled()) {
        return false; // Format disabled, don't generate
    }
    
    if (!file_exists($sourceFile) || !is_readable($sourceFile)) {
        return false;
    }
    
    $cacheDir = getWebcamCacheDir($airportId, $camIndex);
    if (!is_dir($cacheDir) || !is_writable($cacheDir)) {
        return false;
    }
    
    $cacheAvif = getCacheSymlinkPath($airportId, $camIndex, 'avif');
    
    // Log job start
    aviationwx_log('info', 'webcam format generation job started', [
        'airport' => $airportId,
        'cam' => $camIndex,
        'format' => 'avif',
        'source_file' => basename($sourceFile),
        'source_size' => filesize($sourceFile),
        'timestamp' => time()
    ], 'app');
    
    // Get source capture time before starting generation
    $captureTime = getSourceCaptureTime($sourceFile);
    
    // Build ffmpeg command for AVIF encoding with nice 10 (low priority to avoid interfering with normal operations)
    // -c:v libaom-av1: Use AV1 codec (AVIF uses AV1)
    // -crf: Quality setting (0=lossless, 23=high quality, 30+=medium quality)
    // -b:v 0: Use CRF mode (quality-based, not bitrate)
    // -cpu-used: Speed vs quality balance (0-8, 4 is balanced)
    $cmdAvif = sprintf(
        "nice -n 10 ffmpeg -hide_banner -loglevel error -y -i %s -frames:v 1 -c:v libaom-av1 -crf %d -b:v 0 -cpu-used %d %s",
        escapeshellarg($sourceFile),
        getWebcamAvifCrf(),
        WEBCAM_AVIF_CPU_USED,
        escapeshellarg($cacheAvif)
    );
    
    // Chain mtime sync after generation (only if capture time available)
    if ($captureTime > 0) {
        $dateStr = date('YmdHi.s', $captureTime); // Format: YYYYMMDDhhmm.ss (touch -t requires dot before seconds)
        $cmdSync = sprintf("touch -t %s %s", $dateStr, escapeshellarg($cacheAvif));
        $cmd = $cmdAvif . ' && ' . $cmdSync;
    } else {
        $cmd = $cmdAvif;
    }
    
    // Chain EXIF copy to preserve metadata in generated format (if exiftool available)
    if (isExiftoolAvailable()) {
        $cmdExif = sprintf(
            "exiftool -overwrite_original -q -P -TagsFromFile %s -all:all %s || true",
            escapeshellarg($sourceFile),
            escapeshellarg($cacheAvif)
        );
        $cmd = $cmd . ' && ' . $cmdExif;
    }
    
    // Run in background (non-blocking)
    // Result will be logged when format status is checked (in getFormatStatus or isFormatGenerating)
    if (function_exists('exec')) {
        $cmd = $cmd . ' > /dev/null 2>&1 &';
        @exec($cmd);
        return true;
    }
    
    // Log failure if exec not available
    aviationwx_log('error', 'webcam format generation job failed - exec not available', [
        'airport' => $airportId,
        'cam' => $camIndex,
        'format' => 'avif',
        'error' => 'exec_function_not_available',
        'troubleshooting' => 'PHP exec() function is disabled or not available'
    ], 'app');
    
    return false;
}

/**
 * Generate JPEG from source image (non-blocking with mtime sync)
 * 
 * Converts source image to JPEG format using ffmpeg. Runs in background
 * and automatically syncs mtime to match source file's capture time.
 * Only generates if JPEG doesn't already exist.
 * 
 * @param string $sourceFile Source image file path (any format)
 * @param string $airportId Airport ID (e.g., 'kspb')
 * @param int $camIndex Camera index (0-based)
 * @return bool True if JPEG generation started, false on failure
 */
function generateJpeg($sourceFile, $airportId, $camIndex) {
    if (!file_exists($sourceFile) || !is_readable($sourceFile)) {
        return false;
    }
    
    $cacheDir = getWebcamCacheDir($airportId, $camIndex);
    if (!is_dir($cacheDir) || !is_writable($cacheDir)) {
        return false;
    }
    
    $cacheJpeg = getCacheSymlinkPath($airportId, $camIndex, 'jpg');
    
    // Skip if already exists
    if (file_exists($cacheJpeg)) {
        return true;
    }
    
    // Get source capture time before starting generation
    $captureTime = getSourceCaptureTime($sourceFile);
    
    // Build ffmpeg command
    $cmdJpeg = sprintf(
        "ffmpeg -hide_banner -loglevel error -y -i %s -q:v %d %s",
        escapeshellarg($sourceFile),
        getWebcamJpegQuality(),
        escapeshellarg($cacheJpeg)
    );
    
    // Chain mtime sync after generation (only if capture time available)
    if ($captureTime > 0) {
        $dateStr = date('YmdHi.s', $captureTime); // Format: YYYYMMDDhhmm.ss (touch -t requires dot before seconds)
        $cmdSync = sprintf("touch -t %s %s", $dateStr, escapeshellarg($cacheJpeg));
        $cmd = $cmdJpeg . ' && ' . $cmdSync;
    } else {
        $cmd = $cmdJpeg;
    }
    
    // Chain EXIF copy to preserve metadata in generated format (if exiftool available)
    if (isExiftoolAvailable()) {
        $cmdExif = sprintf(
            "exiftool -overwrite_original -q -P -TagsFromFile %s -all:all %s || true",
            escapeshellarg($sourceFile),
            escapeshellarg($cacheJpeg)
        );
        $cmd = $cmd . ' && ' . $cmdExif;
    }
    
    // Run in background (non-blocking)
    if (function_exists('exec')) {
        $cmd = $cmd . ' > /dev/null 2>&1 &';
        @exec($cmd);
        return true;
    }
    
    return false;
}

