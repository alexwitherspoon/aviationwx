<?php
/**
 * Unit Tests for Runway Segment Loading and Processing
 *
 * Tests manual override, programmatic loading from fixture, ident resolution,
 * and cache staleness logic.
 */

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../lib/runways.php';

class RunwaysTest extends TestCase
{
    /**
     * Test manual runway config converts to segments with correct structure
     */
    public function testManualRunwaysToSegments_ReturnsCorrectFormat(): void
    {
        $runways = [
            ['name' => '16/34', 'heading_1' => 160, 'heading_2' => 340],
        ];
        $segments = manualRunwaysToSegments($runways);
        $this->assertCount(1, $segments);
        $seg = $segments[0];
        $this->assertArrayHasKey('start', $seg);
        $this->assertArrayHasKey('end', $seg);
        $this->assertArrayHasKey('le_ident', $seg);
        $this->assertArrayHasKey('he_ident', $seg);
        $this->assertArrayHasKey('source', $seg);
        $this->assertSame('manual', $seg['source']);
        $this->assertSame('16', $seg['le_ident']);
        $this->assertSame('34', $seg['he_ident']);
        $this->assertCount(2, $seg['start']);
        $this->assertCount(2, $seg['end']);
    }

    /**
     * Test manual runways with L/R designations
     */
    public function testManualRunwaysToSegments_ParsesDesignations(): void
    {
        $runways = [
            ['name' => '28L/10R', 'heading_1' => 280, 'heading_2' => 100],
        ];
        $segments = manualRunwaysToSegments($runways);
        $this->assertSame('28L', $segments[0]['le_ident']);
        $this->assertSame('10R', $segments[0]['he_ident']);
    }

    /**
     * Test manual runways skip entries missing headings
     */
    public function testManualRunwaysToSegments_SkipsInvalidEntries(): void
    {
        $runways = [
            ['name' => '16/34', 'heading_1' => 160, 'heading_2' => 340],
            ['name' => 'bad', 'heading_1' => null, 'heading_2' => 340],
            ['name' => 'bad2', 'heading_1' => 90],
        ];
        $segments = manualRunwaysToSegments($runways);
        $this->assertCount(1, $segments);
    }

    /**
     * Test getRunwaySegmentsForAirport uses manual override when present
     */
    public function testGetRunwaySegmentsForAirport_ManualOverrideTakesPrecedence(): void
    {
        $airport = [
            'lat' => 45.0,
            'lon' => -122.0,
            'runways' => [
                ['name' => '15/33', 'heading_1' => 150, 'heading_2' => 330],
            ],
        ];
        $segments = getRunwaySegmentsForAirport('kspb', $airport);
        $this->assertNotNull($segments);
        $this->assertCount(1, $segments);
        $this->assertSame('manual', $segments[0]['source']);
        $this->assertSame('15', $segments[0]['le_ident']);
        $this->assertSame('33', $segments[0]['he_ident']);
    }

    /**
     * Test loadRunwaySegmentsFromFileCache loads from fixture in test mode
     */
    public function testLoadRunwaySegmentsFromFileCache_LoadsFromFixtureInTestMode(): void
    {
        $airport = ['lat' => 45.7710278, 'lon' => -122.8618333, 'icao' => 'KSPB'];
        $segments = loadRunwaySegmentsFromFileCache('kspb', $airport);
        $this->assertNotNull($segments);
        $this->assertNotEmpty($segments);
        $this->assertSame('programmatic', $segments[0]['source']);
        $this->assertArrayHasKey('start', $segments[0]);
        $this->assertArrayHasKey('end', $segments[0]);
    }

    /**
     * Test ident resolution: icao used when airportId differs
     */
    public function testLoadRunwaySegmentsFromFileCache_ResolvesViaIcao(): void
    {
        $airport = ['lat' => 45.5897694, 'lon' => -122.5950944, 'icao' => 'KPDX'];
        $segments = loadRunwaySegmentsFromFileCache('pdx', $airport);
        $this->assertNotNull($segments);
        $this->assertCount(2, $segments);
    }

    /**
     * Test runwaysCacheNeedsRefresh returns boolean
     */
    public function testRunwaysCacheNeedsRefresh_ReturnsBoolean(): void
    {
        $result = runwaysCacheNeedsRefresh();
        $this->assertIsBool($result);
    }

    /**
     * Test warmRunwaysApcuCache skips airports with manual runways
     */
    public function testWarmRunwaysApcuCache_SkipsManualRunways(): void
    {
        $airports = [
            'kspb' => ['runways' => [['name' => '15/33', 'heading_1' => 150, 'heading_2' => 330]]],
            'pdx' => ['icao' => 'KPDX', 'lat' => 45.59, 'lon' => -122.60],
        ];
        $warmed = warmRunwaysApcuCache($airports);
        $this->assertIsInt($warmed);
        $this->assertGreaterThanOrEqual(0, $warmed);
    }

    // =========================================================================
    // Lat/lon schema with bearing-based label assignment (TDD)
    // =========================================================================

    /**
     * Test ident heading extraction: runway number * 10 = magnetic heading
     */
    public function testParseIdentHeading_ExtractsHeadingCorrectly(): void
    {
        $this->assertSame(350, parseIdentHeading('35'));
        $this->assertSame(170, parseIdentHeading('17'));
        $this->assertSame(280, parseIdentHeading('28L'));
        $this->assertSame(100, parseIdentHeading('10R'));
        $this->assertSame(90, parseIdentHeading('09C'));
        $this->assertSame(360, parseIdentHeading('36'));
    }

    /**
     * Test bearing from center to point (degrees 0-360, North=0)
     */
    public function testComputeBearing_ReturnsCorrectBearing(): void
    {
        $center = ['lat' => 45.0, 'lon' => -122.0];
        $north = ['lat' => 46.0, 'lon' => -122.0];
        $south = ['lat' => 44.0, 'lon' => -122.0];
        $east = ['lat' => 45.0, 'lon' => -121.0];
        $west = ['lat' => 45.0, 'lon' => -123.0];
        $this->assertEqualsWithDelta(0.0, computeBearing($center, $north), 1.0);
        $this->assertEqualsWithDelta(180.0, computeBearing($center, $south), 1.0);
        $this->assertEqualsWithDelta(90.0, computeBearing($center, $east), 2.0);
        $this->assertEqualsWithDelta(270.0, computeBearing($center, $west), 1.0);
    }

    /**
     * Test lat/lon schema: 35 label on north end, 17 on south end (bearing-based)
     */
    public function testManualRunwaysLatLonToSegments_AssignsLabelsByBearing(): void
    {
        $airport = ['lat' => 45.5, 'lon' => -122.0];
        $runways = [
            [
                'name' => '35/17',
                '35' => ['lat' => 45.51, 'lon' => -122.0],
                '17' => ['lat' => 45.49, 'lon' => -122.0],
            ],
        ];
        $segments = manualRunwaysLatLonToSegments($runways, $airport);
        $this->assertCount(1, $segments);
        $seg = $segments[0];
        $this->assertSame('manual', $seg['source']);
        $startY = $seg['start'][1];
        $endY = $seg['end'][1];
        $this->assertGreaterThan($endY, $startY, 'North (+y) end should have larger y');
        $identAtNorth = $startY > $endY ? $seg['le_ident'] : $seg['he_ident'];
        $identAtSouth = $startY > $endY ? $seg['he_ident'] : $seg['le_ident'];
        $this->assertSame('35', $identAtNorth, '35 should be at north end');
        $this->assertSame('17', $identAtSouth, '17 should be at south end');
    }

    /**
     * Test schema with swapped idents in config still produces correct placement
     */
    public function testManualRunwaysLatLonToSegments_IgnoresSchemaOrder(): void
    {
        $airport = ['lat' => 45.5, 'lon' => -122.0];
        $runways = [
            [
                'name' => '17/35',
                '17' => ['lat' => 45.49, 'lon' => -122.0],
                '35' => ['lat' => 45.51, 'lon' => -122.0],
            ],
        ];
        $segments = manualRunwaysLatLonToSegments($runways, $airport);
        $this->assertCount(1, $segments);
        $identAtNorth = $segments[0]['start'][1] > $segments[0]['end'][1]
            ? $segments[0]['le_ident'] : $segments[0]['he_ident'];
        $this->assertSame('35', $identAtNorth);
    }

    /**
     * Test parallel runways: each runway's endpoints stay paired (no cross-runway mix)
     */
    public function testManualRunwaysLatLonToSegments_ParallelRunwaysStayPaired(): void
    {
        $airport = ['lat' => 45.589, 'lon' => -122.595];
        $runways = [
            [
                'name' => '28L/10R',
                '28L' => ['lat' => 45.590, 'lon' => -122.601],
                '10R' => ['lat' => 45.588, 'lon' => -122.589],
            ],
            [
                'name' => '28R/10L',
                '28R' => ['lat' => 45.590, 'lon' => -122.598],
                '10L' => ['lat' => 45.588, 'lon' => -122.586],
            ],
        ];
        $segments = manualRunwaysLatLonToSegments($runways, $airport);
        $this->assertCount(2, $segments);
        $idents1 = [$segments[0]['le_ident'], $segments[0]['he_ident']];
        $idents2 = [$segments[1]['le_ident'], $segments[1]['he_ident']];
        sort($idents1);
        sort($idents2);
        $this->assertSame(['10R', '28L'], $idents1);
        $this->assertSame(['10L', '28R'], $idents2);
    }

    /**
     * Test invalid runway (wrong endpoint count) skipped gracefully
     */
    public function testManualRunwaysLatLonToSegments_SkipsInvalidEndpointCount(): void
    {
        $airport = ['lat' => 45.5, 'lon' => -122.0];
        $runways = [
            [
                'name' => '16/34',
                '16' => ['lat' => 45.51, 'lon' => -122.0],
                '34' => ['lat' => 45.49, 'lon' => -122.0],
            ],
            [
                'name' => 'bad',
                '16' => ['lat' => 45.51, 'lon' => -122.0],
            ],
            [
                'name' => 'bad2',
                '16' => ['lat' => 45.51, 'lon' => -122.0],
                '34' => ['lat' => 45.49, 'lon' => -122.0],
                'extra' => ['lat' => 45.5, 'lon' => -122.0],
            ],
        ];
        $segments = manualRunwaysLatLonToSegments($runways, $airport);
        $this->assertCount(1, $segments);
    }

    /**
     * Test getRunwaySegmentsForAirport uses lat/lon format when present
     */
    public function testGetRunwaySegmentsForAirport_UsesLatLonFormatWhenPresent(): void
    {
        $airport = [
            'lat' => 45.5,
            'lon' => -122.0,
            'runways' => [
                [
                    'name' => '35/17',
                    '35' => ['lat' => 45.51, 'lon' => -122.0],
                    '17' => ['lat' => 45.49, 'lon' => -122.0],
                ],
            ],
        ];
        $segments = getRunwaySegmentsForAirport('test', $airport);
        $this->assertNotNull($segments);
        $this->assertCount(1, $segments);
        $this->assertSame('manual', $segments[0]['source']);
    }
}
