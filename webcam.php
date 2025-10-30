<?php
/**
 * Webcam Image Fetcher
 * Fetches and caches webcam images from MJPEG streams
 */

// Optional format parameter: jpg (default), webp, avif
$fmt = isset($_GET['fmt']) ? strtolower($_GET['fmt']) : 'jpg';
if (!in_array($fmt, ['jpg', 'jpeg', 'webp', 'avif'])) { $fmt = 'jpg'; }

// Get parameters
$airportId = $_GET['id'] ?? '';
$camIndex = intval($_GET['cam'] ?? 0);

if (empty($airportId)) {
    // Serve placeholder
    readfile('placeholder.jpg');
    exit;
}

// Load config (supports CONFIG_PATH)
$envConfigPath = getenv('CONFIG_PATH');
$configFile = ($envConfigPath && file_exists($envConfigPath)) ? $envConfigPath : (__DIR__ . '/airports.json');
if (!file_exists($configFile)) {
    http_response_code(500);
    die('Configuration file not found');
}

$config = json_decode(file_get_contents($configFile), true);
if (json_last_error() !== JSON_ERROR_NONE || !$config) {
    http_response_code(500);
    die('Invalid configuration file');
}

if (!isset($config['airports'][$airportId]['webcams'][$camIndex])) {
    readfile('placeholder.jpg');
    exit;
}

$cam = $config['airports'][$airportId]['webcams'][$camIndex];
$cacheDir = __DIR__ . '/cache/webcams';
$base = $cacheDir . '/' . $airportId . '_' . $camIndex;
$cacheJpg = $base . '.jpg';
$cacheWebp = $base . '.webp';
$cacheAvif = $base . '.avif';

// Create cache directory if it doesn't exist
if (!is_dir($cacheDir)) {
    mkdir($cacheDir, 0755, true);
}

// Determine refresh threshold
$defaultWebcamRefresh = getenv('WEBCAM_REFRESH_DEFAULT') !== false ? intval(getenv('WEBCAM_REFRESH_DEFAULT')) : 60;
$airportWebcamRefresh = isset($config['airports'][$airportId]['webcam_refresh_seconds']) ? intval($config['airports'][$airportId]['webcam_refresh_seconds']) : $defaultWebcamRefresh;
$perCamRefresh = isset($cam['refresh_seconds']) ? intval($cam['refresh_seconds']) : $airportWebcamRefresh;

// Pick target file by requested format with fallback to jpg
$targetFile = $fmt === 'avif' ? $cacheAvif : ($fmt === 'webp' ? $cacheWebp : $cacheJpg);
if (!file_exists($targetFile)) { $targetFile = $cacheJpg; }

// Serve cached file if fresh
if (file_exists($targetFile) && (time() - filemtime($targetFile)) < $perCamRefresh) {
    $ctype = (substr($targetFile, -5) === '.avif') ? 'image/avif' : ((substr($targetFile, -5) === '.webp') ? 'image/webp' : 'image/jpeg');
    header('Content-Type: ' . $ctype);
    readfile($targetFile);
    exit;
}

// If no cache, return a simple error message as text
// We don't want to fetch MJPEG on every page load - that should be done via cron
if (!file_exists($cacheJpg)) {
    header('Content-Type: text/plain');
    echo "Webcam image not yet cached. Please wait for the cron job to refresh images.";
    exit;
}

// Fetch new image (only if cache is old and doesn't exist or is stale)
// We skip this on-demand fetch to avoid timeouts - use fetch-webcam.php via cron instead
if (!file_exists($cacheJpg)) {
    // No cache file exists yet - serve placeholder and exit
    // Don't try to fetch during page load - this should be handled by cron
    header('Content-Type: image/jpeg');
    if (file_exists('placeholder.jpg')) {
        readfile('placeholder.jpg');
    } else {
        // Create a simple 1x1 pixel as fallback
        header('Content-Type: image/png');
        echo base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNk+M9QDwADhgGAWjR9awAAAABJRU5ErkJggg==');
    }
    exit;
}

// Serve cached image (best available format)
$serveFile = file_exists($targetFile) ? $targetFile : $cacheJpg;
$ctype = (substr($serveFile, -5) === '.avif') ? 'image/avif' : ((substr($serveFile, -5) === '.webp') ? 'image/webp' : 'image/jpeg');
header('Content-Type: ' . $ctype);
readfile($serveFile);

