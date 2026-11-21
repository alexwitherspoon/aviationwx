<?php

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../lib/exif/read.php';

/**
 * Unit tests for the PNG and WebP EXIF readers and the format dispatcher.
 *
 * Fixture values are fixed by the generator, so assertions are stable across
 * runs.
 */
class ExifNativePngWebpTest extends TestCase
{
    private string $fixtureDir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->fixtureDir = __DIR__ . '/../Fixtures/webcam-exif';
    }

    public function testPngExif_ReadsDateTimeOriginalAndGpsUtc(): void
    {
        $result = exifNativeRead($this->fixtureDir . '/png_exif.png');

        $this->assertSame('ok', $result['status']);
        $this->assertSame('2021:02:03 04:05:06', $result['DateTimeOriginal'] ?? null);
        $this->assertSame('2021:02:03', $result['GpsDateStamp'] ?? null);
        $this->assertSame([12, 5, 6], array_values(array_map('intval', $result['GpsTimeComponents'] ?? [])));
        $this->assertSame(
            strtotime('2021-02-03 12:05:06 UTC'),
            exifNativeGpsUtcTimestamp($result['GpsDateStamp'], $result['GpsTimeComponents'])
        );
    }

    public function testPngNoExif_ReturnsAbsent(): void
    {
        $this->assertSame('absent', exifNativeRead($this->fixtureDir . '/png_no_exif.png')['status']);
    }

    public function testWebpExif_ReadsDateTimeOriginalAndGpsUtc(): void
    {
        $result = exifNativeRead($this->fixtureDir . '/webp_exif.webp');

        $this->assertSame('ok', $result['status']);
        $this->assertSame('2020:07:08 09:10:11', $result['DateTimeOriginal'] ?? null);
        $this->assertSame('2020:07:08', $result['GpsDateStamp'] ?? null);
        $this->assertSame([17, 10, 11], array_values(array_map('intval', $result['GpsTimeComponents'] ?? [])));
        $this->assertSame(
            strtotime('2020-07-08 17:10:11 UTC'),
            exifNativeGpsUtcTimestamp($result['GpsDateStamp'], $result['GpsTimeComponents'])
        );
    }

    public function testWebpNoExif_ReturnsAbsent(): void
    {
        $this->assertSame('absent', exifNativeRead($this->fixtureDir . '/webp_no_exif.webp')['status']);
    }

    public function testDispatcher_DetectsJpegAndMissingFile(): void
    {
        $this->assertSame('ok', exifNativeRead($this->fixtureDir . '/push_full.jpg')['status']);
        $this->assertSame('error', exifNativeRead($this->fixtureDir . '/does_not_exist.png')['status']);
    }
}
