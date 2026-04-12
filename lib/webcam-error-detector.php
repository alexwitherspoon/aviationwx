<?php
/**
 * Webcam Error Frame Detector
 *
 * Detects invalid webcam images including:
 * - Blue Iris error frames (grey borders with white text)
 * - Uniform color images (lens cap, dead camera, corruption)
 * - Corrupt bottom region (solid green/blue/red lines from partial JPEG or device failure)
 * - Lower-right corner fast-fail (last pixels in JPEG scan order; catches partial corruption)
 * - Pixelated/low-quality images (Laplacian variance detection)
 *
 * Uses phase-aware thresholds for pixelation and uniform-color checks (day/twilight/night).
 */

require_once __DIR__ . '/constants.php';
require_once __DIR__ . '/weather/utils.php';

/**
 * Check if an image appears to be an error frame
 *
 * Detects various image quality issues. Runs cheap checks first for efficiency.
 *
 * @param string $imagePath Path to image file
 * @param array|null $airport Airport config for phase-aware pixelation threshold (optional)
 * @param \GdImage|resource|null $gdImage Pre-loaded GD image (optional); when provided, skips file load
 * @return array {
 *   'is_error' => bool,
 *   'confidence' => float,
 *   'error_score' => float,
 *   'reasons' => array
 * }
 */
function detectErrorFrame(string $imagePath, ?array $airport = null, $gdImage = null): array {
    $img = null;

    $isGdImage = ($gdImage instanceof \GdImage)
        || (is_resource($gdImage) && get_resource_type($gdImage) === 'gd');
    if ($gdImage !== null && $isGdImage) {
        $img = $gdImage;
    } else {
        if (!file_exists($imagePath) || !is_readable($imagePath)) {
            return ['is_error' => true, 'confidence' => 1.0, 'error_score' => 1.0, 'reasons' => ['file_not_readable']];
        }
        if (!function_exists('imagecreatefromstring')) {
            return ['is_error' => false, 'confidence' => 0.0, 'error_score' => 0.0, 'reasons' => ['gd_not_available']];
        }
        $imageData = @file_get_contents($imagePath);
        if ($imageData === false) {
            return ['is_error' => true, 'confidence' => 1.0, 'error_score' => 1.0, 'reasons' => ['file_read_failed']];
        }
        $img = @imagecreatefromstring($imageData);
        if ($img === false) {
            return ['is_error' => true, 'confidence' => 1.0, 'error_score' => 1.0, 'reasons' => ['invalid_image']];
        }
    }

    $width = imagesx($img);
    $height = imagesy($img);

    // Palette images: imagecolorat returns index, not packed RGB; convert for correct pixel math
    if (!imageistruecolor($img) && function_exists('imagepalettetotruecolor')) {
        imagepalettetotruecolor($img);
    }

    if ($width < WEBCAM_ERROR_MIN_WIDTH || $height < WEBCAM_ERROR_MIN_HEIGHT) {
        return ['is_error' => true, 'confidence' => 0.8, 'error_score' => 0.8, 'reasons' => ['too_small']];
    }

    // CORRUPT BOTTOM: Run cheap checks first (10 pixels, then 5 rows) before full-image uniform color
    $cornerCheck = detectCorruptBottomCornerFastFail($img, $width, $height);
    if ($cornerCheck['is_corrupt']) {
        return [
            'is_error' => true,
            'confidence' => 0.9,
            'error_score' => 0.9,
            'reasons' => [$cornerCheck['reason']]
        ];
    }

    // Full corrupt bottom region check (rows, variance, etc.)
    $corruptBottomCheck = detectCorruptBottomRegion($img, $width, $height);
    if ($corruptBottomCheck['is_corrupt']) {
        return [
            'is_error' => true,
            'confidence' => 0.9,
            'error_score' => 0.9,
            'reasons' => [$corruptBottomCheck['reason']]
        ];
    }

    // UNIFORM COLOR: Full-image scan (expensive); run after cheap corruption checks
    $uniformCheck = detectUniformColor($img, $width, $height, $airport);
    if ($uniformCheck['is_uniform']) {
        return [
            'is_error' => true,
            'confidence' => 1.0,
            'error_score' => 1.0,
            'reasons' => [$uniformCheck['reason']]
        ];
    }

    // PIXELATION CHECK: Detect overly smooth/blocky images using Laplacian variance
    // Uses phase-aware thresholds (day/twilight/night) for accuracy
    // Hard fail: pixelated images are rejected
    $pixelationCheck = detectPixelation($img, $width, $height, $airport);
    if ($pixelationCheck['is_pixelated']) {
        return [
            'is_error' => true,
            'confidence' => 0.9, // High confidence but not absolute (phase detection could be off)
            'error_score' => 0.9,
            'reasons' => [$pixelationCheck['reason']]
        ];
    }
    
    $reasons = [];
    $errorScore = 0.0;
    
    // STRATEGY 1: Quick border variance check (early exit for legitimate images)
    // Error frames have uniform grey borders (low variance), real images have varied borders (high variance)
    // Sample just the left border (~50-100 pixels) for fast detection
    $quickBorderWidth = max(1, floor($width * 0.05)); // 5% of width
    $quickSampleSize = 50; // Sample ~50 pixels from left border
    $quickStepY = max(1, floor($height / $quickSampleSize));
    
    $quickBorderBrightnesses = [];
    for ($y = 0; $y < $height; $y += $quickStepY) {
        $rgb = imagecolorat($img, floor($quickBorderWidth / 2), $y); // Sample middle of left border
        $r = ($rgb >> 16) & 0xFF;
        $g = ($rgb >> 8) & 0xFF;
        $b = $rgb & 0xFF;
        $quickBorderBrightnesses[] = ($r + $g + $b) / 3;
    }
    
    if (count($quickBorderBrightnesses) > 10) { // Need enough samples for meaningful variance
        $quickBorderVariance = calculateVariance($quickBorderBrightnesses);
        
        // High variance = varied content in border = real image, exit early
        // This catches legitimate images quickly (most common case)
        if ($quickBorderVariance > WEBCAM_ERROR_QUICK_BORDER_VARIANCE_THRESHOLD) {
            return ['is_error' => false, 'confidence' => 0.0, 'error_score' => 0.0, 'reasons' => ['high_border_variance_early_exit']];
        }
        // Low variance = potential error frame, continue with full border analysis
    }
    
    // STRATEGY 2: Full border variance and grey ratio analysis
    // Sample all border regions (top, bottom, left, right) for comprehensive analysis
    $borderGreyCount = 0;
    $borderStep = max(1, floor(min($width, $height) / WEBCAM_ERROR_BORDER_SAMPLE_SIZE));
    $borderSampleCount = 0;
    
    // Sample border regions (5% of image dimensions) where error text typically appears
    $borderRegionRatio = 0.05;
    $borderHeight = max(1, floor($height * $borderRegionRatio));
    $borderWidth = max(1, floor($width * $borderRegionRatio));
    
    $borderRegions = [
        ['x' => 0, 'y' => 0, 'width' => $width, 'height' => $borderHeight], // Top
        ['x' => 0, 'y' => $height - $borderHeight, 'width' => $width, 'height' => $borderHeight], // Bottom
        ['x' => 0, 'y' => 0, 'width' => $borderWidth, 'height' => $height], // Left
        ['x' => $width - $borderWidth, 'y' => 0, 'width' => $borderWidth, 'height' => $height], // Right
    ];
    
    // Exclude very bright pixels (white text) from border grey count
    // Text overlays are bright white, so we count all pixels but only count grey (non-text) pixels
    $textBrightnessThreshold = WEBCAM_ERROR_BRIGHT_PIXEL_THRESHOLD_FOR_TEXT_EXCLUSION;
    
    // Collect brightness values for variance analysis and count grey pixels
    $borderBrightnesses = [];
    
    foreach ($borderRegions as $region) {
        for ($y = $region['y']; $y < min($height, $region['y'] + $region['height']); $y += $borderStep) {
            for ($x = $region['x']; $x < min($width, $region['x'] + $region['width']); $x += $borderStep) {
                $rgb = imagecolorat($img, $x, $y);
                $r = ($rgb >> 16) & 0xFF;
                $g = ($rgb >> 8) & 0xFF;
                $b = $rgb & 0xFF;
                $maxChannel = max($r, $g, $b);
                $minChannel = min($r, $g, $b);
                $brightness = ($r + $g + $b) / 3;
                
                // Store brightness for variance calculation
                $borderBrightnesses[] = $brightness;
                
                // Count all pixels for denominator, but only count grey pixels (excluding text) for numerator
                // This gives us grey ratio of non-text pixels, which is more accurate for error frame detection
                $borderSampleCount++;
                if ($brightness < $textBrightnessThreshold) {
                    if (($maxChannel - $minChannel) < WEBCAM_ERROR_GREY_CHANNEL_DIFF && $brightness < WEBCAM_ERROR_BORDER_BRIGHTNESS) {
                        $borderGreyCount++;
                    }
                }
            }
        }
    }
    
    $borderGreyRatio = $borderSampleCount > 0 ? $borderGreyCount / $borderSampleCount : 0;
    
    // Calculate border variance - this is the key differentiator
    // Error frames have low variance (uniform grey), real images have higher variance (varied content)
    $borderVariance = 0.0;
    if (count($borderBrightnesses) > 10) {
        $borderVariance = calculateVariance($borderBrightnesses);
    }
    
    // Check for white text in borders (key differentiator: error frames have white text overlays)
    // This helps distinguish error frames from legitimate very dark nighttime images
    $whiteTextCount = 0;
    $whiteTextTotal = 0;
    foreach ($borderRegions as $region) {
        for ($y = $region['y']; $y < min($height, $region['y'] + $region['height']); $y += $borderStep) {
            for ($x = $region['x']; $x < min($width, $region['x'] + $region['width']); $x += $borderStep) {
                $rgb = imagecolorat($img, $x, $y);
                $r = ($rgb >> 16) & 0xFF;
                $g = ($rgb >> 8) & 0xFF;
                $b = $rgb & 0xFF;
                $brightness = ($r + $g + $b) / 3;
                $whiteTextTotal++;
                if ($brightness > WEBCAM_ERROR_WHITE_PIXEL_THRESHOLD) {
                    $whiteTextCount++;
                }
            }
        }
    }
    $whiteTextRatio = $whiteTextTotal > 0 ? $whiteTextCount / $whiteTextTotal : 0;
    $hasWhiteText = $whiteTextRatio > 0.02; // At least 2% white pixels indicates text
    
    // DETECTION LOGIC: Use variance, grey ratio, and white text to determine if error frame
    
    // High variance = varied content = real image (should have exited in Strategy 1, but double-check)
    if ($borderVariance > WEBCAM_ERROR_QUICK_BORDER_VARIANCE_THRESHOLD) {
        return ['is_error' => false, 'confidence' => 0.0, 'error_score' => 0.0, 'reasons' => ['high_border_variance']];
    }
    
    // Low variance + high grey ratio + white text = definitive error frame
    // White text is the key differentiator - error frames have text overlays, legitimate dark images don't
    if ($borderVariance < WEBCAM_ERROR_BORDER_VARIANCE_THRESHOLD && $borderGreyRatio > 0.85 && $hasWhiteText) {
        $errorScore = 0.9; // Strong indicator - uniform grey borders with white text is definitive
        $reasons[] = sprintf('low_border_variance_%.1f_grey_%.1f%%_white_text_%.1f%%', $borderVariance, $borderGreyRatio * 100, $whiteTextRatio * 100);
    } elseif ($borderVariance < WEBCAM_ERROR_BORDER_VARIANCE_THRESHOLD && $borderGreyRatio > 0.95) {
        // Extremely uniform borders (>95% grey) even without text = likely error frame
        // But only if variance is very low (<50) to avoid false positives on legitimate very dark images
        if ($borderVariance < 50) {
            $errorScore = 0.7; // Strong but not definitive without text
            $reasons[] = sprintf('extremely_uniform_border_variance_%.1f_grey_%.1f%%', $borderVariance, $borderGreyRatio * 100);
        } else {
            $errorScore = 0.0; // Some variance = likely real image
            $reasons[] = sprintf('high_grey_but_some_variance_%.1f_grey_%.1f%%', $borderVariance, $borderGreyRatio * 100);
        }
    } elseif ($borderVariance < WEBCAM_ERROR_BORDER_VARIANCE_THRESHOLD && $borderGreyRatio > 0.70 && $hasWhiteText) {
        // Medium grey ratio but low variance with white text = potential error frame
        $errorScore = 0.6;
        $reasons[] = sprintf('low_border_variance_%.1f_moderate_grey_%.1f%%_white_text_%.1f%%', $borderVariance, $borderGreyRatio * 100, $whiteTextRatio * 100);
    } elseif ($borderVariance >= WEBCAM_ERROR_BORDER_VARIANCE_THRESHOLD && $borderVariance < WEBCAM_ERROR_QUICK_BORDER_VARIANCE_THRESHOLD) {
        // Medium variance = some variation in borders = likely real image
        $errorScore = 0.0; // No error - real image
        $reasons[] = sprintf('medium_border_variance_%.1f_grey_%.1f%%', $borderVariance, $borderGreyRatio * 100);
    } else {
        // Low variance but no white text and not extremely uniform = likely legitimate dark image
        $errorScore = 0.0;
        $reasons[] = sprintf('low_variance_no_white_text_variance_%.1f_grey_%.1f%%', $borderVariance, $borderGreyRatio * 100);
    }
    
    
    $isError = $errorScore >= WEBCAM_ERROR_SCORE_THRESHOLD;
    $confidence = min(1.0, $errorScore);

    return [
        'is_error' => $isError,
        'confidence' => $confidence,
        'error_score' => $errorScore,
        'reasons' => $reasons
    ];
}

/**
 * Calculate variance of an array of values
 * 
 * @param array $values Array of numeric values
 * @return float Variance (0.0 if empty array)
 */
function calculateVariance(array $values): float {
    if (empty($values)) {
        return 0.0;
    }
    
    $count = count($values);
    $mean = array_sum($values) / $count;
    
    $variance = 0.0;
    foreach ($values as $value) {
        $variance += pow($value - $mean, 2);
    }
    
    return $variance / $count;
}

/**
 * Max channel variance below which the image is treated as a single solid color.
 *
 * Day and civil twilight use the default constant. Nautical twilight uses a middle value.
 * Night uses a lower ceiling so legitimate very dark sky is not misclassified as uniform.
 *
 * @param array|null $airport Airport config with lat/lon; null uses daytime threshold
 * @param int|null $timestamp Unix time for phase (null = now)
 * @return float Threshold on max channel variance; below this the frame is treated as uniform
 */
function getUniformColorVarianceThreshold(?array $airport = null, ?int $timestamp = null): float {
    $default = defined('WEBCAM_ERROR_UNIFORM_COLOR_VARIANCE_THRESHOLD')
        ? (float) WEBCAM_ERROR_UNIFORM_COLOR_VARIANCE_THRESHOLD
        : 25.0;
    if ($airport === null || !isset($airport['lat']) || !isset($airport['lon'])) {
        return $default;
    }
    $phase = getDaylightPhase($airport, $timestamp);
    switch ($phase) {
        case DAYLIGHT_PHASE_NIGHT:
            return defined('WEBCAM_ERROR_UNIFORM_COLOR_VARIANCE_THRESHOLD_NIGHT')
                ? (float) WEBCAM_ERROR_UNIFORM_COLOR_VARIANCE_THRESHOLD_NIGHT
                : 8.0;
        case DAYLIGHT_PHASE_NAUTICAL_TWILIGHT:
            return defined('WEBCAM_ERROR_UNIFORM_COLOR_VARIANCE_THRESHOLD_NAUTICAL')
                ? (float) WEBCAM_ERROR_UNIFORM_COLOR_VARIANCE_THRESHOLD_NAUTICAL
                : 15.0;
        default:
            return $default;
    }
}

/**
 * Detect if image is essentially one uniform color
 *
 * Checks if an image has extremely low color variance, indicating a failed camera,
 * corrupted file, lens cap, or solid color error screen. A healthy webcam image
 * will always have some variance - even fog/night/snow has significant variance
 * due to sensor noise and natural gradients. At night, thresholds are lower so
 * very dark sky (low variance but not a dead sensor) is not rejected as uniform.
 *
 * Samples ~50 pixels distributed across the image for efficiency.
 * Checks both brightness variance AND color channel variance to catch:
 * - Solid black (lens cap, dead camera)
 * - Solid grey (some error states)
 * - Solid color (some cameras output solid blue/green on failure)
 *
 * @param resource|\GdImage $img GD image resource
 * @param int $width Image width
 * @param int $height Image height
 * @param array|null $airport Airport config with lat/lon for phase-aware threshold (optional)
 * @return array {
 *   'is_uniform' => bool,      // True if image is essentially one color
 *   'variance' => float,       // Calculated max variance across channels
 *   'dominant_color' => array, // [r, g, b] average color
 *   'reason' => string         // Descriptive reason string for logging
 * }
 */
function detectUniformColor($img, int $width, int $height, ?array $airport = null): array {
    $sampleSize = defined('WEBCAM_ERROR_UNIFORM_COLOR_SAMPLE_SIZE') 
        ? WEBCAM_ERROR_UNIFORM_COLOR_SAMPLE_SIZE 
        : 50;
    $threshold = getUniformColorVarianceThreshold($airport);
    
    // Sample pixels in a grid pattern across the image
    $gridSize = (int)ceil(sqrt($sampleSize));
    $stepX = max(1, (int)floor($width / $gridSize));
    $stepY = max(1, (int)floor($height / $gridSize));
    
    $redValues = [];
    $greenValues = [];
    $blueValues = [];
    $brightnessValues = [];
    
    for ($y = (int)floor($stepY / 2); $y < $height; $y += $stepY) {
        for ($x = (int)floor($stepX / 2); $x < $width; $x += $stepX) {
            $rgb = imagecolorat($img, $x, $y);
            $r = ($rgb >> 16) & 0xFF;
            $g = ($rgb >> 8) & 0xFF;
            $b = $rgb & 0xFF;
            
            $redValues[] = $r;
            $greenValues[] = $g;
            $blueValues[] = $b;
            $brightnessValues[] = ($r + $g + $b) / 3;
        }
    }
    
    if (count($brightnessValues) < 10) {
        // Not enough samples - can't determine, assume not uniform
        return ['is_uniform' => false, 'variance' => 999, 'dominant_color' => [0, 0, 0], 'reason' => ''];
    }
    
    // Calculate variance for each channel and brightness
    $brightnessVariance = calculateVariance($brightnessValues);
    $redVariance = calculateVariance($redValues);
    $greenVariance = calculateVariance($greenValues);
    $blueVariance = calculateVariance($blueValues);
    
    // Combined color variance (max of all channels)
    // Using max ensures we catch both greyscale and colored solid images
    $maxVariance = max($brightnessVariance, $redVariance, $greenVariance, $blueVariance);
    
    // Calculate dominant color for logging
    $avgR = (int)round(array_sum($redValues) / count($redValues));
    $avgG = (int)round(array_sum($greenValues) / count($greenValues));
    $avgB = (int)round(array_sum($blueValues) / count($blueValues));
    $avgBrightness = ($avgR + $avgG + $avgB) / 3;
    
    if ($maxVariance < $threshold) {
        // Determine what kind of solid color it is for clearer logging
        $colorDesc = '';
        if ($avgBrightness < 20) {
            $colorDesc = 'solid_black';
        } elseif ($avgBrightness > 235) {
            $colorDesc = 'solid_white';
        } elseif (abs($avgR - $avgG) < 15 && abs($avgG - $avgB) < 15) {
            $colorDesc = 'solid_grey';
        } else {
            // Colored solid (e.g., blue screen, green failure)
            $colorDesc = 'solid_color';
        }
        
        return [
            'is_uniform' => true,
            'variance' => $maxVariance,
            'dominant_color' => [$avgR, $avgG, $avgB],
            'reason' => sprintf('%s_variance_%.1f_rgb_%d_%d_%d', 
                $colorDesc, $maxVariance, $avgR, $avgG, $avgB)
        ];
    }
    
    return [
        'is_uniform' => false,
        'variance' => $maxVariance,
        'dominant_color' => [$avgR, $avgG, $avgB],
        'reason' => ''
    ];
}

/**
 * Fast-fail: check last N pixels in lower-right corner for corruption color
 *
 * JPEG encodes top-to-bottom, left-to-right; truncation cuts off at lower-right.
 * Samples rightmost pixels of bottom row. Requires min_match of N to match
 * corruption color, and avg brightness > threshold to skip dark night corners.
 *
 * @param resource|\GdImage $img GD image resource
 * @param int $width Image width
 * @param int $height Image height
 * @return array{is_corrupt: bool, reason: string}
 */
function detectCorruptBottomCornerFastFail($img, int $width, int $height): array
{
    $size = defined('WEBCAM_ERROR_CORRUPT_CORNER_SIZE') ? WEBCAM_ERROR_CORRUPT_CORNER_SIZE : 10;
    $minMatch = defined('WEBCAM_ERROR_CORRUPT_CORNER_MIN_MATCH') ? WEBCAM_ERROR_CORRUPT_CORNER_MIN_MATCH : 8;
    $minBrightness = defined('WEBCAM_ERROR_CORRUPT_CORNER_MIN_BRIGHTNESS') ? WEBCAM_ERROR_CORRUPT_CORNER_MIN_BRIGHTNESS : 35;

    $y = $height - 1;
    $xStart = max(0, $width - $size);
    $matchCount = 0;
    $brightnessSum = 0;
    $sampleCount = 0;

    for ($x = $xStart; $x < $width; $x++) {
        $rgb = imagecolorat($img, $x, $y);
        $r = ($rgb >> 16) & 0xFF;
        $g = ($rgb >> 8) & 0xFF;
        $b = $rgb & 0xFF;
        $brightnessSum += ($r + $g + $b) / 3;
        $sampleCount++;
        if (isCorruptionColor($r, $g, $b)) {
            $matchCount++;
        }
    }

    if ($sampleCount === 0) {
        return ['is_corrupt' => false, 'reason' => ''];
    }
    $avgBrightness = $brightnessSum / $sampleCount;
    if ($avgBrightness < $minBrightness) {
        return ['is_corrupt' => false, 'reason' => ''];
    }

    $minMatchRequired = min($minMatch, $sampleCount);
    if ($matchCount >= $minMatchRequired) {
        return [
            'is_corrupt' => true,
            'reason' => sprintf('corrupt_corner_fast_fail_matched_%d_of_%d_brightness_%.0f', $matchCount, $sampleCount, $avgBrightness)
        ];
    }

    return ['is_corrupt' => false, 'reason' => ''];
}

/**
 * Detect corrupt bottom region (solid corruption-color lines in last N rows)
 *
 * JPEG encodes top-to-bottom; partial uploads or device corruption produce
 * solid color lines at the bottom (green/blue/red from failed encode).
 * Checks only the last N rows for CPU efficiency; corruption typically
 * affects the last-painted rows first.
 *
 * @param resource|\GdImage $img GD image resource
 * @param int $width Image width
 * @param int $height Image height
 * @return array {
 *   'is_corrupt' => bool,
 *   'variance' => float,
 *   'reason' => string
 * }
 */
function detectCorruptBottomRegion($img, int $width, int $height): array
{
    $rowsToCheck = defined('WEBCAM_ERROR_CORRUPT_BOTTOM_ROWS')
        ? WEBCAM_ERROR_CORRUPT_BOTTOM_ROWS
        : 5;
    $rowSampleStep = defined('WEBCAM_ERROR_CORRUPT_ROW_SAMPLE_STEP')
        ? WEBCAM_ERROR_CORRUPT_ROW_SAMPLE_STEP
        : 20;

    $yStart = max(0, $height - $rowsToCheck);
    $stepX = max(1, (int) floor($width / $rowSampleStep));

    // Scan from bottom up; corruption typically affects last-painted rows first
    for ($y = $height - 1; $y >= $yStart; $y--) {
        $rowPixels = [];
        for ($x = (int) floor($stepX / 2); $x < $width; $x += $stepX) {
            $rgb = imagecolorat($img, $x, $y);
            $rowPixels[] = [
                ($rgb >> 16) & 0xFF,
                ($rgb >> 8) & 0xFF,
                $rgb & 0xFF,
            ];
        }

        if (count($rowPixels) < 5) {
            continue;
        }

        $rValues = array_column($rowPixels, 0);
        $gValues = array_column($rowPixels, 1);
        $bValues = array_column($rowPixels, 2);
        $rowVariance = max(
            calculateVariance($rValues),
            calculateVariance($gValues),
            calculateVariance($bValues)
        );

        $varianceThreshold = defined('WEBCAM_ERROR_CORRUPT_ROW_VARIANCE_THRESHOLD')
            ? WEBCAM_ERROR_CORRUPT_ROW_VARIANCE_THRESHOLD
            : 50;
        if ($rowVariance >= $varianceThreshold) {
            continue;
        }

        $avgR = (int) round(array_sum($rValues) / count($rValues));
        $avgG = (int) round(array_sum($gValues) / count($gValues));
        $avgB = (int) round(array_sum($bValues) / count($bValues));

        if (!isCorruptionColor($avgR, $avgG, $avgB)) {
            continue;
        }

        $colorDesc = getCorruptionColorDescription($avgR, $avgG, $avgB);

        return [
            'is_corrupt' => true,
            'variance' => $rowVariance,
            'reason' => sprintf('corrupt_bottom_%s_row_%d_variance_%.1f_rgb_%d_%d_%d',
                $colorDesc, $y, $rowVariance, $avgR, $avgG, $avgB),
        ];
    }

    return ['is_corrupt' => false, 'variance' => 999, 'reason' => ''];
}

/**
 * Check if RGB is a known corruption artifact color (green/blue/red)
 *
 * @param int $r Red 0-255
 * @param int $g Green 0-255
 * @param int $b Blue 0-255
 * @return bool
 */
function isCorruptionColor(int $r, int $g, int $b): bool
{
    $low = defined('WEBCAM_ERROR_CORRUPT_COLOR_LOW') ? WEBCAM_ERROR_CORRUPT_COLOR_LOW : 50;
    $high = defined('WEBCAM_ERROR_CORRUPT_COLOR_HIGH') ? WEBCAM_ERROR_CORRUPT_COLOR_HIGH : 110;

    return ($r < $low && $g > $high && $b < $low)
        || ($r < $low && $g < $low && $b > $high)
        || ($r > $high && $g < $low && $b < $low);
}

/**
 * Get human-readable description for corruption color
 *
 * @param int $r Red 0-255
 * @param int $g Green 0-255
 * @param int $b Blue 0-255
 * @return string Description (solid_green, solid_blue, solid_red, or solid_color)
 */
function getCorruptionColorDescription(int $r, int $g, int $b): string
{
    $low = defined('WEBCAM_ERROR_CORRUPT_COLOR_LOW') ? WEBCAM_ERROR_CORRUPT_COLOR_LOW : 50;
    $high = defined('WEBCAM_ERROR_CORRUPT_COLOR_HIGH') ? WEBCAM_ERROR_CORRUPT_COLOR_HIGH : 110;

    if ($g > $high && $r < $low && $b < $low) {
        return 'solid_green';
    }
    if ($b > $high && $r < $low && $g < $low) {
        return 'solid_blue';
    }
    if ($r > $high && $g < $low && $b < $low) {
        return 'solid_red';
    }

    return 'solid_color';
}

/**
 * Detect pixelation/low quality using Laplacian variance
 * 
 * The Laplacian operator detects edges by measuring second-order derivatives.
 * High variance = sharp edges = good quality image
 * Low variance = smooth/blurry = pixelated or low quality
 * 
 * Uses grayscale brightness for edge detection (faster than per-channel).
 * Samples on a grid for efficiency rather than every pixel.
 * 
 * @param resource $img GD image resource
 * @param int $width Image width
 * @param int $height Image height
 * @return array {
 *   'variance' => float,     // Laplacian variance (higher = sharper)
 *   'mean' => float,         // Mean Laplacian value
 *   'sample_count' => int    // Number of samples taken
 * }
 */
function calculateLaplacianVariance($img, int $width, int $height): array {
    $gridSize = defined('WEBCAM_PIXELATION_SAMPLE_GRID') 
        ? WEBCAM_PIXELATION_SAMPLE_GRID 
        : 20;
    
    // Calculate step sizes for grid sampling
    // Leave 1-pixel border to allow Laplacian calculation
    $stepX = max(1, (int)floor(($width - 2) / $gridSize));
    $stepY = max(1, (int)floor(($height - 2) / $gridSize));
    
    $laplacianValues = [];
    
    // Pre-calculate brightness for sampled pixels + neighbors
    // This is more efficient than calling imagecolorat multiple times per pixel
    $brightnessCache = [];
    
    for ($y = 1; $y < $height - 1; $y += $stepY) {
        for ($x = 1; $x < $width - 1; $x += $stepX) {
            // Get brightness for center and 4 neighbors
            $center = getPixelBrightness($img, $x, $y, $brightnessCache);
            $top = getPixelBrightness($img, $x, $y - 1, $brightnessCache);
            $bottom = getPixelBrightness($img, $x, $y + 1, $brightnessCache);
            $left = getPixelBrightness($img, $x - 1, $y, $brightnessCache);
            $right = getPixelBrightness($img, $x + 1, $y, $brightnessCache);
            
            // Laplacian: 4*center - (top + bottom + left + right)
            // This measures how different the center is from its neighbors
            $laplacian = 4 * $center - ($top + $bottom + $left + $right);
            $laplacianValues[] = abs($laplacian); // Use absolute value
        }
    }
    
    if (count($laplacianValues) < 10) {
        // Not enough samples
        return ['variance' => 0.0, 'mean' => 0.0, 'sample_count' => 0];
    }
    
    $mean = array_sum($laplacianValues) / count($laplacianValues);
    $variance = calculateVariance($laplacianValues);
    
    return [
        'variance' => $variance,
        'mean' => $mean,
        'sample_count' => count($laplacianValues)
    ];
}

/**
 * Get pixel brightness with caching
 * 
 * @param resource $img GD image resource
 * @param int $x X coordinate
 * @param int $y Y coordinate
 * @param array &$cache Brightness cache (passed by reference)
 * @return float Brightness (0-255)
 */
function getPixelBrightness($img, int $x, int $y, array &$cache): float {
    $key = "{$x},{$y}";
    
    if (!isset($cache[$key])) {
        $rgb = imagecolorat($img, $x, $y);
        $r = ($rgb >> 16) & 0xFF;
        $g = ($rgb >> 8) & 0xFF;
        $b = $rgb & 0xFF;
        $cache[$key] = ($r + $g + $b) / 3.0;
    }
    
    return $cache[$key];
}

/**
 * Get pixelation threshold based on daylight phase
 * 
 * Returns the appropriate Laplacian variance threshold for the current
 * lighting conditions. Night images are naturally softer, so thresholds
 * are more lenient.
 * 
 * @param array|null $airport Airport config with lat/lon (null = use day threshold)
 * @param int|null $timestamp Unix timestamp (null = now)
 * @return float Laplacian variance threshold
 */
function getPixelationThreshold(?array $airport = null, ?int $timestamp = null): float {
    // If no airport provided, use conservative day threshold
    if ($airport === null || !isset($airport['lat']) || !isset($airport['lon'])) {
        return defined('WEBCAM_PIXELATION_THRESHOLD_DAY') 
            ? WEBCAM_PIXELATION_THRESHOLD_DAY 
            : 15.0;
    }
    
    $phase = getDaylightPhase($airport, $timestamp);
    
    switch ($phase) {
        case DAYLIGHT_PHASE_DAY:
            return defined('WEBCAM_PIXELATION_THRESHOLD_DAY') 
                ? WEBCAM_PIXELATION_THRESHOLD_DAY 
                : 15.0;
                
        case DAYLIGHT_PHASE_CIVIL_TWILIGHT:
            return defined('WEBCAM_PIXELATION_THRESHOLD_CIVIL') 
                ? WEBCAM_PIXELATION_THRESHOLD_CIVIL 
                : 10.0;
                
        case DAYLIGHT_PHASE_NAUTICAL_TWILIGHT:
            return defined('WEBCAM_PIXELATION_THRESHOLD_NAUTICAL') 
                ? WEBCAM_PIXELATION_THRESHOLD_NAUTICAL 
                : 8.0;
                
        case DAYLIGHT_PHASE_NIGHT:
        default:
            return defined('WEBCAM_PIXELATION_THRESHOLD_NIGHT') 
                ? WEBCAM_PIXELATION_THRESHOLD_NIGHT 
                : 5.0;
    }
}

/**
 * Detect pixelation in image
 * 
 * Uses Laplacian variance with phase-aware thresholds.
 * Hard fail: images below threshold are rejected.
 * 
 * @param resource $img GD image resource
 * @param int $width Image width
 * @param int $height Image height
 * @param array|null $airport Airport config for phase-aware threshold (null = day threshold)
 * @return array {
 *   'is_pixelated' => bool,  // True if image fails pixelation check
 *   'variance' => float,     // Measured Laplacian variance
 *   'threshold' => float,    // Threshold used for comparison
 *   'phase' => string,       // Daylight phase used
 *   'reason' => string       // Descriptive reason for logging
 * }
 */
function detectPixelation($img, int $width, int $height, ?array $airport = null): array {
    // Get phase-appropriate threshold
    $phase = ($airport !== null && isset($airport['lat']) && isset($airport['lon']))
        ? getDaylightPhase($airport)
        : DAYLIGHT_PHASE_DAY;
    $threshold = getPixelationThreshold($airport);
    
    // Calculate Laplacian variance
    $laplacian = calculateLaplacianVariance($img, $width, $height);
    
    if ($laplacian['sample_count'] < 10) {
        // Not enough samples to determine - don't fail
        return [
            'is_pixelated' => false,
            'variance' => 0.0,
            'threshold' => $threshold,
            'phase' => $phase,
            'reason' => 'insufficient_samples'
        ];
    }
    
    $isPixelated = $laplacian['variance'] < $threshold;
    
    $reason = '';
    if ($isPixelated) {
        $reason = sprintf('pixelated_variance_%.1f_threshold_%.1f_phase_%s',
            $laplacian['variance'], $threshold, $phase);
    }
    
    return [
        'is_pixelated' => $isPixelated,
        'variance' => $laplacian['variance'],
        'threshold' => $threshold,
        'phase' => $phase,
        'reason' => $reason
    ];
}

/**
 * Quick check if image might be an error frame (lightweight)
 * 
 * Faster, less accurate check for high-volume scenarios.
 * Uses simplified grey/dark pixel analysis only.
 * 
 * @param string $imagePath Path to image file
 * @return bool True if likely an error frame
 */
function quickErrorFrameCheck(string $imagePath): bool {
    if (!file_exists($imagePath) || !function_exists('imagecreatefromstring')) {
        return true;
    }
    
    // Use @ to suppress errors for non-critical image loading
    // We handle failures explicitly with error return below
    $imageData = @file_get_contents($imagePath);
    if ($imageData === false) {
        return true;
    }
    
    $img = @imagecreatefromstring($imageData);
    if ($img === false) {
        return true;
    }
    
    $width = imagesx($img);
    $height = imagesy($img);
    
    $stepX = max(1, floor($width / 10));
    $stepY = max(1, floor($height / 10));
    
    $greyCount = 0;
    $darkCount = 0;
    $total = 0;
    
    for ($y = 0; $y < $height; $y += $stepY) {
        for ($x = 0; $x < $width; $x += $stepX) {
            $rgb = imagecolorat($img, $x, $y);
            $r = ($rgb >> 16) & 0xFF;
            $g = ($rgb >> 8) & 0xFF;
            $b = $rgb & 0xFF;
            
            $maxChannel = max($r, $g, $b);
            $minChannel = min($r, $g, $b);
            $brightness = ($r + $g + $b) / 3;
            
            if (($maxChannel - $minChannel) < WEBCAM_ERROR_GREY_CHANNEL_DIFF) {
                $greyCount++;
            }
            if ($brightness < WEBCAM_ERROR_DARK_BRIGHTNESS) {
                $darkCount++;
            }
            $total++;
        }
    }
    
    
    // Protect against division by zero
    if ($total === 0) {
        return true;
    }
    
    return ($greyCount / $total > WEBCAM_ERROR_QUICK_GREY_RATIO) && ($darkCount / $total > WEBCAM_ERROR_QUICK_DARK_RATIO);
}

