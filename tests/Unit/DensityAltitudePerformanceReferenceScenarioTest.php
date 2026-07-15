<?php
/**
 * SAFETY-CRITICAL: Locked density altitude performance reference scenarios.
 *
 * Fixture weather and NASR rows assert expected tiers from buildDensityAltitudePerformance().
 * Update tests/Fixtures/density-altitude-performance-scenarios.json only when POH tables,
 * tier policy, or runway selection rules change on purpose (see docs/SAFETY_CRITICAL_CALCULATIONS.md).
 */

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../lib/nasr/cache.php';
require_once __DIR__ . '/../../lib/weather/density-altitude-performance.php';

class DensityAltitudePerformanceReferenceScenarioTest extends TestCase
{
    private const SCENARIO_FIXTURE_PATH = __DIR__ . '/../Fixtures/density-altitude-performance-scenarios.json';

    private const EXPECTED_SCENARIO_COUNT = 12;

    /** @var list<string> */
    private const REQUIRED_SCENARIO_KEYS = [
        'airport_id',
        'faa',
        'elevation_ft',
        'density_altitude_ft',
        'pressure_altitude_ft',
        'temperature_c',
        'expected_tier',
    ];

    protected function setUp(): void
    {
        resetNasrAptCacheMemo();
        resetPohTakeoffTables();

        $built = nasrBuildCacheFromCsvDirectory(__DIR__ . '/../Fixtures/nasr');
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

    /**
     * @return array<string, array{array<string, mixed>}>
     */
    public static function referenceScenariosProvider(): array
    {
        $path = self::SCENARIO_FIXTURE_PATH;
        if (!is_readable($path)) {
            throw new \RuntimeException('Missing density altitude performance scenario fixture: ' . $path);
        }

        $decoded = json_decode((string) file_get_contents($path), true);
        if (!is_array($decoded)) {
            throw new \RuntimeException('Invalid density altitude performance scenario fixture JSON');
        }

        $cases = [];
        foreach ($decoded as $index => $row) {
            if (!is_array($row)) {
                throw new \RuntimeException('Scenario fixture row ' . $index . ' is not an object');
            }

            foreach (self::REQUIRED_SCENARIO_KEYS as $key) {
                if (!array_key_exists($key, $row) || $row[$key] === '' || $row[$key] === null) {
                    throw new \RuntimeException(
                        'Scenario fixture row ' . $index . ' missing required key: ' . $key
                    );
                }
            }

            $tier = (string) $row['expected_tier'];
            if (!in_array($tier, ['normal', 'caution', 'warning'], true)) {
                throw new \RuntimeException(
                    'Scenario fixture row ' . $index . ' has invalid expected_tier: ' . $tier
                );
            }
            if ($tier !== 'normal' && !isset($row['expected_risk_factor'])) {
                throw new \RuntimeException(
                    'Scenario fixture row ' . $index . ' must include expected_risk_factor when tier is not normal'
                );
            }

            $airportId = (string) $row['airport_id'];
            $cases[$airportId] = [$row];
        }

        if (count($cases) !== self::EXPECTED_SCENARIO_COUNT) {
            throw new \RuntimeException(
                'Expected ' . self::EXPECTED_SCENARIO_COUNT . ' reference scenarios, found ' . count($cases)
            );
        }

        return $cases;
    }

    #[DataProvider('referenceScenariosProvider')]
    public function testBuildDensityAltitudePerformance_ReferenceScenario_MatchesLockedTier(array $scenario): void
    {
        $airportId = (string) $scenario['airport_id'];
        $airport = [
            'id' => $airportId,
            'faa' => (string) $scenario['faa'],
            'elevation_ft' => (int) $scenario['elevation_ft'],
        ];
        if (!empty($scenario['icao'])) {
            $airport['icao'] = (string) $scenario['icao'];
        }

        $weather = [
            'density_altitude' => (int) $scenario['density_altitude_ft'],
            'pressure_altitude' => (float) $scenario['pressure_altitude_ft'],
            'temperature' => (float) $scenario['temperature_c'],
        ];

        $result = buildDensityAltitudePerformance($weather, $airport);
        $expectedTier = (string) $scenario['expected_tier'];

        if ($expectedTier === 'normal') {
            $this->assertNull(
                $result,
                $airportId . ': normal tier must omit density_altitude_performance'
            );

            return;
        }

        $this->assertNotNull($result, $airportId . ': expected non-normal tier');
        $this->assertSame($expectedTier, $result['tier'], $airportId . ': tier mismatch');
        $this->assertFalse($result['fallback'], $airportId . ': NASR scenario must not use weather-only fallback');
        $this->assertSame('reference_models', $result['reason'], $airportId . ': reason mismatch');
        $this->assertSame(DENSITY_ALTITUDE_PERFORMANCE_REFERENCE, $result['reference'], $airportId . ': reference mismatch');

        $this->assertEqualsWithDelta(
            (float) $scenario['expected_risk_factor'],
            (float) $result['risk_factor'],
            0.001,
            $airportId . ': risk_factor drift indicates POH or asymmetric tier math change'
        );
    }
}
