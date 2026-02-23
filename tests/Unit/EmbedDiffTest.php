<?php
/**
 * Unit Tests for Embed Diff (lib/embed-diff.php)
 *
 * Topic-level value-diff for refresh=1. Observed time for staleness.
 *
 * @package AviationWX\Tests\Unit
 */

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../lib/embed-diff.php';

class EmbedDiffTest extends TestCase
{
    private const TEST_AIRPORT_ID = 'kspb';

    private function getMinimalAirport(): array
    {
        return [
            'name' => 'Test Airport',
            'icao' => 'KSPB',
            'lat' => 45.77,
            'lon' => -122.86,
            'elevation_ft' => 58,
            'timezone' => 'America/Los_Angeles',
            'webcams' => [],
            'runways' => [],
            'weather_sources' => [],
        ];
    }

    public function testBuildEmbedPayloadByTopic_WithWeather_IncludesObservedAt(): void
    {
        $weather = [
            'temperature' => 15,
            'wind_speed' => 10,
            'obs_time_primary' => 1708000000,
        ];
        $airport = $this->getMinimalAirport();

        $payload = buildEmbedPayloadByTopic(self::TEST_AIRPORT_ID, $weather, $airport);

        $this->assertArrayHasKey('weather', $payload);
        $this->assertArrayHasKey('weather_observed_at', $payload);
        $this->assertSame('2024-02-15T12:26:40+00:00', $payload['weather_observed_at']);
        $this->assertArrayHasKey('airport', $payload);
        $this->assertArrayHasKey('airport_observed_at', $payload);
    }

    public function testBuildEmbedPayloadByTopic_WithoutWeather_NullObservedAt(): void
    {
        $airport = $this->getMinimalAirport();

        $payload = buildEmbedPayloadByTopic(self::TEST_AIRPORT_ID, null, $airport);

        $this->assertSame([], $payload['weather']);
        $this->assertNull($payload['weather_observed_at']);
        $this->assertArrayHasKey('airport', $payload);
    }

    public function testComputeEmbedTopicDiff_WeatherChanged_ReturnsWeatherTopic(): void
    {
        $current = [
            'weather' => ['temperature' => 20],
            'weather_observed_at' => '2024-02-15T13:00:00+00:00',
            'airport' => ['name' => 'Test'],
            'airport_observed_at' => '2024-02-15T13:00:00+00:00',
        ];
        $previous = [
            'weather' => ['temperature' => 15],
            'weather_observed_at' => '2024-02-15T12:00:00+00:00',
            'airport' => ['name' => 'Test'],
            'airport_observed_at' => '2024-02-15T13:00:00+00:00',
        ];

        $diff = computeEmbedTopicDiff($current, $previous);

        $this->assertArrayHasKey('weather', $diff);
        $this->assertArrayHasKey('weather_observed_at', $diff);
        $this->assertArrayNotHasKey('airport', $diff);
    }

    public function testComputeEmbedTopicDiff_ObservedTimeOnlyChanged_ReturnsWeatherTopic(): void
    {
        $current = [
            'weather' => ['temperature' => 15],
            'weather_observed_at' => '2024-02-15T13:00:00+00:00',
            'airport' => ['name' => 'Test'],
            'airport_observed_at' => '2024-02-15T13:00:00+00:00',
        ];
        $previous = [
            'weather' => ['temperature' => 15],
            'weather_observed_at' => '2024-02-15T12:00:00+00:00',
            'airport' => ['name' => 'Test'],
            'airport_observed_at' => '2024-02-15T13:00:00+00:00',
        ];

        $diff = computeEmbedTopicDiff($current, $previous);

        $this->assertArrayHasKey('weather', $diff);
        $this->assertSame('2024-02-15T13:00:00+00:00', $diff['weather_observed_at']);
    }

    public function testComputeEmbedTopicDiff_NoChanges_ReturnsEmpty(): void
    {
        $payload = [
            'weather' => ['temperature' => 15],
            'weather_observed_at' => '2024-02-15T12:00:00+00:00',
            'airport' => ['name' => 'Test'],
            'airport_observed_at' => '2024-02-15T12:00:00+00:00',
        ];

        $diff = computeEmbedTopicDiff($payload, $payload);

        $this->assertEmpty($diff);
    }

    public function testBuildEmbedPayloadByTopic_IncludesRunwaysAndWeatherSources(): void
    {
        $airport = $this->getMinimalAirport();
        $airport['runways'] = [['name' => '17/35', 'heading_1' => 170, 'heading_2' => 350]];
        $airport['weather_sources'] = [['type' => 'metar'], ['type' => 'tempest']];

        $payload = buildEmbedPayloadByTopic(self::TEST_AIRPORT_ID, null, $airport);

        $this->assertArrayHasKey('runways', $payload['airport']);
        $this->assertArrayHasKey('weather_sources', $payload['airport']);
        $this->assertCount(1, $payload['airport']['runways']);
        $this->assertSame('17/35', $payload['airport']['runways'][0]['name']);
        $this->assertCount(2, $payload['airport']['weather_sources']);
        $this->assertSame('metar', $payload['airport']['weather_sources'][0]['type']);
    }

    public function testBuildEmbedPayloadByTopic_WeatherSourcesStripsCredentials(): void
    {
        $airport = $this->getMinimalAirport();
        $airport['weather_sources'] = [
            ['type' => 'tempest', 'station_id' => '123', 'api_key' => 'secret_key'],
            ['type' => 'metar', 'station_id' => 'KSPB'],
        ];

        $payload = buildEmbedPayloadByTopic(self::TEST_AIRPORT_ID, null, $airport);

        $this->assertCount(2, $payload['airport']['weather_sources']);
        $this->assertSame('tempest', $payload['airport']['weather_sources'][0]['type']);
        $this->assertArrayNotHasKey('api_key', $payload['airport']['weather_sources'][0]);
        $this->assertArrayNotHasKey('station_id', $payload['airport']['weather_sources'][0]);
        $this->assertSame('metar', $payload['airport']['weather_sources'][1]['type']);
    }

    public function testBuildEmbedPayloadByTopic_NoRunwaysOrWeatherSources_ReturnsEmptyArrays(): void
    {
        $airport = $this->getMinimalAirport();
        unset($airport['runways'], $airport['weather_sources']);

        $payload = buildEmbedPayloadByTopic(self::TEST_AIRPORT_ID, null, $airport);

        $this->assertSame([], $payload['airport']['runways']);
        $this->assertSame([], $payload['airport']['weather_sources']);
    }

    public function testComputeEmbedTopicDiff_AirportChanged_ReturnsAirportTopic(): void
    {
        $current = [
            'weather' => ['temperature' => 15],
            'weather_observed_at' => '2024-02-15T12:00:00+00:00',
            'airport' => ['name' => 'Updated Name'],
            'airport_observed_at' => '2024-02-15T13:00:00+00:00',
        ];
        $previous = [
            'weather' => ['temperature' => 15],
            'weather_observed_at' => '2024-02-15T12:00:00+00:00',
            'airport' => ['name' => 'Old Name'],
            'airport_observed_at' => '2024-02-15T12:00:00+00:00',
        ];

        $diff = computeEmbedTopicDiff($current, $previous);

        $this->assertArrayHasKey('airport', $diff);
        $this->assertArrayHasKey('airport_observed_at', $diff);
        $this->assertArrayNotHasKey('weather', $diff);
    }
}
