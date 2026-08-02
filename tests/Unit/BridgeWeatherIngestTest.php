<?php
/**
 * Unit tests for bridge weather store, enable gate, and adapter snapshot.
 */

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../lib/bridge/weather-store.php';
require_once __DIR__ . '/../../lib/bridge/config.php';
require_once __DIR__ . '/../../lib/weather/adapter/aviationwx-bridge-v1.php';
require_once __DIR__ . '/../../lib/cache-paths.php';

class BridgeWeatherIngestTest extends TestCase
{
    private string $tmpRoot;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tmpRoot = sys_get_temp_dir() . '/aviationwx_bridge_wx_' . uniqid('', true);
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
        foreach (scandir($dir) ?: [] as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            $path = $dir . '/' . $item;
            is_dir($path) ? $this->removeTree($path) : @unlink($path);
        }
        @rmdir($dir);
    }

    private function sampleItem(string $sourceId = 'station-scappoose-davis', ?float $temp = 18.3): array
    {
        return [
            'observed_at' => '2026-07-29T23:00:15Z',
            'source_id' => $sourceId,
            'provider' => 'davis_weatherlink_live',
            'sample' => [
                'temp_c' => $temp,
                'humidity_pct' => 72,
                'wind_speed_kt' => 6.1,
                'wind_gust_kt' => 9.0,
                'wind_dir_deg' => 270,
                'pressure_inhg' => 30.12,
                'rain_in' => 0.0,
            ],
        ];
    }

    public function testStoreSample_BuildsSixtySecondBuckets(): void
    {
        $n1 = bridgeNormalizeWeatherItem($this->sampleItem('station-a', 10.0));
        $this->assertTrue($n1['ok']);
        $this->assertTrue(bridgeStoreWeatherSample('kspb', 'bridge-1', $n1['record']));

        $item2 = $this->sampleItem('station-a', 12.0);
        $item2['observed_at'] = '2026-07-29T23:00:45Z';
        $n2 = bridgeNormalizeWeatherItem($item2);
        $this->assertTrue(bridgeStoreWeatherSample('kspb', 'bridge-1', $n2['record']));

        $item3 = $this->sampleItem('station-a', 20.0);
        $item3['observed_at'] = '2026-07-29T23:01:10Z';
        $n3 = bridgeNormalizeWeatherItem($item3);
        $this->assertTrue(bridgeStoreWeatherSample('kspb', 'bridge-1', $n3['record']));

        $buckets = bridgeLoadWeatherBuckets('kspb', 'bridge-1', 'station-a');
        $this->assertNotNull($buckets);
        $this->assertCount(2, $buckets['buckets']);

        $firstKey = (string) bridgeWeatherBucketStartUnix(strtotime('2026-07-29T23:00:15Z'));
        $this->assertArrayHasKey($firstKey, $buckets['buckets']);
        $this->assertSame(2, $buckets['buckets'][$firstKey]['count']);
        $this->assertEqualsWithDelta(11.0, $buckets['buckets'][$firstKey]['temp_c']['mean'], 0.001);
        $this->assertEqualsWithDelta(9.0, $buckets['buckets'][$firstKey]['wind_gust_kt']['max'], 0.001);

        $latest = bridgeLoadWeatherLatest('kspb', 'bridge-1', 'station-a');
        $this->assertEqualsWithDelta(20.0, (float) $latest['sample']['temp_c'], 0.001);
    }

    public function testEnableGate_AdapterNullWhenNotInWeatherSources(): void
    {
        $n = bridgeNormalizeWeatherItem($this->sampleItem());
        bridgeStoreWeatherSample('kspb', 'bridge-spb-1', $n['record']);

        $airport = [
            'weather_sources' => [
                ['type' => 'tempest', 'station_id' => '1', 'api_key' => 'x'],
            ],
        ];
        $snap = aviationwxBridgeResolveSnapshot('kspb', [
            'type' => 'aviationwx_bridge',
            'bridge_id' => 'bridge-spb-1',
            'bridge_source_id' => 'station-scappoose-davis',
        ], $airport);
        $this->assertNull($snap);
    }

    public function testEnableGate_AdapterReturnsSnapshotWhenEnabled(): void
    {
        $n = bridgeNormalizeWeatherItem($this->sampleItem());
        bridgeStoreWeatherSample('kspb', 'bridge-spb-1', $n['record']);

        $source = [
            'type' => 'aviationwx_bridge',
            'bridge_id' => 'bridge-spb-1',
            'bridge_source_id' => 'station-scappoose-davis',
            'station_id' => 'wx-spb-bridge-davis',
        ];
        $airport = ['weather_sources' => [$source]];
        $snap = aviationwxBridgeResolveSnapshot('kspb', $source, $airport);
        $this->assertNotNull($snap);
        $this->assertTrue($snap->isValid);
        $this->assertSame('aviationwx_bridge', $snap->source);
        $this->assertEqualsWithDelta(18.3, $snap->temperature->value, 0.01);
        $this->assertEqualsWithDelta(6.1, $snap->wind->speed->value, 0.01);
        $this->assertSame('wx-spb-bridge-davis', $snap->stationId);
    }

    public function testBatchExtract(): void
    {
        $body = [
            'bridge_id' => 'bridge-1',
            'source_id' => 'station-a',
            'samples' => [
                [
                    'observed_at' => '2026-07-29T23:00:00Z',
                    'sample' => ['temp_c' => 1.0],
                ],
                [
                    'observed_at' => '2026-07-29T23:00:01Z',
                    'sample' => ['temp_c' => 2.0],
                ],
            ],
        ];
        $extracted = bridgeExtractWeatherItems($body);
        $this->assertTrue($extracted['ok']);
        $this->assertCount(2, $extracted['items']);
        $this->assertSame('station-a', $extracted['items'][0]['source_id']);
    }
}
