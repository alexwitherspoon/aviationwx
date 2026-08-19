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

    /**
     * @return list<array<string, mixed>>
     */
    private function sampleRing(string $airportId, string $bridgeId, string $sourceId): array
    {
        $path = getBridgeWeatherSamplesCachePath($airportId, $bridgeId, $sourceId);
        $this->assertNotSame('', $path);
        $this->assertFileExists($path);
        $raw = file_get_contents($path);
        $this->assertNotFalse($raw);
        $lines = array_values(array_filter(explode("\n", trim($raw))));
        $decoded = [];
        foreach ($lines as $line) {
            $row = json_decode($line, true);
            $this->assertIsArray($row);
            $decoded[] = $row;
        }
        return $decoded;
    }

    /**
     * @return array<string, mixed>
     */
    private function loadDiagnosticFixture(): array
    {
        $path = __DIR__ . '/../Fixtures/bridge/weather_post_davis_wll_missing_station_time.example.json';
        $this->assertFileExists($path);
        $json = json_decode((string) file_get_contents($path), true);
        $this->assertIsArray($json);
        return $json;
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

    public function testNormalize_RejectsFarFutureObservedAt(): void
    {
        $ts = time() + 120;
        $item = $this->rawObservation('station-a', $ts);
        $n = bridgeNormalizeWeatherItem($item);
        $this->assertFalse($n['ok']);
        $this->assertStringContainsString('future', $n['error']);
    }

    public function testNormalize_AcceptsObservedAtWithinSixtySecondSkew(): void
    {
        $ts = time() + 30;
        $item = $this->rawObservation('station-a', $ts);
        $n = bridgeNormalizeWeatherItem($item);
        $this->assertTrue($n['ok'], $n['error'] ?? '');
    }

    public function testNormalize_RejectsRawTsFarInFuture(): void
    {
        $item = $this->rawObservation('station-a', time());
        $item['provider_meta']['raw']['ts'] = time() + 120;
        $n = bridgeNormalizeWeatherItem($item);
        $this->assertFalse($n['ok']);
        $this->assertStringContainsString('raw.ts', $n['error'] ?? '');
    }

    public function testNormalize_RejectsObservedAtRawTsSkew(): void
    {
        $item = $this->rawObservation('station-a', time());
        $item['provider_meta']['raw']['ts'] = time() - 120;
        $n = bridgeNormalizeWeatherItem($item);
        $this->assertFalse($n['ok']);
        $this->assertStringContainsString('disagree', $n['error'] ?? '');
    }

    public function testStore_KeepsNewerLatestOnOutOfOrderPost(): void
    {
        $newer = $this->rawObservation('station-a', strtotime('2026-07-29T23:01:00Z'));
        $nNew = bridgeNormalizeWeatherItem($newer);
        $this->assertTrue(bridgeStoreWeatherObservation('kspb', 'bridge-1', $nNew['record']));

        $older = $this->rawObservation('station-a', strtotime('2026-07-29T23:00:00Z'));
        $nOld = bridgeNormalizeWeatherItem($older);
        $this->assertTrue(bridgeStoreWeatherObservation('kspb', 'bridge-1', $nOld['record']));

        $latest = bridgeLoadWeatherLatest('kspb', 'bridge-1', 'station-a');
        $this->assertNotNull($latest);
        $this->assertSame(strtotime('2026-07-29T23:01:00Z'), (int) $latest['observed_unix']);
    }

    public function testStore_KeepsFirstAtEqualObservedUnix(): void
    {
        $ts = strtotime('2026-07-29T23:00:00Z');
        $first = $this->rawObservation('station-a', $ts);
        $first['provider_meta']['raw']['conditions'][0]['temp'] = 60.0;
        $n1 = bridgeNormalizeWeatherItem($first);
        $this->assertTrue(bridgeStoreWeatherObservation('kspb', 'bridge-1', $n1['record']));

        $second = $this->rawObservation('station-a', $ts);
        $second['provider_meta']['raw']['conditions'][0]['temp'] = 99.0;
        $n2 = bridgeNormalizeWeatherItem($second);
        $this->assertTrue(bridgeStoreWeatherObservation('kspb', 'bridge-1', $n2['record']));

        $latest = bridgeLoadWeatherLatest('kspb', 'bridge-1', 'station-a');
        $this->assertNotNull($latest);
        $this->assertSame(60.0, (float) $latest['provider_meta']['raw']['conditions'][0]['temp']);
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
                    'provider_meta' => ['raw' => ['ts' => strtotime('2026-07-29T23:00:00Z'), 'conditions' => []]],
                ],
                [
                    'observed_at' => '2026-07-29T23:00:01Z',
                    'provider_meta' => ['raw' => ['ts' => strtotime('2026-07-29T23:00:01Z'), 'conditions' => []]],
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
        $this->assertTrue($n['ok'], $n['error'] ?? '');
    }

    public function testEnabledType_ReturnsNullWhenNotEnabled(): void
    {
        $airport = [
            'weather_sources' => [
                ['type' => 'tempest', 'station_id' => '1'],
            ],
        ];
        $this->assertNull(getBridgeEnabledWeatherSourceType($airport, 'bridge-1', 'station-a'));
        $this->assertFalse(isBridgeWeatherSourceEnabled($airport, 'bridge-1', 'station-a'));
    }

    public function testEnabledType_ReturnsDavisWhenEnabled(): void
    {
        $airport = [
            'weather_sources' => [
                [
                    'type' => 'davis_weatherlink_live',
                    'bridge_id' => 'bridge-1',
                    'bridge_source_id' => 'station-a',
                ],
            ],
        ];
        $this->assertSame(
            'davis_weatherlink_live',
            getBridgeEnabledWeatherSourceType($airport, 'bridge-1', 'station-a')
        );
    }

    public function testProviderMismatch_EnabledSourceTypeDiffersFromPost(): void
    {
        $airport = [
            'weather_sources' => [
                [
                    'type' => 'davis_weatherlink_live',
                    'bridge_id' => 'bridge-1',
                    'bridge_source_id' => 'station-a',
                ],
            ],
        ];
        $enabled = getBridgeEnabledWeatherSourceType($airport, 'bridge-1', 'station-a');
        $this->assertTrue(bridgeWeatherProviderMatchesEnable($enabled, 'davis_weatherlink_live'));
        $this->assertFalse(bridgeWeatherProviderMatchesEnable($enabled, 'other_provider'));
        $this->assertTrue(bridgeWeatherProviderMatchesEnable(null, 'other_provider'));
    }

    public function testPrepareBatch_ProviderMismatchOnEnabledFixtureSource(): void
    {
        $airport = [
            'weather_sources' => [
                [
                    'type' => 'davis_weatherlink_live',
                    'bridge_id' => 'bridge-spb-test-1',
                    'bridge_source_id' => 'station-davis-wll',
                    'station_id' => 'wx-spb-bridge-davis',
                    'txid' => 1,
                ],
            ],
        ];
        $item = $this->rawObservation('station-davis-wll', time() - 10);
        $item['provider'] = 'other_provider';
        $prepared = bridgePrepareWeatherIngestBatch([$item], 'bridge-spb-test-1', $airport);
        $this->assertFalse($prepared['ok']);
        $this->assertSame('PROVIDER_MISMATCH', $prepared['code'] ?? null);
        $this->assertStringContainsString('station-davis-wll', $prepared['error'] ?? '');
    }

    public function testPrepareBatch_AcceptsMatchingEnabledDavisProvider(): void
    {
        $airport = [
            'weather_sources' => [
                [
                    'type' => 'davis_weatherlink_live',
                    'bridge_id' => 'bridge-spb-test-1',
                    'bridge_source_id' => 'station-davis-wll',
                    'station_id' => 'wx-spb-bridge-davis',
                    'txid' => 1,
                ],
            ],
        ];
        $item = $this->rawObservation('station-davis-wll', time() - 10);
        $prepared = bridgePrepareWeatherIngestBatch([$item], 'bridge-spb-test-1', $airport);
        $this->assertTrue($prepared['ok'], $prepared['error'] ?? '');
        $this->assertCount(1, $prepared['pending']);
        $this->assertTrue($prepared['pending'][0]['enabled']);
        $this->assertSame('davis_weatherlink_live', $prepared['pending'][0]['record']['provider']);
    }

    public function testPrepareBatch_AllowsUnenabledMismatchedProvider(): void
    {
        $airport = [
            'weather_sources' => [
                [
                    'type' => 'tempest',
                    'station_id' => '1',
                ],
            ],
        ];
        $item = $this->rawObservation('station-unenabled', time() - 10);
        $item['provider'] = 'other_provider';
        $prepared = bridgePrepareWeatherIngestBatch([$item], 'bridge-spb-test-1', $airport);
        $this->assertTrue($prepared['ok'], $prepared['error'] ?? '');
        $this->assertFalse($prepared['pending'][0]['enabled']);
    }

    public function testStore_AppendsSamplesJsonl(): void
    {
        $n1 = bridgeNormalizeWeatherItem($this->rawObservation('station-a', strtotime('2026-07-29T23:00:00Z')));
        $n2 = bridgeNormalizeWeatherItem($this->rawObservation('station-a', strtotime('2026-07-29T23:00:01Z')));
        $this->assertTrue(bridgeStoreWeatherObservation('kspb', 'bridge-1', $n1['record']));
        $this->assertTrue(bridgeStoreWeatherObservation('kspb', 'bridge-1', $n2['record']));

        $path = getBridgeWeatherSamplesCachePath('kspb', 'bridge-1', 'station-a');
        $this->assertNotSame('', $path);
        $raw = file_get_contents($path);
        $this->assertNotFalse($raw);
        $lines = array_values(array_filter(explode("\n", trim($raw))));
        $this->assertCount(2, $lines);
    }

    public function testNormalize_RejectsUsableRowRawTsZero(): void
    {
        $item = $this->rawObservation('station-a', time() - 10);
        $item['provider_meta']['raw']['ts'] = 0;
        $n = bridgeNormalizeWeatherItem($item);
        $this->assertFalse($n['ok']);
        $this->assertSame('INVALID_REQUEST', $n['code'] ?? null);
        $this->assertStringContainsString('raw.ts', $n['error'] ?? '');
    }

    public function testNormalize_RejectsUsableRowRawTsNegative(): void
    {
        $item = $this->rawObservation('station-a', time() - 10);
        $item['provider_meta']['raw']['ts'] = -1;
        $n = bridgeNormalizeWeatherItem($item);
        $this->assertFalse($n['ok']);
        $this->assertStringContainsString('raw.ts', $n['error'] ?? '');
    }

    public function testStore_DiagnosticMissingStationTimeTsZero_RingOnlyLatestUnchanged(): void
    {
        $usableTs = time() - 20;
        $usable = $this->rawObservation('station-a', $usableTs);
        $nUsable = bridgeNormalizeWeatherItem($usable);
        $this->assertTrue($nUsable['ok'], $nUsable['error'] ?? '');
        $this->assertTrue(bridgeStoreWeatherObservation('kspb', 'bridge-1', $nUsable['record']));

        $item = $this->loadDiagnosticFixture();
        $item['source_id'] = 'station-a';
        $item['observed_at'] = gmdate('c');
        $n = bridgeNormalizeWeatherItem($item);
        $this->assertTrue($n['ok'], $n['error'] ?? '');
        $this->assertSame(0, (int) $n['record']['provider_meta']['raw']['ts']);
        $this->assertSame('missing_station_time', $n['record']['provider_meta']['error']);
        $this->assertTrue(bridgeStoreWeatherObservation('kspb', 'bridge-1', $n['record']));

        $latest = bridgeLoadWeatherLatest('kspb', 'bridge-1', 'station-a');
        $this->assertNotNull($latest);
        $this->assertSame($usableTs, (int) $latest['observed_unix']);
        $this->assertArrayNotHasKey('error', $latest['provider_meta']);
        $this->assertSame(64.9, (float) $latest['provider_meta']['raw']['conditions'][0]['temp']);

        $ring = $this->sampleRing('kspb', 'bridge-1', 'station-a');
        $this->assertCount(2, $ring);
        $this->assertSame('missing_station_time', $ring[1]['provider_meta']['error'] ?? null);
        $this->assertSame(0, (int) $ring[1]['provider_meta']['raw']['ts']);

        $buckets = bridgeLoadWeatherBuckets('kspb', 'bridge-1', 'station-a');
        $this->assertNotNull($buckets);
        $usableKey = (string) bridgeWeatherBucketStartUnix($usableTs);
        $this->assertSame(1, $buckets['buckets'][$usableKey]['count'] ?? null);
        $bucketCount = 0;
        foreach ($buckets['buckets'] as $bucket) {
            $bucketCount += (int) ($bucket['count'] ?? 0);
        }
        $this->assertSame(1, $bucketCount);

        $source = [
            'type' => 'davis_weatherlink_live',
            'bridge_id' => 'bridge-1',
            'bridge_source_id' => 'station-a',
            'txid' => 1,
        ];
        $airport = ['weather_sources' => [$source]];
        $snap = davisWeatherlinkLiveResolveSnapshot('kspb', $source, $airport);
        $this->assertNotNull($snap);
        $this->assertNull(DavisWeatherlinkLiveBridgeAdapter::snapshotFromLatestRecord($n['record'], ['txid' => 1]));
    }

    public function testStore_InterceptorMissingStationTime_AcceptedNoSnapshot(): void
    {
        $item = [
            'observed_at' => gmdate('c'),
            'source_id' => 'station-interceptor',
            'provider' => 'http_interceptor',
            'provider_meta' => [
                'api' => 'http_interceptor_wunderground_v1',
                'path' => '/weatherstation/updateweatherstation.php',
                'dialect' => 'wunderground',
                'error' => 'missing_station_time',
                'raw' => [
                    'dateutc' => 'now',
                    'tempf' => '62.7',
                    'humidity' => '55',
                    'windspeedmph' => '10',
                ],
            ],
        ];
        $n = bridgeNormalizeWeatherItem($item);
        $this->assertTrue($n['ok'], $n['error'] ?? '');
        $this->assertTrue(bridgeStoreWeatherObservation('kspb', 'bridge-1', $n['record']));

        $this->assertNull(bridgeLoadWeatherLatest('kspb', 'bridge-1', 'station-interceptor'));
        $ring = $this->sampleRing('kspb', 'bridge-1', 'station-interceptor');
        $this->assertCount(1, $ring);
        $this->assertSame('now', $ring[0]['provider_meta']['raw']['dateutc'] ?? null);
        $this->assertTrue(bridgeWeatherRecordIsDiagnostic($n['record']));
        $this->assertNull(bridgeLoadWeatherBuckets('kspb', 'bridge-1', 'station-interceptor'));
    }

    public function testStore_IdentityUnmatchedFullRaw_AcceptedNoSnapshot(): void
    {
        $usableTs = time() - 15;
        $usable = $this->rawObservation('station-a', $usableTs);
        $nUsable = bridgeNormalizeWeatherItem($usable);
        $this->assertTrue(bridgeStoreWeatherObservation('kspb', 'bridge-1', $nUsable['record']));

        $item = $this->rawObservation('station-a', $usableTs);
        $item['observed_at'] = gmdate('c');
        $item['provider_meta']['raw']['ts'] = 1531754005;
        $item['provider_meta']['error'] = 'identity_unmatched';
        $n = bridgeNormalizeWeatherItem($item);
        $this->assertTrue($n['ok'], $n['error'] ?? '');
        $this->assertSame('identity_unmatched', $n['record']['provider_meta']['error']);
        $this->assertSame(1531754005, (int) $n['record']['provider_meta']['raw']['ts']);
        $this->assertTrue(bridgeStoreWeatherObservation('kspb', 'bridge-1', $n['record']));

        $latest = bridgeLoadWeatherLatest('kspb', 'bridge-1', 'station-a');
        $this->assertNotNull($latest);
        $this->assertArrayNotHasKey('error', $latest['provider_meta']);
        $this->assertNull(DavisWeatherlinkLiveBridgeAdapter::snapshotFromLatestRecord($n['record'], ['txid' => 1]));
    }

    public function testStore_UsableRowReplacesDiagnosticLatest(): void
    {
        $diagTs = time();
        $diag = $this->rawObservation('station-a', $diagTs);
        $diag['provider_meta']['error'] = 'missing_station_time';
        $diag['provider_meta']['raw']['ts'] = 0;
        $nDiag = bridgeNormalizeWeatherItem($diag);
        $this->assertTrue($nDiag['ok'], $nDiag['error'] ?? '');

        $path = getBridgeWeatherLatestCachePath('kspb', 'bridge-1', 'station-a');
        $this->assertNotSame('', $path);
        $planted = $nDiag['record'];
        $planted['observed_unix'] = $diagTs;
        $this->assertTrue(bridgeWriteJsonFile($path, $planted));

        $usableTs = $diagTs - 30;
        $usable = $this->rawObservation('station-a', $usableTs);
        $nUsable = bridgeNormalizeWeatherItem($usable);
        $this->assertTrue($nUsable['ok'], $nUsable['error'] ?? '');
        $this->assertTrue(bridgeStoreWeatherObservation('kspb', 'bridge-1', $nUsable['record']));

        $latest = bridgeLoadWeatherLatest('kspb', 'bridge-1', 'station-a');
        $this->assertNotNull($latest);
        $this->assertArrayNotHasKey('error', $latest['provider_meta']);
        $this->assertSame($usableTs, (int) $latest['observed_unix']);
    }

    public function testNormalize_RejectsFutureObservedAtOnDiagnosticRow(): void
    {
        $item = $this->loadDiagnosticFixture();
        $item['observed_at'] = gmdate('c', time() + 120);
        $n = bridgeNormalizeWeatherItem($item);
        $this->assertFalse($n['ok']);
        $this->assertStringContainsString('future', $n['error'] ?? '');
    }

    public function testNormalize_DiagnosticDecodeFailedMayOmitRaw(): void
    {
        $item = [
            'observed_at' => gmdate('c'),
            'source_id' => 'station-a',
            'provider' => 'davis_weatherlink_live',
            'provider_meta' => [
                'api' => 'weatherlink_live_local_v1',
                'error' => 'decode_failed',
            ],
        ];
        $n = bridgeNormalizeWeatherItem($item);
        $this->assertTrue($n['ok'], $n['error'] ?? '');
        $this->assertSame('decode_failed', $n['record']['provider_meta']['error']);
        $this->assertArrayNotHasKey('raw', $n['record']['provider_meta']);
        $this->assertTrue(bridgeStoreWeatherObservation('kspb', 'bridge-1', $n['record']));
        $this->assertNull(bridgeLoadWeatherLatest('kspb', 'bridge-1', 'station-a'));
        $this->assertNull(DavisWeatherlinkLiveBridgeAdapter::snapshotFromLatestRecord($n['record'], ['txid' => 1]));
    }

    public function testNormalize_DiagnosticMissingStationTime_RejectsEmptyRaw(): void
    {
        $item = $this->loadDiagnosticFixture();
        $item['observed_at'] = gmdate('c');
        $item['provider_meta']['raw'] = [];
        $n = bridgeNormalizeWeatherItem($item);
        $this->assertFalse($n['ok']);
        $this->assertStringContainsString('provider_meta.raw', $n['error'] ?? '');
    }

    public function testNormalize_UnknownErrorString_TreatedAsDiagnostic(): void
    {
        $item = $this->rawObservation('station-a', time() - 10);
        $item['observed_at'] = gmdate('c');
        $item['provider_meta']['error'] = 'firmware_skew_example';
        $item['provider_meta']['raw']['ts'] = 0;
        $n = bridgeNormalizeWeatherItem($item);
        $this->assertTrue($n['ok'], $n['error'] ?? '');
        $this->assertSame('firmware_skew_example', $n['record']['provider_meta']['error']);
        $this->assertTrue(bridgeStoreWeatherObservation('kspb', 'bridge-1', $n['record']));
        $this->assertNull(bridgeLoadWeatherLatest('kspb', 'bridge-1', 'station-a'));
        $this->assertNull(DavisWeatherlinkLiveBridgeAdapter::snapshotFromLatestRecord($n['record'], ['txid' => 1]));
    }
}
