<?php
/**
 * Partner Logo Server
 * 
 * Serves partner logos with appropriate cache headers.
 * Supports both remote URLs (downloaded and cached) and local paths.
 * Falls back to placeholder if logo unavailable.
 */

// Clean any output buffering that might interfere
if (ob_get_level() > 0) {
    @ob_end_clean();
}

require_once __DIR__ . '/../lib/partner-logo-cache.php';
require_once __DIR__ . '/../lib/constants.php';

// Get logo URL from query parameter
$logoUrl = $_GET['url'] ?? '';

if (empty($logoUrl)) {
    http_response_code(400);
    header('Content-Type: text/plain');
    echo 'Missing url parameter';
    exit;
}

// Check if this is a local path or remote URL
$isLocalPath = strpos($logoUrl, '/') === 0;

if ($isLocalPath) {
    // Local path - serve directly from filesystem
    // Security: Validate path to prevent directory traversal
    
    // Validate file extension first (before checking filesystem)
    $ext = strtolower(pathinfo($logoUrl, PATHINFO_EXTENSION));
    if (!in_array($ext, ['jpg', 'jpeg', 'png', 'gif', 'webp', 'svg'])) {
        http_response_code(400);
        header('Content-Type: text/plain');
        echo 'Invalid file type';
        exit;
    }
    
    // Check for directory traversal attempts in the raw path
    if (strpos($logoUrl, '..') !== false || strpos($logoUrl, "\0") !== false) {
        http_response_code(400);
        header('Content-Type: text/plain');
        echo 'Invalid path';
        exit;
    }
    
    // Build the full path
    $realBase = realpath(__DIR__ . '/..');
    $fullPath = __DIR__ . '/..' . $logoUrl;
    
    // Check if file exists
    if (!file_exists($fullPath)) {
        http_response_code(404);
        header('Content-Type: image/png');
        header('Cache-Control: public, max-age=3600');
        // 1x1 transparent PNG
        echo base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNk+M9QDwADhgGAWjR9awAAAABJRU5ErkJggg==');
        exit;
    }
    
    // Now use realpath on the existing file to validate it's within web root
    $requestedPath = realpath($fullPath);
    if ($requestedPath === false || strpos($requestedPath, $realBase) !== 0) {
        http_response_code(400);
        header('Content-Type: text/plain');
        echo 'Invalid path';
        exit;
    }
    
    // Serve local file directly
    $cacheFile = $requestedPath;
} else {
    // Remote URL - validate and use caching
    if (!filter_var($logoUrl, FILTER_VALIDATE_URL)) {
        http_response_code(400);
        header('Content-Type: text/plain');
        echo 'Invalid URL';
        exit;
    }
    
    // Get cached logo path
    $cacheFile = getPartnerLogoCachePath($logoUrl);
}

if ($cacheFile === null || !file_exists($cacheFile)) {
    // Serve placeholder or 404
    http_response_code(404);
    header('Content-Type: image/png');
    header('Cache-Control: public, max-age=3600');
    // 1x1 transparent PNG
    echo base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNk+M9QDwADhgGAWjR9awAAAABJRU5ErkJggg==');
    exit;
}

// Determine content type
$ext = strtolower(pathinfo($cacheFile, PATHINFO_EXTENSION));
$contentType = 'image/jpeg';
if ($ext === 'png') {
    $contentType = 'image/png';
} elseif ($ext === 'gif') {
    $contentType = 'image/gif';
} elseif ($ext === 'webp') {
    $contentType = 'image/webp';
} elseif ($ext === 'svg') {
    $contentType = 'image/svg+xml';
}

// Check if cache is fresh
$mtime = filemtime($cacheFile);
$age = time() - $mtime;
$remainingTime = max(0, PARTNER_LOGO_CACHE_TTL - $age);

// Set cache headers
if ($remainingTime > 0) {
    header('Cache-Control: public, max-age=' . $remainingTime . ', immutable');
    header('Expires: ' . gmdate('D, d M Y H:i:s', time() + $remainingTime) . ' GMT');
} else {
    // Stale but serve it anyway, refresh in background
    header('Cache-Control: public, max-age=0, must-revalidate');
}

header('Content-Type: ' . $contentType);
header('Last-Modified: ' . gmdate('D, d M Y H:i:s', $mtime) . ' GMT');
header('X-Cache-Status: ' . ($remainingTime > 0 ? 'HIT' : 'STALE'));

// Conditional requests
$ifModSince = $_SERVER['HTTP_IF_MODIFIED_SINCE'] ?? '';
if ($ifModSince && strtotime($ifModSince) >= $mtime) {
    http_response_code(304);
    exit;
}

// Serve file
$size = filesize($cacheFile);
if ($size > 0) {
    header('Content-Length: ' . $size);
}

readfile($cacheFile);

