<?php

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../lib/exif/read.php';
require_once __DIR__ . '/../../lib/exif/write.php';

/**
 * Unit tests for the native credit-only EXIF writer.
 *
 * Copies are applied to copies of the committed fixtures so the originals are
 * never mutated by the suite.
 */
class ExifNativeCreditTest extends TestCase
{
    private string $fixtureDir;
    private string $tmpDir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->fixtureDir = __DIR__ . '/../Fixtures/webcam-exif';
        $this->tmpDir = sys_get_temp_dir() . '/exifnative-credit-' . uniqid('', true);
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
     * Credit payload used across the format cases.
     *
     * @return array{imageDescription: string, artist: string, copyright: string, userComment: string}
     */
    private function credit(string $stamp = '2026-05-06 07:08 UTC'): array
    {
        return [
            'copyright' => '© OpenStreetMap contributors · Cloud data © OpenWeatherMap · Radar © RainViewer · AviationWX.org',
            'artist' => 'AviationWX.org',
            'imageDescription' => "Airport directory weather map rendered {$stamp}.",
            'userComment' => "© OpenStreetMap contributors · Cloud data © OpenWeatherMap · Radar © RainViewer · as of {$stamp}",
        ];
    }

    private function assertCreditReads(array $credit, array $result): void
    {
        $this->assertSame('ok', $result['status']);
        $this->assertSame($credit['copyright'], $result['Copyright'] ?? null);
        $this->assertSame($credit['artist'], $result['Artist'] ?? null);
        $this->assertSame($credit['imageDescription'], $result['ImageDescription'] ?? null);
        $this->assertSame($credit['userComment'], $result['UserComment'] ?? null);
    }

    public function testWriteCredit_Png_CarriesTagsAndPreservesMtime(): void
    {
        $dest = $this->tmpDir . '/card.png';
        copy($this->fixtureDir . '/png_no_exif.png', $dest);
        $mtime = time() - 5400;
        @touch($dest, $mtime);

        $credit = $this->credit();
        $this->assertTrue(exifNativeWriteCredit($dest, $credit));

        $this->assertCreditReads($credit, exifNativeRead($dest));
        $this->assertSame($mtime, @filemtime($dest), 'credit write must restore mtime like exiftool -P');
    }

    public function testWriteCredit_Jpeg_CarriesTags(): void
    {
        $dest = $this->tmpDir . '/card.jpg';
        copy($this->fixtureDir . '/no_exif.jpg', $dest);

        $credit = $this->credit();
        $this->assertTrue(exifNativeWriteCredit($dest, $credit));

        $this->assertCreditReads($credit, exifNativeRead($dest));
    }

    public function testWriteCredit_Webp_CarriesTags(): void
    {
        $dest = $this->tmpDir . '/card.webp';
        copy($this->fixtureDir . '/webp_no_exif.webp', $dest);

        $credit = $this->credit();
        $this->assertTrue(exifNativeWriteCredit($dest, $credit));

        $this->assertCreditReads($credit, exifNativeRead($dest));
    }

    public function testWriteCredit_WritesCreditOnlyBlock(): void
    {
        $dest = $this->tmpDir . '/card.png';
        copy($this->fixtureDir . '/png_no_exif.png', $dest);

        $this->assertTrue(exifNativeWriteCredit($dest, $this->credit()));

        $result = exifNativeRead($dest);
        $this->assertSame('ok', $result['status']);
        $this->assertArrayNotHasKey('DateTimeOriginal', $result, 'a rendered card has no capture time');
        $this->assertArrayNotHasKey('GpsDateStamp', $result);
    }

    public function testWriteCredit_FromSourceWithExif_ReplacesWithCreditOnly(): void
    {
        // Start from a frame that has full webcam EXIF; the credit write must
        // replace that block rather than merge a duplicate APP1 segment.
        $dest = $this->tmpDir . '/card.jpg';
        copy($this->fixtureDir . '/push_full.jpg', $dest);

        $credit = $this->credit();
        $this->assertTrue(exifNativeWriteCredit($dest, $credit));

        $result = exifNativeRead($dest);
        $this->assertCreditReads($credit, $result);
        $this->assertArrayNotHasKey('DateTimeOriginal', $result);
    }

    public function testWriteCredit_UnsupportedContainer_ReturnsFalseAndLeavesFile(): void
    {
        $dest = $this->tmpDir . '/notes.txt';
        file_put_contents($dest, 'not an image');
        $before = file_get_contents($dest);

        $this->assertFalse(exifNativeWriteCredit($dest, $this->credit()));
        $this->assertSame($before, file_get_contents($dest), 'unsupported file must be untouched');
    }

    public function testWriteCredit_MissingFile_ReturnsFalse(): void
    {
        $this->assertFalse(exifNativeWriteCredit($this->tmpDir . '/absent.png', $this->credit()));
    }

    public function testWriteCredit_ReplacesExistingBlock_OneSegmentOnly(): void
    {
        // JPEG source that already carries full webcam EXIF.
        $jpg = $this->tmpDir . '/a.jpg';
        copy($this->fixtureDir . '/push_full.jpg', $jpg);
        $this->assertTrue(exifNativeWriteCredit($jpg, $this->credit()));
        $this->assertSame(1, substr_count(file_get_contents($jpg), "Exif\x00\x00"),
            'credit write must replace the APP1 in place, not append a second one');

        // PNG source with an existing eXIf chunk.
        $png = $this->tmpDir . '/b.png';
        copy($this->fixtureDir . '/png_exif.png', $png);
        $this->assertTrue(exifNativeWriteCredit($png, $this->credit()));
        $this->assertSame(1, $this->pngChunkCount($png, 'eXIf'), 'exactly one eXIf chunk must remain');

        // WebP source with an existing EXIF chunk.
        $webp = $this->tmpDir . '/c.webp';
        copy($this->fixtureDir . '/webp_exif.webp', $webp);
        $this->assertTrue(exifNativeWriteCredit($webp, $this->credit()));
        $this->assertSame(1, $this->riffChunkCount($webp, 'EXIF'), 'exactly one EXIF chunk must remain');
    }

    public function testWriteCredit_PngAndWebp_ChunksCarryTiffNotJpegMarker(): void
    {
        $png = $this->tmpDir . '/card.png';
        copy($this->fixtureDir . '/png_no_exif.png', $png);
        $this->assertTrue(exifNativeWriteCredit($png, $this->credit()));
        $payload = $this->pngChunkPayload($png, 'eXIf');
        $this->assertNotNull($payload);
        $this->assertMatchesRegularExpression('/^(II|MM)/', $payload,
            'eXIf chunk must store the TIFF header, not the Exif00 wrapper');

        $webp = $this->tmpDir . '/card.webp';
        copy($this->fixtureDir . '/webp_no_exif.webp', $webp);
        $this->assertTrue(exifNativeWriteCredit($webp, $this->credit()));
        $payload = $this->riffChunkPayload($webp, 'EXIF');
        $this->assertNotNull($payload);
        $this->assertMatchesRegularExpression('/^(II|MM)/', $payload,
            'EXIF chunk must store the TIFF header, not the Exif00 wrapper');
    }

    /**
     * @return array<string, list<string>> type => list of chunk data payloads
     */
    private function pngChunks(string $bytes): array
    {
        $pos = 8;
        $length = strlen($bytes);
        $chunks = [];
        while ($pos + 8 <= $length) {
            $len = (ord($bytes[$pos]) << 24) | (ord($bytes[$pos + 1]) << 16)
                | (ord($bytes[$pos + 2]) << 8) | ord($bytes[$pos + 3]);
            $type = substr($bytes, $pos + 4, 4);
            $chunks[$type][] = substr($bytes, $pos + 8, $len);
            $pos += 12 + $len;
        }
        return $chunks;
    }

    private function pngChunkCount(string $path, string $type): int
    {
        return count($this->pngChunks((string) file_get_contents($path))[$type] ?? []);
    }

    private function pngChunkPayload(string $path, string $type): ?string
    {
        return $this->pngChunks((string) file_get_contents($path))[$type][0] ?? null;
    }

    /**
     * @return array<string, list<string>> fourcc => list of chunk data payloads
     */
    private function riffChunks(string $bytes): array
    {
        $pos = 12;
        $length = strlen($bytes);
        $chunks = [];
        while ($pos + 8 <= $length) {
            $type = substr($bytes, $pos, 4);
            $len = ord($bytes[$pos + 4]) | (ord($bytes[$pos + 5]) << 8)
                | (ord($bytes[$pos + 6]) << 16) | (ord($bytes[$pos + 7]) << 24);
            $chunks[$type][] = substr($bytes, $pos + 8, $len);
            $pos += 8 + $len + ($len % 2);
        }
        return $chunks;
    }

    private function riffChunkCount(string $path, string $type): int
    {
        return count($this->riffChunks((string) file_get_contents($path))[$type] ?? []);
    }

    private function riffChunkPayload(string $path, string $type): ?string
    {
        return $this->riffChunks((string) file_get_contents($path))[$type][0] ?? null;
    }
}
