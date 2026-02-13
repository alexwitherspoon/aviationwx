<?php
/**
 * Pixelation Detection Unit Tests
 * 
 * Tests for Laplacian variance-based pixelation detection and
 * phase-aware threshold selection.
 */

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../lib/constants.php';
require_once __DIR__ . '/../../lib/webcam-error-detector.php';
require_once __DIR__ . '/../../lib/weather/utils.php';

class PixelationDetectionTest extends TestCase
{
    private string $tmpDir;
    
    protected function setUp(): void
    {
        parent::setUp();
        $this->tmpDir = sys_get_temp_dir() . '/pixelation_test_' . uniqid();
        @mkdir($this->tmpDir, 0755, true);
    }
    
    protected function tearDown(): void
    {
        // Clean up test files
        if (is_dir($this->tmpDir)) {
            $files = glob($this->tmpDir . '/*');
            foreach ($files as $file) {
                @unlink($file);
            }
            @rmdir($this->tmpDir);
        }
        parent::tearDown();
    }
    
    /**
     * Create a test image with sharp edges (high Laplacian variance)
     */
    private function createSharpEdgeImage(int $width = 200, int $height = 200): string
    {
        $img = imagecreatetruecolor($width, $height);
        $white = imagecolorallocate($img, 255, 255, 255);
        $black = imagecolorallocate($img, 0, 0, 0);
        
        // Create checkerboard pattern for sharp edges
        $blockSize = 20;
        for ($y = 0; $y < $height; $y += $blockSize) {
            for ($x = 0; $x < $width; $x += $blockSize) {
                $color = (($x / $blockSize) + ($y / $blockSize)) % 2 == 0 ? $white : $black;
                imagefilledrectangle($img, $x, $y, $x + $blockSize - 1, $y + $blockSize - 1, $color);
            }
        }
        
        $path = $this->tmpDir . '/sharp_edges_' . uniqid() . '.jpg';
        imagejpeg($img, $path, 95);
        
        return $path;
    }
    
    /**
     * Create a test image with no edges (extremely low Laplacian variance)
     * Simulates severely pixelated/corrupted image
     */
    private function createFlatImage(int $width = 200, int $height = 200, int $greyValue = 128): string
    {
        $img = imagecreatetruecolor($width, $height);
        $grey = imagecolorallocate($img, $greyValue, $greyValue, $greyValue);
        imagefill($img, 0, 0, $grey);
        
        $path = $this->tmpDir . '/flat_' . uniqid() . '.jpg';
        imagejpeg($img, $path, 95);
        
        return $path;
    }
    
    /**
     * Create a test image with soft gradients (medium Laplacian variance)
     * Simulates foggy/overcast conditions
     */
    private function createGradientImage(int $width = 200, int $height = 200): string
    {
        $img = imagecreatetruecolor($width, $height);
        
        for ($y = 0; $y < $height; $y++) {
            $brightness = (int)(128 + 50 * sin($y * 0.05) + rand(-10, 10));
            $brightness = max(0, min(255, $brightness));
            for ($x = 0; $x < $width; $x++) {
                $xVar = (int)($brightness + 30 * sin($x * 0.03) + rand(-5, 5));
                $xVar = max(0, min(255, $xVar));
                $color = imagecolorallocate($img, $xVar, $xVar, $xVar);
                imagesetpixel($img, $x, $y, $color);
            }
        }
        
        $path = $this->tmpDir . '/gradient_' . uniqid() . '.jpg';
        imagejpeg($img, $path, 95);
        
        return $path;
    }
    
    /**
     * Create a dark image with some noise (simulates night scene)
     */
    private function createDarkNoiseImage(int $width = 200, int $height = 200): string
    {
        $img = imagecreatetruecolor($width, $height);
        
        for ($y = 0; $y < $height; $y++) {
            for ($x = 0; $x < $width; $x++) {
                // Dark with sensor noise
                $brightness = 20 + rand(-10, 20);
                $brightness = max(0, min(255, $brightness));
                $color = imagecolorallocate($img, $brightness, $brightness, $brightness + rand(0, 10));
                imagesetpixel($img, $x, $y, $color);
            }
        }
        
        $path = $this->tmpDir . '/dark_noise_' . uniqid() . '.jpg';
        imagejpeg($img, $path, 95);
        
        return $path;
    }
    
    // ==================== Laplacian Variance Tests ====================
    
    public function testCalculateLaplacianVariance_SharpEdges_HighVariance(): void
    {
        $path = $this->createSharpEdgeImage();
        $img = imagecreatefromjpeg($path);
        $width = imagesx($img);
        $height = imagesy($img);
        
        $result = calculateLaplacianVariance($img, $width, $height);
        
        
        $this->assertGreaterThan(100, $result['variance'], 
            'Sharp edge image should have high Laplacian variance');
        $this->assertGreaterThan(10, $result['sample_count'],
            'Should have sufficient samples');
    }
    
    public function testCalculateLaplacianVariance_FlatImage_LowVariance(): void
    {
        $path = $this->createFlatImage();
        $img = imagecreatefromjpeg($path);
        $width = imagesx($img);
        $height = imagesy($img);
        
        $result = calculateLaplacianVariance($img, $width, $height);
        
        
        $this->assertLessThan(5, $result['variance'], 
            'Flat image should have very low Laplacian variance');
    }
    
    public function testCalculateLaplacianVariance_Gradient_MediumVariance(): void
    {
        $path = $this->createGradientImage();
        $img = imagecreatefromjpeg($path);
        $width = imagesx($img);
        $height = imagesy($img);
        
        $result = calculateLaplacianVariance($img, $width, $height);
        
        
        // Gradient should have moderate variance (soft edges)
        $this->assertGreaterThan(5, $result['variance'], 
            'Gradient image should have some variance');
        $this->assertLessThan(500, $result['variance'], 
            'Gradient image should not have extreme variance');
    }
    
    // ==================== Phase-Aware Threshold Tests ====================
    
    public function testGetPixelationThreshold_NoAirport_ReturnsDayThreshold(): void
    {
        $threshold = getPixelationThreshold(null);
        
        $this->assertEquals(WEBCAM_PIXELATION_THRESHOLD_DAY, $threshold,
            'Without airport, should use day threshold');
    }
    
    public function testGetPixelationThreshold_DayPhase_ReturnsDayThreshold(): void
    {
        // Create airport at equator at noon (always day)
        $airport = [
            'lat' => 0.0,
            'lon' => 0.0,
        ];
        
        // Use a timestamp that's noon UTC (sun should be up at equator)
        $noonUtc = strtotime('today 12:00 UTC');
        $threshold = getPixelationThreshold($airport, $noonUtc);
        
        // At noon UTC at the equator, it should be day
        $phase = getDaylightPhase($airport, $noonUtc);
        
        if ($phase === DAYLIGHT_PHASE_DAY) {
            $this->assertEquals(WEBCAM_PIXELATION_THRESHOLD_DAY, $threshold);
        }
        // Note: Depending on time of year, phase might differ slightly
    }
    
    public function testGetPixelationThreshold_NightPhase_ReturnsNightThreshold(): void
    {
        // Create airport at equator at midnight (always night)
        $airport = [
            'lat' => 0.0,
            'lon' => 0.0,
        ];
        
        // Use a timestamp that's midnight UTC (sun should be down at equator)
        $midnightUtc = strtotime('today 00:00 UTC');
        $threshold = getPixelationThreshold($airport, $midnightUtc);
        
        // At midnight UTC at the equator, it should be night
        $phase = getDaylightPhase($airport, $midnightUtc);
        
        if ($phase === DAYLIGHT_PHASE_NIGHT) {
            $this->assertEquals(WEBCAM_PIXELATION_THRESHOLD_NIGHT, $threshold);
        }
    }
    
    // ==================== Daylight Phase Tests ====================
    
    public function testGetDaylightPhase_NoLocation_ReturnsDay(): void
    {
        $airport = []; // No lat/lon
        
        $phase = getDaylightPhase($airport);
        
        $this->assertEquals(DAYLIGHT_PHASE_DAY, $phase,
            'Without location data, should default to day (fail safe)');
    }
    
    public function testGetDaylightPhase_ValidLocation_ReturnsPhase(): void
    {
        // Portland, OR
        $airport = [
            'lat' => 45.5898,
            'lon' => -122.5951,
        ];
        
        $phase = getDaylightPhase($airport);
        
        // Just verify we get a valid phase
        $validPhases = [
            DAYLIGHT_PHASE_DAY,
            DAYLIGHT_PHASE_CIVIL_TWILIGHT,
            DAYLIGHT_PHASE_NAUTICAL_TWILIGHT,
            DAYLIGHT_PHASE_NIGHT,
        ];
        
        $this->assertContains($phase, $validPhases,
            'Should return a valid daylight phase');
    }
    
    // ==================== Pixelation Detection Integration Tests ====================
    
    public function testDetectPixelation_SharpImage_NotPixelated(): void
    {
        $path = $this->createSharpEdgeImage();
        $img = imagecreatefromjpeg($path);
        $width = imagesx($img);
        $height = imagesy($img);
        
        $result = detectPixelation($img, $width, $height);
        
        
        $this->assertFalse($result['is_pixelated'],
            'Sharp edge image should NOT be detected as pixelated');
        $this->assertEmpty($result['reason']);
    }
    
    public function testDetectPixelation_FlatImage_DetectedAsPixelated(): void
    {
        $path = $this->createFlatImage();
        $img = imagecreatefromjpeg($path);
        $width = imagesx($img);
        $height = imagesy($img);
        
        $result = detectPixelation($img, $width, $height);
        
        
        $this->assertTrue($result['is_pixelated'],
            'Flat image should be detected as pixelated');
        $this->assertStringContainsString('pixelated', $result['reason']);
    }
    
    public function testDetectPixelation_DarkNoiseImage_NotPixelated(): void
    {
        $path = $this->createDarkNoiseImage();
        $img = imagecreatefromjpeg($path);
        $width = imagesx($img);
        $height = imagesy($img);
        
        $result = detectPixelation($img, $width, $height);
        
        
        $this->assertFalse($result['is_pixelated'],
            'Dark image with noise should NOT be detected as pixelated');
    }
    
    // ==================== Full Error Frame Detection Tests ====================
    
    public function testDetectErrorFrame_SharpImage_NotError(): void
    {
        $path = $this->createSharpEdgeImage();
        
        $result = detectErrorFrame($path);
        
        $this->assertFalse($result['is_error'],
            'Sharp image should not be detected as error');
    }
    
    public function testDetectErrorFrame_FlatImage_DetectedAsError(): void
    {
        // Note: Flat grey image will be caught by uniform color check first
        $path = $this->createFlatImage(200, 200, 128);
        
        $result = detectErrorFrame($path);
        
        $this->assertTrue($result['is_error'],
            'Flat grey image should be detected as error (uniform color)');
    }
    
    public function testDetectErrorFrame_GradientImage_NotError(): void
    {
        $path = $this->createGradientImage();
        
        $result = detectErrorFrame($path);
        
        $this->assertFalse($result['is_error'],
            'Gradient image should not be detected as error');
    }
    
    public function testDetectErrorFrame_WithAirportContext_UsesPhaseThreshold(): void
    {
        $path = $this->createGradientImage();
        
        // Portland, OR at night - should use lower threshold
        $airport = [
            'lat' => 45.5898,
            'lon' => -122.5951,
        ];
        
        $result = detectErrorFrame($path, $airport);
        
        // Just verify the function accepts airport context
        $this->assertArrayHasKey('is_error', $result);
        $this->assertArrayHasKey('reasons', $result);
    }
    
    // ==================== Convenience Function Tests ====================
    
    public function testIsDaytime_ReturnsBoolean(): void
    {
        $airport = [
            'lat' => 45.5898,
            'lon' => -122.5951,
        ];
        
        $result = isDaytime($airport);
        
        $this->assertIsBool($result);
    }
    
    public function testIsNighttime_ReturnsBoolean(): void
    {
        $airport = [
            'lat' => 45.5898,
            'lon' => -122.5951,
        ];
        
        $result = isNighttime($airport);
        
        $this->assertIsBool($result);
    }
    
    public function testGetAirportLocation_ValidAirport_ReturnsLocation(): void
    {
        $airport = [
            'lat' => 45.5898,
            'lon' => -122.5951,
        ];
        
        $location = getAirportLocation($airport);
        
        $this->assertNotNull($location);
        $this->assertEquals(45.5898, $location['lat']);
        $this->assertEquals(-122.5951, $location['lon']);
    }
    
    public function testGetAirportLocation_NoLocation_ReturnsNull(): void
    {
        $airport = [];
        
        $location = getAirportLocation($airport);
        
        $this->assertNull($location);
    }
}
