<?php
/**
 * Webcam Error Frame Detector
 *
 * Detects invalid webcam images including:
 * - Blue Iris error frames (grey borders with white text)
 * - Uniform color images (lens cap, dead camera, corruption)
 * - Format-specific truncation pads (JPEG mid-grey, PNG solid, WebP relative flat)
 *
 * Uniform-color checks use phase-aware thresholds (day/twilight/night).
 * Bitstream completeness (EOI / IEND / RIFF size) complements decode-time pad detection.
 */

require_once __DIR__ . '/constants.php';
require_once __DIR__ . '/weather/utils.php';

/**
 * Check if an image appears to be an error frame
 *
 * Detects various image quality issues. Runs cheap checks first for efficiency.
 * When $sourceFormat is omitted, format is resolved via magic-byte detection;
 * unresolved format fails closed (unknown_source_format).
 *
 * @param string $imagePath Path to image file
 * @param array|null $airport Airport config for phase-aware uniform-color thresholds (optional)
 * @param \GdImage|resource|null $gdImage Pre-loaded GD image (optional); when provided, skips file load
 * @param string|null $sourceFormat Source bitstream format (jpg, png, webp); selects truncation pad model
 * @return array {
 *   'is_error' => bool,
 *   'confidence' => float,
 *   'error_score' => float,
 *   'reasons' => array
 * }
 */
function detectErrorFrame(string $imagePath, ?array $airport = null, $gdImage = null, ?string $sourceFormat = null): array {
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

    // Fail closed: wrong pad model under-rejects truncated PNG/WebP
    $normalizedFormat = normalizeWebcamSourceFormat($sourceFormat, $imagePath);
    if ($normalizedFormat === null) {
        return [
            'is_error' => true,
            'confidence' => 1.0,
            'error_score' => 1.0,
            'reasons' => ['unknown_source_format'],
        ];
    }

    $emptyBandCheck = detectTruncationPadBand($img, $width, $height, $normalizedFormat);
    if ($emptyBandCheck['is_empty_band']) {
        return [
            'is_error' => true,
            'confidence' => 0.95,
            'error_score' => 0.95,
            'reasons' => [$emptyBandCheck['reason']]
        ];
    }

    $uniformCheck = detectUniformColor($img, $width, $height, $airport);
    if ($uniformCheck['is_uniform']) {
        return [
            'is_error' => true,
            'confidence' => 1.0,
            'error_score' => 1.0,
            'reasons' => [$uniformCheck['reason']]
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
 * Resolve source format for truncation-pad dispatch.
 *
 * Prefer caller-supplied format. When omitted, resolve via magic-byte detection.
 * Returns null when unresolved so callers can fail closed.
 *
 * @param string|null $sourceFormat Explicit format from caller
 * @param string $imagePath Path used for magic-byte detect when format omitted
 * @return string|null One of jpg, png, webp; null when unresolved
 */
function normalizeWebcamSourceFormat(?string $sourceFormat, string $imagePath = ''): ?string
{
    $format = $sourceFormat;
    if ($format === null || $format === '') {
        require_once __DIR__ . '/webcam-format-generation.php';
        if ($imagePath !== '') {
            $format = detectImageFormat($imagePath);
        }
    }

    if ($format === null || $format === '') {
        return null;
    }

    $format = strtolower((string) $format);
    if ($format === 'jpeg') {
        return 'jpg';
    }
    if ($format === 'png' || $format === 'webp' || $format === 'jpg') {
        return $format;
    }

    return null;
}

/**
 * Dispatch format-specific bottom-pad detection for truncated uploads.
 *
 * @param resource|\GdImage $img GD image
 * @param int $width Image width
 * @param int $height Image height
 * @param string $sourceFormat Normalized format (jpg, png, webp)
 * @return array{
 *   is_empty_band: bool,
 *   coverage: float,
 *   content_rows: int,
 *   empty_rows: int,
 *   reason: string
 * }
 */
function detectTruncationPadBand($img, int $width, int $height, string $sourceFormat): array
{
    switch ($sourceFormat) {
        case 'png':
            return detectPngSolidEmptyBottomBand($img, $width, $height);
        case 'webp':
            return detectWebpRelativeFlatBottomBand($img, $width, $height);
        case 'jpg':
        default:
            return detectEmptyBottomBand($img, $width, $height);
    }
}

/**
 * Contiguous near-black bottom band from incomplete PNG decode/paint.
 *
 * @param resource|\GdImage $img GD image resource
 * @param int $width Image width
 * @param int $height Image height
 * @return array{
 *   is_empty_band: bool,
 *   coverage: float,
 *   content_rows: int,
 *   empty_rows: int,
 *   reason: string
 * }
 */
function detectPngSolidEmptyBottomBand($img, int $width, int $height): array
{
    $rowStep = defined('WEBCAM_ERROR_EMPTY_BAND_ROW_STEP')
        ? max(1, (int) WEBCAM_ERROR_EMPTY_BAND_ROW_STEP)
        : 4;
    $sampleCount = defined('WEBCAM_ERROR_EMPTY_BAND_SAMPLE_COUNT')
        ? max(5, (int) WEBCAM_ERROR_EMPTY_BAND_SAMPLE_COUNT)
        : 40;
    $minEmptyRows = defined('WEBCAM_ERROR_PNG_EMPTY_BAND_MIN_EMPTY_ROWS')
        ? max(1, (int) WEBCAM_ERROR_PNG_EMPTY_BAND_MIN_EMPTY_ROWS)
        : 1;
    $stepX = max(1, (int) floor($width / $sampleCount));

    if (!midFrameHasTexture($img, $height, $width, $stepX)) {
        return [
            'is_empty_band' => false,
            'coverage' => 1.0,
            'content_rows' => $height,
            'empty_rows' => 0,
            'reason' => '',
        ];
    }

    return measureContiguousBottomBand(
        $img,
        $width,
        $height,
        $rowStep,
        $stepX,
        $minEmptyRows,
        'empty_band_png_solid_rows_',
        static function ($img, int $y, int $width, int $stepX): bool {
            return isPngSolidEmptyFillRow($img, $y, $width, $stepX);
        }
    );
}

/**
 * Contiguous flat bottom pad when mid-frame still has texture (WebP truncation).
 *
 * @param resource|\GdImage $img GD image resource
 * @param int $width Image width
 * @param int $height Image height
 * @return array{
 *   is_empty_band: bool,
 *   coverage: float,
 *   content_rows: int,
 *   empty_rows: int,
 *   reason: string
 * }
 */
function detectWebpRelativeFlatBottomBand($img, int $width, int $height): array
{
    $rowStep = defined('WEBCAM_ERROR_EMPTY_BAND_ROW_STEP')
        ? max(1, (int) WEBCAM_ERROR_EMPTY_BAND_ROW_STEP)
        : 4;
    $sampleCount = defined('WEBCAM_ERROR_EMPTY_BAND_SAMPLE_COUNT')
        ? max(5, (int) WEBCAM_ERROR_EMPTY_BAND_SAMPLE_COUNT)
        : 40;
    $minEmptyRows = defined('WEBCAM_ERROR_WEBP_PAD_MIN_EMPTY_ROWS')
        ? max(1, (int) WEBCAM_ERROR_WEBP_PAD_MIN_EMPTY_ROWS)
        : 1;
    $stepX = max(1, (int) floor($width / $sampleCount));

    if (!midFrameHasTexture($img, $height, $width, $stepX)) {
        return [
            'is_empty_band' => false,
            'coverage' => 1.0,
            'content_rows' => $height,
            'empty_rows' => 0,
            'reason' => '',
        ];
    }

    return measureContiguousBottomBand(
        $img,
        $width,
        $height,
        $rowStep,
        $stepX,
        $minEmptyRows,
        'empty_band_webp_flat_rows_',
        static function ($img, int $y, int $width, int $stepX): bool {
            return isWebpFlatPadRow($img, $y, $width, $stepX);
        }
    );
}

/**
 * Mid-frame row has enough brightness variance to treat as real content.
 *
 * Used by PNG/WebP truncation pads so uniform dark night frames are not
 * rejected as empty bottom bands (uniform-color check remains separate).
 *
 * @param resource|\GdImage $img GD image
 * @param int $height Image height
 * @param int $width Image width
 * @param int $stepX Horizontal sample stride
 * @return bool
 */
function midFrameHasTexture($img, int $height, int $width, int $stepX): bool
{
    $midVarianceMin = defined('WEBCAM_ERROR_TRUNCATION_PAD_MID_VARIANCE_MIN')
        ? (float) WEBCAM_ERROR_TRUNCATION_PAD_MID_VARIANCE_MIN
        : 20.0;
    $midY = (int) floor($height / 2);

    return sampleRowBrightnessVariance($img, $midY, $width, $stepX) >= $midVarianceMin;
}

/**
 * Walk upward from the bottom while $isEmptyRow is true; reject when empty_rows >= min.
 *
 * @param resource|\GdImage $img GD image
 * @param int $width Image width
 * @param int $height Image height
 * @param int $rowStep Coarse vertical stride
 * @param int $stepX Horizontal sample stride
 * @param int $minEmptyRows Minimum contiguous empty rows to reject
 * @param string $reasonPrefix Prefix for rejection reason string
 * @param callable $isEmptyRow fn($img, int $y, int $width, int $stepX): bool
 * @return array{
 *   is_empty_band: bool,
 *   coverage: float,
 *   content_rows: int,
 *   empty_rows: int,
 *   reason: string
 * }
 */
function measureContiguousBottomBand(
    $img,
    int $width,
    int $height,
    int $rowStep,
    int $stepX,
    int $minEmptyRows,
    string $reasonPrefix,
    callable $isEmptyRow
): array {
    $firstEmptyY = null;
    for ($y = $height - 1; $y >= 0; $y -= $rowStep) {
        if ($isEmptyRow($img, $y, $width, $stepX)) {
            $firstEmptyY = $y;
            continue;
        }
        break;
    }

    if ($firstEmptyY === null) {
        return [
            'is_empty_band' => false,
            'coverage' => 1.0,
            'content_rows' => $height,
            'empty_rows' => 0,
            'reason' => '',
        ];
    }

    $transitionY = $firstEmptyY;
    for ($y = $firstEmptyY; $y >= 0; $y--) {
        if ($isEmptyRow($img, $y, $width, $stepX)) {
            $transitionY = $y;
            continue;
        }
        break;
    }

    $contentRows = $transitionY;
    $emptyRows = $height - $contentRows;
    $coverage = $height > 0 ? ($contentRows / $height) : 0.0;

    if ($emptyRows >= $minEmptyRows) {
        return [
            'is_empty_band' => true,
            'coverage' => $coverage,
            'content_rows' => $contentRows,
            'empty_rows' => $emptyRows,
            'reason' => sprintf(
                '%s%d_coverage_%.3f_content_%d',
                $reasonPrefix,
                $emptyRows,
                $coverage,
                $contentRows
            ),
        ];
    }

    return [
        'is_empty_band' => false,
        'coverage' => $coverage,
        'content_rows' => $contentRows,
        'empty_rows' => $emptyRows,
        'reason' => '',
    ];
}

/**
 * Detect GD mid-grey fill band at the bottom of a decoded image (truncated JPEG).
 *
 * JPEG encodes top-to-bottom. Truncation often yields a full canvas with undecoded
 * rows filled as solid mid-grey (128,128,128) - not browser-green. Reject when a
 * contiguous decoder-grey band from the bottom is at least
 * WEBCAM_ERROR_EMPTY_BAND_MIN_EMPTY_ROWS deep (default 1). Night scenes are darker
 * and/or textured and do not match mid-grey fill.
 *
 * @param resource|\GdImage $img GD image resource
 * @param int $width Image width
 * @param int $height Image height
 * @return array{
 *   is_empty_band: bool,
 *   coverage: float,
 *   content_rows: int,
 *   empty_rows: int,
 *   reason: string
 * }
 */
function detectEmptyBottomBand($img, int $width, int $height): array
{
    $rowStep = defined('WEBCAM_ERROR_EMPTY_BAND_ROW_STEP')
        ? max(1, (int) WEBCAM_ERROR_EMPTY_BAND_ROW_STEP)
        : 4;
    $sampleCount = defined('WEBCAM_ERROR_EMPTY_BAND_SAMPLE_COUNT')
        ? max(5, (int) WEBCAM_ERROR_EMPTY_BAND_SAMPLE_COUNT)
        : 40;
    $minEmptyRows = defined('WEBCAM_ERROR_EMPTY_BAND_MIN_EMPTY_ROWS')
        ? max(1, (int) WEBCAM_ERROR_EMPTY_BAND_MIN_EMPTY_ROWS)
        : 1;
    $stepX = max(1, (int) floor($width / $sampleCount));

    return measureContiguousBottomBand(
        $img,
        $width,
        $height,
        $rowStep,
        $stepX,
        $minEmptyRows,
        'empty_band_midgrey_rows_',
        static function ($img, int $y, int $width, int $stepX): bool {
            return isDecoderGreyFillRow($img, $y, $width, $stepX);
        }
    );
}

/**
 * Row is near-black and low-variance (PNG incomplete paint / empty pad).
 *
 * @param resource|\GdImage $img GD image
 * @param int $y Row index
 * @param int $width Image width
 * @param int $stepX Horizontal sample stride
 * @return bool
 */
function isPngSolidEmptyFillRow($img, int $y, int $width, int $stepX): bool
{
    $varianceThreshold = defined('WEBCAM_ERROR_PNG_EMPTY_BAND_VARIANCE_THRESHOLD')
        ? (float) WEBCAM_ERROR_PNG_EMPTY_BAND_VARIANCE_THRESHOLD
        : 5.0;
    $maxBrightness = defined('WEBCAM_ERROR_PNG_EMPTY_BAND_MAX_BRIGHTNESS')
        ? (float) WEBCAM_ERROR_PNG_EMPTY_BAND_MAX_BRIGHTNESS
        : 24.0;

    $brightnesses = [];
    for ($x = (int) floor($stepX / 2); $x < $width; $x += $stepX) {
        $rgb = imagecolorat($img, $x, $y);
        $r = ($rgb >> 16) & 0xFF;
        $g = ($rgb >> 8) & 0xFF;
        $b = $rgb & 0xFF;
        // Fully transparent truecolor samples count as empty pad
        if ((($rgb >> 24) & 0x7F) >= 127) {
            $brightnesses[] = 0.0;
            continue;
        }
        $brightnesses[] = ($r + $g + $b) / 3.0;
    }

    if (count($brightnesses) < 3) {
        return false;
    }

    $mean = array_sum($brightnesses) / count($brightnesses);
    if ($mean > $maxBrightness) {
        return false;
    }

    return calculateVariance($brightnesses) <= $varianceThreshold;
}

/**
 * Row is low-variance flat pad (WebP), independent of mid-grey brightness.
 *
 * @param resource|\GdImage $img GD image
 * @param int $y Row index
 * @param int $width Image width
 * @param int $stepX Horizontal sample stride
 * @return bool
 */
function isWebpFlatPadRow($img, int $y, int $width, int $stepX): bool
{
    $varianceThreshold = defined('WEBCAM_ERROR_WEBP_PAD_VARIANCE_THRESHOLD')
        ? (float) WEBCAM_ERROR_WEBP_PAD_VARIANCE_THRESHOLD
        : 5.0;
    $chromaMax = defined('WEBCAM_ERROR_WEBP_PAD_CHROMA_MAX')
        ? (float) WEBCAM_ERROR_WEBP_PAD_CHROMA_MAX
        : 12.0;

    $brightnesses = [];
    $chromaSum = 0.0;
    $n = 0;
    for ($x = (int) floor($stepX / 2); $x < $width; $x += $stepX) {
        $rgb = imagecolorat($img, $x, $y);
        $r = ($rgb >> 16) & 0xFF;
        $g = ($rgb >> 8) & 0xFF;
        $b = $rgb & 0xFF;
        $brightnesses[] = ($r + $g + $b) / 3.0;
        $chromaSum += (max($r, $g, $b) - min($r, $g, $b));
        $n++;
    }

    if ($n < 3) {
        return false;
    }

    if (($chromaSum / $n) > $chromaMax) {
        return false;
    }

    return calculateVariance($brightnesses) <= $varianceThreshold;
}

/**
 * Brightness variance across one row (mid-frame texture gate for PNG/WebP pads).
 *
 * @param resource|\GdImage $img GD image
 * @param int $y Row index
 * @param int $width Image width
 * @param int $stepX Horizontal sample stride
 * @return float
 */
function sampleRowBrightnessVariance($img, int $y, int $width, int $stepX): float
{
    $brightnesses = [];
    for ($x = (int) floor($stepX / 2); $x < $width; $x += $stepX) {
        $rgb = imagecolorat($img, $x, $y);
        $r = ($rgb >> 16) & 0xFF;
        $g = ($rgb >> 8) & 0xFF;
        $b = $rgb & 0xFF;
        $brightnesses[] = ($r + $g + $b) / 3.0;
    }

    return calculateVariance($brightnesses);
}

/**
 * Row matches GD truncated-JPEG fill: low variance, neutral chroma, mean near 128.
 * Dark night rows fail the mid-grey brightness gate.
 *
 * @param resource|\GdImage $img GD image
 * @param int $y Row index
 * @param int $width Image width
 * @param int $stepX Horizontal sample stride
 * @return bool
 */
function isDecoderGreyFillRow($img, int $y, int $width, int $stepX): bool
{
    $varianceThreshold = defined('WEBCAM_ERROR_EMPTY_BAND_VARIANCE_THRESHOLD')
        ? (float) WEBCAM_ERROR_EMPTY_BAND_VARIANCE_THRESHOLD
        : 5.0;
    $greyTarget = defined('WEBCAM_ERROR_EMPTY_BAND_GREY_TARGET')
        ? (float) WEBCAM_ERROR_EMPTY_BAND_GREY_TARGET
        : 128.0;
    $greyTolerance = defined('WEBCAM_ERROR_EMPTY_BAND_GREY_TOLERANCE')
        ? (float) WEBCAM_ERROR_EMPTY_BAND_GREY_TOLERANCE
        : 16.0;
    $chromaMax = defined('WEBCAM_ERROR_EMPTY_BAND_CHROMA_MAX')
        ? (float) WEBCAM_ERROR_EMPTY_BAND_CHROMA_MAX
        : 10.0;

    $redValues = [];
    $greenValues = [];
    $blueValues = [];

    for ($x = (int) floor($stepX / 2); $x < $width; $x += $stepX) {
        $rgb = imagecolorat($img, $x, $y);
        $redValues[] = ($rgb >> 16) & 0xFF;
        $greenValues[] = ($rgb >> 8) & 0xFF;
        $blueValues[] = $rgb & 0xFF;
    }

    if (count($redValues) < 5) {
        return false;
    }

    $rowVariance = max(
        calculateVariance($redValues),
        calculateVariance($greenValues),
        calculateVariance($blueValues)
    );
    if ($rowVariance >= $varianceThreshold) {
        return false;
    }

    $avgR = array_sum($redValues) / count($redValues);
    $avgG = array_sum($greenValues) / count($greenValues);
    $avgB = array_sum($blueValues) / count($blueValues);
    $chroma = max(abs($avgR - $avgG), abs($avgG - $avgB), abs($avgR - $avgB));
    if ($chroma > $chromaMax) {
        return false;
    }

    $mean = ($avgR + $avgG + $avgB) / 3.0;
    return abs($mean - $greyTarget) <= $greyTolerance;
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

