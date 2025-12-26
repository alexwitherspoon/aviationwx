<?php
/**
 * Mock Webcam Image Generator
 * 
 * Generates placeholder webcam images for development without real webcam sources.
 * Used when shouldMockExternalServices() returns true.
 * 
 * ## When Used
 * - Local development without access to production webcam URLs
 * - Testing with airports.json.example (example.com URLs)
 * - CI environment where external webcams are unavailable
 * 
 * ## Features
 * - Generates identifiable placeholder with airport ID and camera index
 * - Includes timestamp for debugging refresh cycles
 * - Uses consistent styling for easy identification
 * 
 * ## Related Files
 * - lib/config.php: shouldMockExternalServices() determines when to use mocks
 * - lib/test-mocks.php: HTTP response mocking for weather APIs
 * - scripts/fetch-webcam.php: Calls generateMockWebcamImage() when mocking
 */

require_once __DIR__ . '/config.php';

/**
 * Generate a mock webcam placeholder image
 * 
 * Creates a colored placeholder image with airport ID, camera index,
 * and timestamp overlaid. Useful for development and testing.
 * 
 * @param string $airportId Airport identifier (e.g., 'kspb')
 * @param int $camIndex Camera index (0-based)
 * @param int $width Image width in pixels (default: 640)
 * @param int $height Image height in pixels (default: 480)
 * @return string JPEG image data as binary string
 */
function generateMockWebcamImage(string $airportId, int $camIndex, int $width = 640, int $height = 480): string {
    // Create image
    $img = imagecreatetruecolor($width, $height);
    if ($img === false) {
        // Fallback: return minimal valid JPEG if GD fails
        return getMinimalJpeg();
    }
    
    // Colors - use a gradient background for visual interest
    $bgTop = imagecolorallocate($img, 40, 60, 90);      // Dark blue
    $bgBottom = imagecolorallocate($img, 60, 80, 110);  // Slightly lighter
    $textColor = imagecolorallocate($img, 255, 255, 255);
    $subTextColor = imagecolorallocate($img, 180, 200, 220);
    $accentColor = imagecolorallocate($img, 100, 180, 255);
    
    // Draw gradient background
    for ($y = 0; $y < $height; $y++) {
        $ratio = $y / $height;
        $r = (int)(40 + (60 - 40) * $ratio);
        $g = (int)(60 + (80 - 60) * $ratio);
        $b = (int)(90 + (110 - 90) * $ratio);
        $lineColor = imagecolorallocate($img, $r, $g, $b);
        imageline($img, 0, $y, $width, $y, $lineColor);
    }
    
    // Draw decorative elements
    // Runway-like lines
    $lineColor = imagecolorallocate($img, 80, 100, 130);
    imageline($img, 50, $height - 80, $width - 50, $height - 80, $lineColor);
    imageline($img, 100, $height - 60, $width - 100, $height - 60, $lineColor);
    
    // Draw border
    imagerectangle($img, 0, 0, $width - 1, $height - 1, $accentColor);
    imagerectangle($img, 2, 2, $width - 3, $height - 3, $lineColor);
    
    // Main text - Airport ID
    $mainText = strtoupper($airportId) . " - CAM " . ($camIndex + 1);
    $mainTextWidth = imagefontwidth(5) * strlen($mainText);
    $mainTextX = ($width - $mainTextWidth) / 2;
    imagestring($img, 5, (int)$mainTextX, (int)($height / 2 - 30), $mainText, $textColor);
    
    // Subtitle - Mock mode indicator
    $subText = "MOCK MODE";
    $subTextWidth = imagefontwidth(4) * strlen($subText);
    $subTextX = ($width - $subTextWidth) / 2;
    imagestring($img, 4, (int)$subTextX, (int)($height / 2), $subText, $accentColor);
    
    // Timestamp
    $timestamp = date('Y-m-d H:i:s');
    $timestampWidth = imagefontwidth(3) * strlen($timestamp);
    $timestampX = ($width - $timestampWidth) / 2;
    imagestring($img, 3, (int)$timestampX, (int)($height / 2 + 25), $timestamp, $subTextColor);
    
    // Footer text
    $footerText = "Development Placeholder";
    $footerWidth = imagefontwidth(2) * strlen($footerText);
    $footerX = ($width - $footerWidth) / 2;
    imagestring($img, 2, (int)$footerX, $height - 30, $footerText, $subTextColor);
    
    // Output as JPEG
    ob_start();
    imagejpeg($img, null, 85);
    $data = ob_get_clean();
    imagedestroy($img);
    
    return $data ?: getMinimalJpeg();
}

/**
 * Get a minimal valid JPEG image
 * 
 * Returns the smallest valid JPEG for cases where GD is unavailable.
 * This is a 1x1 pixel gray image.
 * 
 * @return string Minimal valid JPEG binary data
 */
function getMinimalJpeg(): string {
    // Minimal valid JPEG (1x1 gray pixel)
    return "\xff\xd8\xff\xe0\x00\x10JFIF\x00\x01\x01\x01\x00H\x00H\x00\x00" .
           "\xff\xdb\x00C\x00\x08\x06\x06\x07\x06\x05\x08\x07\x07\x07\t\t" .
           "\x08\n\x0c\x14\r\x0c\x0b\x0b\x0c\x19\x12\x13\x0f\x14\x1d\x1a" .
           "\x1f\x1e\x1d\x1a\x1c\x1c \$.' \",#\x1c\x1c(7),01444\x1f'9=82telefonía" .
           "\xff\xc0\x00\x11\x08\x00\x01\x00\x01\x01\x01\x11\x00\x02\x11\x01" .
           "\x03\x11\x01\xff\xc4\x00\x14\x00\x01\x00\x00\x00\x00\x00\x00\x00" .
           "\x00\x00\x00\x00\x00\x00\x00\x00\x08\xff\xc4\x00\x14\x10\x01\x00" .
           "\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00\xff" .
           "\xda\x00\x08\x01\x01\x00\x00?\x00\xff\xd9";
}

/**
 * Save mock webcam image to cache
 * 
 * Convenience function to generate and save a mock webcam image.
 * 
 * @param string $airportId Airport identifier
 * @param int $camIndex Camera index
 * @param string $cacheDir Cache directory path
 * @return bool True if saved successfully
 */
function saveMockWebcamImage(string $airportId, int $camIndex, string $cacheDir): bool {
    $imageData = generateMockWebcamImage($airportId, $camIndex);
    
    // Ensure cache directory exists
    $webcamDir = rtrim($cacheDir, '/') . '/webcams';
    if (!is_dir($webcamDir)) {
        if (!@mkdir($webcamDir, 0755, true)) {
            return false;
        }
    }
    
    // Generate filename
    $filename = sprintf('%s_%d.jpg', strtolower($airportId), $camIndex);
    $filepath = $webcamDir . '/' . $filename;
    
    // Write atomically (temp file + rename)
    $tempFile = $filepath . '.tmp.' . getmypid();
    if (@file_put_contents($tempFile, $imageData) === false) {
        return false;
    }
    
    if (!@rename($tempFile, $filepath)) {
        @unlink($tempFile);
        return false;
    }
    
    return true;
}

