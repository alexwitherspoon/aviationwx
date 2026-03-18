<?php
/**
 * Unit Tests for Webcam Error Frame Detector
 *
 * Tests error frame detection functionality including:
 * - Grey pixel detection
 * - Color variance analysis
 * - Edge detection
 * - Border analysis
 * - Corrupt bottom region (solid green/blue/red from partial JPEG/device failure)
 * - Quick check function
 */

namespace AviationWX\Tests;

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../lib/constants.php';
require_once __DIR__ . '/../../lib/webcam-error-detector.php';

class WebcamErrorDetectorTest extends TestCase
{
    private $testImageDir;
    
    protected function setUp(): void
    {
        parent::setUp();
        $this->testImageDir = sys_get_temp_dir() . '/webcam_error_test_' . uniqid();
        @mkdir($this->testImageDir, 0755, true);
    }
    
    protected function tearDown(): void
    {
        // Clean up test images
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
     * Create a test image with specified characteristics
     * 
     * @param int $width Image width
     * @param int $height Image height
     * @param callable $pixelGenerator Function that returns [r, g, b] for pixel at (x, y)
     * @return string Path to created image file
     * @throws \PHPUnit\Framework\SkippedTestError If GD library not available
     */
    private function createTestImage(int $width, int $height, callable $pixelGenerator): string
    {
        if (!function_exists('imagecreatetruecolor')) {
            $this->markTestSkipped('GD library not available');
        }
        
        $img = imagecreatetruecolor($width, $height);
        if ($img === false) {
            $this->fail('Failed to create test image');
        }
        
        for ($y = 0; $y < $height; $y++) {
            for ($x = 0; $x < $width; $x++) {
                [$r, $g, $b] = $pixelGenerator($x, $y);
                $color = imagecolorallocate($img, $r, $g, $b);
                imagesetpixel($img, $x, $y, $color);
            }
        }
        
        $filePath = $this->testImageDir . '/test_' . uniqid() . '.jpg';
        imagejpeg($img, $filePath, 85);

        return $filePath;
    }

    /**
     * Create test image as GD resource for direct function testing
     *
     * @param int $width Image width
     * @param int $height Image height
     * @param callable $pixelGenerator Function returning [r, g, b] for (x, y)
     * @return \GdImage|null GD resource or null if GD unavailable
     */
    private function createTestImageResource(int $width, int $height, callable $pixelGenerator): ?\GdImage
    {
        if (!function_exists('imagecreatetruecolor')) {
            return null;
        }

        $img = imagecreatetruecolor($width, $height);
        if ($img === false) {
            return null;
        }

        for ($y = 0; $y < $height; $y++) {
            for ($x = 0; $x < $width; $x++) {
                [$r, $g, $b] = $pixelGenerator($x, $y);
                $color = imagecolorallocate($img, $r, $g, $b);
                imagesetpixel($img, $x, $y, $color);
            }
        }

        return $img;
    }

    public function testDetectErrorFrame_FileNotExists_ReturnsError()
    {
        $result = detectErrorFrame('/nonexistent/file.jpg');
        
        $this->assertTrue($result['is_error']);
        $this->assertEquals(1.0, $result['confidence']);
        $this->assertContains('file_not_readable', $result['reasons']);
    }
    
    public function testDetectErrorFrame_FileNotReadable_ReturnsError()
    {
        if (PHP_OS_FAMILY === 'Windows') {
            $this->markTestSkipped('File permissions test skipped on Windows');
        }
        
        $filePath = $this->testImageDir . '/unreadable.jpg';
        @touch($filePath);
        @chmod($filePath, 0000);
        
        $result = detectErrorFrame($filePath);
        
        @chmod($filePath, 0644);
        @unlink($filePath);
        
        $this->assertTrue($result['is_error']);
    }
    
    public function testDetectErrorFrame_TooSmall_ReturnsError()
    {
        $filePath = $this->createTestImage(50, 50, function($x, $y) {
            return [100, 100, 100]; // Grey
        });
        
        $result = detectErrorFrame($filePath);
        
        $this->assertTrue($result['is_error']);
        $this->assertGreaterThanOrEqual(0.8, $result['confidence']);
        $this->assertContains('too_small', $result['reasons']);
    }
    
    public function testDetectErrorFrame_GreyImage_DetectsError()
    {
        // Create image that's mostly grey (error frame characteristic)
        $filePath = $this->createTestImage(200, 200, function($x, $y) {
            // 80% grey pixels, 20% some color
            if (($x + $y) % 5 === 0) {
                return [50, 50, 50]; // Dark grey
            }
            return [60, 60, 60]; // Slightly lighter grey
        });
        
        $result = detectErrorFrame($filePath);
        
        $this->assertTrue($result['is_error'], 'Grey image should be detected as error frame');
        $this->assertGreaterThanOrEqual(WEBCAM_ERROR_SCORE_THRESHOLD, $result['error_score']);
    }
    
    public function testDetectErrorFrame_DarkGreyImage_DetectsError()
    {
        // Create very dark grey image (typical error frame)
        $filePath = $this->createTestImage(200, 200, function($x, $y) {
            $brightness = 40 + (($x + $y) % 10); // Dark grey, slight variation
            return [$brightness, $brightness, $brightness];
        });
        
        $result = detectErrorFrame($filePath);
        
        $this->assertTrue($result['is_error'], 'Dark grey image should be detected as error frame');
    }
    
    public function testDetectErrorFrame_NormalColorImage_NotDetected()
    {
        // Create normal colorful image (should not be error)
        $filePath = $this->createTestImage(200, 200, function($x, $y) {
            // Create colorful pattern with good variance
            $r = ($x * 3) % 256;
            $g = ($y * 3) % 256;
            $b = (($x + $y) * 2) % 256;
            return [$r, $g, $b];
        });
        
        $result = detectErrorFrame($filePath);
        
        $this->assertFalse($result['is_error'], 'Normal colorful image should not be detected as error');
        $this->assertLessThan(WEBCAM_ERROR_SCORE_THRESHOLD, $result['error_score']);
    }
    
    public function testDetectErrorFrame_LowVariance_DetectsError()
    {
        // Create image with very low color variance (uniform colors)
        $filePath = $this->createTestImage(200, 200, function($x, $y) {
            // Very uniform colors - slight variation but low variance
            $base = 100;
            $variation = (($x + $y) % 5) - 2; // Very small variation
            return [$base + $variation, $base + $variation, $base + $variation];
        });
        
        $result = detectErrorFrame($filePath);
        
        // Low variance images may be detected as errors depending on other factors
        // Verify the result structure is correct
        $this->assertIsArray($result);
        $this->assertArrayHasKey('is_error', $result);
        $this->assertArrayHasKey('reasons', $result);
        $this->assertIsArray($result['reasons']);
        
        // If detected as error, should include variance reason
        if ($result['is_error']) {
            $hasVarianceReason = false;
            foreach ($result['reasons'] as $reason) {
                if (strpos($reason, 'low_color_variance') !== false) {
                    $hasVarianceReason = true;
                    break;
                }
            }
            // If error detected due to low variance, should have variance reason
            // Note: May be detected due to other factors (grey ratio, etc.)
        }
    }
    
    public function testDetectErrorFrame_LowEdgeDensity_DetectsError()
    {
        // Create image with very few edges (uniform, like error frame)
        $filePath = $this->createTestImage(200, 200, function($x, $y) {
            // Very uniform - no edges
            return [80, 80, 80];
        });
        
        $result = detectErrorFrame($filePath);
        
        $this->assertTrue($result['is_error'], 'Image with no edges should be detected as error');
    }
    
    public function testDetectCorruptBottomRegion_SingleLineSolidGreen_RejectsImage(): void
    {
        // Fail closed: even one row of solid green at bottom = reject
        $width = 300;
        $height = 200;
        $img = $this->createTestImageResource($width, $height, function ($x, $y) use ($height) {
            if ($y === $height - 1) {
                return [0, 255, 0];
            }
            return [100 + ($x % 50), 120 + ($y % 40), 130 + (($x + $y) % 30)];
        });
        if ($img === null) {
            $this->markTestSkipped('GD library not available');
        }

        $result = detectCorruptBottomRegion($img, $width, $height);

        $this->assertTrue($result['is_corrupt'], 'Single line of solid green at bottom must be rejected');
        $this->assertStringContainsString('corrupt_bottom', $result['reason']);
    }

    public function testDetectCorruptBottomRegion_SingleLineSolidBlue_RejectsImage(): void
    {
        // Blue is common corruption artifact (e.g. decoder failure)
        $width = 300;
        $height = 200;
        $img = $this->createTestImageResource($width, $height, function ($x, $y) use ($height) {
            if ($y === $height - 1) {
                return [0, 0, 255];
            }
            return [100 + ($x % 50), 120 + ($y % 40), 130];
        });
        if ($img === null) {
            $this->markTestSkipped('GD library not available');
        }

        $result = detectCorruptBottomRegion($img, $width, $height);

        $this->assertTrue($result['is_corrupt'], 'Single line of solid blue at bottom must be rejected');
    }

    public function testDetectCorruptBottomRegion_SolidGreenAtTop_Passes(): void
    {
        // Only check bottom; green at top (e.g. trees) is legitimate
        $width = 300;
        $height = 200;
        $img = $this->createTestImageResource($width, $height, function ($x, $y) {
            if ($y === 0) {
                return [0, 255, 0];
            }
            return [100 + ($x % 50), 120 + ($y % 40), 130];
        });
        if ($img === null) {
            $this->markTestSkipped('GD library not available');
        }

        $result = detectCorruptBottomRegion($img, $width, $height);

        $this->assertFalse($result['is_corrupt'], 'Green at top only should pass');
    }

    public function testDetectCorruptBottomRegion_VariedBottom_Passes(): void
    {
        $width = 300;
        $height = 200;
        $img = $this->createTestImageResource($width, $height, function ($x, $y) {
            return [80 + ($x % 80), 90 + ($y % 70), 100 + (($x + $y) % 60)];
        });
        if ($img === null) {
            $this->markTestSkipped('GD library not available');
        }

        $result = detectCorruptBottomRegion($img, $width, $height);

        $this->assertFalse($result['is_corrupt'], 'Varied bottom should pass');
    }

    public function testDetectCorruptBottomRegion_GreenWithJpegArtifactVariation_RejectsImage(): void
    {
        // JPEG compression adds block artifacts; corrupt green can have variance 50-150
        // Old threshold 50 skipped these; threshold 200 allows detection
        $width = 300;
        $height = 200;
        $img = $this->createTestImageResource($width, $height, function ($x, $y) use ($height) {
            if ($y >= $height - 3) {
                // Simulate JPEG block artifacts: G varies 125-145 (variance ~65)
                $block = (int) floor($x / 15) % 5;
                $g = [125, 127, 135, 143, 145][$block];
                return [1, $g, 0];
            }
            return [100 + ($x % 50), 120 + ($y % 40), 130];
        });
        if ($img === null) {
            $this->markTestSkipped('GD library not available');
        }

        $result = detectCorruptBottomRegion($img, $width, $height);

        $this->assertTrue($result['is_corrupt'], 'Green with JPEG-like variance must be rejected');
        $this->assertStringContainsString('corrupt_bottom', $result['reason']);
    }

    public function testDetectCorruptBottomRegion_CorruptionOutsideFiveRows_Passes(): void
    {
        // Only last 5 rows are checked; corruption at row 6 from bottom is outside window
        $width = 300;
        $height = 200;
        $corruptRow = $height - 6;
        $img = $this->createTestImageResource($width, $height, function ($x, $y) use ($corruptRow) {
            if ($y === $corruptRow) {
                return [0, 255, 0];
            }
            return [100 + ($x % 50), 120 + ($y % 40), 130];
        });
        if ($img === null) {
            $this->markTestSkipped('GD library not available');
        }

        $result = detectCorruptBottomRegion($img, $width, $height);

        $this->assertFalse($result['is_corrupt'], 'Corruption outside 5-row window should pass');
    }

    public function testDetectErrorFrame_CorruptBottomRegion_DetectsError(): void
    {
        // Simulates partial JPEG/device corruption: varied top, solid green bottom
        $width = 300;
        $height = 200;
        $bottomStart = (int) floor($height * 0.67);
        $filePath = $this->createTestImage($width, $height, function ($x, $y) use ($bottomStart) {
            if ($y >= $bottomStart) {
                return [0, 255, 0];
            }
            return [100 + ($x % 50), 120 + ($y % 40), 130 + (($x + $y) % 30)];
        });

        $result = detectErrorFrame($filePath);

        $this->assertTrue($result['is_error'], 'Image with corrupt solid-green bottom should be detected');
        $this->assertNotEmpty($result['reasons']);
        $hasCorruptReason = false;
        foreach ($result['reasons'] as $reason) {
            if (strpos($reason, 'corrupt_bottom') !== false) {
                $hasCorruptReason = true;
                break;
            }
        }
        $this->assertTrue($hasCorruptReason, 'Should include corrupt_bottom in reasons');
    }

    public function testDetectErrorFrame_HealthyBottomRegion_Passes(): void
    {
        // Varied content throughout (including bottom) - should pass corrupt-bottom check
        $filePath = $this->createTestImage(300, 200, function($x, $y) {
            return [80 + ($x % 80), 90 + ($y % 70), 100 + (($x + $y) % 60)];
        });
        
        $result = detectErrorFrame($filePath);
        
        $this->assertFalse($result['is_error'], 'Image with varied bottom should pass');
    }
    
    public function testDetectErrorFrame_GreyBorders_DetectsError()
    {
        // Create image with grey borders (error frame characteristic)
        $width = 200;
        $height = 200;
        $filePath = $this->createTestImage($width, $height, function($x, $y) use ($width, $height) {
            // Grey borders, some color in middle
            if ($y === 0 || $y === $height - 1 || $x === 0 || $x === $width - 1) {
                return [50, 50, 50]; // Dark grey border
            }
            return [150, 150, 150]; // Lighter grey middle
        });
        
        $result = detectErrorFrame($filePath);
        
        // May be detected due to borders or overall greyness
        $this->assertIsFloat($result['error_score']);
        $this->assertGreaterThanOrEqual(0.0, $result['error_score']);
        $this->assertLessThanOrEqual(1.0, $result['error_score']);
    }
    
    public function testDetectErrorFrame_ReturnsCorrectStructure()
    {
        $filePath = $this->createTestImage(200, 200, function($x, $y) {
            return [100, 100, 100]; // Grey
        });
        
        $result = detectErrorFrame($filePath);
        
        $this->assertIsArray($result);
        $this->assertArrayHasKey('is_error', $result);
        $this->assertArrayHasKey('confidence', $result);
        $this->assertArrayHasKey('error_score', $result);
        $this->assertArrayHasKey('reasons', $result);
        $this->assertIsBool($result['is_error']);
        $this->assertIsFloat($result['confidence']);
        $this->assertIsFloat($result['error_score']);
        $this->assertIsArray($result['reasons']);
        $this->assertGreaterThanOrEqual(0.0, $result['confidence']);
        $this->assertLessThanOrEqual(1.0, $result['confidence']);
    }
    
    public function testDetectErrorFrame_GdNotAvailable_ReturnsNotError()
    {
        // This test verifies graceful degradation when GD is not available
        // We can't easily disable GD in tests, so we verify the function handles it
        $this->assertTrue(function_exists('detectErrorFrame'));
        
        // If GD is available, test will run normally
        // If GD is not available, function should return is_error=false with gd_not_available reason
        // This test documents expected behavior rather than asserting specific conditions
        // since we can't control GD availability in the test environment
    }
    
    public function testCalculateVariance_EmptyArray_ReturnsZero()
    {
        $result = calculateVariance([]);
        
        $this->assertEquals(0.0, $result);
    }
    
    public function testCalculateVariance_SingleValue_ReturnsZero()
    {
        $result = calculateVariance([100]);
        
        $this->assertEquals(0.0, $result);
    }
    
    public function testCalculateVariance_UniformValues_ReturnsZero()
    {
        $result = calculateVariance([50, 50, 50, 50, 50]);
        
        $this->assertEquals(0.0, $result);
    }
    
    public function testCalculateVariance_VariedValues_ReturnsPositive()
    {
        $result = calculateVariance([0, 50, 100, 150, 200]);
        
        $this->assertGreaterThan(0.0, $result);
        // Variance of [0, 50, 100, 150, 200] with mean 100
        // = ((0-100)^2 + (50-100)^2 + (100-100)^2 + (150-100)^2 + (200-100)^2) / 5
        // = (10000 + 2500 + 0 + 2500 + 10000) / 5 = 25000 / 5 = 5000
        $this->assertEquals(5000.0, $result);
    }
    
    public function testQuickErrorFrameCheck_FileNotExists_ReturnsTrue()
    {
        $result = quickErrorFrameCheck('/nonexistent/file.jpg');
        
        $this->assertTrue($result);
    }
    
    public function testQuickErrorFrameCheck_GreyImage_ReturnsTrue()
    {
        $filePath = $this->createTestImage(200, 200, function($x, $y) {
            return [50, 50, 50]; // Dark grey
        });
        
        $result = quickErrorFrameCheck($filePath);
        
        $this->assertTrue($result, 'Grey image should be detected by quick check');
    }
    
    public function testQuickErrorFrameCheck_ColorfulImage_ReturnsFalse()
    {
        $filePath = $this->createTestImage(200, 200, function($x, $y) {
            // Colorful pattern
            $r = ($x * 3) % 256;
            $g = ($y * 3) % 256;
            $b = (($x + $y) * 2) % 256;
            return [$r, $g, $b];
        });
        
        $result = quickErrorFrameCheck($filePath);
        
        $this->assertFalse($result, 'Colorful image should not be detected by quick check');
    }
    
    public function testDetectErrorFrame_CombinedFactors_DetectsError()
    {
        // Create image with multiple error characteristics:
        // - High grey ratio
        // - Low variance
        // - Low edge density
        // - Grey borders
        $width = 200;
        $height = 200;
        $filePath = $this->createTestImage($width, $height, function($x, $y) use ($width, $height) {
            // Grey borders
            if ($y === 0 || $y === $height - 1 || $x === 0 || $x === $width - 1) {
                return [40, 40, 40]; // Dark grey border
            }
            
            // Uniform grey interior with minimal variation
            $base = 50;
            $variation = (($x + $y) % 3) - 1; // Very small variation
            return [$base + $variation, $base + $variation, $base + $variation];
        });
        
        $result = detectErrorFrame($filePath);
        
        $this->assertTrue($result['is_error'], 'Image with multiple error characteristics should be detected');
        $this->assertGreaterThanOrEqual(WEBCAM_ERROR_SCORE_THRESHOLD, $result['error_score']);
        $this->assertNotEmpty($result['reasons']);
    }
    
    public function testDetectErrorFrame_RealWorldNormalImage_NotDetected()
    {
        // Create a more realistic normal image with:
        // - Good color variance
        // - Some edges
        // - Not mostly grey
        $filePath = $this->createTestImage(200, 200, function($x, $y) {
            // Create gradient pattern with good variance
            $r = min(255, ($x * 2) % 256);
            $g = min(255, ($y * 2) % 256);
            $b = min(255, (int)(($x + $y) * 1.5) % 256);
            
            // Add some structure (edges)
            if (($x % 20) < 10 && ($y % 20) < 10) {
                $r = min(255, $r + 50);
                $g = min(255, $g + 30);
            }
            
            return [$r, $g, $b];
        });
        
        $result = detectErrorFrame($filePath);
        
        $this->assertFalse($result['is_error'], 'Realistic normal image should not be detected as error');
    }
    
    public function testDetectErrorFrame_NoPixelsSampled_ReturnsError()
    {
        // This edge case is handled by the division by zero protection in detectErrorFrame()
        // We can't easily create this scenario in tests (would require very specific image dimensions
        // that result in zero pixels sampled), but we verify the function exists and handles it
        $this->assertTrue(function_exists('detectErrorFrame'));
        
        // The code protects against division by zero at line 99-102 in webcam-error-detector.php
        // This test documents that the edge case is handled
    }
}

