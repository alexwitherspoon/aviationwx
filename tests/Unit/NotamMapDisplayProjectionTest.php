<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../lib/notam/map-layer.php';
require_once __DIR__ . '/../../lib/notam/map-display-projection.php';

/**
 * Map-ready TFR display projection (#270).
 */
final class NotamMapDisplayProjectionTest extends TestCase
{
    /**
     * @return array<string, mixed>
     */
    private function polygonFeature(
        string $notamId,
        array $ring,
        string $headline,
        string $status = 'active',
        string $restrictionKind = 'tfr'
    ): array {
        $closed = $ring;
        $first = $ring[0];
        $last = $ring[count($ring) - 1];
        if ($first[0] !== $last[0] || $first[1] !== $last[1]) {
            $closed[] = $first;
        }

        return [
            'type' => 'Feature',
            'id' => 'tfr-' . $notamId,
            'geometry' => [
                'type' => 'Polygon',
                'coordinates' => [$closed],
            ],
            'properties' => [
                'notam_id' => $notamId,
                'status' => $status,
                'map_layer_style' => $status === 'active' ? 'active' : 'upcoming',
                'geometry_kind' => 'polygon',
                'restriction_kind' => $restrictionKind,
                'banner_headline' => $headline,
                'official_link' => 'https://notams.aim.faa.gov/notamSearch/search?notamNumber=' . rawurlencode($notamId),
                'official_link_label' => 'Details on FAA Notam Search',
            ],
        ];
    }

    public function testVerticalKey_ParsesHeadlineBand(): void
    {
        $feature = $this->polygonFeature(
            '1/2026',
            [[0.0, 0.0], [1.0, 0.0], [1.0, 1.0], [0.0, 1.0]],
            'Fire TFR - polygon area - SFC - 9000 ft'
        );
        $this->assertSame('SFC-9000', notamTfrMapLayerDisplayVerticalKey($feature));
    }

    public function testVerticalKey_IncludesDatumSuffix(): void
    {
        $agl = $this->polygonFeature(
            '2/2026',
            [[0.0, 0.0], [1.0, 0.0], [1.0, 1.0], [0.0, 1.0]],
            'Fire TFR - polygon area - SFC - 5000 ft AGL'
        );
        $msl = $this->polygonFeature(
            '3/2026',
            [[0.0, 0.0], [1.0, 0.0], [1.0, 1.0], [0.0, 1.0]],
            'Fire TFR - polygon area - SFC - 5000 ft MSL'
        );
        $this->assertSame('SFC-5000-AGL', notamTfrMapLayerDisplayVerticalKey($agl));
        $this->assertSame('SFC-5000-MSL', notamTfrMapLayerDisplayVerticalKey($msl));
        $this->assertNotSame(
            notamTfrMapLayerDisplayVerticalKey($agl),
            notamTfrMapLayerDisplayVerticalKey($msl)
        );
    }

    public function testProjectDisplayFeatures_DoesNotMergeAglVsMslSameAltitude(): void
    {
        $a = $this->polygonFeature(
            '2000/2026',
            [[-117.5, 44.2], [-117.0, 44.2], [-117.0, 44.8], [-117.5, 44.8]],
            'Fire TFR - polygon area - SFC - 5000 ft AGL'
        );
        $b = $this->polygonFeature(
            '2001/2026',
            [[-117.5, 44.2], [-117.0, 44.2], [-117.0, 44.8], [-117.5, 44.8]],
            'Fire TFR - polygon area - SFC - 5000 ft MSL'
        );

        $out = notamTfrMapLayerProjectDisplayFeatures([$a, $b]);
        $this->assertCount(2, $out);
        foreach ($out as $feature) {
            $this->assertFalse(($feature['properties']['display_merged'] ?? false));
        }
    }

    public function testProjectDisplayFeatures_MergesOverlappingSameVertical(): void
    {
        $a = $this->polygonFeature(
            '8885/2026',
            [[-117.5, 44.2], [-117.0, 44.2], [-117.0, 44.8], [-117.5, 44.8]],
            'Fire TFR - polygon area - SFC - 11500 ft'
        );
        $b = $this->polygonFeature(
            '9227/2026',
            [[-117.5, 44.2], [-117.05, 44.2], [-117.05, 44.8], [-117.5, 44.8]],
            'Fire TFR - polygon area - SFC - 11500 ft'
        );

        $out = notamTfrMapLayerProjectDisplayFeatures([$a, $b]);
        $this->assertCount(1, $out);
        $props = $out[0]['properties'];
        $this->assertTrue($props['display_merged']);
        $this->assertSame(2, $props['member_count']);
        $this->assertSame(['9227/2026', '8885/2026'], $props['member_notam_ids']);
        $this->assertSame('Polygon', $out[0]['geometry']['type']);
        $this->assertStringContainsString('overlapping NOTAMs', (string) $props['banner_headline']);
    }

    public function testProjectDisplayFeatures_DoesNotMergeDifferentVertical(): void
    {
        $a = $this->polygonFeature(
            '1000/2026',
            [[-117.5, 44.2], [-117.0, 44.2], [-117.0, 44.8], [-117.5, 44.8]],
            'Fire TFR - polygon area - SFC - 9000 ft'
        );
        $b = $this->polygonFeature(
            '1001/2026',
            [[-117.5, 44.2], [-117.0, 44.2], [-117.0, 44.8], [-117.5, 44.8]],
            'Fire TFR - polygon area - SFC - 11500 ft'
        );

        $out = notamTfrMapLayerProjectDisplayFeatures([$a, $b]);
        $this->assertCount(2, $out);
        foreach ($out as $feature) {
            $this->assertArrayNotHasKey('display_merged', $feature['properties']);
        }
    }

    public function testProjectDisplayFeatures_LeavesDistantSameVerticalSeparate(): void
    {
        $a = $this->polygonFeature(
            '2000/2026',
            [[-120.0, 44.0], [-119.5, 44.0], [-119.5, 44.5], [-120.0, 44.5]],
            'Fire TFR - polygon area - SFC - 9000 ft'
        );
        $b = $this->polygonFeature(
            '2001/2026',
            [[-117.0, 44.0], [-116.5, 44.0], [-116.5, 44.5], [-117.0, 44.5]],
            'Fire TFR - polygon area - SFC - 9000 ft'
        );

        $out = notamTfrMapLayerProjectDisplayFeatures([$a, $b]);
        $this->assertCount(2, $out);
    }

    /**
     * @return array<string, mixed>
     */
    private function circleFeature(
        string $notamId,
        float $lon,
        float $lat,
        float $radiusNm,
        string $headline,
        string $status = 'active'
    ): array {
        return [
            'type' => 'Feature',
            'id' => 'tfr-' . $notamId,
            'geometry' => [
                'type' => 'Point',
                'coordinates' => [$lon, $lat],
            ],
            'properties' => [
                'notam_id' => $notamId,
                'status' => $status,
                'map_layer_style' => $status === 'active' ? 'active' : 'upcoming',
                'geometry_kind' => 'circle',
                'restriction_kind' => 'tfr',
                'banner_headline' => $headline,
                'radius_nm' => $radiusNm,
                'radius_m' => $radiusNm * 1852.0,
                'official_link' => 'https://notams.aim.faa.gov/notamSearch/search?notamNumber=' . rawurlencode($notamId),
                'official_link_label' => 'Details on FAA Notam Search',
            ],
        ];
    }

    public function testProjectDisplayFeatures_MergesConcentricCirclesSameVertical(): void
    {
        $outer = $this->circleFeature(
            '8495/2026',
            -115.73333333333333,
            45.358333333333334,
            7.0,
            'Daily fire TFR - 7 NM radius - SFC - 12000 ft'
        );
        $inner = $this->circleFeature(
            '8874/2026',
            -115.73333333333333,
            45.35,
            4.0,
            'Daily fire TFR - 4 NM radius - SFC - 12000 ft'
        );

        $out = notamTfrMapLayerProjectDisplayFeatures([$outer, $inner]);
        $this->assertCount(1, $out);
        $props = $out[0]['properties'];
        $this->assertTrue($props['display_merged']);
        $this->assertSame('circle', $props['geometry_kind']);
        $this->assertSame(2, $props['member_count']);
        $this->assertGreaterThanOrEqual(7.0, (float) $props['radius_nm']);
        $this->assertStringContainsString('7 NM', (string) $props['banner_headline']);
        $this->assertStringContainsString('overlapping NOTAMs', (string) $props['banner_headline']);
    }

    public function testProjectDisplayFeatures_MergesOverlappingCircleAndPolygon(): void
    {
        $circle = $this->circleFeature(
            '8654/2026',
            -117.2,
            44.5,
            14.0,
            'Fire TFR - 14 NM radius - SFC - 11500 ft'
        );
        // Nested well inside the 14 NM circle bbox.
        $poly = $this->polygonFeature(
            '9532/2026',
            [[-117.25, 44.45], [-117.15, 44.45], [-117.15, 44.55], [-117.25, 44.55]],
            'Fire TFR - polygon area - SFC - 11500 ft'
        );

        $out = notamTfrMapLayerProjectDisplayFeatures([$circle, $poly]);
        $this->assertCount(1, $out);
        $props = $out[0]['properties'];
        $this->assertTrue($props['display_merged']);
        $this->assertSame('circle', $props['geometry_kind']);
        $this->assertSame('Point', $out[0]['geometry']['type']);
        $this->assertSame(2, $props['member_count']);
        $this->assertGreaterThanOrEqual(14.0, (float) $props['radius_nm']);
        $this->assertStringContainsString('14 NM', (string) $props['banner_headline']);
    }

    public function testProjectDisplayFeatures_LeavesLaterallyOverlappingCirclesSeparate(): void
    {
        // Mount Hood-style peers: same vertical band, overlap, neither contains the other.
        $a = $this->circleFeature(
            '4000/2026',
            -121.70,
            45.30,
            5.0,
            'Fire TFR - 5 NM radius - SFC - 10000 ft'
        );
        $b = $this->circleFeature(
            '4001/2026',
            -121.62,
            45.30,
            5.0,
            'Fire TFR - 5 NM radius - SFC - 10000 ft'
        );
        $c = $this->circleFeature(
            '4002/2026',
            -121.66,
            45.36,
            3.0,
            'Fire TFR - 3 NM radius - SFC - 10000 ft'
        );

        $out = notamTfrMapLayerProjectDisplayFeatures([$a, $b, $c]);
        $this->assertCount(3, $out);
        foreach ($out as $feature) {
            $this->assertSame('circle', $feature['properties']['geometry_kind']);
            $this->assertArrayNotHasKey('display_merged', $feature['properties']);
        }
    }

    public function testProjectDisplayFeatures_RewritesRadiusPolygonRingToCircle(): void
    {
        $centerLon = -121.34;
        $centerLat = 45.25;
        $radiusNm = 10.0;
        $ring = [];
        $dLat = $radiusNm / 60.0;
        $dLon = $radiusNm / (60.0 * cos(deg2rad($centerLat)));
        for ($i = 0; $i < 36; $i++) {
            $theta = (2.0 * M_PI * $i) / 36.0;
            $ring[] = [
                $centerLon + ($dLon * cos($theta)),
                $centerLat + ($dLat * sin($theta)),
            ];
        }

        $feature = $this->polygonFeature(
            '8888/2026',
            $ring,
            'Fire TFR - 10 NM radius - SFC - 10500 ft'
        );

        $out = notamTfrMapLayerProjectDisplayFeatures([$feature]);
        $this->assertCount(1, $out);
        $this->assertSame('Point', $out[0]['geometry']['type']);
        $props = $out[0]['properties'];
        $this->assertSame('circle', $props['geometry_kind']);
        $this->assertEqualsWithDelta(10.0, (float) $props['radius_nm'], 0.01);
        $this->assertEqualsWithDelta($centerLon, (float) $out[0]['geometry']['coordinates'][0], 0.02);
        $this->assertEqualsWithDelta($centerLat, (float) $out[0]['geometry']['coordinates'][1], 0.02);
    }

    public function testProjectDisplayFeatures_RewritesNearCircularPolygonWithoutNmHeadline(): void
    {
        // Disney-style standing TFR: WFS polygon ring, map title has no "NM radius".
        $centerLon = -81.58;
        $centerLat = 28.38;
        $radiusNm = 3.0;
        $ring = [];
        $dLat = $radiusNm / 60.0;
        $dLon = $radiusNm / (60.0 * cos(deg2rad($centerLat)));
        for ($i = 0; $i < 36; $i++) {
            $theta = (2.0 * M_PI * $i) / 36.0;
            $ring[] = [
                $centerLon + ($dLon * cos($theta)),
                $centerLat + ($dLat * sin($theta)),
            ];
        }

        $feature = $this->polygonFeature(
            '4/3634',
            $ring,
            'Security restriction: DISNEY WORLD THEME PARK, ORLANDO, FL',
            'active',
            'security'
        );

        $out = notamTfrMapLayerProjectDisplayFeatures([$feature]);
        $this->assertCount(1, $out);
        $this->assertSame('Point', $out[0]['geometry']['type']);
        $props = $out[0]['properties'];
        $this->assertSame('circle', $props['geometry_kind']);
        $this->assertEqualsWithDelta(3.0, (float) $props['radius_nm'], 0.05);
        $this->assertEqualsWithDelta($centerLon, (float) $out[0]['geometry']['coordinates'][0], 0.02);
        $this->assertEqualsWithDelta($centerLat, (float) $out[0]['geometry']['coordinates'][1], 0.02);
    }

    public function testProjectDisplayFeatures_LeavesIrregularPolygonAsPolygon(): void
    {
        $feature = $this->polygonFeature(
            '5000/2026',
            [[-117.5, 44.2], [-117.0, 44.2], [-117.2, 44.9], [-117.5, 44.2]],
            'Fire TFR - polygon area - SFC - 9000 ft'
        );

        $out = notamTfrMapLayerProjectDisplayFeatures([$feature]);
        $this->assertCount(1, $out);
        $this->assertSame('Polygon', $out[0]['geometry']['type']);
        $this->assertSame('polygon', $out[0]['properties']['geometry_kind']);
        $this->assertArrayNotHasKey('radius_nm', $out[0]['properties']);
    }

    public function testProjectDisplayFeatures_LeavesDistantCircleAndPolygonSeparate(): void
    {
        $circle = $this->circleFeature(
            '3000/2026',
            -121.0,
            45.0,
            5.0,
            'Fire TFR - 5 NM radius - SFC - 9000 ft'
        );
        $poly = $this->polygonFeature(
            '3001/2026',
            [[-117.1, 44.9], [-116.9, 44.9], [-116.9, 45.1], [-117.1, 45.1]],
            'Fire TFR - polygon area - SFC - 9000 ft'
        );

        $out = notamTfrMapLayerProjectDisplayFeatures([$circle, $poly]);
        $this->assertCount(2, $out);
        foreach ($out as $feature) {
            $this->assertArrayNotHasKey('display_merged', $feature['properties']);
        }
    }

    public function testBuildPayload_DisplayProjectionDoesNotRewriteStore(): void
    {
        $now = time();
        $ringA = [[-117.5, 44.2], [-117.0, 44.2], [-117.0, 44.8], [-117.5, 44.8], [-117.5, 44.2]];
        $ringB = [[-117.5, 44.2], [-117.05, 44.2], [-117.05, 44.8], [-117.5, 44.8], [-117.5, 44.2]];
        $makeRecord = static function (string $id, array $ring) use ($now): array {
            return [
                'notam_id' => $id,
                'norm_number' => 'N:' . explode('/', $id)[0],
                'restriction_kind' => 'tfr',
                'geometry' => ['type' => 'Polygon', 'coordinates' => [$ring]],
                'geometry_kind' => 'polygon',
                'timezone' => 'UTC',
                'source_airport_id' => 'bke',
                'capabilities' => ['map' => true, 'banner' => true, 'runway_closure' => false],
                'record_sources' => ['nms'],
                'field_sources' => ['geometry' => 'nms', 'notam_id' => 'nms', 'text' => 'nms'],
                'notam' => [
                    'id' => $id,
                    'text' => 'OR..AIRSPACE TEMPORARY FLIGHT RESTRICTIONS SFC-11500FT '
                        . 'WI AN AREA DEFINED AS 444830N1172500W TO 444400N1170400W '
                        . 'TO 443330N1171030W TO 444830N1172500W TO POINT OF ORIGIN',
                    'start_time_utc' => gmdate('Y-m-d\TH:i:s\Z', $now - 3600),
                    'end_time_utc' => gmdate('Y-m-d\TH:i:s\Z', $now + 86400),
                    'status' => 'active',
                ],
            ];
        };

        $envelope = [
            'schema_version' => NOTAM_MAP_AIRSPACE_SCHEMA_VERSION,
            'merge_logic_version' => NOTAM_AIRSPACE_MERGE_LOGIC_VERSION,
            'data_updated_at' => $now,
            'updated_at' => $now,
            'coverage_sources' => ['nms'],
            'source_status' => ['nms' => ['ok' => true, 'updated_at' => $now]],
            'records' => [
                'N:8885' => $makeRecord('8885/2026', $ringA),
                'N:9227' => $makeRecord('9227/2026', $ringB),
            ],
        ];

        $payload = notamTfrMapLayerBuildPayloadFromAirspaceStore($envelope, $now);
        $this->assertArrayHasKey('display_projection_version', $payload);
        $this->assertSame(NOTAM_TFR_MAP_DISPLAY_PROJECTION_VERSION, $payload['display_projection_version']);
        $this->assertLessThanOrEqual(2, count($payload['features']));
        // Store envelope passed in is unchanged (projection is response-only).
        $this->assertCount(2, $envelope['records']);
        $this->assertArrayHasKey('N:8885', $envelope['records']);
        $this->assertArrayHasKey('N:9227', $envelope['records']);
    }

    public function testProjectDisplayFeatures_ExplodesMultiPolygonAndRewritesDenseArcRing(): void
    {
        $centerLon = -81.42444444444445;
        $centerLat = 30.391388888888887;
        $radiusNm = 4.2;
        $ring = [];
        $dLat = $radiusNm / 60.0;
        $dLon = $radiusNm / (60.0 * cos(deg2rad($centerLat)));
        for ($i = 0; $i < 48; $i++) {
            $theta = (2.0 * M_PI * $i) / 48.0;
            // Slight radial noise like WFS arc approximations.
            $jitter = 1.0 + ((($i % 5) - 2) * 0.03);
            $ring[] = [
                $centerLon + ($dLon * cos($theta) * $jitter),
                $centerLat + ($dLat * sin($theta) * $jitter),
            ];
        }
        $tail = [[-81.58, 30.30], [-81.50, 30.30], [-81.50, 30.34], [-81.58, 30.34], [-81.58, 30.30]];

        $feature = [
            'type' => 'Feature',
            'id' => 'tfr-8418-2026',
            'geometry' => [
                'type' => 'MultiPolygon',
                'coordinates' => [[$ring], [$tail]],
            ],
            'properties' => [
                'notam_id' => '8418/2026',
                'status' => 'active',
                'map_layer_style' => 'active',
                'geometry_kind' => 'multipolygon',
                'restriction_kind' => 'security',
                'banner_headline' => 'Security TFR - polygon area - SFC - 2500 ft',
                'arc_hints' => [[
                    'lon' => $centerLon,
                    'lat' => $centerLat,
                    'radius_nm' => $radiusNm,
                ]],
            ],
        ];

        $out = notamTfrMapLayerProjectDisplayFeatures([$feature]);
        $this->assertCount(2, $out);
        $kinds = array_map(
            static fn (array $f): string => (string) ($f['properties']['geometry_kind'] ?? ''),
            $out
        );
        $this->assertContains('circle', $kinds);
        $this->assertContains('polygon', $kinds);
        $circle = null;
        foreach ($out as $f) {
            if (($f['properties']['geometry_kind'] ?? null) === 'circle') {
                $circle = $f;
                break;
            }
        }
        $this->assertNotNull($circle);
        $this->assertSame('Point', $circle['geometry']['type']);
        $this->assertEqualsWithDelta(4.2, (float) $circle['properties']['radius_nm'], 0.01);
        $this->assertEqualsWithDelta($centerLon, (float) $circle['geometry']['coordinates'][0], 0.01);
        $this->assertEqualsWithDelta($centerLat, (float) $circle['geometry']['coordinates'][1], 0.01);
    }

    public function testArcHintsFromText_ParsesNmArcCenteredOn(): void
    {
        $text = 'WI AN AREA DEFINED AS 301918N0812606W THEN COUNTERCLOCKWISE ON A '
            . '4.2 NM ARC CENTERED ON 302329N0812528W (CRG058005) TO THE POINT OF ORIGIN SFC-2500FT';
        $hints = notamTfrMapLayerArcHintsFromText($text);
        $this->assertCount(1, $hints);
        $this->assertEqualsWithDelta(4.2, $hints[0]['radius_nm'], 0.001);
        $this->assertEqualsWithDelta(-81.42444444444445, $hints[0]['lon'], 0.0001);
        $this->assertEqualsWithDelta(30.391388888888887, $hints[0]['lat'], 0.0001);
    }
}
