<?php

declare(strict_types=1);

namespace AviationWX\Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * @covers ::nwsPointsCollectCoordinatesFromConfig
 * @covers ::nwsPointsCoordinateNeedsRefresh
 * @covers ::nwsPointsRefreshRun
 * @covers ::getNwsPointsRefreshLockPath
 */
final class NwsPointsRefreshTest extends TestCase
{
    private ?string $cacheRoot = null;

    protected function setUp(): void
    {
        parent::setUp();
        $this->cacheRoot = sys_get_temp_dir() . '/nws-points-refresh-test-' . bin2hex(random_bytes(4));
        mkdir($this->cacheRoot, 0755, true);
        $GLOBALS['nwsPointsCacheTestRoot'] = $this->cacheRoot;

        require_once __DIR__ . '/../../lib/cache-paths.php';
        require_once __DIR__ . '/../../lib/nws-points-cache.php';
        require_once __DIR__ . '/../../lib/nws-points-refresh.php';
    }

    protected function tearDown(): void
    {
        unset($GLOBALS['nwsPointsCacheTestRoot'], $GLOBALS['nwsPointsRefreshTestForceRun']);

        if ($this->cacheRoot !== null && is_dir($this->cacheRoot)) {
            $files = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($this->cacheRoot, \FilesystemIterator::SKIP_DOTS),
                \RecursiveIteratorIterator::CHILD_FIRST
            );
            foreach ($files as $file) {
                if ($file->isDir()) {
                    rmdir($file->getPathname());
                } else {
                    unlink($file->getPathname());
                }
            }
            rmdir($this->cacheRoot);
        }

        parent::tearDown();
    }

    public function testCollectCoordinates_DedupesByCacheKey(): void
    {
        $config = [
            'airports' => [
                'kspb' => [
                    'enabled' => true,
                    'lat' => 45.7710278,
                    'lon' => -122.8618333,
                    'weather_sources' => [['type' => 'nws', 'station_id' => 'KSPB']],
                ],
                'dup' => [
                    'enabled' => true,
                    'lat' => 45.7710278,
                    'lon' => -122.8618333,
                    'weather_sources' => [['type' => 'nws', 'station_id' => 'KVUO']],
                ],
            ],
        ];

        $coords = nwsPointsCollectCoordinatesFromConfig($config);
        $this->assertCount(1, $coords);
        $this->assertSame('45.7710,-122.8618', $coords[0]['cache_key']);
    }

    public function testCollectCoordinates_SkipsAirportWithoutNwsSource(): void
    {
        $config = [
            'airports' => [
                'kmet' => [
                    'enabled' => true,
                    'lat' => 40.0,
                    'lon' => -75.0,
                    'weather_sources' => [['type' => 'metar', 'station_id' => 'KPHL']],
                ],
            ],
        ];

        $this->assertSame([], nwsPointsCollectCoordinatesFromConfig($config));
    }

    public function testCollectCoordinates_SkipsInvalidCoordinates(): void
    {
        $config = [
            'airports' => [
                'bad' => [
                    'enabled' => true,
                    'lat' => 100.0,
                    'lon' => 0.0,
                    'weather_sources' => [['type' => 'nws', 'station_id' => 'KXXX']],
                ],
            ],
        ];

        $this->assertSame([], nwsPointsCollectCoordinatesFromConfig($config));
    }

    public function testCoordinateNeedsRefresh_TrueWhenCacheMissing(): void
    {
        $this->assertTrue(nwsPointsCoordinateNeedsRefresh(45.771, -122.86));
    }

    public function testCoordinateNeedsRefresh_FalseWhenCacheFresh(): void
    {
        $now = time();
        $body = '{"properties":{"gridId":"PQR"}}';
        $this->assertTrue(nwsPointsCacheWrite(45.771, -122.86, $body, $now));
        $this->assertFalse(nwsPointsCoordinateNeedsRefresh(45.771, -122.86, $now));
    }

    public function testRefreshRun_SkipsInTestModeByDefault(): void
    {
        $config = [
            'airports' => [
                'kspb' => [
                    'enabled' => true,
                    'lat' => 45.771,
                    'lon' => -122.86,
                    'weather_sources' => [['type' => 'nws', 'station_id' => 'KSPB']],
                ],
            ],
        ];

        $result = nwsPointsRefreshRun($config);
        $this->assertTrue($result['ok']);
        $this->assertSame('skipped_mock_or_test_mode', $result['note'] ?? null);
    }

    public function testRefreshRun_FetchesStaleCoordinatesWhenForced(): void
    {
        require_once __DIR__ . '/../../lib/weather/adapter/nws-api-v1.php';

        $GLOBALS['nwsPointsRefreshTestForceRun'] = true;

        $config = [
            'airports' => [
                'kspb' => [
                    'enabled' => true,
                    'lat' => 45.771,
                    'lon' => -122.86,
                    'weather_sources' => [['type' => 'nws', 'station_id' => 'KSPB']],
                ],
            ],
        ];

        $result = nwsPointsRefreshRun($config);
        $this->assertTrue($result['ok']);
        $this->assertSame(1, $result['coordinates']);
        $this->assertSame(1, $result['fetched']);
        $this->assertSame(0, $result['failed']);
        $this->assertFalse(nwsPointsCoordinateNeedsRefresh(45.771, -122.86));
    }

    public function testRefreshRun_SkipsFreshCoordinatesWhenForced(): void
    {
        $GLOBALS['nwsPointsRefreshTestForceRun'] = true;

        $now = time();
        $this->assertTrue(nwsPointsCacheWrite(45.771, -122.86, '{"properties":{"gridId":"PQR"}}', $now));

        $config = [
            'airports' => [
                'kspb' => [
                    'enabled' => true,
                    'lat' => 45.771,
                    'lon' => -122.86,
                    'weather_sources' => [['type' => 'nws', 'station_id' => 'KSPB']],
                ],
            ],
        ];

        $result = nwsPointsRefreshRun($config);
        $this->assertTrue($result['ok']);
        $this->assertSame(1, $result['skipped_fresh']);
        $this->assertSame(0, $result['fetched']);
    }

    public function testRefreshScript_Exists(): void
    {
        $this->assertFileExists(__DIR__ . '/../../scripts/refresh-nws-points.php');
    }
}
