<?php
/**
 * Test script for webcam error frame detector
 * 
 * Tests the error frame detection with sample images to verify:
 * - Legitimate nighttime images are NOT rejected
 * - Actual error frames ARE rejected
 * 
 * Usage: php scripts/test-error-detector.php [image_path]
 */

require_once __DIR__ . '/../lib/constants.php';
require_once __DIR__ . '/../lib/webcam-error-detector.php';

// Colors for terminal output
$GREEN = "\033[32m";
$RED = "\033[31m";
$YELLOW = "\033[33m";
$BLUE = "\033[34m";
$RESET = "\033[0m";
$BOLD = "\033[1m";

function printHeader($text) {
    global $BOLD, $BLUE, $RESET;
    echo "\n{$BOLD}{$BLUE}=== $text ==={$RESET}\n";
}

function printSuccess($text) {
    global $GREEN, $RESET;
    echo "{$GREEN}✓{$RESET} $text\n";
}

function printError($text) {
    global $RED, $RESET;
    echo "{$RED}✗{$RESET} $text\n";
}

function printWarning($text) {
    global $YELLOW, $RESET;
    echo "{$YELLOW}⚠{$RESET} $text\n";
}

function printInfo($text) {
    echo "  $text\n";
}

// Check if GD library is available
if (!function_exists('imagecreatefromstring')) {
    printError("GD library not available - cannot test error detection");
    exit(1);
}

// If image path provided, test that specific image
if (isset($argv[1])) {
    $imagePath = $argv[1];
    if (!file_exists($imagePath)) {
        printError("Image file not found: $imagePath");
        exit(1);
    }
    
    printHeader("Testing Image: $imagePath");
    
    $result = detectErrorFrame($imagePath);
    
    echo "\n";
    echo "Result: " . ($result['is_error'] ? "{$RED}ERROR FRAME{$RESET}" : "{$GREEN}VALID IMAGE{$RESET}") . "\n";
    echo "Confidence: " . round($result['confidence'] * 100, 1) . "%\n";
    echo "Error Score: " . round($result['error_score'], 3) . " (threshold: " . WEBCAM_ERROR_SCORE_THRESHOLD . ")\n";
    echo "Reasons: " . (empty($result['reasons']) ? "none" : implode(", ", $result['reasons'])) . "\n";
    
    exit($result['is_error'] ? 1 : 0);
}

// Otherwise, create test images and verify behavior
printHeader("Webcam Error Frame Detector Test");

// Test 1: Create a legitimate nighttime image (should NOT be rejected)
printHeader("Test 1: Legitimate Nighttime Image");
$nightImage = sys_get_temp_dir() . '/test_night_' . uniqid() . '.jpg';
$img = imagecreatetruecolor(200, 200);
if ($img) {
    // Create nighttime scene: dark background with some bright lights
    for ($y = 0; $y < 200; $y++) {
        for ($x = 0; $x < 200; $x++) {
            // Dark background (nighttime)
            $r = $g = $b = 30 + (($x + $y) % 10);
            
            // Add some bright spots (lights) - about 5% of image
            if (($x % 40 < 5 && $y % 40 < 5) || ($x > 80 && $x < 120 && $y > 80 && $y < 120)) {
                $r = $g = $b = 200 + (($x + $y) % 30);
            }
            
            $color = imagecolorallocate($img, $r, $g, $b);
            imagesetpixel($img, $x, $y, $color);
        }
    }
    imagejpeg($img, $nightImage, 85);
    imagedestroy($img);
    
    $result = detectErrorFrame($nightImage);
    if ($result['is_error']) {
        printError("Nighttime image incorrectly rejected!");
        printInfo("Error score: " . round($result['error_score'], 3));
        printInfo("Reasons: " . implode(", ", $result['reasons']));
        $nightTestPassed = false;
    } else {
        printSuccess("Nighttime image correctly accepted");
        printInfo("Error score: " . round($result['error_score'], 3) . " (below threshold " . WEBCAM_ERROR_SCORE_THRESHOLD . ")");
        $nightTestPassed = true;
    }
    @unlink($nightImage);
} else {
    printError("Failed to create test image");
    $nightTestPassed = false;
}

// Test 2: Create an error frame (should be rejected)
printHeader("Test 2: Error Frame (Uniform Grey)");
$errorImage = sys_get_temp_dir() . '/test_error_' . uniqid() . '.jpg';
$img = imagecreatetruecolor(200, 200);
if ($img) {
    // Create uniform grey error frame - very uniform, no variation
    for ($y = 0; $y < 200; $y++) {
        for ($x = 0; $x < 200; $x++) {
            // Very uniform grey (error frame characteristic)
            $r = $g = $b = 50 + (($x + $y) % 3); // Minimal variation
            $color = imagecolorallocate($img, $r, $g, $b);
            imagesetpixel($img, $x, $y, $color);
        }
    }
    imagejpeg($img, $errorImage, 85);
    imagedestroy($img);
    
    $result = detectErrorFrame($errorImage);
    if (!$result['is_error']) {
        printError("Error frame incorrectly accepted!");
        printInfo("Error score: " . round($result['error_score'], 3));
        printInfo("Reasons: " . implode(", ", $result['reasons']));
        $errorTestPassed = false;
    } else {
        printSuccess("Error frame correctly rejected");
        printInfo("Error score: " . round($result['error_score'], 3) . " (above threshold " . WEBCAM_ERROR_SCORE_THRESHOLD . ")");
        printInfo("Reasons: " . implode(", ", $result['reasons']));
        $errorTestPassed = true;
    }
    @unlink($errorImage);
} else {
    printError("Failed to create test image");
    $errorTestPassed = false;
}

// Test 3: Create a colorful normal image (should NOT be rejected)
printHeader("Test 3: Normal Colorful Image");
$colorImage = sys_get_temp_dir() . '/test_color_' . uniqid() . '.jpg';
$img = imagecreatetruecolor(200, 200);
if ($img) {
    // Create colorful image with good variance
    for ($y = 0; $y < 200; $y++) {
        for ($x = 0; $x < 200; $x++) {
            $r = ($x * 3) % 256;
            $g = ($y * 3) % 256;
            $b = (($x + $y) * 2) % 256;
            $color = imagecolorallocate($img, $r, $g, $b);
            imagesetpixel($img, $x, $y, $color);
        }
    }
    imagejpeg($img, $colorImage, 85);
    imagedestroy($img);
    
    $result = detectErrorFrame($colorImage);
    if ($result['is_error']) {
        printError("Colorful image incorrectly rejected!");
        printInfo("Error score: " . round($result['error_score'], 3));
        $colorTestPassed = false;
    } else {
        printSuccess("Colorful image correctly accepted");
        printInfo("Error score: " . round($result['error_score'], 3));
        $colorTestPassed = true;
    }
    @unlink($colorImage);
} else {
    printError("Failed to create test image");
    $colorTestPassed = false;
}

// Summary
printHeader("Test Summary");
if ($nightTestPassed && $errorTestPassed && $colorTestPassed) {
    printSuccess("All tests passed!");
    echo "\n";
    printInfo("Current thresholds:");
    printInfo("  Grey ratio: >" . (WEBCAM_ERROR_GREY_RATIO_THRESHOLD * 100) . "%");
    printInfo("  Dark ratio: >" . (WEBCAM_ERROR_DARK_RATIO_THRESHOLD * 100) . "%");
    printInfo("  Color variance: <" . WEBCAM_ERROR_COLOR_VARIANCE_THRESHOLD);
    printInfo("  Edge ratio: <" . (WEBCAM_ERROR_EDGE_RATIO_THRESHOLD * 100) . "%");
    printInfo("  Error score threshold: " . WEBCAM_ERROR_SCORE_THRESHOLD);
    exit(0);
} else {
    printError("Some tests failed");
    if (!$nightTestPassed) printError("  - Nighttime image test failed");
    if (!$errorTestPassed) printError("  - Error frame test failed");
    if (!$colorTestPassed) printError("  - Colorful image test failed");
    exit(1);
}

