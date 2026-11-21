<?php

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../lib/exif/read.php';
require_once __DIR__ . '/../../lib/exif/jpeg.php';
require_once __DIR__ . '/../../lib/exif/write.php';

/**
 * Hardening tests for the native EXIF module.
 *
 * Feeds truncated and malformed binaries through read, stamp, and copy and
 * asserts none of them throw or misreport an exotic but impossible capture.
 * Covers the hostile-input surface for varied incoming camera files.
 */
class ExifNativeHardeningTest extends TestCase
{
    private string $fixtureDir;
    private string $tmpDir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->fixtureDir = __DIR__ . '/../Fixtures/webcam-exif';
        $this->tmpDir = sys_get_temp_dir() . '/exifnative-hard-' . uniqid('', true);
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

    /**
     * Write bytes to a temp file and return its path.
     */
    private function put(string $name, string $bytes): string
    {
        $path = $this->tmpDir . '/' . $name;
        file_put_contents($path, $bytes);
        return $path;
    }

    public function testRead_TruncatedAndGarbageFiles_NeverThrow(): void
    {
        $source = file_get_contents($this->fixtureDir . '/push_full.jpg');
        $length = strlen($source);
        $cuts = [0, 1, 2, 4, 6, 8, 10, 12, 16, 32, (int) ($length / 2), $length - 1];
        $hostile = [
            'garbage.bin' => "\xFF\xFF\x00\xDF\x10",
            'empty.bin' => '',
            'text.txt' => 'not an image',
        ];
        foreach ($cuts as $cut) {
            $hostile["cut-{$cut}.jpg"] = substr($source, 0, $cut);
        }

        foreach ($hostile as $name => $bytes) {
            $path = $this->put($name, $bytes);
            $result = exifNativeRead($path);
            $this->assertContains($result['status'] ?? '', ['ok', 'absent', 'error'], "status for {$name}");
            $stampOk = exifNativeStamp($path, 1700000000, 'UTC');
            $this->assertTrue(is_bool($stampOk), "stamp must not throw: {$name}");

            $dest = $this->put($name . '.png', "\x89PNG\r\n\x1a\n\x00\x00\x00\x0dIHDR" . str_repeat("\x00", 13) . "\x00\x00\x00\x00");
            $copyOk = exifNativeCopy($path, $dest);
            $this->assertTrue(is_bool($copyOk), "copy must not throw: {$name}");
        }
    }

    public function testParseTiffTags_OversizedIfdCount_ReturnsEmpty(): void
    {
        // TIFF with an IFD0 count of 0xFFFF and junk entries after it: the walk
        // must be bounded and yield no EXIF group rather than spin or misread.
        $tiff = 'II' . "\x2A\x00" . "\x08\x00\x00\x00"  // header, IFD0 at offset 8
            . "\xFF\xFF"                               // count 65535
            . str_repeat("\x00", 24);
        $this->assertSame([], exifNativeParseTiffTags($tiff)['exif']);
    }

    public function testParseTiffTags_SelfReferentialExifPointer_ReturnsEmpty(): void
    {
        // IFD0 count 1, an EXIF IFD pointer whose value points back at offset 0.
        $tiff = 'II' . "\x2A\x00" . "\x08\x00\x00\x00"
            . "\x01\x00"                     // one entry
            . "\x69\x87\x04\x00"             // tag 0x8769 Exif IFD pointer
            . "\x01\x00\x00\x00"             // LONG count 1
            . "\x00\x00\x00\x00";            // value = 0 (points at the header)
        $groups = exifNativeParseTiffTags($tiff);
        $this->assertSame([], $groups['exif']);
    }

    public function testPng_TamperedExifCrc_ReturnsAbsent(): void
    {
        $png = file_get_contents($this->fixtureDir . '/png_exif.png');
        $pos = 8;
        $length = strlen($png);
        while ($pos + 8 <= $length) {
            $chunkLen = (ord($png[$pos]) << 24) | (ord($png[$pos + 1]) << 16) | (ord($png[$pos + 2]) << 8) | ord($png[$pos + 3]);
            if (substr($png, $pos + 4, 4) === 'eXIf') {
                // Corrupt one byte of the eXIf payload.
                $png[$pos + 8] = chr(ord($png[$pos + 8]) ^ 0x01);
                break;
            }
            $pos += 8 + $chunkLen + 4;
        }

        $path = $this->put('tampered.png', $png);
        $this->assertSame('absent', exifNativeRead($path)['status'] ?? null);
    }
}
