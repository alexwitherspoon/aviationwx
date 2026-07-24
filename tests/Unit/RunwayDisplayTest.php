<?php

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../lib/nasr/parse.php';
require_once __DIR__ . '/../../lib/nasr/runway-remarks.php';
require_once __DIR__ . '/../../lib/runway-display.php';

class RunwayDisplayTest extends TestCase
{
    public function testRunwayDisplayIsHelipad_H1_ReturnsTrue(): void
    {
        $this->assertTrue(runwayDisplayIsHelipad('H1'));
        $this->assertTrue(runwayDisplayIsHelipad('h2'));
    }

    public function testRunwayDisplayIsHelipad_RunwayPair_ReturnsFalse(): void
    {
        $this->assertFalse(runwayDisplayIsHelipad('09/27'));
        $this->assertFalse(runwayDisplayIsHelipad('10L/28R'));
    }

    public function testRunwayDisplayFormatRunwayRow_Helipad_SetsIsHelipadFlag(): void
    {
        $row = runwayDisplayFormatRunwayRow(
            [
                'rwy_id' => 'H1',
                'length_ft' => 50,
                'width_ft' => 50,
                'surface' => 'ASPH',
                'ends' => [
                    ['end_id' => 'H1'],
                ],
            ],
            [],
            [],
            [],
            ['aerodrome_closed' => false, 'closed_pair_designators' => [], 'closed_end_idents' => []],
            'nasr',
            null
        );

        $this->assertNotNull($row);
        $this->assertTrue($row['is_helipad']);
    }

    public function testRunwayDisplayFormatRunwayRow_RunwayPair_IsNotHelipad(): void
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
            [],
            ['aerodrome_closed' => false, 'closed_pair_designators' => [], 'closed_end_idents' => []],
            'nasr',
            null
        );

        $this->assertNotNull($row);
        $this->assertFalse($row['is_helipad']);
    }

    public function testRunwayDisplayFormatRunwayFactsRow_PreservesIsHelipad(): void
    {
        $facts = runwayDisplayFormatRunwayFactsRow([
            'rwy_id' => 'H1',
            'length_ft' => 50,
            'width_ft' => 50,
            'surface' => 'Asphalt',
            'surface_code' => 'ASPH',
            'lights' => null,
            'closed' => false,
            'is_helipad' => true,
            'field_sources' => ['length_ft' => 'nasr'],
            'ends' => [
                ['end_id' => 'H1', 'heading_mag' => null],
            ],
        ]);

        $this->assertTrue($facts['is_helipad']);
    }

    public function testRunwayDisplaySurfaceLabel_AsphCode_ReturnsAsphalt(): void
    {
        $this->assertSame('Asphalt', runwayDisplaySurfaceLabel('ASPH'));
    }

    public function testNasrRunwayLightsLabel_PeriCode_ReturnsOnRequest(): void
    {
        $this->assertSame('On request', nasrRunwayLightsLabel('PERI'));
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

    public function testRunwayDisplayFormatRunwayRow_FieldSourcesUseOurAirportsSurfaceFallback(): void
    {
        $row = runwayDisplayFormatRunwayRow(
            [
                'rwy_id' => '09/27',
                'length_ft' => 4000,
                'ends' => [
                    ['end_id' => '09'],
                    ['end_id' => '27'],
                ],
            ],
            [],
            [],
            ['surface' => 'GRVL'],
            ['aerodrome_closed' => false, 'closed_pair_designators' => [], 'closed_end_idents' => []],
            'nasr',
            null
        );

        $this->assertNotNull($row);
        $this->assertSame('Gravel', $row['surface']);
        $this->assertSame('GRVL', $row['surface_code']);
        $this->assertSame('ourairports', $row['field_sources']['surface']);
    }

    public function testRunwayDisplayMagneticHeadingForEnd_WithDeclination_ConvertsTrueAlignment(): void
    {
        $heading = runwayDisplayMagneticHeadingForEnd([
            'end_id' => '09',
            'true_alignment' => 100,
        ], 15.0);

        $this->assertSame(85, $heading);
    }

    public function testRunwayDisplayRunwayClosedFromNotam_AerodromeClosure_ReturnsTrue(): void
    {
        $closures = [
            'aerodrome_closed' => true,
            'closed_pair_designators' => [],
            'closed_end_idents' => [],
        ];

        $this->assertTrue(runwayDisplayRunwayClosedFromNotam('09/27', [
            ['end_id' => '09'],
            ['end_id' => '27'],
        ], $closures));
    }

    public function testRunwayDisplayRunwayClosedFromNotam_AllEndsClosed_ReturnsTrue(): void
    {
        $closures = [
            'aerodrome_closed' => false,
            'closed_pair_designators' => [],
            'closed_end_idents' => ['09', '27'],
        ];

        $this->assertTrue(runwayDisplayRunwayClosedFromNotam('09/27', [
            ['end_id' => '09'],
            ['end_id' => '27'],
        ], $closures));
    }

    public function testRunwayDisplayRunwayClosedFromNotam_PartialEndClosure_ReturnsFalse(): void
    {
        $closures = [
            'aerodrome_closed' => false,
            'closed_pair_designators' => [],
            'closed_end_idents' => ['09'],
        ];

        $this->assertFalse(runwayDisplayRunwayClosedFromNotam('09/27', [
            ['end_id' => '09'],
            ['end_id' => '27'],
        ], $closures));
    }

    public function testRunwayDisplayFormatRunwayRow_NotamPairClosure_MarksClosed(): void
    {
        $row = runwayDisplayFormatRunwayRow(
            [
                'rwy_id' => '18/36',
                'length_ft' => 4000,
                'surface' => 'ASPH',
                'ends' => [
                    ['end_id' => '18'],
                    ['end_id' => '36'],
                ],
            ],
            [],
            [],
            [],
            [
                'aerodrome_closed' => false,
                'closed_pair_designators' => ['18/36'],
                'closed_end_idents' => [],
            ],
            'nasr',
            null
        );

        $this->assertNotNull($row);
        $this->assertTrue($row['closed']);
    }

    public function testRunwayDisplayMagneticDeclinationDeg_NoCoordinates_ReturnsNull(): void
    {
        $this->assertNull(runwayDisplayMagneticDeclinationDeg(['id' => 'ktest']));
    }

    public function testRunwayDisplayMagneticDeclinationDeg_ConfigOverride_ReturnsValue(): void
    {
        $this->assertSame(12.5, runwayDisplayMagneticDeclinationDeg([
            'id' => 'ktest',
            'magnetic_declination' => 12.5,
        ]));
    }

    public function testFormatRunwayFactsForAirportApi_ConfigRunway_StripsCalmWindAndTraffic(): void
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
                    'traffic' => 'Right traffic RWY 27',
                    'calm_wind_arrival' => '09',
                ],
            ],
        ];

        $facts = formatRunwayFactsForAirportApi($airport, 'ktest');
        $this->assertNotNull($facts);
        $this->assertSame('config', $facts['runway_source']);
        $row = $facts['runways'][0];
        $this->assertArrayNotHasKey('traffic', $row);
        $this->assertArrayNotHasKey('row_source', $row);
        $this->assertArrayNotHasKey('calm_wind_arrival', $row['ends'][0]);
        $this->assertArrayNotHasKey('calm_wind_departure', $row['ends'][0]);
        $this->assertArrayNotHasKey('right_hand_traffic', $row['ends'][0]);
    }

    public function testGetRunwayDisplayForAirport_IncludeNotamClosuresFalse_SkipsNotamClosure(): void
    {
        $sourceRow = [
            'rwy_id' => '18/36',
            'length_ft' => 4000,
            'surface' => 'ASPH',
            'ends' => [
                ['end_id' => '18'],
                ['end_id' => '36'],
            ],
        ];
        $notamClosures = [
            'aerodrome_closed' => false,
            'closed_pair_designators' => ['18/36'],
            'closed_end_idents' => [],
        ];

        $withNotam = runwayDisplayFormatRunwayRow(
            $sourceRow,
            [],
            [],
            [],
            $notamClosures,
            'nasr',
            null
        );
        $withoutNotam = runwayDisplayFormatRunwayRow(
            $sourceRow,
            [],
            [],
            [],
            [
                'aerodrome_closed' => false,
                'closed_pair_designators' => [],
                'closed_end_idents' => [],
            ],
            'nasr',
            null
        );

        $this->assertNotNull($withNotam);
        $this->assertNotNull($withoutNotam);
        $this->assertTrue($withNotam['closed']);
        $this->assertFalse($withoutNotam['closed']);
        $this->assertFalse(runwayDisplayFormatRunwayFactsRow($withoutNotam)['closed']);
    }

    public function testRunwayDisplayTrafficNote_UsesRwyAbbreviation(): void
    {
        $note = runwayDisplayTrafficNote([
            ['end_id' => '31L', 'right_hand_traffic' => true],
            ['end_id' => '13R', 'right_hand_traffic' => false],
        ]);

        $this->assertSame('RWY 31L: Right traffic', $note);
    }
}
