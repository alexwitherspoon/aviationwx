<?php
/**
 * Image Transformation Library
 * 
 * Provides on-the-fly image cropping and resizing for the public API.
 * Used to serve images in specific dimensions (e.g., FAA weathercam 1280x960).
 * 
 * Features:
 * - Center-crop to target aspect ratio
 * - Scale to exact dimensions
 * - JPEG and WebP output support
 * - Caching of transformed images
 */

require_once __DIR__ . '/cache-paths.php';
require_once __DIR__ . '/logger.php';

// Maximum dimensions to prevent abuse
if (!defined('IMAGE_TRANSFORM_MAX_WIDTH')) {
    define('IMAGE_TRANSFORM_MAX_WIDTH', 3840);
}
if (!defined('IMAGE_TRANSFORM_MAX_HEIGHT')) {
    define('IMAGE_TRANSFORM_MAX_HEIGHT', 2160);
}
if (!defined('IMAGE_TRANSFORM_MIN_DIMENSION')) {
    define('IMAGE_TRANSFORM_MIN_DIMENSION', 16);
}

// JPEG quality for transformed images
if (!defined('IMAGE_TRANSFORM_JPEG_QUALITY')) {
    define('IMAGE_TRANSFORM_JPEG_QUALITY', 85);
}
if (!defined('IMAGE_TRANSFORM_WEBP_QUALITY')) {
    define('IMAGE_TRANSFORM_WEBP_QUALITY', 82);
}

/**
 * Transform an image to specific dimensions with center-crop
 * 
 * If the source aspect ratio differs from the target, the image is
 * center-cropped to match the target aspect ratio before scaling.
 * 
 * @param string $sourcePath Path to source image
 * @param int $targetWidth Target width in pixels
 * @param int $targetHeight Target height in pixels
 * @param string $format Output format ('jpg' or 'webp')
 * @return string|null Binary image data or null on failure
 */
function transformImage(string $sourcePath, int $targetWidth, int $targetHeight, string $format = 'jpg'): ?string
{
    if (!file_exists($sourcePath)) {
        aviationwx_log('warning', 'image transform source not found', [
            'source' => $sourcePath,
        ], 'api');
        return null;
    }
    
    // Validate dimensions
    if ($targetWidth < IMAGE_TRANSFORM_MIN_DIMENSION || 
        $targetHeight < IMAGE_TRANSFORM_MIN_DIMENSION ||
        $targetWidth > IMAGE_TRANSFORM_MAX_WIDTH || 
        $targetHeight > IMAGE_TRANSFORM_MAX_HEIGHT) {
        aviationwx_log('warning', 'image transform invalid dimensions', [
            'width' => $targetWidth,
            'height' => $targetHeight,
        ], 'api');
        return null;
    }
    
    // Load source image
    $sourceImage = loadSourceImage($sourcePath);
    if ($sourceImage === null) {
        return null;
    }
    
    $sourceWidth = imagesx($sourceImage);
    $sourceHeight = imagesy($sourceImage);
    
    // Calculate crop region for center-crop to target aspect ratio
    $cropRegion = calculateCenterCrop($sourceWidth, $sourceHeight, $targetWidth, $targetHeight);
    
    // Create output image
    $outputImage = imagecreatetruecolor($targetWidth, $targetHeight);
    if ($outputImage === false) {
        imagedestroy($sourceImage);
        aviationwx_log('error', 'image transform failed to create output', [], 'api');
        return null;
    }
    
    // Preserve transparency for WebP
    if ($format === 'webp') {
        imagealphablending($outputImage, false);
        imagesavealpha($outputImage, true);
    }
    
    // Resample: crop and scale in one operation
    $result = imagecopyresampled(
        $outputImage,
        $sourceImage,
        0, 0,                                    // Destination X, Y
        $cropRegion['x'], $cropRegion['y'],      // Source X, Y (crop offset)
        $targetWidth, $targetHeight,             // Destination width, height
        $cropRegion['width'], $cropRegion['height'] // Source width, height (crop size)
    );
    
    imagedestroy($sourceImage);
    
    if (!$result) {
        imagedestroy($outputImage);
        aviationwx_log('error', 'image transform resample failed', [], 'api');
        return null;
    }
    
    // Output to string
    ob_start();
    $outputResult = false;
    
    if ($format === 'webp' && function_exists('imagewebp')) {
        $outputResult = imagewebp($outputImage, null, IMAGE_TRANSFORM_WEBP_QUALITY);
    } else {
        $outputResult = imagejpeg($outputImage, null, IMAGE_TRANSFORM_JPEG_QUALITY);
    }
    
    $imageData = ob_get_clean();
    imagedestroy($outputImage);
    
    if (!$outputResult || $imageData === false || strlen($imageData) === 0) {
        aviationwx_log('error', 'image transform output failed', [
            'format' => $format,
        ], 'api');
        return null;
    }
    
    return $imageData;
}

/**
 * Transform an image and cache the result
 * 
 * @param string $sourcePath Path to source image
 * @param string $cachePath Path to store cached result
 * @param int $targetWidth Target width in pixels
 * @param int $targetHeight Target height in pixels
 * @param string $format Output format ('jpg' or 'webp')
 * @return bool True if transform and cache succeeded
 */
function transformAndCacheImage(
    string $sourcePath,
    string $cachePath,
    int $targetWidth,
    int $targetHeight,
    string $format = 'jpg'
): bool {
    $imageData = transformImage($sourcePath, $targetWidth, $targetHeight, $format);
    
    if ($imageData === null) {
        return false;
    }
    
    // Ensure cache directory exists
    $cacheDir = dirname($cachePath);
    if (!is_dir($cacheDir)) {
        if (!@mkdir($cacheDir, 0755, true)) {
            aviationwx_log('error', 'image transform cache dir creation failed', [
                'dir' => $cacheDir,
            ], 'api');
            return false;
        }
    }
    
    // Write atomically via temp file
    $tempPath = $cachePath . '.tmp.' . getmypid();
    $bytesWritten = @file_put_contents($tempPath, $imageData);
    
    if ($bytesWritten === false || $bytesWritten === 0) {
        @unlink($tempPath);
        aviationwx_log('error', 'image transform cache write failed', [
            'path' => $cachePath,
        ], 'api');
        return false;
    }
    
    // Atomic rename
    if (!@rename($tempPath, $cachePath)) {
        @unlink($tempPath);
        aviationwx_log('error', 'image transform cache rename failed', [
            'path' => $cachePath,
        ], 'api');
        return false;
    }
    
    return true;
}

/**
 * Get or create a transformed image
 * 
 * Returns cached version if available and valid, otherwise transforms
 * the source image and caches the result.
 * 
 * @param string $airportId Airport identifier
 * @param int $camIndex Camera index
 * @param int $timestamp Image timestamp
 * @param int $targetWidth Target width
 * @param int $targetHeight Target height
 * @param string $format Output format ('jpg' or 'webp')
 * @return string|null Path to transformed image or null on failure
 */
function getTransformedImagePath(
    string $airportId,
    int $camIndex,
    int $timestamp,
    int $targetWidth,
    int $targetHeight,
    string $format = 'jpg'
): ?string {
    // Build cache path for transformed image
    $cacheDir = getWebcamCameraDir($airportId, $camIndex);
    $cachePath = $cacheDir . '/' . $timestamp . '_' . $targetWidth . 'x' . $targetHeight . '.' . $format;
    
    // Return cached version if exists
    if (file_exists($cachePath) && filesize($cachePath) > 0) {
        return $cachePath;
    }
    
    // Find source image (prefer original JPG)
    $sourcePath = getWebcamOriginalTimestampedPath($airportId, $camIndex, $timestamp, 'jpg');
    
    if (!file_exists($sourcePath)) {
        // Try WebP source
        $sourcePath = getWebcamOriginalTimestampedPath($airportId, $camIndex, $timestamp, 'webp');
    }
    
    if (!file_exists($sourcePath)) {
        aviationwx_log('warning', 'image transform no source found', [
            'airport' => $airportId,
            'cam' => $camIndex,
            'timestamp' => $timestamp,
        ], 'api');
        return null;
    }
    
    // Transform and cache
    if (transformAndCacheImage($sourcePath, $cachePath, $targetWidth, $targetHeight, $format)) {
        return $cachePath;
    }
    
    return null;
}

/**
 * Load a source image from file
 * 
 * @param string $path Path to image file
 * @return \GdImage|null GD image resource or null on failure
 */
function loadSourceImage(string $path): ?\GdImage
{
    $imageInfo = @getimagesize($path);
    if ($imageInfo === false) {
        aviationwx_log('warning', 'image transform could not read image info', [
            'path' => $path,
        ], 'api');
        return null;
    }
    
    $mimeType = $imageInfo['mime'] ?? '';
    
    switch ($mimeType) {
        case 'image/jpeg':
            $image = @imagecreatefromjpeg($path);
            break;
        case 'image/webp':
            if (function_exists('imagecreatefromwebp')) {
                $image = @imagecreatefromwebp($path);
            } else {
                aviationwx_log('warning', 'image transform webp not supported', [], 'api');
                return null;
            }
            break;
        case 'image/png':
            $image = @imagecreatefrompng($path);
            break;
        default:
            aviationwx_log('warning', 'image transform unsupported format', [
                'mime' => $mimeType,
            ], 'api');
            return null;
    }
    
    if ($image === false) {
        aviationwx_log('warning', 'image transform load failed', [
            'path' => $path,
            'mime' => $mimeType,
        ], 'api');
        return null;
    }
    
    return $image;
}

/**
 * Calculate center-crop region to achieve target aspect ratio
 * 
 * @param int $sourceWidth Source image width
 * @param int $sourceHeight Source image height
 * @param int $targetWidth Target width (for aspect ratio calculation)
 * @param int $targetHeight Target height (for aspect ratio calculation)
 * @return array{x: int, y: int, width: int, height: int} Crop region
 */
function calculateCenterCrop(
    int $sourceWidth,
    int $sourceHeight,
    int $targetWidth,
    int $targetHeight
): array {
    $sourceAspect = $sourceWidth / $sourceHeight;
    $targetAspect = $targetWidth / $targetHeight;
    
    // Tolerance for aspect ratio comparison (avoid unnecessary crops)
    $aspectTolerance = 0.01;
    
    if (abs($sourceAspect - $targetAspect) < $aspectTolerance) {
        // Aspect ratios match (within tolerance) - no crop needed
        return [
            'x' => 0,
            'y' => 0,
            'width' => $sourceWidth,
            'height' => $sourceHeight,
        ];
    }
    
    if ($sourceAspect > $targetAspect) {
        // Source is wider than target - crop sides
        $cropHeight = $sourceHeight;
        $cropWidth = (int) round($sourceHeight * $targetAspect);
        $cropX = (int) round(($sourceWidth - $cropWidth) / 2);
        $cropY = 0;
    } else {
        // Source is taller than target - crop top/bottom
        $cropWidth = $sourceWidth;
        $cropHeight = (int) round($sourceWidth / $targetAspect);
        $cropX = 0;
        $cropY = (int) round(($sourceHeight - $cropHeight) / 2);
    }
    
    return [
        'x' => $cropX,
        'y' => $cropY,
        'width' => $cropWidth,
        'height' => $cropHeight,
    ];
}

/**
 * Validate transform parameters
 * 
 * @param int|null $width Requested width
 * @param int|null $height Requested height
 * @param string $format Requested format
 * @return array{valid: bool, width: int|null, height: int|null, format: string, error: string|null}
 */
function validateTransformParams(?int $width, ?int $height, string $format): array
{
    $result = [
        'valid' => true,
        'width' => $width,
        'height' => $height,
        'format' => $format,
        'error' => null,
    ];
    
    // Validate format
    if (!in_array($format, ['jpg', 'webp'])) {
        $result['format'] = 'jpg';
    }
    
    // Validate width
    if ($width !== null) {
        if ($width < IMAGE_TRANSFORM_MIN_DIMENSION) {
            $result['valid'] = false;
            $result['error'] = 'Width must be at least ' . IMAGE_TRANSFORM_MIN_DIMENSION . ' pixels';
            return $result;
        }
        if ($width > IMAGE_TRANSFORM_MAX_WIDTH) {
            $result['valid'] = false;
            $result['error'] = 'Width cannot exceed ' . IMAGE_TRANSFORM_MAX_WIDTH . ' pixels';
            return $result;
        }
    }
    
    // Validate height
    if ($height !== null) {
        if ($height < IMAGE_TRANSFORM_MIN_DIMENSION) {
            $result['valid'] = false;
            $result['error'] = 'Height must be at least ' . IMAGE_TRANSFORM_MIN_DIMENSION . ' pixels';
            return $result;
        }
        if ($height > IMAGE_TRANSFORM_MAX_HEIGHT) {
            $result['valid'] = false;
            $result['error'] = 'Height cannot exceed ' . IMAGE_TRANSFORM_MAX_HEIGHT . ' pixels';
            return $result;
        }
    }
    
    return $result;
}

/**
 * Scale dimensions proportionally based on a single dimension
 * 
 * @param int $sourceWidth Source width
 * @param int $sourceHeight Source height
 * @param int|null $targetWidth Target width (null to calculate from height)
 * @param int|null $targetHeight Target height (null to calculate from width)
 * @return array{width: int, height: int}
 */
function calculateScaledDimensions(
    int $sourceWidth,
    int $sourceHeight,
    ?int $targetWidth,
    ?int $targetHeight
): array {
    $sourceAspect = $sourceWidth / $sourceHeight;
    
    if ($targetWidth !== null && $targetHeight !== null) {
        // Both specified - use as-is (will center-crop if needed)
        return ['width' => $targetWidth, 'height' => $targetHeight];
    }
    
    if ($targetWidth !== null) {
        // Calculate height from width
        return [
            'width' => $targetWidth,
            'height' => (int) round($targetWidth / $sourceAspect),
        ];
    }
    
    if ($targetHeight !== null) {
        // Calculate width from height
        return [
            'width' => (int) round($targetHeight * $sourceAspect),
            'height' => $targetHeight,
        ];
    }
    
    // Neither specified - return source dimensions
    return ['width' => $sourceWidth, 'height' => $sourceHeight];
}
