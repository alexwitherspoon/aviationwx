<?php
/**
 * Unit tests for the MQTT canonical-schema bridge cache adapter.
 */

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../lib/bridge/weather-store.php';
require_once __DIR__ . '/../../lib/bridge/config.php';
require_once __DIR__ . '/../../lib/weather/adapter/mqtt-bridge.php';
require_once __DIR__ . '/../../lib/cache-paths.php';

class MqttBridgeTest extends TestCase
{
    private string $tmpRoot;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tmpRoot = sys_get_temp_dir() . '/aviationwx_mqtt_' . uniqid('', true);
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

    public function testParse_GoldenPayload(): void
    {
        $parsed = MqttBridgeAdapter::parseRawData([
            'temperature_c' => 21.5,
            'humidity' => 55,
            'pressure_inhg' => 30.01,
            'wind_kt' => 12,
            'gust_kt' => 18,
            'wind_dir_deg' => 270,
            'precip_in' => 0.03,
        ]);
        $this->assertNotNull($parsed);
        $this->assertEqualsWithDelta(21.5, $parsed['temperature'], 0.01);
        $this->assertEqualsWithDelta(55.0, $parsed['humidity'], 0.01);
        $this->assertEqualsWithDelta(30.01, $parsed['pressure'], 0.001);
        $this->assertEqualsWithDelta(12.0, $parsed['wind_speed'], 0.001);
        $this->assertEqualsWithDelta(18.0, $parsed['gust_speed'], 0.001);
        $this->assertEqualsWithDelta(270.0, $parsed['wind_direction'], 0.001);
        $this->assertEqualsWithDelta(0.03, $parsed['precip_accum'], 0.0001);
    }

    public function testParse_PartialOnlyWind(): void
    {
        $parsed = MqttBridgeAdapter::parseRawData([
            'wind_kt' => 8,
            'wind_dir_deg' => 90,
        ]);
        $this->assertNotNull($parsed);
        $this->assertEqualsWithDelta(8.0, $parsed['wind_speed'], 0.001);
        $this->assertEqualsWithDelta(90.0, $parsed['wind_direction'], 0.001);
        $this->assertNull($parsed['temperature']);
        $this->assertNull($parsed['pressure']);
    }

    public function testParse_UnknownKeysIgnored(): void
    {
        $parsed = MqttBridgeAdapter::parseRawData([
            'temperature_c' => 20.0,
            'somevendorfield' => 999,
            'other' => 'ignored',
        ]);
        $this->assertNotNull($parsed);
        $this->assertEqualsWithDelta(20.0, $parsed['temperature'], 0.01);
        $this->assertNull($parsed['humidity']);
    }

    public function testParse_AbsurdValuesRejected(): void
    {
        $parsed = MqttBridgeAdapter::parseRawData([
            'temperature_c' => 500.0,
            'humidity' => 150,
            'pressure_inhg' => 100.0,
            'wind_kt' => 5000,
            'wind_dir_deg' => 999,
            'precip_in' => -5,
        ]);
        $this->assertNotNull($parsed);
        $this->assertNull($parsed['temperature']);
        $this->assertNull($parsed['humidity']);
        $this->assertNull($parsed['pressure']);
        $this->assertNull($parsed['wind_speed']);
        $this->assertNull($parsed['wind_direction']);
        $this->assertNull($parsed['precip_accum']);
    }

    public function testParse_Direction360NormalizesToZero(): void
    {
        $parsed = MqttBridgeAdapter::parseRawData(['wind_dir_deg' => 360]);
        $this->assertNotNull($parsed);
        $this->assertEqualsWithDelta(0.0, $parsed['wind_direction'], 0.001);
    }

    public function testSnapshot_FromStoredRecord(): void
    {
        $record = [
            'observed_at' => '2020-05-08T13:36:54Z',
            'observed_unix' => 1588948614,
            'provider' => 'mqtt',
            'provider_meta' => [
                'api' => 'mqtt_v1',
                'topic' => 'weather/station1',
                'raw' => [
                    'temperature_c' => 21.5,
                    'humidity' => 55,
                    'pressure_inhg' => 30.01,
                    'wind_kt' => 12,
                    'gust_kt' => 18,
                    'wind_dir_deg' => 270,
                    'precip_in' => 0.03,
                ],
            ],
        ];
        $record['source_id'] = 'station-mqtt';
        $this->assertTrue(
            bridgeStoreWeatherObservation('kspb', 'bridge-spb', $record, time()),
            'store failed'
        );

        $source = [
            'type' => 'mqtt',
            'bridge_id' => 'bridge-spb',
            'bridge_source_id' => 'station-mqtt',
            'station_id' => 'wx-spb-mqtt',
        ];
        $snap = mqttResolveSnapshot('kspb', $source, [
            'bridges' => [],
            'weather_sources' => [$source],
        ]);
        $this->assertNotNull($snap);
        $this->assertTrue($snap->isValid);
        $this->assertEqualsWithDelta(21.5, $snap->temperature->getValue(), 0.01);
        $this->assertEqualsWithDelta(30.01, $snap->pressure->getValue(), 0.001);
        $this->assertEqualsWithDelta(12.0, $snap->wind->speed->getValue(), 0.001);
        $this->assertEqualsWithDelta(0.03, $snap->precipAccum->getValue(), 0.0001);
        $this->assertSame('wx-spb-mqtt', $snap->getStationIdForAttribution());
    }

    public function testSnapshot_RejectsWrongProvider(): void
    {
        $record = [
            'observed_at' => '2020-05-08T13:36:54Z',
            'provider' => 'some_other',
            'provider_meta' => ['raw' => ['temperature_c' => 20.0]],
        ];
        $this->assertNull(MqttBridgeAdapter::snapshotFromLatestRecord($record, []));
    }

    public function testSnapshot_NoUsableFieldsRejected(): void
    {
        $record = [
            'observed_at' => '2020-05-08T13:36:54Z',
            'observed_unix' => 1588948614,
            'provider' => 'mqtt',
            'provider_meta' => ['raw' => ['unknown' => 'junk']],
        ];
        $this->assertNull(MqttBridgeAdapter::snapshotFromLatestRecord($record, []));
    }

    public function testSnapshot_FutureObservedAtRejected(): void
    {
        $record = [
            'observed_at' => gmdate('c', time() + 120),
            'provider' => 'mqtt',
            'provider_meta' => ['raw' => ['temperature_c' => 20.0]],
        ];
        $this->assertNull(MqttBridgeAdapter::snapshotFromLatestRecord($record, []));
    }

    public function testSnapshot_InvalidObservedAtRejected(): void
    {
        $record = [
            'observed_at' => 'not-a-date',
            'provider' => 'mqtt',
            'provider_meta' => ['raw' => ['temperature_c' => 20.0]],
        ];
        $this->assertNull(MqttBridgeAdapter::snapshotFromLatestRecord($record, []));
    }

    public function testSnapshot_MissingObservedAtRejected(): void
    {
        $record = [
            'provider' => 'mqtt',
            'provider_meta' => ['raw' => ['temperature_c' => 20.0]],
        ];
        $this->assertNull(MqttBridgeAdapter::snapshotFromLatestRecord($record, []));
    }
}