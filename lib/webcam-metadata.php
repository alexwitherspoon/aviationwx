<?php
/**
 * Webcam Metadata Cache
 * 
 * Stores webcam image metadata in APCu (24h TTL).
 * Falls back to file read if APCu unavailable.
 */

require_once __DIR__ . '/cache-paths.php';
require_once __DIR__ . '/webcam-format-generation.php';
require_once __DIR__ . '/exif-utils.php';
require_once __DIR__ . '/config.php';

// APCu TTL: 24 hours
if (!defined('WEBCAM_METADATA_TTL')) {
    define('WEBCAM_METADATA_TTL', 86400); // 24 hours
}

/**
 * Get webcam metadata from APCu or file
 * 
 * @param string $airportId Airport identifier
 * @param int $camIndex Camera index (0-based)
 * @return array|null Metadata array or null if unavailable
 */
function getWebcamMetadata(string $airportId, int $camIndex): ?array {
    $key = "webcam_meta_{$airportId}_{$camIndex}";
    
    if (function_exists('apcu_fetch')) {
        $meta = @apcu_fetch($key);
        if ($meta !== false && is_array($meta)) {
            // Validate that cached timestamp matches latest file timestamp
            // This handles CLI/FPM APCu isolation (CLI scheduler writes don't affect FPM cache)
            $latestTimestamp = getLatestImageTimestamp($airportId, $camIndex);
            if ($latestTimestamp > 0 && isset($meta['timestamp']) && $meta['timestamp'] === $latestTimestamp) {
                return $meta;
            }
            // Cache is stale - fall through to rebuild
        }
    }
    
    $originalFile = getWebcamOriginalPath($airportId, $camIndex);
    if (!file_exists($originalFile)) {
        return null;
    }
    
    $meta = buildWebcamMetadataFromFile($originalFile, $airportId, $camIndex);
    if ($meta === null) {
        return null;
    }
    
    if (function_exists('apcu_store')) {
        @apcu_store($key, $meta, WEBCAM_METADATA_TTL);
    }
    
    return $meta;
}

/**
 * Clear all webcam metadata cache entries from APCu
 * 
 * Used when config changes to ensure webcam names and other config-dependent
 * metadata are refreshed from the updated config.
 * 
 * @return void
 */
function clearWebcamMetadataCache(): void {
    if (!function_exists('apcu_delete')) {
        return;
    }
    
    // Get all APCu cache entries and delete webcam metadata keys
    $info = @apcu_cache_info(false);
    if (!is_array($info) || !isset($info['cache_list'])) {
        return;
    }
    
    foreach ($info['cache_list'] as $entry) {
        $key = $entry['info'] ?? $entry['key'] ?? null;
        if ($key !== null && strpos($key, 'webcam_meta_') === 0) {
            @apcu_delete($key);
        }
    }
}

/**
 * Update webcam metadata in APCu
 * 
 * @param string $airportId Airport identifier
 * @param int $camIndex Camera index (0-based)
 * @param string $originalFile Path to original image file
 * @return bool True on success
 */
function updateWebcamMetadata(string $airportId, int $camIndex, string $originalFile): bool {
    if (!file_exists($originalFile)) {
        return false;
    }
    
    $meta = buildWebcamMetadataFromFile($originalFile, $airportId, $camIndex);
    if ($meta === null) {
        return false;
    }
    
    $key = "webcam_meta_{$airportId}_{$camIndex}";
    
    if (function_exists('apcu_store')) {
        @apcu_store($key, $meta, WEBCAM_METADATA_TTL);
        return true;
    }
    
    return false;
}

/**
 * Build metadata array from image file
 * 
 * @param string $filePath Path to image file
 * @param string $airportId Airport identifier
 * @param int $camIndex Camera index (0-based)
 * @return array|null Metadata array or null on failure
 */
function buildWebcamMetadataFromFile(string $filePath, string $airportId, int $camIndex): ?array {
    $dims = getImageDimensions($filePath);
    if ($dims === null) {
        return null;
    }
    
    $width = $dims['width'];
    $height = $dims['height'];
    $aspectRatio = $width / $height;
    
    $format = detectImageFormat($filePath);
    if ($format === null) {
        $format = 'jpg';
    }
    
    $timestamp = getSourceCaptureTime($filePath);
    if ($timestamp <= 0) {
        $timestamp = filemtime($filePath) ?: time();
    }
    
    $config = loadConfig();
    $camName = null;
    if ($config !== null && isset($config['airports'][$airportId]['webcams'][$camIndex])) {
        $camName = $config['airports'][$airportId]['webcams'][$camIndex]['name'] ?? null;
    }
    
    $variantHeights = getVariantHeights($airportId, $camIndex);
    
    $exifData = null;
    if (function_exists('exif_read_data') && ($format === 'jpg' || $format === 'jpeg')) {
        $exif = @exif_read_data($filePath, 'EXIF', true);
        if ($exif !== false) {
            $exifData = [
                'datetime_original' => $exif['EXIF']['DateTimeOriginal'] ?? $exif['DateTimeOriginal'] ?? null,
                'make' => $exif['IFD0']['Make'] ?? null,
                'model' => $exif['IFD0']['Model'] ?? null,
            ];
        }
    }
    
    return [
        'width' => $width,
        'height' => $height,
        'aspect_ratio' => round($aspectRatio, 6),
        'format' => $format,
        'timestamp' => $timestamp,
        'name' => $camName,
        'variant_heights' => $variantHeights,
        'exif' => $exifData,
        'last_updated' => time(),
    ];
}

/**
 * Get variant heights for a camera
 * 
 * Priority: camera-level → airport-level → global → default [1080, 720, 360]
 * 
 * @param string $airportId Airport identifier
 * @param int $camIndex Camera index (0-based)
 * @return array Array of heights (integers)
 */
function getVariantHeights(string $airportId, int $camIndex): array {
    $config = loadConfig();
    if ($config === null) {
        return [1080, 720, 360];
    }
    
    if (isset($config['airports'][$airportId]['webcams'][$camIndex]['variant_heights'])) {
        $heights = $config['airports'][$airportId]['webcams'][$camIndex]['variant_heights'];
        if (is_array($heights) && !empty($heights)) {
            return validateVariantHeights($heights);
        }
    }
    
    if (isset($config['airports'][$airportId]['webcam_variant_heights'])) {
        $heights = $config['airports'][$airportId]['webcam_variant_heights'];
        if (is_array($heights) && !empty($heights)) {
            return validateVariantHeights($heights);
        }
    }
    
    if (isset($config['config']['webcam_variant_heights'])) {
        $heights = $config['config']['webcam_variant_heights'];
        if (is_array($heights) && !empty($heights)) {
            return validateVariantHeights($heights);
        }
    }
    
    return [1080, 720, 360];
}

/**
 * Validate and sanitize variant heights
 * 
 * @param array $heights Array of height values
 * @return array Validated array of heights (integers, 1-5000, sorted descending)
 */
function validateVariantHeights(array $heights): array {
    $validated = [];
    
    foreach ($heights as $height) {
        $height = (int)$height;
        if ($height >= 1 && $height <= 5000) {
            $validated[] = $height;
        }
    }
    
    $validated = array_unique($validated);
    rsort($validated);
    
    return $validated;
}

/**
 * Get path to latest original image
 * 
 * @param string $airportId Airport identifier
 * @param int $camIndex Camera index (0-based)
 * @return string|null Path to original file or null if not found
 */
function getWebcamOriginalPath(string $airportId, int $camIndex): ?string {
    // Try symlink first
    $symlink = getWebcamOriginalSymlinkPath($airportId, $camIndex, 'jpg');
    if (is_link($symlink) && file_exists($symlink)) {
        $realPath = readlink($symlink);
        if ($realPath !== false) {
            $fullPath = dirname($symlink) . '/' . $realPath;
            if (file_exists($fullPath)) {
                return $fullPath;
            }
        }
    }
    
    $files = getWebcamImageFiles($airportId, $camIndex, '*_original.{jpg,jpeg,webp}');
    
    if (empty($files)) {
        return null;
    }
    
    // Sort by mtime descending
    usort($files, function($a, $b) {
        return filemtime($b) - filemtime($a);
    });
    
    return $files[0];
}

/**
 * Get staging file path for a variant
 * 
 * @param string $airportId Airport identifier
 * @param int $camIndex Camera index
 * @param string|int $size Size: "original" or numeric height
 * @param string $format Format: 'jpg' or 'webp'
 * @return string Staging file path
 */
function getStagingPathForVariant(string $airportId, int $camIndex, $size, string $format): string {
    $cacheDir = getWebcamCameraDir($airportId, $camIndex);
    
    if ($size === 'original') {
        return $cacheDir . '/staging_original.' . $format . '.tmp';
    } else {
        return $cacheDir . '/staging_' . (int)$size . '_' . $format . '.tmp';
    }
}

/**
 * Get image file path for requested size and format
 * 
 * Falls back to staging files if promotion is in progress, waiting up to 100ms.
 * 
 * @param string $airportId Airport identifier
 * @param int $camIndex Camera index
 * @param int $timestamp Image timestamp
 * @param string|int $size Size: "original" or numeric height
 * @param string $format Format: 'jpg' or 'webp'
 * @return string|null File path or null if not found
 */
function getImagePathForSize(string $airportId, int $camIndex, int $timestamp, $size, string $format): ?string {
    require_once __DIR__ . '/logger.php';
    
    if ($size === 'original') {
        $path = getWebcamOriginalTimestampedPath($airportId, $camIndex, $timestamp, $format);
    } else {
        $path = getWebcamVariantPath($airportId, $camIndex, $timestamp, (int)$size, $format);
    }
    
    if (file_exists($path)) {
        return $path;
    }
    
    // Fallback to staging file (promotion in progress)
    $stagingPath = getStagingPathForVariant($airportId, $camIndex, $size, $format);
    if (file_exists($stagingPath)) {
        $maxWait = 100; // milliseconds
        $waited = 0;
        $waitInterval = 10;
        
        while ($waited < $maxWait && !file_exists($path)) {
            usleep($waitInterval * 1000);
            $waited += $waitInterval;
        }
        
        if (file_exists($path)) {
            return $path;
        }
        
        // Serve staging file directly (rename is atomic, file is complete)
        if (file_exists($stagingPath) && filesize($stagingPath) > 0) {
            aviationwx_log('debug', 'webcam serving from staging file', [
                'airport' => $airportId,
                'cam' => $camIndex,
                'timestamp' => $timestamp,
                'size' => $size,
                'format' => $format
            ], 'app');
            return $stagingPath;
        }
    }
    
    return null;
}

/**
 * Get available variants for an image
 * 
 * @param string $airportId Airport identifier
 * @param int $camIndex Camera index
 * @param int $timestamp Image timestamp
 * @return array Array of available heights and formats [height => [formats]]
 */
function getAvailableVariants(string $airportId, int $camIndex, int $timestamp): array {
    $cacheDir = getWebcamCameraDir($airportId, $camIndex);
    if (!is_dir($cacheDir)) {
        return [];
    }
    
    // Only check for enabled formats
    require_once __DIR__ . '/config.php';
    $enabledFormats = getEnabledWebcamFormats();
    
    $variants = [];
    
    // Check original files for enabled formats only
    foreach ($enabledFormats as $format) {
        $path = getWebcamOriginalTimestampedPath($airportId, $camIndex, $timestamp, $format);
        if (file_exists($path)) {
            if (!isset($variants['original'])) {
                $variants['original'] = [];
            }
            $variants['original'][] = $format;
        }
    }
    
    // Build pattern for enabled formats only
    $formatExtensions = [];
    foreach ($enabledFormats as $format) {
        if ($format === 'jpg') {
            $formatExtensions[] = 'jpg';
            $formatExtensions[] = 'jpeg';
        } else {
            $formatExtensions[] = $format;
        }
    }
    
    if (empty($formatExtensions)) {
        return $variants;
    }
    
    // Check variant files in date/hour subdir (matches getWebcamFramesDir structure)
    $framesDir = getWebcamFramesDir($airportId, $camIndex, $timestamp);
    $pattern = $framesDir . '/' . $timestamp . '_*.{' . implode(',', $formatExtensions) . '}';
    $files = glob($pattern, GLOB_BRACE);
    
    foreach ($files as $file) {
        if (is_link($file)) {
            continue;
        }
        
        $basename = basename($file);
        // Match: {timestamp}_{height}.{format}
        if (preg_match('/^' . $timestamp . '_(\d+)\.(jpg|jpeg|webp)$/', $basename, $matches)) {
            $height = (int)$matches[1];
            $format = $matches[2] === 'jpeg' ? 'jpg' : $matches[2];
            
            // Only include if format is enabled
            if (!in_array($format, $enabledFormats)) {
                continue;
            }
            
            if (!isset($variants[$height])) {
                $variants[$height] = [];
            }
            $variants[$height][] = $format;
        }
    }
    
    return $variants;
}

/**
 * Get latest image timestamp for a camera
 * 
 * Scans the camera directory for timestamped files and returns the most recent timestamp.
 * 
 * @param string $airportId Airport identifier
 * @param int $camIndex Camera index (0-based)
 * @return int Unix timestamp, or 0 if no images found
 */
function getLatestImageTimestamp(string $airportId, int $camIndex): int {
    $files = getWebcamImageFiles($airportId, $camIndex, '*_*.{jpg,jpeg,webp}');
    if (empty($files)) {
        return 0;
    }
    
    $latestTimestamp = 0;
    foreach ($files as $file) {
        // Skip symlinks
        if (is_link($file)) {
            continue;
        }
        
        $basename = basename($file);
        // Match: {timestamp}_original.{format} or {timestamp}_{height}.{format}
        if (preg_match('/^(\d+)_(original|\d+)\.(jpg|jpeg|webp)$/', $basename, $matches)) {
            $timestamp = (int)$matches[1];
            if ($timestamp > $latestTimestamp) {
                $latestTimestamp = $timestamp;
            }
        }
    }
    
    return $latestTimestamp;
}

/**
 * Get last completed image timestamp for a camera
 * 
 * Returns the second-most-recent timestamp to avoid race conditions during
 * variant generation. The latest image may still be in-progress, but the
 * second-latest is guaranteed to be fully promoted.
 * 
 * @param string $airportId Airport identifier
 * @param int $camIndex Camera index (0-based)
 * @return int Unix timestamp, or 0 if no completed images found
 */
function getLastCompletedImageTimestamp(string $airportId, int $camIndex): int {
    $files = getWebcamImageFiles($airportId, $camIndex, '*_*.{jpg,jpeg,webp}');
    if (empty($files)) {
        return 0;
    }
    
    // Collect unique timestamps
    $timestamps = [];
    foreach ($files as $file) {
        // Skip symlinks
        if (is_link($file)) {
            continue;
        }
        
        $basename = basename($file);
        // Match: {timestamp}_original.{format} or {timestamp}_{height}.{format}
        if (preg_match('/^(\d+)_(original|\d+)\.(jpg|jpeg|webp)$/', $basename, $matches)) {
            $timestamp = (int)$matches[1];
            $timestamps[$timestamp] = true;
        }
    }
    
    if (empty($timestamps)) {
        return 0;
    }
    
    // Get unique timestamps and sort descending
    $uniqueTimestamps = array_keys($timestamps);
    rsort($uniqueTimestamps);
    
    // Return second-most-recent (last completed)
    // If only one image exists, return it (no race condition possible)
    if (count($uniqueTimestamps) === 1) {
        return $uniqueTimestamps[0];
    }
    
    return $uniqueTimestamps[1];
}


