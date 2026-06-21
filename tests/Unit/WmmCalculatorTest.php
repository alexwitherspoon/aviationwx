<?php

declare(strict_types=1);

/**
 * WmmCalculator Unit Tests - TDD validation against NOAA reference data
 *
 * SAFETY-CRITICAL: These tests use independent NOAA published test vectors.
 * If tests fail, the WmmCalculator implementation is wrong.
 *
 * Fixture source: tests/Fixtures/wmm-noaa-reference.json
 */

namespace AviationWX\Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../lib/wmm/WmmCoefficients.php';
require_once __DIR__ . '/../../lib/wmm/WmmCalculator.php';

class WmmCalculatorTest extends TestCase
{
    private const FIXTURE_PATH = __DIR__ . '/../Fixtures/wmm-noaa-reference.json';

    /** @var array<string, mixed>|null */
    private static ?array $fixtureData = null;

    #[DataProvider('noaaFixtureProvider')]
    public function testGetDeclinationForDecimalYear_NoaaFixture_WithinTolerance(
        string $id,
        float $decimalYear,
        float $altitudeKm,
        float $lat,
        float $lon,
        float $expectedDeclination,
        float $expectedInclination
    ): void {
        $toleranceDegrees = self::getToleranceDegrees();
        $declination = \WmmCalculator::getDeclinationForDecimalYear($decimalYear, $lat, $lon, $altitudeKm);

        $this->assertEqualsWithDelta(
            $expectedDeclination,
            $declination,
            $toleranceDegrees,
            "Fixture $id: declination mismatch"
        );

        $elements = \WmmCalculator::getElements(
            \WmmCalculator::decimalYearToTimestamp($decimalYear),
            $lat,
            $lon,
            $altitudeKm
        );
        $this->assertEqualsWithDelta(
            $expectedInclination,
            $elements['inclination'],
            $toleranceDegrees,
            "Fixture $id: inclination mismatch"
        );
    }

    public function testGetDeclination_RejectsInvalidLatitude(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        \WmmCalculator::getDeclination(time(), 95.0, 0.0);
    }

    public function testGetDeclination_RejectsInvalidLongitude(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        \WmmCalculator::getDeclination(time(), 0.0, 200.0);
    }

    public function testGetDeclination_RejectsNegativeTimestamp(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        \WmmCalculator::getDeclination(-1, 0.0, 0.0);
    }

    public function testTimestampToDecimalYear_MidYear(): void
    {
        // 2025-07-02 00:00:00 UTC is approximately decimal year 2025.5
        $ts = gmmktime(0, 0, 0, 7, 2, 2025);
        $decimalYear = \WmmCalculator::timestampToDecimalYear($ts);
        $this->assertEqualsWithDelta(2025.5, $decimalYear, 0.01);
    }

    public function testDecimalYearToTimestamp_MidYear_RoundTrips(): void
    {
        $decimalYear = 2025.5;
        $timestamp = \WmmCalculator::decimalYearToTimestamp($decimalYear);
        $this->assertEqualsWithDelta($decimalYear, \WmmCalculator::timestampToDecimalYear($timestamp), 0.0001);
    }

    public function testGetDeclination_TimestampPathMatchesDecimalYear(): void
    {
        $decimalYear = 2025.0;
        $lat = 14.0;
        $lon = 143.0;
        $altitudeKm = 66.0;
        $ts = \WmmCalculator::decimalYearToTimestamp($decimalYear);

        $fromDecimalYear = \WmmCalculator::getDeclinationForDecimalYear($decimalYear, $lat, $lon, $altitudeKm);
        $fromTimestamp = \WmmCalculator::getDeclination($ts, $lat, $lon, $altitudeKm);

        $this->assertEqualsWithDelta($fromDecimalYear, $fromTimestamp, 0.001);
    }

    public static function noaaFixtureProvider(): array
    {
        $fixtures = self::loadFixtureData()['fixtures'] ?? [];
        if ($fixtures === []) {
            throw new \RuntimeException('WMM fixture file contains no test vectors');
        }
        $cases = [];

        foreach ($fixtures as $fixture) {
            $cases[$fixture['id']] = [
                $fixture['id'],
                (float) $fixture['decimal_year'],
                (float) $fixture['altitude_km'],
                (float) $fixture['lat'],
                (float) $fixture['lon'],
                (float) $fixture['declination'],
                (float) $fixture['inclination'],
            ];
        }

        return $cases;
    }

    private static function getToleranceDegrees(): float
    {
        return (float) (self::loadFixtureData()['_meta']['tolerance_degrees'] ?? 0.05);
    }

    /**
     * @return array<string, mixed>
     */
    private static function loadFixtureData(): array
    {
        if (self::$fixtureData !== null) {
            return self::$fixtureData;
        }

        $json = file_get_contents(self::FIXTURE_PATH);
        if ($json === false) {
            throw new \RuntimeException('Failed to read WMM fixture file: ' . self::FIXTURE_PATH);
        }

        $data = json_decode($json, true);
        if (!is_array($data) || json_last_error() !== JSON_ERROR_NONE) {
            throw new \RuntimeException('Invalid WMM fixture JSON: ' . json_last_error_msg());
        }

        self::$fixtureData = $data;

        return self::$fixtureData;
    }
}
