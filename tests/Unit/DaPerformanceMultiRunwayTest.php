<?php
/**
 * Multi-runway density altitude performance selection.
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

    public function testNasrPerformanceRunwaysExcludeCrossSurfaceStripsAt69v(): void
    {
        $nasrRecord = getNasrAirportForConfig(['faa' => '69V']);
        $this->assertNotNull($nasrRecord);

        $runways = nasrSelectActiveLandRunwaysForPerformance($nasrRecord);
        $this->assertCount(1, $runways);
        $this->assertSame('08/26', $runways[0]['rwy_id']);
    }

    public function testNasrPerformanceRunwaysIncludeAllAsphaltStripsAtHio(): void
    {
        $nasrRecord = getNasrAirportForConfig(['faa' => 'HIO']);
        $this->assertNotNull($nasrRecord);

        $runways = nasrSelectActiveLandRunwaysForPerformance($nasrRecord);
        $this->assertCount(3, $runways);
        $this->assertSame('13R/31L', $runways[0]['rwy_id']);
        $this->assertSame('02/20', $runways[1]['rwy_id']);
        $this->assertSame('13L/31R', $runways[2]['rwy_id']);
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

        $this->assertSame('31L', $evaluation['best']['end_id']);
        $this->assertSame('13R/31L', $evaluation['best']['rwy_id']);
    }

    public function testPickDepartureEndByWindAcrossRunwaysSelectsBestAlignedStrip(): void
    {
        $nasrRecord = getNasrAirportForConfig(['faa' => 'HIO']);
        $this->assertNotNull($nasrRecord);

        $runways = nasrSelectActiveLandRunwaysForPerformance($nasrRecord);
        $airport = ['faa' => 'HIO', 'magnetic_declination' => 16.0];

        $picked = pickDepartureEndByWindAcrossRunways($runways, $airport, 130.0);
        $this->assertNotNull($picked);
        $this->assertSame('13L', $picked['end_id']);
        $this->assertSame('13L/31R', $picked['rwy_id']);
    }

    public function testBothEndsTooltipNamesBestRunwayEndAcrossComparableStrips(): void
    {
        $tooltip = densityAltitudePerformanceTooltip('caution', [
            'tier' => 'caution',
            'selection_basis' => 'both_ends',
            'operational_end_id' => '31L',
            'operational_rwy_id' => '13R/31L',
        ]);

        $this->assertStringContainsString('RWY 31L (13R/31L)', $tooltip);
        $this->assertStringContainsString('comparable runways', $tooltip);
    }
}
