<?php
/**
 * Partner Logo Server
 * 
 * Serves cached partner logos with appropriate cache headers.
 * Falls back to placeholder if logo unavailable.
 */

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

// Validate URL
if (!filter_var($logoUrl, FILTER_VALIDATE_URL)) {
    http_response_code(400);
    header('Content-Type: text/plain');
    echo 'Invalid URL';
    exit;
}

// Get cached logo path
$cacheFile = getPartnerLogoCachePath($logoUrl);

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

