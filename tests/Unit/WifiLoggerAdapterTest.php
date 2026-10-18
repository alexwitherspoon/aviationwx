<?php
/**
 * Unit tests for the WiFiLogger cache-backed adapter (Davis-native integer scaling).
 */

use PHPUnit\Framework\TestCase;
use AviationWX\Weather\Data\WeatherSnapshot;

require_once __DIR__ . '/../../lib/weather/adapter/wifilogger.php';
require_once __DIR__ . '/../../lib/upload/store.php';

class WifiLoggerAdapterTest extends TestCase
{
    public function testParseRawData_ConvertsDavisNativeUnits(): void
    {
        $raw = [
            'type' => 'rtd',
            'wid' => 'station-davis-01',
            'tutc' => 1531754005,
            'tempout' => 678,
            'dew' => 520,
            'humout' => 55,
            'altime' => 29968,
            'windspd' => 8,
            'winddir' => 210,
            'gust' => 14,
            'raindly' => 3,
        ];
        $parsed = WifiLoggerAdapter::parseRawData($raw);
        $this->assertNotNull($parsed);
        $this->assertEqualsWithDelta(fahrenheitToCelsius(67.8), $parsed['temperature'], 0.001);
        $this->assertEqualsWithDelta(fahrenheitToCelsius(52.0), $parsed['dewpoint'], 0.001);
        $this->assertSame(55.0, $parsed['humidity']);
        $this->assertEqualsWithDelta(29.968, $parsed['pressure'], 0.0001);
        $this->assertEqualsWithDelta(mphToKnots(8), $parsed['wind_speed'], 0.001);
        $this->assertSame(210.0, $parsed['wind_direction']);
        $this->assertEqualsWithDelta(mphToKnots(14), $parsed['gust_speed'], 0.001);
        $this->assertEqualsWithDelta(0.03, $parsed['precip_accum'], 0.0001);
        $this->assertSame(1531754005, $parsed['obs_time']);
    }

    public function testParseRawData_PrefersAltimeterOverBarometer(): void
    {
        $raw = [
            'type' => 'rtd',
            'tutc' => 1531754005,
            'altime' => 29968,
            'bar' => 28500,
        ];
        $parsed = WifiLoggerAdapter::parseRawData($raw);
        $this->assertEqualsWithDelta(29.968, $parsed['pressure'], 0.0001);

        unset($raw['altime']);
        $parsed = WifiLoggerAdapter::parseRawData($raw);
        $this->assertEqualsWithDelta(28.5, $parsed['pressure'], 0.0001);
    }

    public function testParseRawData_FallsBackToBarWhenAltimeterOutOfRange(): void
    {
        $parsed = WifiLoggerAdapter::parseRawData([
            'type' => 'rtd',
            'tutc' => 1531754005,
            'altime' => 0,
            'bar' => 29968,
        ]);
        $this->assertNotNull($parsed);
        $this->assertEqualsWithDelta(29.968, $parsed['pressure'], 0.0001);
    }

    public function testParseRawData_NullsOutOfRangeRain(): void
    {
        $this->assertNull(WifiLoggerAdapter::parseRawData([
            'type' => 'rtd',
            'tutc' => 1531754005,
            'raindly' => -100,
        ])['precip_accum']);
        $this->assertNull(WifiLoggerAdapter::parseRawData([
            'type' => 'rtd',
            'tutc' => 1531754005,
            'raindly' => 65535,
        ])['precip_accum']);
    }

    public function testParseRawData_NullsFractionalValues(): void
    {
        $parsed = WifiLoggerAdapter::parseRawData([
            'type' => 'rtd',
            'tutc' => 1531754005,
            'tempout' => 67.8,
            'humout' => 55.5,
            'windspd' => 8.5,
            'altime' => 29.968,
        ]);
        $this->assertNull($parsed['temperature']);
        $this->assertNull($parsed['humidity']);
        $this->assertNull($parsed['wind_speed']);
        $this->assertNull($parsed['pressure']);
    }

    public function testParseRawData_RejectsMissingOrFutureTutc(): void
    {
        $this->assertNull(WifiLoggerAdapter::parseRawData(['type' => 'rtd']));
        $this->assertNull(WifiLoggerAdapter::parseRawData(['type' => 'rtd', 'tutc' => 0]));
        $this->assertNull(WifiLoggerAdapter::parseRawData([
            'type' => 'rtd',
            'tutc' => time() + UPLOAD_WEATHER_FUTURE_SKEW_SECONDS + 10,
        ]));
    }

    public function testParseRawData_NullsOutOfRangeValues(): void
    {
        $raw = [
            'type' => 'rtd',
            'tutc' => 1531754005,
            'tempout' => 2000,
            'humout' => 150,
            'altime' => 5,
            'winddir' => 400,
        ];
        $parsed = WifiLoggerAdapter::parseRawData($raw);
        $this->assertNull($parsed['temperature']);
        $this->assertNull($parsed['humidity']);
        $this->assertNull($parsed['pressure']);
        $this->assertNull($parsed['wind_direction']);
    }

    public function testSnapshotFromLatestRecord_BuildsValidSnapshot(): void
    {
        $record = [
            'provider' => 'wifilogger',
            'observed_unix' => 1531754005,
            'provider_meta' => [
                'raw' => [
                    'type' => 'rtd',
                    'tutc' => 1531754005,
                    'tempout' => 678,
                    'humout' => 55,
                    'windspd' => 8,
                    'winddir' => 210,
                ],
            ],
        ];
        $snap = WifiLoggerAdapter::snapshotFromLatestRecord($record, ['station_id' => 'wx-wl-01']);
        $this->assertInstanceOf(WeatherSnapshot::class, $snap);
        $this->assertTrue($snap->isValid);
        $this->assertSame('wifilogger', $snap->source);
        $this->assertSame('wx-wl-01', $snap->stationId);
        $this->assertTrue($snap->hasField('temperature'));
        $this->assertTrue($snap->hasCompleteWind());
    }

    public function testSnapshotFromLatestRecord_RejectsProviderMismatch(): void
    {
        $record = [
            'provider' => 'davis_weatherlink_live',
            'provider_meta' => ['raw' => ['type' => 'rtd']],
        ];
        $this->assertNull(WifiLoggerAdapter::snapshotFromLatestRecord($record));
    }

    public function testWifiloggerResolveSnapshot_ResolvesFromStore(): void
    {
        $record = [
            'observed_at' => gmdate('c', 1531754005),
            'observed_unix' => 1531754005,
            'source_id' => 'station-davis-01',
            'provider' => 'wifilogger',
            'provider_meta' => [
                'raw' => [
                    'type' => 'rtd',
                    'tutc' => 1531754005,
                    'tempout' => 678,
                    'humout' => 55,
                ],
            ],
        ];
        $GLOBALS['uploadTestCacheRoot'] = sys_get_temp_dir() . '/aviationwx_wifilogger_adapt_' . uniqid('', true);
        try {
            uploadStoreWeatherObservation('kspb', 'station-davis-01', $record);
            $snap = wifiloggerResolveSnapshot('kspb', ['wid' => 'station-davis-01']);
            $this->assertInstanceOf(WeatherSnapshot::class, $snap);
            $this->assertTrue($snap->hasField('temperature'));
        } finally {
            unset($GLOBALS['uploadTestCacheRoot']);
        }
    }
}