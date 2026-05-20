<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../lib/nws-points-cache.php';

/**
 * @covers ::nws_points_coordinates_valid
 * @covers ::nws_points_normalize_coord
 * @covers ::nws_points_cache_key
 * @covers ::nws_points_cache_entry_is_fresh
 * @covers ::nws_points_cache_read
 * @covers ::nws_points_cache_write
 */
final class NwsPointsCacheTest extends TestCase
{
    private ?string $testRoot = null;

    protected function setUp(): void
    {
        parent::setUp();
        $this->testRoot = sys_get_temp_dir() . '/nws-points-cache-test-' . bin2hex(random_bytes(4));
        mkdir($this->testRoot, 0755, true);
        $GLOBALS['nws_points_cache_test_root'] = $this->testRoot;
    }

    protected function tearDown(): void
    {
        unset($GLOBALS['nws_points_cache_test_root']);
        if ($this->testRoot !== null && is_dir($this->testRoot)) {
            $files = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($this->testRoot, FilesystemIterator::SKIP_DOTS),
                RecursiveIteratorIterator::CHILD_FIRST
            );
            foreach ($files as $file) {
                if ($file->isDir()) {
                    rmdir($file->getPathname());
                } else {
                    unlink($file->getPathname());
                }
            }
            rmdir($this->testRoot);
        }
        parent::tearDown();
    }

    public function testCacheKey_NormalizesToFourDecimals(): void
    {
        $this->assertSame('45.7710,-122.8600', nws_points_cache_key(45.7710278, -122.8600123));
    }

    public function testCoordinatesValid_RejectsOutOfRange(): void
    {
        $this->assertFalse(nws_points_coordinates_valid(91.0, 0.0));
        $this->assertFalse(nws_points_coordinates_valid(0.0, 181.0));
        $this->assertTrue(nws_points_coordinates_valid(45.77, -122.86));
    }

    public function testCacheWriteAndRead_ReturnsBodyWhileFresh(): void
    {
        $now = 1_700_000_000;
        $body = '{"properties":{"gridId":"PQR"}}';
        $this->assertTrue(nws_points_cache_write(45.771, -122.86, $body, $now));
        $this->assertSame($body, nws_points_cache_read(45.771, -122.86, $now));
    }

    public function testCacheRead_ReturnsNullWhenExpired(): void
    {
        $fetchedAt = 1_700_000_000;
        $body = '{"properties":{}}';
        $this->assertTrue(nws_points_cache_write(45.771, -122.86, $body, $fetchedAt));

        $expiredNow = $fetchedAt + NWS_POINTS_CACHE_TTL_SECONDS + 1;
        $this->assertNull(nws_points_cache_read(45.771, -122.86, $expiredNow));
    }

    public function testCacheRead_ReturnsNullForInvalidCoordinates(): void
    {
        $this->assertNull(nws_points_cache_read(100.0, 0.0));
        $this->assertFalse(nws_points_cache_write(100.0, 0.0, '{}'));
    }

    public function testNwsFetchPoints_WritesCacheAndReusesOnSecondCall(): void
    {
        require_once __DIR__ . '/../../lib/weather/adapter/nws-api-v1.php';

        $first = nws_fetch_points(45.771, -122.86);
        $this->assertIsArray($first);
        $this->assertSame('PQR', $first['properties']['gridId'] ?? null);

        $cachePath = nws_points_cache_file_path(nws_points_cache_key(45.771, -122.86));
        $this->assertFileExists($cachePath);

        $second = nws_fetch_points(45.771, -122.86);
        $this->assertSame($first, $second);
    }
}
