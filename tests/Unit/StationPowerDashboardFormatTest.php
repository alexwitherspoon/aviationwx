<?php
/**
 * Golden-vector tests for station power dashboard cell formatting (PHP).
 */

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../lib/station-power/station-power-dashboard-format.php';

final class StationPowerDashboardFormatTest extends TestCase
{
    public function testFormatCells_FromFixture_MatchesExpected(): void
    {
        $path = __DIR__ . '/../Fixtures/station-power-dashboard-sample.json';
        $this->assertFileExists($path);
        $raw = file_get_contents($path);
        $this->assertNotFalse($raw);
        $sp = json_decode($raw, true);
        $this->assertIsArray($sp);

        $cells = stationPowerDashboardFormatCells($sp);

        $this->assertSame('1500 W', $cells['solar_now']);
        $this->assertSame('12000 Wh', $cells['solar_today']);
        $this->assertStringContainsString('local midnight', $cells['solar_today_title']);
        $this->assertSame('210 W', $cells['dc_load_now']);
        $this->assertSame('8000 Wh', $cells['load_today']);
        $this->assertSame('52.3v', $cells['battery_volts']);
        $this->assertSame('5 hrs', $cells['ttg']);
        $this->assertSame('62.5%', $cells['soc_text']);
        $this->assertTrue($cells['soc_show_meter']);
        $this->assertSame('station-power-meter', $cells['soc_meter_class']);
    }

    public function testFormatCells_EmptyArray_AllDashes(): void
    {
        $cells = stationPowerDashboardFormatCells([]);
        $this->assertSame('---', $cells['solar_now']);
        $this->assertSame('---', $cells['solar_today']);
        $this->assertSame('---', $cells['dc_load_now']);
        $this->assertSame('---', $cells['load_today']);
        $this->assertSame('---', $cells['battery_volts']);
        $this->assertSame('---', $cells['ttg']);
        $this->assertSame('---', $cells['soc_text']);
        $this->assertFalse($cells['soc_show_meter']);
    }
}
