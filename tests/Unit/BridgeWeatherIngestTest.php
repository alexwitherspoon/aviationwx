<?php
/**
 * Unit tests for bridge weather ingest (raw-only wire) and enable gate.
 */

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../lib/bridge/weather-store.php';
require_once __DIR__ . '/../../lib/bridge/config.php';
require_once __DIR__ . '/../../lib/weather/adapter/davis-weatherlink-live-bridge.php';
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

    /**
     * Minimal Davis-shaped observation matching the bridge wire contract.
     *
     * @return array<string, mixed>
     */
    private function rawObservation(
        string $sourceId = 'station-scappoose-davis',
        int $ts = 1531754005
    ): array {
        return [
            'observed_at' => gmdate('c', $ts),
            'source_id' => $sourceId,
            'provider' => 'davis_weatherlink_live',
            'provider_meta' => [
                'api' => 'weatherlink_live_local_v1',
                'path' => '/v1/current_conditions',
                'txid' => 1,
                'wind_reference' => 'true',
                'did' => '001D0A700002',
                'raw' => [
                    'did' => '001D0A700002',
                    'ts' => $ts,
                    'conditions' => [
                        [
                            'lsid' => 318687,
                            'data_structure_type' => 1,
                            'txid' => 1,
                            'temp' => 64.9,
                            'hum' => 48.8,
                            'wind_speed_last' => 5.0,
                            'wind_dir_last' => 270,
                        ],
                        [
                            'lsid' => 318686,
                            'data_structure_type' => 3,
                            'bar_sea_level' => 30.082,
                        ],
                    ],
                ],
            ],
        ];
    }

    public function testNormalize_RequiresProviderMetaRaw(): void
    {
        $ok = bridgeNormalizeWeatherItem($this->rawObservation());
        $this->assertTrue($ok['ok']);
        $this->assertSame('davis_weatherlink_live', $ok['record']['provider']);
        $this->assertArrayHasKey('raw', $ok['record']['provider_meta']);
        $this->assertArrayNotHasKey('sample', $ok['record']);
        $this->assertSame(64.9, $ok['record']['provider_meta']['raw']['conditions'][0]['temp']);
    }

    public function testNormalize_RejectsMissingRaw(): void
    {
        $item = $this->rawObservation();
        unset($item['provider_meta']['raw']);
        $n = bridgeNormalizeWeatherItem($item);
        $this->assertFalse($n['ok']);
        $this->assertStringContainsString('provider_meta.raw', $n['error']);
    }

    public function testNormalize_RejectsEmptyRaw(): void
    {
        $item = $this->rawObservation();
        $item['provider_meta']['raw'] = [];
        $n = bridgeNormalizeWeatherItem($item);
        $this->assertFalse($n['ok']);
    }

    public function testNormalize_RejectsMissingProvider(): void
    {
        $item = $this->rawObservation();
        unset($item['provider']);
        $n = bridgeNormalizeWeatherItem($item);
        $this->assertFalse($n['ok']);
        $this->assertStringContainsString('provider', $n['error']);
    }

    public function testNormalize_IgnoresLegacySampleKey(): void
    {
        $item = $this->rawObservation();
        $item['sample'] = ['temp_c' => 18.3];
        $n = bridgeNormalizeWeatherItem($item);
        $this->assertTrue($n['ok']);
        $this->assertArrayNotHasKey('sample', $n['record']);
    }

    public function testStore_PersistsRawAndCountBuckets(): void
    {
        $n1 = bridgeNormalizeWeatherItem($this->rawObservation('station-a', strtotime('2026-07-29T23:00:15Z')));
        $this->assertTrue($n1['ok']);
        $this->assertTrue(bridgeStoreWeatherObservation('kspb', 'bridge-1', $n1['record']));

        $item2 = $this->rawObservation('station-a', strtotime('2026-07-29T23:00:45Z'));
        $n2 = bridgeNormalizeWeatherItem($item2);
        $this->assertTrue(bridgeStoreWeatherObservation('kspb', 'bridge-1', $n2['record']));

        $item3 = $this->rawObservation('station-a', strtotime('2026-07-29T23:01:10Z'));
        $n3 = bridgeNormalizeWeatherItem($item3);
        $this->assertTrue(bridgeStoreWeatherObservation('kspb', 'bridge-1', $n3['record']));

        $buckets = bridgeLoadWeatherBuckets('kspb', 'bridge-1', 'station-a');
        $this->assertNotNull($buckets);
        $this->assertCount(2, $buckets['buckets']);

        $firstKey = (string) bridgeWeatherBucketStartUnix(strtotime('2026-07-29T23:00:15Z'));
        $this->assertArrayHasKey($firstKey, $buckets['buckets']);
        $this->assertSame(2, $buckets['buckets'][$firstKey]['count']);
        $this->assertSame('davis_weatherlink_live', $buckets['buckets'][$firstKey]['last_provider']);
        $this->assertArrayNotHasKey('temp_c', $buckets['buckets'][$firstKey]);

        $latest = bridgeLoadWeatherLatest('kspb', 'bridge-1', 'station-a');
        $this->assertNotNull($latest);
        $this->assertSame('davis_weatherlink_live', $latest['provider']);
        $this->assertArrayHasKey('conditions', $latest['provider_meta']['raw']);
        $this->assertArrayNotHasKey('sample', $latest);
    }

    public function testEnableGate_ResolveNullWhenNotInWeatherSources(): void
    {
        $n = bridgeNormalizeWeatherItem($this->rawObservation());
        bridgeStoreWeatherObservation('kspb', 'bridge-spb-1', $n['record']);

        $airport = [
            'weather_sources' => [
                ['type' => 'tempest', 'station_id' => '1', 'api_key' => 'x'],
            ],
        ];
        $snap = davisWeatherlinkLiveResolveSnapshot('kspb', [
            'type' => 'davis_weatherlink_live',
            'bridge_id' => 'bridge-spb-1',
            'bridge_source_id' => 'station-scappoose-davis',
        ], $airport);
        $this->assertNull($snap);
    }

    public function testEnableGate_DavisResolveWhenEnabled(): void
    {
        $n = bridgeNormalizeWeatherItem($this->rawObservation());
        bridgeStoreWeatherObservation('kspb', 'bridge-spb-1', $n['record']);

        $source = [
            'type' => 'davis_weatherlink_live',
            'bridge_id' => 'bridge-spb-1',
            'bridge_source_id' => 'station-scappoose-davis',
            'station_id' => 'wx-spb-bridge-davis',
            'txid' => 1,
        ];
        $airport = ['weather_sources' => [$source]];
        $this->assertTrue(isBridgeWeatherSourceEnabled($airport, 'bridge-spb-1', 'station-scappoose-davis'));
        $snap = davisWeatherlinkLiveResolveSnapshot('kspb', $source, $airport);
        $this->assertNotNull($snap);
        $this->assertTrue($snap->isValid);
        $this->assertSame('davis_weatherlink_live', $snap->source);
    }

    public function testBatchExtract(): void
    {
        $body = [
            'bridge_id' => 'bridge-1',
            'source_id' => 'station-a',
            'provider' => 'davis_weatherlink_live',
            'samples' => [
                [
                    'observed_at' => '2026-07-29T23:00:00Z',
                    'provider_meta' => ['raw' => ['ts' => 1, 'conditions' => []]],
                ],
                [
                    'observed_at' => '2026-07-29T23:00:01Z',
                    'provider_meta' => ['raw' => ['ts' => 2, 'conditions' => []]],
                ],
            ],
        ];
        $extracted = bridgeExtractWeatherItems($body);
        $this->assertTrue($extracted['ok']);
        $this->assertCount(2, $extracted['items']);
        $this->assertSame('station-a', $extracted['items'][0]['source_id']);
        $this->assertSame('davis_weatherlink_live', $extracted['items'][0]['provider']);

        $n = bridgeNormalizeWeatherItem($extracted['items'][0]);
        // Empty conditions array is fine; raw itself is non-empty
        $this->assertTrue($n['ok']);
    }
}
