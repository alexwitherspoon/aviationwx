<?php
/**
 * Unit tests for the WeatherFlow Tempest UDP bridge cache adapter.
 */

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../lib/bridge/weather-store.php';
require_once __DIR__ . '/../../lib/bridge/config.php';
require_once __DIR__ . '/../../lib/weather/adapter/tempest-udp-bridge.php';
require_once __DIR__ . '/../../lib/cache-paths.php';

class TempestUDPBridgeTest extends TestCase
{
    private string $tmpRoot;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tmpRoot = sys_get_temp_dir() . '/aviationwx_tempest_udp_' . uniqid('', true);
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
            is_dir($path) ? $this->removeTree($path) : unlink($path);
        }
        rmdir($dir);
    }

    private function storeRecord(string $airport, string $bridge, string $sourceId, array $record): void
    {
        $record['source_id'] = $sourceId;
        $this->assertTrue(
            bridgeStoreWeatherObservation($airport, $bridge, $record, time()),
            'store failed'
        );
    }

    public function testParse_ObsStConvertsUnits(): void
    {
        // WeatherFlow v171 fixture: obs_st with wind + temp + humidity + pressure.
        $raw = [
            'type' => 'obs_st',
            'obs' => [[1588948614, 0.18, 0.22, 0.27, 144, 6, 1017.57, 22.37, 50.26, 328, 0.03, 3, 0.0, 0, 0, 0, 2.410, 1]],
        ];
        $parsed = TempestUDPBridgeAdapter::parseRawData($raw, [], [], [
            'elevation_ft' => 100,
        ]);
        $this->assertNotNull($parsed);
        // 22.37 C stays C
        $this->assertEqualsWithDelta(22.37, $parsed['temperature'], 0.01);
        $this->assertEqualsWithDelta(50.26, $parsed['humidity'], 0.01);
        // 0.22 m/s -> kt (0.22 * 1.94384)
        $this->assertEqualsWithDelta(0.22 * 1.943844, $parsed['wind_speed'], 0.0001);
        $this->assertEqualsWithDelta(0.27 * 1.943844, $parsed['gust_speed'], 0.0001);
        $this->assertEqualsWithDelta(144.0, $parsed['wind_direction'], 0.001);
        // 1017.57 MB station pressure -> altimeter at 100 ft elevation
        $this->assertNotNull($parsed['pressure']);
        $this->assertGreaterThan(30.0, $parsed['pressure']);
        $this->assertLessThan(30.5, $parsed['pressure']);
        $this->assertSame(1588948614, $parsed['obs_time']);
    }

    public function testParse_ObsAirProvidesTempHumPressure(): void
    {
        $raw = [
            'type' => 'obs_air',
            'obs' => [[1493164835, 835.0, 10.0, 45, 0, 0, 3.46, 1]],
        ];
        $parsed = TempestUDPBridgeAdapter::parseRawData($raw, [], [], [
            'elevation_ft' => 5000,
        ]);
        $this->assertNotNull($parsed);
        $this->assertEqualsWithDelta(10.0, $parsed['temperature'], 0.01);
        $this->assertEqualsWithDelta(45.0, $parsed['humidity'], 0.01);
        $this->assertNotNull($parsed['pressure']);
        $this->assertNull($parsed['wind_speed']);
    }

    public function testParse_RapidWindProvidesWindOnly(): void
    {
        $raw = ['type' => 'rapid_wind', 'ob' => [1493322445, 2.3, 128]];
        $parsed = TempestUDPBridgeAdapter::parseRawData($raw, [], [], [
            'elevation_ft' => 100,
        ]);
        $this->assertNotNull($parsed);
        $this->assertEqualsWithDelta(2.3 * 1.943844, $parsed['wind_speed'], 0.0001);
        $this->assertEqualsWithDelta(128.0, $parsed['wind_direction'], 0.001);
        // No temp/hum/pressure in a rapid_wind datagram
        $this->assertNull($parsed['temperature']);
        $this->assertNull($parsed['humidity']);
        $this->assertNull($parsed['pressure']);
        $this->assertSame(1493322445, $parsed['obs_time']);
    }

    public function testParse_ObsSkyProvidesWindOnly(): void
    {
        // v171 fixture; wind avg at index 5, gust 6, dir 7. No temp/hum/pressure.
        $raw = [
            'type' => 'obs_sky',
            'obs' => [[1493321340, 9000, 10, 0.0, 2.6, 4.6, 7.4, 187, 3.12, 1, 130, null, 0, 3]],
        ];
        $parsed = TempestUDPBridgeAdapter::parseRawData($raw, [], [], [
            'elevation_ft' => 100,
        ]);
        $this->assertNotNull($parsed);
        $this->assertEqualsWithDelta(4.6 * 1.943844, $parsed['wind_speed'], 0.0001);
        $this->assertEqualsWithDelta(7.4 * 1.943844, $parsed['gust_speed'], 0.0001);
        $this->assertEqualsWithDelta(187.0, $parsed['wind_direction'], 0.001);
        $this->assertNull($parsed['temperature']);
        $this->assertNull($parsed['humidity']);
        $this->assertNull($parsed['pressure']);
        $this->assertSame(1493321340, $parsed['obs_time']);
    }

    public function testParse_InvalidEpochRejected(): void
    {
        $raw = ['type' => 'rapid_wind', 'ob' => [0, 2.3, 128]];
        $this->assertNull(TempestUDPBridgeAdapter::parseRawData($raw, [], [], []));
    }

    public function testParse_FutureEpochRejected(): void
    {
        $raw = ['type' => 'rapid_wind', 'ob' => [time() + 99999, 2.3, 128]];
        $this->assertNull(TempestUDPBridgeAdapter::parseRawData($raw, [], [], []));
    }

    public function testParse_StatusDatagramRejected(): void
    {
        $raw = [
            'type' => 'device_status',
            'serial_number' => 'AR-00004049',
            'hub_sn' => 'HB-00000001',
            'timestamp' => 1510855923,
        ];
        $this->assertNull(TempestUDPBridgeAdapter::parseRawData($raw, [], [], []));
    }

    public function testParse_MissingPressureWhenNoElevation(): void
    {
        $raw = [
            'type' => 'obs_st',
            'obs' => [[1588948614, 0.18, 0.22, 0.27, 144, 6, 1017.57, 22.37, 50.26, 328, 0.03, 3, 0.0, 0, 0, 0, 2.410, 1]],
        ];
        $parsed = TempestUDPBridgeAdapter::parseRawData($raw, [], [], []);
        $this->assertNotNull($parsed);
        $this->assertNull($parsed['pressure']);
        $this->assertEqualsWithDelta(22.37, $parsed['temperature'], 0.01);
    }

    public function testParse_AbsurdWindRejected(): void
    {
        $raw = ['type' => 'rapid_wind', 'ob' => [1493322445, 500.0, 128]];
        $parsed = TempestUDPBridgeAdapter::parseRawData($raw, [], [], []);
        $this->assertNotNull($parsed);
        $this->assertNull($parsed['wind_speed']);
    }

    public function testSnapshot_FromStoredRecord(): void
    {
        $record = [
            'observed_at' => '2020-05-08T13:36:54Z',
            'observed_unix' => 1588948614,
            'provider' => 'tempest_udp',
            'provider_meta' => [
                'api' => 'weatherflow_tempest_udp_v1',
                'raw' => [
                    'type' => 'obs_st',
                    'obs' => [[1588948614, 1.0, 5.0, 9.0, 220, 6, 1017.57, 18.5, 61.0, 250, 0.1, 7, 0.4, 0, 0, 0, 2.4, 1]],
                ],
            ],
        ];
        $this->storeRecord('kspb', 'bridge-spb', 'station-tempest', $record);

        $source = [
            'type' => 'tempest_udp',
            'bridge_id' => 'bridge-spb',
            'bridge_source_id' => 'station-tempest',
            'station_id' => 'wx-spb-tempest',
        ];
        $snap = tempestUDPResolveSnapshot('kspb', $source, [
            'bridges' => [],
            'elevation_ft' => 100,
            'weather_sources' => [$source],
        ]);
        $this->assertNotNull($snap);
        $this->assertTrue($snap->isValid);
        $this->assertEqualsWithDelta(18.5, $snap->temperature->getValue(), 0.01);
        $this->assertEqualsWithDelta(61.0, $snap->humidity->getValue(), 0.01);
        $this->assertNotNull($snap->pressure);
        $this->assertNotNull($snap->wind->speed->getValue());
        $this->assertSame('wx-spb-tempest', $snap->getStationIdForAttribution());
    }

    public function testSnapshot_RejectsWrongProvider(): void
    {
        $record = [
            'observed_at' => '2020-05-08T13:36:54Z',
            'provider' => 'some_other_provider',
            'provider_meta' => [
                'raw' => ['type' => 'obs_st', 'obs' => [[1588948614, 1.0, 5.0, 9.0, 220, 6, 1017.57, 18.5, 61.0, 0, 0, 0, 0, 0, 0, 0, 2.4, 1]]],
            ],
        ];
        $this->assertNull(
            TempestUDPBridgeAdapter::snapshotFromLatestRecord($record, [], [])
        );
    }

    public function testSnapshot_NoUsableFieldsRejected(): void
    {
        $record = [
            'observed_at' => '2020-05-08T13:36:54Z',
            'provider' => 'tempest_udp',
            'provider_meta' => [
                'raw' => ['type' => 'device_status', 'serial_number' => 'ST-00000512'],
            ],
        ];
        $this->assertNull(
            TempestUDPBridgeAdapter::snapshotFromLatestRecord($record, [], [])
        );
    }
}