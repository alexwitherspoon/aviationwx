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
}
