<?php
/**
 * Unit tests for NASR airports.json cross-check warnings.
 */

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../Helpers/LoadsNasrAptFixtureCacheTrait.php';
require_once __DIR__ . '/../../lib/nasr/config-cross-check.php';

class NasrConfigCrossCheckTest extends TestCase
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

    public function testElevationMatchWithinToleranceProducesNoWarning(): void
    {
        $result = nasrCrossCheckAirportConfig([
            'airports' => [
                '69v' => [
                    'id' => '69v',
                    'faa' => '69V',
                    'elevation_ft' => 5915,
                ],
            ],
        ]);

        $this->assertSame([], $result['warnings']);
        $this->assertSame(1, $result['summary']['checked']);
    }

    public function testElevationMismatchProducesWarning(): void
    {
        $result = nasrCrossCheckAirportConfig([
            'airports' => [
                '69v' => [
                    'id' => '69v',
                    'faa' => '69V',
                    'elevation_ft' => 5800,
                ],
            ],
        ]);

        $this->assertCount(1, $result['warnings']);
        $this->assertStringContainsString("Airport '69v' (69V)", $result['warnings'][0]);
        $this->assertStringContainsString('elevation_ft 5800 differs from NASR 5915', $result['warnings'][0]);
        $this->assertSame(1, $result['summary']['elevation_warnings']);
    }

    public function testMagneticMatchWithinToleranceProducesNoWarning(): void
    {
        $result = nasrCrossCheckAirportConfig([
            'airports' => [
                '69v' => [
                    'id' => '69v',
                    'faa' => '69V',
                    'magnetic_declination' => 14,
                ],
            ],
        ]);

        $this->assertSame([], $result['warnings']);
        $this->assertSame(1, $result['summary']['checked']);
    }

    public function testMagneticMismatchProducesWarning(): void
    {
        $result = nasrCrossCheckAirportConfig([
            'airports' => [
                'keul' => [
                    'id' => 'keul',
                    'faa' => 'EUL',
                    'magnetic_declination' => 14,
                ],
            ],
        ]);

        $this->assertCount(1, $result['warnings']);
        $this->assertStringContainsString("Airport 'keul' (EUL)", $result['warnings'][0]);
        $this->assertStringContainsString('magnetic_declination 14 differs from NASR 16°E (year 2000)', $result['warnings'][0]);
        $this->assertSame(1, $result['summary']['magnetic_warnings']);
    }

    public function testMagneticExactlyAtTolerancePasses(): void
    {
        $result = nasrCrossCheckAirportConfig([
            'airports' => [
                'keul' => [
                    'id' => 'keul',
                    'faa' => 'EUL',
                    'magnetic_declination' => 15,
                ],
            ],
        ]);

        $this->assertSame([], $result['warnings']);
    }

    public function testNoNasrRowIsSkippedWithoutWarning(): void
    {
        $result = nasrCrossCheckAirportConfig([
            'airports' => [
                'zzzz' => [
                    'id' => 'zzzz',
                    'faa' => 'ZZZZ',
                    'elevation_ft' => 1000,
                    'magnetic_declination' => 10,
                ],
            ],
        ]);

        $this->assertSame([], $result['warnings']);
        $this->assertSame(0, $result['summary']['checked']);
        $this->assertSame(1, $result['summary']['skipped_no_nasr']);
    }

    public function testConfigMissingFieldIsSkippedWithoutWarning(): void
    {
        $result = nasrCrossCheckAirportConfig([
            'airports' => [
                '69v' => [
                    'id' => '69v',
                    'faa' => '69V',
                ],
            ],
        ]);

        $this->assertSame([], $result['warnings']);
        $this->assertSame(1, $result['summary']['checked']);
        $this->assertSame(2, $result['summary']['skipped_no_config_field']);
    }

    public function testNasrCacheNullReturnsEmptyWarnings(): void
    {
        $this->tearDownNasrAptFixtureCache();

        $result = nasrCrossCheckAirportConfig([
            'airports' => [
                '69v' => [
                    'id' => '69v',
                    'faa' => '69V',
                    'elevation_ft' => 5800,
                ],
            ],
        ]);

        $this->assertSame([], $result['warnings']);
        $this->assertSame(0, $result['summary']['checked']);
    }

    public function testElevationWithinTwoFootTolerancePasses(): void
    {
        $result = nasrCrossCheckAirportConfig([
            'airports' => [
                '69v' => [
                    'id' => '69v',
                    'faa' => '69V',
                    'elevation_ft' => 5917,
                ],
            ],
        ]);

        $this->assertSame([], $result['warnings']);
    }
}
