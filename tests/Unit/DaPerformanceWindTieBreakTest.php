<?php
/**
 * Wind-based tie-breaking for equal-risk departure ends.
 */

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../lib/weather/density-altitude-performance.php';

class DaPerformanceWindTieBreakTest extends TestCase
{
    public function testCrosswindKtsForPureCrosswind(): void
    {
        $cross = densityAltitudePerformanceCrosswindKts(90.0, 10.0, 0);
        $this->assertEqualsWithDelta(10.0, $cross, 0.001);
    }

    public function testHeadwindKtsForRunwayIntoWind(): void
    {
        $head = densityAltitudePerformanceHeadwindKts(270.0, 12.0, 90);
        $this->assertGreaterThan(11.0, $head);
    }

    public function testPickBestEndPrefersHeadwindWhenCrosswindEqual(): void
    {
        $scoredEnds = [
            [
                'total_risk' => 1.5,
                'end_id' => '25',
                'rwy_id' => '07/25',
                'true_alignment' => 250,
                'risk152' => 0.0,
                'risk172' => 0.0,
                'risk182' => 0.0,
            ],
            [
                'total_risk' => 1.5,
                'end_id' => '07',
                'rwy_id' => '07/25',
                'true_alignment' => 70,
                'risk152' => 0.0,
                'risk172' => 0.0,
                'risk182' => 0.0,
            ],
        ];

        $picked = pickBestWorstScoredEnds($scoredEnds, 250.0, 10.0);

        $this->assertSame('07', $picked['best']['end_id']);
    }

    public function testPickBestEndFallsBackToEndIdWithoutWind(): void
    {
        $scoredEnds = [
            [
                'total_risk' => 1.0,
                'end_id' => '25',
                'rwy_id' => '07/25',
                'true_alignment' => 250,
                'risk152' => 0.0,
                'risk172' => 0.0,
                'risk182' => 0.0,
            ],
            [
                'total_risk' => 1.0,
                'end_id' => '07',
                'rwy_id' => '07/25',
                'true_alignment' => 70,
                'risk152' => 0.0,
                'risk172' => 0.0,
                'risk182' => 0.0,
            ],
        ];

        $picked = pickBestWorstScoredEnds($scoredEnds, null, null);

        $this->assertSame('07', $picked['best']['end_id']);
    }

    public function testResolveWindFromPublicApiShape(): void
    {
        $wind = resolveDensityAltitudePerformanceWind([
            'wind_direction' => [
                'true_north' => 270,
                'magnetic_north' => 255,
                'variable' => false,
            ],
            'wind_speed' => 8,
        ]);

        $this->assertSame(270.0, $wind['direction']);
        $this->assertSame(8.0, $wind['speed']);
    }

    public function testComputeUsesWindTieBreakForEqualRiskOr81Ends(): void
    {
        require_once __DIR__ . '/../../lib/nasr/cache.php';
        resetNasrAptCacheMemo();
        setNasrAptCacheForTesting([
            'schema_version' => NASR_APT_SCHEMA_VERSION,
            'airports' => [
                'OR81' => [
                    'runways' => [[
                        'rwy_id' => '07/25',
                        'length_ft' => 2000,
                        'surface' => 'TURF-GRVL',
                        'condition' => '',
                        'ends' => [
                            ['end_id' => '07', 'true_alignment' => 70, 'obstruction' => []],
                            [
                                'end_id' => '25',
                                'true_alignment' => 250,
                                'obstruction' => ['type' => 'TREES', 'hgt_ft' => 100.0, 'dist_ft' => 2000.0],
                            ],
                        ],
                    ]],
                ],
            ],
        ]);

        $result = computeDensityAltitudePerformance([
            'density_altitude' => 1531,
            'pressure_altitude' => 85,
            'temperature' => 26.0,
            'wind_direction' => 250,
            'wind_speed' => 12,
        ], [
            'id' => 'or81',
            'faa' => 'OR81',
            'elevation_ft' => 185,
        ], 'or81');

        $this->assertIsArray($result);
        $this->assertSame('07', $result['best_end']['end_id']);
    }
}
