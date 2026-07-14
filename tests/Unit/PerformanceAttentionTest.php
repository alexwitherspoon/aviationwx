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
        $this->assertGreaterThanOrEqual(0.70, $result['risk_factor']);
    }

    public function test03SSyntheticObstructionScenarioFlagsStrongTier(): void
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
        $this->assertSame('strong', $result['tier']);
        $this->assertFalse($result['fallback']);
        $this->assertGreaterThanOrEqual(0.70, $result['risk_factor']);
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
}
