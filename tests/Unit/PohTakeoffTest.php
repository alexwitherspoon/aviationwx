<?php
/**
 * SAFETY-CRITICAL: AFM takeoff table lookup and grass correction.
 */

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../lib/weather/poh-takeoff.php';

class PohTakeoffTest extends TestCase
{
    protected function setUp(): void
    {
        resetPohTakeoffTables();
    }

    public function testC172ValidationSamplesFromFixture(): void
    {
        $tables = loadPohTakeoffTables();
        $table = $tables['c172'];

        foreach ($table['validation_samples'] as $sample) {
            $got = pohLookupChartTotalFt($table, (float) $sample['pa_ft'], (float) $sample['temp_c']);
            $this->assertSame(
                (int) $sample['expected_total_ft'],
                $got,
                'C172 validation sample failed: ' . json_encode($sample)
            );
        }
    }

    public function testGrassCorrectionUsesFifteenPercentGroundRoll(): void
    {
        $tables = loadPohTakeoffTables();
        $table = $tables['c152'];

        $chartTotal = pohLookupChartTotalFt($table, 5000, 30);
        $groundRoll = pohLookupChartGroundRollFt($table, 5000, 30);
        $corrected = pohApplyGrassCorrection($chartTotal, $groundRoll);

        $this->assertSame(2525, $chartTotal);
        $this->assertSame(1315, $groundRoll);
        $this->assertSame(2525 + (int) round(1315 * 0.15), $corrected);
    }

    public function testPohTablesIncreaseMonotonicallyWithTemperature(): void
    {
        $tables = loadPohTakeoffTables();

        foreach (['c152', 'c172', 'c182'] as $model) {
            $table = $tables[$model];
            foreach ($table['total_ft'] as $paKey => $tempRow) {
                $prevTotal = null;
                $prevGround = null;
                foreach ($table['temperature_c'] as $temp) {
                    $tempKey = (string) $temp;
                    $total = $table['total_ft'][$paKey][$tempKey];
                    $ground = $table['ground_roll_ft'][$paKey][$tempKey];
                    if ($prevTotal !== null) {
                        $this->assertGreaterThanOrEqual(
                            $prevTotal,
                            $total,
                            "{$model} PA {$paKey} total not monotonic at {$tempKey}C"
                        );
                        $this->assertGreaterThanOrEqual(
                            $prevGround,
                            $ground,
                            "{$model} PA {$paKey} ground roll not monotonic at {$tempKey}C"
                        );
                    }
                    $prevTotal = $total;
                    $prevGround = $ground;
                }
            }
        }
    }

    public function testObstacleHeightRatioScalesBelowFiftyFeet(): void
    {
        $tables = loadPohTakeoffTables();
        $table = $tables['c172'];
        $chartTotal = pohChartSurfaceTotalFt($table, 800.0, 30.0, true);

        $stressFourFt = pohComputeDepartureEndStress($table, 800.0, 30.0, true, 2115, 4.0, 142.0);
        $expectedFourFt = ($chartTotal * pohObstacleHeightRatio(4.0)) / 142.0;

        $this->assertEqualsWithDelta($expectedFourFt, $stressFourFt, 0.01);
        $this->assertLessThan($chartTotal / 142.0, $stressFourFt);
    }

    public function testFiftyFootObstacleUsesFullChartDistance(): void
    {
        $tables = loadPohTakeoffTables();
        $table = $tables['c172'];
        $chartTotal = pohChartSurfaceTotalFt($table, 800.0, 30.0, false);

        $stress = pohComputeDepartureEndStress($table, 800.0, 30.0, false, 3000, 50.0, 2000.0);
        $expected = $chartTotal / 2000.0;

        $this->assertEqualsWithDelta($expected, $stress, 0.01);
    }

    public function testTallObstacleScalesAboveFiftyFootChartReference(): void
    {
        $tables = loadPohTakeoffTables();
        $table = $tables['c172'];
        $chartTotal = pohChartSurfaceTotalFt($table, 90.0, 29.0, false);

        $stress = pohComputeDepartureEndStress($table, 90.0, 29.0, false, 6600, 135.0, 2800.0);
        $expected = ($chartTotal * pohObstacleHeightRatio(135.0)) / 2800.0;

        $this->assertEqualsWithDelta($expected, $stress, 0.01);
        $this->assertGreaterThan($chartTotal / 2800.0, $stress);
    }

    public function testNasrClearanceSlopeSuppressesObstacleOnPublishedSurface(): void
    {
        $tables = loadPohTakeoffTables();
        $table = $tables['c172'];
        $chartTotal = pohChartSurfaceTotalFt($table, 90.0, 29.0, false);

        $stressWithSlope = pohComputeDepartureEndStress(
            $table,
            90.0,
            29.0,
            false,
            6600,
            135.0,
            2800.0,
            19.0
        );
        $stressRunwayOnly = $chartTotal / 6600.0;

        $this->assertEqualsWithDelta($stressRunwayOnly, $stressWithSlope, 0.01);
        $this->assertSame(0.0, pohEffectiveObstacleHeightForChart(135.0, 2800.0, 19.0));
    }

    public function testNasrClearanceSlopeUsesFullHeightWhenPenetrating(): void
    {
        $tables = loadPohTakeoffTables();
        $table = $tables['c172'];
        $chartTotal = pohChartSurfaceTotalFt($table, 90.0, 29.0, false);

        $stress = pohComputeDepartureEndStress(
            $table,
            90.0,
            29.0,
            false,
            6600,
            150.0,
            2800.0,
            19.0
        );
        $expected = ($chartTotal * pohObstacleHeightRatio(150.0)) / 2800.0;

        $this->assertEqualsWithDelta($expected, $stress, 0.01);
        $this->assertSame(150.0, pohEffectiveObstacleHeightForChart(150.0, 2800.0, 19.0));
    }
}
