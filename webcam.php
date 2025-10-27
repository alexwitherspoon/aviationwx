<?php
/**
 * Webcam Image Fetcher
 * Fetches and caches webcam images from MJPEG streams
 */

header('Content-Type: image/jpeg');

// Get parameters
$airportId = $_GET['id'] ?? '';
$camIndex = intval($_GET['cam'] ?? 0);

if (empty($airportId)) {
    // Serve placeholder
    readfile('placeholder.jpg');
    exit;
}

// Load config
$configFile = __DIR__ . '/airports.json';
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
$cacheFile = $cacheDir . '/' . $airportId . '_' . $camIndex . '.jpg';

// Create cache directory if it doesn't exist
if (!is_dir($cacheDir)) {
    mkdir($cacheDir, 0755, true);
}

// Check if cached image exists and is recent (less than 60 seconds old)
if (file_exists($cacheFile) && (time() - filemtime($cacheFile)) < 60) {
    readfile($cacheFile);
    exit;
}

// If no cache, return a simple error message as text
// We don't want to fetch MJPEG on every page load - that should be done via cron
if (!file_exists($cacheFile)) {
    header('Content-Type: text/plain');
    echo "Webcam image not yet cached. Please wait for the cron job to refresh images.";
    exit;
}

// Fetch new image (only if cache is old and doesn't exist or is stale)
// We skip this on-demand fetch to avoid timeouts - use fetch-webcam.php via cron instead
if (!file_exists($cacheFile)) {
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

// Serve cached image
readfile($cacheFile);

