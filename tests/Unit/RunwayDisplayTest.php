<?php

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../lib/nasr/parse.php';
require_once __DIR__ . '/../../lib/nasr/runway-remarks.php';
require_once __DIR__ . '/../../lib/runway-display.php';

class RunwayDisplayTest extends TestCase
{
    public function testRunwayDisplaySurfaceLabel_AsphCode_ReturnsAsphalt(): void
    {
        $this->assertSame('Asphalt', runwayDisplaySurfaceLabel('ASPH'));
    }

    public function testNasrRunwayLightsLabel_PeriCode_ReturnsOnRequest(): void
    {
        $this->assertSame('On request', nasrRunwayLightsLabel('PERI'));
    }

    public function testNasrParseCalmWindDesignationFromRemark_SplitEnds_ReturnsArrivalAndDeparture(): void
    {
        $parsed = nasrParseCalmWindDesignationFromRemark(
            'CALM WIND RWY 15 FOR ARRIVALS; RWY 33 FOR DEPARTURES.'
        );
        $this->assertNotNull($parsed);
        $this->assertSame('15', $parsed['arrival']);
        $this->assertSame('33', $parsed['departure']);
    }

    public function testNasrParseCalmWindDesignationFromRemark_SingleEnd_ReturnsBothEnds(): void
    {
        $parsed = nasrParseCalmWindDesignationFromRemark(
            'RWY 15 DESIGNATED CALM WIND RWY.'
        );
        $this->assertNotNull($parsed);
        $this->assertSame('15', $parsed['arrival']);
        $this->assertSame('15', $parsed['departure']);
    }

    public function testGetRunwayDisplayForAirport_NoSourceData_ReturnsNull(): void
    {
        $this->assertNull(getRunwayDisplayForAirport(['id' => 'nonexistent-strip-xyz'], 'nonexistent-strip-xyz'));
    }

    public function testGetRunwayDisplayForAirport_ConfigRunwayFacts_MergesOverrides(): void
    {
        $airport = [
            'id' => 'ktest',
            'runway_length_ft' => 3000,
            'runway_surface' => 'ASPH',
            'runways' => [
                ['name' => '09/27', 'heading_1' => 90, 'heading_2' => 270],
            ],
            'runway_facts' => [
                [
                    'rwy_id' => '09/27',
                    'lights' => 'On request',
                    'calm_wind_arrival' => '09',
                ],
            ],
        ];

        $display = getRunwayDisplayForAirport($airport, 'ktest');
        $this->assertNotNull($display);
        $this->assertSame('config', $display['runway_source']);
        $this->assertCount(1, $display['runways']);
        $this->assertSame('09/27', $display['runways'][0]['rwy_id']);
        $this->assertSame('On request', $display['runways'][0]['lights']);
        $this->assertTrue($display['runways'][0]['ends'][0]['calm_wind_arrival']);
    }

    public function testAttachRunwayDisplay_ValidConfigAttachesRunwayDisplay(): void
    {
        $airport = [
            'id' => 'ktest',
            'runway_length_ft' => 2500,
            'runway_surface' => 'TURF',
            'runways' => [
                ['name' => '17/35', 'heading_1' => 175, 'heading_2' => 355],
            ],
        ];

        $weather = ['temperature' => 72];
        $withRunways = attachRunwayDisplay($weather, $airport, 'ktest');

        $this->assertArrayHasKey('runway_display', $withRunways);
        $this->assertSame('config', $withRunways['runway_display']['runway_source']);
        $this->assertSame(72, $withRunways['temperature']);
    }

    public function testRunwayDisplayMagneticHeadingForEnd_NoDeclination_PrefersEndIdentOverTrueAlignment(): void
    {
        $heading = runwayDisplayMagneticHeadingForEnd([
            'end_id' => '09',
            'true_alignment' => 95,
        ], null);

        $this->assertSame(90, $heading);
    }

    public function testRunwayDisplayFormatRunwayRow_FieldSourcesUseOurAirportsFallback(): void
    {
        $row = runwayDisplayFormatRunwayRow(
            [
                'rwy_id' => '09/27',
                'length_ft' => 4000,
                'surface' => 'ASPH',
                'ends' => [
                    ['end_id' => '09'],
                    ['end_id' => '27'],
                ],
            ],
            [],
            [],
            ['width_ft' => 75, 'lighted' => true],
            ['aerodrome_closed' => false, 'closed_pair_designators' => [], 'closed_end_idents' => []],
            'nasr',
            null
        );

        $this->assertNotNull($row);
        $this->assertSame(75, $row['width_ft']);
        $this->assertSame('Lighted', $row['lights']);
        $this->assertSame('ourairports', $row['field_sources']['width_ft']);
        $this->assertSame('ourairports', $row['field_sources']['lights']);
    }
}
