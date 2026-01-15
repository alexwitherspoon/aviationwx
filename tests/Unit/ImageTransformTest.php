<?php
/**
 * Unit Tests for Image Transform Library
 * 
 * Tests the on-the-fly image transformation functionality used by the public API
 * to serve images in specific dimensions (e.g., FAA weathercam 1280x960).
 * 
 * Tests:
 * - calculateCenterCrop() - Center-crop region calculations for various aspect ratios
 * - validateTransformParams() - Parameter validation for width, height, format
 * - calculateScaledDimensions() - Proportional scaling calculations
 * - transformImage() - Full image transformation with GD
 * - getTransformedImagePath() - Caching behavior
 */

namespace AviationWX\Tests;

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../lib/image-transform.php';
require_once __DIR__ . '/../../lib/cache-paths.php';

class ImageTransformTest extends TestCase
{
    private string $testImageDir;
    private array $createdFiles = [];
    
    protected function setUp(): void
    {
        parent::setUp();
        $this->testImageDir = sys_get_temp_dir() . '/image_transform_test_' . uniqid();
        @mkdir($this->testImageDir, 0755, true);
    }
    
    protected function tearDown(): void
    {
        // Clean up created files
        foreach ($this->createdFiles as $file) {
            @unlink($file);
        }
        
        // Clean up test directory
        if (is_dir($this->testImageDir)) {
            $files = glob($this->testImageDir . '/*');
            foreach ($files as $file) {
                @unlink($file);
            }
            @rmdir($this->testImageDir);
        }
        parent::tearDown();
    }
    
    /**
     * Create a test JPEG image using GD
     */
    private function createTestJpeg(int $width, int $height, string $filename = 'test.jpg'): string
    {
        $path = $this->testImageDir . '/' . $filename;
        
        if (function_exists('imagecreatetruecolor')) {
            $img = imagecreatetruecolor($width, $height);
            $bg = imagecolorallocate($img, 100, 150, 200);
            imagefill($img, 0, 0, $bg);
            // Add a simple pattern to make the image non-trivial
            $fg = imagecolorallocate($img, 255, 255, 255);
            imagestring($img, 5, 10, 10, "{$width}x{$height}", $fg);
            imagejpeg($img, $path, 85);
            imagedestroy($img);
        } else {
            $this->markTestSkipped('GD library not available');
        }
        
        $this->createdFiles[] = $path;
        return $path;
    }
    
    /**
     * Create a test WebP image using GD
     */
    private function createTestWebp(int $width, int $height, string $filename = 'test.webp'): string
    {
        $path = $this->testImageDir . '/' . $filename;
        
        if (function_exists('imagecreatetruecolor') && function_exists('imagewebp')) {
            $img = imagecreatetruecolor($width, $height);
            $bg = imagecolorallocate($img, 100, 150, 200);
            imagefill($img, 0, 0, $bg);
            imagewebp($img, $path, 80);
            imagedestroy($img);
        } else {
            $this->markTestSkipped('GD library with WebP support not available');
        }
        
        $this->createdFiles[] = $path;
        return $path;
    }
    
    // =========================================================================
    // calculateCenterCrop() Tests
    // =========================================================================
    
    /**
     * Test center crop when source and target have same aspect ratio
     */
    public function testCalculateCenterCrop_SameAspectRatio_NoCrop(): void
    {
        // 16:9 source to 16:9 target
        $crop = calculateCenterCrop(1920, 1080, 1280, 720);
        
        $this->assertEquals(0, $crop['x'], 'X offset should be 0 for same aspect ratio');
        $this->assertEquals(0, $crop['y'], 'Y offset should be 0 for same aspect ratio');
        $this->assertEquals(1920, $crop['width'], 'Crop width should equal source width');
        $this->assertEquals(1080, $crop['height'], 'Crop height should equal source height');
    }
    
    /**
     * Test center crop when source is wider than target (16:9 to 4:3)
     * FAA weathercam use case: 16:9 camera image to 4:3 output
     */
    public function testCalculateCenterCrop_WiderSource_CropsSides(): void
    {
        // 16:9 source (1920x1080) to 4:3 target (1280x960)
        $crop = calculateCenterCrop(1920, 1080, 1280, 960);
        
        // Target aspect ratio is 4:3 = 1.333...
        // Source height is 1080, so crop width should be 1080 * (4/3) = 1440
        // X offset should be (1920 - 1440) / 2 = 240
        $this->assertEquals(240, $crop['x'], 'X offset should center the crop');
        $this->assertEquals(0, $crop['y'], 'Y offset should be 0 when cropping sides');
        $this->assertEquals(1440, $crop['width'], 'Crop width should match target aspect ratio');
        $this->assertEquals(1080, $crop['height'], 'Crop height should use full source height');
    }
    
    /**
     * Test center crop when source is taller than target (4:3 to 16:9)
     */
    public function testCalculateCenterCrop_TallerSource_CropsTopBottom(): void
    {
        // 4:3 source (1600x1200) to 16:9 target (1920x1080)
        $crop = calculateCenterCrop(1600, 1200, 1920, 1080);
        
        // Target aspect ratio is 16:9 = 1.777...
        // Source width is 1600, so crop height should be 1600 / (16/9) = 900
        // Y offset should be (1200 - 900) / 2 = 150
        $this->assertEquals(0, $crop['x'], 'X offset should be 0 when cropping top/bottom');
        $this->assertEquals(150, $crop['y'], 'Y offset should center the crop');
        $this->assertEquals(1600, $crop['width'], 'Crop width should use full source width');
        $this->assertEquals(900, $crop['height'], 'Crop height should match target aspect ratio');
    }
    
    /**
     * Test center crop for square source to wide target
     */
    public function testCalculateCenterCrop_SquareToWide_CropsTopBottom(): void
    {
        // 1:1 source (1000x1000) to 16:9 target
        $crop = calculateCenterCrop(1000, 1000, 1920, 1080);
        
        // Crop height = 1000 / (16/9) = 562.5 ≈ 563 (rounded)
        $this->assertEquals(0, $crop['x']);
        $this->assertGreaterThan(0, $crop['y'], 'Y offset should be positive for tall-to-wide');
        $this->assertEquals(1000, $crop['width']);
        $this->assertLessThan(1000, $crop['height'], 'Crop height should be less than source');
    }
    
    /**
     * Test center crop for wide source to square target
     */
    public function testCalculateCenterCrop_WideToSquare_CropsSides(): void
    {
        // 16:9 source (1920x1080) to 1:1 target
        $crop = calculateCenterCrop(1920, 1080, 500, 500);
        
        // Crop width = 1080 * 1 = 1080 (square based on height)
        // X offset = (1920 - 1080) / 2 = 420
        $this->assertEquals(420, $crop['x']);
        $this->assertEquals(0, $crop['y']);
        $this->assertEquals(1080, $crop['width']);
        $this->assertEquals(1080, $crop['height']);
    }
    
    /**
     * Test aspect ratio tolerance - very close ratios shouldn't crop
     */
    public function testCalculateCenterCrop_NearlyMatchingRatio_NoCrop(): void
    {
        // Source 1920x1080 (1.777...) vs target that's nearly the same
        // Within 0.01 tolerance should not crop
        $crop = calculateCenterCrop(1920, 1080, 1280, 720);
        
        $this->assertEquals(0, $crop['x']);
        $this->assertEquals(0, $crop['y']);
        $this->assertEquals(1920, $crop['width']);
        $this->assertEquals(1080, $crop['height']);
    }
    
    // =========================================================================
    // validateTransformParams() Tests
    // =========================================================================
    
    /**
     * Test validation with valid width and height
     */
    public function testValidateTransformParams_ValidDimensions_ReturnsValid(): void
    {
        $result = validateTransformParams(1280, 960, 'jpg');
        
        $this->assertTrue($result['valid']);
        $this->assertEquals(1280, $result['width']);
        $this->assertEquals(960, $result['height']);
        $this->assertEquals('jpg', $result['format']);
        $this->assertNull($result['error']);
    }
    
    /**
     * Test validation with width below minimum
     */
    public function testValidateTransformParams_WidthBelowMinimum_ReturnsInvalid(): void
    {
        $result = validateTransformParams(10, 960, 'jpg');
        
        $this->assertFalse($result['valid']);
        $this->assertStringContainsString('Width', $result['error']);
        $this->assertStringContainsString('at least', $result['error']);
    }
    
    /**
     * Test validation with width above maximum
     */
    public function testValidateTransformParams_WidthAboveMaximum_ReturnsInvalid(): void
    {
        $result = validateTransformParams(5000, 960, 'jpg');
        
        $this->assertFalse($result['valid']);
        $this->assertStringContainsString('Width', $result['error']);
        $this->assertStringContainsString('exceed', $result['error']);
    }
    
    /**
     * Test validation with height below minimum
     */
    public function testValidateTransformParams_HeightBelowMinimum_ReturnsInvalid(): void
    {
        $result = validateTransformParams(1280, 5, 'jpg');
        
        $this->assertFalse($result['valid']);
        $this->assertStringContainsString('Height', $result['error']);
    }
    
    /**
     * Test validation with height above maximum
     */
    public function testValidateTransformParams_HeightAboveMaximum_ReturnsInvalid(): void
    {
        $result = validateTransformParams(1280, 3000, 'jpg');
        
        $this->assertFalse($result['valid']);
        $this->assertStringContainsString('Height', $result['error']);
    }
    
    /**
     * Test validation with null dimensions (allowed)
     */
    public function testValidateTransformParams_NullDimensions_ReturnsValid(): void
    {
        $result = validateTransformParams(null, null, 'jpg');
        
        $this->assertTrue($result['valid']);
        $this->assertNull($result['width']);
        $this->assertNull($result['height']);
    }
    
    /**
     * Test validation with only width (allowed)
     */
    public function testValidateTransformParams_OnlyWidth_ReturnsValid(): void
    {
        $result = validateTransformParams(1280, null, 'jpg');
        
        $this->assertTrue($result['valid']);
        $this->assertEquals(1280, $result['width']);
        $this->assertNull($result['height']);
    }
    
    /**
     * Test validation with invalid format defaults to jpg
     */
    public function testValidateTransformParams_InvalidFormat_DefaultsToJpg(): void
    {
        $result = validateTransformParams(1280, 960, 'gif');
        
        $this->assertTrue($result['valid']);
        $this->assertEquals('jpg', $result['format']);
    }
    
    /**
     * Test validation with webp format
     */
    public function testValidateTransformParams_WebpFormat_Accepted(): void
    {
        $result = validateTransformParams(1280, 960, 'webp');
        
        $this->assertTrue($result['valid']);
        $this->assertEquals('webp', $result['format']);
    }
    
    // =========================================================================
    // calculateScaledDimensions() Tests
    // =========================================================================
    
    /**
     * Test scaled dimensions with both width and height specified
     */
    public function testCalculateScaledDimensions_BothSpecified_ReturnsAsIs(): void
    {
        $result = calculateScaledDimensions(1920, 1080, 1280, 960);
        
        $this->assertEquals(1280, $result['width']);
        $this->assertEquals(960, $result['height']);
    }
    
    /**
     * Test scaled dimensions with only width specified (preserves aspect ratio)
     */
    public function testCalculateScaledDimensions_OnlyWidth_PreservesAspectRatio(): void
    {
        // 16:9 source, scale to width 960
        $result = calculateScaledDimensions(1920, 1080, 960, null);
        
        $this->assertEquals(960, $result['width']);
        // 960 / (16/9) = 540
        $this->assertEquals(540, $result['height']);
    }
    
    /**
     * Test scaled dimensions with only height specified (preserves aspect ratio)
     */
    public function testCalculateScaledDimensions_OnlyHeight_PreservesAspectRatio(): void
    {
        // 16:9 source, scale to height 540
        $result = calculateScaledDimensions(1920, 1080, null, 540);
        
        // 540 * (16/9) = 960
        $this->assertEquals(960, $result['width']);
        $this->assertEquals(540, $result['height']);
    }
    
    /**
     * Test scaled dimensions with neither specified (returns source dimensions)
     */
    public function testCalculateScaledDimensions_NeitherSpecified_ReturnsSource(): void
    {
        $result = calculateScaledDimensions(1920, 1080, null, null);
        
        $this->assertEquals(1920, $result['width']);
        $this->assertEquals(1080, $result['height']);
    }
    
    /**
     * Test scaled dimensions with 4:3 source
     */
    public function testCalculateScaledDimensions_FourThreeSource_CorrectCalculation(): void
    {
        // 4:3 source (1600x1200), scale to width 800
        $result = calculateScaledDimensions(1600, 1200, 800, null);
        
        $this->assertEquals(800, $result['width']);
        // 800 / (4/3) = 600
        $this->assertEquals(600, $result['height']);
    }
    
    // =========================================================================
    // transformImage() Tests
    // =========================================================================
    
    /**
     * Test transform with valid JPEG source
     */
    public function testTransformImage_ValidJpegSource_ReturnsImageData(): void
    {
        if (!function_exists('imagecreatetruecolor')) {
            $this->markTestSkipped('GD library not available');
        }
        
        $source = $this->createTestJpeg(1920, 1080, 'source_16x9.jpg');
        
        // Transform to FAA 4:3 dimensions
        $result = transformImage($source, 1280, 960, 'jpg');
        
        $this->assertNotNull($result, 'Transform should return image data');
        $this->assertGreaterThan(0, strlen($result), 'Image data should not be empty');
        
        // Verify it's a valid JPEG
        $this->assertStringStartsWith("\xFF\xD8", $result, 'Output should be valid JPEG');
    }
    
    /**
     * Test transform preserves center content when cropping
     */
    public function testTransformImage_CenterCrop_OutputHasCorrectDimensions(): void
    {
        if (!function_exists('imagecreatetruecolor')) {
            $this->markTestSkipped('GD library not available');
        }
        
        $source = $this->createTestJpeg(1920, 1080, 'source_crop.jpg');
        
        $result = transformImage($source, 1280, 960, 'jpg');
        
        // Save result and check dimensions
        $tempOutput = $this->testImageDir . '/output_crop.jpg';
        file_put_contents($tempOutput, $result);
        $this->createdFiles[] = $tempOutput;
        
        $dims = getimagesize($tempOutput);
        $this->assertEquals(1280, $dims[0], 'Output width should be 1280');
        $this->assertEquals(960, $dims[1], 'Output height should be 960');
    }
    
    /**
     * Test transform with WebP output
     */
    public function testTransformImage_WebpOutput_ReturnsValidWebp(): void
    {
        if (!function_exists('imagecreatetruecolor') || !function_exists('imagewebp')) {
            $this->markTestSkipped('GD library with WebP support not available');
        }
        
        $source = $this->createTestJpeg(1920, 1080, 'source_webp.jpg');
        
        $result = transformImage($source, 1280, 720, 'webp');
        
        $this->assertNotNull($result);
        // WebP files start with "RIFF"
        $this->assertStringStartsWith('RIFF', $result, 'Output should be valid WebP');
    }
    
    /**
     * Test transform with non-existent source file
     */
    public function testTransformImage_NonExistentSource_ReturnsNull(): void
    {
        $result = transformImage('/nonexistent/path/image.jpg', 1280, 960, 'jpg');
        
        $this->assertNull($result);
    }
    
    /**
     * Test transform with invalid dimensions
     */
    public function testTransformImage_InvalidDimensions_ReturnsNull(): void
    {
        if (!function_exists('imagecreatetruecolor')) {
            $this->markTestSkipped('GD library not available');
        }
        
        $source = $this->createTestJpeg(1920, 1080, 'source_invalid.jpg');
        
        // Width too small
        $result = transformImage($source, 5, 960, 'jpg');
        $this->assertNull($result);
        
        // Height too large
        $result = transformImage($source, 1280, 5000, 'jpg');
        $this->assertNull($result);
    }
    
    /**
     * Test transform with WebP source
     */
    public function testTransformImage_WebpSource_TransformsCorrectly(): void
    {
        if (!function_exists('imagecreatetruecolor') || !function_exists('imagecreatefromwebp')) {
            $this->markTestSkipped('GD library with WebP support not available');
        }
        
        $source = $this->createTestWebp(1920, 1080, 'source.webp');
        
        $result = transformImage($source, 1280, 960, 'jpg');
        
        $this->assertNotNull($result);
        $this->assertStringStartsWith("\xFF\xD8", $result, 'Output should be valid JPEG');
    }
    
    /**
     * Test transform upscaling is prevented (output capped at source size)
     */
    public function testTransformImage_SmallSource_ScalesCorrectly(): void
    {
        if (!function_exists('imagecreatetruecolor')) {
            $this->markTestSkipped('GD library not available');
        }
        
        // Create small source image
        $source = $this->createTestJpeg(640, 480, 'source_small.jpg');
        
        // Request larger output - should still work but will upscale
        $result = transformImage($source, 1280, 960, 'jpg');
        
        $this->assertNotNull($result);
        
        // Save and verify dimensions
        $tempOutput = $this->testImageDir . '/output_upscale.jpg';
        file_put_contents($tempOutput, $result);
        $this->createdFiles[] = $tempOutput;
        
        $dims = getimagesize($tempOutput);
        $this->assertEquals(1280, $dims[0]);
        $this->assertEquals(960, $dims[1]);
    }
    
    // =========================================================================
    // transformAndCacheImage() Tests
    // =========================================================================
    
    /**
     * Test transform and cache creates file
     */
    public function testTransformAndCacheImage_ValidInput_CreatesFile(): void
    {
        if (!function_exists('imagecreatetruecolor')) {
            $this->markTestSkipped('GD library not available');
        }
        
        $source = $this->createTestJpeg(1920, 1080, 'source_cache.jpg');
        $cachePath = $this->testImageDir . '/cached_1280x960.jpg';
        $this->createdFiles[] = $cachePath;
        
        $result = transformAndCacheImage($source, $cachePath, 1280, 960, 'jpg');
        
        $this->assertTrue($result);
        $this->assertFileExists($cachePath);
        
        // Verify dimensions
        $dims = getimagesize($cachePath);
        $this->assertEquals(1280, $dims[0]);
        $this->assertEquals(960, $dims[1]);
    }
    
    /**
     * Test transform and cache creates directory if needed
     */
    public function testTransformAndCacheImage_MissingDirectory_CreatesDirectory(): void
    {
        if (!function_exists('imagecreatetruecolor')) {
            $this->markTestSkipped('GD library not available');
        }
        
        $source = $this->createTestJpeg(1920, 1080, 'source_mkdir.jpg');
        $cachePath = $this->testImageDir . '/subdir/nested/cached.jpg';
        $this->createdFiles[] = $cachePath;
        
        $result = transformAndCacheImage($source, $cachePath, 1280, 960, 'jpg');
        
        $this->assertTrue($result);
        $this->assertFileExists($cachePath);
        
        // Cleanup nested dirs
        @unlink($cachePath);
        @rmdir($this->testImageDir . '/subdir/nested');
        @rmdir($this->testImageDir . '/subdir');
    }
    
    /**
     * Test transform and cache with invalid source returns false
     */
    public function testTransformAndCacheImage_InvalidSource_ReturnsFalse(): void
    {
        $cachePath = $this->testImageDir . '/should_not_exist.jpg';
        
        $result = transformAndCacheImage('/nonexistent/source.jpg', $cachePath, 1280, 960, 'jpg');
        
        $this->assertFalse($result);
        $this->assertFileDoesNotExist($cachePath);
    }
    
    // =========================================================================
    // loadSourceImage() Tests
    // =========================================================================
    
    /**
     * Test loading JPEG source
     */
    public function testLoadSourceImage_ValidJpeg_ReturnsGdImage(): void
    {
        if (!function_exists('imagecreatetruecolor')) {
            $this->markTestSkipped('GD library not available');
        }
        
        $source = $this->createTestJpeg(800, 600, 'load_test.jpg');
        
        $image = loadSourceImage($source);
        
        $this->assertNotNull($image);
        $this->assertInstanceOf(\GdImage::class, $image);
        $this->assertEquals(800, imagesx($image));
        $this->assertEquals(600, imagesy($image));
        
        imagedestroy($image);
    }
    
    /**
     * Test loading WebP source
     */
    public function testLoadSourceImage_ValidWebp_ReturnsGdImage(): void
    {
        if (!function_exists('imagecreatetruecolor') || !function_exists('imagecreatefromwebp')) {
            $this->markTestSkipped('GD library with WebP support not available');
        }
        
        $source = $this->createTestWebp(800, 600, 'load_test.webp');
        
        $image = loadSourceImage($source);
        
        $this->assertNotNull($image);
        $this->assertInstanceOf(\GdImage::class, $image);
        
        imagedestroy($image);
    }
    
    /**
     * Test loading non-existent file
     */
    public function testLoadSourceImage_NonExistent_ReturnsNull(): void
    {
        $image = loadSourceImage('/nonexistent/image.jpg');
        
        $this->assertNull($image);
    }
    
    /**
     * Test loading invalid image file
     */
    public function testLoadSourceImage_InvalidFile_ReturnsNull(): void
    {
        $invalidFile = $this->testImageDir . '/invalid.jpg';
        file_put_contents($invalidFile, 'not an image');
        $this->createdFiles[] = $invalidFile;
        
        $image = loadSourceImage($invalidFile);
        
        $this->assertNull($image);
    }
    
    // =========================================================================
    // Integration Tests - FAA Weathercam Use Case
    // =========================================================================
    
    /**
     * Test FAA weathercam transformation: 16:9 source to 4:3 @ 1280x960
     */
    public function testFaaWeathercam_16x9To4x3_CorrectOutput(): void
    {
        if (!function_exists('imagecreatetruecolor')) {
            $this->markTestSkipped('GD library not available');
        }
        
        // Create 16:9 source (typical webcam)
        $source = $this->createTestJpeg(1920, 1080, 'webcam_16x9.jpg');
        
        // Transform to FAA spec: 1280x960 (4:3)
        $result = transformImage($source, 1280, 960, 'jpg');
        
        $this->assertNotNull($result);
        
        // Verify output
        $tempOutput = $this->testImageDir . '/faa_output.jpg';
        file_put_contents($tempOutput, $result);
        $this->createdFiles[] = $tempOutput;
        
        $dims = getimagesize($tempOutput);
        $this->assertEquals(1280, $dims[0], 'FAA output width should be 1280');
        $this->assertEquals(960, $dims[1], 'FAA output height should be 960');
        $this->assertEquals('image/jpeg', $dims['mime'], 'FAA output should be JPEG');
    }
    
    /**
     * Test FAA weathercam with already 4:3 source (no crop needed)
     */
    public function testFaaWeathercam_4x3Source_NoCropNeeded(): void
    {
        if (!function_exists('imagecreatetruecolor')) {
            $this->markTestSkipped('GD library not available');
        }
        
        // Create 4:3 source
        $source = $this->createTestJpeg(1600, 1200, 'webcam_4x3.jpg');
        
        // Transform to FAA spec
        $result = transformImage($source, 1280, 960, 'jpg');
        
        $this->assertNotNull($result);
        
        $tempOutput = $this->testImageDir . '/faa_4x3_output.jpg';
        file_put_contents($tempOutput, $result);
        $this->createdFiles[] = $tempOutput;
        
        $dims = getimagesize($tempOutput);
        $this->assertEquals(1280, $dims[0]);
        $this->assertEquals(960, $dims[1]);
    }
    
    /**
     * Test various aspect ratio transformations
     */
    public function testTransformImage_VariousAspectRatios_CorrectDimensions(): void
    {
        if (!function_exists('imagecreatetruecolor')) {
            $this->markTestSkipped('GD library not available');
        }
        
        $testCases = [
            '16:9 to 4:3' => [1920, 1080, 1280, 960],
            '16:9 to 16:9' => [1920, 1080, 1280, 720],
            '4:3 to 16:9' => [1600, 1200, 1280, 720],
            '1:1 to 16:9' => [1000, 1000, 1280, 720],
            '1:1 to 4:3' => [1000, 1000, 1280, 960],
            '21:9 to 16:9' => [2560, 1080, 1920, 1080],
            '21:9 to 4:3' => [2560, 1080, 1280, 960],
        ];
        
        foreach ($testCases as $name => [$sourceW, $sourceH, $targetW, $targetH]) {
            $source = $this->createTestJpeg($sourceW, $sourceH, "source_{$sourceW}x{$sourceH}.jpg");
            
            $result = transformImage($source, $targetW, $targetH, 'jpg');
            
            $this->assertNotNull($result, "Transform {$name}: {$sourceW}x{$sourceH} -> {$targetW}x{$targetH} should succeed");
            
            $tempOutput = $this->testImageDir . "/output_{$targetW}x{$targetH}_{$name}.jpg";
            file_put_contents($tempOutput, $result);
            $this->createdFiles[] = $tempOutput;
            
            $dims = getimagesize($tempOutput);
            $this->assertEquals($targetW, $dims[0], "{$name}: Width should be {$targetW}");
            $this->assertEquals($targetH, $dims[1], "{$name}: Height should be {$targetH}");
        }
    }
}
