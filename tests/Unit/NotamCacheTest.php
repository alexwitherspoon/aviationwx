<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class NotamCacheTest extends TestCase
{
    private static string $cacheDir;

    public static function setUpBeforeClass(): void
    {
        self::$cacheDir = sys_get_temp_dir() . '/aviationwx-notam-cache-' . bin2hex(random_bytes(4));
        mkdir(self::$cacheDir, 0755, true);
        if (!defined('AVIATIONWX_NOTAM_CACHE_DIR')) {
            define('AVIATIONWX_NOTAM_CACHE_DIR', self::$cacheDir);
        }
        require_once dirname(__DIR__, 2) . '/lib/constants.php';
        require_once dirname(__DIR__, 2) . '/lib/notam/cache.php';
    }

    public static function tearDownAfterClass(): void
    {
        if (!isset(self::$cacheDir) || !is_dir(self::$cacheDir)) {
            return;
        }

        foreach (scandir(self::$cacheDir) ?: [] as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            $path = self::$cacheDir . '/' . $item;
            is_dir($path) ? self::removeTree($path) : @unlink($path);
        }
        @rmdir(self::$cacheDir);
    }

    protected function setUp(): void
    {
        foreach (scandir(self::$cacheDir) ?: [] as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            $path = self::$cacheDir . '/' . $item;
            is_dir($path) ? self::removeTree($path) : @unlink($path);
        }
    }

    public function testWriteCacheFile_WritesValidJsonAtomically(): void
    {
        $path = self::$cacheDir . '/kspb.json';
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
        $path = self::$cacheDir . '/kspb.json';
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
        self::assertSame(rtrim(self::$cacheDir, '/'), notamCacheDirectory());
    }

    public function testShouldEnqueueRefresh_SkipsDuringFetchFailureBackoff(): void
    {
        $now = 1_700_000_000;
        touch(notamCacheFilePath('kspb'), $now - 900);
        touch(notamFetchAttemptFilePath('kspb'), $now - 60);

        self::assertFalse(notamShouldEnqueueRefresh('kspb', 600, $now));
    }

    public function testShouldEnqueueRefresh_AllowsAfterBackoffExpires(): void
    {
        $now = 1_700_000_000;
        touch(notamCacheFilePath('kspb'), $now - 900);
        touch(notamFetchAttemptFilePath('kspb'), $now - NOTAM_FETCH_FAILURE_BACKOFF_SECONDS - 10);

        self::assertTrue(notamShouldEnqueueRefresh('kspb', 600, $now));
    }

    private static function removeTree(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }

        foreach (scandir($dir) ?: [] as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            $path = $dir . '/' . $item;
            is_dir($path) ? self::removeTree($path) : @unlink($path);
        }
        @rmdir($dir);
    }
}
