<?php

declare(strict_types=1);

/**
 * Tests for push FTP/SFTP upload debris cleanup (non-image files ignored by push worker).
 */

require_once __DIR__ . '/../../lib/push-upload-debris-cleanup.php';

use PHPUnit\Framework\TestCase;

final class PushUploadDebrisCleanupTest extends TestCase
{
    private string $tempRoot;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tempRoot = sys_get_temp_dir() . '/awx_push_debris_test_' . bin2hex(random_bytes(8));
        mkdir($this->tempRoot . '/nested/2026/05/09', 0755, true);
    }

    protected function tearDown(): void
    {
        $this->deleteTree($this->tempRoot);
        parent::tearDown();
    }

    /**
     * Old MP4 and TXT are removed; fresh MP4 and JPG remain.
     */
    public function testRemovesStaleVideoAndSidecarKeepsRecentAndImages(): void
    {
        $oldMp4 = $this->tempRoot . '/nested/old_clip.mp4';
        $newMp4 = $this->tempRoot . '/nested/new_clip.mp4';
        $oldTxt = $this->tempRoot . '/nested/sidecar.txt';
        $jpg = $this->tempRoot . '/nested/frame.jpg';

        file_put_contents($oldMp4, str_repeat('x', 100));
        touch($oldMp4, time() - 200000);

        file_put_contents($newMp4, 'small');
        touch($newMp4, time() - 3600);

        file_put_contents($oldTxt, 'meta');
        touch($oldTxt, time() - 200000);

        file_put_contents($jpg, 'jpeg');
        touch($jpg, time() - 200000);

        $stats = [
            'files_checked' => 0,
            'files_deleted' => 0,
            'bytes_freed' => 0,
            'errors' => 0,
        ];

        $maxAge = 172800;

        cleanupPushUploadDebris($maxAge, $stats, false, false, [$this->tempRoot], push_upload_master_image_extensions());

        $this->assertFileDoesNotExist($oldMp4);
        $this->assertFileDoesNotExist($oldTxt);
        $this->assertFileExists($newMp4);
        $this->assertFileExists($jpg);
        $this->assertSame(2, $stats['files_deleted']);
        $this->assertSame(0, $stats['errors']);
    }

    /**
     * Unknown extension is debris and is removed when stale.
     */
    public function testRemovesStaleUnknownExtension(): void
    {
        $junk = $this->tempRoot . '/nested/file.xyz';
        file_put_contents($junk, 'x');
        touch($junk, time() - 200000);

        $stats = [
            'files_checked' => 0,
            'files_deleted' => 0,
            'bytes_freed' => 0,
            'errors' => 0,
        ];

        cleanupPushUploadDebris(172800, $stats, false, false, [$this->tempRoot], push_upload_master_image_extensions());

        $this->assertFileDoesNotExist($junk);
        $this->assertSame(1, $stats['files_deleted']);
    }

    /**
     * Restrictive allowlist treats omitted types as debris.
     */
    public function testRestrictiveAllowlistDeletesStaleAllowedTypesNotInList(): void
    {
        $oldJpg = $this->tempRoot . '/nested/old_frame.jpg';
        file_put_contents($oldJpg, 'jpeg');
        touch($oldJpg, time() - 200000);

        $stats = [
            'files_checked' => 0,
            'files_deleted' => 0,
            'bytes_freed' => 0,
            'errors' => 0,
        ];

        cleanupPushUploadDebris(172800, $stats, false, false, [$this->tempRoot], ['png']);

        $this->assertFileDoesNotExist($oldJpg);
        $this->assertSame(1, $stats['files_deleted']);
    }

    /**
     * Dry run increments counts but does not unlink.
     */
    public function testDryRunDoesNotDelete(): void
    {
        $mp4 = $this->tempRoot . '/nested/stale.mp4';
        file_put_contents($mp4, 'data');
        touch($mp4, time() - 200000);

        $stats = [
            'files_checked' => 0,
            'files_deleted' => 0,
            'bytes_freed' => 0,
            'errors' => 0,
        ];

        cleanupPushUploadDebris(172800, $stats, true, false, [$this->tempRoot], push_upload_master_image_extensions());

        $this->assertFileExists($mp4);
        $this->assertGreaterThanOrEqual(1, $stats['files_deleted']);
    }

    public function testGetPushUploadAllowedExtensionsForCleanupMatchesMasterByDefault(): void
    {
        $this->assertSame(
            push_upload_sorted_unique_extensions_list(push_upload_master_image_extensions()),
            getPushUploadAllowedExtensionsForCleanup()
        );
    }

    public function testValidatePushUploadAllowedExtensionsRejectsUnknownExtension(): void
    {
        $config = [
            'config' => [
                'push_upload_allowed_extensions' => ['jpg', 'bmp'],
            ],
            'airports' => [],
        ];
        $result = validateAirportsJsonStructure($config);
        $this->assertFalse($result['valid']);
        $this->assertStringContainsString('bmp', implode(' ', $result['errors']));
    }

    public function testFormatPushUploadDebrisAge(): void
    {
        $this->assertStringEndsWith('min', formatPushUploadDebrisAge(120));
        $this->assertStringEndsWith('h', formatPushUploadDebrisAge(7200));
        $this->assertStringEndsWith('d', formatPushUploadDebrisAge(200000));
    }

    private function deleteTree(string $path): void
    {
        if (!is_dir($path)) {
            return;
        }
        $it = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($path, RecursiveDirectoryIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($it as $fileInfo) {
            $p = $fileInfo->getPathname();
            if ($fileInfo->isDir()) {
                @rmdir($p);
            } else {
                @unlink($p);
            }
        }
        @rmdir($path);
    }
}
