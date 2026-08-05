<?php
/**
 * Unit tests for bridge fleet ops cache path helpers.
 */

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../lib/cache-paths.php';

class BridgeCachePathsTest extends TestCase
{
    private string $tmpRoot;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tmpRoot = sys_get_temp_dir() . '/aviationwx_bridge_cache_' . uniqid('', true);
        mkdir($this->tmpRoot, 0755, true);
        $GLOBALS['bridgeTestCacheRoot'] = $this->tmpRoot;
    }

    protected function tearDown(): void
    {
        unset($GLOBALS['bridgeTestCacheRoot']);
        $this->removeTree($this->tmpRoot);
        parent::tearDown();
    }

    private function removeTree(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        $items = scandir($dir);
        if ($items === false) {
            return;
        }
        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            $path = $dir . '/' . $item;
            if (is_dir($path)) {
                $this->removeTree($path);
            } else {
                @unlink($path);
            }
        }
        @rmdir($dir);
    }

    public function testGetBridgeCacheDir_UsesTestRoot(): void
    {
        $dir = getBridgeCacheDir('KSPB', 'bridge-spb-1');
        $this->assertSame($this->tmpRoot . '/kspb/bridge-spb-1', $dir);
    }

    public function testSanitizeRejectsTraversal(): void
    {
        $this->assertSame('', getBridgeCacheDir('../etc', 'bridge-1'));
        $this->assertSame('', getBridgeCacheDir('kspb', 'bridge/../x'));
        $this->assertSame('', getBridgeWeatherSourceCacheDir('kspb', 'bridge-1', 'a/b'));
    }

    public function testWeatherPaths(): void
    {
        $latest = getBridgeWeatherLatestCachePath('kspb', 'bridge-spb-1', 'station-davis');
        $this->assertSame(
            $this->tmpRoot . '/kspb/bridge-spb-1/weather/station-davis/latest.json',
            $latest
        );
        $this->assertStringEndsWith('/buckets.json', getBridgeWeatherBucketsCachePath('kspb', 'bridge-spb-1', 'station-davis'));
        $this->assertStringEndsWith('/health.json', getBridgeHealthCachePath('kspb', 'bridge-spb-1'));
        $this->assertStringEndsWith('/meta.json', getBridgeMetaCachePath('kspb', 'bridge-spb-1'));
    }
}
