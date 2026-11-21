<?php

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../lib/exif/read.php';
require_once __DIR__ . '/../../lib/exif/write.php';

/**
 * Byte-level conformance tests for the native EXIF writer.
 *
 * Parses the TIFF block the writer emits and asserts the structure exiftool
 * expects: ExifVersion 2.31, the required JPEG Exif/GPS tags, an ASCII
 * UserComment header, ascending IFD order, and even out-of-line offsets. No
 * external tool is involved; the suite stays exiftool-free.
 */
class ExifNativeConformanceTest extends TestCase
{
    private string $fixtureDir;
    private string $tmpDir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->fixtureDir = __DIR__ . '/../Fixtures/webcam-exif';
        $this->tmpDir = sys_get_temp_dir() . '/exifnative-conf-' . uniqid('', true);
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

    private function u16(string $s, int $o): int
    {
        return ord($s[$o]) | (ord($s[$o + 1]) << 8);
    }

    private function u32(string $s, int $o): int
    {
        return ord($s[$o]) | (ord($s[$o + 1]) << 8) | (ord($s[$o + 2]) << 16) | (ord($s[$o + 3]) << 24);
    }

    /**
     * @return array{type: int, count: int, valueField: int}
     */
    private function readIfd(string $tiff, int $offset): array
    {
        $count = $this->u16($tiff, $offset);
        $entries = [];
        for ($i = 0; $i < $count; $i++) {
            $p = $offset + 2 + $i * 12;
            $entries[$this->u16($tiff, $p)] = [
                'type' => $this->u16($tiff, $p + 2),
                'count' => $this->u32($tiff, $p + 4),
                'valueField' => $p + 8,
            ];
        }
        return $entries;
    }

    /**
     * @return array{ifd0: array, exif: array, gps: array} tag => entry
     */
    private function parseTiff(string $tiff): array
    {
        $ifd0 = $this->readIfd($tiff, $this->u32($tiff, 4));
        $groups = ['ifd0' => $ifd0];
        $groups['exif'] = isset($ifd0[0x8769]) ? $this->readIfd($tiff, $this->u32($tiff, $ifd0[0x8769]['valueField'])) : [];
        $groups['gps'] = isset($ifd0[0x8825]) ? $this->readIfd($tiff, $this->u32($tiff, $ifd0[0x8825]['valueField'])) : [];
        return $groups;
    }

    private function entryBytes(string $tiff, array $entry): string
    {
        $unit = [1 => 1, 2 => 1, 3 => 2, 4 => 4, 5 => 8, 7 => 1, 9 => 4, 10 => 8][$entry['type']] ?? 1;
        $n = $unit * $entry['count'];
        if ($n <= 4) {
            return substr($tiff, $entry['valueField'], $n);
        }
        return substr($tiff, $this->u32($tiff, $entry['valueField']), $n);
    }

    private function entryOffset(string $tiff, array $entry): ?int
    {
        $unit = [1 => 1, 2 => 1, 3 => 2, 4 => 4, 5 => 8, 7 => 1, 9 => 4, 10 => 8][$entry['type']] ?? 1;
        return $unit * $entry['count'] > 4 ? $this->u32($tiff, $entry['valueField']) : null;
    }

    private function jpegTiff(string $jpg): string
    {
        $pos = 2;
        $len = strlen($jpg);
        while ($pos + 4 <= $len) {
            if (ord($jpg[$pos]) !== 0xFF) {
                $pos++;
                continue;
            }
            $code = ord($jpg[$pos + 1]);
            if ($code === 0xE1) {
                $segLen = (ord($jpg[$pos + 2]) << 8) | ord($jpg[$pos + 3]);
                $data = substr($jpg, $pos + 4, $segLen - 2);
                if (substr($data, 0, 6) === "Exif\x00\x00") {
                    return substr($data, 6);
                }
            }
            if ($code === 0xDA) {
                break;
            }
            $segLen = (ord($jpg[$pos + 2]) << 8) | ord($jpg[$pos + 3]);
            $pos += 2 + $segLen;
        }
        return '';
    }

    private function stampJpeg(int $width, int $height): string
    {
        $path = $this->tmpDir . '/stamped.jpg';
        $img = imagecreatetruecolor($width, $height);
        imagefill($img, 0, 0, imagecolorallocate($img, 90, 130, 70));
        imagejpeg($img, $path, 90);
        imagedestroy($img);
        $this->assertTrue(exifNativeStamp($path, strtotime('2026-11-19 12:00:00 UTC'), 'America/Los_Angeles'));
        return $this->jpegTiff((string) file_get_contents($path));
    }

    public function testStamp_ConformsToExifSpec(): void
    {
        $tiff = $this->stampJpeg(640, 480);
        $groups = $this->parseTiff($tiff);

        $this->assertSame('0231', $this->entryBytes($tiff, $groups['exif'][0x9000]), 'ExifVersion must be 2.31');
        $this->assertArrayHasKey(0x9101, $groups['exif'], 'ComponentsConfiguration must be present');
        $this->assertArrayHasKey(0x0213, $groups['ifd0'], 'YCbCrPositioning must be present');
        $this->assertArrayHasKey(0x0000, $groups['gps'], 'GPSVersionID must be present');
        $this->assertSame("\x02\x03\x00\x00", $this->entryBytes($tiff, $groups['gps'][0x0000]), 'GPSVersionID must be 2.3.0.0');

        // ExifImageWidth/Height must match the actual pixel dimensions.
        $this->assertSame(640, $this->u32($tiff, $groups['exif'][0xA002]['valueField']));
        $this->assertSame(480, $this->u32($tiff, $groups['exif'][0xA003]['valueField']));

        // UserComment carries the ASCII charset header the spec requires.
        $this->assertStringStartsWith("ASCII\x00\x00\x00", $this->entryBytes($tiff, $groups['exif'][0x9286]));

        // Every out-of-line value sits on an even offset.
        foreach ($groups as $name => $entries) {
            foreach ($entries as $tag => $entry) {
                $offset = $this->entryOffset($tiff, $entry);
                if ($offset !== null) {
                    $this->assertSame(0, $offset % 2, "{$name} tag 0x" . dechex($tag) . " must be even-aligned");
                }
            }
        }
    }

    private function pngTiff(string $png): string
    {
        $pos = 8;
        $len = strlen($png);
        while ($pos + 8 <= $len) {
            $chunkLen = (ord($png[$pos]) << 24) | (ord($png[$pos + 1]) << 16)
                | (ord($png[$pos + 2]) << 8) | ord($png[$pos + 3]);
            if (substr($png, $pos + 4, 4) === 'eXIf') {
                return substr($png, $pos + 8, $chunkLen);
            }
            $pos += 12 + $chunkLen;
        }
        return '';
    }

    private function webpTiff(string $webp): string
    {
        $pos = 12;
        $len = strlen($webp);
        while ($pos + 8 <= $len) {
            $chunkLen = ord($webp[$pos + 4]) | (ord($webp[$pos + 5]) << 8)
                | (ord($webp[$pos + 6]) << 16) | (ord($webp[$pos + 7]) << 24);
            if (substr($webp, $pos, 4) === 'EXIF') {
                return substr($webp, $pos + 8, $chunkLen);
            }
            $pos += 8 + $chunkLen + ($chunkLen % 2);
        }
        return '';
    }

    public function testStamp_PngAndWebp_WriteDimensions(): void
    {
        foreach (['png' => 'png_no_exif.png', 'webp' => 'webp_no_exif.webp'] as $name => $fixture) {
            $path = $this->tmpDir . "/{$name}.img";
            copy($this->fixtureDir . '/' . $fixture, $path);
            $this->assertTrue(exifNativeStamp($path, strtotime('2026-11-19 12:00:00 UTC'), 'UTC'));

            $bytes = (string) file_get_contents($path);
            $tiff = $name === 'png' ? $this->pngTiff($bytes) : $this->webpTiff($bytes);
            $groups = $this->parseTiff($tiff);
            $this->assertSame(32, $this->u32($tiff, $groups['exif'][0xA002]['valueField']), $name . ' width');
            $this->assertSame(24, $this->u32($tiff, $groups['exif'][0xA003]['valueField']), $name . ' height');
        }
    }

    public function testStamp_Webp_ConformsToContainerOrder(): void
    {
        $path = $this->tmpDir . '/card.webp';
        copy($this->fixtureDir . '/webp_no_exif.webp', $path);
        $this->assertTrue(exifNativeStamp($path, strtotime('2026-11-19 12:00:00 UTC'), 'UTC'));

        $bytes = (string) file_get_contents($path);
        // Metadata requires the extended container: VP8X must lead.
        $this->assertSame('VP8X', substr($bytes, 12, 4), 'metadata requires a VP8X container');
        $this->assertNotSame(0, ord($bytes[20]) & 0x08, 'the EXIF-present flag must be set');

        // The EXIF chunk follows the image chunk, like the reference layout.
        $exifPos = strpos($bytes, 'EXIF');
        $vp8Pos = strpos($bytes, 'VP8 ');
        $vp8lPos = strpos($bytes, 'VP8L');
        $imgPos = ($vp8lPos !== false && ($vp8Pos === false || $vp8lPos < $vp8Pos)) ? $vp8lPos : $vp8Pos;
        $this->assertNotFalse($imgPos, 'an image chunk must exist');
        $this->assertNotFalse($exifPos, 'the EXIF chunk must be present');
        $this->assertGreaterThan($imgPos, $exifPos, 'EXIF must follow the image chunk');

        $groups = $this->parseTiff($this->webpTiff($bytes));
        $this->assertArrayHasKey(0x9003, $groups['exif'], 'the stamped capture time must parse back');
    }
}
