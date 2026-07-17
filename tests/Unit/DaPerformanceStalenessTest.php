<?php
/**
 * Staleness gating for density altitude performance alerts.
 */

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../Helpers/LoadsNasrAptFixtureCacheTrait.php';
require_once __DIR__ . '/../../lib/weather/cache-utils.php';
require_once __DIR__ . '/../../lib/weather/density-altitude-performance.php';

class DaPerformanceStalenessTest extends TestCase
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
    public function testIsWeatherFieldFailclosedStaleUsesPerFieldObsTime(): void
    {
        $failclosed = getStaleFailclosedSeconds();
        $weather = [
            'temperature' => 24.3,
            'last_updated_primary' => time(),
            '_field_obs_time_map' => [
                'temperature' => time() - $failclosed - 10,
            ],
        ];

        $this->assertTrue(isWeatherFieldFailclosedStale($weather, 'temperature', null, false));
    }

    public function testIsWeatherFieldFailclosedStaleFreshFieldWithRecentObsTime(): void
    {
        $weather = [
            'temperature' => 24.3,
            'last_updated_primary' => time() - 99999,
            '_field_obs_time_map' => [
                'temperature' => time() - 30,
            ],
        ];

        $this->assertFalse(isWeatherFieldFailclosedStale($weather, 'temperature', null, false));
    }

    public function testDensityAltitudePerformanceMaySurfaceAlertRequiresFreshPressureForFullModel(): void
    {
        $failclosed = getStaleFailclosedSeconds();
        $airport = ['weather_sources' => [['type' => 'tempest']]];
        $weather = [
            'density_altitude' => 5342,
            'pressure_altitude' => 3408,
            'temperature' => 24.3,
            'pressure' => 30.12,
            'last_updated_primary' => time(),
            '_field_obs_time_map' => [
                'pressure_altitude' => time() - $failclosed - 5,
            ],
        ];
        $payload = ['fallback' => false, 'tier' => 'warning'];

        $this->assertFalse(densityAltitudePerformanceMaySurfaceAlert($weather, $airport, $payload));
    }

    public function testDensityAltitudePerformanceMaySurfaceAlertAllowsFallbackWhenDaFresh(): void
    {
        $airport = ['weather_sources' => [['type' => 'tempest']]];
        $weather = [
            'density_altitude' => 9000,
            'temperature' => 30.0,
            'pressure' => 30.0,
            'last_updated_primary' => time(),
        ];
        $payload = ['fallback' => true, 'tier' => 'warning'];

        $this->assertTrue(densityAltitudePerformanceMaySurfaceAlert($weather, $airport, $payload));
    }

    public function testAttachOmitsWarningWhenTemperatureStale(): void
    {
        $failclosed = getStaleFailclosedSeconds();
        $weather = [
            'density_altitude' => 5342,
            'pressure_altitude' => 3408,
            'temperature' => 24.3,
            'pressure' => 30.12,
            'last_updated_primary' => time(),
            '_field_obs_time_map' => [
                'temperature' => time() - $failclosed - 1,
            ],
        ];

        $attached = attachDensityAltitudePerformance($weather, [
            'id' => '12id',
            'faa' => '12ID',
            'elevation_ft' => 3647,
            'weather_sources' => [['type' => 'tempest']],
        ], '12id');

        $this->assertArrayNotHasKey('density_altitude_performance', $attached);
    }

    public function testAttachKeepsNormalPayloadWhenFresh(): void
    {
        $weather = [
            'density_altitude' => 9399,
            'pressure_altitude' => 5673,
            'temperature' => 34.7,
            'pressure' => 30.12,
            'last_updated_primary' => time(),
        ];

        $attached = attachDensityAltitudePerformance($weather, [
            'id' => '69v',
            'faa' => '69V',
            'elevation_ft' => 5915,
            'weather_sources' => [['type' => 'tempest']],
        ], '69v');

        $this->assertArrayHasKey('density_altitude_performance', $attached);
        $this->assertSame('normal', $attached['density_altitude_performance']['tier']);
    }
}
