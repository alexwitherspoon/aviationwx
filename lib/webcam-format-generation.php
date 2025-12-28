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
 * @param string $airportId Airport ID (e.g., 'kspb')
 * @param int $camIndex Camera index (0-based)
 * @param string $format Format extension (jpg, webp, avif)
 * @return string Staging file path with .tmp suffix
 */
function getStagingFilePath(string $airportId, int $camIndex, string $format): string {
    $cacheDir = __DIR__ . '/../cache/webcams';
    return $cacheDir . '/' . $airportId . '_' . $camIndex . '.' . $format . '.tmp';
}

/**
 * Get timestamp-based cache file path
 * 
 * Files are stored with timestamp in filename for immutability and cache busting.
 * Format: {timestamp}.{format} (e.g., 1703700000.jpg)
 * 
 * @param int $timestamp Unix timestamp
 * @param string $format Format extension (jpg, webp, avif)
 * @return string Timestamp-based cache file path
 */
function getTimestampCacheFilePath(int $timestamp, string $format): string {
    $cacheDir = __DIR__ . '/../cache/webcams';
    return $cacheDir . '/' . $timestamp . '.' . $format;
}

/**
 * Get symlink path for current cache file
 * 
 * Symlink points to latest timestamp-based file for easy lookup.
 * Format: {airport}_{camIndex}.{format} (e.g., kspb_0.jpg)
 * 
 * @param string $airportId Airport ID (e.g., 'kspb')
 * @param int $camIndex Camera index (0-based)
 * @param string $format Format extension (jpg, webp, avif)
 * @return string Symlink path
 */
function getCacheSymlinkPath(string $airportId, int $camIndex, string $format): string {
    $cacheDir = __DIR__ . '/../cache/webcams';
    return $cacheDir . '/' . $airportId . '_' . $camIndex . '.' . $format;
}

/**
 * Get final cache file path for a format (timestamp-based)
 * 
 * @param string $airportId Airport ID (e.g., 'kspb')
 * @param int $camIndex Camera index (0-based)
 * @param string $format Format extension (jpg, webp, avif)
 * @param int $timestamp Unix timestamp for the image
 * @return string Final timestamp-based cache file path
 */
function getFinalFilePath(string $airportId, int $camIndex, string $format, int $timestamp): string {
    return getTimestampCacheFilePath($timestamp, $format);
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
    $cacheDir = __DIR__ . '/../cache/webcams';
    $pattern = $cacheDir . '/' . $airportId . '_' . $camIndex . '.*.tmp';
    
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
 * Cleanup old format cache files (migration helper)
 * 
 * Removes old format files ({airport}_{camIndex}.{format}) that are not symlinks.
 * These are legacy files from before timestamp-based naming was introduced.
 * 
 * @param string $airportId Airport ID (e.g., 'kspb')
 * @param int $camIndex Camera index (0-based)
 * @return int Number of files cleaned up
 */
function cleanupOldFormatFiles(string $airportId, int $camIndex): int {
    $cacheDir = __DIR__ . '/../cache/webcams';
    $pattern = $cacheDir . '/' . $airportId . '_' . $camIndex . '.*';
    
    $files = glob($pattern);
    if ($files === false || empty($files)) {
        return 0;
    }
    
    $cleaned = 0;
    foreach ($files as $file) {
        // Skip symlinks (they're the new format, pointing to timestamp files)
        if (is_link($file)) {
            continue;
        }
        
        // Skip staging files (handled separately)
        if (strpos(basename($file), '.tmp') !== false) {
            continue;
        }
        
        // Only remove old format files (not timestamp-based)
        $basename = basename($file);
        // Old format: {airport}_{camIndex}.{format} (e.g., kspb_0.jpg)
        // New format: {timestamp}.{format} (e.g., 1703700000.jpg)
        if (preg_match('/^' . preg_quote($airportId, '/') . '_' . $camIndex . '\.(jpg|webp|avif)$/', $basename)) {
            if (@unlink($file)) {
                $cleaned++;
            }
        }
    }
    
    if ($cleaned > 0) {
        aviationwx_log('debug', 'webcam old format cleanup', [
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
    $cacheDir = __DIR__ . '/../cache/webcams';
    
    // Get all timestamp-based files (format: {timestamp}.{format})
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
        // Match timestamp-based filename: "1703700000.jpg" (numeric timestamp)
        if (preg_match('/^(\d+)\.(jpg|webp|avif)$/', $basename, $matches)) {
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
    $symlinks = glob($cacheDir . '/*_*.*');
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
            $cmd = sprintf(
                "nice -n -1 ffmpeg -hide_banner -loglevel error -y -i %s -frames:v 1 -q:v 30 -compression_level 6 -preset default %s",
                escapeshellarg($sourceFile),
                escapeshellarg($destFile)
            );
            break;
            
        case 'avif':
            $cmd = sprintf(
                "nice -n -1 ffmpeg -hide_banner -loglevel error -y -i %s -frames:v 1 -c:v libaom-av1 -crf 30 -b:v 0 -cpu-used 4 %s",
                escapeshellarg($sourceFile),
                escapeshellarg($destFile)
            );
            break;
            
        case 'jpg':
        default:
            $cmd = sprintf(
                "ffmpeg -hide_banner -loglevel error -y -i %s -q:v 2 %s",
                escapeshellarg($sourceFile),
                escapeshellarg($destFile)
            );
            break;
    }
    
    // Chain mtime sync after generation (only if capture time available)
    if ($captureTime > 0) {
        $dateStr = date('YmdHis', $captureTime);
        $cmdSync = sprintf("touch -t %s %s", $dateStr, escapeshellarg($destFile));
        $cmd = $cmd . ' && ' . $cmdSync;
    }
    
    // Chain EXIF copy to preserve metadata in generated format (if exiftool available)
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
        'formats_to_generate' => $formatsToGenerate,
        'timeout_seconds' => $timeout
    ], 'app');
    
    // Start all format generation processes in parallel
    foreach ($formatsToGenerate as $format) {
        $destFile = getStagingFilePath($airportId, $camIndex, $format);
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
                        'size_bytes' => filesize($proc['dest'])
                    ], 'app');
                } else {
                    aviationwx_log('warning', 'webcam format generation failed', [
                        'airport' => $airportId,
                        'cam' => $camIndex,
                        'format' => $format,
                        'exit_code' => $exitCode,
                        'duration_ms' => $elapsed,
                        'stderr' => substr($stderr, 0, 200)
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
 * Create or update symlink to point to timestamp-based file
 * 
 * Atomically updates symlink by creating new symlink then renaming.
 * 
 * @param string $symlinkPath Path to symlink (e.g., kspb_0.jpg)
 * @param string $targetPath Path to target file (e.g., 1703700000.jpg)
 * @return bool True on success, false on failure
 */
function updateCacheSymlink(string $symlinkPath, string $targetPath): bool {
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
        $sourceStagingFile = getStagingFilePath($airportId, $camIndex, $sourceFormat);
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
        
        $stagingFile = getStagingFilePath($airportId, $camIndex, $format);
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
    
    // Chain EXIF copy to preserve metadata in generated format (if exiftool available)
    if (isExiftoolAvailable()) {
        $cmdExif = sprintf(
            "exiftool -overwrite_original -q -P -TagsFromFile %s -all:all %s",
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
    
    // Chain EXIF copy to preserve metadata in generated format (if exiftool available)
    if (isExiftoolAvailable()) {
        $cmdExif = sprintf(
            "exiftool -overwrite_original -q -P -TagsFromFile %s -all:all %s",
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
    
    // Chain EXIF copy to preserve metadata in generated format (if exiftool available)
    if (isExiftoolAvailable()) {
        $cmdExif = sprintf(
            "exiftool -overwrite_original -q -P -TagsFromFile %s -all:all %s",
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

