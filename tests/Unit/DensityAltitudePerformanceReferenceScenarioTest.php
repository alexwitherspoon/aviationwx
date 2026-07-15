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

require_once __DIR__ . '/../Helpers/LoadsNasrAptFixtureCacheTrait.php';
require_once __DIR__ . '/../../lib/weather/density-altitude-performance.php';

class DensityAltitudePerformanceReferenceScenarioTest extends TestCase
{
    use LoadsNasrAptFixtureCacheTrait;

    private const SCENARIO_FIXTURE_PATH = __DIR__ . '/../Fixtures/density-altitude-performance-scenarios.json';

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

    /** @var list<string> */
    private const REQUIRED_NON_NORMAL_KEYS = [
        'expected_risk_factor',
        'expected_worst_end_risk',
        'expected_best_end_risk',
    ];

    /** @var list<string> */
    private const REQUIRED_NUMERIC_KEYS = [
        'elevation_ft',
        'density_altitude_ft',
        'pressure_altitude_ft',
        'temperature_c',
    ];

    protected function setUp(): void
    {
        $this->loadNasrAptFixtureCache();
    }

    protected function tearDown(): void
    {
        $this->tearDownNasrAptFixtureCache();
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
        if (!is_array($decoded) || $decoded === []) {
            throw new \RuntimeException('Invalid or empty density altitude performance scenario fixture JSON');
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

            foreach (self::REQUIRED_NUMERIC_KEYS as $key) {
                if (!is_numeric($row[$key])) {
                    throw new \RuntimeException(
                        'Scenario fixture row ' . $index . ' has non-numeric value for: ' . $key
                    );
                }
            }

            $tier = (string) $row['expected_tier'];
            if (!in_array($tier, ['normal', 'caution', 'warning'], true)) {
                throw new \RuntimeException(
                    'Scenario fixture row ' . $index . ' has invalid expected_tier: ' . $tier
                );
            }
            if ($tier !== 'normal') {
                foreach (self::REQUIRED_NON_NORMAL_KEYS as $key) {
                    if (!isset($row[$key]) || !is_numeric($row[$key])) {
                        throw new \RuntimeException(
                            'Scenario fixture row ' . $index . ' missing required key: ' . $key
                        );
                    }
                }
            }

            $airportId = (string) $row['airport_id'];
            if (isset($cases[$airportId])) {
                throw new \RuntimeException(
                    'Scenario fixture has duplicate airport_id: ' . $airportId
                );
            }
            $cases[$airportId] = [$row];
        }

        if (count($cases) !== count($decoded)) {
            throw new \RuntimeException(
                'Scenario fixture row count does not match unique airport_id count'
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

        $this->assertEqualsWithDelta(
            (float) $scenario['expected_risk_factor'],
            (float) $result['risk_factor'],
            0.001,
            $airportId . ': risk_factor drift indicates POH or asymmetric tier math change'
        );
        $this->assertEqualsWithDelta(
            (float) $scenario['expected_worst_end_risk'],
            (float) $result['worst_end_risk'],
            0.001,
            $airportId . ': worst_end_risk drift indicates runway end scoring change'
        );
        $this->assertEqualsWithDelta(
            (float) $scenario['expected_best_end_risk'],
            (float) $result['best_end_risk'],
            0.001,
            $airportId . ': best_end_risk drift indicates runway end scoring change'
        );
    }
}
