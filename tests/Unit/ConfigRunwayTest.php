<?php

declare(strict_types=1);

require_once __DIR__ . '/../../lib/config-runway.php';

use PHPUnit\Framework\TestCase;

final class ConfigRunwayTest extends TestCase
{
    public function testBuildConfigRunwayIncludesEndsAndHeadings(): void
    {
        $runway = buildConfigRunwayForDensityAltitude([
            'runway_length_ft' => 2700,
            'runway_surface' => 'turf',
            'runway_ends' => [
                [
                    'end_id' => '17',
                    'tkof_dist_avbl' => 2600,
                    'obstruction' => ['hgt_ft' => 200, 'dist_ft' => 2500, 'slope' => 20],
                ],
                ['end_id' => '35'],
            ],
            'runways' => [
                ['name' => '17/35', 'heading_1' => 175, 'heading_2' => 355],
            ],
        ]);

        $this->assertNotNull($runway);
        $this->assertSame(2700, $runway['length_ft']);
        $this->assertSame('TURF', $runway['surface']);
        $this->assertSame('17/35', $runway['rwy_id']);
        $this->assertSame(175.0, $runway['heading_1']);
        $this->assertCount(2, $runway['ends']);
        $this->assertTrue(configRunwayHasDepartureObstructionData($runway));
    }

    public function testConfigRunwayMoreThanTwoEndsWithObstructionDoesNotLiftTierCap(): void
    {
        $runway = buildConfigRunwayForDensityAltitude([
            'runway_length_ft' => 2700,
            'runway_ends' => [
                ['end_id' => '09', 'obstruction' => ['hgt_ft' => 200, 'dist_ft' => 2500]],
                ['end_id' => '18'],
                ['end_id' => '27'],
            ],
        ]);

        $this->assertNotNull($runway);
        $this->assertFalse(configRunwayHasDepartureObstructionData($runway));
    }

    public function testConfigRunwaySingleEndWithObstructionDoesNotLiftTierCap(): void
    {
        $runway = buildConfigRunwayForDensityAltitude([
            'runway_length_ft' => 2700,
            'runway_ends' => [
                [
                    'end_id' => '17',
                    'obstruction' => ['hgt_ft' => 200, 'dist_ft' => 2500],
                ],
            ],
        ]);

        $this->assertNotNull($runway);
        $this->assertFalse(configRunwayHasDepartureObstructionData($runway));
    }

    public function testParseConfigRunwayEndsSkipsDuplicateEndIdAtRuntime(): void
    {
        $runway = buildConfigRunwayForDensityAltitude([
            'runway_length_ft' => 2700,
            'runway_ends' => [
                [
                    'end_id' => '09',
                    'obstruction' => ['hgt_ft' => 200, 'dist_ft' => 2500],
                ],
                ['end_id' => '09'],
            ],
        ]);

        $this->assertNotNull($runway);
        $this->assertCount(1, $runway['ends']);
        $this->assertFalse(configRunwayHasDepartureObstructionData($runway));
    }

    public function testParseConfigRunwayEndsCanonicalizesSingleDigitEndId(): void
    {
        $runway = buildConfigRunwayForDensityAltitude([
            'runway_length_ft' => 2700,
            'runway_ends' => [
                ['end_id' => '9'],
                ['end_id' => '27'],
            ],
        ]);

        $this->assertNotNull($runway);
        $this->assertSame('09', $runway['ends'][0]['end_id']);
        $this->assertSame('27', $runway['ends'][1]['end_id']);
    }

    public function testConfigRunwaySkipsInvalidEndIdAtParseTime(): void
    {
        $runway = buildConfigRunwayForDensityAltitude([
            'runway_length_ft' => 2700,
            'runway_ends' => [
                [
                    'end_id' => '99',
                    'obstruction' => ['hgt_ft' => 200, 'dist_ft' => 2500],
                ],
                ['end_id' => '17'],
            ],
        ]);

        $this->assertNotNull($runway);
        $this->assertCount(1, $runway['ends']);
        $this->assertSame('17', $runway['ends'][0]['end_id']);
        $this->assertFalse(configRunwayHasDepartureObstructionData($runway));
    }

    public function testConfigRunwayWithoutObstructionData(): void
    {
        $runway = buildConfigRunwayForDensityAltitude([
            'runway_length_ft' => 2000,
            'runway_ends' => [
                ['end_id' => '09'],
                ['end_id' => '27'],
            ],
        ]);

        $this->assertNotNull($runway);
        $this->assertFalse(configRunwayHasDepartureObstructionData($runway));
    }
}
