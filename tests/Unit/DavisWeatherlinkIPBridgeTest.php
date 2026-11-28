<?php
/**
 * Unit tests for the Davis WeatherLinkIP LOOP1 bridge cache adapter.
 */

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../lib/bridge/weather-store.php';
require_once __DIR__ . '/../../lib/bridge/config.php';
require_once __DIR__ . '/../../lib/weather/adapter/davis-weatherlink-ip-bridge.php';
require_once __DIR__ . '/../../lib/cache-paths.php';

class DavisWeatherlinkIPBridgeTest extends TestCase
{
    private string $tmpRoot;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tmpRoot = sys_get_temp_dir() . '/aviationwx_wlip_' . uniqid('', true);
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

    private static function le16(string $s, int $off, int $v): string
    {
        $s[$off] = chr($v & 0xff);
        $s[$off + 1] = chr(($v >> 8) & 0xff);
        return $s;
    }

    private static function crc16(string $data): int
    {
        $crc = 0;
        $len = strlen($data);
        for ($i = 0; $i < $len; $i++) {
            $crc ^= ord($data[$i]) << 8;
            for ($bit = 0; $bit < 8; $bit++) {
                if ($crc & 0x8000) {
                    $crc = (($crc << 1) ^ 0x1021) & 0xffff;
                } else {
                    $crc = ($crc << 1) & 0xffff;
                }
            }
        }
        return $crc;
    }

    /**
     * Build a CRC-valid 99-byte LOOP1 packet with the given field values.
     */
    private static function buildLoopPacket(array $fields): string
    {
        $pkt = str_repeat("\x00", 99);
        $pkt[0] = 'L';
        $pkt[1] = 'O';
        $pkt[2] = 'O';
        $pkt[4] = "\x00"; // packet_type: LOOP1

        // barometer inHg * 1000 at 7, outTemp F * 10 signed at 12
        $pkt = self::le16($pkt, 7, $fields['barometer'] ?? 30008);
        $pkt = self::le16($pkt, 12, $fields['outTemp'] ?? 701);
        $pkt[14] = chr($fields['windSpeed'] ?? 12);       // mph
        $pkt = self::le16($pkt, 16, $fields['windDir'] ?? 270);
        $pkt[33] = chr($fields['outHumidity'] ?? 55);     // %
        $pkt = self::le16($pkt, 50, $fields['dayRain'] ?? 3); // 0.01 in

        $crc = self::crc16(substr($pkt, 0, 94));
        $pkt[94] = chr(($crc >> 8) & 0xff);
        $pkt[95] = chr($crc & 0xff);
        return $pkt;
    }

    public function testParse_GoldenPacketConvertsUnits(): void
    {
        $packet = self::buildLoopPacket([]);
        // Sanity: the fixture CRC validates
        $this->assertSame(0, self::crc16($packet));

        $raw = ['loop_base64' => base64_encode($packet)];
        $parsed = DavisWeatherlinkIPBridgeAdapter::parseRawData($raw, [], []);
        $this->assertNotNull($parsed);
        // 30.008 inHg stays as-is
        $this->assertEqualsWithDelta(30.008, $parsed['pressure'], 0.001);
        // 70.1 F -> C
        $this->assertEqualsWithDelta((70.1 - 32) / 1.8, $parsed['temperature'], 0.01);
        $this->assertEqualsWithDelta(55.0, $parsed['humidity'], 0.01);
        // 12 mph -> kt
        $this->assertEqualsWithDelta(12 * 0.868976, $parsed['wind_speed'], 0.001);
        $this->assertEqualsWithDelta(270.0, $parsed['wind_direction'], 0.001);
        // day rain 3 * 0.01 in
        $this->assertEqualsWithDelta(0.03, $parsed['precip_accum'], 0.0001);
    }

    public function testParse_CrcMismatchRejected(): void
    {
        $packet = self::buildLoopPacket([]);
        // Flip a data byte after CRC was computed
        $packet[12] = chr(ord($packet[12]) ^ 0x01);
        $raw = ['loop_base64' => base64_encode($packet)];
        $this->assertNull(DavisWeatherlinkIPBridgeAdapter::parseRawData($raw, [], []));
    }

    public function testParse_BadHeaderRejected(): void
    {
        $packet = self::buildLoopPacket([]);
        $packet[0] = 'X';
        // Recompute CRC so only the header is wrong
        $crc = self::crc16(substr($packet, 0, 94));
        $packet[94] = chr(($crc >> 8) & 0xff);
        $packet[95] = chr($crc & 0xff);
        $raw = ['loop_base64' => base64_encode($packet)];
        $this->assertNull(DavisWeatherlinkIPBridgeAdapter::parseRawData($raw, [], []));
    }

    public function testParse_BadLengthRejected(): void
    {
        $raw = ['loop_base64' => base64_encode('LOO-not-a-full-packet')];
        $this->assertNull(DavisWeatherlinkIPBridgeAdapter::parseRawData($raw, [], []));
    }

    public function testParse_MissingRawRejected(): void
    {
        $this->assertNull(DavisWeatherlinkIPBridgeAdapter::parseRawData([], [], [], 1));
    }

    public function testParse_NoTemperatureMarkerRejectedField(): void
    {
        $packet = self::buildLoopPacket(['outTemp' => 0x7fff]);
        $raw = ['loop_base64' => base64_encode($packet)];
        $parsed = DavisWeatherlinkIPBridgeAdapter::parseRawData($raw, [], []);
        $this->assertNotNull($parsed);
        $this->assertNull($parsed['temperature']);
        $this->assertEqualsWithDelta(30.008, $parsed['pressure'], 0.001);
    }

    public function testSnapshot_FromStoredRecord(): void
    {
        $packet = self::buildLoopPacket([]);
        $now = time() - 30;
        $record = [
            'observed_at' => gmdate('c', $now),
            'observed_unix' => $now,
            'provider' => 'davis_weatherlink_ip',
            'provider_meta' => [
                'api' => 'vantage_serial_v1',
                'raw' => ['loop_base64' => base64_encode($packet)],
            ],
        ];
        $record['source_id'] = 'station-wlip';
        $this->assertTrue(
            bridgeStoreWeatherObservation('kspb', 'bridge-spb', $record, time()),
            'store failed'
        );

        $source = [
            'type' => 'davis_weatherlink_ip',
            'bridge_id' => 'bridge-spb',
            'bridge_source_id' => 'station-wlip',
            'station_id' => 'wx-spb-wlip',
        ];
        $snap = davisWeatherlinkIPResolveSnapshot('kspb', $source, [
            'bridges' => [],
            'elevation_ft' => 100,
            'timezone' => 'America/Chicago',
            'weather_sources' => [$source],
        ]);
        $this->assertNotNull($snap);
        $this->assertTrue($snap->isValid);
        $this->assertEqualsWithDelta((70.1 - 32) / 1.8, $snap->temperature->getValue(), 0.01);
        $this->assertEqualsWithDelta(30.008, $snap->pressure->getValue(), 0.001);
        $this->assertEqualsWithDelta(12 * 0.868976, $snap->wind->speed->getValue(), 0.001);
        $this->assertEqualsWithDelta(0.03, $snap->precipAccum->getValue(), 0.0001);
        $this->assertSame('wx-spb-wlip', $snap->getStationIdForAttribution());
    }

    public function testSnapshot_LocalConsoleNormalizedToUtc(): void
    {
        // A console running the site's local time displays the wall clock; the
        // naive GETTIME value is off UTC by the site offset. The framing must
        // identify it as local and normalize the epoch back to the true instant.
        $now = time() - 30;
        $tz = new DateTimeZone('America/Chicago');
        $offset = $tz->getOffset(new DateTime('@' . $now)); // negative west, DST-aware
        $naive = $now + $offset; // what a local console displays at instant $now

        $packet = self::buildLoopPacket([]);
        $record = [
            'observed_at' => gmdate('c', $naive),
            'observed_unix' => $naive,
            'provider' => 'davis_weatherlink_ip',
            'provider_meta' => [
                'api' => 'vantage_serial_v1',
                'raw' => ['loop_base64' => base64_encode($packet)],
            ],
        ];
        $record['source_id'] = 'station-wlip';
        $this->assertTrue(bridgeStoreWeatherObservation('kspb', 'bridge-spb', $record, time()));

        $source = [
            'type' => 'davis_weatherlink_ip',
            'bridge_id' => 'bridge-spb',
            'bridge_source_id' => 'station-wlip',
        ];
        $snap = davisWeatherlinkIPResolveSnapshot('kspb', $source, [
            'bridges' => [],
            'timezone' => 'America/Chicago',
            'weather_sources' => [$source],
        ]);
        $this->assertNotNull($snap);
        $this->assertTrue($snap->isValid);
        // The naive local value normalizes back to the true instant.
        $this->assertEqualsWithDelta($now, $snap->temperature->observationTime ?? 0, 5);
    }

    public function testSnapshot_UnidentifiableClockRejected(): void
    {
        // 47 minutes off matches neither UTC nor the site frame: fail closed.
        $now = time() - 47 * 60;
        $packet = self::buildLoopPacket([]);
        $record = [
            'observed_at' => gmdate('c', $now),
            'provider' => 'davis_weatherlink_ip',
            'provider_meta' => [
                'raw' => ['loop_base64' => base64_encode($packet)],
            ],
        ];
        $this->assertNull(DavisWeatherlinkIPBridgeAdapter::snapshotFromLatestRecord($record, [], [
            'timezone' => 'America/Chicago',
        ]));
    }

    public function testSnapshot_RejectsWrongProvider(): void
    {
        $packet = self::buildLoopPacket([]);
        $record = [
            'observed_at' => '2020-05-08T13:36:54Z',
            'provider' => 'some_other',
            'provider_meta' => [
                'raw' => ['loop_base64' => base64_encode($packet)],
            ],
        ];
        $this->assertNull(DavisWeatherlinkIPBridgeAdapter::snapshotFromLatestRecord($record, []));
    }

    public function testSnapshot_MissingObservedAtRejected(): void
    {
        $packet = self::buildLoopPacket([]);
        $record = [
            'provider' => 'davis_weatherlink_ip',
            'provider_meta' => [
                'raw' => ['loop_base64' => base64_encode($packet)],
            ],
        ];
        $this->assertNull(DavisWeatherlinkIPBridgeAdapter::snapshotFromLatestRecord($record, []));
    }

    public function testSnapshot_FutureObservedAtRejected(): void
    {
        // A console clock ahead of wall time would make stale cache look fresh;
        // the future-skew guard must reject it.
        $packet = self::buildLoopPacket([]);
        $record = [
            'observed_at' => gmdate('c', time() + 120),
            'provider' => 'davis_weatherlink_ip',
            'provider_meta' => [
                'raw' => ['loop_base64' => base64_encode($packet)],
            ],
        ];
        $this->assertNull(DavisWeatherlinkIPBridgeAdapter::snapshotFromLatestRecord($record, []));
    }
}