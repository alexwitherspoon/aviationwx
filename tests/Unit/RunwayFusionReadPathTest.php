<?php

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../lib/runway-fusion.php';
require_once __DIR__ . '/../../lib/runway-display.php';
require_once __DIR__ . '/../Helpers/LoadsNasrAptFixtureCacheTrait.php';

/**
 * Read-path consumption of the fused artifact: list and compass go
 * fused-first with a staleness gate that falls back to the live resolver.
 *
 * The artifact is written to the real (gitignored) cache path with input
 * stamps matched to the on-disk fixtures; the gate itself is stat-only.
 */
class RunwayFusionReadPathTest extends TestCase
{
    use LoadsNasrAptFixtureCacheTrait;

    protected bool $createdNasrDir = false;

    protected function setUp(): void
    {
        parent::setUp();
        $this->loadNasrAptFixtureCache();
        resetRunwayFusionArtifactMemo();
        $this->removeArtifact();
    }

    protected function tearDown(): void
    {
        $this->removeArtifact();
        resetRunwayFusionArtifactMemo();
        $this->removeNasrFileIfCreated();
        $this->tearDownNasrAptFixtureCache();
        parent::tearDown();
    }

    public function testList_FreshArtifact_UnionsInventoryWithNasrSourceLine(): void
    {
        $this->writeArtifact([
            '42b' => $this->goodspeedFusedEntry(),
        ]);

        $display = getRunwayDisplayForAirport($this->goodspeedAirport(), '42b');

        $this->assertIsArray($display);
        $this->assertSame('nasr', $display['runway_source']);
        $this->assertSame('FAA NASR', $display['source_reference']);
        $this->assertSame('2026-10-01', $display['effective_date']);

        $byId = [];
        foreach ($display['runways'] as $row) {
            $byId[$row['rwy_id']] = $row;
        }
        $this->assertCount(2, $byId);
        $this->assertFalse($byId['14/32']['is_water']);
        $this->assertTrue($byId['16W/34W']['is_water']);
        $this->assertFalse($byId['16W/34W']['closed']);
    }

    public function testList_FreshArtifact_IncludesOaOnlyPairThatLiveNasrLacks(): void
    {
        // Live NASR for 03S has one strip; the fused artifact adds an
        // OurAirports-only pair. Fresh artifact: both rows. Expired artifact:
        // the live resolver's closed-world inventory (one row).
        setNasrAptCacheForTesting($this->stripFieldNasrPayload());

        $this->writeArtifact([
            '03s' => $this->stripFieldFusedEntry(),
        ]);
        $fresh = getRunwayDisplayForAirport($this->stripFieldAirport(), '03s');
        $this->assertIsArray($fresh);
        $this->assertCount(2, $fresh['runways']);
        // Airport-level line stays the official source; the row-level
        // provenance marks the OA-only pair.
        $this->assertSame('nasr', $fresh['runway_source']);
        $rowSource = [];
        foreach ($fresh['runways'] as $row) {
            $rowSource[$row['rwy_id']] = $row['row_source'];
        }
        $this->assertSame('nasr', $rowSource['16/34']);
        $this->assertSame('ourairports', $rowSource['17W/35W']);

        // Expire the artifact: the gate fails, the live resolver takes over.
        $artifact = $this->readArtifact();
        $artifact['built_at'] = time() - RUNWAY_FUSION_MAX_AGE - 10;
        file_put_contents(CACHE_RUNWAY_FUSION_FILE, json_encode($artifact));
        resetRunwayFusionArtifactMemo();

        $stale = getRunwayDisplayForAirport($this->stripFieldAirport(), '03s');
        $this->assertIsArray($stale);
        $this->assertCount(1, $stale['runways']);
    }

    public function testList_MissingArtifact_UsesLiveResolver(): void
    {
        setNasrAptCacheForTesting($this->stripFieldNasrPayload());

        $display = getRunwayDisplayForAirport($this->stripFieldAirport(), '03s');

        $this->assertIsArray($display);
        $this->assertSame('nasr', $display['runway_source']);
        $this->assertCount(1, $display['runways']);
    }

    public function testList_FreshArtifact_OaOnlyInventory_ReadsAsOurAirports(): void
    {
        // No official rows for this airport: the union is the OurAirports
        // pair alone, and the payload carries the community source line.
        $this->writeArtifact([
            '42b' => [
                'rows' => [
                    [
                        'key' => '16W/34W',
                        'source' => 'ourairports',
                        'effective_date' => null,
                        'is_water' => true,
                        'closed' => false,
                        'field_sources' => ['length_ft' => 'ourairports'],
                        'nasr' => null,
                        'oa' => [
                            'rwy_id' => '16W/34W',
                            'length_ft' => 4500,
                            'width_ft' => 1000,
                            'surface' => 'WATER',
                            'lighted' => false,
                            'closed' => false,
                            'ends' => [
                                ['end_id' => '16W'],
                                ['end_id' => '34W'],
                            ],
                        ],
                        'config' => null,
                    ],
                ],
                'segments' => [],
                'calm_wind' => null,
            ],
        ]);

        $display = getRunwayDisplayForAirport($this->goodspeedAirport(), '42b');

        $this->assertIsArray($display);
        $this->assertSame('ourairports', $display['runway_source']);
        $this->assertSame('OurAirports', $display['source_reference']);
        $this->assertNull($display['effective_date']);
        $this->assertCount(1, $display['runways']);
        $this->assertTrue($display['runways'][0]['is_water']);
        $this->assertSame('ourairports', $display['runways'][0]['row_source']);
    }

    public function testList_FreshArtifact_NotamClosureStillApplies(): void
    {
        $this->writeArtifact([
            '42b' => $this->goodspeedFusedEntry(),
        ]);

        $display = getRunwayDisplayForAirport($this->goodspeedAirport(), '42b', [
            'notam_closures' => [
                'aerodrome_closed' => false,
                'closed_pair_designators' => ['14/32'],
                'closed_end_idents' => [],
            ],
        ]);

        $this->assertIsArray($display);
        $byId = [];
        foreach ($display['runways'] as $row) {
            $byId[$row['rwy_id']] = $row;
        }
        $this->assertTrue($byId['14/32']['closed']);
        $this->assertFalse($byId['16W/34W']['closed']);
    }

    public function testArtifactIsFresh_ValidCurrent_ReturnsTrue(): void
    {
        $this->writeArtifact([]);
        $this->assertTrue(runwayFusionArtifactIsFresh());
    }

    public function testArtifactIsFresh_NasrFileNewerThanRecordedStamp_ReturnsFalse(): void
    {
        $dir = CACHE_NASR_DIR;
        if (!is_dir($dir)) {
            $this->createdNasrDir = @mkdir($dir, 0755, true) && true;
        }
        touch(CACHE_NASR_APT_DATA_FILE);

        $this->writeArtifact([], ['nasr_apt_mtime' => 1000]);
        $this->assertFalse(runwayFusionArtifactIsFresh());
    }

    public function testArtifactIsFresh_Expired_ReturnsFalse(): void
    {
        $this->writeArtifact([], null, time() - RUNWAY_FUSION_MAX_AGE - 10);
        $this->assertFalse(runwayFusionArtifactIsFresh());
    }

    public function testArtifactIsFresh_ConfigNewerThanRecordedStamp_ReturnsFalse(): void
    {
        $this->writeArtifact([], ['config_mtime' => 1000]);
        $this->assertFalse(runwayFusionArtifactIsFresh());
    }

    public function testArtifactIsFresh_RunwaysCacheBuiltInThenGone_ReturnsFalse(): void
    {
        // The merge retention gate deletes runways_data.json on failed
        // fetches; an artifact that assumed its geometry must not be served.
        $hidden = $this->hideFileIfPresent(CACHE_RUNWAYS_DATA_FILE);
        try {
            $this->writeArtifact([], ['runways_cache_mtime' => 1000]);
            $this->assertFalse(runwayFusionArtifactIsFresh());
        } finally {
            $this->restoreHiddenFile($hidden);
        }
    }

    public function testArtifactIsFresh_NasrBuiltOutAndStillGone_ReturnsTrue(): void
    {
        // Built without a NASR cache (stamp 0) and it is still gone: the
        // artifact holds every row the sources had, so it stays servable.
        $hiddenNasr = $this->hideFileIfPresent(CACHE_NASR_APT_DATA_FILE);
        $hiddenRunways = $this->hideFileIfPresent(CACHE_RUNWAYS_DATA_FILE);
        try {
            $this->writeArtifact([], ['nasr_apt_mtime' => 0, 'runways_cache_mtime' => 0]);
            $this->assertTrue(runwayFusionArtifactIsFresh());
        } finally {
            $this->restoreHiddenFile($hiddenNasr);
            $this->restoreHiddenFile($hiddenRunways);
        }
    }

    public function testEntryForAirport_FreshReturnsEntryStaleReturnsNull(): void
    {
        $this->writeArtifact([
            '42b' => $this->goodspeedFusedEntry(),
        ]);
        $entry = runwayFusionEntryForAirport('42b');
        $this->assertIsArray($entry);
        $this->assertCount(2, $entry['rows']);
        $this->assertNull(runwayFusionEntryForAirport('no-such-airport'));

        $artifact = $this->readArtifact();
        $artifact['schema_version'] = 99;
        file_put_contents(CACHE_RUNWAY_FUSION_FILE, json_encode($artifact));
        resetRunwayFusionArtifactMemo();
        $this->assertNull(runwayFusionEntryForAirport('42b'));
    }

    public function testCompass_FreshArtifact_MergesDerivedSegmentWithGeometry(): void
    {
        // The test fixture cache has FAA geometry for 03S (34/16) and no
        // display rows; the fused entry adds the water strip 17W/35W.
        $this->writeArtifact([
            '03s' => [
                'rows' => [
                    [
                        'key' => '16/34',
                        'source' => 'nasr',
                        'effective_date' => '2026-10-01',
                        'is_water' => false,
                        'closed' => false,
                        'field_sources' => [],
                        'nasr' => [
                            'rwy_id' => '16/34',
                            'length_ft' => 3000,
                            'width_ft' => 45,
                            'surface' => 'ASPH',
                            'condition' => 'GOOD',
                            'lights_code' => null,
                            'ends' => [['end_id' => '16'], ['end_id' => '34']],
                        ],
                        'oa' => null,
                        'config' => null,
                    ],
                ],
                'segments' => [
                    [
                        'start' => [sin(170 * M_PI / 180) * 0.9, cos(170 * M_PI / 180) * 0.9],
                        'end' => [-sin(170 * M_PI / 180) * 0.9, -cos(170 * M_PI / 180) * 0.9],
                        'le_ident' => '17W',
                        'he_ident' => '35W',
                        'ident_at_start' => '35W',
                        'ident_at_end' => '17W',
                        'source' => 'fusion',
                        'synthesized' => true,
                        'water' => true,
                        'magnetic' => true,
                    ],
                ],
                'calm_wind' => null,
            ],
        ]);

        $segments = getRunwaySegmentsForAirport('03s', $this->stripFieldAirport());

        $this->assertIsArray($segments);
        $this->assertCount(2, $segments);

        $byLe = [];
        foreach ($segments as $seg) {
            $byLe[$seg['le_ident']] = $seg;
        }
        // The fixture geometry pair survives the merge...
        $this->assertArrayHasKey('34', $byLe);
        // ...and the water strip is added, flagged, in the magnetic frame.
        $this->assertArrayHasKey('17W', $byLe);
        $this->assertTrue($byLe['17W']['water']);
        $this->assertTrue($byLe['17W']['magnetic']);
        $this->assertSame('fusion', $byLe['17W']['source']);
        // The centering pass rescales the set (farthest endpoint capped at
        // 0.9), so assert the frame, not raw coordinates: the 17W end must
        // sit at its 170-degree bearing, not rotated by the airport's -8
        // declination (which would read 162 or 178).
        $start = $byLe['17W']['start'];
        $bearing = fmod(rad2deg(atan2($start[0], $start[1])) + 360.0, 360.0);
        $this->assertEqualsWithDelta(
            170.0,
            $bearing,
            0.1,
            'magnetic segments must not be rotated again'
        );
        $this->assertLessThanOrEqual(0.9001, sqrt($start[0] ** 2 + $start[1] ** 2));
    }

    public function testCompass_FreshArtifact_NoFileCacheEntry_DerivedSegmentsCarryTheCompass(): void
    {
        // 42b has no geometry in the fixture merge cache; a fresh artifact's
        // derived segment replaces the closed-world display fallback.
        $this->writeArtifact([
            '42b' => [
                'rows' => [
                    [
                        'key' => '16W/34W',
                        'source' => 'nasr',
                        'effective_date' => '2026-10-01',
                        'is_water' => true,
                        'closed' => false,
                        'field_sources' => ['length_ft' => 'nasr'],
                        'nasr' => [
                            'rwy_id' => '16W/34W',
                            'length_ft' => 4500,
                            'width_ft' => 1000,
                            'surface' => 'WATER',
                            'condition' => 'GOOD',
                            'lights_code' => null,
                            'ends' => [
                                ['end_id' => '16W', 'true_alignment' => null],
                                ['end_id' => '34W', 'true_alignment' => null],
                            ],
                        ],
                        'oa' => null,
                        'config' => null,
                    ],
                ],
                'segments' => [[
                    'start' => [sin(160 * M_PI / 180) * 0.9, cos(160 * M_PI / 180) * 0.9],
                    'end' => [-sin(160 * M_PI / 180) * 0.9, -cos(160 * M_PI / 180) * 0.9],
                    'le_ident' => '16W',
                    'he_ident' => '34W',
                    'source' => 'fusion',
                    'synthesized' => true,
                    'water' => true,
                    'magnetic' => true,
                ]],
                'calm_wind' => null,
            ],
        ]);

        $segments = getRunwaySegmentsForAirport('42b', $this->goodspeedAirport());

        $this->assertIsArray($segments);
        $this->assertCount(1, $segments);
        $this->assertSame('16W', $segments[0]['le_ident']);
        $this->assertSame('fusion', $segments[0]['source']);
        $this->assertTrue($segments[0]['water']);
        $this->assertTrue($segments[0]['magnetic']);
    }

    public function testCompass_StaleArtifact_UsesCacheSegmentsOnly(): void
    {
        $this->writeArtifact(
            [
                '03s' => [
                    'rows' => [],
                    'segments' => [[
                        'start' => [0.1, 0.1],
                        'end' => [-0.1, -0.1],
                        'le_ident' => '17W',
                        'he_ident' => '35W',
                        'source' => 'fusion',
                        'water' => true,
                        'magnetic' => true,
                    ]],
                    'calm_wind' => null,
                ],
            ],
            null,
            time() - RUNWAY_FUSION_MAX_AGE - 10
        );

        $segments = getRunwaySegmentsForAirport('03s', $this->stripFieldAirport());

        $this->assertIsArray($segments);
        $this->assertCount(1, $segments);
        $this->assertSame('34', $segments[0]['le_ident']);
    }

    public function testCompass_ManualRunways_StillOverrideWithFreshArtifact(): void
    {
        $this->writeArtifact([
            '03s' => [
                'rows' => [],
                'segments' => [[
                    'start' => [0.1, 0.1],
                    'end' => [-0.1, -0.1],
                    'le_ident' => '17W',
                    'he_ident' => '35W',
                    'source' => 'fusion',
                    'water' => true,
                    'magnetic' => true,
                ]],
                'calm_wind' => null,
            ],
        ]);

        $airport = $this->stripFieldAirport();
        $airport['runways'] = [
            ['name' => '09/27', 'heading_1' => 90, 'heading_2' => 270],
        ];

        $segments = getRunwaySegmentsForAirport('03s', $airport);

        $this->assertIsArray($segments);
        $this->assertCount(1, $segments);
        $this->assertSame('09', $segments[0]['le_ident']);
    }

    public function testMergeDerivedSegments_DedupesByNormalizedPairKey(): void
    {
        $geometry = [
            [
                'start' => [0.9, 0.0],
                'end' => [-0.9, 0.0],
                'le_ident' => '34',
                'he_ident' => '16',
                'source' => 'faa',
            ],
            [
                'start' => [0.0, 0.9],
                'end' => [0.0, -0.9],
                'le_ident' => '12',
                'he_ident' => '30',
                'source' => 'faa',
            ],
        ];
        $fused = [
            [
                'start' => [0.1, 0.1],
                'end' => [-0.1, -0.1],
                'le_ident' => '16',
                'he_ident' => '34',
                'source' => 'fusion',
                'synthesized' => true,
            ],
            [
                'start' => [0.2, 0.2],
                'end' => [-0.2, -0.2],
                'le_ident' => '17W',
                'he_ident' => '35W',
                'source' => 'fusion',
                'synthesized' => true,
                'water' => true,
                'magnetic' => true,
            ],
        ];

        $merged = runwayFusionMergeDerivedSegments($geometry, $fused);

        $this->assertCount(3, $merged);
        $byLe = [];
        foreach ($merged as $seg) {
            $byLe[$seg['le_ident']] = $seg;
        }
        // The duplicated 34/16 keeps the geometry segment, not the derived one.
        $this->assertSame('faa', $byLe['34']['source']);
        $this->assertSame('fusion', $byLe['17W']['source']);
        $this->assertSame('faa', $byLe['12']['source']);
    }

    // --- Fixtures and helpers ------------------------------------------------

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

    private function goodspeedFusedEntry(): array
    {
        return [
            'rows' => [
                [
                    'key' => '14/32',
                    'source' => 'nasr',
                    'effective_date' => '2026-10-01',
                    'is_water' => false,
                    'closed' => false,
                    'field_sources' => ['length_ft' => 'nasr'],
                    'nasr' => [
                        'rwy_id' => '14/32',
                        'length_ft' => 2120,
                        'width_ft' => 50,
                        'surface' => 'ASPH',
                        'condition' => 'GOOD',
                        'lights_code' => 'LOW',
                        'ends' => [
                            ['end_id' => '14', 'true_alignment' => null],
                            ['end_id' => '32', 'true_alignment' => null],
                        ],
                    ],
                    'oa' => null,
                    'config' => null,
                ],
                [
                    'key' => '16W/34W',
                    'source' => 'nasr',
                    'effective_date' => '2026-10-01',
                    'is_water' => true,
                    'closed' => false,
                    'field_sources' => ['length_ft' => 'nasr'],
                    'nasr' => [
                        'rwy_id' => '16W/34W',
                        'length_ft' => 4500,
                        'width_ft' => 1000,
                        'surface' => 'WATER',
                        'condition' => 'GOOD',
                        'lights_code' => null,
                        'ends' => [
                            ['end_id' => '16W', 'true_alignment' => null],
                            ['end_id' => '34W', 'true_alignment' => null],
                        ],
                    ],
                    'oa' => null,
                    'config' => null,
                ],
            ],
            'segments' => [],
            'calm_wind' => null,
        ];
    }

    /**
     * Live NASR payload for the 03S-shaped test field: one land strip.
     */
    private function stripFieldNasrPayload(): array
    {
        return [
            'schema_version' => 1,
            'airports' => [
                '03S' => [
                    'arpt_id' => '03S',
                    'icao_id' => null,
                    'notam_id' => '03S',
                    'elev_ft' => 200,
                    'lat' => 33.0,
                    'lon' => -116.0,
                    'mag_declination_deg' => -8.0,
                    'mag_declination_year' => 2020,
                    'runways' => [
                        [
                            'rwy_id' => '16/34',
                            'length_ft' => 3000,
                            'width_ft' => 45,
                            'surface' => 'ASPH',
                            'condition' => 'GOOD',
                            'lights_code' => null,
                            'ends' => [
                                ['end_id' => '16', 'true_alignment' => null],
                                ['end_id' => '34', 'true_alignment' => null],
                            ],
                        ],
                    ],
                ],
            ],
        ];
    }

    private function stripFieldAirport(): array
    {
        return [
            'id' => '03s',
            'name' => 'Strip Field',
            'faa' => '03S',
            'ourairports_ident' => '03S',
            'lat' => 33.0,
            'lon' => -116.0,
            'elevation_ft' => 200,
            'enabled' => true,
            'magnetic_declination' => -8.0,
        ];
    }

    /**
     * Fused entry for the 03S-shaped field: the NASR land strip plus an
     * OurAirports-only water pair, with the water pair's derived segment.
     */
    private function stripFieldFusedEntry(): array
    {
        return [
            'rows' => [
                [
                    'key' => '16/34',
                    'source' => 'nasr',
                    'effective_date' => '2026-10-01',
                    'is_water' => false,
                    'closed' => false,
                    'field_sources' => ['length_ft' => 'nasr'],
                    'nasr' => [
                        'rwy_id' => '16/34',
                        'length_ft' => 3000,
                        'width_ft' => 45,
                        'surface' => 'ASPH',
                        'condition' => 'GOOD',
                        'lights_code' => null,
                        'ends' => [
                            ['end_id' => '16', 'true_alignment' => null],
                            ['end_id' => '34', 'true_alignment' => null],
                        ],
                    ],
                    'oa' => null,
                    'config' => null,
                ],
                [
                    'key' => '17W/35W',
                    'source' => 'ourairports',
                    'effective_date' => null,
                    'is_water' => true,
                    'closed' => false,
                    'field_sources' => ['length_ft' => 'ourairports'],
                    'nasr' => null,
                    'oa' => [
                        'rwy_id' => '17W/35W',
                        'length_ft' => 1200,
                        'width_ft' => 300,
                        'surface' => 'WATER',
                        'lighted' => false,
                        'closed' => false,
                        'ends' => [
                            ['end_id' => '17W'],
                            ['end_id' => '35W'],
                        ],
                    ],
                    'config' => null,
                ],
            ],
            'segments' => [],
            'calm_wind' => null,
        ];
    }

    /**
     * Write the artifact to the real cache path. Stamps are truthful about
     * whatever on-disk inputs the cache dir happens to hold (CI checkouts
     * have none; local dirs may carry gitignored leftovers), so the gate
     * passes on the stamps alone. config_mtime matches the real fixture
     * config.
     *
     * @param array<string, mixed> $airports
     * @param array<string, mixed>|null $stampOverrides
     */
    private function writeArtifact(array $airports, ?array $stampOverrides = null, ?int $builtAt = null): void
    {
        $configPath = getConfigFilePath();
        clearstatcache();
        $stamps = [
            'nasr_apt_mtime' => is_readable(CACHE_NASR_APT_DATA_FILE) ? (int) filemtime(CACHE_NASR_APT_DATA_FILE) : 0,
            'runways_cache_mtime' => is_readable(CACHE_RUNWAYS_DATA_FILE) ? (int) filemtime(CACHE_RUNWAYS_DATA_FILE) : 0,
            'config_sha' => 'test-sha',
            'config_mtime' => is_string($configPath) && $configPath !== '' && is_file($configPath)
                ? (int) filemtime($configPath)
                : 0,
        ];
        foreach ((array) $stampOverrides as $key => $value) {
            $stamps[$key] = $value;
        }

        $payload = [
            'schema_version' => RUNWAY_FUSION_SCHEMA_VERSION,
            'built_at' => $builtAt ?? time() - 60,
            'inputs' => $stamps,
            'nasr_effective_date' => '2026-10-01',
            'airports' => $airports,
        ];

        $dir = dirname(CACHE_RUNWAY_FUSION_FILE);
        if (!is_dir($dir)) {
            @mkdir($dir, 0755, true);
        }
        file_put_contents(CACHE_RUNWAY_FUSION_FILE, json_encode($payload));
        resetRunwayFusionArtifactMemo();
    }

    /**
     * @return array<string, mixed>
     */
    private function readArtifact(): array
    {
        $data = json_decode((string) file_get_contents(CACHE_RUNWAY_FUSION_FILE), true);
        $this->assertIsArray($data);
        return $data;
    }

    private function removeArtifact(): void
    {
        if (is_file(CACHE_RUNWAY_FUSION_FILE)) {
            @unlink(CACHE_RUNWAY_FUSION_FILE);
        }
    }

    /**
     * Move a cache input file aside so a test can pin "input absent"
     * semantics. Returns the path to restore, or null when nothing was
     * present.
     */
    private function hideFileIfPresent(string $path): ?string
    {
        if (!is_file($path)) {
            return null;
        }
        $hidden = $path . '.hidden.' . getmypid();
        if (!@rename($path, $hidden)) {
            $this->fail('could not move ' . $path . ' aside');
        }

        return $hidden;
    }

    private function restoreHiddenFile(?string $hiddenPath): void
    {
        if ($hiddenPath === null || !is_file($hiddenPath)) {
            return;
        }
        if (!@rename($hiddenPath, substr($hiddenPath, 0, -strlen('.hidden.' . getmypid())))) {
            $this->fail('could not restore ' . $hiddenPath);
        }
    }

    private function removeNasrFileIfCreated(): void
    {
        if (is_file(CACHE_NASR_APT_DATA_FILE)) {
            @unlink(CACHE_NASR_APT_DATA_FILE);
        }
        if ($this->createdNasrDir && is_dir(CACHE_NASR_DIR)) {
            @rmdir(CACHE_NASR_DIR);
        }
    }
}
