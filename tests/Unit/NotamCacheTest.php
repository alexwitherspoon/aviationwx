<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class NotamCacheTest extends TestCase
{
    private string $cacheDir;

    protected function setUp(): void
    {
        $this->cacheDir = sys_get_temp_dir() . '/aviationwx-notam-cache-' . bin2hex(random_bytes(4));
        mkdir($this->cacheDir, 0755, true);
        putenv('APP_ENV=testing');
        putenv('CONFIG_PATH=' . dirname(__DIR__, 2) . '/tests/Fixtures/airports.json.test');
        require_once dirname(__DIR__, 2) . '/lib/notam/cache.php';
    }

    protected function tearDown(): void
    {
        $this->removeTree($this->cacheDir);
    }

    public function testWriteCacheFile_WritesValidJsonAtomically(): void
    {
        $path = $this->cacheDir . '/kspb.json';
        $payload = [
            'fetched_at' => 1700000000,
            'airport' => 'kspb',
            'notams' => [['id' => 'test-1', 'text' => 'RWY CLSD']],
            'status' => 'success',
        ];

        self::assertTrue(notamWriteCacheFile($path, $payload));

        self::assertFileExists($path);
        self::assertEmpty(glob($path . '.*.tmp'));

        $decoded = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame('kspb', $decoded['airport']);
        self::assertCount(1, $decoded['notams']);
    }

    public function testWriteCacheFile_ReplacesExistingFile(): void
    {
        $path = $this->cacheDir . '/kspb.json';
        file_put_contents($path, '{"airport":"old"}');

        $payload = [
            'fetched_at' => 1700000001,
            'airport' => 'kspb',
            'notams' => [],
            'status' => 'success',
        ];

        self::assertTrue(notamWriteCacheFile($path, $payload));

        $decoded = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame(1700000001, $decoded['fetched_at']);
    }

    public function testCacheDirectory_UsesOverrideConstant(): void
    {
        if (!defined('AVIATIONWX_NOTAM_CACHE_DIR')) {
            define('AVIATIONWX_NOTAM_CACHE_DIR', $this->cacheDir);
        }

        self::assertSame($this->cacheDir, notamCacheDirectory());
    }

    private function removeTree(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }

        foreach (scandir($dir) ?: [] as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            $path = $dir . '/' . $item;
            is_dir($path) ? $this->removeTree($path) : @unlink($path);
        }
        @rmdir($dir);
    }
}
