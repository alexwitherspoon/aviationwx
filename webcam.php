<?php
/**
 * Webcam Image Fetcher
 * Fetches and caches webcam images from MJPEG streams
 */

require_once __DIR__ . '/config-utils.php';
require_once __DIR__ . '/rate-limit.php';

/**
 * Serve placeholder image
 */
function servePlaceholder() {
    if (file_exists(__DIR__ . '/placeholder.jpg')) {
        header('Content-Type: image/jpeg');
        header('Cache-Control: public, max-age=3600'); // Cache placeholder for 1 hour
        readfile(__DIR__ . '/placeholder.jpg');
    } else {
        header('Content-Type: image/png');
        header('Cache-Control: public, max-age=3600');
        echo base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNk+M9QDwADhgGAWjR9awAAAABJRU5ErkJggg==');
    }
    exit;
}

// Rate limiting (100 requests per minute per IP for images)
if (!checkRateLimit('webcam_api', 100, 60)) {
    http_response_code(429);
    servePlaceholder();
}

// Optional format parameter: jpg (default), webp, avif
$fmt = isset($_GET['fmt']) ? strtolower(trim($_GET['fmt'])) : 'jpg';
if (!in_array($fmt, ['jpg', 'jpeg', 'webp', 'avif'])) { 
    $fmt = 'jpg'; 
}

// Get and validate parameters
$rawAirportId = $_GET['id'] ?? '';
$camIndex = isset($_GET['cam']) ? intval($_GET['cam']) : 0;

if (empty($rawAirportId) || !validateAirportId($rawAirportId)) {
    servePlaceholder();
}

$airportId = strtolower(trim($rawAirportId));

// Validate cam index is non-negative
if ($camIndex < 0) {
    $camIndex = 0;
}

// Load config (with caching)
$config = loadConfig();
if ($config === null || !isset($config['airports'][$airportId]['webcams'][$camIndex])) {
    servePlaceholder();
}

$cam = $config['airports'][$airportId]['webcams'][$camIndex];
$cacheDir = __DIR__ . '/cache/webcams';
$base = $cacheDir . '/' . $airportId . '_' . $camIndex;
$cacheJpg = $base . '.jpg';
$cacheWebp = $base . '.webp';
$cacheAvif = $base . '.avif';

// Create cache directory if it doesn't exist
if (!is_dir($cacheDir)) {
    @mkdir($cacheDir, 0755, true);
}

// Determine refresh threshold
$defaultWebcamRefresh = getenv('WEBCAM_REFRESH_DEFAULT') !== false ? intval(getenv('WEBCAM_REFRESH_DEFAULT')) : 60;
$airportWebcamRefresh = isset($config['airports'][$airportId]['webcam_refresh_seconds']) ? intval($config['airports'][$airportId]['webcam_refresh_seconds']) : $defaultWebcamRefresh;
$perCamRefresh = isset($cam['refresh_seconds']) ? intval($cam['refresh_seconds']) : $airportWebcamRefresh;

// Pick target file by requested format with fallback to jpg
$targetFile = $fmt === 'avif' ? $cacheAvif : ($fmt === 'webp' ? $cacheWebp : $cacheJpg);
if (!file_exists($targetFile)) { 
    $targetFile = $cacheJpg; 
}

// Determine content type
$ctype = (substr($targetFile, -5) === '.avif') ? 'image/avif' : ((substr($targetFile, -5) === '.webp') ? 'image/webp' : 'image/jpeg');

// If no cache exists, serve placeholder
if (!file_exists($cacheJpg)) {
    servePlaceholder();
}

// Check if requesting timestamp only (for frontend to get latest mtime)
if (isset($_GET['mtime']) && $_GET['mtime'] === '1') {
    header('Content-Type: application/json');
    if (file_exists($cacheJpg)) {
        $mtime = filemtime($cacheJpg);
        echo json_encode(['timestamp' => $mtime, 'success' => true]);
    } else {
        echo json_encode(['timestamp' => 0, 'success' => false]);
    }
    exit;
}

// Serve cached file if fresh
if (file_exists($targetFile) && (time() - filemtime($targetFile)) < $perCamRefresh) {
    $age = time() - filemtime($targetFile);
    $remainingTime = $perCamRefresh - $age;
    $mtime = filemtime($targetFile);
    
    header('Content-Type: ' . $ctype);
    header('Cache-Control: public, max-age=' . $remainingTime);
    header('Expires: ' . gmdate('D, d M Y H:i:s', time() + $remainingTime) . ' GMT');
    header('Last-Modified: ' . gmdate('D, d M Y H:i:s', $mtime) . ' GMT');
    header('X-Cache-Status: HIT');
    header('X-Image-Timestamp: ' . $mtime); // Custom header for timestamp
    
    readfile($targetFile);
    exit;
}

// Cache expired or file not found - serve stale cache if available, otherwise placeholder
if (file_exists($targetFile)) {
    $mtime = filemtime($targetFile);
    header('Content-Type: ' . $ctype);
    header('Cache-Control: public, max-age=0, must-revalidate'); // Stale, revalidate
    header('Last-Modified: ' . gmdate('D, d M Y H:i:s', $mtime) . ' GMT');
    header('X-Cache-Status: STALE');
    header('X-Image-Timestamp: ' . $mtime); // Custom header for timestamp
    readfile($targetFile);
} else {
    servePlaceholder();
}

