<?php
/**
 * SAFETY-CRITICAL: Density altitude performance attention assessment.
 */

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../lib/nasr/cache.php';
require_once __DIR__ . '/../../lib/weather/performance-attention.php';

class PerformanceAttentionTest extends TestCase
{
    private string $nasrFixtureDir;

    protected function setUp(): void
    {
        $this->nasrFixtureDir = __DIR__ . '/../Fixtures/nasr';
        resetNasrAptCacheMemo();
        resetPohTakeoffTables();

        $built = nasrBuildCacheFromCsvDirectory($this->nasrFixtureDir);
        setNasrAptCacheForTesting([
            'schema_version' => 1,
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
        $result = buildPerformanceAttention(['temperature' => 20, 'pressure_altitude' => 1000], [
            'id' => 'id76',
            'faa' => 'ID76',
            'elevation_ft' => 4925,
        ]);
        $this->assertNull($result);
    }

    public function testFallbackOmitsRiskFactor(): void
    {
        $result = buildPerformanceAttention([
            'density_altitude' => 9500,
            'pressure_altitude' => 9000,
            'temperature' => 35,
        ], [
            'id' => 'unknown',
            'elevation_ft' => 500,
        ]);

        $this->assertNotNull($result);
        $this->assertSame('strong', $result['tier']);
        $this->assertTrue($result['fallback']);
        $this->assertNull($result['risk_factor']);
        $this->assertSame('density_altitude_only', $result['reason']);
    }

    public function testId76AfternoonScenarioFlagsStrongTier(): void
    {
        $result = buildPerformanceAttention([
            'density_altitude' => 6280,
            'pressure_altitude' => 4570,
            'temperature' => 20.1,
        ], [
            'id' => 'id76',
            'faa' => 'ID76',
            'elevation_ft' => 4925,
        ]);

        $this->assertNotNull($result);
        $this->assertSame('strong', $result['tier']);
        $this->assertFalse($result['fallback']);
        $this->assertIsFloat($result['risk_factor']);
        $this->assertGreaterThanOrEqual(PERFORMANCE_ATTENTION_TIER_STRONG, $result['risk_factor']);
    }

    public function test03SRiskFactorReportsWorstEndSumForCaution(): void
    {
        $result = buildPerformanceAttention([
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
        $this->assertGreaterThanOrEqual(PERFORMANCE_ATTENTION_TIER_CAUTION, $result['risk_factor']);
    }

    public function testKhioLongRunwayStaysSilent(): void
    {
        $result = buildPerformanceAttention([
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

    public function testObstructionMultiplierCapsAtThree(): void
    {
        $mult = calculateDepartureObstructionMultiplier(142, 583, 2115);
        $this->assertEqualsWithDelta(3.0, $mult, 0.001);
    }

    public function testSummedPerformanceRiskUsesUnweightedSum(): void
    {
        $this->assertEqualsWithDelta(2.1, calculateSummedPerformanceRisk(0.8, 0.7, 0.6), 0.001);
        $this->assertEqualsWithDelta(3.0, calculateSummedPerformanceRisk(1.0, 1.0, 1.0), 0.001);
    }

    public function testPerformanceAttentionTierThresholdsOnSummedRisk(): void
    {
        $this->assertSame('none', performanceAttentionTierForRisk(1.19));
        $this->assertSame('caution', performanceAttentionTierForRisk(1.20));
        $this->assertSame('caution', performanceAttentionTierForRisk(2.39));
        $this->assertSame('strong', performanceAttentionTierForRisk(2.40));
    }

    public function testSingleHighProfileDoesNotReachStrongTierAlone(): void
    {
        $this->assertSame('caution', performanceAttentionTierForRisk(calculateSummedPerformanceRisk(1.0, 0.2, 0.15)));
    }

    public function testAsymmetricTierStrongRequiresBestEndAboveThreshold(): void
    {
        $this->assertSame('caution', performanceAttentionTierFromEndRisks(3.0, 1.0));
        $this->assertSame('caution', performanceAttentionTierFromEndRisks(3.0, 1.5));
        $this->assertSame('caution', performanceAttentionTierFromEndRisks(2.5, 2.39));
        $this->assertSame('strong', performanceAttentionTierFromEndRisks(3.0, 2.4));
        $this->assertSame('strong', performanceAttentionTierFromEndRisks(2.4, 2.4));
        $this->assertSame('none', performanceAttentionTierFromEndRisks(1.19, 1.0));
    }

    public function testAsymmetricTierCautionUsesWorstEndOnly(): void
    {
        $this->assertSame('none', performanceAttentionTierFromEndRisks(1.19, 0.5));
        $this->assertSame('caution', performanceAttentionTierFromEndRisks(1.2, 0.5));
        $this->assertSame('caution', performanceAttentionTierFromEndRisks(2.0, 1.0));
    }

    public function testRiskFactorUsesBestEndForStrongAndWorstEndForCaution(): void
    {
        $this->assertEqualsWithDelta(
            2.5,
            performanceAttentionRiskFactorForTier('strong', 3.0, 2.5),
            0.001
        );
        $this->assertEqualsWithDelta(
            2.2,
            performanceAttentionRiskFactorForTier('caution', 2.2, 0.8),
            0.001
        );
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

    public function testSyntheticDualEndRunwayDowngradesStrongToCaution(): void
    {
        $tables = loadPohTakeoffTables();
        $runway = [
            'length_ft' => 3425,
            'surface' => 'ASPH',
            'ends' => [
                [
                    'end_id' => '32',
                    'obstruction' => ['hgt_ft' => 212.0, 'dist_ft' => 3059.0],
                ],
                [
                    'end_id' => '14',
                    'obstruction' => ['hgt_ft' => 77.0, 'dist_ft' => 1002.0],
                ],
            ],
        ];

        $range = evaluateRunwayEndPerformanceRange($runway, 148.0, 29.9, $tables);
        $tier = performanceAttentionTierFromEndRisks(
            $range['worst']['total_risk'],
            $range['best']['total_risk']
        );

        $this->assertSame('caution', $tier);
        $this->assertGreaterThanOrEqual(PERFORMANCE_ATTENTION_TIER_CAUTION, $range['worst']['total_risk']);
        $this->assertLessThan(PERFORMANCE_ATTENTION_TIER_STRONG, $range['best']['total_risk']);
    }

    public function testSyntheticShortTurfRunwayRemainsStrongOnBothEnds(): void
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
        $tier = performanceAttentionTierFromEndRisks(
            $range['worst']['total_risk'],
            $range['best']['total_risk']
        );

        $this->assertSame('strong', $tier);
        $this->assertGreaterThanOrEqual(PERFORMANCE_ATTENTION_TIER_STRONG, $range['best']['total_risk']);
    }

    public function testConfigRunwayOverrideUsesSingleEndScoring(): void
    {
        $result = buildPerformanceAttention([
            'density_altitude' => 8000,
            'pressure_altitude' => 6000,
            'temperature' => 30,
        ], [
            'id' => 'custom',
            'elevation_ft' => 5000,
            'runway_length_ft' => 1200,
            'runway_surface' => 'TURF',
        ]);

        $this->assertNotNull($result);
        $this->assertContains($result['tier'], ['caution', 'strong']);
    }
}
