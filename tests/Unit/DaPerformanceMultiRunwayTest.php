<?php
/**
 * Multi-runway DA performance: all active strips scored; tier from global best end.
 */

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../Helpers/LoadsNasrAptFixtureCacheTrait.php';
require_once __DIR__ . '/../../lib/weather/density-altitude-performance.php';
require_once __DIR__ . '/../../lib/density-altitude-performance-display.php';

class DaPerformanceMultiRunwayTest extends TestCase
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

    public function testNasrPerformanceRunwaysIncludeAllSelectableStripsAt69v(): void
    {
        $nasrRecord = getNasrAirportForConfig(['faa' => '69V']);
        $this->assertNotNull($nasrRecord);

        $runways = nasrSelectActiveLandRunwaysForPerformance($nasrRecord);
        $this->assertCount(3, $runways);
        $this->assertSame('08/26', $runways[0]['rwy_id']);
    }

    public function testNasrPerformanceRunwaysIncludeAllStripsAtHio(): void
    {
        $nasrRecord = getNasrAirportForConfig(['faa' => 'HIO']);
        $this->assertNotNull($nasrRecord);

        $runways = nasrSelectActiveLandRunwaysForPerformance($nasrRecord);
        $this->assertCount(3, $runways);
        $this->assertSame('13R/31L', $runways[0]['rwy_id']);
    }

    public function testEvaluateAirportRunwayEndPerformanceRangeFindsGlobalBestAtHio(): void
    {
        $nasrRecord = getNasrAirportForConfig(['faa' => 'HIO']);
        $this->assertNotNull($nasrRecord);

        $runways = nasrSelectActiveLandRunwaysForPerformance($nasrRecord);
        $tables = loadPohTakeoffTables();
        $evaluation = evaluateAirportRunwayEndPerformanceRange(
            $runways,
            90.0,
            29.0,
            $tables
        );

        $this->assertSame('13R/31L', $evaluation['best']['rwy_id']);
        $this->assertEqualsWithDelta(0.0, $evaluation['best']['total_risk'], 0.001);
    }

    public function testTierUsesBestEndOnlyAt69v(): void
    {
        $nasrRecord = getNasrAirportForConfig(['faa' => '69V']);
        $this->assertNotNull($nasrRecord);

        $runways = nasrSelectActiveLandRunwaysForPerformance($nasrRecord);
        $tables = loadPohTakeoffTables();
        $evaluation = evaluateAirportRunwayEndPerformanceRange($runways, 5673.0, 34.7, $tables);

        $this->assertGreaterThanOrEqual(3.0, $evaluation['worst']['total_risk']);
        $this->assertLessThan(DENSITY_ALTITUDE_PERFORMANCE_TIER_CAUTION, $evaluation['best']['total_risk']);
        $this->assertSame(
            'normal',
            densityAltitudePerformanceTierFromScoredEnd($evaluation['best']['total_risk'])
        );
    }

    public function testBuildPerformanceUsesBestEndAcrossRunwaysAt69v(): void
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
        $this->assertSame('26', $result['best_end']['end_id']);
    }

    public function testBestPerformanceTooltipNamesScoredDepartureEnd(): void
    {
        $tooltip = densityAltitudePerformanceTooltip('caution', [
            'tier' => 'caution',
            'selection_basis' => 'best_performance',
            'best_end' => [
                'end_id' => '31L',
                'rwy_id' => '13R/31L',
            ],
        ]);

        $this->assertStringContainsString('RWY 31L (13R/31L)', $tooltip);
        $this->assertStringContainsString('best option among runways on file', $tooltip);
    }
}
