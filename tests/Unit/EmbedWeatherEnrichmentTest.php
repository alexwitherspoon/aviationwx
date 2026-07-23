<?php
/**
 * Embed weather enrichment for density altitude performance display.
 */

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../Helpers/LoadsNasrAptFixtureCacheTrait.php';
require_once __DIR__ . '/../../lib/embed-helpers.php';
require_once __DIR__ . '/../../lib/embed-diff.php';
require_once __DIR__ . '/../../lib/embed-templates/shared.php';

class EmbedWeatherEnrichmentTest extends TestCase
{
    use LoadsNasrAptFixtureCacheTrait;

    protected function setUp(): void
    {
        $this->loadNasrAptFixtureCache();
    }

    protected function tearDown(): void
    {
        $this->tearDownNasrAptFixtureCache();
    }

    public function testEnrichEmbedWeatherForDisplay_AttachesPerformanceFromRawCacheRow(): void
    {
        $airport = [
            'id' => '12id',
            'faa' => '12ID',
            'elevation_ft' => 3647,
        ];
        $weather = [
            'density_altitude' => 5342,
            'pressure_altitude' => 3408,
            'temperature' => 24.3,
        ];

        $enriched = enrichEmbedWeatherForDisplay($weather, $airport, '12id');

        $this->assertArrayHasKey('density_altitude_performance', $enriched);
        $this->assertContains(
            $enriched['density_altitude_performance']['tier'],
            ['caution', 'warning'],
            true
        );
        $this->assertArrayHasKey('best_end', $enriched['density_altitude_performance']);
        $this->assertArrayHasKey('ends', $enriched['density_altitude_performance']);
    }

    public function testConvertPublicApiToInternalFormat_PreservesDensityAltitudePerformance(): void
    {
        $apiWeather = [
            'density_altitude' => 6280,
            'density_altitude_performance' => [
                'tier' => 'warning',
                'fallback' => false,
                'best_end' => [
                    'end_id' => '08',
                    'rwy_id' => '08/26',
                    'total_risk' => 2.45,
                    'tier' => 'warning',
                ],
                'ends' => [],
            ],
            'wind_direction' => [
                'true_north' => 270,
                'magnetic_north' => 255,
                'variable' => false,
            ],
        ];

        $converted = convertPublicApiToInternalFormat($apiWeather);

        $this->assertSame('warning', $converted['density_altitude_performance']['tier']);
        $this->assertSame('08', $converted['density_altitude_performance']['best_end']['end_id']);
    }

    public function testBuildEmbedPayloadByTopic_IncludesDensityAltitudePerformance(): void
    {
        $airport = [
            'name' => 'Test Airport',
            'icao' => '12ID',
            'elevation_ft' => 3647,
            'timezone' => 'America/Los_Angeles',
            'webcams' => [],
            'runways' => [],
            'weather_sources' => [],
        ];
        $weather = [
            'density_altitude' => 5342,
            'pressure_altitude' => 3408,
            'temperature' => 24.3,
            'obs_time_primary' => 1708000000,
        ];

        $payload = buildEmbedPayloadByTopic('12id', $weather, $airport);

        $this->assertArrayHasKey('density_altitude_performance', $payload['weather']);
        $this->assertArrayHasKey('best_end', $payload['weather']['density_altitude_performance']);
        $this->assertArrayHasKey('ends', $payload['weather']['density_altitude_performance']);
    }

    public function testEnrichEmbedWeatherForDisplay_AttachesRunwayDisplayFromConfig(): void
    {
        $airport = [
            'id' => 'ktest',
            'runway_length_ft' => 2500,
            'runway_surface' => 'TURF',
            'runways' => [
                ['name' => '17/35', 'heading_1' => 175, 'heading_2' => 355],
            ],
        ];
        $weather = ['temperature' => 68];

        $enriched = enrichEmbedWeatherForDisplay($weather, $airport, 'ktest');

        $this->assertArrayHasKey('runway_display', $enriched);
        $this->assertSame('config', $enriched['runway_display']['runway_source']);
        $this->assertSame('17/35', $enriched['runway_display']['runways'][0]['rwy_id']);
    }

    public function testGetCompactWidgetMetrics_IncludesDaPerformanceCueForWarningTier(): void
    {
        $airport = [
            'id' => '12id',
            'faa' => '12ID',
            'elevation_ft' => 3647,
        ];
        $weather = enrichEmbedWeatherForDisplay([
            'density_altitude' => 5342,
            'pressure_altitude' => 3408,
            'temperature' => 24.3,
        ], $airport, '12id');

        $metrics = getCompactWidgetMetrics($weather, [
            'tempUnit' => 'F',
            'distUnit' => 'ft',
            'baroUnit' => 'inHg',
        ], true);

        $daMetric = null;
        foreach ($metrics as $metric) {
            if (($metric['label'] ?? '') === 'DA') {
                $daMetric = $metric;
                break;
            }
        }

        $this->assertNotNull($daMetric);
        $this->assertStringContainsString('🚩', (string) ($daMetric['value'] ?? ''));
        $this->assertStringContainsString('density-altitude-warning', (string) ($daMetric['tile_class_suffix'] ?? ''));
        $this->assertStringContainsString('RWY', (string) ($daMetric['tile_attrs'] ?? ''));
    }
}
