<?php
/**
 * Webcam Format Generation Library
 * 
 * Shared functions for generating image formats (WebP, AVIF, JPEG) from source images.
 * Used by both push webcam processing and fetched webcam processing.
 * 
 * All generation functions:
 * - Run asynchronously (exec() &) for non-blocking behavior
 * - Automatically sync mtime to match source file's capture time
 * - Support any source format (JPEG, PNG, WebP, AVIF)
 */

require_once __DIR__ . '/constants.php';
require_once __DIR__ . '/logger.php';
require_once __DIR__ . '/config.php';

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
    
    $cacheDir = __DIR__ . '/../cache/webcams';
    if (!is_dir($cacheDir) || !is_writable($cacheDir)) {
        return false;
    }
    
    $cacheWebp = $cacheDir . '/' . $airportId . '_' . $camIndex . '.webp';
    
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
    
    // Build ffmpeg command with nice -1
    $cmdWebp = sprintf(
        "nice -n -1 ffmpeg -hide_banner -loglevel error -y -i %s -frames:v 1 -q:v 30 -compression_level 6 -preset default %s",
        escapeshellarg($sourceFile),
        escapeshellarg($cacheWebp)
    );
    
    // Chain mtime sync after generation (only if capture time available)
    if ($captureTime > 0) {
        $dateStr = date('YmdHis', $captureTime);
        $cmdSync = sprintf("touch -t %s %s", $dateStr, escapeshellarg($cacheWebp));
        $cmd = $cmdWebp . ' && ' . $cmdSync;
    } else {
        $cmd = $cmdWebp;
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
    
    $cacheDir = __DIR__ . '/../cache/webcams';
    if (!is_dir($cacheDir) || !is_writable($cacheDir)) {
        return false;
    }
    
    $cacheAvif = $cacheDir . '/' . $airportId . '_' . $camIndex . '.avif';
    
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
    
    // Build ffmpeg command for AVIF encoding with nice -1
    // -c:v libaom-av1: Use AV1 codec (AVIF uses AV1)
    // -crf 30: Quality setting (similar to WebP's -q:v 30)
    // -b:v 0: Use CRF mode (quality-based, not bitrate)
    // -cpu-used 4: Speed vs quality balance (0-8, 4 is balanced)
    $cmdAvif = sprintf(
        "nice -n -1 ffmpeg -hide_banner -loglevel error -y -i %s -frames:v 1 -c:v libaom-av1 -crf 30 -b:v 0 -cpu-used 4 %s",
        escapeshellarg($sourceFile),
        escapeshellarg($cacheAvif)
    );
    
    // Chain mtime sync after generation (only if capture time available)
    if ($captureTime > 0) {
        $dateStr = date('YmdHis', $captureTime);
        $cmdSync = sprintf("touch -t %s %s", $dateStr, escapeshellarg($cacheAvif));
        $cmd = $cmdAvif . ' && ' . $cmdSync;
    } else {
        $cmd = $cmdAvif;
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
    
    $cacheDir = __DIR__ . '/../cache/webcams';
    if (!is_dir($cacheDir) || !is_writable($cacheDir)) {
        return false;
    }
    
    $cacheJpeg = $cacheDir . '/' . $airportId . '_' . $camIndex . '.jpg';
    
    // Skip if already exists
    if (file_exists($cacheJpeg)) {
        return true;
    }
    
    // Get source capture time before starting generation
    $captureTime = getSourceCaptureTime($sourceFile);
    
    // Build ffmpeg command
    $cmdJpeg = sprintf(
        "ffmpeg -hide_banner -loglevel error -y -i %s -q:v 2 %s",
        escapeshellarg($sourceFile),
        escapeshellarg($cacheJpeg)
    );
    
    // Chain mtime sync after generation (only if capture time available)
    if ($captureTime > 0) {
        $dateStr = date('YmdHis', $captureTime);
        $cmdSync = sprintf("touch -t %s %s", $dateStr, escapeshellarg($cacheJpeg));
        $cmd = $cmdJpeg . ' && ' . $cmdSync;
    } else {
        $cmd = $cmdJpeg;
    }
    
    // Run in background (non-blocking)
    if (function_exists('exec')) {
        $cmd = $cmd . ' > /dev/null 2>&1 &';
        @exec($cmd);
        return true;
    }
    
    return false;
}

