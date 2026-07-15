<?php
/**
 * SAFETY-CRITICAL: Density altitude performance assessment.
 */

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../Helpers/LoadsNasrAptFixtureCacheTrait.php';
require_once __DIR__ . '/../../lib/runways.php';
require_once __DIR__ . '/../../lib/density-altitude-performance-display.php';
require_once __DIR__ . '/../../lib/weather/density-altitude-performance.php';

class DensityAltitudePerformanceTest extends TestCase
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
        $this->assertSame(DENSITY_ALTITUDE_PERFORMANCE_REFERENCE_FALLBACK, $result['reference']);
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
        $this->assertSame(DENSITY_ALTITUDE_PERFORMANCE_REFERENCE_FALLBACK, $result['reference']);
        $this->assertNull($result['risk_factor']);
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

    public function testCyavUsesOurAirportsLongestRunwayWhenNasrAbsent(): void
    {
        $result = buildDensityAltitudePerformance([
            'density_altitude' => 5000,
            'pressure_altitude' => 4500,
            'temperature' => 35,
        ], [
            'id' => 'cyav',
            'icao' => 'CYAV',
            'elevation_ft' => 759,
        ]);

        $this->assertNotNull($result);
        $this->assertSame('caution', $result['tier']);
        $this->assertFalse($result['fallback']);
        $this->assertSame('reference_models_ourairports', $result['reason']);
        $this->assertSame(DENSITY_ALTITUDE_PERFORMANCE_REFERENCE_OURAIRPORTS, $result['reference']);
    }

    public function testConfigRunwayOverrideCapsWarningAtCaution(): void
    {
        $result = buildDensityAltitudePerformance([
            'density_altitude' => 5000,
            'pressure_altitude' => 3441,
            'temperature' => 28.3,
        ], [
            'id' => 'zzcfg',
            'icao' => 'ZZCFG',
            'elevation_ft' => 3647,
            'runway_length_ft' => 2000,
            'runway_surface' => 'TURF',
        ]);

        $this->assertNotNull($result);
        $this->assertSame('caution', $result['tier']);
        $this->assertSame('reference_models', $result['reason']);
        $this->assertSame(DENSITY_ALTITUDE_PERFORMANCE_REFERENCE_CONFIG, $result['reference']);
        $this->assertGreaterThanOrEqual(DENSITY_ALTITUDE_PERFORMANCE_TIER_WARNING, $result['worst_end_risk']);
    }

    public function testOurAirportsPathCapsWarningAtCaution(): void
    {
        $result = buildDensityAltitudePerformance([
            'density_altitude' => 5000,
            'pressure_altitude' => 3441,
            'temperature' => 28.3,
        ], [
            'id' => 'zzoa',
            'icao' => 'ZZOA',
            'elevation_ft' => 3647,
        ]);

        $this->assertNotNull($result);
        $this->assertSame('caution', $result['tier']);
        $this->assertSame('reference_models_ourairports', $result['reason']);
        $this->assertGreaterThanOrEqual(DENSITY_ALTITUDE_PERFORMANCE_TIER_WARNING, $result['worst_end_risk']);
    }

    public function testOurAirportsSelectsLongestNonWaterRunway(): void
    {
        $runways = [
            ['length_ft' => 2850, 'surface' => 'ASPH', 'le_ident' => '04', 'he_ident' => '22'],
            ['length_ft' => 3000, 'surface' => 'ASPH', 'le_ident' => '13', 'he_ident' => '31'],
            ['length_ft' => 4500, 'surface' => 'WATER', 'le_ident' => '01', 'he_ident' => '19'],
        ];

        $selected = ourAirportsSelectLongestActiveLandRunway(buildOurAirportsPerformanceRunways($runways));

        $this->assertNotNull($selected);
        $this->assertSame(3000, $selected['length_ft']);
        $this->assertSame('13/31', $selected['rwy_id']);
    }

    public function testFallbackTooltipMentionsMissingRunwayData(): void
    {
        $tooltip = densityAltitudePerformanceTooltip('warning', [
            'tier' => 'warning',
            'fallback' => true,
            'reason' => 'density_altitude_only',
        ]);

        $this->assertStringContainsString('Runway data unavailable', $tooltip);
        $this->assertStringContainsString('field elevation only', $tooltip);
    }

    public function testFallbackAriaLabelMentionsMissingRunwayData(): void
    {
        $aria = densityAltitudePerformanceAriaLabel(9500, 'warning', 'ft', [
            'tier' => 'warning',
            'fallback' => true,
            'reason' => 'density_altitude_only',
        ]);

        $this->assertStringContainsString('Runway data unavailable', $aria);
        $this->assertStringContainsString('field elevation only', $aria);
    }

    public function testDensityAltitudePerformanceTierFromScoredEndThresholds(): void
    {
        $this->assertSame('normal', densityAltitudePerformanceTierFromScoredEnd(1.19));
        $this->assertSame('caution', densityAltitudePerformanceTierFromScoredEnd(1.20));
        $this->assertSame('warning', densityAltitudePerformanceTierFromScoredEnd(2.40));
    }

    public function testAsymmetricHeuristicSuppressesFalsePositiveOn69v(): void
    {
        $result = buildDensityAltitudePerformance([
            'density_altitude' => 9399,
            'pressure_altitude' => 5673,
            'temperature' => 34.7,
        ], [
            'id' => '69v',
            'faa' => '69V',
            'elevation_ft' => 5915,
        ], '69v');

        $this->assertNull($result);
    }

    public function testOr81WarmDayScoresFavorableEndWhenSpreadHigh(): void
    {
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

        $result = buildDensityAltitudePerformance([
            'density_altitude' => 1531,
            'pressure_altitude' => 85,
            'temperature' => 26.0,
        ], [
            'id' => 'or81',
            'faa' => 'OR81',
            'elevation_ft' => 185,
            'magnetic_declination' => 13,
            'runways' => [
                ['name' => '07/25', 'heading_1' => 70, 'heading_2' => 250],
            ],
        ], 'or81');

        $this->assertIsArray($result);
        $this->assertSame('caution', $result['tier']);
        $this->assertSame('asymmetric_heuristic', $result['selection_basis']);
        $this->assertSame('07', $result['operational_end_id']);
        $this->assertGreaterThanOrEqual(DENSITY_ALTITUDE_PERFORMANCE_TIER_CAUTION, $result['scored_end_risk']);
        $this->assertLessThan(DENSITY_ALTITUDE_PERFORMANCE_TIER_WARNING, $result['scored_end_risk']);
        $this->assertSame(3.0, $result['worst_end_risk']);
    }

    public function testOr81CoolDayNormalOnFavorableEnd(): void
    {
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

        $result = buildDensityAltitudePerformance([
            'density_altitude' => 126,
            'pressure_altitude' => 75,
            'temperature' => 15.0,
        ], [
            'id' => 'or81',
            'faa' => 'OR81',
            'elevation_ft' => 185,
            'magnetic_declination' => 13,
            'runways' => [
                ['name' => '07/25', 'heading_1' => 70, 'heading_2' => 250],
            ],
        ], 'or81');

        $this->assertNull($result);
    }

    public function testKpfcLikeAsymmetricStripUsesBestEndHeuristic(): void
    {
        require_once __DIR__ . '/../../lib/nasr/cache.php';
        resetNasrAptCacheMemo();
        setNasrAptCacheForTesting([
            'schema_version' => NASR_APT_SCHEMA_VERSION,
            'airports' => [
                'PFC' => [
                    'runways' => [[
                        'rwy_id' => '14/32',
                        'length_ft' => 5000,
                        'surface' => 'ASPH',
                        'condition' => 'GOOD',
                        'ends' => [
                            [
                                'end_id' => '14',
                                'true_alignment' => 140,
                                'obstruction' => ['hgt_ft' => 200.0, 'dist_ft' => 500.0],
                            ],
                            [
                                'end_id' => '32',
                                'true_alignment' => 320,
                                'obstruction' => [],
                            ],
                        ],
                    ]],
                ],
            ],
        ]);

        $result = buildDensityAltitudePerformance([
            'density_altitude' => 500,
            'pressure_altitude' => 100,
            'temperature' => 15,
        ], [
            'id' => 'kpfc',
            'faa' => 'PFC',
            'icao' => 'KPFC',
            'elevation_ft' => 10,
        ], 'kpfc');

        $this->assertNull($result);

        $tables = loadPohTakeoffTables();
        $nasrRecord = getNasrAirportForConfig(['faa' => 'PFC', 'icao' => 'KPFC']);
        $this->assertNotNull($nasrRecord);
        $runway = nasrSelectLongestActiveLandRunway($nasrRecord);
        $evaluation = evaluateRunwayEndPerformanceRange($runway, 100.0, 15.0, $tables);
        $selection = resolveDensityAltitudePerformanceEndSelection(
            $evaluation,
            $runway,
            ['faa' => 'PFC', 'magnetic_declination' => 14.0],
            null,
            100.0,
            15.0,
            $tables,
            'nasr'
        );

        $this->assertSame('asymmetric_heuristic', $selection['selection_basis']);
        $this->assertSame('32', $selection['operational_end_id']);
        $this->assertLessThan(DENSITY_ALTITUDE_PERFORMANCE_TIER_CAUTION, $selection['scored_end']['total_risk']);
    }

    public function testSelectionBasisTooltipMentionsScoredDepartureEnd(): void
    {
        $tooltip = densityAltitudePerformanceTooltip('caution', [
            'tier' => 'caution',
            'selection_basis' => 'asymmetric_heuristic',
            'operational_end_id' => '32',
        ]);

        $this->assertStringContainsString('RWY 32', $tooltip);
        $this->assertStringContainsString('reference takeoff performance', $tooltip);
    }

    public function testCapTierWithoutObstructionsDowngradesWarning(): void
    {
        $this->assertSame('caution', densityAltitudePerformanceCapTierWithoutObstructions('warning'));
        $this->assertSame('caution', densityAltitudePerformanceCapTierWithoutObstructions('caution'));
        $this->assertSame('normal', densityAltitudePerformanceCapTierWithoutObstructions('normal'));
    }
}
