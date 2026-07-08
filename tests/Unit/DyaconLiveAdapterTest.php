<?php
/**
 * DyaconLive API adapter tests (safety-critical parsing path).
 */

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../lib/weather/adapter/dyaconlive-v1.php';
require_once __DIR__ . '/../mock-weather-responses.php';

class DyaconLiveAdapterTest extends TestCase
{
    public function testBuildUrl_MissingCredentials_ReturnsNull(): void
    {
        $this->assertNull(DyaconLiveAdapter::buildUrl(['station_id' => 130114], 'America/Boise'));
        $this->assertNull(DyaconLiveAdapter::buildUrl([
            'username' => 'user',
            'password' => 'pass',
        ], 'America/Boise'));
    }

    public function testBuildUrl_InvalidStationId_ReturnsNull(): void
    {
        $config = [
            'station_id' => 'evil/130114',
            'username' => 'user',
            'password' => 'pass',
        ];
        $this->assertNull(DyaconLiveAdapter::buildUrl($config, 'America/Boise'));
    }

    public function testBuildUrl_ValidConfig_ContainsDataEndpointAndVariables(): void
    {
        $url = DyaconLiveAdapter::buildUrl([
            'station_id' => 130114,
            'username' => 'user',
            'password' => 'pass',
        ], 'America/Boise');
        $this->assertNotNull($url);
        $this->assertStringContainsString('https://api.dyacon.net/data/130114', $url);
        $this->assertStringContainsString('timezone=America%2FBoise', $url);
        $this->assertStringContainsString('variable=air_temp%7CF', $url);
        $this->assertStringContainsString('variable=wind10m_speed%7Cmph', $url);
    }

    public function testBuildUrl_InvalidTimezone_FallsBackToUtcInQueryParam(): void
    {
        $url = DyaconLiveAdapter::buildUrl([
            'station_id' => 130114,
            'username' => 'user',
            'password' => 'pass',
        ], 'Not/A_Real_Timezone');
        $this->assertNotNull($url);
        $this->assertStringContainsString('timezone=UTC', $url);
        $this->assertStringNotContainsString('Not%2FA_Real_Timezone', $url);
    }

    public function testBuildUrl_ValidConfig_IncludesYesterdayAndTodayDateRange(): void
    {
        $url = DyaconLiveAdapter::buildUrl([
            'station_id' => 130114,
            'username' => 'user',
            'password' => 'pass',
        ], 'America/Boise');
        $this->assertNotNull($url);
        $tz = new DateTimeZone('America/Boise');
        $today = (new DateTimeImmutable('now', $tz))->format('Y-m-d');
        $yesterday = (new DateTimeImmutable('now', $tz))->modify('-1 day')->format('Y-m-d');
        $this->assertStringContainsString('startdate=' . rawurlencode($yesterday), $url);
        $this->assertStringContainsString('enddate=' . rawurlencode($today), $url);
    }

    public function testParseToSnapshot_MockResponse_MapsFieldsAndObservationTime(): void
    {
        $response = getMockDyaconLiveDataResponse();
        $snapshot = DyaconLiveAdapter::parseToSnapshot($response, [
            'station_id' => 130114,
            'timezone' => 'America/Boise',
            'elevation_ft' => 5335,
        ]);
        $this->assertNotNull($snapshot);
        $this->assertTrue($snapshot->isValid);
        $this->assertTrue($snapshot->temperature->hasValue());
        $this->assertEqualsWithDelta(22.42, $snapshot->temperature->value, 0.05);
        $this->assertTrue($snapshot->humidity->hasValue());
        $this->assertEqualsWithDelta(50.9, $snapshot->humidity->value, 0.1);
        $this->assertTrue($snapshot->pressure->hasValue());
        $this->assertGreaterThan(29.5, $snapshot->pressure->value);
        $this->assertLessThan(30.5, $snapshot->pressure->value);
        $this->assertTrue($snapshot->wind->speed->hasValue());
        $this->assertTrue($snapshot->wind->direction->hasValue());
        $this->assertFalse($snapshot->ceiling->hasValue());
        $this->assertFalse($snapshot->visibility->hasValue());
        $obs = $snapshot->getFieldObservationTime('temperature');
        $this->assertIsInt($obs);
        $dt = (new DateTimeImmutable('@' . $obs))->setTimezone(new DateTimeZone('America/Boise'));
        $this->assertSame('2026-07-07 09:40:00', $dt->format('Y-m-d H:i:s'));
    }

    public function testParseToSnapshot_MissingElevation_PressureNull(): void
    {
        $response = getMockDyaconLiveDataResponse();
        $snapshot = DyaconLiveAdapter::parseToSnapshot($response, [
            'station_id' => 130114,
            'timezone' => 'America/Boise',
        ]);
        $this->assertNotNull($snapshot);
        $this->assertTrue($snapshot->isValid);
        $this->assertFalse($snapshot->pressure->hasValue());
    }

    public function testParseToSnapshot_EmptySeries_ReturnsInvalidSnapshot(): void
    {
        $response = json_encode([
            ['variable_name' => 'air_temp', 'units' => 'F', 'datetimes' => [], 'values' => [], 'timezone' => 'UTC'],
        ], JSON_THROW_ON_ERROR);
        $snapshot = DyaconLiveAdapter::parseToSnapshot($response, ['station_id' => 1]);
        $this->assertNotNull($snapshot);
        $this->assertFalse($snapshot->isValid);
    }

    public function testExtractLastBucketIso_InvalidJson_ReturnsNull(): void
    {
        $this->assertNull(DyaconLiveAdapter::extractLastBucketIso('not-json', [
            'timezone' => 'America/Boise',
        ]));
    }

    public function testParseToSnapshot_InvalidJson_ReturnsNull(): void
    {
        $this->assertNull(DyaconLiveAdapter::parseToSnapshot('not-json', ['station_id' => 1]));
    }

    public function testParseToSnapshot_ZeroGust_TreatedAsMissing(): void
    {
        $response = json_encode([
            [
                'variable_name' => 'air_temp',
                'units' => 'F',
                'datetimes' => ['2026-07-07T09:40:00'],
                'values' => [72.0],
                'timezone' => 'America/Boise',
            ],
            [
                'variable_name' => 'wind10m_speed',
                'units' => 'mph',
                'datetimes' => ['2026-07-07T09:40:00'],
                'values' => [5.0],
                'timezone' => 'America/Boise',
            ],
            [
                'variable_name' => 'wind10m_direction',
                'units' => 'degrees',
                'datetimes' => ['2026-07-07T09:40:00'],
                'values' => [180.0],
                'timezone' => 'America/Boise',
            ],
            [
                'variable_name' => 'wind_gust',
                'units' => 'mph',
                'datetimes' => ['2026-07-07T09:40:00'],
                'values' => [0.0],
                'timezone' => 'America/Boise',
            ],
        ], JSON_THROW_ON_ERROR);
        $snapshot = DyaconLiveAdapter::parseToSnapshot($response, ['timezone' => 'America/Boise', 'elevation_ft' => 100]);
        $this->assertNotNull($snapshot);
        $this->assertFalse($snapshot->wind->gust->hasValue());
    }

    public function testParseToSnapshot_MisalignedSeries_UsesAnchorBucketNotTrailingIndex(): void
    {
        $response = json_encode([
            [
                'variable_name' => 'air_temp',
                'units' => 'F',
                'datetimes' => ['2026-07-07T09:30:00', '2026-07-07T09:40:00'],
                'values' => [71.0, 72.0],
                'timezone' => 'America/Boise',
            ],
            [
                'variable_name' => 'humidity',
                'units' => '%',
                'datetimes' => ['2026-07-07T09:30:00', '2026-07-07T09:40:00', '2026-07-07T09:50:00'],
                'values' => [52.0, 48.0, 99.0],
                'timezone' => 'America/Boise',
            ],
            [
                'variable_name' => 'wind10m_speed',
                'units' => 'mph',
                'datetimes' => ['2026-07-07T09:30:00', '2026-07-07T09:40:00'],
                'values' => [3.0, 4.0],
                'timezone' => 'America/Boise',
            ],
            [
                'variable_name' => 'wind10m_direction',
                'units' => 'degrees',
                'datetimes' => ['2026-07-07T09:30:00', '2026-07-07T09:40:00'],
                'values' => [180.0, 190.0],
                'timezone' => 'America/Boise',
            ],
        ], JSON_THROW_ON_ERROR);

        $snapshot = DyaconLiveAdapter::parseToSnapshot($response, [
            'timezone' => 'America/Boise',
            'elevation_ft' => 100,
        ]);
        $this->assertNotNull($snapshot);
        $this->assertTrue($snapshot->isValid);
        $this->assertEqualsWithDelta(48.0, $snapshot->humidity->value, 0.1);
    }

    public function testParseToSnapshot_MissingAnchorBucketInSeries_ReturnsNullForField(): void
    {
        $response = json_encode([
            [
                'variable_name' => 'air_temp',
                'units' => 'F',
                'datetimes' => ['2026-07-07T09:30:00', '2026-07-07T09:40:00'],
                'values' => [71.0, 72.0],
                'timezone' => 'America/Boise',
            ],
            [
                'variable_name' => 'humidity',
                'units' => '%',
                'datetimes' => ['2026-07-07T09:20:00', '2026-07-07T09:30:00', '2026-07-07T09:50:00'],
                'values' => [40.0, 52.0, 99.0],
                'timezone' => 'America/Boise',
            ],
            [
                'variable_name' => 'wind10m_speed',
                'units' => 'mph',
                'datetimes' => ['2026-07-07T09:30:00', '2026-07-07T09:40:00'],
                'values' => [3.0, 4.0],
                'timezone' => 'America/Boise',
            ],
            [
                'variable_name' => 'wind10m_direction',
                'units' => 'degrees',
                'datetimes' => ['2026-07-07T09:30:00', '2026-07-07T09:40:00'],
                'values' => [180.0, 190.0],
                'timezone' => 'America/Boise',
            ],
        ], JSON_THROW_ON_ERROR);

        $snapshot = DyaconLiveAdapter::parseToSnapshot($response, [
            'timezone' => 'America/Boise',
            'elevation_ft' => 100,
        ]);
        $this->assertNotNull($snapshot);
        $this->assertTrue($snapshot->isValid);
        $this->assertFalse($snapshot->humidity->hasValue());
    }

    public function testDyaconlivePeakGustTodayFromResponse_ReturnsLocalDayMax(): void
    {
        $tz = 'America/Boise';
        $today = (new DateTimeImmutable('now', new DateTimeZone($tz)))->format('Y-m-d');
        $yesterday = (new DateTimeImmutable('now', new DateTimeZone($tz)))->modify('-1 day')->format('Y-m-d');
        $response = json_encode([
            [
                'variable_name' => 'wind_gust',
                'units' => 'mph',
                'datetimes' => [
                    $yesterday . 'T23:50:00',
                    $today . 'T10:00:00',
                    $today . 'T10:10:00',
                ],
                'values' => [40.0, 0.0, 28.6336],
                'timezone' => $tz,
            ],
        ], JSON_THROW_ON_ERROR);

        $peak = dyaconlivePeakGustTodayFromResponse($response, $tz);
        $this->assertNotNull($peak);
        $this->assertSame(25.0, $peak['value']);
        $this->assertSame(
            dyaconliveParseBucketIsoToUnix($today . 'T10:10:00', $tz),
            $peak['obs_time']
        );
    }

    public function testDyaconlivePeakGustTodayFromResponse_EmptySeries_ReturnsNull(): void
    {
        $response = json_encode([
            [
                'variable_name' => 'wind_gust',
                'units' => 'mph',
                'datetimes' => [],
                'values' => [],
                'timezone' => 'America/Boise',
            ],
        ], JSON_THROW_ON_ERROR);

        $this->assertNull(dyaconlivePeakGustTodayFromResponse($response, 'America/Boise'));
    }

    public function testDyaconlivePeakGustTodayFromResponse_OnlyZeroToday_ReturnsNull(): void
    {
        $tz = 'America/Boise';
        $today = (new DateTimeImmutable('now', new DateTimeZone($tz)))->format('Y-m-d');
        $response = json_encode([
            [
                'variable_name' => 'wind_gust',
                'units' => 'mph',
                'datetimes' => [$today . 'T09:00:00', $today . 'T09:10:00'],
                'values' => [0.0, 0.0],
                'timezone' => $tz,
            ],
        ], JSON_THROW_ON_ERROR);

        $this->assertNull(dyaconlivePeakGustTodayFromResponse($response, $tz));
    }
}
