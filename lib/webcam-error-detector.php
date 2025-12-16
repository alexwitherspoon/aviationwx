<?php
/**
 * Webcam Error Frame Detector
 * Detects Blue Iris error frames and other invalid webcam images
 */

require_once __DIR__ . '/constants.php';

/**
 * Check if an image appears to be an error frame
 * 
 * Uses multiple detection strategies: grey pixel analysis, edge detection,
 * color variance, and border uniformity. Error frames typically have excessive
 * grey backgrounds with low detail and color variance.
 * 
 * @param string $imagePath Path to image file (JPEG)
 * @return array {
 *   'is_error' => bool,        // True if image appears to be an error frame
 *   'confidence' => float,     // Confidence score (0.0 to 1.0)
 *   'error_score' => float,    // Raw error score before threshold
 *   'reasons' => array         // Array of detection reason strings
 * }
 */
function detectErrorFrame(string $imagePath): array {
    if (!file_exists($imagePath) || !is_readable($imagePath)) {
        return ['is_error' => true, 'confidence' => 1.0, 'error_score' => 1.0, 'reasons' => ['file_not_readable']];
    }
    
    // Check if GD library is available
    if (!function_exists('imagecreatefromstring')) {
        return ['is_error' => false, 'confidence' => 0.0, 'error_score' => 0.0, 'reasons' => ['gd_not_available']];
    }
    
    // Use @ to suppress errors for non-critical image loading
    // We handle failures explicitly with error return below
    $imageData = @file_get_contents($imagePath);
    if ($imageData === false) {
        return ['is_error' => true, 'confidence' => 1.0, 'error_score' => 1.0, 'reasons' => ['file_read_failed']];
    }
    
    $img = @imagecreatefromstring($imageData);
    if ($img === false) {
        return ['is_error' => true, 'confidence' => 1.0, 'error_score' => 1.0, 'reasons' => ['invalid_image']];
    }
    
    $width = imagesx($img);
    $height = imagesy($img);
    
    if ($width < WEBCAM_ERROR_MIN_WIDTH || $height < WEBCAM_ERROR_MIN_HEIGHT) {
        imagedestroy($img);
        return ['is_error' => true, 'confidence' => 0.8, 'error_score' => 0.8, 'reasons' => ['too_small']];
    }
    
    $reasons = [];
    $errorScore = 0.0;
    
    // Strategy 1: Grey pixel analysis
    // Sample pixels to detect excessive grey/dark backgrounds (error frames are mostly grey)
    $greyPixelCount = 0;
    $darkPixelCount = 0;
    $totalPixels = 0;
    $sampleSize = min(WEBCAM_ERROR_SAMPLE_SIZE, $width * $height);
    $stepX = max(1, floor($width / sqrt($sampleSize)));
    $stepY = max(1, floor($height / sqrt($sampleSize)));
    
    $rValues = [];
    $gValues = [];
    $bValues = [];
    
    for ($y = 0; $y < $height; $y += $stepY) {
        for ($x = 0; $x < $width; $x += $stepX) {
            $rgb = imagecolorat($img, $x, $y);
            $r = ($rgb >> 16) & 0xFF;
            $g = ($rgb >> 8) & 0xFF;
            $b = $rgb & 0xFF;
            
            $rValues[] = $r;
            $gValues[] = $g;
            $bValues[] = $b;
            
            $maxChannel = max($r, $g, $b);
            $minChannel = min($r, $g, $b);
            $channelDiff = $maxChannel - $minChannel;
            
            if ($channelDiff < WEBCAM_ERROR_GREY_CHANNEL_DIFF) {
                $greyPixelCount++;
            }
            
            $brightness = ($r + $g + $b) / 3;
            if ($brightness < WEBCAM_ERROR_DARK_BRIGHTNESS) {
                $darkPixelCount++;
            }
            
            $totalPixels++;
        }
    }
    
    // Protect against division by zero
    if ($totalPixels === 0) {
        imagedestroy($img);
        return ['is_error' => true, 'confidence' => 1.0, 'error_score' => 1.0, 'reasons' => ['no_pixels_sampled']];
    }
    
    $greyRatio = $greyPixelCount / $totalPixels;
    $darkRatio = $darkPixelCount / $totalPixels;
    
    if ($greyRatio > WEBCAM_ERROR_GREY_RATIO_THRESHOLD) {
        $errorScore += WEBCAM_ERROR_GREY_SCORE_WEIGHT;
        $reasons[] = sprintf('high_grey_ratio_%.1f%%', $greyRatio * 100);
    }
    if ($darkRatio > WEBCAM_ERROR_DARK_RATIO_THRESHOLD) {
        $errorScore += WEBCAM_ERROR_DARK_SCORE_WEIGHT;
        $reasons[] = sprintf('high_dark_ratio_%.1f%%', $darkRatio * 100);
    }
    
    // Strategy 2: Color variance analysis
    // Error frames have low color variance (uniform colors indicate error frames)
    if (!empty($rValues)) {
        $rVariance = calculateVariance($rValues);
        $gVariance = calculateVariance($gValues);
        $bVariance = calculateVariance($bValues);
        $avgVariance = ($rVariance + $gVariance + $bVariance) / 3;
        
        if ($avgVariance < WEBCAM_ERROR_COLOR_VARIANCE_THRESHOLD) {
            $errorScore += WEBCAM_ERROR_VARIANCE_SCORE_WEIGHT;
            $reasons[] = sprintf('low_color_variance_%.1f', $avgVariance);
        }
    }
    
    // Strategy 3: Edge detection
    // Error frames have very few edges (low detail)
    $edgeCount = 0;
    $edgeSampleSize = min(WEBCAM_ERROR_EDGE_SAMPLE_SIZE, $width * $height);
    $edgeStepX = max(1, floor($width / sqrt($edgeSampleSize)));
    $edgeStepY = max(1, floor($height / sqrt($edgeSampleSize)));
    
    for ($y = $edgeStepY; $y < $height - $edgeStepY; $y += $edgeStepY) {
        for ($x = $edgeStepX; $x < $width - $edgeStepX; $x += $edgeStepX) {
            $rgb1 = imagecolorat($img, $x, $y);
            $rgb2 = imagecolorat($img, $x + $edgeStepX, $y);
            $rgb3 = imagecolorat($img, $x, $y + $edgeStepY);
            
            $r1 = ($rgb1 >> 16) & 0xFF;
            $g1 = ($rgb1 >> 8) & 0xFF;
            $b1 = $rgb1 & 0xFF;
            
            $r2 = ($rgb2 >> 16) & 0xFF;
            $g2 = ($rgb2 >> 8) & 0xFF;
            $b2 = $rgb2 & 0xFF;
            
            $r3 = ($rgb3 >> 16) & 0xFF;
            $g3 = ($rgb3 >> 8) & 0xFF;
            $b3 = $rgb3 & 0xFF;
            
            $diff1 = abs($r1 - $r2) + abs($g1 - $g2) + abs($b1 - $b2);
            $diff2 = abs($r1 - $r3) + abs($g1 - $g3) + abs($b1 - $b3);
            
            if ($diff1 > WEBCAM_ERROR_EDGE_DIFF_THRESHOLD || $diff2 > WEBCAM_ERROR_EDGE_DIFF_THRESHOLD) {
                $edgeCount++;
            }
        }
    }
    
    $edgeSampleCount = max(1, ($width / $edgeStepX) * ($height / $edgeStepY));
    $edgeRatio = $edgeCount / $edgeSampleCount;
    
    if ($edgeRatio < WEBCAM_ERROR_EDGE_RATIO_THRESHOLD) {
        $errorScore += WEBCAM_ERROR_EDGE_SCORE_WEIGHT;
        $reasons[] = sprintf('low_edge_density_%.1f%%', $edgeRatio * 100);
    }
    
    // Strategy 4: Border analysis
    // Error frames often have uniform grey borders
    $borderGreyCount = 0;
    $borderStep = max(1, floor(min($width, $height) / WEBCAM_ERROR_BORDER_SAMPLE_SIZE));
    
    // Top border
    for ($x = 0; $x < $width; $x += $borderStep) {
        $rgb = imagecolorat($img, $x, 0);
        $r = ($rgb >> 16) & 0xFF;
        $g = ($rgb >> 8) & 0xFF;
        $b = $rgb & 0xFF;
        $maxChannel = max($r, $g, $b);
        $minChannel = min($r, $g, $b);
        $brightness = ($r + $g + $b) / 3;
        if (($maxChannel - $minChannel) < WEBCAM_ERROR_GREY_CHANNEL_DIFF && $brightness < WEBCAM_ERROR_BORDER_BRIGHTNESS) {
            $borderGreyCount++;
        }
    }
    
    // Bottom border
    for ($x = 0; $x < $width; $x += $borderStep) {
        $rgb = imagecolorat($img, $x, $height - 1);
        $r = ($rgb >> 16) & 0xFF;
        $g = ($rgb >> 8) & 0xFF;
        $b = $rgb & 0xFF;
        $maxChannel = max($r, $g, $b);
        $minChannel = min($r, $g, $b);
        $brightness = ($r + $g + $b) / 3;
        if (($maxChannel - $minChannel) < WEBCAM_ERROR_GREY_CHANNEL_DIFF && $brightness < WEBCAM_ERROR_BORDER_BRIGHTNESS) {
            $borderGreyCount++;
        }
    }
    
    $borderSampleCount = max(1, ($width / $borderStep) * 2);
    $borderGreyRatio = $borderGreyCount / $borderSampleCount;
    if ($borderGreyRatio > WEBCAM_ERROR_BORDER_RATIO_THRESHOLD) {
        $errorScore += WEBCAM_ERROR_BORDER_SCORE_WEIGHT;
        $reasons[] = sprintf('grey_borders_%.1f%%', $borderGreyRatio * 100);
    }
    
    imagedestroy($img);
    
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
    
    imagedestroy($img);
    
    // Protect against division by zero
    if ($total === 0) {
        return true;
    }
    
    return ($greyCount / $total > WEBCAM_ERROR_QUICK_GREY_RATIO) && ($darkCount / $total > WEBCAM_ERROR_QUICK_DARK_RATIO);
}

