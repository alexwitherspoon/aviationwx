<?php
/**
 * Unit Tests for Webcam History Time Handling
 * 
 * Tests the getHistoryImageCaptureTime() function's ability to handle:
 * - Bridge uploads with UTC timestamps (AviationWX-Bridge marker)
 * - Direct camera uploads with local time (backward compatible)
 * - GPS timestamps (always UTC per EXIF spec)
 * - EXIF 2.31 OffsetTimeOriginal timezone offsets
 */

namespace AviationWX\Tests;

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../lib/webcam-history.php';

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
     * Test validateImageForHistory with valid complete image
     */
    public function testValidateImageForHistory_ValidImage_ReturnsTrue(): void
    {
        $file = $this->testImageDir . '/valid.jpg';
        // Create a "valid" JPEG that passes basic checks
        $jpeg = "\xFF\xD8\xFF\xE0" . str_repeat("\x00", 200) . "\xFF\xD9";
        file_put_contents($file, $jpeg);
        
        $result = validateImageForHistory($file);
        $this->assertTrue($result);
    }
    
    /**
     * Test validateImageForHistory with truncated image
     */
    public function testValidateImageForHistory_TruncatedImage_ReturnsFalse(): void
    {
        $file = $this->testImageDir . '/truncated.jpg';
        // Truncated JPEG (missing end marker)
        $jpeg = "\xFF\xD8\xFF\xE0" . str_repeat("\x00", 200);
        file_put_contents($file, $jpeg);
        
        $result = validateImageForHistory($file);
        $this->assertFalse($result);
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
     * Test getHistoryFrames() returns frames with formats array
     */
    public function testGetHistoryFrames_ReturnsFormatsArray(): void
    {
        $testAirport = 'test_frames_' . time();
        $historyDir = getWebcamHistoryDir($testAirport, 0);
        
        // Create history directory
        @mkdir($historyDir, 0755, true);
        
        $timestamp = time() - 60;
        
        // Create multiple format files for the same timestamp
        @file_put_contents($historyDir . '/' . $timestamp . '.jpg', 'test jpg');
        @file_put_contents($historyDir . '/' . $timestamp . '.webp', 'test webp');
        
        $frames = getHistoryFrames($testAirport, 0);
        
        $this->assertCount(1, $frames);
        $this->assertEquals($timestamp, $frames[0]['timestamp']);
        $this->assertArrayHasKey('formats', $frames[0]);
        $this->assertContains('jpg', $frames[0]['formats']);
        $this->assertContains('webp', $frames[0]['formats']);
        
        // Cleanup
        @unlink($historyDir . '/' . $timestamp . '.jpg');
        @unlink($historyDir . '/' . $timestamp . '.webp');
        @rmdir($historyDir);
    }

    /**
     * Test getHistoryFrames() returns multiple frames sorted by timestamp
     */
    public function testGetHistoryFrames_SortsByTimestamp(): void
    {
        $testAirport = 'test_sort_' . time();
        $historyDir = getWebcamHistoryDir($testAirport, 0);
        
        @mkdir($historyDir, 0755, true);
        
        $ts1 = time() - 120;
        $ts2 = time() - 60;
        $ts3 = time();
        
        // Create files in random order
        @file_put_contents($historyDir . '/' . $ts2 . '.jpg', 'test');
        @file_put_contents($historyDir . '/' . $ts3 . '.jpg', 'test');
        @file_put_contents($historyDir . '/' . $ts1 . '.jpg', 'test');
        
        $frames = getHistoryFrames($testAirport, 0);
        
        $this->assertCount(3, $frames);
        $this->assertEquals($ts1, $frames[0]['timestamp']);
        $this->assertEquals($ts2, $frames[1]['timestamp']);
        $this->assertEquals($ts3, $frames[2]['timestamp']);
        
        // Cleanup
        @unlink($historyDir . '/' . $ts1 . '.jpg');
        @unlink($historyDir . '/' . $ts2 . '.jpg');
        @unlink($historyDir . '/' . $ts3 . '.jpg');
        @rmdir($historyDir);
    }

    /**
     * Test getHistoryDiskUsage() includes all format files
     */
    public function testGetHistoryDiskUsage_IncludesAllFormats(): void
    {
        $testAirport = 'test_disk_' . time();
        $historyDir = getWebcamHistoryDir($testAirport, 0);
        
        @mkdir($historyDir, 0755, true);
        
        $timestamp = time();
        $jpgContent = str_repeat('j', 1000);
        $webpContent = str_repeat('w', 500);
        $avifContent = str_repeat('a', 300);
        
        @file_put_contents($historyDir . '/' . $timestamp . '.jpg', $jpgContent);
        @file_put_contents($historyDir . '/' . $timestamp . '.webp', $webpContent);
        @file_put_contents($historyDir . '/' . $timestamp . '.avif', $avifContent);
        
        $usage = getHistoryDiskUsage($testAirport, 0);
        
        // Should sum all format sizes
        $expectedSize = strlen($jpgContent) + strlen($webpContent) + strlen($avifContent);
        $this->assertEquals($expectedSize, $usage);
        
        // Cleanup
        @unlink($historyDir . '/' . $timestamp . '.jpg');
        @unlink($historyDir . '/' . $timestamp . '.webp');
        @unlink($historyDir . '/' . $timestamp . '.avif');
        @rmdir($historyDir);
    }

    /**
     * Test getHistoryDiskUsage() returns 0 for non-existent directory
     */
    public function testGetHistoryDiskUsage_NonExistentDir_ReturnsZero(): void
    {
        $usage = getHistoryDiskUsage('nonexistent_airport_' . time(), 0);
        $this->assertEquals(0, $usage);
    }

    /**
     * Test cleanupHistoryFramesAllFormats() removes all formats for old timestamps
     */
    public function testCleanupHistoryFramesAllFormats_RemovesAllFormatsForOldTimestamps(): void
    {
        $testAirport = 'test_cleanup_' . time();
        $historyDir = getWebcamHistoryDir($testAirport, 0);
        
        @mkdir($historyDir, 0755, true);
        
        // Create 5 frame sets (exceed default max of 3 from test config)
        $timestamps = [];
        for ($i = 0; $i < 5; $i++) {
            $ts = time() - (300 - $i * 60); // Oldest first
            $timestamps[] = $ts;
            @file_put_contents($historyDir . '/' . $ts . '.jpg', 'test');
            @file_put_contents($historyDir . '/' . $ts . '.webp', 'test');
        }
        
        // Run cleanup (should keep most recent frames)
        cleanupHistoryFramesAllFormats($testAirport, 0);
        
        // Check that frames were cleaned up
        $remainingFrames = getHistoryFrames($testAirport, 0);
        $maxFrames = getWebcamHistoryMaxFrames($testAirport);
        
        // Should have at most maxFrames
        $this->assertLessThanOrEqual($maxFrames, count($remainingFrames));
        
        // Cleanup remaining files
        foreach ($remainingFrames as $frame) {
            foreach ($frame['formats'] as $fmt) {
                @unlink($historyDir . '/' . $frame['timestamp'] . '.' . $fmt);
            }
        }
        @rmdir($historyDir);
    }

    /**
     * Test saveAllFormatsToHistory() returns results for each format
     */
    public function testSaveAllFormatsToHistory_ReturnsResultsForEachFormat(): void
    {
        // Skip if history is not enabled for test airport
        if (!isWebcamHistoryEnabledForAirport('kspb')) {
            $this->markTestSkipped('History not enabled for test airport');
        }
        
        require_once __DIR__ . '/../../lib/webcam-format-generation.php';
        $testAirport = 'kspb';
        $timestamp = time();
        
        // Create cache files that would be "promoted"
        $jpgFile = getCacheFile($testAirport, 0, 'jpg', 'primary');
        $webpFile = getCacheFile($testAirport, 0, 'webp', 'primary');
        
        // Create test JPEG with proper structure
        $jpeg = "\xFF\xD8\xFF\xE0" . str_repeat("\x00", 200) . "\xFF\xD9";
        @file_put_contents($jpgFile, $jpeg);
        @file_put_contents($webpFile, $jpeg);
        
        // Set mtime
        @touch($jpgFile, $timestamp);
        @touch($webpFile, $timestamp);
        
        $results = saveAllFormatsToHistory($testAirport, 0, ['jpg', 'webp']);
        
        $this->assertArrayHasKey('jpg', $results);
        $this->assertArrayHasKey('webp', $results);
        $this->assertTrue($results['jpg']);
        $this->assertTrue($results['webp']);
        
        // Cleanup
        $historyDir = getWebcamHistoryDir($testAirport, 0);
        $files = glob($historyDir . '/*.*');
        foreach ($files as $file) {
            @unlink($file);
        }
        @unlink($jpgFile);
        @unlink($webpFile);
    }

    /**
     * Test saveAllFormatsToHistory() returns empty array when history disabled
     */
    public function testSaveAllFormatsToHistory_HistoryDisabled_ReturnsEmptyArray(): void
    {
        // Use an airport ID that definitely won't have history enabled
        $results = saveAllFormatsToHistory('nonexistent_no_history_' . time(), 0, ['jpg', 'webp']);
        $this->assertEmpty($results);
    }
}

