<?php

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../lib/exif/jpeg.php';

/**
 * Unit tests for the self-contained JPEG EXIF reader.
 *
 * Fixtures are committed under tests/Fixtures/webcam-exif/. The values are
 * fixed so the assertions are stable, independent of when they were created.
 */
class ExifNativeJpegTest extends TestCase
{
    private string $fixtureDir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->fixtureDir = __DIR__ . '/../Fixtures/webcam-exif';
    }

    public function testPushFullFrame_ReadsDateTimeOriginalAndGpsUtc(): void
    {
        $result = exifNativeJpegRead($this->fixtureDir . '/push_full.jpg');

        $this->assertSame('ok', $result['status']);
        $this->assertSame('2024:12:26 15:30:45', $result['DateTimeOriginal'] ?? null);
        $this->assertSame('-08:00', $result['OffsetTimeOriginal'] ?? null);
        $this->assertSame('2024:12:26', $result['GpsDateStamp'] ?? null);
        $this->assertSame([23, 30, 45], array_values(array_map('intval', $result['GpsTimeComponents'] ?? [])));

        $utc = exifNativeGpsUtcTimestamp($result['GpsDateStamp'], $result['GpsTimeComponents']);
        $expected = strtotime('2024-12-26 23:30:45 UTC');
        $this->assertSame($expected, $utc, 'GPS pair must combine to UTC 2024-12-26 23:30:45');
    }

    public function testDateTimeOnlyFrame_ReadsDateTimeOriginalButNoGps(): void
    {
        $result = exifNativeJpegRead($this->fixtureDir . '/datetime_only.jpg');

        $this->assertSame('ok', $result['status']);
        $this->assertSame('2023:06:15 12:05:00', $result['DateTimeOriginal'] ?? null);
        $this->assertArrayNotHasKey('GpsDateStamp', $result);
        $this->assertArrayNotHasKey('GpsTimeComponents', $result);
    }

    public function testGpsOnlyFrame_ReadsGpsButNoDateTimeOriginal(): void
    {
        $result = exifNativeJpegRead($this->fixtureDir . '/gps_only.jpg');

        $this->assertSame('ok', $result['status']);
        $this->assertArrayNotHasKey('DateTimeOriginal', $result);
        $this->assertSame('2023:06:15', $result['GpsDateStamp'] ?? null);
        $this->assertSame([19, 5, 0], array_values(array_map('intval', $result['GpsTimeComponents'] ?? [])));
    }

    public function testXmpBearingFrame_StillReadsExif(): void
    {
        // XMP is not part of the copy contract, but EXIF must still be readable.
        $result = exifNativeJpegRead($this->fixtureDir . '/xmp_bearing.jpg');

        $this->assertSame('ok', $result['status']);
        $this->assertSame('2022:11:01 08:00:00', $result['DateTimeOriginal'] ?? null);
    }

    public function testGeometryFrame_ReadsMakeModelOrientation(): void
    {
        $result = exifNativeJpegRead($this->fixtureDir . '/source_with_geom.jpg');

        $this->assertSame('ok', $result['status']);
        $this->assertSame('PineCam', $result['Make'] ?? null);
        $this->assertSame('PC-9000', $result['Model'] ?? null);
        $this->assertSame(6, $result['Orientation'] ?? null);
    }

    public function testNoExifFrame_ReturnsAbsent(): void
    {
        $result = exifNativeJpegRead($this->fixtureDir . '/no_exif.jpg');
        $this->assertSame('absent', $result['status']);
    }

    public function testMissingFile_ReturnsError(): void
    {
        $result = exifNativeJpegRead($this->fixtureDir . '/does_not_exist.jpg');
        $this->assertSame('error', $result['status']);
    }

    public function testDateTimeOriginalTimestamp_ParsesLocalAsUtc(): void
    {
        $this->assertSame(
            strtotime('2024-12-26 15:30:45 UTC'),
            exifNativeDateTimeOriginalTimestamp('2024:12:26 15:30:45')
        );
        $this->assertSame(0, exifNativeDateTimeOriginalTimestamp('not-a-date'));
    }
}
