<?php

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../lib/exif/read.php';
require_once __DIR__ . '/../../lib/exif/write.php';

/**
 * Unit tests for the native EXIF copy.
 *
 * Copies are applied to copies of the committed fixtures so the originals are
 * never mutated by the suite.
 */
class ExifNativeCopyTest extends TestCase
{
    private string $fixtureDir;
    private string $tmpDir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->fixtureDir = __DIR__ . '/../Fixtures/webcam-exif';
        $this->tmpDir = sys_get_temp_dir() . '/exifnative-copy-' . uniqid('', true);
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

    public function testCopy_SameFormat_CarriesTags(): void
    {
        $dest = $this->tmpDir . '/out.jpg';
        copy($this->fixtureDir . '/no_exif.jpg', $dest);

        $this->assertTrue(exifNativeCopy($this->fixtureDir . '/push_full.jpg', $dest));

        $result = exifNativeRead($dest);
        $this->assertSame('ok', $result['status']);
        $this->assertSame('2024:12:26 15:30:45', $result['DateTimeOriginal'] ?? null);
        $this->assertSame('2024:12:26', $result['GpsDateStamp'] ?? null);
        $this->assertSame('© AviationWX.org and image contributors', $result['Copyright'] ?? null);
        $this->assertSame('Processed by AviationWX.org', $result['UserComment'] ?? null);
    }

    public function testCopy_CrossFormat_JpegToWebp(): void
    {
        $dest = $this->tmpDir . '/out.webp';
        copy($this->fixtureDir . '/webp_no_exif.webp', $dest);

        $this->assertTrue(exifNativeCopy($this->fixtureDir . '/push_full.jpg', $dest));

        $result = exifNativeRead($dest);
        $this->assertSame('ok', $result['status']);
        $this->assertSame('2024:12:26 15:30:45', $result['DateTimeOriginal'] ?? null);
        $this->assertSame(
            strtotime('2024-12-26 23:30:45 UTC'),
            exifNativeGpsUtcTimestamp($result['GpsDateStamp'], $result['GpsTimeComponents'])
        );
    }

    public function testCopy_CrossFormat_WebpToPng(): void
    {
        $dest = $this->tmpDir . '/out.png';
        copy($this->fixtureDir . '/png_no_exif.png', $dest);

        $this->assertTrue(exifNativeCopy($this->fixtureDir . '/webp_exif.webp', $dest));

        $result = exifNativeRead($dest);
        $this->assertSame('ok', $result['status']);
        $this->assertSame('2020:07:08 09:10:11', $result['DateTimeOriginal'] ?? null);
        $this->assertSame('2020:07:08', $result['GpsDateStamp'] ?? null);
    }

    public function testCopy_CarriesMakeModelOrientation(): void
    {
        $dest = $this->tmpDir . '/geom.jpg';
        copy($this->fixtureDir . '/no_exif.jpg', $dest);

        $this->assertTrue(exifNativeCopy($this->fixtureDir . '/source_with_geom.jpg', $dest));

        $result = exifNativeRead($dest);
        $this->assertSame('PineCam', $result['Make'] ?? null);
        $this->assertSame('PC-9000', $result['Model'] ?? null);
        $this->assertSame(6, $result['Orientation'] ?? null);
    }

    public function testCopyTiff_OneExtraction_StampsManyDestinations(): void
    {
        $tiff = exifNativeExtractTiffBytes($this->fixtureDir . '/push_full.jpg');
        $this->assertNotEmpty($tiff);

        $jpg = $this->tmpDir . '/a.jpg';
        $webp = $this->tmpDir . '/b.webp';
        copy($this->fixtureDir . '/no_exif.jpg', $jpg);
        copy($this->fixtureDir . '/webp_no_exif.webp', $webp);

        $this->assertTrue(exifNativeCopyTiff($tiff, $jpg));
        $this->assertTrue(exifNativeCopyTiff($tiff, $webp));

        foreach ([$jpg, $webp] as $path) {
            $result = exifNativeRead($path);
            $this->assertSame('ok', $result['status']);
            $this->assertSame('2024:12:26 15:30:45', $result['DateTimeOriginal'] ?? null);
        }
    }

    public function testCopyTiff_EmptyTiff_ReturnsFalseAndLeavesDestUntouched(): void
    {
        $dest = $this->tmpDir . '/out.jpg';
        copy($this->fixtureDir . '/no_exif.jpg', $dest);

        $this->assertFalse(exifNativeCopyTiff('', $dest));

        $result = exifNativeRead($dest);
        $this->assertNotSame('ok', $result['status'] ?? null);
    }

    public function testCopy_SourceWithoutExif_ReturnsFalseAndLeavesDestUntouched(): void
    {
        $dest = $this->tmpDir . '/out.jpg';
        copy($this->fixtureDir . '/no_exif.jpg', $dest);
        $before = file_get_contents($dest);

        $this->assertFalse(exifNativeCopy($this->fixtureDir . '/no_exif.jpg', $dest));
        $this->assertSame($before, file_get_contents($dest), 'destination must be untouched when source has no EXIF');
    }

    public function testCopy_UnsupportedDest_FailsClosed(): void
    {
        $dest = $this->tmpDir . '/out.txt';
        file_put_contents($dest, 'plain text');
        $before = file_get_contents($dest);

        $this->assertFalse(exifNativeCopy($this->fixtureDir . '/push_full.jpg', $dest));
        $this->assertSame($before, file_get_contents($dest), 'unsupported destination must not be touched');
    }

    public function testCopy_PreservesDestMtime(): void
    {
        $dest = $this->tmpDir . '/out.jpg';
        copy($this->fixtureDir . '/no_exif.jpg', $dest);
        $mtimeBefore = filemtime($dest);

        $this->assertTrue(exifNativeCopy($this->fixtureDir . '/push_full.jpg', $dest));
        $this->assertSame($mtimeBefore, filemtime($dest), 'copy must preserve destination mtime');
    }
}
