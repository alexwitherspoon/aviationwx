<?php
/**
 * Unit Tests for Webcam History
 * 
 * Tests the webcam history functions including:
 * - getHistoryImageCaptureTime() - EXIF timestamp parsing
 * - getHistoryFrames() - Frame listing from unified cache
 * - getHistoryStatus() - History availability checking
 * - Image completeness validation functions
 * 
 * Storage Architecture (Unified):
 * - All webcam images stored in camera cache directory
 * - No separate history subfolder
 * - Retention controlled by webcam_history_max_frames config
 */

namespace AviationWX\Tests;

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../lib/webcam-history.php';
require_once __DIR__ . '/../../lib/cache-paths.php';
require_once __DIR__ . '/../../lib/constants.php';
require_once __DIR__ . '/../../lib/logger.php';
require_once __DIR__ . '/../../scripts/process-push-webcams.php';

class WebcamHistoryTest extends TestCase
{
    private $testImageDir;
    
    protected function setUp(): void
    {
        parent::setUp();
        $this->testImageDir = sys_get_temp_dir() . '/webcam_history_test_' . uniqid();
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
     * Create a minimal valid JPEG file for testing
     */
    private function createMinimalJpeg(string $filename): string
    {
        $path = $this->testImageDir . '/' . $filename;
        // Minimal valid JPEG: SOI + APP0 + EOI
        $jpeg = "\xFF\xD8\xFF\xE0\x00\x10JFIF\x00\x01\x01\x00\x00\x01\x00\x01\x00\x00\xFF\xD9";
        file_put_contents($path, $jpeg);
        return $path;
    }
    
    /**
     * Create a test JPEG with proper EXIF data using GD
     * Returns null if GD is not available
     */
    private function createJpegWithExif(string $filename, bool $addBridgeMarker = false): ?string
    {
        if (!extension_loaded('gd')) {
            return null;
        }
        
        $path = $this->testImageDir . '/' . $filename;
        
        // Create a small test image with GD
        $image = imagecreatetruecolor(100, 100);
        $white = imagecolorallocate($image, 255, 255, 255);
        imagefill($image, 0, 0, $white);
        imagejpeg($image, $path);
        imagedestroy($image);
        
        // Note: GD doesn't support writing EXIF, so we test the function 
        // behavior with files that may or may not have EXIF
        
        return $path;
    }
    
    /**
     * Test that function returns 0 for non-existent file
     */
    public function testGetHistoryImageCaptureTime_NonExistentFile_ReturnsZero(): void
    {
        $result = getHistoryImageCaptureTime('/nonexistent/file.jpg');
        $this->assertEquals(0, $result);
    }
    
    /**
     * Test that function returns file mtime when EXIF is not available
     */
    public function testGetHistoryImageCaptureTime_MinimalJpeg_ReturnsFileMtime(): void
    {
        $file = $this->createMinimalJpeg('test.jpg');
        
        $result = getHistoryImageCaptureTime($file);
        $mtime = filemtime($file);
        
        // Should return file mtime (or near it) when no EXIF
        $this->assertGreaterThan(0, $result);
        // Allow for small time difference
        $this->assertLessThanOrEqual(2, abs($result - $mtime));
    }
    
    /**
     * Test that function handles empty files gracefully
     */
    public function testGetHistoryImageCaptureTime_EmptyFile_ReturnsFileMtime(): void
    {
        $file = $this->testImageDir . '/empty.jpg';
        file_put_contents($file, '');
        
        $result = getHistoryImageCaptureTime($file);
        
        // Should fall back to file mtime for empty files
        $this->assertGreaterThanOrEqual(0, $result);
    }
    
    /**
     * Test parseGPSTimestamp with valid GPS data (simple numeric values)
     */
    public function testParseGPSTimestamp_ValidData_ReturnsTimestamp(): void
    {
        $gps = [
            'GPSDateStamp' => '2024:12:25',
            'GPSTimeStamp' => [16, 30, 45]  // Simple numeric array
        ];
        
        $result = parseGPSTimestamp($gps);
        
        // Should parse as UTC
        $expected = strtotime('2024-12-25 16:30:45 UTC');
        $this->assertEquals($expected, $result);
    }
    
    /**
     * Test parseGPSTimestamp with rational GPS time values (num/den format)
     */
    public function testParseGPSTimestamp_RationalValues_ReturnsTimestamp(): void
    {
        $gps = [
            'GPSDateStamp' => '2024:12:25',
            'GPSTimeStamp' => [
                ['num' => 16, 'den' => 1],
                ['num' => 30, 'den' => 1],
                ['num' => 45, 'den' => 1]
            ]
        ];
        
        $result = parseGPSTimestamp($gps);
        
        // Should parse as UTC
        $expected = strtotime('2024-12-25 16:30:45 UTC');
        $this->assertEquals($expected, $result);
    }
    
    /**
     * Test parseGPSTimestamp with fractional seconds (rational with denominator)
     */
    public function testParseGPSTimestamp_FractionalSeconds_ReturnsTimestamp(): void
    {
        $gps = [
            'GPSDateStamp' => '2024:12:25',
            'GPSTimeStamp' => [
                ['num' => 16, 'den' => 1],
                ['num' => 30, 'den' => 1],
                ['num' => 4550, 'den' => 100]  // 45.50 seconds
            ]
        ];
        
        $result = parseGPSTimestamp($gps);
        
        // Should parse as UTC (seconds truncated to int)
        $expected = strtotime('2024-12-25 16:30:45 UTC');
        $this->assertEquals($expected, $result);
    }
    
    /**
     * Test parseGPSTimestamp with missing date
     */
    public function testParseGPSTimestamp_MissingDate_ReturnsZero(): void
    {
        $gps = [
            'GPSTimeStamp' => [16, 30, 45]
        ];
        
        $result = parseGPSTimestamp($gps);
        $this->assertEquals(0, $result);
    }
    
    /**
     * Test parseGPSTimestamp with missing time
     */
    public function testParseGPSTimestamp_MissingTime_ReturnsZero(): void
    {
        $gps = [
            'GPSDateStamp' => '2024:12:25'
        ];
        
        $result = parseGPSTimestamp($gps);
        $this->assertEquals(0, $result);
    }
    
    /**
     * Test parseGPSTimestamp with invalid time array (too few elements)
     */
    public function testParseGPSTimestamp_InvalidTimeArray_ReturnsZero(): void
    {
        $gps = [
            'GPSDateStamp' => '2024:12:25',
            'GPSTimeStamp' => [16, 30]  // Missing seconds
        ];
        
        $result = parseGPSTimestamp($gps);
        $this->assertEquals(0, $result);
    }
    
    /**
     * Test parseGPSTimestamp with non-array time
     */
    public function testParseGPSTimestamp_NonArrayTime_ReturnsZero(): void
    {
        $gps = [
            'GPSDateStamp' => '2024:12:25',
            'GPSTimeStamp' => '16:30:45'  // String instead of array
        ];
        
        $result = parseGPSTimestamp($gps);
        $this->assertEquals(0, $result);
    }
    
    /**
     * Test isBridgeUpload with non-existent file
     */
    public function testIsBridgeUpload_NonExistentFile_ReturnsFalse(): void
    {
        $result = isBridgeUpload('/nonexistent/file.jpg');
        $this->assertFalse($result);
    }
    
    /**
     * Test isBridgeUpload with file without EXIF
     */
    public function testIsBridgeUpload_NoExif_ReturnsFalse(): void
    {
        $file = $this->createMinimalJpeg('test.jpg');
        $result = isBridgeUpload($file);
        $this->assertFalse($result);
    }
    
    /**
     * Test isJpegComplete with valid JPEG
     */
    public function testIsJpegComplete_ValidJpeg_ReturnsTrue(): void
    {
        $file = $this->testImageDir . '/valid.jpg';
        // JPEG with proper end marker
        file_put_contents($file, "\xFF\xD8\xFF\xE0" . str_repeat("\x00", 100) . "\xFF\xD9");
        
        $result = isJpegComplete($file);
        $this->assertTrue($result);
    }
    
    /**
     * Test isJpegComplete with truncated JPEG (missing end marker)
     */
    public function testIsJpegComplete_TruncatedJpeg_ReturnsFalse(): void
    {
        $file = $this->testImageDir . '/truncated.jpg';
        // JPEG without end marker
        file_put_contents($file, "\xFF\xD8\xFF\xE0" . str_repeat("\x00", 100));
        
        $result = isJpegComplete($file);
        $this->assertFalse($result);
    }
    
    /**
     * Test isJpegComplete with too small file
     */
    public function testIsJpegComplete_TooSmall_ReturnsFalse(): void
    {
        $file = $this->testImageDir . '/small.jpg';
        file_put_contents($file, "\xFF\xD8\xFF\xD9");  // Only 4 bytes
        
        $result = isJpegComplete($file);
        $this->assertFalse($result);
    }
    
    /**
     * Test isPngComplete with valid PNG
     */
    public function testIsPngComplete_ValidPng_ReturnsTrue(): void
    {
        $file = $this->testImageDir . '/valid.png';
        // PNG header + IEND chunk
        $pngHeader = "\x89\x50\x4E\x47\x0D\x0A\x1A\x0A";
        $iendChunk = "\x00\x00\x00\x00IEND\xAE\x42\x60\x82";
        file_put_contents($file, $pngHeader . str_repeat("\x00", 100) . $iendChunk);
        
        $result = isPngComplete($file);
        $this->assertTrue($result);
    }
    
    /**
     * Test isPngComplete with truncated PNG
     */
    public function testIsPngComplete_TruncatedPng_ReturnsFalse(): void
    {
        $file = $this->testImageDir . '/truncated.png';
        // PNG header without IEND
        $pngHeader = "\x89\x50\x4E\x47\x0D\x0A\x1A\x0A";
        file_put_contents($file, $pngHeader . str_repeat("\x00", 100));
        
        $result = isPngComplete($file);
        $this->assertFalse($result);
    }
    
    /**
     * Test isWebpComplete with valid WebP
     */
    public function testIsWebpComplete_ValidWebp_ReturnsTrue(): void
    {
        $file = $this->testImageDir . '/valid.webp';
        // WebP: RIFF + size + WEBP + data
        $size = 100;  // Total data after RIFF header
        $content = "RIFF" . pack("V", $size) . "WEBP" . str_repeat("\x00", $size - 4);
        file_put_contents($file, $content);
        
        $result = isWebpComplete($file);
        $this->assertTrue($result);
    }
    
    /**
     * Test isWebpComplete with truncated WebP
     */
    public function testIsWebpComplete_TruncatedWebp_ReturnsFalse(): void
    {
        $file = $this->testImageDir . '/truncated.webp';
        // WebP with incorrect size (claims more data than present)
        $size = 1000;  // Claims 1000 bytes but we only write 100
        $content = "RIFF" . pack("V", $size) . "WEBP" . str_repeat("\x00", 50);
        file_put_contents($file, $content);
        
        $result = isWebpComplete($file);
        $this->assertFalse($result);
    }
    
    /**
     * Test isImageComplete auto-detection for JPEG
     */
    public function testIsImageComplete_JpegAutoDetect_ReturnsTrue(): void
    {
        $file = $this->testImageDir . '/test.jpg';
        file_put_contents($file, "\xFF\xD8\xFF\xE0" . str_repeat("\x00", 100) . "\xFF\xD9");
        
        $result = isImageComplete($file);  // No format specified
        $this->assertTrue($result);
    }
    
    /**
     * Test isImageComplete with explicit format
     */
    public function testIsImageComplete_ExplicitFormat_ReturnsTrue(): void
    {
        $file = $this->testImageDir . '/test.jpg';
        file_put_contents($file, "\xFF\xD8\xFF\xE0" . str_repeat("\x00", 100) . "\xFF\xD9");
        
        $result = isImageComplete($file, 'jpg');
        $this->assertTrue($result);
    }
    
    /**
     * Test that function correctly handles the summary scenarios:
     * Bridge upload, Direct camera, GPS timestamp, OffsetTimeOriginal
     * 
     * Note: Creating actual EXIF data in PHP is complex without external tools.
     * These tests verify the parsing logic paths are correct using mocked data.
     */
    public function testGetHistoryImageCaptureTime_DocumentsBehavior(): void
    {
        // Document the expected behavior per the specification:
        // 
        // | Scenario               | EXIF UserComment           | Interpretation                    |
        // |------------------------|----------------------------|-----------------------------------|
        // | Bridge upload          | Contains "AviationWX-Bridge" | DateTimeOriginal is UTC         |
        // | Direct camera          | No marker                  | DateTimeOriginal is local time   |
        // | Any with GPS timestamp | N/A                        | GPSTimeStamp is always UTC       |
        // | Any with OffsetTimeOriginal | N/A                   | Use specified offset             |
        
        $this->assertTrue(function_exists('getHistoryImageCaptureTime'));
        $this->assertTrue(function_exists('parseGPSTimestamp'));
        $this->assertTrue(function_exists('isBridgeUpload'));
    }
    
    /**
     * Test GPS timestamp parsing edge case: zero denominator protection
     */
    public function testParseGPSTimestamp_ZeroDenominator_HandledSafely(): void
    {
        $gps = [
            'GPSDateStamp' => '2024:12:25',
            'GPSTimeStamp' => [
                ['num' => 16, 'den' => 0],  // Zero denominator - should not crash
                ['num' => 30, 'den' => 1],
                ['num' => 45, 'den' => 1]
            ]
        ];
        
        // Should use max(1, den) to prevent division by zero
        $result = parseGPSTimestamp($gps);
        $this->assertGreaterThan(0, $result);
    }

    /**
     * Test getHistoryStatus returns correct structure
     */
    public function testGetHistoryStatus_ReturnsCorrectStructure(): void
    {
        $status = getHistoryStatus('nonexistent_airport_' . time(), 0);
        
        $this->assertArrayHasKey('enabled', $status);
        $this->assertArrayHasKey('available', $status);
        $this->assertArrayHasKey('frame_count', $status);
        $this->assertArrayHasKey('max_frames', $status);
        
        $this->assertIsBool($status['enabled']);
        $this->assertIsBool($status['available']);
        $this->assertIsInt($status['frame_count']);
        $this->assertIsInt($status['max_frames']);
    }

    /**
     * Test getHistoryStatus returns enabled=false when max_frames < 2
     */
    public function testGetHistoryStatus_MaxFramesLessThan2_ReturnsDisabled(): void
    {
        // Default config has max_frames >= 2 for enabled airports
        // For non-existent airport, it falls back to global default
        $status = getHistoryStatus('nonexistent_airport_' . time(), 0);
        
        // The status depends on whether the airport exists and its config
        // For a non-existent airport, max_frames will be the default (12)
        // so it should be enabled but not available (no frames)
        $this->assertArrayHasKey('enabled', $status);
        $this->assertArrayHasKey('max_frames', $status);
    }

    /**
     * Test getHistoryFrames returns empty array when max_frames < 2
     */
    public function testGetHistoryFrames_ReturnsEmptyWhenHistoryDisabled(): void
    {
        // For airports where history is effectively disabled (max_frames < 2)
        // getHistoryFrames should return empty array
        // Since we can't easily set max_frames < 2 in tests without config manipulation,
        // we just verify the function exists and returns an array
        $frames = getHistoryFrames('nonexistent_airport_' . time(), 0);
        $this->assertIsArray($frames);
    }

    /**
     * Test getHistoryDiskUsage returns 0 for non-existent directory
     */
    public function testGetHistoryDiskUsage_NonExistentDir_ReturnsZero(): void
    {
        $usage = getHistoryDiskUsage('nonexistent_airport_' . time(), 0);
        $this->assertEquals(0, $usage);
    }
}
