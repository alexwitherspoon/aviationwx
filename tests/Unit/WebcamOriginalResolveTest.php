<?php
/**
 * Native original resolve and Public API fmt parsing.
 */

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/../../lib/webcam-metadata.php';
require_once __DIR__ . '/../../api/v1/webcam-image.php';
require_once __DIR__ . '/../../api/v1/webcams.php';
require_once __DIR__ . '/../../lib/webcam-history.php';
require_once __DIR__ . '/WebcamUnsupportedPayloads.php';

class WebcamOriginalResolveTest extends TestCase
{
    use WebcamUnsupportedPayloads;
    private string $airportId = 'origfmt_unit';
    private int $camIndex = 0;

    protected function tearDown(): void
    {
        $base = CACHE_WEBCAMS_DIR . '/' . strtolower($this->airportId);
        if (is_dir($base)) {
            $this->deleteTreeRecursive($base);
        }
        parent::tearDown();
    }

    private function deleteTreeRecursive(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        $items = scandir($dir);
        if ($items === false) {
            return;
        }
        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            $path = $dir . '/' . $item;
            if (is_dir($path)) {
                $this->deleteTreeRecursive($path);
            } else {
                // @: leftover files must not fail tearDown
                @unlink($path);
            }
        }
        @rmdir($dir);
    }

    private function jpegBytes(): string
    {
        return "\xFF\xD8\xFF\xD9" . str_repeat("\x00", 8);
    }

    private function pngBytes(): string
    {
        return "\x89\x50\x4E\x47\x0D\x0A\x1A\x0A" . str_repeat("\x00", 8);
    }

    private function webpBytes(): string
    {
        return 'RIFF' . pack('V', 12) . 'WEBP';
    }

    private function writeOriginal(int $timestamp, string $ext, string $bytes): string
    {
        $framesDir = getWebcamFramesDir($this->airportId, $this->camIndex, $timestamp);
        ensureCacheDir($framesDir);
        $path = getWebcamOriginalTimestampedPath($this->airportId, $this->camIndex, $timestamp, $ext);
        file_put_contents($path, $bytes);
        return $path;
    }

    private function linkOriginal(int $timestamp, string $format, string $targetPath): void
    {
        $camDir = getWebcamCameraDir($this->airportId, $this->camIndex);
        ensureCacheDir($camDir);
        $symlink = getWebcamOriginalSymlinkPath($this->airportId, $this->camIndex, $format);
        if (is_link($symlink) || file_exists($symlink)) {
            unlink($symlink);
        }
        $relative = getWebcamFramesSubdir($timestamp) . '/' . basename($targetPath);
        symlink($relative, $symlink);
    }

    public function testGetSupportedWebcamSourceFormats_ReturnsJpgPngWebp(): void
    {
        $this->assertSame(['jpg', 'png', 'webp'], getSupportedWebcamSourceFormats());
    }

    public function testNormalizeWebcamFormatName_JpegAlias_ReturnsJpg(): void
    {
        $this->assertSame('jpg', normalizeWebcamFormatName('JPEG'));
    }

    public function testNormalizeWebcamFormatName_Unknown_ReturnsNull(): void
    {
        $this->assertNull(normalizeWebcamFormatName('gif'));
        $this->assertNull(normalizeWebcamFormatName('bmp'));
    }

    public function testDetectServableWebcamImageFormat_UnknownBytes_ReturnsNull(): void
    {
        $path = $this->writeOriginal(1704067000, 'jpg', str_repeat('notanimage!', 2));
        $this->assertNull(detectServableWebcamImageFormat($path));
    }

    public function testParsePublicApiWebcamFmtQuery_Omitted_IsNativeOriginal(): void
    {
        $parsed = parsePublicApiWebcamFmtQuery(null);
        $this->assertTrue($parsed['ok']);
        $this->assertFalse($parsed['explicit']);
        $this->assertNull($parsed['format']);
    }

    public function testParsePublicApiWebcamFmtQuery_EmptyString_IsNativeOriginal(): void
    {
        $parsed = parsePublicApiWebcamFmtQuery('  ');
        $this->assertTrue($parsed['ok']);
        $this->assertFalse($parsed['explicit']);
        $this->assertNull($parsed['format']);
    }

    public function testParsePublicApiWebcamFmtQueryFromGet_ArrayValue_ReturnsShapeErrorWithoutWarning(): void
    {
        $previousGet = $_GET;
        $_GET['fmt'] = ['webp'];
        set_error_handler(
            static function (int $severity, string $message, string $file, int $line): never {
                throw new ErrorException($message, 0, $severity, $file, $line);
            }
        );

        try {
            $parsed = parsePublicApiWebcamFmtQueryFromGet();
        } finally {
            restore_error_handler();
            $_GET = $previousGet;
        }

        $this->assertFalse($parsed['ok']);
        $this->assertTrue($parsed['explicit']);
        $this->assertSame('fmt must be a single value', $parsed['error']);
    }

    public function testParsePublicApiWebcamFmtQuery_Unknown_Errors(): void
    {
        $parsed = parsePublicApiWebcamFmtQuery('gif');
        $this->assertFalse($parsed['ok']);
        $this->assertSame('Unknown image format', $parsed['error']);
    }

    public function testParsePublicApiWebcamFmtQuery_JpegAlias_IsJpg(): void
    {
        $parsed = parsePublicApiWebcamFmtQuery('jpeg');
        $this->assertTrue($parsed['ok']);
        $this->assertSame('jpg', $parsed['format']);
    }

    public function testParsePublicApiWebcamFmtQuery_Jpg_IsEnabled(): void
    {
        $parsed = parsePublicApiWebcamFmtQuery('jpg');
        $this->assertTrue($parsed['ok']);
        $this->assertTrue($parsed['explicit']);
        $this->assertSame('jpg', $parsed['format']);
    }

    public function testParsePublicApiWebcamFmtQuery_WebpWhenDisabled_Errors(): void
    {
        $this->assertFalse(in_array('webp', getEnabledWebcamFormats(), true));
        $parsed = parsePublicApiWebcamFmtQuery('webp');
        $this->assertFalse($parsed['ok']);
        $this->assertSame("Format 'webp' is not enabled", $parsed['error']);
    }

    public function testParsePublicApiWebcamFmtQuery_PngWhenNotGenerationFormat_Errors(): void
    {
        $this->assertFalse(in_array('png', getEnabledWebcamFormats(), true));
        $parsed = parsePublicApiWebcamFmtQuery('png');
        $this->assertFalse($parsed['ok']);
        $this->assertSame("Format 'png' is not enabled", $parsed['error']);
    }

    public function testPublicApiWebcamContentType_Unsupported_Throws(): void
    {
        $this->expectException(InvalidArgumentException::class);
        publicApiWebcamContentType('gif');
    }

    public function testWebcamReadServableImage_JpegFile_ReturnsJpgAndBytes(): void
    {
        $bytes = $this->jpegBytes();
        $path = $this->writeOriginal(1704067050, 'jpg', $bytes);
        $read = webcamReadServableImage($path);
        $this->assertNotNull($read);
        $this->assertSame('jpg', $read['format']);
        $this->assertSame('image/jpeg', $read['content_type']);
        $this->assertSame($bytes, $read['bytes']);
    }

    public function testOpenServableWebcamImage_PathReplaced_KeepsOriginalDescriptor(): void
    {
        $jpeg = $this->jpegBytes();
        $path = $this->writeOriginal(1704067055, 'jpg', $jpeg);
        $opened = openServableWebcamImage($path);
        $this->assertNotNull($opened);

        try {
            unlink($path);
            file_put_contents($path, $this->pngBytes());

            $this->assertSame('jpg', $opened['format']);
            $this->assertSame(strlen($jpeg), $opened['size']);
            $this->assertSame($jpeg, stream_get_contents($opened['handle']));
        } finally {
            fclose($opened['handle']);
        }
    }

    public function testWebcamServableImageFileMeta_JpegFile_ReturnsHeaderTypeAndSize(): void
    {
        $bytes = $this->jpegBytes();
        $path = $this->writeOriginal(1704067060, 'jpg', $bytes);
        $meta = webcamServableImageFileMeta($path);
        $this->assertNotNull($meta);
        $this->assertSame('jpg', $meta['format']);
        $this->assertSame('image/jpeg', $meta['content_type']);
        $this->assertSame(strlen($bytes), $meta['size']);
    }

    public function testWebcamSourceFormatFileExtensions_Unknown_ReturnsEmpty(): void
    {
        $this->assertSame([], webcamSourceFormatFileExtensions('exe'));
        $this->assertSame([], webcamSourceFormatFileExtensions('pdf'));
    }

    public function testPublicApiWebcamContentType_KnownFormats_ReturnsImageMime(): void
    {
        $this->assertSame('image/png', publicApiWebcamContentType('png'));
        $this->assertSame('image/jpeg', publicApiWebcamContentType('jpg'));
        $this->assertSame('image/webp', publicApiWebcamContentType('webp'));
    }

    public function testPublicApiWebcamVariantUrl_OriginalPng_OmitsFmt(): void
    {
        $this->assertSame(
            '/v1/airports/kspb/webcams/0/image',
            publicApiWebcamVariantUrl('kspb', 0, 'original', 'png')
        );
        $this->assertSame(
            '/v1/airports/kspb/webcams/0/image',
            publicApiWebcamVariantUrl('kspb', 0, 'original', 'jpg')
        );
    }

    public function testPublicApiWebcamVariantUrl_SizedWebp_IncludesFmtAndSize(): void
    {
        $this->assertSame(
            '/v1/airports/kspb/webcams/0/image?fmt=webp&size=1080',
            publicApiWebcamVariantUrl('kspb', 0, '1080', 'webp')
        );
        $this->assertSame(
            '/v1/airports/kspb/webcams/0/image?size=360',
            publicApiWebcamVariantUrl('kspb', 0, '360', 'jpg')
        );
    }

    public function testGetHistoryFrames_PngOriginal_IncludesPngFormat(): void
    {
        $ts = time() - 30;
        $path = $this->writeOriginal($ts, 'png', $this->pngBytes());
        $frames = getHistoryFrames($this->airportId, $this->camIndex);
        $match = null;
        foreach ($frames as $frame) {
            if ((int) $frame['timestamp'] === $ts) {
                $match = $frame;
                break;
            }
        }
        $this->assertNotNull($match);
        $this->assertContains('png', $match['formats']);
        $this->assertGreaterThan(0, getHistoryDiskUsage($this->airportId, $this->camIndex));
        $this->assertFileExists($path);
    }

    public function testFindHistoricalWebcamSizeFile_ExplicitWebpMissing_DoesNotFallBackToJpeg(): void
    {
        $ts = 1704068000;
        $framesDir = getWebcamFramesDir($this->airportId, $this->camIndex, $ts);
        ensureCacheDir($framesDir);
        $jpg = getWebcamVariantPath($this->airportId, $this->camIndex, $ts, 720, 'jpg');
        file_put_contents($jpg, $this->jpegBytes());

        $found = findHistoricalWebcamSizeFile($this->airportId, $this->camIndex, $ts, 720, 'webp');
        $this->assertNull($found['path']);
        $this->assertSame(720, $found['size']);
    }

    public function testExplicitFmtMatchesFile_JpegBytesInWebpCacheFile_IsRejected(): void
    {
        $ts = 1704068020;
        $framesDir = getWebcamFramesDir($this->airportId, $this->camIndex, $ts);
        ensureCacheDir($framesDir);
        $path = getWebcamVariantPath($this->airportId, $this->camIndex, $ts, 720, 'webp');
        file_put_contents($path, $this->jpegBytes());

        $found = findHistoricalWebcamSizeFile($this->airportId, $this->camIndex, $ts, 720, 'webp');
        $this->assertSame($path, $found['path']);
        $meta = webcamServableImageFileMeta($found['path']);
        $this->assertNotNull($meta);
        $this->assertSame('jpg', $meta['format']);
        $this->assertFalse(webcamExplicitFmtMatchesFile(true, 'webp', $meta['format']));
        $this->assertTrue(webcamExplicitFmtMatchesFile(false, 'webp', $meta['format']));
    }

    public function testFindHistoricalWebcamSizeFile_MissingHeight_DoesNotUseSameFormatOriginal(): void
    {
        $ts = 1704068010;
        $this->writeOriginal($ts, 'webp', $this->webpBytes());

        $found = findHistoricalWebcamSizeFile($this->airportId, $this->camIndex, $ts, 720, 'webp');
        $this->assertNull($found['path']);
        $this->assertSame(720, $found['size']);
    }

    public function testFindHistoricalWebcamSizeFile_MissingHeight_DoesNotUseJpegExtensionOriginal(): void
    {
        $ts = 1704068110;
        $this->writeOriginal($ts, 'jpeg', $this->jpegBytes());

        $found = findHistoricalWebcamSizeFile($this->airportId, $this->camIndex, $ts, 720, 'jpg');
        $this->assertNull($found['path']);
        $this->assertSame(720, $found['size']);
    }

    public function testFindHistoricalWebcamSizeFile_MissingTimestamp_DoesNotServeStagingVariant(): void
    {
        $ts = 1704068070;
        ensureCacheDir(getWebcamCameraDir($this->airportId, $this->camIndex));
        $staging = getStagingPathForVariant($this->airportId, $this->camIndex, 720, 'webp');
        file_put_contents($staging, $this->webpBytes());

        $found = findHistoricalWebcamSizeFile($this->airportId, $this->camIndex, $ts, 720, 'webp');
        $this->assertNull($found['path']);
        $this->assertSame(720, $found['size']);
    }

    public function testFindHistoricalWebcamSizeFile_MissingTimestamp_DoesNotServeStagingOriginal(): void
    {
        $ts = 1704068090;
        ensureCacheDir(getWebcamCameraDir($this->airportId, $this->camIndex));
        $staging = getStagingPathForVariant($this->airportId, $this->camIndex, 'original', 'webp');
        file_put_contents($staging, $this->webpBytes());

        $found = findHistoricalWebcamSizeFile($this->airportId, $this->camIndex, $ts, 720, 'webp');
        $this->assertNull($found['path']);
        $this->assertSame(720, $found['size']);
    }

    public function testGetHistoryFrames_JpegExtension_ReportsJpg(): void
    {
        $ts = time() - 20;
        $this->writeOriginal($ts, 'jpeg', $this->jpegBytes());
        $frames = getHistoryFrames($this->airportId, $this->camIndex);
        $match = null;
        foreach ($frames as $frame) {
            if ((int) $frame['timestamp'] === $ts) {
                $match = $frame;
                break;
            }
        }
        $this->assertNotNull($match);
        $this->assertContains('jpg', $match['formats']);
        $this->assertNotContains('jpeg', $match['formats']);
    }

    public function testGetWebcamOriginalPath_NewerPngSymlink_WinsOverOlderJpeg(): void
    {
        $oldTs = 1704067000;
        $newTs = 1704067600;
        $jpgPath = $this->writeOriginal($oldTs, 'jpg', $this->jpegBytes());
        $pngPath = $this->writeOriginal($newTs, 'png', $this->pngBytes());
        $this->linkOriginal($oldTs, 'jpg', $jpgPath);
        $this->linkOriginal($newTs, 'png', $pngPath);

        $this->assertSame($pngPath, getWebcamOriginalPath($this->airportId, $this->camIndex));
    }

    public function testGetWebcamOriginalPath_UnservableJpegSymlink_SkippedForPng(): void
    {
        $oldTs = 1704067010;
        $newTs = 1704067610;
        $jpgPath = $this->writeOriginal($newTs, 'jpg', str_repeat('notanimage!', 2));
        $pngPath = $this->writeOriginal($oldTs, 'png', $this->pngBytes());
        $this->linkOriginal($newTs, 'jpg', $jpgPath);
        $this->linkOriginal($oldTs, 'png', $pngPath);

        $this->assertSame($pngPath, getWebcamOriginalPath($this->airportId, $this->camIndex));
    }

    public function testGetWebcamOriginalPath_DirectoryScanNewerPng_WinsWithoutSymlinks(): void
    {
        $oldTs = 1704067020;
        $newTs = 1704067620;
        $this->writeOriginal($oldTs, 'jpg', $this->jpegBytes());
        $pngPath = $this->writeOriginal($newTs, 'png', $this->pngBytes());

        $this->assertSame($pngPath, getWebcamOriginalPath($this->airportId, $this->camIndex));
    }

    public function testGetWebcamOriginalPath_DirectoryScanCorruptNewest_Skipped(): void
    {
        $oldTs = 1704067030;
        $newTs = 1704067630;
        $pngPath = $this->writeOriginal($oldTs, 'png', $this->pngBytes());
        $this->writeOriginal($newTs, 'jpg', str_repeat('notanimage!', 2));

        $this->assertSame($pngPath, getWebcamOriginalPath($this->airportId, $this->camIndex));
    }

    public function testGetCurrentServableWebcamOriginal_NewerVariantOnlyFrame_SelectsOriginalTimestamp(): void
    {
        $originalTs = 1704067035;
        $variantTs = 1704067635;
        $originalPath = $this->writeOriginal($originalTs, 'png', $this->pngBytes());
        $variantPath = getWebcamVariantPath($this->airportId, $this->camIndex, $variantTs, 720, 'jpg');
        ensureCacheDir(dirname($variantPath));
        file_put_contents($variantPath, $this->jpegBytes());

        $this->assertSame($variantTs, getLatestImageTimestamp($this->airportId, $this->camIndex));
        $current = getCurrentServableWebcamOriginal($this->airportId, $this->camIndex);
        $this->assertNotNull($current);
        $this->assertSame($originalPath, $current['path']);
        $this->assertSame('png', $current['format']);
        $this->assertSame($originalTs, $current['timestamp']);
    }

    public function testWebcamServableOriginalCaptureRank_UnsupportedBytes_ReturnsNull(): void
    {
        $path = $this->writeOriginal(1704067040, 'jpg', str_repeat('notanimage!', 2));
        $this->assertNull(webcamServableOriginalCaptureRank($path));
    }

    public function testCleanupOldTimestampFiles_ExpiredPngOriginal_IsRemoved(): void
    {
        $oldTs = time() - 120;
        $newTs = time() - 30;
        $oldPath = $this->writeOriginal($oldTs, 'png', $this->pngBytes());
        $newPath = $this->writeOriginal($newTs, 'png', $this->pngBytes());

        $cleaned = cleanupOldTimestampFiles($this->airportId, $this->camIndex, 1);

        $this->assertGreaterThan(0, $cleaned);
        $this->assertFileDoesNotExist($oldPath);
        $this->assertFileExists($newPath);
    }

    public function testCleanupOldTimestampFiles_OriginalSymlinkTarget_IsPreserved(): void
    {
        $liveTs = time() - 90000;
        $livePath = $this->writeOriginal($liveTs, 'png', $this->pngBytes());
        $otherPath = getWebcamVariantPath($this->airportId, $this->camIndex, $liveTs, 720, 'jpg');
        file_put_contents($otherPath, $this->jpegBytes());
        $this->linkOriginal($liveTs, 'png', $livePath);

        $cleaned = cleanupOldTimestampFiles($this->airportId, $this->camIndex, 1);

        $this->assertGreaterThan(0, $cleaned);
        $this->assertFileExists($livePath);
        $this->assertFileDoesNotExist($otherPath);
        $this->assertSame(
            realpath($livePath),
            realpath(getWebcamOriginalSymlinkPath($this->airportId, $this->camIndex, 'png'))
        );
    }

    public function testResolveWebcamOriginalAtTimestamp_PngFile_ReturnsPng(): void
    {
        $ts = 1704067200;
        $this->writeOriginal($ts, 'png', $this->pngBytes());

        $this->assertSame($ts, getLatestImageTimestamp($this->airportId, $this->camIndex));
        $resolved = resolveWebcamOriginalAtTimestamp($this->airportId, $this->camIndex, $ts);
        $this->assertTrue($resolved['ok']);
        $this->assertSame('png', $resolved['format']);
    }

    public function testResolveWebcamOriginalAtTimestamp_JpegExtension_ReturnsJpg(): void
    {
        $ts = 1704067250;
        $this->writeOriginal($ts, 'jpeg', $this->jpegBytes());

        $resolved = resolveWebcamOriginalAtTimestamp($this->airportId, $this->camIndex, $ts);
        $this->assertTrue($resolved['ok']);
        $this->assertSame('jpg', $resolved['format']);
    }

    public function testResolveWebcamOriginalAtTimestamp_JpegFile_ReturnsJpg(): void
    {
        $ts = 1704067300;
        $this->writeOriginal($ts, 'jpg', $this->jpegBytes());

        $resolved = resolveWebcamOriginalAtTimestamp($this->airportId, $this->camIndex, $ts);
        $this->assertTrue($resolved['ok']);
        $this->assertSame('jpg', $resolved['format']);
    }

    public function testResolveWebcamOriginalAtTimestamp_StagingJpeg_DoesNotHideTimestampedPng(): void
    {
        $ts = 1704068050;
        $pngPath = $this->writeOriginal($ts, 'png', $this->pngBytes());
        $staging = getStagingPathForVariant($this->airportId, $this->camIndex, 'original', 'jpg');
        file_put_contents($staging, $this->jpegBytes());

        $resolved = resolveWebcamOriginalAtTimestamp($this->airportId, $this->camIndex, $ts);
        $this->assertTrue($resolved['ok']);
        $this->assertSame('png', $resolved['format']);
        $this->assertSame($pngPath, $resolved['path']);
    }

    public function testResolveWebcamOriginalAtTimestamp_MissingTimestamp_DoesNotServeStaging(): void
    {
        $this->writeOriginal(1704068051, 'png', $this->pngBytes());
        $staging = getStagingPathForVariant($this->airportId, $this->camIndex, 'original', 'jpg');
        file_put_contents($staging, $this->jpegBytes());

        $resolved = resolveWebcamOriginalAtTimestamp($this->airportId, $this->camIndex, 1704068060);
        $this->assertFalse($resolved['ok']);
        $this->assertSame('missing', $resolved['error']);
    }

    public function testResolveWebcamOriginalAtTimestamp_WebpFile_ReturnsWebp(): void
    {
        $ts = 1704067350;
        $this->writeOriginal($ts, 'webp', $this->webpBytes());

        $resolved = resolveWebcamOriginalAtTimestamp($this->airportId, $this->camIndex, $ts);
        $this->assertTrue($resolved['ok']);
        $this->assertSame('webp', $resolved['format']);
    }

    public function testResolveWebcamOriginalAtTimestamp_CorruptJpgWithValidPng_ReturnsPng(): void
    {
        $ts = 1704067380;
        $this->writeOriginal($ts, 'jpg', str_repeat('notanimage!', 2));
        $this->writeOriginal($ts, 'png', $this->pngBytes());

        $resolved = resolveWebcamOriginalAtTimestamp($this->airportId, $this->camIndex, $ts);
        $this->assertTrue($resolved['ok']);
        $this->assertSame('png', $resolved['format']);
    }

    public function testResolveWebcamOriginalAtTimestamp_UnknownBytes_Errors(): void
    {
        $ts = 1704067400;
        $this->writeOriginal($ts, 'jpg', str_repeat('notanimage!', 2));

        $resolved = resolveWebcamOriginalAtTimestamp($this->airportId, $this->camIndex, $ts);
        $this->assertFalse($resolved['ok']);
        $this->assertSame('unknown', $resolved['error']);
    }

    public function testResolveWebcamOriginalAtTimestamp_Missing_Errors(): void
    {
        $resolved = resolveWebcamOriginalAtTimestamp($this->airportId, $this->camIndex, 1704067500);
        $this->assertFalse($resolved['ok']);
        $this->assertSame('missing', $resolved['error']);
    }

    public function testResolveWebcamOriginalAtTimestamp_NonPositiveTimestamp_ReturnsMissing(): void
    {
        $resolved = resolveWebcamOriginalAtTimestamp($this->airportId, $this->camIndex, 0);
        $this->assertFalse($resolved['ok']);
        $this->assertSame('missing', $resolved['error']);
    }

    public function testPublicApiWebcamFmtRejectedOnOriginal_ExplicitFmt_ReturnsTrue(): void
    {
        $this->assertTrue(publicApiWebcamFmtRejectedOnOriginal(true, 'original', null, null));
        $this->assertFalse(publicApiWebcamFmtRejectedOnOriginal(false, 'original', null, null));
        $this->assertFalse(publicApiWebcamFmtRejectedOnOriginal(true, '360', null, null));
        $this->assertFalse(publicApiWebcamFmtRejectedOnOriginal(true, 'original', 1280, null));
    }

    public function testFormatWebcamImageVariants_OriginalRow_OmitsFmt(): void
    {
        $images = formatWebcamImageVariants('kspb', 0, false);
        $this->assertSame('original', $images[0]['variant']);
        $this->assertStringNotContainsString('fmt=', $images[0]['url']);
        if (isset($images[0]['format'])) {
            $this->assertContains($images[0]['format'], getSupportedWebcamSourceFormats());
        }
        $this->assertSame('jpg', $images[1]['format']);
        $this->assertStringContainsString('size=', $images[1]['url']);
    }

    public function testFormatWebcamImageVariants_ServablePngOriginal_IncludesFormatOmitsFmt(): void
    {
        $ts = 1704067700;
        $this->writeOriginal($ts, 'png', $this->pngBytes());
        $images = formatWebcamImageVariants($this->airportId, $this->camIndex, false);
        $this->assertSame('original', $images[0]['variant']);
        $this->assertSame('png', $images[0]['format']);
        $this->assertStringNotContainsString('fmt=', $images[0]['url']);
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('unsupportedWebcamPayloadProvider')]
    public function testFormatWebcamImageVariants_UnsupportedBytesNamedJpg_OmitsFormat(string $label, string $bytes): void
    {
        $ts = 1704067800;
        $this->writeOriginal($ts, 'jpg', $bytes);
        $images = formatWebcamImageVariants($this->airportId, $this->camIndex, false);
        $this->assertSame('original', $images[0]['variant']);
        $this->assertArrayNotHasKey('format', $images[0]);
        $this->assertStringNotContainsString('fmt=', $images[0]['url']);
        $this->assertNotSame('', $label);
    }
}
