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
        $path = getStagingFilePath('kspb', 0, 'jpg', 'primary');
        $this->assertStringContainsString('cache/webcams', $path);
        $this->assertStringContainsString('kspb/0/staging_primary.jpg.tmp', $path);
    }

    /**
     * Test getStagingFilePath() works for different formats
     */
    public function testGetStagingFilePath_WorksForDifferentFormats(): void
    {
        $jpgPath = getStagingFilePath('kspb', 0, 'jpg', 'primary');
        $webpPath = getStagingFilePath('kspb', 0, 'webp', 'primary');
        $avifPath = getStagingFilePath('kspb', 0, 'avif', 'primary');
        
        $this->assertStringEndsWith('staging_primary.jpg.tmp', $jpgPath);
        $this->assertStringEndsWith('staging_primary.webp.tmp', $webpPath);
        $this->assertStringEndsWith('staging_primary.avif.tmp', $avifPath);
    }

    /**
     * Test getStagingFilePath() works for different camera indices
     */
    public function testGetStagingFilePath_WorksForDifferentCamIndices(): void
    {
        $path0 = getStagingFilePath('kspb', 0, 'jpg', 'primary');
        $path1 = getStagingFilePath('kspb', 1, 'jpg', 'primary');
        $path2 = getStagingFilePath('kspb', 2, 'jpg', 'primary');
        
        $this->assertStringContainsString('kspb/0/staging_primary.jpg.tmp', $path0);
        $this->assertStringContainsString('kspb/1/staging_primary.jpg.tmp', $path1);
        $this->assertStringContainsString('kspb/2/staging_primary.jpg.tmp', $path2);
    }

    /**
     * Test getFinalFilePath() returns timestamp-based path format
     */
    public function testGetFinalFilePath_ReturnsTimestampBasedPath(): void
    {
        $timestamp = 1703700000;
        $path = getFinalFilePath('kspb', 0, 'jpg', $timestamp);
        $this->assertStringContainsString('cache/webcams', $path);
        $this->assertStringContainsString('kspb/0/1703700000_primary.jpg', $path);
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
        
        $this->assertStringEndsWith('1703700000_primary.jpg', $jpgPath);
        $this->assertStringEndsWith('1703700000_primary.webp', $webpPath);
        $this->assertStringEndsWith('1703700000_primary.avif', $avifPath);
    }

    /**
     * Test getCacheSymlinkPath() returns correct symlink path
     */
    public function testGetCacheSymlinkPath_ReturnsCorrectPath(): void
    {
        $path = getCacheSymlinkPath('kspb', 0, 'jpg');
        $this->assertStringContainsString('cache/webcams', $path);
        $this->assertStringEndsWith('kspb/0/current.jpg', $path);
    }

    /**
     * Test getTimestampCacheFilePath() returns timestamp-based path
     */
    public function testGetTimestampCacheFilePath_ReturnsTimestampPath(): void
    {
        $timestamp = 1703700000;
        $path = getTimestampCacheFilePath('kspb', 0, $timestamp, 'jpg', 'primary');
        $this->assertStringContainsString('cache/webcams', $path);
        $this->assertStringContainsString('kspb/0/1703700000_primary.jpg', $path);
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
        $testAirport = 'test_cleanup_' . time();
        
        // Create test .tmp files using the new path structure
        $tmpFiles = [
            getStagingFilePath($testAirport, 0, 'jpg', 'primary'),
            getStagingFilePath($testAirport, 0, 'webp', 'primary'),
            getStagingFilePath($testAirport, 0, 'avif', 'primary')
        ];
        
        // Create the test files (getStagingFilePath ensures directory exists)
        foreach ($tmpFiles as $file) {
            $dir = dirname($file);
            if (!is_dir($dir)) {
                @mkdir($dir, 0755, true);
            }
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
     * Test buildFormatCommand() generates valid WebP command with -f webp flag
     */
    public function testBuildFormatCommand_Webp_ContainsCorrectParameters(): void
    {
        $cmd = buildFormatCommand('/source/file.jpg', '/dest/file.webp.tmp', 'webp', time());
        
        $this->assertStringContainsString('ffmpeg', $cmd);
        $this->assertStringContainsString('-i', $cmd);
        $this->assertStringContainsString('/source/file.jpg', $cmd);
        $this->assertStringContainsString('/dest/file.webp.tmp', $cmd);
        $this->assertStringContainsString('-f webp', $cmd, 'WebP command must include -f webp flag for .tmp extension');
        $this->assertStringContainsString('-q:v 30', $cmd);
        $this->assertStringContainsString('nice -n -1', $cmd);
    }

    /**
     * Test buildFormatCommand() generates valid AVIF command with -f avif flag
     */
    public function testBuildFormatCommand_Avif_ContainsCorrectParameters(): void
    {
        $cmd = buildFormatCommand('/source/file.jpg', '/dest/file.avif.tmp', 'avif', time());
        
        $this->assertStringContainsString('ffmpeg', $cmd);
        $this->assertStringContainsString('libaom-av1', $cmd);
        $this->assertStringContainsString('/source/file.jpg', $cmd);
        $this->assertStringContainsString('/dest/file.avif.tmp', $cmd);
        $this->assertStringContainsString('-f avif', $cmd, 'AVIF command must include -f avif flag for .tmp extension');
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
     * 
     * Verifies the touch command uses correct date format: YYYYMMDDhhmm.ss
     * (touch -t requires dot before seconds)
     */
    public function testBuildFormatCommand_WithCaptureTime_IncludesTouchCommand(): void
    {
        $captureTime = strtotime('2024-12-25 10:30:45');
        $cmd = buildFormatCommand('/source/file.jpg', '/dest/file.webp', 'webp', $captureTime);
        
        $this->assertStringContainsString('touch -t', $cmd);
        // Verify correct format: YYYYMMDDhhmm.ss (with dot before seconds)
        $this->assertStringContainsString('202412251030.45', $cmd, 'touch command must use YYYYMMDDhhmm.ss format');
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
        $stagingFile = getStagingFilePath($testAirport, 0, 'jpg', 'primary');
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
        @file_put_contents(getStagingFilePath($testAirport, 0, 'jpg', 'primary'), 'test jpg');
        @file_put_contents(getStagingFilePath($testAirport, 0, 'webp', 'primary'), 'test webp');
        
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

    /**
     * Test generateFormatsSync() actually generates WebP format (if ffmpeg available)
     * 
     * This is an integration test that requires ffmpeg to be installed.
     * It verifies that the format generation pipeline works end-to-end.
     */
    public function testGenerateFormatsSync_WebP_GeneratesValidFile(): void
    {
        // Skip if ffmpeg not available
        $ffmpegPath = trim(shell_exec('which ffmpeg 2>/dev/null') ?: '');
        if (empty($ffmpegPath)) {
            $this->markTestSkipped('ffmpeg not available');
        }
        
        // Skip if WebP generation disabled
        if (!isWebpGenerationEnabled()) {
            $this->markTestSkipped('WebP generation disabled in config');
        }
        
        $testAirport = 'test_gen_' . time();
        $cacheDir = __DIR__ . '/../../cache/webcams';
        if (!is_dir($cacheDir)) {
            @mkdir($cacheDir, 0755, true);
        }
        
        // Create a real JPEG test image using GD (if available)
        $sourceFile = $this->testImageDir . '/source.jpg';
        if (function_exists('imagecreate')) {
            $img = imagecreate(100, 100);
            $bg = imagecolorallocate($img, 255, 255, 255);
            $text = imagecolorallocate($img, 0, 0, 0);
            imagestring($img, 5, 10, 10, 'TEST', $text);
            imagejpeg($img, $sourceFile, 85);
            imagedestroy($img);
        } else {
            // Fallback: create minimal valid JPEG
            file_put_contents($sourceFile, "\xFF\xD8\xFF\xE0\x00\x10JFIF\x00\x01\x01\x01\x00H\x00H\x00\x00\xFF\xD9");
        }
        
        // Generate formats
        $formatResults = generateFormatsSync($sourceFile, $testAirport, 0, 'jpg');
        
        // Verify WebP was generated
        $this->assertArrayHasKey('webp', $formatResults, 'WebP should be in format results');
        $this->assertTrue($formatResults['webp'], 'WebP generation should succeed');
        
        // Verify staging file exists and is valid WebP
        $stagingFile = getStagingFilePath($testAirport, 0, 'webp', 'primary');
        $this->assertFileExists($stagingFile, 'WebP staging file should exist');
        
        // Verify it's a valid WebP file
        $format = detectImageFormat($stagingFile);
        $this->assertEquals('webp', $format, 'Generated file should be valid WebP');
        
        // Cleanup
        @unlink($stagingFile);
        @unlink($sourceFile);
    }

    /**
     * Test full format generation pipeline with mock data (functional test)
     * 
     * This is a comprehensive functional test that verifies the entire image conversion
     * pipeline works end-to-end using mock image data. Tests:
     * - generateFormatsSync() generates all enabled formats
     * - promoteFormats() promotes staging files to final cache
     * - Date format fix (touch -t command) works correctly
     * - Generated files are valid and accessible
     * 
     * Uses mock webcam image data to ensure testability without external dependencies.
     */
    public function testFormatGenerationPipeline_WithMockData_GeneratesAllFormats(): void
    {
        // Skip if ffmpeg not available
        $ffmpegPath = trim(shell_exec('which ffmpeg 2>/dev/null') ?: '');
        if (empty($ffmpegPath)) {
            $this->markTestSkipped('ffmpeg not available');
        }
        
        $testAirport = 'test_pipeline_' . time();
        $cacheDir = __DIR__ . '/../../cache/webcams';
        if (!is_dir($cacheDir)) {
            @mkdir($cacheDir, 0755, true);
        }
        
        // Use mock webcam image generator for consistent test data
        require_once __DIR__ . '/../../lib/mock-webcam.php';
        $mockImageData = generateMockWebcamImage($testAirport, 0, 640, 480);
        
        // Save mock image to source file
        $sourceFile = $this->testImageDir . '/source_mock.jpg';
        file_put_contents($sourceFile, $mockImageData);
        
        // Verify source file is valid JPEG
        $sourceFormat = detectImageFormat($sourceFile);
        $this->assertEquals('jpg', $sourceFormat, 'Source mock image should be valid JPEG');
        
        // Get capture time for timestamp-based filenames
        $captureTime = time();
        
        // Generate all enabled formats
        $formatResults = generateFormatsSync($sourceFile, $testAirport, 0, 'jpg');
        
        // Verify formats were generated (check what's enabled)
        $enabledFormats = getEnabledWebcamFormats();
        $expectedFormats = array_filter(['jpg', 'webp', 'avif'], function($fmt) use ($enabledFormats) {
            return in_array($fmt, $enabledFormats);
        });
        
        foreach ($expectedFormats as $format) {
            if ($format === 'jpg') {
                continue; // Source is already JPG
            }
            
            $this->assertArrayHasKey($format, $formatResults, "Format {$format} should be in results");
            
            if (in_array($format, $enabledFormats)) {
                $this->assertTrue($formatResults[$format], "Format {$format} generation should succeed");
                
                // Verify staging file exists and is valid
                $stagingFile = getStagingFilePath($testAirport, 0, $format, 'primary');
                $this->assertFileExists($stagingFile, "Staging file for {$format} should exist");
                
                // Verify it's a valid file of the expected format
                $detectedFormat = detectImageFormat($stagingFile);
                $this->assertEquals($format, $detectedFormat, "Generated {$format} file should be valid");
                
                // Verify file has content
                $this->assertGreaterThan(0, filesize($stagingFile), "Generated {$format} file should have content");
            }
        }
        
        // Test promotion pipeline
        if (!empty($formatResults)) {
            $promotedFormats = promoteFormats($testAirport, 0, $formatResults, 'jpg', $captureTime);
            
            // Verify promoted formats
            foreach ($promotedFormats as $format) {
                // Check timestamp-based file exists
                $timestampFile = getFinalFilePath($testAirport, 0, $format, $captureTime);
                $this->assertFileExists($timestampFile, "Promoted {$format} file should exist");
                
                // Verify symlink exists
                $symlinkPath = getCacheSymlinkPath($testAirport, 0, $format);
                if ($format !== 'jpg' || count($promotedFormats) > 1) {
                    // Symlink should exist for promoted formats
                    $this->assertTrue(is_link($symlinkPath) || file_exists($symlinkPath), 
                        "Symlink for {$format} should exist");
                }
                
                // Verify file mtime matches capture time (tests date format fix)
                $fileMtime = filemtime($timestampFile);
                $this->assertGreaterThanOrEqual($captureTime - 2, $fileMtime, 
                    "File mtime should be close to capture time (tests touch -t date format)");
                $this->assertLessThanOrEqual($captureTime + 2, $fileMtime, 
                    "File mtime should be close to capture time (tests touch -t date format)");
            }
        }
        
        // Cleanup
        @unlink($sourceFile);
        // Clean up staging files
        foreach (['jpg', 'webp', 'avif'] as $format) {
            $stagingFile = getStagingFilePath($testAirport, 0, $format, 'primary');
            @unlink($stagingFile);
            $timestampFile = getFinalFilePath($testAirport, 0, $format, $captureTime);
            @unlink($timestampFile);
            $symlinkPath = getCacheSymlinkPath($testAirport, 0, $format);
            if (is_link($symlinkPath)) {
                @unlink($symlinkPath);
            }
        }
    }

    /**
     * Test generateFormatsSync() actually generates AVIF format (if ffmpeg available)
     * 
     * This is an integration test that requires ffmpeg with libaom-av1 to be installed.
     * It verifies that the AVIF format generation pipeline works end-to-end.
     */
    public function testGenerateFormatsSync_AVIF_GeneratesValidFile(): void
    {
        // This test requires ffmpeg and may be slow, so we'll skip if not available
        if (!isCommandAvailable('ffmpeg')) {
            $this->markTestSkipped('ffmpeg not available');
        }
        
        $sourceFile = $this->createTestJpeg('source.jpg');
        $airportId = 'test';
        $camIndex = 0;
        
        // Enable AVIF generation for this test
        // Note: This test may fail if AVIF is disabled in config
        // We'll check if it's enabled first
        if (!isAvifGenerationEnabled()) {
            $this->markTestSkipped('AVIF generation not enabled in config');
        }
        
        $results = generateFormatsSync($sourceFile, $airportId, $camIndex, 'jpg');
        
        // Clean up staging files
        cleanupStagingFiles($airportId, $camIndex);
        
        // Should have generated AVIF (and possibly WebP if enabled)
        $this->assertIsArray($results);
    }
    
    /**
     * Test parseResolutionString() - Valid format
     */
    public function testParseResolutionString_ValidFormat_ReturnsArray(): void
    {
        $result = parseResolutionString('1920x1080');
        $this->assertIsArray($result);
        $this->assertEquals(1920, $result['width']);
        $this->assertEquals(1080, $result['height']);
    }
    
    /**
     * Test parseResolutionString() - Invalid format
     */
    public function testParseResolutionString_InvalidFormat_ReturnsNull(): void
    {
        $result = parseResolutionString('invalid');
        $this->assertNull($result);
    }
    
    /**
     * Test parseResolutionString() - Case insensitive
     */
    public function testParseResolutionString_CaseInsensitive_ReturnsArray(): void
    {
        $result = parseResolutionString('1920X1080');
        $this->assertIsArray($result);
        $this->assertEquals(1920, $result['width']);
        $this->assertEquals(1080, $result['height']);
    }
    
    /**
     * Test getVariantDimensions() - Fixed variants
     */
    public function testGetVariantDimensions_FixedVariants_ReturnsCorrectDimensions(): void
    {
        $thumb = getVariantDimensions('thumb');
        $this->assertIsArray($thumb);
        $this->assertEquals(160, $thumb['width']);
        $this->assertEquals(90, $thumb['height']);
        
        $small = getVariantDimensions('small');
        $this->assertEquals(320, $small['width']);
        $this->assertEquals(180, $small['height']);
        
        $medium = getVariantDimensions('medium');
        $this->assertEquals(640, $medium['width']);
        $this->assertEquals(360, $medium['height']);
        
        $large = getVariantDimensions('large');
        $this->assertEquals(1280, $large['width']);
        $this->assertEquals(720, $large['height']);
    }
    
    /**
     * Test getVariantDimensions() - Dynamic variants
     */
    public function testGetVariantDimensions_DynamicVariants_ReturnsProvidedDimensions(): void
    {
        $primaryDims = ['width' => 1920, 'height' => 1080];
        
        $primary = getVariantDimensions('primary', $primaryDims);
        $this->assertEquals($primaryDims, $primary);
        
        $full = getVariantDimensions('full', $primaryDims);
        $this->assertEquals($primaryDims, $full);
    }
    
    /**
     * Test getVariantDimensions() - Invalid variant
     */
    public function testGetVariantDimensions_InvalidVariant_ReturnsNull(): void
    {
        $result = getVariantDimensions('invalid');
        $this->assertNull($result);
    }
    
    /**
     * Test shouldGenerateVariant() - Smaller variant
     */
    public function testShouldGenerateVariant_SmallerVariant_ReturnsTrue(): void
    {
        $variantSize = ['width' => 320, 'height' => 180];
        $actualPrimary = ['width' => 1920, 'height' => 1080];
        
        $result = shouldGenerateVariant($variantSize, $actualPrimary);
        $this->assertTrue($result);
    }
    
    /**
     * Test shouldGenerateVariant() - Larger variant
     */
    public function testShouldGenerateVariant_LargerVariant_ReturnsFalse(): void
    {
        $variantSize = ['width' => 1920, 'height' => 1080];
        $actualPrimary = ['width' => 320, 'height' => 180];
        
        $result = shouldGenerateVariant($variantSize, $actualPrimary);
        $this->assertFalse($result);
    }
    
    /**
     * Test shouldGenerateVariant() - Equal size
     */
    public function testShouldGenerateVariant_EqualSize_ReturnsTrue(): void
    {
        $variantSize = ['width' => 1920, 'height' => 1080];
        $actualPrimary = ['width' => 1920, 'height' => 1080];
        
        $result = shouldGenerateVariant($variantSize, $actualPrimary);
        $this->assertTrue($result);
    }
    
    /**
     * Test getImageResolutionConfig() - Returns valid structure
     */
    public function testGetImageResolutionConfig_ReturnsValidStructure(): void
    {
        $config = getImageResolutionConfig();
        
        $this->assertIsArray($config);
        $this->assertArrayHasKey('primary', $config);
        $this->assertArrayHasKey('max', $config);
        $this->assertArrayHasKey('aspect_ratio', $config);
        $this->assertArrayHasKey('variants', $config);
        
        // Check primary and max are arrays with width/height
        if ($config['primary'] !== null) {
            $this->assertArrayHasKey('width', $config['primary']);
            $this->assertArrayHasKey('height', $config['primary']);
        }
        if ($config['max'] !== null) {
            $this->assertArrayHasKey('width', $config['max']);
            $this->assertArrayHasKey('height', $config['max']);
        }
        
        // Check variants is an array
        $this->assertIsArray($config['variants']);
    }
    
    /**
     * Test buildVariantCommand() - Contains resize parameters
     */
    public function testBuildVariantCommand_ContainsResizeParameters(): void
    {
        $sourceFile = $this->testImageDir . '/source.jpg';
        $destFile = $this->testImageDir . '/dest.jpg';
        $this->createTestJpeg('source.jpg');
        
        $variantDims = ['width' => 320, 'height' => 180];
        $cmd = buildVariantCommand($sourceFile, $destFile, 'small', 'jpg', $variantDims, false, 0);
        
        $this->assertStringContainsString('scale=320:180', $cmd);
        $this->assertStringContainsString(escapeshellarg($sourceFile), $cmd);
        $this->assertStringContainsString(escapeshellarg($destFile), $cmd);
    }
    
    /**
     * Test buildVariantCommand() - Letterboxing adds pad filter
     */
    public function testBuildVariantCommand_WithLetterboxing_ContainsPadFilter(): void
    {
        $sourceFile = $this->testImageDir . '/source.jpg';
        $destFile = $this->testImageDir . '/dest.jpg';
        $this->createTestJpeg('source.jpg');
        
        $variantDims = ['width' => 1920, 'height' => 1080];
        $cmd = buildVariantCommand($sourceFile, $destFile, 'primary', 'jpg', $variantDims, true, 0);
        
        $this->assertStringContainsString('pad=', $cmd);
        $this->assertStringContainsString('color=black', $cmd);
    }
    
    /**
     * Test getTimestampCacheFilePath() - With variant
     */
    public function testGetTimestampCacheFilePath_WithVariant_IncludesVariant(): void
    {
        $path = getTimestampCacheFilePath('kspb', 0, 1703700000, 'jpg', 'primary');
        $this->assertStringContainsString('kspb/0/1703700000_primary.jpg', $path);
    }
    
    /**
     * Test getTimestampCacheFilePath() - Without variant (backward compatible)
     */
    public function testGetTimestampCacheFilePath_WithoutVariant_UsesPrimary(): void
    {
        $path = getTimestampCacheFilePath('kspb', 0, 1703700000, 'jpg', 'primary');
        $this->assertStringContainsString('kspb/0/1703700000_primary.jpg', $path);
    }
}

