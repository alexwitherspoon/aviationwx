<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../lib/nws-points-cache.php';

/**
 * @covers ::nwsPointsCoordinatesValid
 * @covers ::nwsPointsNormalizeCoord
 * @covers ::nwsPointsCacheKey
 * @covers ::nwsPointsCacheEntryIsFresh
 * @covers ::nwsPointsCacheRead
 * @covers ::nwsPointsCacheWrite
 * @covers ::nwsFetchPoints
 */
final class NwsPointsCacheTest extends TestCase
{
    private ?string $testRoot = null;

    protected function setUp(): void
    {
        parent::setUp();
        $this->testRoot = sys_get_temp_dir() . '/nws-points-cache-test-' . bin2hex(random_bytes(4));
        mkdir($this->testRoot, 0755, true);
        $GLOBALS['nwsPointsCacheTestRoot'] = $this->testRoot;
    }

    protected function tearDown(): void
    {
        unset($GLOBALS['nwsPointsCacheTestRoot']);
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
        $this->assertSame('45.7710,-122.8600', nwsPointsCacheKey(45.7710278, -122.8600123));
    }

    public function testNormalizeCoord_NegativeZeroMatchesPositiveZero(): void
    {
        $this->assertSame('0.0000', nwsPointsNormalizeCoord(-0.0));
        $this->assertSame(
            nwsPointsCacheKey(0.0, -122.86),
            nwsPointsCacheKey(-0.0, -122.86)
        );
    }

    public function testCoordinatesValid_RejectsOutOfRange(): void
    {
        $this->assertFalse(nwsPointsCoordinatesValid(91.0, 0.0));
        $this->assertFalse(nwsPointsCoordinatesValid(0.0, 181.0));
        $this->assertTrue(nwsPointsCoordinatesValid(45.77, -122.86));
    }

    public function testCacheWriteAndRead_ReturnsBodyWhileFresh(): void
    {
        $now = 1_700_000_000;
        $body = '{"properties":{"gridId":"PQR"}}';
        $this->assertTrue(nwsPointsCacheWrite(45.771, -122.86, $body, $now));
        $this->assertSame($body, nwsPointsCacheRead(45.771, -122.86, $now));
    }

    public function testCacheRead_ReturnsNullWhenExpired(): void
    {
        $fetchedAt = 1_700_000_000;
        $body = '{"properties":{}}';
        $this->assertTrue(nwsPointsCacheWrite(45.771, -122.86, $body, $fetchedAt));

        $expiredNow = $fetchedAt + NWS_POINTS_CACHE_TTL_SECONDS + 1;
        $this->assertNull(nwsPointsCacheRead(45.771, -122.86, $expiredNow));
    }

    public function testCacheRead_ReturnsNullForInvalidCoordinates(): void
    {
        $this->assertNull(nwsPointsCacheRead(100.0, 0.0));
        $this->assertFalse(nwsPointsCacheWrite(100.0, 0.0, '{}'));
    }

    public function testCacheRead_ReturnsNullWhenEnvelopeCoordinatesMismatch(): void
    {
        $now = 1_700_000_000;
        $path = nwsPointsCacheFilePath(nwsPointsCacheKey(45.771, -122.86));
        if (!is_dir(dirname($path))) {
            mkdir(dirname($path), 0755, true);
        }
        file_put_contents($path, json_encode([
            'fetched_at' => $now,
            'lat' => '99.9999',
            'lon' => '-122.8600',
            'body' => '{"properties":{"gridId":"BAD"}}',
        ], JSON_UNESCAPED_SLASHES));

        $this->assertNull(nwsPointsCacheRead(45.771, -122.86, $now));
    }

    public function testNwsFetchPoints_RefetchesWhenCacheBodyIsCorrupt(): void
    {
        require_once __DIR__ . '/../../lib/weather/adapter/nws-api-v1.php';

        $now = time();
        $path = nwsPointsCacheFilePath(nwsPointsCacheKey(45.771, -122.86));
        if (!is_dir(dirname($path))) {
            mkdir(dirname($path), 0755, true);
        }
        file_put_contents($path, json_encode([
            'fetched_at' => $now,
            'lat' => '45.7710',
            'lon' => '-122.8600',
            'body' => 'not-json',
        ], JSON_UNESCAPED_SLASHES));

        $result = nwsFetchPoints(45.771, -122.86);
        $this->assertIsArray($result);
        $this->assertSame('PQR', $result['properties']['gridId'] ?? null);
    }

    public function testNwsFetchPoints_WritesCacheAndReusesOnSecondCall(): void
    {
        require_once __DIR__ . '/../../lib/weather/adapter/nws-api-v1.php';

        $first = nwsFetchPoints(45.771, -122.86);
        $this->assertIsArray($first);
        $this->assertSame('PQR', $first['properties']['gridId'] ?? null);

        $cachePath = nwsPointsCacheFilePath(nwsPointsCacheKey(45.771, -122.86));
        $this->assertFileExists($cachePath);

        $second = nwsFetchPoints(45.771, -122.86);
        $this->assertSame($first, $second);
    }
}
