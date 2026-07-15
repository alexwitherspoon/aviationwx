<?php
/**
 * SAFETY-CRITICAL: Density altitude performance assessment.
 */

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../lib/nasr/cache.php';
require_once __DIR__ . '/../../lib/weather/density-altitude-performance.php';

class DensityAltitudePerformanceTest extends TestCase
{
    private string $nasrFixtureDir;

    protected function setUp(): void
    {
        $this->nasrFixtureDir = __DIR__ . '/../Fixtures/nasr';
        resetNasrAptCacheMemo();
        resetPohTakeoffTables();

        $built = nasrBuildCacheFromCsvDirectory($this->nasrFixtureDir);
        setNasrAptCacheForTesting([
            'schema_version' => NASR_APT_SCHEMA_VERSION,
            'airports' => $built['airports'],
        ]);
    }

    protected function tearDown(): void
    {
        resetNasrAptCacheMemo();
        resetPohTakeoffTables();
    }

    public function testReturnsNullWhenDensityAltitudeMissing(): void
    {
        $result = buildDensityAltitudePerformance(['temperature' => 20, 'pressure_altitude' => 1000], [
            'id' => 'id76',
            'faa' => 'ID76',
            'elevation_ft' => 4925,
        ]);
        $this->assertNull($result);
    }

    public function testFallbackOmitsRiskFactor(): void
    {
        $result = buildDensityAltitudePerformance([
            'density_altitude' => 9500,
            'pressure_altitude' => 9000,
            'temperature' => 35,
        ], [
            'id' => 'unknown',
            'elevation_ft' => 500,
        ]);

        $this->assertNotNull($result);
        $this->assertSame('warning', $result['tier']);
        $this->assertTrue($result['fallback']);
        $this->assertNull($result['risk_factor']);
        $this->assertSame('density_altitude_only', $result['reason']);
    }

    public function testFallbackRunsWhenRunwayMissingEvenWithoutPressureAltitude(): void
    {
        $result = buildDensityAltitudePerformance([
            'density_altitude' => 9500,
        ], [
            'id' => 'unknown',
            'elevation_ft' => 500,
        ]);

        $this->assertNotNull($result);
        $this->assertSame('warning', $result['tier']);
        $this->assertTrue($result['fallback']);
    }

    public function testId76AfternoonScenarioFlagsWarningTier(): void
    {
        $result = buildDensityAltitudePerformance([
            'density_altitude' => 6280,
            'pressure_altitude' => 4570,
            'temperature' => 20.1,
        ], [
            'id' => 'id76',
            'faa' => 'ID76',
            'elevation_ft' => 4925,
        ]);

        $this->assertNotNull($result);
        $this->assertSame('warning', $result['tier']);
        $this->assertFalse($result['fallback']);
        $this->assertIsFloat($result['risk_factor']);
        $this->assertGreaterThanOrEqual(DENSITY_ALTITUDE_PERFORMANCE_TIER_WARNING, $result['risk_factor']);
    }

    public function testFallbackWhenPressureAltitudeMissingButRunwayPresent(): void
    {
        $result = buildDensityAltitudePerformance([
            'density_altitude' => 9500,
        ], [
            'id' => 'id76',
            'faa' => 'ID76',
            'elevation_ft' => 4925,
        ]);

        $this->assertNotNull($result);
        $this->assertTrue($result['fallback']);
        $this->assertSame('density_altitude_only', $result['reason']);
        $this->assertNull($result['risk_factor']);
    }

    public function test03STurfRunwayTierUsesRoadObstacleOnOppositeEnd(): void
    {
        $result = buildDensityAltitudePerformance([
            'density_altitude' => 2000,
            'pressure_altitude' => 800,
            'temperature' => 30,
        ], [
            'id' => '03S',
            'faa' => '03S',
            'elevation_ft' => 704,
        ]);

        $this->assertNotNull($result);
        $this->assertSame('caution', $result['tier']);
        $this->assertFalse($result['fallback']);
        $this->assertGreaterThanOrEqual(DENSITY_ALTITUDE_PERFORMANCE_TIER_CAUTION, $result['risk_factor']);
    }

    public function testKhioClearanceSlopeSuppressesPublishedTreeObstacle(): void
    {
        $result = buildDensityAltitudePerformance([
            'density_altitude' => 1792,
            'pressure_altitude' => 90,
            'temperature' => 29,
        ], [
            'id' => 'khio',
            'faa' => 'HIO',
            'icao' => 'KHIO',
            'elevation_ft' => 208,
        ]);

        $this->assertNull($result);
    }

    public function testPohObstructionStressUsesChartDistanceToClearObstacle(): void
    {
        $tables = loadPohTakeoffTables();
        $table = $tables['c172'];
        $chartTotal = pohChartSurfaceTotalFt($table, 800.0, 30.0, true);

        $stress = pohComputeDepartureEndStress($table, 800.0, 30.0, true, 2115, 142.0, 583.0);
        $expectedObstacleStress = ($chartTotal * (142.0 / POH_OBSTACLE_REFERENCE_HEIGHT_FT)) / 583.0;
        $expectedRunwayStress = $chartTotal / 2115.0;

        $this->assertEqualsWithDelta(max($expectedRunwayStress, $expectedObstacleStress), $stress, 0.01);
    }

    public function testSummedPerformanceRiskUsesUnweightedSum(): void
    {
        $this->assertEqualsWithDelta(2.1, calculateSummedPerformanceRisk(0.8, 0.7, 0.6), 0.001);
        $this->assertEqualsWithDelta(3.0, calculateSummedPerformanceRisk(1.0, 1.0, 1.0), 0.001);
    }

    public function testDensityAltitudePerformanceTierThresholdsOnSummedRisk(): void
    {
        $this->assertSame('normal', densityAltitudePerformanceTierForRisk(1.19));
        $this->assertSame('caution', densityAltitudePerformanceTierForRisk(1.20));
        $this->assertSame('caution', densityAltitudePerformanceTierForRisk(2.39));
        $this->assertSame('warning', densityAltitudePerformanceTierForRisk(2.40));
    }

    public function testAsymmetricTierWarningRequiresBestEndAboveThreshold(): void
    {
        $this->assertSame('caution', densityAltitudePerformanceTierFromEndRisks(3.0, 1.0));
        $this->assertSame('warning', densityAltitudePerformanceTierFromEndRisks(3.0, 2.4));
        $this->assertSame('normal', densityAltitudePerformanceTierFromEndRisks(1.19, 1.0));
    }

    public function testRunwayEndPerformanceRangeSelectsBestAndWorstEnds(): void
    {
        $tables = loadPohTakeoffTables();
        $runway = [
            'length_ft' => 3000,
            'surface' => 'ASPH',
            'ends' => [
                [
                    'end_id' => 'bad',
                    'obstruction' => ['hgt_ft' => 200.0, 'dist_ft' => 500.0],
                ],
                [
                    'end_id' => 'good',
                    'obstruction' => [],
                ],
            ],
        ];

        $range = evaluateRunwayEndPerformanceRange($runway, 1000.0, 25.0, $tables);

        $this->assertSame('bad', $range['worst']['end_id']);
        $this->assertSame('good', $range['best']['end_id']);
        $this->assertGreaterThan($range['best']['total_risk'], $range['worst']['total_risk']);
    }

    public function testAsymmetricTierCautionWhenOnlyWorstEndConstrained(): void
    {
        $tables = loadPohTakeoffTables();
        $runway = [
            'length_ft' => 6600,
            'surface' => 'ASPH',
            'ends' => [
                [
                    'end_id' => 'good',
                    'obstruction' => [],
                ],
                [
                    'end_id' => 'bad',
                    'obstruction' => ['hgt_ft' => 135.0, 'dist_ft' => 2800.0],
                ],
            ],
        ];

        $range = evaluateRunwayEndPerformanceRange($runway, 90.0, 29.0, $tables);
        $tier = densityAltitudePerformanceTierFromEndRisks(
            $range['worst']['total_risk'],
            $range['best']['total_risk']
        );

        $this->assertSame('caution', $tier);
        $this->assertGreaterThanOrEqual(DENSITY_ALTITUDE_PERFORMANCE_TIER_CAUTION, $range['worst']['total_risk']);
        $this->assertLessThan(DENSITY_ALTITUDE_PERFORMANCE_TIER_WARNING, $range['best']['total_risk']);
    }

    public function testSyntheticShortTurfRunwayRemainsWarningOnBothEnds(): void
    {
        $tables = loadPohTakeoffTables();
        $runway = [
            'length_ft' => 2000,
            'surface' => 'TURF',
            'ends' => [
                ['end_id' => 'N', 'obstruction' => []],
                ['end_id' => 'S', 'obstruction' => []],
            ],
        ];

        $range = evaluateRunwayEndPerformanceRange($runway, 3441.0, 28.3, $tables);
        $tier = densityAltitudePerformanceTierFromEndRisks(
            $range['worst']['total_risk'],
            $range['best']['total_risk']
        );

        $this->assertSame('warning', $tier);
    }
}
