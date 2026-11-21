<?php

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../lib/exif/read.php';
require_once __DIR__ . '/../../lib/exif/write.php';

/**
 * Unit tests for the native EXIF stamp writer.
 *
 * Stamps are applied to copies of the committed fixtures so the originals are
 * never mutated by the suite.
 */
class ExifNativeStampTest extends TestCase
{
    private string $fixtureDir;
    private string $tmpDir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->fixtureDir = __DIR__ . '/../Fixtures/webcam-exif';
        $this->tmpDir = sys_get_temp_dir() . '/exifnative-' . uniqid('', true);
        mkdir($this->tmpDir, 0777, true);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->tmpDir . '/*') ?: [] as $f) {
            @unlink($f);
        }
        @rmdir($this->tmpDir);
        parent::tearDown();
    }

    public function testStamp_WritesAndVerifiesOnFreshImage(): void
    {
        foreach (['no_exif.jpg', 'png_no_exif.png', 'webp_no_exif.webp'] as $fixture) {
            $src = $this->fixtureDir . '/' . $fixture;
            $dst = $this->tmpDir . '/stamped.' . pathinfo($fixture, PATHINFO_EXTENSION);
            copy($src, $dst);
            $mtimeBefore = filemtime($dst);
            $ts = strtotime('2025-03-04 05:06:07 UTC');

            $this->assertTrue(exifNativeStamp($dst, $ts, 'UTC'), "stamp {$fixture}");

            $result = exifNativeRead($dst);
            $this->assertSame('ok', $result['status'], "status {$fixture}");
            $this->assertSame('2025:03:04 05:06:07', $result['DateTimeOriginal'] ?? null, "dt {$fixture}");
            $this->assertSame('+00:00', $result['OffsetTimeOriginal'] ?? null, "offset {$fixture}");
            $this->assertSame('2025:03:04', $result['GpsDateStamp'] ?? null, "gps date {$fixture}");
            $this->assertSame([5, 6, 7], array_values(array_map('intval', $result['GpsTimeComponents'] ?? [])), "gps time {$fixture}");
            $this->assertSame(
                strtotime('2025-03-04 05:06:07 UTC'),
                exifNativeGpsUtcTimestamp($result['GpsDateStamp'], $result['GpsTimeComponents']),
                "gps utc {$fixture}"
            );
            $this->assertSame('Aviation weather webcam image processed by AviationWX.org', $result['ImageDescription'] ?? null, "desc {$fixture}");
            $this->assertSame('AviationWX.org', $result['Artist'] ?? null, "artist {$fixture}");
            $this->assertSame('© AviationWX.org and image contributors', $result['Copyright'] ?? null, "copyright {$fixture}");
            $this->assertSame('Processed by AviationWX.org', $result['UserComment'] ?? null, "usercomment {$fixture}");
            $this->assertSame($mtimeBefore, filemtime($dst), "mtime {$fixture}");
        }
    }

    public function testStamp_ReplacesExistingExif(): void
    {
        $dst = $this->tmpDir . '/replaced.jpg';
        copy($this->fixtureDir . '/push_full.jpg', $dst);
        $ts = strtotime('2026-06-07 08:09:10 UTC');

        $this->assertTrue(exifNativeStamp($dst, $ts, 'UTC'));
        $result = exifNativeRead($dst);
        $this->assertSame('2026:06:07 08:09:10', $result['DateTimeOriginal'] ?? null);
        $this->assertSame('2026:06:07', $result['GpsDateStamp'] ?? null);
    }

    public function testStamp_PreservesMakeModelOrientation(): void
    {
        $dst = $this->tmpDir . '/geometry.jpg';
        copy($this->fixtureDir . '/source_with_geom.jpg', $dst);
        $ts = strtotime('2026-02-02 03:04:05 UTC');

        $this->assertTrue(exifNativeStamp($dst, $ts, 'UTC'));
        $result = exifNativeRead($dst);
        $this->assertSame('2026:02:02 03:04:05', $result['DateTimeOriginal'] ?? null, 'stamp must update capture time');
        $this->assertSame('PineCam', $result['Make'] ?? null, 'Make must survive a stamp');
        $this->assertSame('PC-9000', $result['Model'] ?? null, 'Model must survive a stamp');
        $this->assertSame(6, $result['Orientation'] ?? null, 'Orientation must survive a stamp');
    }

    public function testStamp_OnFreshImage_AddsNoMakeModelOrientation(): void
    {
        $dst = $this->tmpDir . '/fresh.jpg';
        copy($this->fixtureDir . '/no_exif.jpg', $dst);

        $this->assertTrue(exifNativeStamp($dst, strtotime('2025-03-04 05:06:07 UTC'), 'UTC'));
        $result = exifNativeRead($dst);
        $this->assertArrayNotHasKey('Make', $result);
        $this->assertArrayNotHasKey('Model', $result);
        $this->assertArrayNotHasKey('Orientation', $result);
    }

    public function testStamp_MissingFile_ReturnsFalse(): void
    {
        $this->assertFalse(exifNativeStamp($this->tmpDir . '/missing.jpg', 1700000000, 'UTC'));
    }

    public function testStamp_UnsupportedFormat_FailsClosed(): void
    {
        $txt = $this->tmpDir . '/not-an-image.txt';
        file_put_contents($txt, 'plain text');
        $before = file_get_contents($txt);
        $this->assertFalse(exifNativeStamp($txt, 1700000000, 'UTC'));
        $this->assertSame($before, file_get_contents($txt), 'unsupported input must not be touched');
    }

    public function testStamp_NullTimestamp_UsesMtime(): void
    {
        $dst = $this->tmpDir . '/mtime.jpg';
        copy($this->fixtureDir . '/no_exif.jpg', $dst);
        $mtime = filemtime($dst);

        $this->assertTrue(exifNativeStamp($dst, null, 'UTC'));
        $result = exifNativeRead($dst);
        $this->assertNotNull($result['DateTimeOriginal'] ?? null);
        $this->assertSame($mtime, filemtime($dst));
    }
}
