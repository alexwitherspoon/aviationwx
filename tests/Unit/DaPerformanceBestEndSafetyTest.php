<?php
/**
 * Safety-critical regression tests for best-end tier policy and closed-runway exclusion.
 */

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../Helpers/LoadsNasrAptFixtureCacheTrait.php';
require_once __DIR__ . '/../../lib/config-runway.php';
require_once __DIR__ . '/../../lib/weather/density-altitude-performance.php';

class DaPerformanceBestEndSafetyTest extends TestCase
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

    public function testFalsePositiveRegressionWorstAtCapBestBelowCautionYieldsNormal(): void
    {
        $runways = [
            [
                'rwy_id' => '18/36',
                'length_ft' => 1200,
                'surface' => 'TURF',
                'condition' => '',
                'ends' => [
                    ['end_id' => '18', 'true_alignment' => 180, 'obstruction' => []],
                    ['end_id' => '36', 'true_alignment' => 360, 'obstruction' => []],
                ],
            ],
            [
                'rwy_id' => '08/26',
                'length_ft' => 8000,
                'surface' => 'ASPH',
                'condition' => 'GOOD',
                'ends' => [
                    ['end_id' => '08', 'true_alignment' => 80, 'obstruction' => []],
                    ['end_id' => '26', 'true_alignment' => 260, 'obstruction' => []],
                ],
            ],
        ];

        $tables = loadPohTakeoffTables();
        $evaluation = evaluateAirportRunwayEndPerformanceRange($runways, 5673.0, 34.7, $tables);

        $this->assertGreaterThanOrEqual(DENSITY_ALTITUDE_PERFORMANCE_TIER_WARNING, $evaluation['worst']['total_risk']);
        $this->assertLessThan(DENSITY_ALTITUDE_PERFORMANCE_TIER_CAUTION, $evaluation['best']['total_risk']);
        $this->assertSame(
            'normal',
            densityAltitudePerformanceTierFromScoredEnd($evaluation['best']['total_risk'])
        );
    }

    public function test69vHotDayBuildReturnsNormalTierDespiteWorstEndAtCap(): void
    {
        $result = computeDensityAltitudePerformance([
            'density_altitude' => 9399,
            'pressure_altitude' => 5673,
            'temperature' => 34.7,
        ], [
            'id' => '69v',
            'faa' => '69V',
            'elevation_ft' => 5915,
        ], '69v');

        $this->assertIsArray($result);
        $this->assertSame('normal', $result['tier']);
        $this->assertFalse($result['fallback']);
        $this->assertArrayHasKey('ends', $result);
        $this->assertGreaterThan(1, count($result['ends']));
        $this->assertGreaterThanOrEqual(
            $result['best_end']['total_risk'],
            $result['worst_end']['total_risk']
        );
    }

    public function test69vGlobalBestEndIsOnLongestUsableStrip(): void
    {
        $nasrRecord = getNasrAirportForConfig(['faa' => '69V']);
        $this->assertNotNull($nasrRecord);

        $runways = nasrSelectActiveLandRunwaysForPerformance($nasrRecord);
        $tables = loadPohTakeoffTables();
        $evaluation = evaluateAirportRunwayEndPerformanceRange($runways, 5673.0, 34.7, $tables);

        $this->assertSame('26', $evaluation['best']['end_id']);
        $this->assertSame('08/26', $evaluation['best']['rwy_id']);
        $this->assertGreaterThanOrEqual(3.0, $evaluation['worst']['total_risk']);
    }

    public function testNonNormalPayloadInvariants(): void
    {
        $result = computeDensityAltitudePerformance([
            'density_altitude' => 5342,
            'pressure_altitude' => 3408,
            'temperature' => 24.3,
        ], [
            'id' => '12id',
            'faa' => '12ID',
            'elevation_ft' => 3647,
        ], '12id');

        $this->assertIsArray($result);
        $this->assertContains($result['tier'], ['caution', 'warning'], true);
        $this->assertSame('best_performance', $result['selection_basis']);
        $this->assertFalse($result['fallback']);
        $this->assertGreaterThanOrEqual(
            $result['best_end']['total_risk'],
            $result['worst_end']['total_risk']
        );
        $this->assertArrayHasKey('end_id', $result['best_end']);
        $this->assertArrayHasKey('rwy_id', $result['best_end']);
        $this->assertArrayHasKey('ends', $result);
        $this->assertNotEmpty($result['ends']);
    }

    public function testDensityAltitudePerformanceAllowsWarningTierNasr(): void
    {
        $nasrRecord = getNasrAirportForConfig(['faa' => 'C53']);
        $this->assertNotNull($nasrRecord);
        $runways = nasrSelectActiveLandRunwaysForPerformance($nasrRecord);

        $this->assertTrue(densityAltitudePerformanceAllowsWarningTier('nasr', $runways));
    }

    public function testDensityAltitudePerformanceAllowsWarningTierOurairportsFalse(): void
    {
        $this->assertFalse(densityAltitudePerformanceAllowsWarningTier('ourairports', [
            ['rwy_id' => '17/35', 'length_ft' => 2000, 'surface' => 'TURF', 'ends' => []],
        ]));
    }

    public function testDensityAltitudePerformanceAllowsWarningTierConfigWithoutObstructionFalse(): void
    {
        $configRunway = buildConfigRunwayForDensityAltitude([
            'runway_length_ft' => 2000,
            'runway_surface' => 'TURF',
        ]);
        $this->assertNotNull($configRunway);

        $this->assertFalse(densityAltitudePerformanceAllowsWarningTier('config', [$configRunway]));
    }

    public function testDensityAltitudePerformanceAllowsWarningTierConfigWithObstructionTrue(): void
    {
        $configRunway = buildConfigRunwayForDensityAltitude([
            'runway_length_ft' => 2000,
            'runway_surface' => 'TURF',
            'runway_ends' => [
                ['end_id' => '17', 'obstruction' => ['hgt_ft' => 500, 'dist_ft' => 900]],
                ['end_id' => '35'],
            ],
        ]);
        $this->assertNotNull($configRunway);

        $this->assertTrue(densityAltitudePerformanceAllowsWarningTier('config', [$configRunway]));
    }

    public function testAttachDensityAltitudePerformanceReplacesStalePayloadOnNormal(): void
    {
        $weather = [
            'density_altitude' => 9399,
            'pressure_altitude' => 5673,
            'temperature' => 34.7,
            'density_altitude_performance' => ['tier' => 'warning'],
        ];

        $attached = attachDensityAltitudePerformance($weather, [
            'id' => '69v',
            'faa' => '69V',
            'elevation_ft' => 5915,
        ], '69v');

        $this->assertArrayHasKey('density_altitude_performance', $attached);
        $this->assertSame('normal', $attached['density_altitude_performance']['tier']);
        $this->assertArrayHasKey('ends', $attached['density_altitude_performance']);
        $this->assertSame('26', $attached['density_altitude_performance']['best_end']['end_id']);
    }

    public function testAttachDensityAltitudePerformanceSetsFieldOnCaution(): void
    {
        $weather = [
            'density_altitude' => 5342,
            'pressure_altitude' => 3408,
            'temperature' => 24.3,
        ];

        $attached = attachDensityAltitudePerformance($weather, [
            'id' => '12id',
            'faa' => '12ID',
            'elevation_ft' => 3647,
        ], '12id');

        $this->assertArrayHasKey('density_altitude_performance', $attached);
        $this->assertSame('warning', $attached['density_altitude_performance']['tier']);
        $this->assertArrayHasKey('ends', $attached['density_altitude_performance']);
        $this->assertNotEmpty($attached['density_altitude_performance']['ends']);
    }

    public function testAttachDensityAltitudePerformanceOmitsAlertWhenDensityAltitudeStale(): void
    {
        $staleSeconds = getStaleFailclosedSeconds(['weather_refresh_seconds' => 60]);
        $weather = [
            'density_altitude' => 5342,
            'pressure_altitude' => 3408,
            'temperature' => 24.3,
            'pressure' => 30.12,
            'last_updated_primary' => time() - $staleSeconds - 60,
        ];

        $attached = attachDensityAltitudePerformance($weather, [
            'id' => '12id',
            'faa' => '12ID',
            'elevation_ft' => 3647,
        ], '12id');

        $this->assertArrayNotHasKey('density_altitude_performance', $attached);
    }

    public function testAttachDensityAltitudePerformanceKeepsNormalPayloadWhenFresh(): void
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
        ], '69v');

        $this->assertArrayHasKey('density_altitude_performance', $attached);
        $this->assertSame('normal', $attached['density_altitude_performance']['tier']);
        $this->assertArrayHasKey('ends', $attached['density_altitude_performance']);
    }

    public function testWaterRunwayExcludedFromMultiRunwayScoringAt42b(): void
    {
        $nasrRecord = getNasrAirportForConfig(['faa' => '42B']);
        $this->assertNotNull($nasrRecord);

        $runways = nasrSelectActiveLandRunwaysForPerformance($nasrRecord);
        $rwyIds = array_column($runways, 'rwy_id');

        $this->assertNotContains('WATER', $rwyIds);
        $this->assertContains('14/32', $rwyIds);
    }
}
