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
require_once __DIR__ . '/exif-utils.php';

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
    
    // Copy EXIF metadata from source to transformed image
    // GD library strips all EXIF, so we must restore it from source
    copyExifMetadata($sourcePath, $cachePath);
    
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

// ============================================================================
// FAA Profile Transform Functions
// ============================================================================

// FAA WCPO preferred dimensions
if (!defined('FAA_PREFERRED_WIDTH')) {
    define('FAA_PREFERRED_WIDTH', 1280);
}
if (!defined('FAA_PREFERRED_HEIGHT')) {
    define('FAA_PREFERRED_HEIGHT', 960);
}
if (!defined('FAA_MINIMUM_WIDTH')) {
    define('FAA_MINIMUM_WIDTH', 640);
}
if (!defined('FAA_MINIMUM_HEIGHT')) {
    define('FAA_MINIMUM_HEIGHT', 480);
}

// Default FAA crop margins (percentages) - built-in fallback
if (!defined('FAA_DEFAULT_MARGIN_TOP')) {
    define('FAA_DEFAULT_MARGIN_TOP', 7);
}
if (!defined('FAA_DEFAULT_MARGIN_BOTTOM')) {
    define('FAA_DEFAULT_MARGIN_BOTTOM', 4);
}
if (!defined('FAA_DEFAULT_MARGIN_LEFT')) {
    define('FAA_DEFAULT_MARGIN_LEFT', 0);
}
if (!defined('FAA_DEFAULT_MARGIN_RIGHT')) {
    define('FAA_DEFAULT_MARGIN_RIGHT', 4);
}

/**
 * Calculate FAA crop margins in pixels from percentage values
 * 
 * @param int $sourceWidth Source image width
 * @param int $sourceHeight Source image height
 * @param array $margins Margin percentages (top, bottom, left, right)
 * @return array{top: int, bottom: int, left: int, right: int} Margins in pixels
 */
function calculateFaaMargins(int $sourceWidth, int $sourceHeight, array $margins): array
{
    return [
        'top' => (int) round($sourceHeight * (($margins['top'] ?? 0) / 100)),
        'bottom' => (int) round($sourceHeight * (($margins['bottom'] ?? 0) / 100)),
        'left' => (int) round($sourceWidth * (($margins['left'] ?? 0) / 100)),
        'right' => (int) round($sourceWidth * (($margins['right'] ?? 0) / 100)),
    ];
}

/**
 * Determine FAA output size based on safe zone dimensions (quality-capping)
 * 
 * Returns 1280x960 if the safe zone can support it without upscaling,
 * otherwise returns 640x480 (FAA minimum).
 * 
 * @param int $safeWidth Safe zone width after margins applied
 * @param int $safeHeight Safe zone height after margins applied
 * @return array{width: int, height: int} Output dimensions
 */
function determineFaaOutputSize(int $safeWidth, int $safeHeight): array
{
    // Calculate what the 4:3 crop dimensions would be from the safe zone
    $targetAspect = 4 / 3;
    $safeAspect = $safeWidth / $safeHeight;
    
    if ($safeAspect > $targetAspect) {
        // Safe zone is wider than 4:3 - will crop sides
        $cropWidth = (int) round($safeHeight * $targetAspect);
        $cropHeight = $safeHeight;
    } else {
        // Safe zone is taller than 4:3 - will crop top/bottom
        $cropWidth = $safeWidth;
        $cropHeight = (int) round($safeWidth / $targetAspect);
    }
    
    // Can we get 1280x960 without upscaling?
    if ($cropWidth >= FAA_PREFERRED_WIDTH && $cropHeight >= FAA_PREFERRED_HEIGHT) {
        return ['width' => FAA_PREFERRED_WIDTH, 'height' => FAA_PREFERRED_HEIGHT];
    }
    
    // Fall back to FAA minimum size
    return ['width' => FAA_MINIMUM_WIDTH, 'height' => FAA_MINIMUM_HEIGHT];
}

/**
 * Transform an image for FAA profile with margins and quality-capping
 * 
 * Applies crop margins to exclude timestamps/watermarks, then center-crops
 * to 4:3 aspect ratio, and scales to appropriate FAA dimensions.
 * 
 * @param string $sourcePath Path to source image
 * @param array $margins Margin percentages (top, bottom, left, right)
 * @return string|null Binary JPEG image data or null on failure
 */
function transformImageFaa(string $sourcePath, array $margins): ?string
{
    if (!file_exists($sourcePath)) {
        aviationwx_log('warning', 'FAA transform source not found', [
            'source' => $sourcePath,
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
    
    // Calculate margins in pixels
    $marginPx = calculateFaaMargins($sourceWidth, $sourceHeight, $margins);
    
    // Calculate safe zone dimensions after margins
    $safeWidth = $sourceWidth - $marginPx['left'] - $marginPx['right'];
    $safeHeight = $sourceHeight - $marginPx['top'] - $marginPx['bottom'];
    
    // Validate safe zone is large enough
    if ($safeWidth < FAA_MINIMUM_WIDTH || $safeHeight < FAA_MINIMUM_HEIGHT) {
        imagedestroy($sourceImage);
        aviationwx_log('warning', 'FAA transform safe zone too small', [
            'source_width' => $sourceWidth,
            'source_height' => $sourceHeight,
            'safe_width' => $safeWidth,
            'safe_height' => $safeHeight,
            'margins' => $margins,
        ], 'api');
        return null;
    }
    
    // Determine output size (quality-capped)
    $outputSize = determineFaaOutputSize($safeWidth, $safeHeight);
    $targetWidth = $outputSize['width'];
    $targetHeight = $outputSize['height'];
    
    // Calculate center-crop region within the safe zone for 4:3 aspect ratio
    $targetAspect = $targetWidth / $targetHeight;
    $safeAspect = $safeWidth / $safeHeight;
    
    if ($safeAspect > $targetAspect) {
        // Safe zone is wider than target - crop sides from safe zone
        $cropHeight = $safeHeight;
        $cropWidth = (int) round($safeHeight * $targetAspect);
        $cropX = $marginPx['left'] + (int) round(($safeWidth - $cropWidth) / 2);
        $cropY = $marginPx['top'];
    } else {
        // Safe zone is taller than target - crop top/bottom from safe zone
        $cropWidth = $safeWidth;
        $cropHeight = (int) round($safeWidth / $targetAspect);
        $cropX = $marginPx['left'];
        $cropY = $marginPx['top'] + (int) round(($safeHeight - $cropHeight) / 2);
    }
    
    // Create output image
    $outputImage = imagecreatetruecolor($targetWidth, $targetHeight);
    if ($outputImage === false) {
        imagedestroy($sourceImage);
        aviationwx_log('error', 'FAA transform failed to create output', [], 'api');
        return null;
    }
    
    // Resample: crop from safe zone and scale in one operation
    $result = imagecopyresampled(
        $outputImage,
        $sourceImage,
        0, 0,                           // Destination X, Y
        $cropX, $cropY,                 // Source X, Y (includes margin offset)
        $targetWidth, $targetHeight,    // Destination width, height
        $cropWidth, $cropHeight         // Source width, height (crop size)
    );
    
    imagedestroy($sourceImage);
    
    if (!$result) {
        imagedestroy($outputImage);
        aviationwx_log('error', 'FAA transform resample failed', [], 'api');
        return null;
    }
    
    // Output to JPEG (FAA requires JPG)
    ob_start();
    $outputResult = imagejpeg($outputImage, null, IMAGE_TRANSFORM_JPEG_QUALITY);
    $imageData = ob_get_clean();
    imagedestroy($outputImage);
    
    if (!$outputResult || $imageData === false || strlen($imageData) === 0) {
        aviationwx_log('error', 'FAA transform output failed', [], 'api');
        return null;
    }
    
    return $imageData;
}

/**
 * Get or create an FAA-profile transformed image
 * 
 * Returns cached version if available, otherwise transforms the source
 * image with FAA margins and caches the result.
 * 
 * @param string $airportId Airport identifier
 * @param int $camIndex Camera index
 * @param int $timestamp Image timestamp
 * @param array $margins Margin percentages
 * @return array{path: string, width: int, height: int}|null Path and dimensions, or null on failure
 */
function getFaaTransformedImagePath(
    string $airportId,
    int $camIndex,
    int $timestamp,
    array $margins
): ?array {
    // Build cache path for FAA transformed image
    $cacheDir = getWebcamCameraDir($airportId, $camIndex);
    $cachePath = $cacheDir . '/' . $timestamp . '_faa.jpg';
    
    // Check if cached version exists and get its dimensions
    if (file_exists($cachePath) && filesize($cachePath) > 0) {
        $info = @getimagesize($cachePath);
        if ($info !== false) {
            return [
                'path' => $cachePath,
                'width' => $info[0],
                'height' => $info[1],
            ];
        }
    }
    
    // Find source image (prefer original JPG)
    $sourcePath = getWebcamOriginalTimestampedPath($airportId, $camIndex, $timestamp, 'jpg');
    
    if (!file_exists($sourcePath)) {
        // Try WebP source
        $sourcePath = getWebcamOriginalTimestampedPath($airportId, $camIndex, $timestamp, 'webp');
    }
    
    if (!file_exists($sourcePath)) {
        aviationwx_log('warning', 'FAA transform no source found', [
            'airport' => $airportId,
            'cam' => $camIndex,
            'timestamp' => $timestamp,
        ], 'api');
        return null;
    }
    
    // Transform the image
    $imageData = transformImageFaa($sourcePath, $margins);
    
    if ($imageData === null) {
        return null;
    }
    
    // Ensure cache directory exists
    if (!is_dir($cacheDir)) {
        if (!@mkdir($cacheDir, 0755, true)) {
            aviationwx_log('error', 'FAA transform cache dir creation failed', [
                'dir' => $cacheDir,
            ], 'api');
            return null;
        }
    }
    
    // Write atomically via temp file
    $tempPath = $cachePath . '.tmp.' . getmypid();
    $bytesWritten = @file_put_contents($tempPath, $imageData);
    
    if ($bytesWritten === false || $bytesWritten === 0) {
        @unlink($tempPath);
        aviationwx_log('error', 'FAA transform cache write failed', [
            'path' => $cachePath,
        ], 'api');
        return null;
    }
    
    // Atomic rename
    if (!@rename($tempPath, $cachePath)) {
        @unlink($tempPath);
        aviationwx_log('error', 'FAA transform cache rename failed', [
            'path' => $cachePath,
        ], 'api');
        return null;
    }
    
    // Copy EXIF metadata from source to FAA-transformed image
    // GD library strips all EXIF, so we must restore it from source
    copyExifMetadata($sourcePath, $cachePath);
    
    // Get dimensions from the created file
    $info = @getimagesize($cachePath);
    if ($info === false) {
        return null;
    }
    
    return [
        'path' => $cachePath,
        'width' => $info[0],
        'height' => $info[1],
    ];
}
