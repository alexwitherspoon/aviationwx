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

    public function testBuildDashboardWeatherSourceAttribution_OmitsHiddenSupplementalMetar(): void
    {
        $airport = [
            'faa' => '7S9',
            'weather_sources' => [
                ['type' => 'tempest', 'station_id' => '216638'],
                ['type' => 'metar', 'station_id' => 'KUAO'],
            ],
        ];
        $weatherData = [
            'wind_speed' => null,
            'visibility' => null,
            'obs_time_metar' => null,
            'last_updated_metar' => null,
            '_field_source_map' => ['wind_speed' => 'metar', 'temperature' => 'tempest'],
            '_field_station_map' => ['wind_speed' => 'KUAO', 'temperature' => '216638'],
        ];

        $this->assertSame([], buildDashboardWeatherSourceAttribution($airport, $weatherData));
    }

    public function testBuildDashboardWeatherSourceAttribution_CreditsDisplayedOnFieldSource(): void
    {
        $airport = [
            'weather_sources' => [
                ['type' => 'tempest', 'station_id' => '216638'],
            ],
        ];
        $weatherData = [
            'temperature' => 15.0,
            '_field_source_map' => ['temperature' => 'tempest'],
            '_field_station_map' => ['temperature' => '216638'],
        ];

        $sources = buildDashboardWeatherSourceAttribution($airport, $weatherData);

        $this->assertCount(1, $sources);
        $this->assertStringContainsString('Tempest', $sources[0]['name']);
    }

    public function testBuildDashboardWeatherSourceAttribution_CreditsCoLocatedMetarWhenDisplayed(): void
    {
        $airport = [
            'icao' => 'KSPB',
            'weather_sources' => [
                ['type' => 'metar', 'station_id' => 'KSPB'],
            ],
        ];
        $weatherData = [
            'visibility' => 10.0,
            'obs_time_metar' => time() - 300,
            'last_updated_metar' => time() - 300,
            '_field_source_map' => ['visibility' => 'metar'],
            '_field_station_map' => ['visibility' => 'KSPB'],
        ];

        $sources = buildDashboardWeatherSourceAttribution($airport, $weatherData);

        $this->assertCount(1, $sources);
        $this->assertStringContainsString('KSPB', $sources[0]['name']);
    }

    public function testBuildDashboardWeatherSourceAttribution_CreditsEachDisplayedStationPerSourceType(): void
    {
        $airport = ['weather_sources' => [['type' => 'tempest', 'station_id' => '111']]];
        $weatherData = [
            'temperature' => 10.0,
            'humidity' => 50.0,
            '_field_source_map' => ['temperature' => 'tempest', 'humidity' => 'tempest'],
            '_field_station_map' => ['temperature' => '111', 'humidity' => '222'],
        ];

        $sources = buildDashboardWeatherSourceAttribution($airport, $weatherData);

        $this->assertCount(2, $sources);
        $names = array_column($sources, 'name');
        $this->assertTrue(
            count(array_filter($names, static fn ($n) => str_contains($n, '111'))) === 1
        );
        $this->assertTrue(
            count(array_filter($names, static fn ($n) => str_contains($n, '222'))) === 1
        );
    }

    public function testBuildDashboardWeatherSourceAttribution_ToleratesNonArrayFieldMaps(): void
    {
        $airport = ['weather_sources' => [['type' => 'metar', 'station_id' => 'KUAO']]];
        $weatherData = [
            'visibility' => 10.0,
            'obs_time_metar' => time() - 300,
            '_field_source_map' => 'invalid',
            '_field_station_map' => null,
        ];

        $this->assertSame([], buildDashboardWeatherSourceAttribution($airport, $weatherData));
    }
}
