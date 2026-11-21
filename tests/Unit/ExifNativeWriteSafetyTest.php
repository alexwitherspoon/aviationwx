<?php

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../lib/exif/read.php';
require_once __DIR__ . '/../../lib/exif/write.php';

/**
 * Unit tests for the native write path's cache-safety behavior.
 *
 * Writes must preserve file mode, restore mtime and atime, and update the
 * target of a symlink instead of replacing the link, matching how the webcam
 * cache points current/original links at timestamped files.
 */
class ExifNativeWriteSafetyTest extends TestCase
{
    private string $fixtureDir;
    private string $tmpDir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->fixtureDir = __DIR__ . '/../Fixtures/webcam-exif';
        $this->tmpDir = sys_get_temp_dir() . '/exifnative-write-' . uniqid('', true);
        mkdir($this->tmpDir, 0777, true);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->tmpDir . '/**') ?: [] as $f) {
            @unlink($f);
        }
        foreach (glob($this->tmpDir . '/*') ?: [] as $f) {
            if (is_link($f) || is_file($f)) {
                @unlink($f);
            }
        }
        @rmdir($this->tmpDir);
        parent::tearDown();
    }

    public function testWrite_ThroughSymlink_UpdatesTargetAndKeepsLink(): void
    {
        $target = $this->tmpDir . '/real.jpg';
        $link = $this->tmpDir . '/current.jpg';
        copy($this->fixtureDir . '/no_exif.jpg', $target);
        @symlink('real.jpg', $link);
        $this->assertTrue(is_link($link), 'precondition: dest is a symlink');

        $this->assertTrue(exifNativeCopy($this->fixtureDir . '/push_full.jpg', $link));

        $this->assertTrue(is_link($link), 'stamp must not replace the symlink');
        $this->assertSame('2024:12:26 15:30:45', exifNativeRead($target)['DateTimeOriginal'] ?? null);
    }

    public function testWrite_PreservesFileMode(): void
    {
        $dest = $this->tmpDir . '/mode.jpg';
        copy($this->fixtureDir . '/no_exif.jpg', $dest);
        @chmod($dest, 0640);

        $this->assertTrue(exifNativeCopy($this->fixtureDir . '/push_full.jpg', $dest));
        $this->assertSame(0640, @fileperms($dest) & 0777, 'write must keep the original mode');
    }

    public function testWrite_PreservesMtime(): void
    {
        $dest = $this->tmpDir . '/mtime-only.jpg';
        copy($this->fixtureDir . '/no_exif.jpg', $dest);
        $mtime = time() - 3600;
        @touch($dest, $mtime);

        $this->assertTrue(exifNativeStamp($dest, strtotime('2025-03-04 05:06:07 UTC'), 'UTC'));

        $this->assertSame($mtime, @filemtime($dest), 'write must restore mtime');
    }
}
