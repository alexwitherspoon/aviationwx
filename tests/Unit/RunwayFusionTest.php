<?php

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../lib/runway-fusion.php';

/**
 * Fused runway inventory builder: union membership, per-pair precedence,
 * provenance, derived segments, and rebuild policy.
 *
 * The Goodspeed (42B) fixtures carry the real NASR 2026-10-01 cycle values:
 * 14/32 (2120 ft ASPH, width 50, LOW lights, GOOD) and 16W/34W
 * (4500 x 1000 WATER, no published alignment, no lights).
 */
class RunwayFusionTest extends TestCase
{
    protected function tearDown(): void
    {
        if (is_file(CACHE_RUNWAY_FUSION_FILE)) {
            @unlink(CACHE_RUNWAY_FUSION_FILE);
        }
        parent::tearDown();
    }

    public function testBuildAirportEntry_Goodspeed_UnionOfNasrPairsWithProvenance(): void
    {
        $entry = runwayFusionBuildAirportEntry(
            '42B',
            $this->goodspeedAirport(),
            $this->goodspeedNasrPayload(),
            $this->goodspeedRunwaysData()
        );

        $this->assertIsArray($entry);
        $byKey = $this->rowsByKey($entry['rows']);
        $this->assertCount(2, $byKey);
        $this->assertArrayHasKey('14/32', $byKey);
        $this->assertArrayHasKey('16W/34W', $byKey);

        $land = $byKey['14/32'];
        $this->assertSame('nasr', $land['source']);
        $this->assertSame('2026-10-01', $land['effective_date']);
        $this->assertFalse($land['is_water']);
        $this->assertFalse($land['closed']);
        $this->assertSame(
            ['length_ft' => 'nasr', 'width_ft' => 'nasr', 'surface' => 'nasr', 'lights' => 'nasr'],
            $land['field_sources']
        );
        $this->assertSame(2120, $land['nasr']['length_ft']);
        $this->assertNotNull($land['oa']);

        $water = $byKey['16W/34W'];
        $this->assertSame('nasr', $water['source']);
        $this->assertTrue($water['is_water']);
        $this->assertFalse($water['closed']);
        // NASR publishes no light code for the water strip; the OurAirports
        // row (lighted=false -> 'None') fills the field.
        $this->assertSame(
            ['length_ft' => 'nasr', 'width_ft' => 'nasr', 'surface' => 'nasr', 'lights' => 'ourairports'],
            $water['field_sources']
        );
    }

    public function testBuildAirportEntry_Goodspeed_DerivesWaterSegmentAtIdentBearing(): void
    {
        $entry = runwayFusionBuildAirportEntry(
            '42B',
            $this->goodspeedAirport(),
            $this->goodspeedNasrPayload(),
            $this->goodspeedRunwaysData()
        );

        // 14/32 has FAA geometry in the merge cache; only the water strip
        // needs a derived through-center segment.
        $this->assertCount(1, $entry['segments']);
        $seg = $entry['segments'][0];
        $this->assertSame('16W', $seg['le_ident']);
        $this->assertSame('34W', $seg['he_ident']);
        $this->assertSame('34W', $seg['ident_at_start']);
        $this->assertSame('16W', $seg['ident_at_end']);
        $this->assertTrue($seg['water']);
        $this->assertTrue($seg['magnetic']);
        $this->assertTrue($seg['synthesized']);
        $this->assertSame('fusion', $seg['source']);

        // No published alignment: ident bearing 160 degrees through center.
        $angle = 160 * M_PI / 180;
        $this->assertEqualsWithDelta(sin($angle) * 0.9, $seg['start'][0], 1e-9);
        $this->assertEqualsWithDelta(cos($angle) * 0.9, $seg['start'][1], 1e-9);
        $this->assertEqualsWithDelta(-sin($angle) * 0.9, $seg['end'][0], 1e-9);
        $this->assertEqualsWithDelta(-cos($angle) * 0.9, $seg['end'][1], 1e-9);
    }

    public function testBuildAirportEntry_SingleStripLetterDrift_GeometryCoversUnletteredRow(): void
    {
        // L/C/R suffixes carry meaning only while the airport has parallel
        // strips at the numeric heading. Heading 18 here has a single strip
        // across all sources, so the geometry's 18L is lettering drift
        // against OurAirports' 18: the pair keys merge into one, the row is
        // covered by the geometry, and no derived segment is produced.
        $runways = [
            'airports' => [
                '77L' => [
                    'segments' => [
                        [
                            'start' => [-0.9, 0.0],
                            'end' => [0.9, 0.0],
                            'le_ident' => '36',
                            'he_ident' => '18L',
                            'ident_at_start' => '18L',
                            'ident_at_end' => '36',
                            'source' => 'faa',
                        ],
                    ],
                    'display_runways' => [
                        [
                            'rwy_id' => '18/36',
                            'length_ft' => 3000,
                            'width_ft' => 45,
                            'surface' => 'ASPH',
                            'lighted' => false,
                            'closed' => false,
                            'ends' => [['end_id' => '18'], ['end_id' => '36']],
                            'le_displaced_threshold_ft' => 0,
                            'he_displaced_threshold_ft' => 0,
                        ],
                    ],
                ],
            ],
        ];

        $airport = [
            'id' => '77L',
            'name' => 'Letter Mismatch Field',
            'faa' => '77L',
            'ourairports_ident' => '77L',
            'lat' => 39.0,
            'lon' => -95.0,
            'elevation_ft' => 1000,
            'enabled' => true,
            'magnetic_declination' => -5.0,
        ];
        $entry = runwayFusionBuildAirportEntry('77L', $airport, null, $runways);

        $this->assertIsArray($entry);
        $this->assertCount(1, $entry['rows']);
        $this->assertSame('ourairports', $entry['rows'][0]['source']);
        $this->assertSame('18/36', $entry['rows'][0]['key']);
        $this->assertCount(0, $entry['segments']);
    }

    public function testBuildAirportEntry_TrueParallelStrips_StayDistinctAndCovered(): void
    {
        // Heading 09 has two lettered ends (09L and 09R) across the
        // airport's sources, so both are real parallel strips. The suffixes
        // are kept, the rows stay distinct, and the geometry covers both:
        // no derived segment.
        $runways = [
            'airports' => [
                'PPA' => [
                    'segments' => [
                        [
                            'start' => [-0.9, -0.1],
                            'end' => [0.9, -0.1],
                            'le_ident' => '09L',
                            'he_ident' => '27R',
                            'ident_at_start' => '09L',
                            'ident_at_end' => '27R',
                            'source' => 'faa',
                        ],
                        [
                            'start' => [-0.9, 0.1],
                            'end' => [0.9, 0.1],
                            'le_ident' => '09R',
                            'he_ident' => '27L',
                            'ident_at_start' => '09R',
                            'ident_at_end' => '27L',
                            'source' => 'faa',
                        ],
                    ],
                    'display_runways' => [
                        [
                            'rwy_id' => '09L/27R',
                            'length_ft' => 5000,
                            'width_ft' => 150,
                            'surface' => 'ASPH',
                            'lighted' => true,
                            'closed' => false,
                            'ends' => [['end_id' => '09L'], ['end_id' => '27R']],
                            'le_displaced_threshold_ft' => 0,
                            'he_displaced_threshold_ft' => 0,
                        ],
                        [
                            'rwy_id' => '09R/27L',
                            'length_ft' => 4500,
                            'width_ft' => 150,
                            'surface' => 'ASPH',
                            'lighted' => true,
                            'closed' => false,
                            'ends' => [['end_id' => '09R'], ['end_id' => '27L']],
                            'le_displaced_threshold_ft' => 0,
                            'he_displaced_threshold_ft' => 0,
                        ],
                    ],
                ],
            ],
        ];

        $airport = [
            'id' => 'PPA',
            'name' => 'Parallel Field',
            'faa' => 'PPA',
            'ourairports_ident' => 'PPA',
            'lat' => 39.0,
            'lon' => -95.0,
            'elevation_ft' => 1000,
            'enabled' => true,
            'magnetic_declination' => -5.0,
        ];
        $entry = runwayFusionBuildAirportEntry('PPA', $airport, null, $runways);

        $this->assertIsArray($entry);
        $keys = array_column($entry['rows'], 'key');
        sort($keys);
        $this->assertSame(['09L/27R', '09R/27L'], $keys);
        $this->assertCount(0, $entry['segments']);
    }

    public function testBuildAirportEntry_MissingParallel_DerivesUncoveredStrip(): void
    {
        // The geometry carries only one of two parallel strips. OurAirports
        // lists both, so the 09L in the geometry is a real letter (not
        // drift): 09R/27L stays a distinct pair and gets a derived
        // through-center segment for the compass.
        $runways = [
            'airports' => [
                'PPA' => [
                    'segments' => [
                        [
                            'start' => [-0.9, -0.1],
                            'end' => [0.9, -0.1],
                            'le_ident' => '09L',
                            'he_ident' => '27R',
                            'ident_at_start' => '09L',
                            'ident_at_end' => '27R',
                            'source' => 'faa',
                        ],
                    ],
                    'display_runways' => [
                        [
                            'rwy_id' => '09L/27R',
                            'length_ft' => 5000,
                            'width_ft' => 150,
                            'surface' => 'ASPH',
                            'lighted' => true,
                            'closed' => false,
                            'ends' => [['end_id' => '09L'], ['end_id' => '27R']],
                            'le_displaced_threshold_ft' => 0,
                            'he_displaced_threshold_ft' => 0,
                        ],
                        [
                            'rwy_id' => '09R/27L',
                            'length_ft' => 4500,
                            'width_ft' => 150,
                            'surface' => 'ASPH',
                            'lighted' => true,
                            'closed' => false,
                            'ends' => [['end_id' => '09R'], ['end_id' => '27L']],
                            'le_displaced_threshold_ft' => 0,
                            'he_displaced_threshold_ft' => 0,
                        ],
                    ],
                ],
            ],
        ];

        $airport = [
            'id' => 'PPA',
            'name' => 'Parallel Field',
            'faa' => 'PPA',
            'ourairports_ident' => 'PPA',
            'lat' => 39.0,
            'lon' => -95.0,
            'elevation_ft' => 1000,
            'enabled' => true,
            'magnetic_declination' => -5.0,
        ];
        $entry = runwayFusionBuildAirportEntry('PPA', $airport, null, $runways);

        $this->assertIsArray($entry);
        $this->assertCount(2, $entry['rows']);
        $this->assertCount(1, $entry['segments']);
        $this->assertSame('09R', $entry['segments'][0]['le_ident']);
        $this->assertSame('27L', $entry['segments'][0]['he_ident']);
    }

    public function testBuildAirportEntry_MixedLetteringParallels_MergeIsLossy(): void
    {
        // Pinned known limitation: the sources together show one lettered end
        // (16L, from geometry) and one unlettered end (16, from OurAirports)
        // at heading 16, and the ident set cannot tell whether that is
        // lettering drift on one strip or two genuinely parallel strips.
        // The merge direction treats it as drift: one pair, one display row,
        // covered by the geometry. The unlettered second strip (if it is one)
        // is not drawn separately; lettering it upstream is the fix.
        $runways = [
            'airports' => [
                'MIX' => [
                    'segments' => [
                        [
                            'start' => [-0.9, -0.1],
                            'end' => [0.9, -0.1],
                            'le_ident' => '16L',
                            'he_ident' => '34L',
                            'ident_at_start' => '16L',
                            'ident_at_end' => '34L',
                            'source' => 'faa',
                        ],
                    ],
                    'display_runways' => [
                        [
                            'rwy_id' => '16/34',
                            'length_ft' => 4000,
                            'width_ft' => 60,
                            'surface' => 'ASPH',
                            'lighted' => true,
                            'closed' => false,
                            'ends' => [['end_id' => '16'], ['end_id' => '34']],
                            'le_displaced_threshold_ft' => 0,
                            'he_displaced_threshold_ft' => 0,
                        ],
                    ],
                ],
            ],
        ];

        $airport = [
            'id' => 'MIX',
            'name' => 'Mixed Lettering Field',
            'faa' => 'MIX',
            'ourairports_ident' => 'MIX',
            'lat' => 39.0,
            'lon' => -95.0,
            'elevation_ft' => 1000,
            'enabled' => true,
            'magnetic_declination' => -5.0,
        ];
        $entry = runwayFusionBuildAirportEntry('MIX', $airport, null, $runways);

        $this->assertIsArray($entry);
        $this->assertCount(1, $entry['rows']);
        $this->assertSame('16/34', $entry['rows'][0]['key']);
        $this->assertCount(0, $entry['segments']);
    }

    public function testBuildAirportEntry_BareRowAtParallelBearing_LowerSourceRowDropped(): void
    {
        // One source (NASR) lists two parallels at heading 16 and another
        // source (OurAirports) lists one bare 16/34. The bare designator is a
        // mislabel of one of the parallels, not a third strip: the FAA letters
        // every parallel. The fusion applies the defined precedence: the bare
        // pair is dropped while the higher-precedence source holds the
        // lettered parallels.
        $end = static function (string $id): array {
            return ['end_id' => $id, 'true_alignment' => null];
        };
        $nasr = [
            'schema_version' => 1,
            'effective_date' => '2026-10-01',
            'airports' => [
                'BSCN' => [
                    'arpt_id' => 'BSCN',
                    'runways' => [
                        [
                            'rwy_id' => '16L/34L',
                            'length_ft' => 4000,
                            'width_ft' => 60,
                            'surface' => 'ASPH',
                            'condition' => 'GOOD',
                            'lights_code' => 'LOW',
                            'ends' => [$end('16L'), $end('34L')],
                        ],
                        [
                            'rwy_id' => '16R/34R',
                            'length_ft' => 3800,
                            'width_ft' => 60,
                            'surface' => 'ASPH',
                            'condition' => 'GOOD',
                            'lights_code' => 'MED',
                            'ends' => [$end('16R'), $end('34R')],
                        ],
                    ],
                ],
            ],
        ];
        $runways = [
            'airports' => [
                'BSCN' => [
                    'display_runways' => [
                        [
                            'rwy_id' => '16/34',
                            'length_ft' => 3900,
                            'width_ft' => 50,
                            'surface' => 'ASPH',
                            'lighted' => false,
                            'closed' => false,
                            'ends' => [$end('16'), $end('34')],
                            'le_displaced_threshold_ft' => 0,
                            'he_displaced_threshold_ft' => 0,
                        ],
                    ],
                ],
            ],
        ];

        $airport = [
            'id' => 'bscn',
            'name' => 'Bare Row Parallel Field',
            'faa' => 'BSCN',
            'ourairports_ident' => 'BSCN',
            'lat' => 39.0,
            'lon' => -95.0,
            'elevation_ft' => 1000,
            'enabled' => true,
            'magnetic_declination' => -5.0,
        ];
        $entry = runwayFusionBuildAirportEntry('bscn', $airport, $nasr, $runways);

        $this->assertIsArray($entry);
        $keys = array_column($entry['rows'], 'key');
        sort($keys);
        $this->assertSame(['16L/34L', '16R/34R'], $keys);
        // No geometry: each parallel gets a derived segment, and the dropped
        // bare pair adds no third line to the diagram.
        $this->assertCount(2, $entry['segments']);
    }

    public function testBuildAirportEntry_BareRowAtParallelBearing_ConfigRowKept(): void
    {
        // A manual config row is never dropped: the operator wrote a bare
        // 16/34 next to NASR's lettered parallels, so the pair stays in the
        // union and the operator sees it.
        $end = static function (string $id): array {
            return ['end_id' => $id, 'true_alignment' => null];
        };
        $nasr = [
            'schema_version' => 1,
            'effective_date' => '2026-10-01',
            'airports' => [
                'BSCN' => [
                    'arpt_id' => 'BSCN',
                    'runways' => [
                        [
                            'rwy_id' => '16L/34L',
                            'length_ft' => 4000,
                            'width_ft' => 60,
                            'surface' => 'ASPH',
                            'condition' => 'GOOD',
                            'lights_code' => 'LOW',
                            'ends' => [$end('16L'), $end('34L')],
                        ],
                        [
                            'rwy_id' => '16R/34R',
                            'length_ft' => 3800,
                            'width_ft' => 60,
                            'surface' => 'ASPH',
                            'condition' => 'GOOD',
                            'lights_code' => 'MED',
                            'ends' => [$end('16R'), $end('34R')],
                        ],
                    ],
                ],
            ],
        ];

        $airport = [
            'id' => 'bscn',
            'name' => 'Bare Row Parallel Field',
            'faa' => 'BSCN',
            'ourairports_ident' => 'BSCN',
            'lat' => 39.0,
            'lon' => -95.0,
            'elevation_ft' => 1000,
            'enabled' => true,
            'magnetic_declination' => -5.0,
            'runway_length_ft' => 3900,
            'runways' => [['name' => '16/34', 'heading_1' => 160, 'heading_2' => 340]],
        ];
        $entry = runwayFusionBuildAirportEntry('bscn', $airport, $nasr, null);

        $this->assertIsArray($entry);
        $byKey = $this->rowsByKey($entry['rows']);
        $this->assertCount(3, $byKey);
        $this->assertArrayHasKey('16/34', $byKey);
        $this->assertSame('config', $byKey['16/34']['source']);
    }

    public function testBuildAirportEntry_BareRowAtParallelBearing_SameSourceBareRowKept(): void
    {
        // NASR publishes a bare pair alongside its own lettered parallels:
        // the winning source outranks none of the lettered ones, so the pair
        // stays in the union, visible as the data error it is.
        $end = static function (string $id): array {
            return ['end_id' => $id, 'true_alignment' => null];
        };
        $nasr = [
            'schema_version' => 1,
            'effective_date' => '2026-10-01',
            'airports' => [
                'BSCN' => [
                    'arpt_id' => 'BSCN',
                    'runways' => [
                        [
                            'rwy_id' => '16L/34L',
                            'length_ft' => 4000,
                            'width_ft' => 60,
                            'surface' => 'ASPH',
                            'condition' => 'GOOD',
                            'lights_code' => 'LOW',
                            'ends' => [$end('16L'), $end('34L')],
                        ],
                        [
                            'rwy_id' => '16R/34R',
                            'length_ft' => 3800,
                            'width_ft' => 60,
                            'surface' => 'ASPH',
                            'condition' => 'GOOD',
                            'lights_code' => 'MED',
                            'ends' => [$end('16R'), $end('34R')],
                        ],
                        [
                            'rwy_id' => '16/34',
                            'length_ft' => 3900,
                            'width_ft' => 50,
                            'surface' => 'ASPH',
                            'condition' => 'GOOD',
                            'lights_code' => null,
                            'ends' => [$end('16'), $end('34')],
                        ],
                    ],
                ],
            ],
        ];

        $airport = [
            'id' => 'bscn',
            'name' => 'Bare Row Parallel Field',
            'faa' => 'BSCN',
            'ourairports_ident' => 'BSCN',
            'lat' => 39.0,
            'lon' => -95.0,
            'elevation_ft' => 1000,
            'enabled' => true,
            'magnetic_declination' => -5.0,
        ];
        $entry = runwayFusionBuildAirportEntry('bscn', $airport, $nasr, null);

        $this->assertIsArray($entry);
        $keys = array_column($entry['rows'], 'key');
        sort($keys);
        $this->assertSame(['16/34', '16L/34L', '16R/34R'], $keys);
    }

    public function testBuildAirportEntry_OaOnlyPairIncludedForAirportWithNasrRecord(): void
    {
        $nasr = [
            'schema_version' => 1,
            'effective_date' => '2026-10-01',
            'airports' => [
                '99X' => [
                    'arpt_id' => '99X',
                    'icao_id' => null,
                    'notam_id' => '99X',
                    'elev_ft' => 100,
                    'lat' => 39.0,
                    'lon' => -95.0,
                    'mag_declination_deg' => -6.0,
                    'mag_declination_year' => 2020,
                    'runways' => [
                        [
                            'rwy_id' => '09/27',
                            'length_ft' => 3000,
                            'width_ft' => 45,
                            'surface' => 'ASPH',
                            'condition' => 'GOOD',
                            'lights_code' => null,
                            'ends' => [
                                $this->nasrEnd('09'),
                                $this->nasrEnd('27'),
                            ],
                        ],
                    ],
                ],
            ],
        ];
        $runways = [
            'airports' => [
                '99X' => [
                    'display_runways' => [
                        [
                            'rwy_id' => '09/27',
                            'length_ft' => 3000,
                            'width_ft' => 45,
                            'surface' => 'ASPH',
                            'lighted' => false,
                            'closed' => false,
                            'ends' => [['end_id' => '09'], ['end_id' => '27']],
                        ],
                        [
                            'rwy_id' => '15/33',
                            'length_ft' => 1800,
                            'width_ft' => 40,
                            'surface' => 'TURF',
                            'lighted' => false,
                            'closed' => false,
                            'ends' => [['end_id' => '15'], ['end_id' => '33']],
                        ],
                    ],
                ],
            ],
        ];
        $airport = [
            'id' => '99x',
            'name' => 'Off-Grid Test Field',
            'faa' => '99X',
            'ourairports_ident' => '99X',
            'lat' => 39.0,
            'lon' => -95.0,
            'enabled' => true,
            'magnetic_declination' => -6.0,
        ];

        $entry = runwayFusionBuildAirportEntry('99x', $airport, $nasr, $runways);

        $this->assertIsArray($entry);
        $byKey = $this->rowsByKey($entry['rows']);
        $this->assertCount(2, $byKey);
        $this->assertSame('nasr', $byKey['09/27']['source']);
        $this->assertSame('ourairports', $byKey['15/33']['source']);
        $this->assertNull($byKey['15/33']['effective_date']);
        // No geometry in the cache: both pairs get derived segments.
        $this->assertCount(2, $entry['segments']);
    }

    public function testBuildAirportEntry_ManualRowWinsMembershipAndFieldPrecedence(): void
    {
        $airport = $this->goodspeedAirport();
        $airport['runway_length_ft'] = 2100;
        $airport['runways'] = [
            ['name' => '14/32', 'heading_1' => 140, 'heading_2' => 320],
        ];
        $airport['runway_facts'] = [
            ['rwy_id' => '14/32', 'length_ft' => 2200],
        ];

        $entry = runwayFusionBuildAirportEntry(
            '42B',
            $airport,
            $this->goodspeedNasrPayload(),
            $this->goodspeedRunwaysData()
        );

        $this->assertIsArray($entry);
        $byKey = $this->rowsByKey($entry['rows']);

        $land = $byKey['14/32'];
        $this->assertSame('config', $land['source']);
        $this->assertSame(2100, $land['config']['length_ft']);
        // The config row contributes only the operator's length; width,
        // surface, and lights fall through to the NASR row, matching the
        // live resolver. The raw config row stays in the artifact.
        $this->assertSame(
            ['length_ft' => 'config', 'width_ft' => 'nasr', 'surface' => 'nasr', 'lights' => 'nasr'],
            $land['field_sources']
        );

        // The water strip is still fused from NASR.
        $this->assertSame('nasr', $byKey['16W/34W']['source']);

        // The manual compass entry covers 14/32, so only the water strip
        // gets a derived segment.
        $this->assertCount(1, $entry['segments']);
        $this->assertSame('16W', $entry['segments'][0]['le_ident']);
    }

    public function testBuildAirportEntry_ConfigRowWinsNasrPair_KeepsNasrWidthSurfaceAndWater(): void
    {
        // Operator length override on the water strip. The named config row
        // wins the pair, but only its length is operator data: width,
        // surface, and closure come from the NASR row, not the config row's
        // default ASPH surface and missing width.
        $airport = $this->goodspeedAirport();
        $airport['runway_length_ft'] = 4400;
        $airport['runways'] = [
            ['name' => '16W/34W', 'heading_1' => 160, 'heading_2' => 340],
        ];

        $nasr = $this->goodspeedNasrPayload();
        $nasr['airports']['42B']['runways'][1]['condition'] = 'CLOSED';

        $entry = runwayFusionBuildAirportEntry('42B', $airport, $nasr, $this->goodspeedRunwaysData());

        $this->assertIsArray($entry);
        $water = $this->rowsByKey($entry['rows'])['16W/34W'];
        $this->assertSame('config', $water['source']);
        $this->assertSame(4400, $water['config']['length_ft']);
        $this->assertSame('config', $water['field_sources']['length_ft']);
        $this->assertSame('nasr', $water['field_sources']['width_ft']);
        $this->assertSame('nasr', $water['field_sources']['surface']);
        $this->assertTrue($water['is_water']);
        $this->assertTrue($water['closed']);
    }

    public function testEffectiveSourceRow_NoLowerRow_ReturnsConfigRowUnchanged(): void
    {
        $configRow = ['rwy_id' => '16W/34W', 'length_ft' => 4400, 'surface' => 'ASPH', 'ends' => []];

        $this->assertSame($configRow, runwayFusionEffectiveSourceRow($configRow, [], null));
    }

    public function testEffectiveSourceRow_LayersOnlyExplicitOperatorFields(): void
    {
        $configRow = ['rwy_id' => '16W/34W', 'length_ft' => 4400, 'surface' => 'ASPH', 'ends' => []];
        $lowerRow = [
            'rwy_id' => '16W/34W',
            'length_ft' => 4500,
            'width_ft' => 1000,
            'surface' => 'WATER',
            'lights_code' => null,
            'condition' => 'GOOD',
            'ends' => [['end_id' => '16W'], ['end_id' => '34W']],
        ];

        // No runway_surface and no runway_ends: the lower row keeps surface,
        // width, condition, and ends; only the length layers on.
        $effective = runwayFusionEffectiveSourceRow($configRow, $lowerRow, null);
        $this->assertSame(4400, $effective['length_ft']);
        $this->assertSame(1000, $effective['width_ft']);
        $this->assertSame('WATER', $effective['surface']);
        $this->assertSame('GOOD', $effective['condition']);
        $this->assertSame($lowerRow['ends'], $effective['ends']);

        // An explicit surface and operator per-end data win over the lower row.
        $effective = runwayFusionEffectiveSourceRow(
            [
                'rwy_id' => '16W/34W',
                'length_ft' => 4400,
                'surface' => 'ASPH',
                'ends' => [['end_id' => '16W', 'displaced_thr_len' => 200]],
            ],
            $lowerRow,
            'GRASS'
        );
        $this->assertSame('GRASS', $effective['surface']);
        $this->assertSame([['end_id' => '16W', 'displaced_thr_len' => 200]], $effective['ends']);
    }

    public function testEffectiveSourceRow_OperatorHeadingsMapOntoLowerEnds(): void
    {
        // The read path consumes ends, not top-level heading keys, so the
        // operator headings must land on the lower row's ends to survive.
        $configRow = [
            'rwy_id' => '14/32',
            'length_ft' => 4400,
            'heading_1' => 145,
            'heading_2' => 325,
            'ends' => [],
        ];
        $lowerRow = [
            'rwy_id' => '14/32',
            'ends' => [
                ['end_id' => '14', 'true_alignment' => null],
                ['end_id' => '32', 'true_alignment' => null],
            ],
        ];

        $effective = runwayFusionEffectiveSourceRow($configRow, $lowerRow, null);
        $this->assertSame(145, $effective['ends'][0]['heading_mag']);
        $this->assertSame(325, $effective['ends'][1]['heading_mag']);
    }

    public function testEffectiveSourceRow_OperatorHeadingsMatchEndByIdentNotPosition(): void
    {
        // Ends reversed against the designator order: heading_1 still lands
        // on the 14 end, by numeric ident, not by array position.
        $configRow = ['rwy_id' => '14/32', 'heading_1' => 145, 'heading_2' => 325, 'ends' => []];
        $lowerRow = [
            'rwy_id' => '14/32',
            'ends' => [
                ['end_id' => '32', 'true_alignment' => null],
                ['end_id' => '14', 'true_alignment' => null],
            ],
        ];

        $effective = runwayFusionEffectiveSourceRow($configRow, $lowerRow, null);
        $this->assertSame(325, $effective['ends'][0]['heading_mag']);
        $this->assertSame(145, $effective['ends'][1]['heading_mag']);
    }

    public function testConfigFactsForRow_BareWinnerFallsBackToNasrStrictKey(): void
    {
        // A bare config row wins a pair while NASR carries lettered parallels
        // and the operator keyed runway_facts to the lettered designator. The
        // live resolver reads facts through the NASR key, so the fused lookup
        // must honor it too or the closed override is dropped.
        $facts = [
            '18L/36R' => ['rwy_id' => '18L/36R', 'closed' => true],
        ];
        $winner = ['rwy_id' => '18/36', 'length_ft' => 5000, 'surface' => 'ASPH', 'ends' => []];
        $nasr = ['rwy_id' => '18L/36R', 'length_ft' => 4900, 'width_ft' => 75, 'surface' => 'ASPH', 'ends' => []];

        $this->assertSame(
            ['rwy_id' => '18L/36R', 'closed' => true],
            runwayFusionConfigFactsForRow($facts, '18/36', $winner, [$nasr])
        );
    }

    public function testConfigFactsForRow_WinnerKeyWinsBeforeSourceKeys(): void
    {
        $facts = [
            '18/36' => ['rwy_id' => '18/36', 'closed' => true],
            '18L/36R' => ['rwy_id' => '18L/36R', 'closed' => false],
        ];
        $winner = ['rwy_id' => '18/36', 'ends' => []];
        $nasr = ['rwy_id' => '18L/36R', 'ends' => []];

        // The winner's (contextual) key hits first; the source-key fallback
        // must not override the operator's bare-key choice.
        $this->assertTrue(runwayFusionConfigFactsForRow($facts, '18/36', $winner, [$nasr])['closed']);
    }

    public function testConfigFactsForRow_NoMatchReturnsEmpty(): void
    {
        $this->assertSame([], runwayFusionConfigFactsForRow([], '09/27', ['rwy_id' => '09/27']));
    }

    public function testBuildAirportEntry_NamelessManualRowIncludedOnlyWithoutOtherSources(): void
    {
        $bare = [
            'id' => 'nowhere',
            'name' => 'Nowhere Strip',
            'lat' => 35.0,
            'lon' => -100.0,
            'enabled' => true,
            'magnetic_declination' => -5.0,
            'runway_length_ft' => 1500,
        ];

        // No NASR, no cache rows: the manual length override is the only source.
        $entry = runwayFusionBuildAirportEntry('nowhere', $bare, null, null);
        $this->assertIsArray($entry);
        $this->assertCount(1, $entry['rows']);
        $this->assertSame('config', $entry['rows'][0]['source']);
        $this->assertSame('CONFIG', $entry['rows'][0]['key']);

        // With NASR rows present, the nameless override stays a DA-only
        // surface and does not add a phantom pair.
        $nasr = [
            'schema_version' => 1,
            'effective_date' => '2026-10-01',
            'airports' => [
                'NOWHERE' => [
                    'arpt_id' => 'NOWHERE',
                    'icao_id' => null,
                    'notam_id' => 'NOWHERE',
                    'elev_ft' => 500,
                    'lat' => 35.0,
                    'lon' => -100.0,
                    'mag_declination_deg' => -5.0,
                    'mag_declination_year' => 2020,
                    'runways' => [
                        [
                            'rwy_id' => '12/30',
                            'length_ft' => 4000,
                            'width_ft' => 60,
                            'surface' => 'ASPH',
                            'condition' => 'GOOD',
                            'lights_code' => null,
                            'ends' => [
                                $this->nasrEnd('12'),
                                $this->nasrEnd('30'),
                            ],
                        ],
                    ],
                ],
            ],
        ];
        $entry2 = runwayFusionBuildAirportEntry('nowhere', $bare, $nasr, null);
        $this->assertIsArray($entry2);
        $keys = array_column($entry2['rows'], 'key');
        $this->assertContains('12/30', $keys);
        $this->assertNotContains('CONFIG', $keys);
    }

    public function testBuildAirportEntry_NasrMissing_DegradesToOaRows(): void
    {
        $entry = runwayFusionBuildAirportEntry(
            '42B',
            $this->goodspeedAirport(),
            null,
            $this->goodspeedRunwaysData()
        );

        $this->assertIsArray($entry);
        $byKey = $this->rowsByKey($entry['rows']);
        $this->assertSame('ourairports', $byKey['14/32']['source']);
        $this->assertSame('ourairports', $byKey['16W/34W']['source']);
        $this->assertNull($byKey['14/32']['effective_date']);
        $this->assertTrue($byKey['16W/34W']['is_water']);

        // The water strip still derives its segment from the ident bearing.
        $this->assertCount(1, $entry['segments']);
        $this->assertSame('16W', $entry['segments'][0]['le_ident']);
        $this->assertTrue($entry['segments'][0]['water']);
    }

    public function testBuildAirportEntry_RunwaysCacheMissing_DerivesSegmentsForAllPairs(): void
    {
        $entry = runwayFusionBuildAirportEntry(
            '42B',
            $this->goodspeedAirport(),
            $this->goodspeedNasrPayload(),
            null
        );

        $this->assertIsArray($entry);
        $this->assertCount(2, $entry['segments']);

        $byLeIdent = [];
        foreach ($entry['segments'] as $seg) {
            $byLeIdent[$seg['le_ident']] = $seg;
        }
        $this->assertArrayHasKey('14', $byLeIdent);
        $this->assertArrayHasKey('16W', $byLeIdent);

        $angle14 = 140 * M_PI / 180;
        $this->assertEqualsWithDelta(sin($angle14) * 0.9, $byLeIdent['14']['start'][0], 1e-9);
        $this->assertFalse($byLeIdent['14']['water'] ?? false);
        $this->assertTrue($byLeIdent['16W']['water']);
    }

    public function testBuildAirportEntry_SourceClosedRowKeptButNotSegmented(): void
    {
        $nasr = $this->goodspeedNasrPayload();
        foreach ($nasr['airports']['42B']['runways'] as &$row) {
            if ($row['rwy_id'] === '16W/34W') {
                $row['condition'] = 'CLOSED';
            }
        }
        unset($row);

        $entry = runwayFusionBuildAirportEntry(
            '42B',
            $this->goodspeedAirport(),
            $nasr,
            $this->goodspeedRunwaysData()
        );

        $this->assertIsArray($entry);
        $byKey = $this->rowsByKey($entry['rows']);
        // Closed runways stay in the inventory (status is displayed, not
        // filtered out), but a closed strip gets no derived segment.
        $this->assertTrue($byKey['16W/34W']['closed']);
        $this->assertCount(0, $entry['segments']);
    }

    public function testBuildAirportEntry_NoSources_ReturnsNull(): void
    {
        $airport = [
            'id' => 'ghost',
            'name' => 'Ghost Field',
            'lat' => 1.0,
            'lon' => 1.0,
            'enabled' => true,
        ];
        $this->assertNull(runwayFusionBuildAirportEntry('ghost', $airport, null, null));
    }

    public function testShouldRebuild_MissingArtifact_ReturnsTrue(): void
    {
        $stamps = $this->inputStamps();
        $this->assertTrue(runwayFusionShouldRebuild(null, $stamps));
    }

    public function testShouldRebuild_FreshArtifactNoNewerInputs_ReturnsFalse(): void
    {
        $stamps = $this->inputStamps();
        $this->assertFalse(runwayFusionShouldRebuild($this->freshArtifact($stamps), $stamps));
    }

    public function testShouldRebuild_NasrNewerThanRecordedStamp_ReturnsTrue(): void
    {
        $stamps = $this->inputStamps();
        $artifact = $this->freshArtifact($stamps);
        $stamps['nasr_apt_mtime'] = 2000;
        $this->assertTrue(runwayFusionShouldRebuild($artifact, $stamps));
    }

    public function testShouldRebuild_RunwaysCacheNewerThanRecordedStamp_ReturnsTrue(): void
    {
        $stamps = $this->inputStamps();
        $artifact = $this->freshArtifact($stamps);
        $stamps['runways_cache_mtime'] = 2000;
        $this->assertTrue(runwayFusionShouldRebuild($artifact, $stamps));
    }

    public function testShouldRebuild_ConfigShaChanged_ReturnsTrue(): void
    {
        $stamps = $this->inputStamps();
        $artifact = $this->freshArtifact($stamps);
        $stamps['config_sha'] = 'other-sha';
        $this->assertTrue(runwayFusionShouldRebuild($artifact, $stamps));
    }

    public function testShouldRebuild_ConfigMtimeNewerThanRecordedStamp_ReturnsTrue(): void
    {
        $stamps = $this->inputStamps();
        $stamps['config_mtime'] = 1000;
        $artifact = $this->freshArtifact($stamps);
        // Same bytes redeployed: the sha matches, the mtime moved, and the
        // read-path gate withholds the artifact until this rebuild runs.
        $stamps['config_mtime'] = 2000;
        $this->assertTrue(runwayFusionShouldRebuild($artifact, $stamps));
    }

    public function testShouldRebuild_ConfigMtimeUnchanged_ReturnsFalse(): void
    {
        $stamps = $this->inputStamps();
        $stamps['config_mtime'] = 1000;
        $this->assertFalse(runwayFusionShouldRebuild($this->freshArtifact($stamps), $stamps));
    }

    public function testShouldRebuild_AgeExceedsMax_ReturnsTrue(): void
    {
        $stamps = $this->inputStamps();
        $artifact = $this->freshArtifact($stamps);
        $artifact['built_at'] = time() - RUNWAY_FUSION_MAX_AGE - 10;
        $this->assertTrue(runwayFusionShouldRebuild($artifact, $stamps));
    }

    public function testShouldRebuild_SchemaVersionMismatch_ReturnsTrue(): void
    {
        $stamps = $this->inputStamps();
        $artifact = $this->freshArtifact($stamps);
        $artifact['schema_version'] = 99;
        $this->assertTrue(runwayFusionShouldRebuild($artifact, $stamps));
    }

    public function testShouldRebuild_InputMissingOnDisk_KeepsFreshArtifact(): void
    {
        $stamps = $this->inputStamps();
        $artifact = $this->freshArtifact($stamps);
        // NASR cache went missing (cold start): keep the artifact rather than
        // churning a rebuild that would just drop the official slice.
        $stamps['nasr_apt_mtime'] = null;
        $this->assertFalse(runwayFusionShouldRebuild($artifact, $stamps));
    }

    public function testShouldRebuild_RunwaysCacheBuiltInThenGone_ReturnsTrue(): void
    {
        $stamps = $this->inputStamps();
        $artifact = $this->freshArtifact($stamps);
        // The merge retention gate deletes the runways cache on failed
        // fetches. The read-path gate withholds the artifact in this state,
        // so the worker must rebuild rather than keep skipping it.
        $stamps['runways_cache_mtime'] = null;
        $this->assertTrue(runwayFusionShouldRebuild($artifact, $stamps));
    }

    public function testConfigStampMatchesParsed_StampMatchesParsedDigest_ReturnsTrue(): void
    {
        $this->assertTrue(runwayFusionConfigStampMatchesParsed('sha-a', ['config_sha' => 'sha-a']));
    }

    public function testConfigStampMatchesParsed_StampNamesDifferentGeneration_ReturnsFalse(): void
    {
        // The A to B to A race: loadConfig() parsed generation B's bytes and
        // recorded them; the file reverted before the stamp read, so the
        // stamp names generation A. Publishing here would pair B's rows with
        // A's stamp, and the freshness gate would serve it as current.
        $this->assertFalse(runwayFusionConfigStampMatchesParsed('sha-b', ['config_sha' => 'sha-a']));
    }

    public function testConfigStampMatchesParsed_MissingDigestOrStamp_ReturnsFalse(): void
    {
        $this->assertFalse(runwayFusionConfigStampMatchesParsed(null, ['config_sha' => 'sha-a']));
        $this->assertFalse(runwayFusionConfigStampMatchesParsed('', ['config_sha' => 'sha-a']));
        $this->assertFalse(runwayFusionConfigStampMatchesParsed('sha-a', ['config_sha' => null]));
    }

    public function testPublishGate_StableStamps_AcceptsPublish(): void
    {
        $stamps = $this->inputStamps();
        $this->assertTrue(runwayFusionPublishGatePasses($stamps, $stamps, 'test-sha'));
    }

    public function testPublishGate_SameMtimeFlipBetweenChecks_RejectsPublish(): void
    {
        // The post-load stamp read saw generation A. Before the final gate
        // the file flips to B with its mtime restored: the re-read shows the
        // same mtimes but a different sha, so the gate must reject and the
        // worker keeps the existing artifact.
        $before = $this->inputStamps();
        $before['config_sha'] = 'sha-a';
        $after = $before;
        $after['config_sha'] = 'sha-b';
        $this->assertFalse(runwayFusionPublishGatePasses($before, $after, 'sha-a'));
    }

    public function testPublishGate_DriftedNasrMtime_RejectsPublish(): void
    {
        $before = $this->inputStamps();
        $after = $before;
        $after['nasr_apt_mtime'] = 2000;
        $this->assertFalse(runwayFusionPublishGatePasses($before, $after, 'test-sha'));
    }

    public function testInputDriftKey_StableStamps_ReturnsNull(): void
    {
        $stamps = $this->inputStamps();
        $stamps['config_mtime'] = 1000;
        $this->assertNull(runwayFusionInputDriftKey($stamps, $stamps));
    }

    public function testInputDriftKey_ChangedConfig_ReturnsConfigKey(): void
    {
        $before = $this->inputStamps();
        $before['config_mtime'] = 1000;

        $after = $before;
        $after['config_sha'] = 'other-sha';
        $this->assertSame('config_sha', runwayFusionInputDriftKey($before, $after));

        $after = $before;
        $after['config_mtime'] = 2000;
        $this->assertSame('config_mtime', runwayFusionInputDriftKey($before, $after));
    }

    public function testInputDriftKey_CacheAppearedOrVanished_IsDrift(): void
    {
        $before = $this->inputStamps();
        $before['config_mtime'] = 1000;

        $after = $before;
        $after['nasr_apt_mtime'] = null;
        $this->assertSame('nasr_apt_mtime', runwayFusionInputDriftKey($before, $after));

        $before['runways_cache_mtime'] = null;
        $after = $before;
        $after['runways_cache_mtime'] = 1000;
        $this->assertSame('runways_cache_mtime', runwayFusionInputDriftKey($before, $after));
    }

    public function testArtifactRoundTrip_WriteAndLoad(): void
    {
        if (is_file(CACHE_RUNWAY_FUSION_FILE)) {
            @unlink(CACHE_RUNWAY_FUSION_FILE);
        }
        $this->assertNull(runwayFusionLoadArtifact());

        $payload = [
            'schema_version' => RUNWAY_FUSION_SCHEMA_VERSION,
            'built_at' => time(),
            'inputs' => $this->inputStamps(),
            'nasr_effective_date' => '2026-10-01',
            'airports' => ['42b' => ['rows' => [], 'segments' => [], 'calm_wind' => null]],
        ];
        $this->assertTrue(runwayFusionWriteArtifact($payload));

        $loaded = runwayFusionLoadArtifact();
        $this->assertIsArray($loaded);
        $this->assertSame($payload['schema_version'], $loaded['schema_version']);
        $this->assertSame('2026-10-01', $loaded['nasr_effective_date']);
        $this->assertArrayHasKey('42b', $loaded['airports']);
    }

    public function testLoadArtifact_InvalidContent_ReturnsNull(): void
    {
        $dir = dirname(CACHE_RUNWAY_FUSION_FILE);
        if (!is_dir($dir) && !@mkdir($dir, 0755, true) && !is_dir($dir)) {
            $this->markTestSkipped("cannot create {$dir}");
        }
        try {
            file_put_contents(CACHE_RUNWAY_FUSION_FILE, '{not json');
            $this->assertNull(runwayFusionLoadArtifact());

            file_put_contents(CACHE_RUNWAY_FUSION_FILE, json_encode([
                'schema_version' => 99,
                'airports' => [],
            ]));
            $this->assertNull(runwayFusionLoadArtifact());
        } finally {
            if (is_file(CACHE_RUNWAY_FUSION_FILE)) {
                @unlink(CACHE_RUNWAY_FUSION_FILE);
            }
        }
    }

    public function testInputStamps_ReadsMtimesAndConfigSha(): void
    {
        $configPath = tempnam(sys_get_temp_dir(), 'runway-fusion-cfg');
        $this->assertIsString($configPath);
        file_put_contents($configPath, '{"airports": {}}');

        try {
            $stamps = runwayFusionInputStamps($configPath);
            $this->assertArrayHasKey('nasr_apt_mtime', $stamps);
            $this->assertArrayHasKey('runways_cache_mtime', $stamps);
            $this->assertArrayHasKey('config_mtime', $stamps);
            $this->assertSame(hash('sha256', '{"airports": {}}'), $stamps['config_sha']);
            $this->assertSame((int) filemtime($configPath), $stamps['config_mtime']);
        } finally {
            @unlink($configPath);
        }
    }

    public function testConfigStampMatchesParsed_SameMtimeContentFlip_ReturnsFalse(): void
    {
        // A config changed during the build loop with the same integer mtime
        // (a redeploy that restores the timestamp) is invisible to any mtime
        // comparison. The pre-write gate pairs the current stamp against the
        // digest loadConfig() parsed, so the sha catches it regardless.
        $configPath = tempnam(sys_get_temp_dir(), 'runway-fusion-cfg');
        $this->assertIsString($configPath);
        file_put_contents($configPath, '{"airports": {}}');
        $stampsA = runwayFusionInputStamps($configPath);
        $mtimeA = (int) filemtime($configPath);

        try {
            file_put_contents($configPath, '{"airports": {"x": {}}}');
            $this->assertTrue(touch($configPath, $mtimeA));

            $stampsB = runwayFusionInputStamps($configPath);
            $this->assertSame($mtimeA, (int) filemtime($configPath));
            $this->assertNotSame($stampsA['config_sha'], $stampsB['config_sha']);
            $this->assertFalse(runwayFusionConfigStampMatchesParsed($stampsA['config_sha'], $stampsB));
        } finally {
            @unlink($configPath);
        }
    }

    // --- Fixtures -----------------------------------------------------------

    private function goodspeedAirport(): array
    {
        return [
            'id' => '42B',
            'name' => 'Goodspeed Airport',
            'faa' => '42B',
            'ourairports_ident' => '42B',
            'lat' => 41.44565444,
            'lon' => -72.4556425,
            'elevation_ft' => 9,
            'enabled' => true,
            'magnetic_declination' => -14.0,
        ];
    }

    private function goodspeedNasrPayload(): array
    {
        return [
            'schema_version' => 1,
            'effective_date' => '2026-10-01',
            'airports' => [
                '42B' => [
                    'arpt_id' => '42B',
                    'icao_id' => null,
                    'notam_id' => 'BDR',
                    'elev_ft' => 9,
                    'lat' => 41.44565444,
                    'lon' => -72.4556425,
                    'mag_declination_deg' => -14.0,
                    'mag_declination_year' => 1985,
                    'runways' => [
                        [
                            'rwy_id' => '14/32',
                            'length_ft' => 2120,
                            'width_ft' => 50,
                            'surface' => 'ASPH',
                            'condition' => 'GOOD',
                            'lights_code' => 'LOW',
                            'ends' => [
                                $this->nasrEnd('14'),
                                $this->nasrEnd('32'),
                            ],
                        ],
                        [
                            'rwy_id' => '16W/34W',
                            'length_ft' => 4500,
                            'width_ft' => 1000,
                            'surface' => 'WATER',
                            'condition' => 'GOOD',
                            'lights_code' => null,
                            'ends' => [
                                $this->nasrEnd('16W'),
                                $this->nasrEnd('34W'),
                            ],
                        ],
                    ],
                ],
            ],
        ];
    }

    /**
     * Merge-cache entry for 42B: FAA geometry for 14/32 only (NGDA publishes
     * no water strip) plus OurAirports display rows for both pairs.
     */
    private function goodspeedRunwaysData(): array
    {
        return [
            'airports' => [
                '42B' => [
                    'segments' => [
                        [
                            'start' => [0.9, -0.035],
                            'end' => [-0.9, 0.035],
                            'le_ident' => '14',
                            'he_ident' => '32',
                            'ident_at_start' => '32',
                            'ident_at_end' => '14',
                            'source' => 'faa',
                        ],
                    ],
                    'display_runways' => [
                        [
                            'rwy_id' => '14/32',
                            'length_ft' => 2120,
                            'width_ft' => 50,
                            'surface' => 'ASPH',
                            'lighted' => true,
                            'closed' => false,
                            'ends' => [['end_id' => '14'], ['end_id' => '32']],
                            'le_displaced_threshold_ft' => 0,
                            'he_displaced_threshold_ft' => 0,
                        ],
                        [
                            'rwy_id' => '16W/34W',
                            'length_ft' => 4500,
                            'width_ft' => 1000,
                            'surface' => 'WATER',
                            'lighted' => false,
                            'closed' => false,
                            'ends' => [['end_id' => '16W'], ['end_id' => '34W']],
                            'le_displaced_threshold_ft' => 0,
                            'he_displaced_threshold_ft' => 0,
                        ],
                    ],
                ],
            ],
        ];
    }

    private function nasrEnd(string $endId): array
    {
        return [
            'end_id' => $endId,
            'true_alignment' => null,
            'right_hand_traffic' => false,
            'elev_ft' => null,
            'tkof_dist_avbl' => null,
            'displaced_thr_len' => null,
            'rwy_grad_pct' => null,
            'obstruction' => [
                'type' => null,
                'hgt_ft' => null,
                'dist_ft' => null,
                'slope' => null,
            ],
        ];
    }

    private function inputStamps(): array
    {
        return [
            'nasr_apt_mtime' => 1000,
            'runways_cache_mtime' => 1000,
            'config_sha' => 'test-sha',
        ];
    }

    private function freshArtifact(array $stamps): array
    {
        return [
            'schema_version' => RUNWAY_FUSION_SCHEMA_VERSION,
            'built_at' => time() - 60,
            'inputs' => $stamps,
            'nasr_effective_date' => '2026-10-01',
            'airports' => [],
        ];
    }

    /**
     * @param list<array<string, mixed>> $rows
     * @return array<string, array<string, mixed>>
     */
    private function rowsByKey(array $rows): array
    {
        $byKey = [];
        foreach ($rows as $row) {
            $byKey[$row['key']] = $row;
        }
        return $byKey;
    }
}
