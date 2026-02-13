<?php
/**
 * Partner Logo Caching Utility
 * 
 * Downloads and caches partner logos locally with long TTL (30 days).
 * Supports JPEG and PNG formats, converts PNG to JPEG for consistency.
 * Uses atomic file operations to prevent corruption.
 */

require_once __DIR__ . '/constants.php';
require_once __DIR__ . '/logger.php';

/**
 * Get cache directory for partner logos
 * 
 * @return string Cache directory path
 */
function getPartnerLogoCacheDir(): string {
    $cacheDir = __DIR__ . '/../cache/partners';
    if (!is_dir($cacheDir)) {
        @mkdir($cacheDir, 0755, true);
    }
    return $cacheDir;
}

/**
 * Generate cache filename from logo URL
 * 
 * @param string $logoUrl Logo URL
 * @return string Cache file path
 */
function getPartnerLogoCacheFile(string $logoUrl): string {
    $cacheDir = getPartnerLogoCacheDir();
    $hash = md5($logoUrl);
    
    // Try to extract extension from URL
    $ext = 'jpg'; // default
    $parsed = parse_url($logoUrl);
    if (isset($parsed['path'])) {
        $pathExt = strtolower(pathinfo($parsed['path'], PATHINFO_EXTENSION));
        if (in_array($pathExt, ['jpg', 'jpeg', 'png', 'gif', 'webp'])) {
            $ext = ($pathExt === 'jpeg') ? 'jpg' : $pathExt;
        }
    }
    
    return $cacheDir . '/' . $hash . '.' . $ext;
}

/**
 * Check if cached logo is fresh
 * 
 * @param string $cacheFile Cache file path
 * @return bool True if cache is fresh (within TTL)
 */
function isPartnerLogoCacheFresh(string $cacheFile): bool {
    if (!file_exists($cacheFile)) {
        return false;
    }
    
    $age = time() - filemtime($cacheFile);
    return $age < PARTNER_LOGO_CACHE_TTL;
}

/**
 * Download and cache partner logo
 * 
 * Downloads logo from URL, validates it's an image, and saves to cache.
 * Converts PNG to JPEG for consistency. Uses atomic file operations.
 * 
 * @param string $logoUrl Logo URL to download
 * @return bool True on success, false on failure
 */
function downloadPartnerLogo(string $logoUrl): bool {
    $cacheFile = getPartnerLogoCacheFile($logoUrl);
    
    // Check if already cached and fresh
    if (isPartnerLogoCacheFresh($cacheFile)) {
        return true;
    }
    
    // Download logo
    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL => $logoUrl,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => CURL_TIMEOUT,
        CURLOPT_CONNECTTIMEOUT => CURL_CONNECT_TIMEOUT,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_MAXREDIRS => 3,
        CURLOPT_USERAGENT => 'AviationWX Partner Logo Bot',
        CURLOPT_MAXFILESIZE => CACHE_FILE_MAX_SIZE,
    ]);
    
    $data = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error = curl_error($ch);
    
    if ($error) {
        aviationwx_log('warning', 'partner logo download failed', [
            'url' => $logoUrl,
            'error' => $error
        ], 'app');
        return false;
    }
    
    if ($httpCode !== 200 || !$data || strlen($data) < 100) {
        aviationwx_log('warning', 'partner logo download invalid response', [
            'url' => $logoUrl,
            'http_code' => $httpCode,
            'size' => strlen($data ?? '')
        ], 'app');
        return false;
    }
    
    // Validate and process image
    $tmpFile = getUniqueTmpFile($cacheFile);
    
    // Check if JPEG
    if (strpos($data, "\xff\xd8") === 0) {
        // JPEG - write directly
        if (@file_put_contents($tmpFile, $data) !== false) {
            if (@rename($tmpFile, $cacheFile)) {
                aviationwx_log('info', 'partner logo cached', [
                    'url' => $logoUrl,
                    'cache_file' => basename($cacheFile)
                ], 'app');
                return true;
            } else {
                @unlink($tmpFile);
            }
        }
    } elseif (strpos($data, "\x89PNG") === 0) {
        // PNG - convert to JPEG
        if (function_exists('imagecreatefromstring') && function_exists('imagejpeg')) {
            $img = @imagecreatefromstring($data);
            if ($img) {
                if (@imagejpeg($img, $tmpFile, 85)) {
                    if (@rename($tmpFile, $cacheFile)) {
                        aviationwx_log('info', 'partner logo cached (PNG converted)', [
                            'url' => $logoUrl,
                            'cache_file' => basename($cacheFile)
                        ], 'app');
                        return true;
                    } else {
                        @unlink($tmpFile);
                    }
                } else {
                }
            }
        }
    } else {
        aviationwx_log('warning', 'partner logo unsupported format', [
            'url' => $logoUrl,
            'header' => substr($data, 0, 4)
        ], 'app');
    }
    
    return false;
}

/**
 * Get unique temporary file path
 * 
 * @param string $targetFile Target file path
 * @return string Temporary file path
 */
function getUniqueTmpFile(string $targetFile): string {
    $dir = dirname($targetFile);
    $base = basename($targetFile);
    return $dir . '/.' . $base . '.' . uniqid('tmp_', true);
}

/**
 * Get cached logo path (downloads if needed)
 * 
 * Returns path to cached logo file. If cache is stale or missing,
 * triggers background download (non-blocking).
 * 
 * @param string $logoUrl Logo URL
 * @return string|null Cache file path if available, null otherwise
 */
function getPartnerLogoCachePath(string $logoUrl): ?string {
    $cacheFile = getPartnerLogoCacheFile($logoUrl);
    
    // If cache is fresh, return it
    if (isPartnerLogoCacheFresh($cacheFile) && file_exists($cacheFile)) {
        return $cacheFile;
    }
    
    // Try to download (blocking for first request, but fast)
    // In production, this could be done in background
    if (downloadPartnerLogo($logoUrl)) {
        return $cacheFile;
    }
    
    // If download failed but we have stale cache, return it
    if (file_exists($cacheFile)) {
        return $cacheFile;
    }
    
    return null;
}

