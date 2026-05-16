<?php
/**
 * NOTAM TFR map layer (GeoJSON aggregation) unit tests.
 */

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../lib/notam/map-layer.php';

class NotamMapLayerTest extends TestCase {
    public function testGeoJsonRingFromVertices_ClosedSquare(): void {
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

    public function testGeoJsonRingFromCircle_Closed(): void {
        $ring = notamTfrMapLayerGeoJsonRingFromCircle(45.0, -122.0, 5.0);
        $this->assertCount(NOTAM_TFR_MAP_CIRCLE_SEGMENTS + 1, $ring);
        $this->assertEqualsWithDelta($ring[0][0], $ring[count($ring) - 1][0], 1e-9);
        $this->assertEqualsWithDelta($ring[0][1], $ring[count($ring) - 1][1], 1e-9);
    }

    public function testMapLayerStyleBucket(): void {
        $this->assertSame('active', notamTfrMapLayerStyleBucket('active'));
        $this->assertSame('upcoming', notamTfrMapLayerStyleBucket('inactive_scheduled'));
        $this->assertSame('upcoming', notamTfrMapLayerStyleBucket('upcoming_today'));
    }

    public function testTooltipStatusLine_ActiveUntilSegmentEnd(): void {
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

    public function testTooltipStatusLine_UpcomingFirstWindow(): void {
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

    public function testTooltipStatusLine_InactiveScheduledNextWindow(): void {
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

    public function testTooltipStatusLine_EnvelopeOnlyActive(): void {
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
}
