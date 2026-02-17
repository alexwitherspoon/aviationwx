<?php
/**
 * Unit tests for runway designation math (FAA standard)
 *
 * Runway designation = round(magnetic_heading / 10) when rounded to nearest 10.
 * Tests the Math.round(heading/10) logic used in wind compass display.
 */

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class RunwayDesignationTest extends TestCase
{
    /**
     * Compute runway designation from heading (matches FAA standard and display logic)
     *
     * @param int $heading Magnetic heading in degrees (0-360)
     * @return int Runway number (round to nearest 10, divide by 10)
     */
    private static function headingToDesignation(int $heading): int
    {
        return (int) round($heading / 10);
    }

    #[DataProvider('headingToDesignationProvider')]
    public function testHeadingToDesignation_RoundsToNearestTen(int $heading, int $expectedDesignation): void
    {
        $this->assertSame($expectedDesignation, self::headingToDesignation($heading));
    }

    /**
     * @return array<string, array{int, int}> Heading => expected designation
     */
    public static function headingToDesignationProvider(): array
    {
        return [
            '119 rounds to 12 (not 11)' => [119, 12],
            '299 rounds to 30 (not 29)' => [299, 30],
            '120 exact' => [120, 12],
            '300 exact' => [300, 30],
            '115 rounds up to 12' => [115, 12],
            '114 rounds down to 11' => [114, 11],
            '295 rounds up to 30' => [295, 30],
            '294 rounds down to 29' => [294, 29],
            '0 north' => [0, 0],
            '360 north' => [360, 36],
            '180 south' => [180, 18],
            '90 east' => [90, 9],
            '270 west' => [270, 27],
        ];
    }

    public function testFloorWouldBeWrongFor119(): void
    {
        $heading = 119;
        $roundResult = (int) round($heading / 10);
        $floorResult = (int) floor($heading / 10);

        $this->assertSame(12, $roundResult, 'FAA standard: 119° → runway 12');
        $this->assertSame(11, $floorResult, 'Floor would incorrectly give 11');
    }

    public function testFloorWouldBeWrongFor299(): void
    {
        $heading = 299;
        $roundResult = (int) round($heading / 10);
        $floorResult = (int) floor($heading / 10);

        $this->assertSame(30, $roundResult, 'FAA standard: 299° → runway 30');
        $this->assertSame(29, $floorResult, 'Floor would incorrectly give 29');
    }
}
