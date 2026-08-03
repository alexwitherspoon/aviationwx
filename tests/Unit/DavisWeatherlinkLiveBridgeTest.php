<?php
/**
 * Unit tests for Davis WeatherLink Live bridge cache adapter.
 */

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../lib/bridge/weather-store.php';
require_once __DIR__ . '/../../lib/bridge/config.php';
require_once __DIR__ . '/../../lib/weather/adapter/davis-weatherlink-live-bridge.php';
require_once __DIR__ . '/../../lib/cache-paths.php';

class DavisWeatherlinkLiveBridgeTest extends TestCase
{
    private string $tmpRoot;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tmpRoot = sys_get_temp_dir() . '/aviationwx_davis_wll_' . uniqid('', true);
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
     * @return array<string, mixed>
     */
    private function loadGoldenPost(): array
    {
        $path = __DIR__ . '/../Fixtures/bridge/weather_post_davis_wll.example.json';
        $this->assertFileExists($path);
        $json = json_decode((string) file_get_contents($path), true);
        $this->assertIsArray($json);
        return $json;
    }

    public function testParseRaw_GoldenFixtureConvertsUnits(): void
    {
        $post = $this->loadGoldenPost();
        $parsed = DavisWeatherlinkLiveBridgeAdapter::parseRawData(
            $post['provider_meta']['raw'],
            $post['provider_meta'],
            ['txid' => 1]
        );
        $this->assertNotNull($parsed);
        // 62.7°F → °C
        $this->assertEqualsWithDelta((62.7 - 32) / 1.8, $parsed['temperature'], 0.01);
        $this->assertEqualsWithDelta(55.0, $parsed['humidity'], 0.01);
        $this->assertEqualsWithDelta((45.0 - 32) / 1.8, $parsed['dewpoint'], 0.01);
        // 10 mph → kt
        $this->assertEqualsWithDelta(10 * 0.868976, $parsed['wind_speed'], 0.01);
        $this->assertEqualsWithDelta(270.0, $parsed['wind_direction'], 0.01);
        $this->assertEqualsWithDelta(15 * 0.868976, $parsed['gust_speed'], 0.01);
        $this->assertEqualsWithDelta(30.008, $parsed['pressure'], 0.001);
        // rain_size 1 = 0.01"/click * 3 clicks
        $this->assertEqualsWithDelta(0.03, $parsed['precip_accum'], 0.0001);
        $this->assertSame(1531754005, $parsed['obs_time']);
    }

    public function testParseRaw_IgnoresBarAbsoluteFallback(): void
    {
        $raw = [
            'ts' => 1,
            'conditions' => [
                [
                    'data_structure_type' => 1,
                    'txid' => 1,
                    'temp' => 60,
                ],
                [
                    'data_structure_type' => 3,
                    'bar_absolute' => 29.5,
                ],
            ],
        ];
        $parsed = DavisWeatherlinkLiveBridgeAdapter::parseRawData($raw, ['txid' => 1], []);
        $this->assertNotNull($parsed);
        $this->assertNull($parsed['pressure']);
    }

    public function testParseRaw_RejectsMissingTxidWhenMultipleIss(): void
    {
        $raw = [
            'ts' => 1,
            'conditions' => [
                ['data_structure_type' => 1, 'txid' => 1, 'temp' => 60],
                ['data_structure_type' => 1, 'txid' => 2, 'temp' => 61],
            ],
        ];
        $this->assertNull(DavisWeatherlinkLiveBridgeAdapter::parseRawData($raw, [], []));
        $parsed = DavisWeatherlinkLiveBridgeAdapter::parseRawData($raw, ['txid' => 2], []);
        $this->assertNotNull($parsed);
        $this->assertEqualsWithDelta((61 - 32) / 1.8, $parsed['temperature'], 0.01);
    }

    public function testParseRaw_IgnoresAbsurdWindPlaceholders(): void
    {
        $raw = [
            'ts' => 1,
            'conditions' => [
                [
                    'data_structure_type' => 1,
                    'txid' => 1,
                    'temp' => 60,
                    'wind_speed_last' => 42606,
                    'wind_dir_last' => 90,
                ],
            ],
        ];
        $parsed = DavisWeatherlinkLiveBridgeAdapter::parseRawData($raw, ['txid' => 1], []);
        $this->assertNotNull($parsed);
        $this->assertNull($parsed['wind_speed']);
        $this->assertEqualsWithDelta(90.0, $parsed['wind_direction'], 0.01);
    }

    public function testResolveSnapshot_FromStoredGoldenAndEnableGate(): void
    {
        $post = $this->loadGoldenPost();
        $n = bridgeNormalizeWeatherItem($post);
        $this->assertTrue($n['ok']);
        $this->assertTrue(bridgeStoreWeatherObservation('kspb', 'bridge-spb-1', $n['record']));

        $source = [
            'type' => 'davis_weatherlink_live',
            'bridge_id' => 'bridge-spb-1',
            'bridge_source_id' => 'station-davis-wll',
            'station_id' => 'wx-spb-davis',
            'txid' => 1,
        ];
        $airport = ['weather_sources' => [$source], 'lat' => 45.77, 'lon' => -122.86];

        $snap = davisWeatherlinkLiveResolveSnapshot('kspb', $source, $airport);
        $this->assertNotNull($snap);
        $this->assertTrue($snap->isValid);
        $this->assertSame('davis_weatherlink_live', $snap->source);
        $this->assertEqualsWithDelta((62.7 - 32) / 1.8, $snap->temperature->value, 0.01);
        $this->assertEqualsWithDelta(10 * 0.868976, $snap->wind->speed->value, 0.01);
        $this->assertSame('wx-spb-davis', $snap->stationId);

        $disabledAirport = ['weather_sources' => []];
        $this->assertNull(davisWeatherlinkLiveResolveSnapshot('kspb', $source, $disabledAirport));
    }

    public function testSnapshot_IgnoresMagneticWindReference(): void
    {
        // Mis-aimed / magnetic vane must not be corrected in software.
        $post = $this->loadGoldenPost();
        $post['provider_meta']['wind_reference'] = 'magnetic';
        $n = bridgeNormalizeWeatherItem($post);
        bridgeStoreWeatherObservation('kspb', 'bridge-spb-1', $n['record']);

        $source = [
            'type' => 'davis_weatherlink_live',
            'bridge_id' => 'bridge-spb-1',
            'bridge_source_id' => 'station-davis-wll',
            'txid' => 1,
        ];
        $airport = [
            'weather_sources' => [$source],
            'magnetic_declination' => -15.0,
            'lat' => 45.77,
            'lon' => -122.86,
        ];
        $snap = davisWeatherlinkLiveResolveSnapshot('kspb', $source, $airport);
        $this->assertNotNull($snap);
        $this->assertEqualsWithDelta(270.0, $snap->wind->direction->value, 0.1);
    }

    public function testRainfallDailyInches_RainSizeVariants(): void
    {
        $this->assertEqualsWithDelta(
            0.02,
            DavisWeatherlinkLiveBridgeAdapter::rainfallDailyInches([
                'rainfall_daily' => 2,
                'rain_size' => 1,
            ]),
            0.0001
        );
        $this->assertEqualsWithDelta(
            2 * (0.2 / 25.4),
            DavisWeatherlinkLiveBridgeAdapter::rainfallDailyInches([
                'rainfall_daily' => 2,
                'rain_size' => 2,
            ]),
            0.0001
        );
        $this->assertNull(
            DavisWeatherlinkLiveBridgeAdapter::rainfallDailyInches([
                'rainfall_daily' => 2,
                'rain_size' => 0,
            ])
        );
    }
}
