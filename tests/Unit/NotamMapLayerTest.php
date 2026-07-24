<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../lib/notam/cache.php';
require_once __DIR__ . '/../../lib/notam/map-aggregate-cache.php';
require_once __DIR__ . '/../../lib/notam/map-layer-cache.php';

/**
 * NOTAM TFR map layer (GeoJSON from map-airspace side-channel) unit tests.
 */
final class NotamMapLayerTest extends TestCase
{
    private string $cacheDir = '';

    protected function setUp(): void
    {
        $this->cacheDir = sys_get_temp_dir() . '/aviationwx-notam-map-' . bin2hex(random_bytes(4));
        if (!mkdir($this->cacheDir, 0755, true) && !is_dir($this->cacheDir)) {
            self::fail('Could not create NOTAM map test cache directory: ' . $this->cacheDir);
        }
        $GLOBALS['notamCacheTestDirectory'] = $this->cacheDir;
    }

    protected function tearDown(): void
    {
        unset($GLOBALS['notamCacheTestDirectory']);
        if ($this->cacheDir === '' || !is_dir($this->cacheDir)) {
            return;
        }

        foreach (scandir($this->cacheDir) ?: [] as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            $path = $this->cacheDir . '/' . $item;
            is_dir($path) ? self::removeTree($path) : @unlink($path);
        }
        @rmdir($this->cacheDir);
    }

    /**
     * @param array<string, array<string, mixed>> $recordsById
     */
    private function writeAirspaceAggregate(array $recordsById, int $mtime, ?string $buildToken = null): void
    {
        $path = getNotamMapAirspaceAggregatePath();
        $json = json_encode([
            'schema_version' => NOTAM_MAP_AIRSPACE_SCHEMA_VERSION,
            'records' => $recordsById,
            'updated_at' => $mtime,
            'map_layer_build_token' => $buildToken ?? notamTfrMapLayerCurrentBuildToken(),
        ], JSON_UNESCAPED_SLASHES);
        if ($json === false) {
            self::fail('Could not encode map-airspace test aggregate JSON');
        }
        if (file_put_contents($path, $json) === false) {
            self::fail('Could not write map-airspace test aggregate: ' . $path);
        }
        if (!touch($path, $mtime)) {
            self::fail('Could not set mtime on map-airspace test aggregate: ' . $path);
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function minimalListedAirportConfig(string $airportId = 's83'): array
    {
        return [
            'airports' => [
                $airportId => [
                    'enabled' => true,
                    'listed' => true,
                    'lat' => 47.52,
                    'lon' => -116.08,
                    'timezone' => 'UTC',
                ],
            ],
        ];
    }

    /**
     * @return array<int, array<string, mixed>> NOTAM rows for a drawable circle TFR
     */
    private function drawableTfrNotamRows(int $now, string $notamId = 'MAP1/2026'): array
    {
        return [
            [
                'id' => $notamId,
                'text' => $this->sampleTfrText(),
                'start_time_utc' => gmdate('Y-m-d\TH:i:s\Z', $now - 3600),
                'end_time_utc' => gmdate('Y-m-d\TH:i:s\Z', $now + 7200),
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function drawableTfrAirspaceRecord(int $now, string $notamId = 'MAP1/2026', string $airportId = 's83'): array
    {
        $notam = $this->drawableTfrNotamRows($now, $notamId)[0];
        $record = notamAirspaceRecordFromNotam($notam, $airportId, 'UTC');
        if ($record === null) {
            self::fail('Expected drawable airspace record for ' . $notamId);
        }

        return $record;
    }

    private function sampleTfrText(): string
    {
        return 'ZLC UT..AIRSPACE OGDEN, UT..TEMPORARY FLIGHT RESTRICTIONS '
            . 'WITHIN AN AREA DEFINED AS 5NM RADIUS OF 413900N1122300W (OGD319029) STATIC GROUND BASED ROCKET ENGINE TEST.';
    }

    private static function removeTree(string $dir): void
    {
        foreach (scandir($dir) ?: [] as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            $path = $dir . '/' . $item;
            is_dir($path) ? self::removeTree($path) : @unlink($path);
        }
        @rmdir($dir);
    }

    public function testNotamTfrMapLayerCurrentBuildToken_WithGitSha_IncludesLogicVersion(): void
    {
        $originalGitSha = getenv('GIT_SHA');
        putenv('GIT_SHA=abcdef12');
        try {
            $this->assertSame(
                'abcdef1-v' . NOTAM_TFR_MAP_LAYER_LOGIC_VERSION,
                notamTfrMapLayerCurrentBuildToken()
            );
        } finally {
            if ($originalGitSha === false) {
                putenv('GIT_SHA');
            } else {
                putenv('GIT_SHA=' . $originalGitSha);
            }
        }
    }

    public function testNotamMapAirspaceAggregateBuildTokenMatches_RejectsLegacyShaOnlyToken(): void
    {
        $originalGitSha = getenv('GIT_SHA');
        putenv('GIT_SHA=abcdef12');
        try {
            $legacy = [
                'schema_version' => NOTAM_MAP_AIRSPACE_SCHEMA_VERSION,
                'records' => [],
                'map_layer_build_token' => 'abcdef1',
            ];
            $this->assertFalse(notamMapAirspaceAggregateBuildTokenMatches($legacy));

            $legacy['map_layer_build_token'] = notamTfrMapLayerCurrentBuildToken();
            $this->assertTrue(notamMapAirspaceAggregateBuildTokenMatches($legacy));
        } finally {
            if ($originalGitSha === false) {
                putenv('GIT_SHA');
            } else {
                putenv('GIT_SHA=' . $originalGitSha);
            }
        }
    }

    public function testNotamMapAirspaceAggregateIsStale_MissingFile_ReturnsTrue(): void
    {
        $this->assertTrue(notamMapAirspaceAggregateIsStale(3600));
    }

    public function testNotamMapAirspaceAggregateIsStale_FreshFile_ReturnsFalse(): void
    {
        $now = time();
        $this->writeAirspaceAggregate([], $now);
        $this->assertFalse(notamMapAirspaceAggregateIsStale(3600, $now));
    }

    public function testNotamMapAirspaceAggregateIsStale_ExpiredTtl_ReturnsTrue(): void
    {
        $now = time();
        $this->writeAirspaceAggregate([], $now - 4000);
        $this->assertTrue(notamMapAirspaceAggregateIsStale(3600, $now));
    }

    public function testNotamAirspaceRecordFromNotam_SetsFieldSourcesForDrawableTfr(): void
    {
        $now = time();
        $record = $this->drawableTfrAirspaceRecord($now, 'SRC1/2026');

        $this->assertSame('tfr', $record['restriction_kind']);
        $this->assertTrue($record['capabilities']['map']);
        $this->assertArrayHasKey('geometry', $record['field_sources']);
        $this->assertSame(NOTAM_AIRSPACE_SOURCE_NMS, $record['field_sources']['geometry']);
        $this->assertSame(NOTAM_AIRSPACE_SOURCE_NMS, $record['field_sources']['notam_id']);
    }

    public function testNotamMapAirspaceAggregateUpsertFromFetch_WritesDrawableTfr(): void
    {
        $now = time();
        $config = $this->minimalListedAirportConfig();
        $airport = $config['airports']['s83'];

        notamMapAirspaceAggregateUpsertFromFetch('s83', $airport, $this->drawableTfrNotamRows($now, 'UPS1/2026'));

        $envelope = notamMapAirspaceAggregateRead();
        $this->assertNotNull($envelope);
        $this->assertArrayHasKey('UPS1/2026', $envelope['records']);
        $this->assertSame(notamTfrMapLayerCurrentBuildToken(), $envelope['map_layer_build_token'] ?? null);
    }

    public function testSideChannel_DrawableTfrNotAirportRelevant_AppearsOnMapNotInBannerFilter(): void
    {
        $now = time();
        $config = $this->minimalListedAirportConfig();
        $airport = $config['airports']['s83'];
        $notams = $this->drawableTfrNotamRows($now, 'FAR1/2026');

        notamMapAirspaceAggregateUpsertFromFetch('s83', $airport, $notams);

        $filtered = filterRelevantNotams($notams, $airport);
        $this->assertSame([], $filtered);

        $this->writeAirspaceAggregate(
            ['FAR1/2026' => $this->drawableTfrAirspaceRecord($now, 'FAR1/2026')],
            $now
        );

        $payload = notamTfrMapLayerServeOrRebuild();
        $this->assertCount(1, $payload['features']);
        $this->assertSame('FAR1/2026', $payload['features'][0]['properties']['notam_id'] ?? null);
    }

    public function testNotamTfrMapLayerServeOrRebuild_MissingStore_FailClosed(): void
    {
        $payload = notamTfrMapLayerServeOrRebuild();

        $this->assertSame([], $payload['features']);
        $this->assertTrue($payload['failclosed'] ?? false);
        $this->assertSame('faa_nms_side_channel', $payload['coverage_scope'] ?? null);
    }

    public function testNotamTfrMapLayerServeOrRebuild_StaleStore_FailClosed(): void
    {
        $now = time();
        $record = $this->drawableTfrAirspaceRecord($now, 'OLD1/2026');
        $this->writeAirspaceAggregate(['OLD1/2026' => $record], $now - 4000);

        $payload = notamTfrMapLayerServeOrRebuild();

        $this->assertSame([], $payload['features']);
        $this->assertTrue($payload['failclosed'] ?? false);
    }

    public function testNotamTfrMapLayerServeOrRebuild_StaleBuildToken_FailClosed(): void
    {
        $now = time();
        $record = $this->drawableTfrAirspaceRecord($now, 'TOK1/2026');
        $this->writeAirspaceAggregate(['TOK1/2026' => $record], $now, 'stale-token');

        $payload = notamTfrMapLayerServeOrRebuild();

        $this->assertSame([], $payload['features']);
        $this->assertTrue($payload['failclosed'] ?? false);
    }

    public function testNotamTfrMapLayerServeOrRebuild_FreshStore_ReturnsFeature(): void
    {
        $now = time();
        $record = $this->drawableTfrAirspaceRecord($now, 'LIVE1/2026');
        $this->writeAirspaceAggregate(['LIVE1/2026' => $record], $now);

        $payload = notamTfrMapLayerServeOrRebuild();

        $this->assertCount(1, $payload['features']);
        $this->assertSame('LIVE1/2026', $payload['features'][0]['properties']['notam_id'] ?? null);
        $this->assertSame('faa_nms_side_channel', $payload['coverage_scope'] ?? null);
        $this->assertArrayHasKey('coverage_note', $payload);
        $this->assertFalse($payload['failclosed'] ?? false);
    }

    public function testNotamTfrMapLayerBuildPayloadFromAirspaceStore_UsesCachedStatusWhenStartTimeMissing(): void
    {
        $now = time();
        $notam = [
            'id' => 'CACHE1/2026',
            'text' => $this->sampleTfrText(),
            'start_time_utc' => '',
            'end_time_utc' => gmdate('Y-m-d\TH:i:s\Z', $now + 7200),
            'status' => 'active',
        ];
        $record = notamAirspaceRecordFromNotam($notam, 's83', 'UTC');
        $this->assertNotNull($record);

        $envelope = [
            'schema_version' => NOTAM_MAP_AIRSPACE_SCHEMA_VERSION,
            'records' => ['CACHE1/2026' => $record],
            'map_layer_build_token' => notamTfrMapLayerCurrentBuildToken(),
        ];

        $payload = notamTfrMapLayerBuildPayloadFromAirspaceStore($envelope, $now);

        $this->assertCount(1, $payload['features']);
        $this->assertSame('active', $payload['features'][0]['properties']['status'] ?? null);
        $this->assertSame('active', $payload['features'][0]['properties']['map_layer_style'] ?? null);
    }

    public function testNotamTfrMapLayerBuildPayloadFromAirspaceStore_SkipsUnknownCachedStatusWhenStartTimeMissing(): void
    {
        $now = time();
        $notam = [
            'id' => 'CACHE2/2026',
            'text' => $this->sampleTfrText(),
            'start_time_utc' => '',
            'end_time_utc' => gmdate('Y-m-d\TH:i:s\Z', $now + 7200),
            'status' => 'unknown',
        ];
        $record = notamAirspaceRecordFromNotam($notam, 's83', 'UTC');
        $this->assertNotNull($record);

        $envelope = [
            'schema_version' => NOTAM_MAP_AIRSPACE_SCHEMA_VERSION,
            'records' => ['CACHE2/2026' => $record],
            'map_layer_build_token' => notamTfrMapLayerCurrentBuildToken(),
        ];

        $payload = notamTfrMapLayerBuildPayloadFromAirspaceStore($envelope, $now);

        $this->assertSame([], $payload['features']);
    }

    public function testNotamTfrMapLayerBuildPayloadFromAirspaceStore_DropsExpiredFeature(): void
    {
        $now = strtotime('2026-05-16T10:00:00Z');
        $notam = [
            'id' => 'T1/2026',
            'text' => $this->sampleTfrText(),
            'start_time_utc' => '2026-05-15T18:00:00Z',
            'end_time_utc' => '2026-05-15T22:00:00Z',
            'effective_segments' => [
                [
                    'start_time_utc' => '2026-05-15T18:00:00Z',
                    'end_time_utc' => '2026-05-15T22:00:00Z',
                ],
            ],
        ];
        $record = notamAirspaceRecordFromNotam($notam, 's83', 'UTC');
        $this->assertNotNull($record);

        $envelope = [
            'schema_version' => NOTAM_MAP_AIRSPACE_SCHEMA_VERSION,
            'records' => ['T1/2026' => $record],
            'map_layer_build_token' => notamTfrMapLayerCurrentBuildToken(),
        ];

        $payload = notamTfrMapLayerBuildPayloadFromAirspaceStore($envelope, $now);

        $this->assertSame([], $payload['features']);
    }

    public function testNotamTfrMapLayerBuildPayloadFromAirspaceStore_PromotesActiveOnDedup(): void
    {
        $now = strtotime('2026-05-15T20:00:00Z');
        $tfrText = $this->sampleTfrText();
        $segment = [
            'start_time_utc' => '2026-05-15T18:00:00Z',
            'end_time_utc' => '2026-05-15T22:00:00Z',
        ];
        $activeNotam = [
            'id' => 'A3389/2026',
            'text' => $tfrText,
            'start_time_utc' => '2026-05-15T18:00:00Z',
            'end_time_utc' => '2026-05-15T22:00:00Z',
            'effective_segments' => [$segment],
        ];
        $upcomingNotam = [
            'id' => '8821/2026',
            'text' => $tfrText,
            'start_time_utc' => '2026-05-16T18:00:00Z',
            'end_time_utc' => '2026-05-16T22:00:00Z',
            'effective_segments' => [
                [
                    'start_time_utc' => '2026-05-16T18:00:00Z',
                    'end_time_utc' => '2026-05-16T22:00:00Z',
                ],
            ],
        ];

        $envelope = [
            'schema_version' => NOTAM_MAP_AIRSPACE_SCHEMA_VERSION,
            'records' => [
                'A3389/2026' => notamAirspaceRecordFromNotam($activeNotam, 's83', 'UTC'),
                '8821/2026' => notamAirspaceRecordFromNotam($upcomingNotam, 's83', 'UTC'),
            ],
            'map_layer_build_token' => notamTfrMapLayerCurrentBuildToken(),
        ];

        $payload = notamTfrMapLayerBuildPayloadFromAirspaceStore($envelope, $now);

        $this->assertCount(1, $payload['features']);
        $this->assertSame('active', $payload['features'][0]['properties']['status']);
        $this->assertSame('A3389/2026', $payload['features'][0]['properties']['notam_id']);
    }

    public function testNotamTfrMapLayerFeatureFromAirspaceRecord_IncludesBannerHeadlineForFireTfr(): void
    {
        $now = time();
        $notam = [
            'id' => '8339/2026',
            'text' => 'ID..AIRSPACE 34NM SE COEUR D\'ALENE, ID..TEMPORARY FLIGHT RESTRICTIONS. '
                . 'PURSUANT TO 14 CFR SECTION 91.137(A)(2) WI AN AREA DEFINED AS 7NM RADIUS OF '
                . '473130N1160445W SFC-7500FT GOLD RUN FIRE',
            'start_time_utc' => gmdate('Y-m-d\TH:i:s\Z', $now - 3600),
            'end_time_utc' => gmdate('Y-m-d\TH:i:s\Z', $now + 7200),
        ];
        $record = notamAirspaceRecordFromNotam($notam, 's83', 'UTC');
        $this->assertNotNull($record);

        $feature = notamTfrMapLayerFeatureFromAirspaceRecord($record, $now);
        $this->assertNotNull($feature);
        $headline = $feature['properties']['banner_headline'] ?? '';
        $this->assertStringContainsString('Fire TFR', $headline);
        $this->assertStringContainsString('7 NM radius', $headline);
        $this->assertStringContainsString('SFC', $headline);
    }

    public function testNotamTfrMapLayerGeoJsonRingFromVertices_ClosedSquare_ReturnsClosedRing(): void
    {
        $vertices = [
            ['lat' => 45.0, 'lon' => -122.0],
            ['lat' => 45.0, 'lon' => -123.0],
            ['lat' => 46.0, 'lon' => -123.0],
            ['lat' => 46.0, 'lon' => -122.0],
        ];
        $ring = notamTfrMapLayerGeoJsonRingFromVertices($vertices, true);
        $this->assertNotNull($ring);
        $this->assertGreaterThanOrEqual(4, count($ring));
        $first = $ring[0];
        $last = $ring[count($ring) - 1];
        $this->assertEqualsWithDelta($first[0], $last[0], 1e-9);
        $this->assertEqualsWithDelta($first[1], $last[1], 1e-9);
    }

    public function testNotamTfrMapLayerGeoJsonRingFromCircle_DefaultSegments_ReturnsClosedRing(): void
    {
        $ring = notamTfrMapLayerGeoJsonRingFromCircle(45.0, -122.0, 5.0);
        $this->assertCount(NOTAM_TFR_MAP_CIRCLE_SEGMENTS + 1, $ring);
        $this->assertEqualsWithDelta($ring[0][0], $ring[count($ring) - 1][0], 1e-9);
        $this->assertEqualsWithDelta($ring[0][1], $ring[count($ring) - 1][1], 1e-9);
    }

    public function testNotamTfrMapLayerStyleBucket_VariousStatuses_MapsToActiveOrUpcoming(): void
    {
        $this->assertSame('active', notamTfrMapLayerStyleBucket('active'));
        $this->assertSame('upcoming', notamTfrMapLayerStyleBucket('inactive_scheduled'));
        $this->assertSame('upcoming', notamTfrMapLayerStyleBucket('upcoming_today'));
    }

    public function testNotamTfrMapLayerTooltipStatusLine_ActiveSegment_ReturnsActiveUntilLine(): void
    {
        $notam = [
            'id' => 'T1',
            'text' => '',
            'effective_segments' => [
                ['start_time_utc' => '2026-05-15T18:00:00Z', 'end_time_utc' => '2026-05-15T22:00:00Z'],
            ],
        ];
        $now = strtotime('2026-05-15T20:00:00Z');
        $line = notamTfrMapLayerTooltipStatusLine($notam, 'active', 'UTC', $now);
        $this->assertNotNull($line);
        $this->assertStringStartsWith('Active now until', $line);
        $this->assertStringContainsString('10:00 PM', $line);
        $this->assertStringContainsString('May 15, 2026', $line);
    }

    public function testNotamTfrMapLayerTooltipStatusLine_UpcomingFirstWindow_ReturnsUpcomingFromLine(): void
    {
        $notam = [
            'id' => 'T1',
            'text' => '',
            'effective_segments' => [
                ['start_time_utc' => '2026-05-15T18:00:00Z', 'end_time_utc' => '2026-05-15T22:00:00Z'],
            ],
        ];
        $now = strtotime('2026-05-15T10:00:00Z');
        $line = notamTfrMapLayerTooltipStatusLine($notam, 'upcoming_future', 'UTC', $now);
        $this->assertNotNull($line);
        $this->assertStringStartsWith('Upcoming from', $line);
        $this->assertStringContainsString(' to ', $line);
    }

    public function testNotamTfrMapLayerTooltipStatusLine_InactiveScheduledGap_ReturnsUpcomingFromLine(): void
    {
        $notam = [
            'id' => 'T1',
            'text' => '',
            'effective_segments' => [
                ['start_time_utc' => '2026-05-15T18:00:00Z', 'end_time_utc' => '2026-05-15T20:00:00Z'],
                ['start_time_utc' => '2026-05-15T22:00:00Z', 'end_time_utc' => '2026-05-16T02:00:00Z'],
            ],
        ];
        $now = strtotime('2026-05-15T21:00:00Z');
        $line = notamTfrMapLayerTooltipStatusLine($notam, 'inactive_scheduled', 'UTC', $now);
        $this->assertNotNull($line);
        $this->assertStringStartsWith('Upcoming from', $line);
        $this->assertStringContainsString('10:00 PM', $line);
    }

    public function testNotamTfrMapLayerTooltipStatusLine_EnvelopeOnlyActive_ReturnsActiveUntilLine(): void
    {
        $notam = [
            'id' => 'T2',
            'text' => '',
            'start_time_utc' => '2026-05-15T12:00:00Z',
            'end_time_utc' => '2026-05-15T23:59:59Z',
        ];
        $now = strtotime('2026-05-15T15:00:00Z');
        $line = notamTfrMapLayerTooltipStatusLine($notam, 'active', 'UTC', $now);
        $this->assertNotNull($line);
        $this->assertStringStartsWith('Active now until', $line);
    }

    public function testNotamTfrMapLayerDeduplicateFeaturesByGeometry_ActiveAndUpcomingCircle_KeepsActive(): void
    {
        $active = [
            'type' => 'Feature',
            'geometry' => ['type' => 'Point', 'coordinates' => [-116.08, 47.52]],
            'properties' => [
                'geometry_kind' => 'circle',
                'radius_nm' => 7.0,
                'status' => 'active',
                'map_layer_style' => 'active',
                'notam_id' => 'A3389/2026',
            ],
        ];
        $upcoming = [
            'type' => 'Feature',
            'geometry' => ['type' => 'Point', 'coordinates' => [-116.08, 47.52]],
            'properties' => [
                'geometry_kind' => 'circle',
                'radius_nm' => 7.0,
                'status' => 'upcoming_future',
                'map_layer_style' => 'upcoming',
                'notam_id' => '8821/2026',
            ],
        ];

        $deduped = notamTfrMapLayerDeduplicateFeaturesByGeometry([$upcoming, $active]);

        $this->assertCount(1, $deduped);
        $this->assertSame('active', $deduped[0]['properties']['status']);
        $this->assertSame('A3389/2026', $deduped[0]['properties']['notam_id']);
    }

    public function testNotamTfrMapLayerDeduplicateFeaturesByGeometry_ScheduledGapAndUpcomingCircle_KeepsScheduledGap(): void
    {
        $scheduled = [
            'type' => 'Feature',
            'geometry' => ['type' => 'Point', 'coordinates' => [-116.08, 47.52]],
            'properties' => [
                'geometry_kind' => 'circle',
                'radius_nm' => 7.0,
                'status' => 'inactive_scheduled',
                'notam_id' => 'A1001/2026',
            ],
        ];
        $upcoming = [
            'type' => 'Feature',
            'geometry' => ['type' => 'Point', 'coordinates' => [-116.08, 47.52]],
            'properties' => [
                'geometry_kind' => 'circle',
                'radius_nm' => 7.0,
                'status' => 'upcoming_future',
                'notam_id' => 'A1002/2026',
            ],
        ];

        $deduped = notamTfrMapLayerDeduplicateFeaturesByGeometry([$upcoming, $scheduled]);

        $this->assertCount(1, $deduped);
        $this->assertSame('inactive_scheduled', $deduped[0]['properties']['status']);
    }

    public function testNotamTfrMapLayerDeduplicateFeaturesByGeometry_DistinctCircles_KeepsBoth(): void
    {
        $a = [
            'type' => 'Feature',
            'geometry' => ['type' => 'Point', 'coordinates' => [-116.08, 47.52]],
            'properties' => [
                'geometry_kind' => 'circle',
                'radius_nm' => 7.0,
                'status' => 'upcoming_today',
                'notam_id' => 'A1',
            ],
        ];
        $b = [
            'type' => 'Feature',
            'geometry' => ['type' => 'Point', 'coordinates' => [-117.08, 48.52]],
            'properties' => [
                'geometry_kind' => 'circle',
                'radius_nm' => 7.0,
                'status' => 'upcoming_today',
                'notam_id' => 'A2',
            ],
        ];

        $deduped = notamTfrMapLayerDeduplicateFeaturesByGeometry([$a, $b]);

        $this->assertCount(2, $deduped);
    }
}
