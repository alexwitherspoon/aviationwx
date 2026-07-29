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

    public function testProjectDisplayFeatures_PassesCirclesThrough(): void
    {
        $circle = [
            'type' => 'Feature',
            'id' => 'tfr-circle',
            'geometry' => [
                'type' => 'Point',
                'coordinates' => [-121.0, 45.0],
            ],
            'properties' => [
                'notam_id' => '3000/2026',
                'status' => 'active',
                'map_layer_style' => 'active',
                'geometry_kind' => 'circle',
                'restriction_kind' => 'tfr',
                'banner_headline' => 'Fire TFR - 5 NM radius - SFC - 9000 ft',
                'radius_nm' => 5.0,
                'radius_m' => 5.0 * 1852.0,
            ],
        ];
        $poly = $this->polygonFeature(
            '3001/2026',
            [[-121.1, 44.9], [-120.9, 44.9], [-120.9, 45.1], [-121.1, 45.1]],
            'Fire TFR - polygon area - SFC - 9000 ft'
        );

        $out = notamTfrMapLayerProjectDisplayFeatures([$circle, $poly]);
        $this->assertCount(2, $out);
        $kinds = array_map(
            static fn(array $f): string => (string) ($f['properties']['geometry_kind'] ?? ''),
            $out
        );
        sort($kinds);
        $this->assertSame(['circle', 'polygon'], $kinds);
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
}
