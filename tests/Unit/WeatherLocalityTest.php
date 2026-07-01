<?php
/**
 * Unit tests for local vs supplemental remote weather helpers.
 */

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../lib/weather/weather-locality.php';

class WeatherLocalityTest extends TestCase
{
    public function testAirportHasOnFieldInfrastructure_TempestAndWebcams(): void
    {
        $airport = [
            'weather_sources' => [
                ['type' => 'tempest', 'station_id' => '216638'],
                ['type' => 'metar', 'station_id' => 'KUAO'],
            ],
            'webcams' => [['name' => 'North', 'url' => 'http://example.com/n.jpg']],
        ];

        $this->assertTrue(airportHasOnFieldInfrastructure($airport));
    }

    public function testAirportHasOnFieldInfrastructure_MetarOnly_ReturnsFalse(): void
    {
        $airport = [
            'weather_sources' => [['type' => 'metar', 'station_id' => 'KUAO']],
        ];

        $this->assertFalse(airportHasOnFieldInfrastructure($airport));
    }

    public function testIsCoLocatedMetarStation_MatchingIcao(): void
    {
        $airport = ['icao' => 'KSPB'];

        $this->assertTrue(isCoLocatedMetarStation('KSPB', $airport));
        $this->assertFalse(isCoLocatedMetarStation('KUAO', $airport));
    }

    public function testIsCoLocatedMetarStation_NoAirportIcao_ReturnsFalse(): void
    {
        $airport = ['faa' => '7S9'];

        $this->assertFalse(isCoLocatedMetarStation('KUAO', $airport));
    }

    public function testIsSupplementalMetarForOutage_7S9Shaped_ReturnsTrue(): void
    {
        $airport = [
            'faa' => '7S9',
            'weather_sources' => [
                ['type' => 'tempest', 'station_id' => '216638'],
                ['type' => 'metar', 'station_id' => 'KUAO'],
            ],
            'webcams' => [['name' => 'North', 'url' => 'http://example.com/n.jpg']],
        ];

        $weatherData = [
            '_field_station_map' => ['visibility' => 'KUAO'],
        ];

        $this->assertTrue(isSupplementalMetarForOutage($airport, $weatherData));
    }

    public function testIsSupplementalMetarForOutage_CoLocatedKspb_ReturnsFalse(): void
    {
        $airport = [
            'icao' => 'KSPB',
            'weather_sources' => [
                ['type' => 'tempest', 'station_id' => '12345'],
                ['type' => 'metar', 'station_id' => 'KSPB'],
            ],
        ];

        $weatherData = [
            '_field_station_map' => ['visibility' => 'KSPB'],
        ];

        $this->assertFalse(isSupplementalMetarForOutage($airport, $weatherData));
    }

    public function testIsSupplementalMetarForOutage_MetarOnly_ReturnsFalse(): void
    {
        $airport = [
            'weather_sources' => [['type' => 'metar', 'station_id' => 'KUAO']],
        ];

        $this->assertFalse(isSupplementalMetarForOutage($airport, null));
    }

    public function testGetActiveMetarStationId_UsesFieldStationMapFirst(): void
    {
        $weatherData = [
            '_field_station_map' => [
                'visibility' => 'KUAO',
                'wind_speed' => 'KPDX',
            ],
        ];

        $this->assertSame('KUAO', getActiveMetarStationId($weatherData, null));
    }

    public function testNewestOnFieldOutageTimestamp_IncludesBackupSource(): void
    {
        $sources = [
            'primary' => ['timestamp' => 1000],
            'backup' => ['timestamp' => 2000],
        ];
        $sourceTimestamps = [
            'backup' => ['timestamp' => 2500],
            'webcams' => ['newest_timestamp' => 1500],
        ];

        $this->assertSame(2500, newestOnFieldOutageTimestamp($sources, $sourceTimestamps));
    }

    public function testGetSupplementalOutageClientConfig_7S9Shaped(): void
    {
        $airport = [
            'faa' => '7S9',
            'weather_sources' => [
                ['type' => 'tempest', 'station_id' => '216638'],
                ['type' => 'metar', 'station_id' => 'KUAO'],
            ],
            'webcams' => [['name' => 'North', 'url' => 'http://example.com/n.jpg']],
        ];

        $config = getSupplementalOutageClientConfig($airport, ['_field_station_map' => ['visibility' => 'KUAO']]);

        $this->assertTrue($config['is_supplemental_metar']);
        $this->assertFalse($config['is_metar_only']);
        $this->assertContains('wind_speed', $config['hidden_fields']);
        $this->assertContains('flight_category_class', $config['hidden_fields']);
    }
}
