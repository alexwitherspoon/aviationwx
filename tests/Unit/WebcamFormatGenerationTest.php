<?php
/**
 * Unit Tests for Webcam Format Generation
 * 
 * Tests format detection and validation functionality:
 * - detectImageFormat() - JPEG, PNG, WebP, AVIF detection
 * - isValidAvifFile() - AVIF header validation
 */

namespace AviationWX\Tests;

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../lib/constants.php';
require_once __DIR__ . '/../../lib/config.php';
require_once __DIR__ . '/../../lib/webcam-format-generation.php';
require_once __DIR__ . '/../../api/webcam.php';

class WebcamFormatGenerationTest extends TestCase
{
    private $testImageDir;
    
    protected function setUp(): void
    {
        parent::setUp();
        $this->testImageDir = sys_get_temp_dir() . '/webcam_format_test_' . uniqid();
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
     * Create a minimal JPEG file
     */
    private function createTestJpeg($filename): string
    {
        $path = $this->testImageDir . '/' . $filename;
        // Minimal valid JPEG: SOI marker + EOI marker
        file_put_contents($path, "\xFF\xD8\xFF\xD9");
        return $path;
    }
    
    /**
     * Create a minimal PNG file
     */
    private function createTestPng($filename): string
    {
        $path = $this->testImageDir . '/' . $filename;
        // PNG signature: 89 50 4E 47 0D 0A 1A 0A
        file_put_contents($path, "\x89\x50\x4E\x47\x0D\x0A\x1A\x0A" . str_repeat("\x00", 8));
        return $path;
    }
    
    /**
     * Create a minimal WebP file
     */
    private function createTestWebp($filename): string
    {
        $path = $this->testImageDir . '/' . $filename;
        // WebP: RIFF header + WEBP identifier
        $content = "RIFF" . pack("V", 12) . "WEBP";
        file_put_contents($path, $content);
        return $path;
    }
    
    /**
     * Create a minimal AVIF file
     */
    private function createTestAvif($filename): string
    {
        $path = $this->testImageDir . '/' . $filename;
        // AVIF: ftyp box with avif major brand
        // [4 bytes size][4 bytes 'ftyp'][4 bytes major brand 'avif'][...]
        $size = pack("N", 20); // 20 bytes total
        $ftyp = "ftyp";
        $majorBrand = "avif";
        $content = $size . $ftyp . $majorBrand . str_repeat("\x00", 8);
        file_put_contents($path, $content);
        return $path;
    }
    
    public function testDetectImageFormat_JpegFile_ReturnsJpg(): void
    {
        $file = $this->createTestJpeg('test.jpg');
        $format = detectImageFormat($file);
        $this->assertEquals('jpg', $format);
    }
    
    public function testDetectImageFormat_PngFile_ReturnsPng(): void
    {
        $file = $this->createTestPng('test.png');
        $format = detectImageFormat($file);
        $this->assertEquals('png', $format);
    }
    
    public function testDetectImageFormat_WebpFile_ReturnsWebp(): void
    {
        $file = $this->createTestWebp('test.webp');
        $format = detectImageFormat($file);
        $this->assertEquals('webp', $format);
    }
    
    public function testDetectImageFormat_AvifFile_ReturnsAvif(): void
    {
        $file = $this->createTestAvif('test.avif');
        $format = detectImageFormat($file);
        $this->assertEquals('avif', $format);
    }
    
    public function testDetectImageFormat_InvalidFile_ReturnsNull(): void
    {
        $file = $this->testImageDir . '/invalid.txt';
        file_put_contents($file, "not an image");
        $format = detectImageFormat($file);
        $this->assertNull($format);
    }
    
    public function testDetectImageFormat_NonExistentFile_ReturnsNull(): void
    {
        $file = $this->testImageDir . '/nonexistent.jpg';
        $format = detectImageFormat($file);
        $this->assertNull($format);
    }
    
    public function testDetectImageFormat_EmptyFile_ReturnsNull(): void
    {
        $file = $this->testImageDir . '/empty.jpg';
        file_put_contents($file, "");
        $format = detectImageFormat($file);
        $this->assertNull($format);
    }
    
    public function testDetectImageFormat_ShortFile_ReturnsNull(): void
    {
        $file = $this->testImageDir . '/short.jpg';
        file_put_contents($file, "\xFF"); // Only 1 byte
        $format = detectImageFormat($file);
        $this->assertNull($format);
    }
    
    public function testIsValidAvifFile_ValidAvif_ReturnsTrue(): void
    {
        $file = $this->createTestAvif('test.avif');
        $result = isValidAvifFile($file);
        $this->assertTrue($result);
    }
    
    public function testIsValidAvifFile_AvifWithAvisBrand_ReturnsTrue(): void
    {
        $path = $this->testImageDir . '/test.avif';
        // AVIF with 'avis' major brand (AVIF image sequence)
        $size = pack("N", 20);
        $ftyp = "ftyp";
        $majorBrand = "avis";
        $content = $size . $ftyp . $majorBrand . str_repeat("\x00", 8);
        file_put_contents($path, $content);
        
        $result = isValidAvifFile($path);
        $this->assertTrue($result);
    }
    
    public function testIsValidAvifFile_InvalidFile_ReturnsFalse(): void
    {
        $file = $this->createTestJpeg('test.jpg');
        $result = isValidAvifFile($file);
        $this->assertFalse($result);
    }
    
    public function testIsValidAvifFile_NonExistentFile_ReturnsFalse(): void
    {
        $file = $this->testImageDir . '/nonexistent.avif';
        $result = isValidAvifFile($file);
        $this->assertFalse($result);
    }
    
    public function testIsValidAvifFile_EmptyFile_ReturnsFalse(): void
    {
        $file = $this->testImageDir . '/empty.avif';
        file_put_contents($file, "");
        $result = isValidAvifFile($file);
        $this->assertFalse($result);
    }
    
    public function testIsValidAvifFile_ShortFile_ReturnsFalse(): void
    {
        $file = $this->testImageDir . '/short.avif';
        file_put_contents($file, "\x00\x00\x00\x00ftyp"); // Too short
        $result = isValidAvifFile($file);
        $this->assertFalse($result);
    }
    
    public function testIsValidAvifFile_NonFtypBox_ReturnsFalse(): void
    {
        $file = $this->testImageDir . '/notavif.avif';
        // Valid box structure but not ftyp
        $size = pack("N", 20);
        $boxType = "moov"; // Not ftyp
        $content = $size . $boxType . str_repeat("\x00", 12);
        file_put_contents($file, $content);
        
        $result = isValidAvifFile($file);
        $this->assertFalse($result);
    }

    /**
     * Test isWebpGenerationEnabled() - Default (disabled)
     */
    public function testIsWebpGenerationEnabled_Default_ReturnsFalse(): void
    {
        // Clear any cached config
        if (function_exists('clearConfigCache')) {
            clearConfigCache();
        }
        
        // Test with no config (should default to false)
        $result = isWebpGenerationEnabled();
        $this->assertFalse($result, 'WebP generation should default to disabled');
    }

    /**
     * Test isAvifGenerationEnabled() - Default (disabled)
     */
    public function testIsAvifGenerationEnabled_Default_ReturnsFalse(): void
    {
        // Clear any cached config
        if (function_exists('clearConfigCache')) {
            clearConfigCache();
        }
        
        // Test with no config (should default to false)
        $result = isAvifGenerationEnabled();
        $this->assertFalse($result, 'AVIF generation should default to disabled');
    }

    /**
     * Test getEnabledWebcamFormats() - Default (only JPEG)
     */
    public function testGetEnabledWebcamFormats_Default_ReturnsOnlyJpg(): void
    {
        // Clear any cached config
        if (function_exists('clearConfigCache')) {
            clearConfigCache();
        }
        
        $result = getEnabledWebcamFormats();
        $this->assertIsArray($result);
        $this->assertContains('jpg', $result, 'JPEG should always be enabled');
        $this->assertCount(1, $result, 'Only JPEG should be enabled by default');
    }

    /**
     * Test getEnabledWebcamFormats() - WebP enabled
     */
    public function testGetEnabledWebcamFormats_WebpEnabled_ReturnsJpgAndWebp(): void
    {
        // This test would require mocking the config, which is complex
        // For now, we'll test the logic with a note that it requires config setup
        $this->markTestIncomplete('Requires config mocking - test manually with airports.json');
    }

    /**
     * Test getEnabledWebcamFormats() - Both enabled
     */
    public function testGetEnabledWebcamFormats_BothEnabled_ReturnsAllFormats(): void
    {
        // This test would require mocking the config, which is complex
        // For now, we'll test the logic with a note that it requires config setup
        $this->markTestIncomplete('Requires config mocking - test manually with airports.json');
    }

    /**
     * Test getFormatGenerationTimeout() returns positive integer
     */
    public function testGetFormatGenerationTimeout_ReturnsPositiveInteger(): void
    {
        $timeout = getFormatGenerationTimeout();
        $this->assertIsInt($timeout);
        $this->assertGreaterThan(0, $timeout);
    }

    /**
     * Test getFormatGenerationTimeout() returns half of worker timeout
     */
    public function testGetFormatGenerationTimeout_ReturnsHalfOfWorkerTimeout(): void
    {
        $workerTimeout = getWorkerTimeout();
        $formatTimeout = getFormatGenerationTimeout();
        $this->assertEquals((int)($workerTimeout / 2), $formatTimeout);
    }

    /**
     * Test getStagingFilePath() returns correct path format
     */
    public function testGetStagingFilePath_ReturnsCorrectPathFormat(): void
    {
        $path = getStagingFilePath('kspb', 0, 'jpg');
        $this->assertStringContainsString('cache/webcams', $path);
        $this->assertStringContainsString('kspb_0.jpg.tmp', $path);
    }

    /**
     * Test getStagingFilePath() works for different formats
     */
    public function testGetStagingFilePath_WorksForDifferentFormats(): void
    {
        $jpgPath = getStagingFilePath('kspb', 0, 'jpg');
        $webpPath = getStagingFilePath('kspb', 0, 'webp');
        $avifPath = getStagingFilePath('kspb', 0, 'avif');
        
        $this->assertStringEndsWith('.jpg.tmp', $jpgPath);
        $this->assertStringEndsWith('.webp.tmp', $webpPath);
        $this->assertStringEndsWith('.avif.tmp', $avifPath);
    }

    /**
     * Test getStagingFilePath() works for different camera indices
     */
    public function testGetStagingFilePath_WorksForDifferentCamIndices(): void
    {
        $path0 = getStagingFilePath('kspb', 0, 'jpg');
        $path1 = getStagingFilePath('kspb', 1, 'jpg');
        $path2 = getStagingFilePath('kspb', 2, 'jpg');
        
        $this->assertStringContainsString('kspb_0.jpg.tmp', $path0);
        $this->assertStringContainsString('kspb_1.jpg.tmp', $path1);
        $this->assertStringContainsString('kspb_2.jpg.tmp', $path2);
    }

    /**
     * Test getFinalFilePath() returns timestamp-based path format
     */
    public function testGetFinalFilePath_ReturnsTimestampBasedPath(): void
    {
        $timestamp = 1703700000;
        $path = getFinalFilePath('kspb', 0, 'jpg', $timestamp);
        $this->assertStringContainsString('cache/webcams', $path);
        $this->assertStringEndsWith('1703700000.jpg', $path);
        $this->assertFalse(str_ends_with($path, '.tmp'), 'Final path should not end with .tmp');
    }

    /**
     * Test getFinalFilePath() works for different formats
     */
    public function testGetFinalFilePath_WorksForDifferentFormats(): void
    {
        $timestamp = 1703700000;
        $jpgPath = getFinalFilePath('kspb', 0, 'jpg', $timestamp);
        $webpPath = getFinalFilePath('kspb', 0, 'webp', $timestamp);
        $avifPath = getFinalFilePath('kspb', 0, 'avif', $timestamp);
        
        $this->assertStringEndsWith('1703700000.jpg', $jpgPath);
        $this->assertStringEndsWith('1703700000.webp', $webpPath);
        $this->assertStringEndsWith('1703700000.avif', $avifPath);
    }

    /**
     * Test getCacheSymlinkPath() returns correct symlink path
     */
    public function testGetCacheSymlinkPath_ReturnsCorrectPath(): void
    {
        $path = getCacheSymlinkPath('kspb', 0, 'jpg');
        $this->assertStringContainsString('cache/webcams', $path);
        $this->assertStringEndsWith('kspb_0.jpg', $path);
    }

    /**
     * Test getTimestampCacheFilePath() returns timestamp-based path
     */
    public function testGetTimestampCacheFilePath_ReturnsTimestampPath(): void
    {
        $timestamp = 1703700000;
        $path = getTimestampCacheFilePath($timestamp, 'jpg');
        $this->assertStringContainsString('cache/webcams', $path);
        $this->assertStringEndsWith('1703700000.jpg', $path);
    }

    /**
     * Test cleanupStagingFiles() returns 0 when no files exist
     */
    public function testCleanupStagingFiles_NoFiles_ReturnsZero(): void
    {
        // Use a non-existent airport ID to ensure no files exist
        $result = cleanupStagingFiles('nonexistent_airport_' . time(), 0);
        $this->assertEquals(0, $result);
    }

    /**
     * Test cleanupStagingFiles() removes .tmp files
     */
    public function testCleanupStagingFiles_RemovesTmpFiles(): void
    {
        $cacheDir = __DIR__ . '/../../cache/webcams';
        $testAirport = 'test_cleanup_' . time();
        
        // Create test .tmp files
        $tmpFiles = [
            $cacheDir . '/' . $testAirport . '_0.jpg.tmp',
            $cacheDir . '/' . $testAirport . '_0.webp.tmp',
            $cacheDir . '/' . $testAirport . '_0.avif.tmp'
        ];
        
        // Ensure cache directory exists
        if (!is_dir($cacheDir)) {
            @mkdir($cacheDir, 0755, true);
        }
        
        // Create the test files
        foreach ($tmpFiles as $file) {
            @file_put_contents($file, 'test content');
        }
        
        // Verify files were created
        $this->assertFileExists($tmpFiles[0]);
        
        // Run cleanup
        $result = cleanupStagingFiles($testAirport, 0);
        
        // Verify all files were removed
        $this->assertEquals(3, $result);
        foreach ($tmpFiles as $file) {
            $this->assertFileDoesNotExist($file);
        }
    }

    /**
     * Test buildFormatCommand() generates valid WebP command
     */
    public function testBuildFormatCommand_Webp_ContainsCorrectParameters(): void
    {
        $cmd = buildFormatCommand('/source/file.jpg', '/dest/file.webp', 'webp', time());
        
        $this->assertStringContainsString('ffmpeg', $cmd);
        $this->assertStringContainsString('-i', $cmd);
        $this->assertStringContainsString('/source/file.jpg', $cmd);
        $this->assertStringContainsString('/dest/file.webp', $cmd);
        $this->assertStringContainsString('-q:v 30', $cmd);
        $this->assertStringContainsString('nice -n -1', $cmd);
    }

    /**
     * Test buildFormatCommand() generates valid AVIF command
     */
    public function testBuildFormatCommand_Avif_ContainsCorrectParameters(): void
    {
        $cmd = buildFormatCommand('/source/file.jpg', '/dest/file.avif', 'avif', time());
        
        $this->assertStringContainsString('ffmpeg', $cmd);
        $this->assertStringContainsString('libaom-av1', $cmd);
        $this->assertStringContainsString('/source/file.jpg', $cmd);
        $this->assertStringContainsString('/dest/file.avif', $cmd);
        $this->assertStringContainsString('-crf 30', $cmd);
        $this->assertStringContainsString('nice -n -1', $cmd);
    }

    /**
     * Test buildFormatCommand() generates valid JPG command
     */
    public function testBuildFormatCommand_Jpg_ContainsCorrectParameters(): void
    {
        $cmd = buildFormatCommand('/source/file.png', '/dest/file.jpg', 'jpg', time());
        
        $this->assertStringContainsString('ffmpeg', $cmd);
        $this->assertStringContainsString('/source/file.png', $cmd);
        $this->assertStringContainsString('/dest/file.jpg', $cmd);
        $this->assertStringContainsString('-q:v 2', $cmd);
    }

    /**
     * Test buildFormatCommand() includes mtime sync when captureTime provided
     */
    public function testBuildFormatCommand_WithCaptureTime_IncludesTouchCommand(): void
    {
        $captureTime = strtotime('2024-12-25 10:30:00');
        $cmd = buildFormatCommand('/source/file.jpg', '/dest/file.webp', 'webp', $captureTime);
        
        $this->assertStringContainsString('touch -t', $cmd);
        $this->assertStringContainsString('20241225103000', $cmd);
    }

    /**
     * Test buildFormatCommand() skips mtime sync when captureTime is 0
     */
    public function testBuildFormatCommand_ZeroCaptureTime_SkipsTouchCommand(): void
    {
        $cmd = buildFormatCommand('/source/file.jpg', '/dest/file.webp', 'webp', 0);
        
        $this->assertStringNotContainsString('touch -t', $cmd);
    }

    /**
     * Test promoteFormats() returns empty array when no formats to promote
     */
    public function testPromoteFormats_NoFormats_ReturnsSourceFormatIfExists(): void
    {
        // Create a staging file for this test
        $testAirport = 'test_promote_' . time();
        $stagingFile = getStagingFilePath($testAirport, 0, 'jpg');
        $cacheDir = dirname($stagingFile);
        $timestamp = time();
        
        if (!is_dir($cacheDir)) {
            @mkdir($cacheDir, 0755, true);
        }
        
        // Create staging file
        @file_put_contents($stagingFile, 'test jpg content');
        
        // Promote with empty format results
        $result = promoteFormats($testAirport, 0, [], 'jpg', $timestamp);
        
        // Should have promoted the source format
        $this->assertContains('jpg', $result);
        
        // Cleanup
        $timestampFile = getFinalFilePath($testAirport, 0, 'jpg', $timestamp);
        $symlink = getCacheSymlinkPath($testAirport, 0, 'jpg');
        @unlink($timestampFile);
        if (is_link($symlink)) {
            @unlink($symlink);
        }
    }

    /**
     * Test promoteFormats() promotes successful formats only
     */
    public function testPromoteFormats_PromotesSuccessfulFormatsOnly(): void
    {
        $testAirport = 'test_promote_partial_' . time();
        $cacheDir = __DIR__ . '/../../cache/webcams';
        $timestamp = time();
        
        if (!is_dir($cacheDir)) {
            @mkdir($cacheDir, 0755, true);
        }
        
        // Create staging files for jpg and webp (avif will be marked as failed)
        @file_put_contents(getStagingFilePath($testAirport, 0, 'jpg'), 'test jpg');
        @file_put_contents(getStagingFilePath($testAirport, 0, 'webp'), 'test webp');
        
        // Promote with mixed results (webp success, avif failed)
        $formatResults = ['webp' => true, 'avif' => false];
        $result = promoteFormats($testAirport, 0, $formatResults, 'jpg', $timestamp);
        
        // Should include jpg and webp, not avif
        $this->assertContains('jpg', $result);
        $this->assertContains('webp', $result);
        $this->assertNotContains('avif', $result);
        
        // Verify timestamp files exist
        $this->assertFileExists(getFinalFilePath($testAirport, 0, 'jpg', $timestamp));
        $this->assertFileExists(getFinalFilePath($testAirport, 0, 'webp', $timestamp));
        
        // Verify symlinks exist
        $this->assertTrue(is_link(getCacheSymlinkPath($testAirport, 0, 'jpg')));
        $this->assertTrue(is_link(getCacheSymlinkPath($testAirport, 0, 'webp')));
        
        // Cleanup
        @unlink(getFinalFilePath($testAirport, 0, 'jpg', $timestamp));
        @unlink(getFinalFilePath($testAirport, 0, 'webp', $timestamp));
        $symlinkJpg = getCacheSymlinkPath($testAirport, 0, 'jpg');
        $symlinkWebp = getCacheSymlinkPath($testAirport, 0, 'webp');
        if (is_link($symlinkJpg)) {
            @unlink($symlinkJpg);
        }
        if (is_link($symlinkWebp)) {
            @unlink($symlinkWebp);
        }
    }
}

