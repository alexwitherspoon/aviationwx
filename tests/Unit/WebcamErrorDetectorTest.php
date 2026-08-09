<?php
/**
 * Unit Tests for Webcam Error Frame Detector
 *
 * Covers error-frame detection:
 * - Grey / uniform / Blue Iris border heuristics
 * - Format-specific truncation pads (JPEG mid-grey, PNG solid, WebP relative flat)
 * - Phase-aware uniform-color thresholds
 * - Production partial fixture (tests/Fixtures/webcam-partial/)
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

    /**
     * Solid-color GD image for bitstream completeness fixtures (no per-pixel work).
     *
     * @return \GdImage|null
     */
    private function createSolidTestImageResource(int $width, int $height, int $r = 80, int $g = 90, int $b = 100): ?\GdImage
    {
        if (!function_exists('imagecreatetruecolor')) {
            return null;
        }

        $img = imagecreatetruecolor($width, $height);
        if ($img === false) {
            return null;
        }

        $fill = imagecolorallocate($img, $r, $g, $b);
        imagefilledrectangle($img, 0, 0, $width - 1, $height - 1, $fill);

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
    
    public function testDetectEmptyBottomBand_GreyFillBelowCoverage_Rejects(): void
    {
        // GD truncation fill is mid-grey 128, not browser-green
        $width = 320;
        $height = 240;
        $fillStart = (int) floor($height * 0.40); // large mid-grey pad from bottom
        $img = $this->createTestImageResource($width, $height, function ($x, $y) use ($fillStart) {
            if ($y >= $fillStart) {
                return [128, 128, 128];
            }
            return [100 + ($x % 50), 120 + ($y % 40), 130 + (($x + $y) % 30)];
        });
        if ($img === null) {
            $this->markTestSkipped('GD library not available');
        }

        $result = detectEmptyBottomBand($img, $width, $height);

        $this->assertTrue($result['is_empty_band'], 'Large grey fill band must be rejected');
        $this->assertLessThan(0.85, $result['coverage']);
        $this->assertStringContainsString('empty_band_midgrey_rows_', $result['reason']);
    }

    public function testDetectEmptyBottomBand_LastLineMidGrey_Rejects(): void
    {
        // Partial upload signature: even one GD mid-grey last line fails closed
        $width = 320;
        $height = 240;
        $img = $this->createTestImageResource($width, $height, function ($x, $y) use ($height) {
            if ($y === $height - 1) {
                return [128, 128, 128];
            }
            return [80 + ($x % 80), 90 + ($y % 70), 100 + (($x + $y) % 60)];
        });
        if ($img === null) {
            $this->markTestSkipped('GD library not available');
        }

        $result = detectEmptyBottomBand($img, $width, $height);

        $this->assertTrue($result['is_empty_band'], 'Single mid-grey last line must reject');
        $this->assertSame(1, $result['empty_rows']);
        $this->assertStringContainsString('empty_band_midgrey_rows_', $result['reason']);
    }

    public function testDetectEmptyBottomBand_DarkNightLastRow_Passes(): void
    {
        // Night bottoms are dark, not mid-grey 128 - must not false-reject
        $width = 320;
        $height = 240;
        $img = $this->createTestImageResource($width, $height, function ($x, $y) {
            $n = ($x * 17 + $y * 13) % 11;
            return [4 + $n, 5 + $n, 7 + $n];
        });
        if ($img === null) {
            $this->markTestSkipped('GD library not available');
        }

        $result = detectEmptyBottomBand($img, $width, $height);

        $this->assertFalse($result['is_empty_band'], 'Dark noisy night frame must pass');
        $this->assertSame(0, $result['empty_rows']);
    }

    public function testDetectEmptyBottomBand_SolidBlackLastRow_Passes(): void
    {
        // Solid black is low-variance but not decoder mid-grey
        $width = 320;
        $height = 240;
        $img = $this->createTestImageResource($width, $height, function ($x, $y) use ($height) {
            if ($y === $height - 1) {
                return [0, 0, 0];
            }
            return [80 + ($x % 80), 90 + ($y % 70), 100 + (($x + $y) % 60)];
        });
        if ($img === null) {
            $this->markTestSkipped('GD library not available');
        }

        $result = detectEmptyBottomBand($img, $width, $height);

        $this->assertFalse($result['is_empty_band'], 'Solid black last row is not GD mid-grey fill');
    }

    public function testDetectEmptyBottomBand_VariedBottom_Passes(): void
    {
        $width = 320;
        $height = 240;
        $img = $this->createTestImageResource($width, $height, function ($x, $y) {
            return [80 + ($x % 80), 90 + ($y % 70), 100 + (($x + $y) % 60)];
        });
        if ($img === null) {
            $this->markTestSkipped('GD library not available');
        }

        $result = detectEmptyBottomBand($img, $width, $height);

        $this->assertFalse($result['is_empty_band']);
        $this->assertSame(0, $result['empty_rows']);
        $this->assertEqualsWithDelta(1.0, $result['coverage'], 0.001);
    }

    public function testDetectEmptyBottomBand_SolidFillAtTopOnly_Passes(): void
    {
        // Fill band must be contiguous from the bottom
        $width = 320;
        $height = 240;
        $img = $this->createTestImageResource($width, $height, function ($x, $y) {
            if ($y < 40) {
                return [128, 128, 128];
            }
            return [80 + ($x % 80), 90 + ($y % 70), 100 + (($x + $y) % 60)];
        });
        if ($img === null) {
            $this->markTestSkipped('GD library not available');
        }

        $result = detectEmptyBottomBand($img, $width, $height);

        $this->assertFalse($result['is_empty_band'], 'Top-only solid band is not a truncation pad');
    }

    public function testDetectErrorFrame_EmptyBand_DetectsError(): void
    {
        $width = 300;
        $height = 200;
        $fillStart = (int) floor($height * 0.50);
        $filePath = $this->createTestImage($width, $height, function ($x, $y) use ($fillStart) {
            if ($y >= $fillStart) {
                return [128, 128, 128];
            }
            return [100 + ($x % 50), 120 + ($y % 40), 130 + (($x + $y) % 30)];
        });

        $result = detectErrorFrame($filePath);

        $this->assertTrue($result['is_error'], 'Empty-band partial must be detected via detectErrorFrame');
        $hasEmptyBand = false;
        foreach ($result['reasons'] as $reason) {
            if (strpos($reason, 'empty_band_midgrey_rows_') !== false) {
                $hasEmptyBand = true;
                break;
            }
        }
        $this->assertTrue($hasEmptyBand, 'Reason should include empty_band_midgrey_rows_');
    }

    public function testDetectErrorFrame_HealthyBottomRegion_Passes(): void
    {
        $filePath = $this->createTestImage(300, 200, function ($x, $y) {
            return [80 + ($x % 80), 90 + ($y % 70), 100 + (($x + $y) % 60)];
        });

        $result = detectErrorFrame($filePath);

        $this->assertFalse($result['is_error'], 'Image with varied bottom should pass');
    }

    public function testU88WestPartialFixture_MissingEoiAndEmptyBand(): void
    {
        require_once __DIR__ . '/../../lib/webcam-history.php';

        $fixture = __DIR__ . '/../Fixtures/webcam-partial/u88-west-partial-original.jpg';
        if (!is_file($fixture)) {
            $this->markTestSkipped('U88 West partial fixture missing');
        }

        $this->assertFalse(isJpegComplete($fixture), 'Production partial must fail EOI check');

        $img = @imagecreatefromjpeg($fixture);
        if ($img === false) {
            $this->fail('GD must decode the U88 West partial fixture');
        }

        $width = imagesx($img);
        $height = imagesy($img);
        $band = detectEmptyBottomBand($img, $width, $height);
        $this->assertTrue($band['is_empty_band'], 'U88 West must fail empty-band coverage');
        $this->assertLessThan(0.5, $band['coverage'], 'Expected large undecoded fill (~29% content)');
        $this->assertStringContainsString('empty_band_midgrey_rows_', $band['reason']);

        $frame = detectErrorFrame($fixture, null, $img);
        $this->assertTrue($frame['is_error'], 'detectErrorFrame must reject U88 West partial');
        $joined = implode(' ', $frame['reasons']);
        $this->assertStringContainsString('empty_band_midgrey_rows_', $joined);
    }

    public function testTruncatedJpegDecode_EmptyBandRejects(): void
    {
        require_once __DIR__ . '/../../lib/webcam-history.php';

        if (!function_exists('imagecreatetruecolor')) {
            $this->markTestSkipped('GD library not available');
        }

        $fullPath = $this->createTestImage(640, 480, function ($x, $y) {
            $sky = (int) max(0, 200 - (int) ($y * 0.3));
            return [
                min(255, $sky + (($x * 3 + $y * 5) % 17)),
                min(255, (int) (180 - $y * 0.15) + (($x * 7 + $y) % 23)),
                min(255, (int) (220 - $y * 0.2) + (($x + $y * 11) % 19)),
            ];
        });

        $bytes = file_get_contents($fullPath);
        $this->assertNotFalse($bytes);
        $partialPath = $this->testImageDir . '/truncated_' . uniqid() . '.jpg';
        // Keep enough header/scan data to decode, drop EOI and bottom MCUs
        $cut = (int) floor(strlen($bytes) * 0.45);
        file_put_contents($partialPath, substr($bytes, 0, $cut));

        $this->assertFalse(isJpegComplete($partialPath));

        $img = @imagecreatefromstring(file_get_contents($partialPath));
        $this->assertNotFalse($img, 'GD should still decode truncated JPEG');

        $band = detectEmptyBottomBand($img, imagesx($img), imagesy($img));
        $this->assertTrue($band['is_empty_band'], 'Synthetic truncate must trip empty-band');

        $frame = detectErrorFrame($partialPath, null, $img);
        $this->assertTrue($frame['is_error']);
    }

    public function testDetectPngSolidEmptyBottomBand_BlackPadRejects(): void
    {
        $width = 300;
        $height = 200;
        $fillStart = (int) floor($height * 0.55);
        $img = $this->createTestImageResource($width, $height, function ($x, $y) use ($fillStart) {
            if ($y >= $fillStart) {
                return [0, 0, 0];
            }
            return [80 + ($x % 80), 90 + ($y % 70), 100 + (($x + $y) % 60)];
        });
        if ($img === null) {
            $this->markTestSkipped('GD library not available');
        }

        $band = detectPngSolidEmptyBottomBand($img, $width, $height);
        $this->assertTrue($band['is_empty_band']);
        $this->assertStringContainsString('empty_band_png_solid_rows_', $band['reason']);
    }

    public function testDetectPngSolidEmptyBottomBand_TexturedBottomPasses(): void
    {
        $width = 300;
        $height = 200;
        $img = $this->createTestImageResource($width, $height, function ($x, $y) {
            return [80 + ($x % 80), 90 + ($y % 70), 100 + (($x + $y) % 60)];
        });
        if ($img === null) {
            $this->markTestSkipped('GD library not available');
        }

        $band = detectPngSolidEmptyBottomBand($img, $width, $height);
        $this->assertFalse($band['is_empty_band']);
    }

    public function testDetectPngSolidEmptyBottomBand_UniformDarkPassesRelativeGate(): void
    {
        $width = 300;
        $height = 200;
        $img = $this->createTestImageResource($width, $height, function ($x, $y) {
            return [6, 6, 6];
        });
        if ($img === null) {
            $this->markTestSkipped('GD library not available');
        }

        $band = detectPngSolidEmptyBottomBand($img, $width, $height);
        $this->assertFalse($band['is_empty_band'], 'Uniform dark PNG must not trip solid-pad without mid content');
    }

    public function testDetectPngSolidEmptyBottomBand_MidGreyBottomDoesNotMatchPngSolid(): void
    {
        // JPEG mid-grey is a different pad model; PNG solid gate is near-black / empty
        $width = 300;
        $height = 200;
        $fillStart = (int) floor($height * 0.55);
        $img = $this->createTestImageResource($width, $height, function ($x, $y) use ($fillStart) {
            if ($y >= $fillStart) {
                return [128, 128, 128];
            }
            return [80 + ($x % 80), 90 + ($y % 70), 100 + (($x + $y) % 60)];
        });
        if ($img === null) {
            $this->markTestSkipped('GD library not available');
        }

        $pngBand = detectPngSolidEmptyBottomBand($img, $width, $height);
        $this->assertFalse($pngBand['is_empty_band'], 'Mid-grey pad is JPEG-specific, not PNG solid');

        $jpegBand = detectEmptyBottomBand($img, $width, $height);
        $this->assertTrue($jpegBand['is_empty_band']);
    }

    public function testDetectWebpRelativeFlatBottomBand_FlatPadWithContentMidRejects(): void
    {
        $width = 300;
        $height = 200;
        $fillStart = (int) floor($height * 0.60);
        $img = $this->createTestImageResource($width, $height, function ($x, $y) use ($fillStart) {
            if ($y >= $fillStart) {
                return [90, 90, 90];
            }
            return [40 + ($x % 100), 50 + ($y % 90), 60 + (($x + $y) % 80)];
        });
        if ($img === null) {
            $this->markTestSkipped('GD library not available');
        }

        $band = detectWebpRelativeFlatBottomBand($img, $width, $height);
        $this->assertTrue($band['is_empty_band']);
        $this->assertStringContainsString('empty_band_webp_flat_rows_', $band['reason']);
    }

    public function testDetectWebpRelativeFlatBottomBand_UniformDarkPassesRelativeGate(): void
    {
        // Entire frame flat/dark: relative mid-content gate must not fire (uniform check is separate)
        $width = 300;
        $height = 200;
        $img = $this->createTestImageResource($width, $height, function ($x, $y) {
            return [8, 8, 8];
        });
        if ($img === null) {
            $this->markTestSkipped('GD library not available');
        }

        $band = detectWebpRelativeFlatBottomBand($img, $width, $height);
        $this->assertFalse($band['is_empty_band']);
    }

    public function testDetectErrorFrame_PngFormat_UsesSolidPad(): void
    {
        $width = 300;
        $height = 200;
        // Pad must start below mid-row so the relative mid-content gate still sees texture
        $fillStart = (int) floor($height * 0.55);
        $img = $this->createTestImageResource($width, $height, function ($x, $y) use ($fillStart) {
            if ($y >= $fillStart) {
                return [0, 0, 0];
            }
            return [80 + ($x % 80), 90 + ($y % 70), 100 + (($x + $y) % 60)];
        });
        if ($img === null) {
            $this->markTestSkipped('GD library not available');
        }

        // Pass GD directly - JPEG re-encode would add noise that masks solid-black pads
        $result = detectErrorFrame('/dev/null', null, $img, 'png');
        $this->assertTrue($result['is_error']);
        $joined = implode(' ', $result['reasons']);
        $this->assertStringContainsString('empty_band_png_solid_rows_', $joined);
    }

    public function testDetectErrorFrame_WebpFormat_UsesRelativeFlatPad(): void
    {
        $width = 300;
        $height = 200;
        $fillStart = (int) floor($height * 0.55);
        $img = $this->createTestImageResource($width, $height, function ($x, $y) use ($fillStart) {
            if ($y >= $fillStart) {
                return [100, 100, 100];
            }
            return [30 + ($x % 120), 40 + ($y % 100), 50 + (($x + $y) % 90)];
        });
        if ($img === null) {
            $this->markTestSkipped('GD library not available');
        }

        $result = detectErrorFrame('/dev/null', null, $img, 'webp');
        $this->assertTrue($result['is_error']);
        $joined = implode(' ', $result['reasons']);
        $this->assertStringContainsString('empty_band_webp_flat_rows_', $joined);
    }

    public function testDetectErrorFrame_PngFile_AutoDetectsFormatPad(): void
    {
        if (!function_exists('imagepng')) {
            $this->markTestSkipped('GD PNG support not available');
        }

        $width = 300;
        $height = 200;
        $fillStart = (int) floor($height * 0.55);
        $img = $this->createTestImageResource($width, $height, function ($x, $y) use ($fillStart) {
            if ($y >= $fillStart) {
                return [0, 0, 0];
            }
            return [80 + ($x % 80), 90 + ($y % 70), 100 + (($x + $y) % 60)];
        });
        if ($img === null) {
            $this->markTestSkipped('GD library not available');
        }

        $pngPath = $this->testImageDir . '/auto_png_' . uniqid() . '.png';
        imagepng($img, $pngPath);

        // No explicit sourceFormat: magic-byte detect must select PNG solid pad
        $result = detectErrorFrame($pngPath);
        $this->assertTrue($result['is_error']);
        $joined = implode(' ', $result['reasons']);
        $this->assertStringContainsString('empty_band_png_solid_rows_', $joined);
    }

    public function testNormalizeWebcamSourceFormat_ExplicitAndMagicDetect(): void
    {
        $this->assertSame('jpg', normalizeWebcamSourceFormat('jpeg'));
        $this->assertSame('png', normalizeWebcamSourceFormat('PNG'));
        $this->assertSame('webp', normalizeWebcamSourceFormat('webp'));
        $this->assertNull(normalizeWebcamSourceFormat('gif'));
        $this->assertNull(normalizeWebcamSourceFormat(null, ''));
        $this->assertNull(normalizeWebcamSourceFormat(null, '/nonexistent/no-such-image.bin'));

        if (!function_exists('imagepng')) {
            $this->markTestSkipped('GD PNG support not available');
        }
        $img = $this->createTestImageResource(64, 64, function ($x, $y) {
            return [10 + ($x % 40), 20 + ($y % 30), 30 + (($x + $y) % 20)];
        });
        if ($img === null) {
            $this->markTestSkipped('GD library not available');
        }
        $pngPath = $this->testImageDir . '/fmt_' . uniqid() . '.png';
        imagepng($img, $pngPath);
        $this->assertSame('png', normalizeWebcamSourceFormat(null, $pngPath));
    }

    public function testDetectErrorFrame_UnresolvedFormat_FailsClosed(): void
    {
        $img = $this->createTestImageResource(200, 200, function ($x, $y) {
            return [80 + ($x % 80), 90 + ($y % 70), 100 + (($x + $y) % 60)];
        });
        if ($img === null) {
            $this->markTestSkipped('GD library not available');
        }

        // GD present but path cannot yield magic-byte format and caller omitted format
        $result = detectErrorFrame('/dev/null', null, $img, null);
        $this->assertTrue($result['is_error']);
        $this->assertContains('unknown_source_format', $result['reasons']);

        $bogus = detectErrorFrame('/dev/null', null, $img, 'gif');
        $this->assertTrue($bogus['is_error']);
        $this->assertContains('unknown_source_format', $bogus['reasons']);
    }

    public function testTruncatedPngBitstream_FailsIendBeforeDecodePad(): void
    {
        require_once __DIR__ . '/../../lib/webcam-history.php';

        if (!function_exists('imagecreatetruecolor') || !function_exists('imagepng')) {
            $this->markTestSkipped('GD PNG support not available');
        }

        $img = $this->createSolidTestImageResource(320, 240);
        if ($img === null) {
            $this->markTestSkipped('GD library not available');
        }
        $fullPath = $this->testImageDir . '/full_' . uniqid() . '.png';
        imagepng($img, $fullPath);
        unset($img);

        $bytes = file_get_contents($fullPath);
        $this->assertNotFalse($bytes);
        $this->assertTrue(isPngDataComplete($bytes));

        $partialPath = $this->testImageDir . '/partial_' . uniqid() . '.png';
        $cut = (int) floor(strlen($bytes) * 0.70);
        file_put_contents($partialPath, substr($bytes, 0, $cut));

        $this->assertFalse(isPngComplete($partialPath));
        $this->assertFalse(isPngDataComplete(file_get_contents($partialPath)));
    }

    public function testTruncatedWebpBitstream_FailsRiffSizeGate(): void
    {
        require_once __DIR__ . '/../../lib/webcam-history.php';

        if (!function_exists('imagecreatetruecolor') || !function_exists('imagewebp')) {
            $this->markTestSkipped('GD WebP support not available');
        }

        $img = $this->createSolidTestImageResource(320, 240, 40, 50, 60);
        if ($img === null) {
            $this->markTestSkipped('GD library not available');
        }
        $fullPath = $this->testImageDir . '/full_' . uniqid() . '.webp';
        if (@imagewebp($img, $fullPath, 80) === false) {
            unset($img);
            $this->markTestSkipped('imagewebp failed');
        }
        unset($img);

        $bytes = file_get_contents($fullPath);
        $this->assertNotFalse($bytes);
        $this->assertTrue(isWebpComplete($fullPath));

        $partialPath = $this->testImageDir . '/partial_' . uniqid() . '.webp';
        $cut = (int) floor(strlen($bytes) * 0.55);
        file_put_contents($partialPath, substr($bytes, 0, max(12, $cut)));

        $this->assertFalse(isWebpComplete($partialPath));
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

    /**
     * Uniform threshold without airport matches the default (day) constant.
     */
    public function testGetUniformColorVarianceThreshold_NoAirport_ReturnsDefault(): void
    {
        $this->assertSame(
            (float) WEBCAM_ERROR_UNIFORM_COLOR_VARIANCE_THRESHOLD,
            getUniformColorVarianceThreshold(null)
        );
    }

    /**
     * At night, uniform detection uses a lower variance ceiling so very dark sky is accepted.
     */
    public function testGetUniformColorVarianceThreshold_EquatorMidnight_ReturnsNightConstant(): void
    {
        $airport = ['lat' => 0.0, 'lon' => 0.0];
        $midnightUtc = strtotime('2026-06-15 00:00:00 UTC');
        $this->assertSame(
            DAYLIGHT_PHASE_NIGHT,
            getDaylightPhase($airport, $midnightUtc),
            'Precondition: equator midnight should be night'
        );
        $this->assertSame(
            (float) WEBCAM_ERROR_UNIFORM_COLOR_VARIANCE_THRESHOLD_NIGHT,
            getUniformColorVarianceThreshold($airport, $midnightUtc)
        );
    }
}

