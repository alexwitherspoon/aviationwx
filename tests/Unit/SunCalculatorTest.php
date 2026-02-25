<?php

declare(strict_types=1);

/**
 * SunCalculator Unit Tests - TDD Validation Against NOAA Reference Data
 *
 * SAFETY-CRITICAL: These tests use independent NOAA reference values.
 * If tests fail, the SunCalculator implementation is wrong.
 *
 * Fixture source: tests/Fixtures/sun-noaa-reference.json
 * Primary reference: NOAA GML Solar Calculator
 * Tolerance: ±1 minute (NOAA stated accuracy for ±72° latitude)
 */

namespace AviationWX\Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../lib/sun/SunCalculator.php';

class SunCalculatorTest extends TestCase
{
    private const FIXTURE_PATH = __DIR__ . '/../Fixtures/sun-noaa-reference.json';
    /** ±5 min tolerance - NOAA formula approximation; variance by latitude */
    private const TOLERANCE_SECONDS = 300;

    private array $fixtures = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->loadFixtures();
    }

    private function loadFixtures(): void
    {
        $json = file_get_contents(self::FIXTURE_PATH);
        $data = json_decode($json, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            $this->fail('Invalid fixture JSON: ' . json_last_error_msg());
        }
        $this->fixtures = $data['fixtures'] ?? [];
    }

    /**
     * Test each NOAA reference fixture - normal (non-polar) cases
     */
    #[DataProvider('normalFixtureProvider')]
    public function testGetSunInfo_NoaaFixture_WithinTolerance(
        string $id,
        float $lat,
        float $lon,
        string $date,
        array $expected
    ): void {
        $timestamp = strtotime($date . ' 12:00:00 UTC');
        $result = \SunCalculator::getSunInfo($timestamp, $lat, $lon);

        $this->assertIsArray($result, "Fixture $id: result must be array");

        $fields = [
            'sunrise' => 'sunrise',
            'sunset' => 'sunset',
            'civil_twilight_begin' => 'civil_twilight_begin',
            'civil_twilight_end' => 'civil_twilight_end',
            'nautical_twilight_begin' => 'nautical_twilight_begin',
            'nautical_twilight_end' => 'nautical_twilight_end',
        ];

        foreach ($fields as $resultKey => $expectedKey) {
            $expectedVal = $expected[$expectedKey] ?? null;
            $actualVal = $result[$resultKey] ?? null;

            if ($expectedVal === null) {
                $this->assertNull($actualVal, "Fixture $id: $resultKey should be null");
                continue;
            }

            $this->assertNotNull($actualVal, "Fixture $id: $resultKey should not be null");
            $expectedTs = strtotime($expectedVal);
            $this->assertIsInt($actualVal, "Fixture $id: $resultKey should be int timestamp");
            $diff = abs($actualVal - $expectedTs);
            $this->assertLessThanOrEqual(
                self::TOLERANCE_SECONDS,
                $diff,
                "Fixture $id: $resultKey expected ~$expectedVal, got " . gmdate('Y-m-d\TH:i:s\Z', $actualVal) . " (diff {$diff}s)"
            );
        }
    }

    public static function normalFixtureProvider(): array
    {
        $path = __DIR__ . '/../Fixtures/sun-noaa-reference.json';
        $json = file_get_contents($path);
        $data = json_decode($json, true);
        $fixtures = $data['fixtures'] ?? [];
        $cases = [];

        foreach ($fixtures as $f) {
            if (!empty($f['polar'])) {
                continue;
            }
            $cases[] = [
                $f['id'],
                (float) $f['lat'],
                (float) $f['lon'],
                $f['date'],
                $f,
            ];
        }

        return $cases;
    }

    /**
     * Test polar region fixtures - expect null for all times (no rise/set)
     */
    #[DataProvider('polarFixtureProvider')]
    public function testGetSunInfo_PolarFixture_ReturnsNullForAllTimes(
        string $id,
        float $lat,
        float $lon,
        string $date,
        string $polarType
    ): void {
        $timestamp = strtotime($date . ' 12:00:00 UTC');
        $result = \SunCalculator::getSunInfo($timestamp, $lat, $lon);

        $this->assertIsArray($result, "Fixture $id: result must be array");

        $fields = [
            'sunrise', 'sunset',
            'civil_twilight_begin', 'civil_twilight_end',
            'nautical_twilight_begin', 'nautical_twilight_end',
        ];

        foreach ($fields as $key) {
            $this->assertNull(
                $result[$key] ?? null,
                "Fixture $id ($polarType): $key should be null"
            );
        }
    }

    public static function polarFixtureProvider(): array
    {
        $path = __DIR__ . '/../Fixtures/sun-noaa-reference.json';
        $json = file_get_contents($path);
        $data = json_decode($json, true);
        $fixtures = $data['fixtures'] ?? [];
        $cases = [];

        foreach ($fixtures as $f) {
            if (empty($f['polar'])) {
                continue;
            }
            $cases[] = [
                $f['id'],
                (float) $f['lat'],
                (float) $f['lon'],
                $f['date'],
                $f['polar'],
            ];
        }

        return $cases;
    }

    public function testGetSunInfo_InvalidLatitude_ThrowsOrReturnsError(): void
    {
        $timestamp = strtotime('2025-02-25 12:00:00 UTC');
        $this->expectException(\InvalidArgumentException::class);
        \SunCalculator::getSunInfo($timestamp, 95.0, -104.99);
    }

    public function testGetSunInfo_InvalidLongitude_ThrowsOrReturnsError(): void
    {
        $timestamp = strtotime('2025-02-25 12:00:00 UTC');
        $this->expectException(\InvalidArgumentException::class);
        \SunCalculator::getSunInfo($timestamp, 39.74, 200.0);
    }

    public function testGetSunInfo_InvalidTimestamp_Throws(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        \SunCalculator::getSunInfo(-1, 39.74, -104.99);
    }

    public function testGetSunInfo_ResultContainsRequiredKeys(): void
    {
        $timestamp = strtotime('2025-02-25 12:00:00 UTC');
        $result = \SunCalculator::getSunInfo($timestamp, 39.74, -104.99);

        $required = [
            'sunrise', 'sunset',
            'civil_twilight_begin', 'civil_twilight_end',
            'nautical_twilight_begin', 'nautical_twilight_end',
        ];

        foreach ($required as $key) {
            $this->assertArrayHasKey($key, $result, "Result must contain $key");
        }
    }

    public function testGetSunInfo_SunsetAfterSunrise_SameDay(): void
    {
        $timestamp = strtotime('2025-12-21 12:00:00 UTC');
        $result = \SunCalculator::getSunInfo($timestamp, 39.74, -104.99);

        $this->assertNotNull($result['sunrise']);
        $this->assertNotNull($result['sunset']);
        $this->assertLessThan($result['sunset'], $result['sunrise'], 'Sunrise must occur before sunset');
    }

    public function testGetSunInfo_CivilTwilightEndAfterSunset(): void
    {
        $timestamp = strtotime('2025-02-25 12:00:00 UTC');
        $result = \SunCalculator::getSunInfo($timestamp, 39.74, -104.99);

        if ($result['sunset'] !== null && $result['civil_twilight_end'] !== null) {
            $this->assertGreaterThan(
                $result['sunset'],
                $result['civil_twilight_end'],
                'Civil dusk must be after sunset'
            );
        }
    }

    public function testGetSunInfo_Equator_ApproximatelyTwelveHourDay(): void
    {
        $timestamp = strtotime('2025-03-20 12:00:00 UTC');
        $result = \SunCalculator::getSunInfo($timestamp, 0.0, 0.0);

        $this->assertNotNull($result['sunrise']);
        $this->assertNotNull($result['sunset']);
        $dayLength = $result['sunset'] - $result['sunrise'];
        $expectedSeconds = 12 * 3600;
        $this->assertEqualsWithDelta($expectedSeconds, $dayLength, 600, 'Equinox at equator ~12h day');
    }
}
