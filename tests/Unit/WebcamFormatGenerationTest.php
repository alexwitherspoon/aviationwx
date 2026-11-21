<?php
/**
 * Unit Tests for Webcam Format Generation
 * 
 * Tests format detection and validation functionality:
 * - detectImageFormat() - JPEG, PNG, WebP detection
 */

namespace AviationWX\Tests;

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../lib/constants.php';
require_once __DIR__ . '/../../lib/config.php';
require_once __DIR__ . '/../../lib/webcam-format-generation.php';
require_once __DIR__ . '/../../lib/circuit-breaker.php';
require_once __DIR__ . '/../../lib/logger.php';
require_once __DIR__ . '/WebcamUnsupportedPayloads.php';

class WebcamFormatGenerationTest extends TestCase
{
    use \WebcamUnsupportedPayloads;
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
        // Need at least 12 bytes for detectImageFormat to work
        file_put_contents($path, "\xFF\xD8\xFF\xD9" . str_repeat("\x00", 8));
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
    
    public function testDetectImageFormat_JpegFile_ReturnsJpg(): void
    {
        $file = $this->createTestJpeg('test.jpg');
        $format = detectImageFormat($file);
        $this->assertEquals('jpg', $format);
        $this->assertSame('jpg', detectImageFormatFromHeader(file_get_contents($file)));
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

    #[\PHPUnit\Framework\Attributes\DataProvider('unsupportedWebcamPayloadProvider')]
    public function testDetectImageFormat_UnsupportedPayload_ReturnsNull(string $label, string $bytes): void
    {
        $file = $this->testImageDir . '/payload.' . $label;
        file_put_contents($file, $bytes);
        $this->assertNull(detectImageFormat($file));
        $this->assertNull(detectServableWebcamImageFormat($file));
        $this->assertNull(webcamServableImageContent($file));
        $this->assertNull(webcamServableImageContentFromBytes($bytes));
        $this->assertNull(webcamReadServableImage($file));
        $this->assertNull(webcamImageContentType($label));
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('unsupportedWebcamPayloadProvider')]
    public function testGenerateVariantsFromOriginal_UnsupportedPayload_DoesNotPromoteAsJpeg(string $label, string $bytes): void
    {
        $airportId = 'badpipe_unit';
        $camIndex = 0;
        $timestamp = 1704067600;
        $source = $this->testImageDir . '/payload.' . $label;
        file_put_contents($source, $bytes);

        $result = generateVariantsFromOriginal($source, $airportId, $camIndex, $timestamp);
        $this->assertNull($result['original']);
        $this->assertFileDoesNotExist(
            getWebcamOriginalTimestampedPath($airportId, $camIndex, $timestamp, 'jpg')
        );
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
        $fixture = __DIR__ . '/../Fixtures/airports.json.webp-test';
        if (!file_exists($fixture)) {
            $this->markTestSkipped('WebP fixture not found');
        }
        $originalPath = getenv('CONFIG_PATH');
        putenv('CONFIG_PATH=' . $fixture);
        clearConfigCache();
        try {
            $result = getEnabledWebcamFormats();
            $this->assertIsArray($result);
            $this->assertContains('jpg', $result);
            $this->assertContains('webp', $result);
            $this->assertCount(2, $result);
        } finally {
            putenv($originalPath !== false ? 'CONFIG_PATH=' . $originalPath : 'CONFIG_PATH');
            clearConfigCache();
        }
    }

    /**
     * Test getEnabledWebcamFormats() - Both enabled
     */
    public function testGetEnabledWebcamFormats_BothEnabled_ReturnsAllFormats(): void
    {
        $fixture = __DIR__ . '/../Fixtures/airports.json.webp-test';
        if (!file_exists($fixture)) {
            $this->markTestSkipped('WebP fixture not found');
        }
        $originalPath = getenv('CONFIG_PATH');
        putenv('CONFIG_PATH=' . $fixture);
        clearConfigCache();
        try {
            $result = getEnabledWebcamFormats();
            $this->assertIsArray($result);
            $this->assertContains('jpg', $result);
            $this->assertContains('webp', $result);
        } finally {
            putenv($originalPath !== false ? 'CONFIG_PATH=' . $originalPath : 'CONFIG_PATH');
            clearConfigCache();
        }
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
        $path = getStagingFilePath('kspb', 0, 'jpg', 'original');
        $this->assertStringContainsString('cache/webcams', $path);
        $this->assertStringContainsString('kspb/0/staging_original.jpg.tmp', $path);
    }

    /**
     * Test getStagingFilePath() works for different formats
     */
    public function testGetStagingFilePath_WorksForDifferentFormats(): void
    {
        $jpgPath = getStagingFilePath('kspb', 0, 'jpg', 'original');
        $webpPath = getStagingFilePath('kspb', 0, 'webp', 'original');
        
        $this->assertStringEndsWith('staging_original.jpg.tmp', $jpgPath);
        $this->assertStringEndsWith('staging_original.webp.tmp', $webpPath);
    }

    /**
     * Test getStagingFilePath() works for different camera indices
     */
    public function testGetStagingFilePath_WorksForDifferentCamIndices(): void
    {
        $path0 = getStagingFilePath('kspb', 0, 'jpg', 'original');
        $path1 = getStagingFilePath('kspb', 1, 'jpg', 'original');
        $path2 = getStagingFilePath('kspb', 2, 'jpg', 'original');
        
        $this->assertStringContainsString('kspb/0/staging_original.jpg.tmp', $path0);
        $this->assertStringContainsString('kspb/1/staging_original.jpg.tmp', $path1);
        $this->assertStringContainsString('kspb/2/staging_original.jpg.tmp', $path2);
    }
    
    /**
     * Test getStagingFilePath() works for height-based variants
     */
    public function testGetStagingFilePath_WorksForHeightVariants(): void
    {
        $path720 = getStagingFilePath('kspb', 0, 'jpg', 720);
        $path360 = getStagingFilePath('kspb', 0, 'webp', 360);
        
        $this->assertStringEndsWith('staging_720.jpg.tmp', $path720);
        $this->assertStringEndsWith('staging_360.webp.tmp', $path360);
    }

    /**
     * Test getFinalFilePath() returns timestamp-based path format (date/hour subdir)
     */
    public function testGetFinalFilePath_ReturnsTimestampBasedPath(): void
    {
        $timestamp = 1703700000;
        $path = getFinalFilePath('kspb', 0, 'jpg', $timestamp);
        $this->assertStringContainsString('cache/webcams', $path);
        $this->assertStringContainsString('kspb/0/', $path);
        $this->assertStringContainsString('1703700000_original.jpg', $path);
        $this->assertMatchesRegularExpression('#\d{4}-\d{2}-\d{2}/\d{2}/#', $path);
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
        
        $this->assertStringEndsWith('1703700000_original.jpg', $jpgPath);
        $this->assertStringEndsWith('1703700000_original.webp', $webpPath);
    }
    
    /**
     * Test getFinalFilePath() works for height-based variants
     */
    public function testGetFinalFilePath_WorksForHeightVariants(): void
    {
        $timestamp = 1703700000;
        $path720 = getFinalFilePath('kspb', 0, 'jpg', $timestamp, 720);
        $path360 = getFinalFilePath('kspb', 0, 'webp', $timestamp, 360);
        
        $this->assertStringEndsWith('1703700000_720.jpg', $path720);
        $this->assertStringEndsWith('1703700000_360.webp', $path360);
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
     * Test getTimestampCacheFilePath() returns timestamp-based path (date/hour subdir)
     */
    public function testGetTimestampCacheFilePath_ReturnsTimestampPath(): void
    {
        $timestamp = 1703700000;
        $path = getTimestampCacheFilePath('kspb', 0, $timestamp, 'jpg', 'original');
        $this->assertStringContainsString('cache/webcams', $path);
        $this->assertStringContainsString('kspb/0/', $path);
        $this->assertStringContainsString('1703700000_original.jpg', $path);
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
            getStagingFilePath($testAirport, 0, 'jpg', 'original'),
            getStagingFilePath($testAirport, 0, 'webp', 'original')
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
        $this->assertEquals(2, $result);
        foreach ($tmpFiles as $file) {
            $this->assertFileDoesNotExist($file);
        }
    }

    /**
     * Test getTimestampCacheFilePath() - With variant (uses date/hour subdir)
     */
    public function testGetTimestampCacheFilePath_WithVariant_IncludesVariant(): void
    {
        $path = getTimestampCacheFilePath('kspb', 0, 1703700000, 'jpg', 'original');
        $this->assertStringContainsString('kspb/0/', $path);
        $this->assertStringContainsString('1703700000_original.jpg', $path);
        $this->assertMatchesRegularExpression('#\d{4}-\d{2}-\d{2}/\d{2}/#', $path);
    }
    
    /**
     * Test getTimestampCacheFilePath() with the 'original' variant
     */
    public function testGetTimestampCacheFilePath_OriginalVariant_ReturnsTimestampedPath(): void
    {
        $path = getTimestampCacheFilePath('kspb', 0, 1703700000, 'jpg', 'original');
        $this->assertStringContainsString('1703700000_original.jpg', $path);
    }

    public function testGlobWebcamHourDirImageFiles_PngFrame_IsIncluded(): void
    {
        $this->createTestJpeg('123_original.jpg');
        $this->createTestPng('456_original.png');
        $this->createTestWebp('789_original.webp');
        file_put_contents($this->testImageDir . '/notes.txt', 'skip');

        $files = globWebcamHourDirImageFiles($this->testImageDir);
        $basenames = array_map('basename', $files);
        sort($basenames);

        $this->assertSame(
            ['123_original.jpg', '456_original.png', '789_original.webp'],
            $basenames
        );
    }

    /**
     * A generated variant must carry EXIF and its mtime must match the capture
     * time, not the time ffmpeg finished. Both are required for browser-side
     * verification and for retention that trusts file mtime.
     */
    public function testGenerateVariantsFromOriginal_VariantCarriesExifAndCaptureMtime(): void
    {
        $ffmpeg = trim(shell_exec('which ffmpeg 2>/dev/null') ?: '');
        if (empty($ffmpeg)) {
            $this->markTestSkipped('ffmpeg not available');
        }

        require_once __DIR__ . '/../../lib/exif-utils.php';

        $captureTime = strtotime('2026-05-06 07:08:09 UTC');
        $testAirport = 'test_var_' . time();
        ensureCacheDir(CACHE_WEBCAMS_DIR);

        // 640x480 source tall enough to produce a 360 variant, stamped at a
        // known capture time.
        $source = $this->testImageDir . '/source.jpg';
        $img = imagecreatetruecolor(640, 480);
        imagefill($img, 0, 0, imagecolorallocate($img, 200, 200, 200));
        imagejpeg($img, $source, 90);
        imagedestroy($img);

        $this->assertTrue(
            ensureImageHasExif($source, $captureTime, 'UTC', [
                'airport_id' => $testAirport,
                'cam_index' => 0,
                'source_type' => 'test'
            ]),
            'source must carry EXIF before variant generation'
        );

        $result = generateVariantsFromOriginal($source, $testAirport, 0, $captureTime);

        $this->assertNotNull($result['original'], 'original must be preserved');
        $this->assertTrue(hasExifTimestamp($result['original']), 'original must keep its EXIF');

        $this->assertNotEmpty($result['variants'], 'a 360 jpg variant must be generated for a 640x480 source');
        foreach ($result['variants'] as $height => $paths) {
            foreach ($paths as $path) {
                $this->assertTrue(hasExifTimestamp($path), "variant {$height} must carry EXIF");
                $this->assertSame($captureTime, @filemtime($path), "variant {$height} mtime must equal capture time");
            }
        }

        // Tidy the generated cache entries for this airport.
        $cameraDir = getWebcamCameraDir($testAirport, 0);
        foreach (glob($cameraDir . '/*') ?: [] as $f) {
            @unlink($f);
        }
        @rmdir($cameraDir);
        $framesDir = getWebcamFramesDir($testAirport, 0, $captureTime);
        foreach (glob($framesDir . '/*') ?: [] as $f) {
            @unlink($f);
        }
        @rmdir($framesDir);
    }

    /**
     * One ffmpeg pass writes every output, decoding the source once: a single
     * -i and one -map/-frames block per output.
     */
    public function testBuildVariantArgs_SinglePass_ManyOutputsOneDecode(): void
    {
        $source = '/cache/webcams/x/original.jpg';
        $args = buildVariantArgs($source, [
            '720_jpg' => ['dest' => '/cache/webcams/x/staging_720_jpg.tmp', 'format' => 'jpg', 'width' => 1280, 'height' => 720],
            '360_webp' => ['dest' => '/cache/webcams/x/staging_360_webp.tmp', 'format' => 'webp', 'width' => 640, 'height' => 360],
        ]);

        $this->assertSame(1, count(array_keys($args, $source, true)), 'source must be read once');
        $this->assertContains('-noautorotate', $args, 'source pixels must not be rotated by the decoder');
        $this->assertSame(2, count(array_keys($args, '-map', true)), 'one -map per output');
        $this->assertSame(2, count(array_keys($args, '-frames:v', true)), 'one -frames:v per output');
        $this->assertSame(1, count(array_keys($args, 'scale=1280:720:force_original_aspect_ratio=decrease', true)));
        $this->assertSame(1, count(array_keys($args, 'scale=640:360:force_original_aspect_ratio=decrease', true)));
        $this->assertContains('image2', $args, 'jpg output must force the image2 muxer');
        $this->assertContains('webp', $args, 'webp output must force the webp muxer');
        $this->assertContains((string) getWebcamJpegQuality(), $args);
        $this->assertContains((string) getWebcamWebpQuality(), $args);
    }

    /**
     * A real multi-output ffmpeg run writes every staging file, verifying the
     * one-pass command shape actually produces both formats.
     */
    public function testBuildVariantArgs_MultiOutput_RunsAndWritesBothFormats(): void
    {
        $ffmpeg = trim(shell_exec('which ffmpeg 2>/dev/null') ?: '');
        if (empty($ffmpeg)) {
            $this->markTestSkipped('ffmpeg not available');
        }

        $source = $this->testImageDir . '/multi.jpg';
        $jpg = $this->testImageDir . '/out_720.jpg.tmp';
        $webp = $this->testImageDir . '/out_360.webp.tmp';
        $img = imagecreatetruecolor(640, 480);
        imagefill($img, 0, 0, imagecolorallocate($img, 90, 130, 70));
        imagejpeg($img, $source, 90);
        imagedestroy($img);

        $args = buildVariantArgs($source, [
            '720_jpg' => ['dest' => $jpg, 'format' => 'jpg', 'width' => 1280, 'height' => 720],
            '360_webp' => ['dest' => $webp, 'format' => 'webp', 'width' => 640, 'height' => 360],
        ]);
        $result = @proc_open($args, [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        if (!is_resource($result)) {
            $this->fail('proc_open failed for the single-pass ffmpeg argv');
        }
        @fclose($pipes[0]);
        stream_set_blocking($pipes[1], false);
        stream_set_blocking($pipes[2], false);
        while ($st = proc_get_status($result)) {
            if (!$st['running']) {
                break;
            }
            usleep(50000);
        }
        @fclose($pipes[1]);
        @fclose($pipes[2]);
        @proc_close($result);

        $this->assertSame(0, $st['exitcode'], 'single-pass ffmpeg must succeed');
        $this->assertSame('jpg', detectImageFormat($jpg), 'jpg output must be a real jpg');
        $this->assertSame('webp', detectImageFormat($webp), 'webp output must be a real webp');
    }

    public function testVariantCpuTicks_LiveProcess_ReturnsTicks(): void
    {
        $ticks = variantCpuTicks(getmypid());
        $this->assertTrue(is_int($ticks) || $ticks === null, 'ticks must be int on Linux, null elsewhere');
    }

    public function testVariantCpuTicks_UnknownPid_ReturnsNull(): void
    {
        $this->assertNull(variantCpuTicks(2147483647));
    }

    /**
     * A leftover staging file under the old fixed name must never be promoted
     * by a later run, whose staging names carry a per-run marker.
     */
    public function testGenerateVariantsFromOriginal_StaleStagingFileIsNotPromoted(): void
    {
        $ffmpeg = trim(shell_exec('which ffmpeg 2>/dev/null') ?: '');
        if (empty($ffmpeg)) {
            $this->markTestSkipped('ffmpeg not available');
        }

        require_once __DIR__ . '/../../lib/exif-utils.php';

        $captureTime = strtotime('2026-11-19 12:00:00 UTC');
        $testAirport = 'test_stale_' . time();
        ensureCacheDir(CACHE_WEBCAMS_DIR);

        $source = $this->testImageDir . '/source.jpg';
        $img = imagecreatetruecolor(640, 480);
        imagefill($img, 0, 0, imagecolorallocate($img, 70, 90, 130));
        imagejpeg($img, $source, 90);
        imagedestroy($img);
        $this->assertTrue(
            ensureImageHasExif($source, $captureTime, 'UTC', [
                'airport_id' => $testAirport,
                'cam_index' => 0,
                'source_type' => 'test'
            ])
        );

        // Seed a stale file at the old fixed staging name; it belongs to a
        // stopped worker and must survive untouched, never get promoted.
        $cameraDir = getWebcamCameraDir($testAirport, 0);
        ensureCacheDir($cameraDir);
        $stale = $cameraDir . '/staging_360_jpg.tmp';
        file_put_contents($stale, 'stale bytes from a stopped worker');
        $staleMtime = time() - 60;
        @touch($stale, $staleMtime);

        $result = generateVariantsFromOriginal($source, $testAirport, 0, $captureTime);

        $this->assertNotEmpty($result['variants'], 'a fresh variant is produced');
        foreach ($result['variants'] as $height => $formats) {
            foreach ($formats as $path) {
                $this->assertFileExists($path);
                $this->assertNotSame($stale, $path, 'the stale staging file must not become a variant');
            }
        }
        $this->assertSame('stale bytes from a stopped worker', file_get_contents($stale), 'stale file left untouched');
        $this->assertSame($staleMtime, @filemtime($stale), 'stale file mtime unchanged');

        foreach (glob($cameraDir . '/*') ?: [] as $f) {
            @unlink($f);
        }
        @rmdir($cameraDir);
        $framesDir = getWebcamFramesDir($testAirport, 0, $captureTime);
        foreach (glob($framesDir . '/*') ?: [] as $f) {
            @unlink($f);
        }
        @rmdir($framesDir);
    }

    /**
     * A truncated jpg (start-of-scan with no end-of-image) is rejected as
     * incomplete, so the promotion gate cannot serve a torn frame.
     */
    public function testIsImageCompleteRejectsTruncatedJpeg(): void
    {
        require_once __DIR__ . '/../../lib/webcam-history.php';
        $file = $this->testImageDir . '/truncated.jpg';
        // SOI + APP0 + SOF + SOS, but no EOI.
        $jpeg = "\xFF\xD8\xFF\xE0\x00\x10JFIF\x00\x01\x01\x01\x00H\x00H\x00\x00"
            . "\xFF\xC0\x00\x0B\x08\x00\x01\x00\x01\x01\x01\x11\x00"
            . "\xFF\xDA\x00\x08\x01\x01\x00\x00\x3F\x00";
        file_put_contents($file, $jpeg);
        $this->assertFalse(isImageComplete($file, 'jpg'), 'a torn jpg must be incomplete');
    }

    /**
     * A png source keeps its native original; no png variant is emitted, and
     * the jpg variant is real jpg, never jpeg bytes carrying a png name.
     */
    public function testGenerateVariantsFromOriginal_PngSource_EmitsNoPngVariant(): void
    {
        $ffmpeg = trim(shell_exec('which ffmpeg 2>/dev/null') ?: '');
        if (empty($ffmpeg)) {
            $this->markTestSkipped('ffmpeg not available');
        }

        require_once __DIR__ . '/../../lib/exif-utils.php';

        $captureTime = strtotime('2026-11-19 12:00:00 UTC');
        $testAirport = 'test_pngvar_' . time();
        ensureCacheDir(CACHE_WEBCAMS_DIR);

        $source = $this->testImageDir . '/source.png';
        $img = imagecreatetruecolor(640, 480);
        imagefill($img, 0, 0, imagecolorallocate($img, 100, 60, 40));
        imagepng($img, $source);
        imagedestroy($img);

        $this->assertTrue(
            ensureImageHasExif($source, $captureTime, 'UTC', [
                'airport_id' => $testAirport,
                'cam_index' => 0,
                'source_type' => 'test'
            ]),
            'png source must carry EXIF before variant generation'
        );

        $result = generateVariantsFromOriginal($source, $testAirport, 0, $captureTime);

        $this->assertNotNull($result['original']);
        $this->assertSame('png', detectImageFormat($result['original']), 'original stays png');
        foreach ($result['variants'] as $height => $formats) {
            foreach ($formats as $format => $path) {
                $this->assertNotSame('png', $format, 'png must not be emitted as a served variant');
                $this->assertTrue(hasExifTimestamp($path));
                $this->assertSame('jpg', detectImageFormat($path), 'variant must be real jpg');
            }
        }

        $cameraDir = getWebcamCameraDir($testAirport, 0);
        foreach (glob($cameraDir . '/*') ?: [] as $f) {
            @unlink($f);
        }
        @rmdir($cameraDir);
        $framesDir = getWebcamFramesDir($testAirport, 0, $captureTime);
        foreach (glob($framesDir . '/*') ?: [] as $f) {
            @unlink($f);
        }
        @rmdir($framesDir);
    }
}

