<?php
/**
 * Generate Test Webcam History Data
 * 
 * Creates sample historical frames for local development and testing.
 * Files are created directly in the camera cache directory (unified storage).
 * 
 * Usage:
 *   php dev/generate-test-history.php [airport_id] [cam_index] [num_frames]
 * 
 * Examples:
 *   php dev/generate-test-history.php kspb 0 12
 *   php dev/generate-test-history.php kspb 0        # Uses default 12 frames
 *   php dev/generate-test-history.php               # Uses kspb, cam 0, 12 frames
 */

require_once __DIR__ . '/../lib/config.php';
require_once __DIR__ . '/../lib/cache-paths.php';
require_once __DIR__ . '/../lib/webcam-history.php';

// Parse command line arguments
$airportId = $argv[1] ?? 'kspb';
$camIndex = (int)($argv[2] ?? 0);
$numFrames = (int)($argv[3] ?? 12);

$maxFrames = getWebcamHistoryMaxFrames($airportId);

echo "=== Webcam History Test Data Generator ===\n\n";
echo "Airport:    {$airportId}\n";
echo "Camera:     {$camIndex}\n";
echo "Frames:     {$numFrames}\n";
echo "Max Frames: {$maxFrames}\n";
echo "History:    " . ($maxFrames >= 2 ? "ENABLED" : "DISABLED (max_frames < 2)") . "\n\n";

// Get camera cache directory (unified storage)
$cacheDir = getWebcamCameraDir($airportId, $camIndex);
echo "Cache dir: {$cacheDir}\n\n";

// Create cache directory if needed
if (!is_dir($cacheDir)) {
    if (!@mkdir($cacheDir, 0755, true)) {
        echo "ERROR: Failed to create cache directory\n";
        exit(1);
    }
    echo "Created cache directory\n";
}

// Check for source webcam image
$sourceFile = getCacheSymlinkPath($airportId, $camIndex, 'jpg');

// Create placeholder if no source exists
if (!file_exists($sourceFile)) {
    echo "No existing webcam image found. Creating placeholder...\n";
    
    // Ensure cache dir exists
    ensureCacheDir($cacheDir);
    
    // Create a simple test image
    $img = imagecreatetruecolor(1600, 900);
    
    // Dark blue gradient background
    for ($y = 0; $y < 900; $y++) {
        $color = imagecolorallocate($img, 20, 30 + ($y / 900 * 40), 60 + ($y / 900 * 30));
        imageline($img, 0, $y, 1600, $y, $color);
    }
    
    // Add some "runway" lines
    $lineColor = imagecolorallocate($img, 80, 80, 80);
    imagefilledrectangle($img, 400, 500, 1200, 520, $lineColor);
    
    // Add text
    $textColor = imagecolorallocate($img, 255, 255, 255);
    $shadowColor = imagecolorallocate($img, 0, 0, 0);
    
    $title = strtoupper($airportId) . " - Camera {$camIndex}";
    imagestring($img, 5, 702, 102, $title, $shadowColor);
    imagestring($img, 5, 700, 100, $title, $textColor);
    
    $subtitle = "Test Webcam Image";
    imagestring($img, 4, 722, 132, $subtitle, $shadowColor);
    imagestring($img, 4, 720, 130, $subtitle, $textColor);
    
    imagejpeg($img, $sourceFile, 85);
    
    echo "Created placeholder image: {$sourceFile}\n";
}

echo "\nGenerating {$numFrames} test frames...\n\n";

// Generate frames with intervals going back in time
$now = time();
$intervalSeconds = 60; // 1 minute between frames
$createdCount = 0;
$skippedCount = 0;

for ($i = 0; $i < $numFrames; $i++) {
    // Calculate timestamp (oldest to newest)
    $timestamp = $now - (($numFrames - 1 - $i) * $intervalSeconds);
    // Use date/hour subdir structure (matches pipeline)
    $framesDir = getWebcamFramesDir($airportId, $camIndex, $timestamp);
    if (!is_dir($framesDir)) {
        @mkdir($framesDir, 0755, true);
    }
    $destFile = $framesDir . '/' . $timestamp . '_original.jpg';
    
    if (file_exists($destFile)) {
        echo "  [{$i}] SKIP: " . date('H:i:s', $timestamp) . " (already exists)\n";
        $skippedCount++;
        continue;
    }
    
    // Load source image and modify it slightly
    $img = imagecreatefromjpeg($sourceFile);
    
    // Add timestamp overlay
    $textColor = imagecolorallocate($img, 255, 255, 0);
    $shadowColor = imagecolorallocate($img, 0, 0, 0);
    
    $dateStr = date('Y-m-d H:i:s', $timestamp);
    $frameLabel = "Frame " . ($i + 1) . " of {$numFrames}";
    
    // Shadow
    imagestring($img, 5, 22, 22, $dateStr, $shadowColor);
    imagestring($img, 4, 22, 42, $frameLabel, $shadowColor);
    
    // Text
    imagestring($img, 5, 20, 20, $dateStr, $textColor);
    imagestring($img, 4, 20, 40, $frameLabel, $textColor);
    
    // Add a moving element to show animation (simple bar)
    $progressWidth = (int)(($i / ($numFrames - 1)) * 200);
    $barColor = imagecolorallocate($img, 59, 130, 246);
    imagefilledrectangle($img, 20, 870, 20 + $progressWidth, 880, $barColor);
    
    // Save
    imagejpeg($img, $destFile, 85);
    
    echo "  [{$i}] OK:   " . date('H:i:s', $timestamp) . " -> " . basename($destFile) . "\n";
    $createdCount++;
}

echo "\n=== Summary ===\n";
echo "Created: {$createdCount} frames\n";
echo "Skipped: {$skippedCount} frames (already existed)\n";

// List all frames
$frames = getHistoryFrames($airportId, $camIndex);
echo "\nTotal frames in history: " . count($frames) . "\n";

if (count($frames) > 0) {
    echo "\nFrame range:\n";
    echo "  Oldest: " . date('Y-m-d H:i:s', $frames[0]['timestamp']) . "\n";
    echo "  Newest: " . date('Y-m-d H:i:s', $frames[count($frames) - 1]['timestamp']) . "\n";
    
    $totalSize = getHistoryDiskUsage($airportId, $camIndex);
    echo "\nDisk usage: " . number_format($totalSize / 1024, 1) . " KB\n";
}

echo "\n=== Test URLs ===\n";
echo "Manifest: /api/webcam-history.php?id={$airportId}&cam={$camIndex}\n";
if (count($frames) > 0) {
    echo "Frame:    /api/webcam-history.php?id={$airportId}&cam={$camIndex}&ts=" . $frames[0]['timestamp'] . "\n";
}

echo "\nDone!\n";

