<?php

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../Helpers/IsolatesOurAirportsCacheTrait.php';
require_once __DIR__ . '/../../lib/runways.php';
require_once __DIR__ . '/../../scripts/fetch-runways.php';

class FetchRunwaysTest extends TestCase
{
    use IsolatesOurAirportsCacheTrait;

    protected function setUp(): void
    {
        parent::setUp();
        $this->resetOurAirportsTestCacheState();
    }

    /**
     * @return array{
     *   faa: array<string, array<int, array<string, mixed>>>,
     *   ourairports: array<string, array<int, array<string, mixed>>>,
     *   centers: array<string, array{lat: float, lon: float}>,
     *   faa_to_icao: array<string, string>
     * }
     */
    private function sampleMergeInputs(): array
    {
        return [
            'faa' => [
                'HIO' => [
                    [
                        'lat1' => 45.54,
                        'lon1' => -122.95,
                        'lat2' => 45.53,
                        'lon2' => -122.94,
                        'le_ident' => '03',
                        'he_ident' => '21',
                        'source' => 'faa',
                    ],
                ],
            ],
            'ourairports' => [
                'KHIO' => [
                    [
                        'lat1' => 45.54,
                        'lon1' => -122.95,
                        'lat2' => 45.53,
                        'lon2' => -122.94,
                        'le_ident' => '03',
                        'he_ident' => '21',
                        'length_ft' => 6600,
                        'surface' => 'ASPH',
                        'le_displaced_threshold_ft' => 0,
                        'he_displaced_threshold_ft' => 0,
                        'source' => 'ourairports',
                    ],
                ],
                'CYAV' => [
                    [
                        'lat1' => 50.05,
                        'lon1' => -97.03,
                        'lat2' => 50.06,
                        'lon2' => -97.02,
                        'le_ident' => '13',
                        'he_ident' => '31',
                        'length_ft' => 3000,
                        'surface' => 'ASPH',
                        'le_displaced_threshold_ft' => 0,
                        'he_displaced_threshold_ft' => 0,
                        'source' => 'ourairports',
                    ],
                ],
            ],
            'centers' => [
                'HIO' => ['lat' => 45.535, 'lon' => -122.945],
                'CYAV' => ['lat' => 50.055, 'lon' => -97.025],
            ],
            'faa_to_icao' => ['HIO' => 'KHIO'],
        ];
    }

    public function testResolveOurAirportsRunwaysForCacheIdent_UsesFaaToIcaoMapping(): void
    {
        $ourairports = [
            'KHIO' => [
                [
                    'length_ft' => 6600,
                    'surface' => 'ASPH',
                    'le_ident' => '03',
                    'he_ident' => '21',
                ],
            ],
        ];

        $resolved = resolveOurAirportsRunwaysForCacheIdent('HIO', $ourairports, ['HIO' => 'KHIO']);

        $this->assertNotNull($resolved);
        $this->assertSame(6600, $resolved[0]['length_ft']);
    }

    public function testGetOurAirportsPerformanceRunwaysFromParsedCache_UsesOurAirportsIdent(): void
    {
        $data = [
            'airports' => [
                'US-4027' => [
                    'performance_runways' => [
                        [
                            'rwy_id' => '17/35',
                            'length_ft' => 2700,
                            'surface' => 'GRS',
                            'ends' => [],
                        ],
                    ],
                ],
            ],
        ];

        $runways = getOurAirportsPerformanceRunwaysFromParsedCache($data, '45ranch', [
            'ourairports_ident' => 'US-4027',
        ]);

        $this->assertNotNull($runways);
        $this->assertSame(2700, $runways[0]['length_ft']);
    }

    public function testMergeRunwaySources_FaaAirport_AttachesOurAirportsPerformanceUnderBothIdents(): void
    {
        $inputs = $this->sampleMergeInputs();
        $merged = mergeRunwaySources(
            $inputs['faa'],
            $inputs['ourairports'],
            $inputs['centers'],
            $inputs['faa_to_icao']
        );

        $this->assertArrayHasKey('performance_runways', $merged['HIO']);
        $this->assertArrayHasKey('performance_runways', $merged['KHIO']);
        $this->assertSame(6600, $merged['KHIO']['performance_runways'][0]['length_ft']);
        $this->assertArrayHasKey('CYAV', $merged);
    }

    public function testWriteMergedRunwaysCacheStreaming_ProducesValidJsonMatchingInMemoryMerge(): void
    {
        $inputs = $this->sampleMergeInputs();
        $fetchedAt = 1700000000;
        $expected = mergeRunwaySources(
            $inputs['faa'],
            $inputs['ourairports'],
            $inputs['centers'],
            $inputs['faa_to_icao']
        );

        $result = writeMergedRunwaysCacheStreaming(
            $inputs['faa'],
            $inputs['ourairports'],
            $inputs['centers'],
            $inputs['faa_to_icao'],
            $fetchedAt
        );

        $this->assertTrue($result['ok'], (string) ($result['error'] ?? ''));
        $this->assertSame(count($expected), $result['count']);
        $this->assertFileExists((string) $result['tmp_path']);
        $this->assertFileDoesNotExist(CACHE_RUNWAYS_DATA_FILE);

        $decoded = json_decode((string) file_get_contents((string) $result['tmp_path']), true);
        @unlink((string) $result['tmp_path']);

        $this->assertIsArray($decoded);
        $this->assertSame($fetchedAt, $decoded['fetched_at'] ?? null);
        $this->assertSame(array_keys($expected), array_keys($decoded['airports'] ?? []));
        $this->assertSame(
            $expected['KHIO']['performance_runways'][0]['length_ft'],
            $decoded['airports']['KHIO']['performance_runways'][0]['length_ft']
        );
    }

    public function testWriteMergedRunwaysCacheStreaming_DoesNotReplaceLiveCacheUntilPublish(): void
    {
        $liveMarker = json_encode(['fetched_at' => 1, 'airports' => ['OLD' => ['segments' => []]]], JSON_UNESCAPED_SLASHES);
        file_put_contents(CACHE_RUNWAYS_DATA_FILE, $liveMarker, LOCK_EX);

        $inputs = $this->sampleMergeInputs();
        $result = writeMergedRunwaysCacheStreaming(
            $inputs['faa'],
            $inputs['ourairports'],
            $inputs['centers'],
            $inputs['faa_to_icao'],
            time()
        );

        $this->assertTrue($result['ok']);
        $this->assertSame($liveMarker, file_get_contents(CACHE_RUNWAYS_DATA_FILE));
        @unlink((string) $result['tmp_path']);
    }

    public function testPublishMergedRunwaysCacheAfterRetentionCheck_BelowRetainRatio_KeepsPreviousCache(): void
    {
        $previous = json_encode([
            'fetched_at' => 100,
            'airports' => array_fill_keys(range(1, 100), ['segments' => []]),
        ], JSON_UNESCAPED_SLASHES);
        file_put_contents(CACHE_RUNWAYS_DATA_FILE, $previous, LOCK_EX);
        file_put_contents(
            CACHE_RUNWAYS_META_FILE,
            json_encode(['airport_count' => 100, 'fetched_at' => 100], JSON_UNESCAPED_SLASHES),
            LOCK_EX
        );

        $tmpPath = CACHE_RUNWAYS_DATA_FILE . '.tmp.test';
        file_put_contents(
            $tmpPath,
            json_encode(['fetched_at' => 200, 'airports' => array_fill_keys(range(1, 80), ['segments' => []])], JSON_UNESCAPED_SLASHES),
            LOCK_EX
        );

        $publish = publishMergedRunwaysCacheAfterRetentionCheck(
            $tmpPath,
            80,
            100,
            ['KTEST' => ['lat' => 1.0, 'lon' => 2.0]],
            false,
            200
        );

        $this->assertFalse($publish['ok']);
        $this->assertTrue($publish['retained_previous']);
        $this->assertSame('merged airport count below retention threshold', $publish['error']);
        $this->assertFileDoesNotExist($tmpPath);
        $this->assertSame($previous, file_get_contents(CACHE_RUNWAYS_DATA_FILE));
    }

    public function testPublishMergedRunwaysCacheAfterRetentionCheck_WithinRetainRatio_PublishesAndWritesMeta(): void
    {
        file_put_contents(
            CACHE_RUNWAYS_DATA_FILE,
            json_encode(['fetched_at' => 100, 'airports' => ['OLD' => ['segments' => []]]], JSON_UNESCAPED_SLASHES),
            LOCK_EX
        );

        $tmpPath = CACHE_RUNWAYS_DATA_FILE . '.tmp.test';
        $payload = ['fetched_at' => 200, 'airports' => ['NEW' => ['segments' => [], 'center_lat' => 1.0, 'center_lon' => 2.0]]];
        file_put_contents($tmpPath, json_encode($payload, JSON_UNESCAPED_SLASHES), LOCK_EX);

        $publish = publishMergedRunwaysCacheAfterRetentionCheck(
            $tmpPath,
            1,
            null,
            ['NEW' => ['lat' => 1.0, 'lon' => 2.0]],
            false,
            200
        );

        $this->assertTrue($publish['ok'], (string) ($publish['error'] ?? ''));
        $this->assertFalse($publish['retained_previous']);
        $this->assertFileDoesNotExist($tmpPath);
        $live = json_decode((string) file_get_contents(CACHE_RUNWAYS_DATA_FILE), true);
        $this->assertSame('NEW', array_key_first($live['airports'] ?? []));
        $meta = json_decode((string) file_get_contents(CACHE_RUNWAYS_META_FILE), true);
        $this->assertSame(1, $meta['airport_count'] ?? null);
        $this->assertSame(200, $meta['fetched_at'] ?? null);
    }

    public function testParseOurAirportsRunwaysFromPath_MissingCoordinates_SkipsRow(): void
    {
        $path = CACHE_OURAIRPORTS_RUNWAYS_CSV;
        file_put_contents(
            $path,
            "id,airport_ident,length_ft,width_ft,surface,lighted,closed,le_ident,he_ident,le_latitude_deg,le_longitude_deg,he_latitude_deg,he_longitude_deg,le_heading_degT,he_heading_degT,le_displaced_threshold_ft,he_displaced_threshold_ft\n"
            . "1,KBAD,3000,75,ASPH,1,0,09,27,,,,90,270,0,0\n"
            . "2,KTEST,3000,75,ASPH,1,0,09,27,45.0,-122.0,45.1,-121.9,90,270,0,0\n",
            LOCK_EX
        );

        $parsed = parseOurAirportsRunwaysFromPath($path);
        $this->assertArrayNotHasKey('KBAD', $parsed);
        $this->assertArrayHasKey('KTEST', $parsed);
        $this->assertSame(3000, $parsed['KTEST'][0]['length_ft']);
    }

    public function testRunwaysCacheBytesFullyWritten_RejectsShortOrFailedWrites(): void
    {
        $this->assertTrue(runwaysCacheBytesFullyWritten(3, 'abc'));
        $this->assertFalse(runwaysCacheBytesFullyWritten(2, 'abc'));
        $this->assertFalse(runwaysCacheBytesFullyWritten(false, 'abc'));
        $this->assertFalse(runwaysCacheBytesFullyWritten(0, 'abc'));
    }

    public function testFwriteExactRunwaysCache_WritesFullPayload(): void
    {
        $handle = fopen('php://memory', 'r+b');
        $this->assertNotFalse($handle);
        $payload = str_repeat('{"k":"v"}', 50);
        $this->assertTrue(fwriteExactRunwaysCache($handle, $payload));
        rewind($handle);
        $this->assertSame($payload, stream_get_contents($handle));
        fclose($handle);
    }

    public function testReadPreviousRunwaysCacheAirportCount_MetaPresent_PrefersMetaOverFile(): void
    {
        file_put_contents(
            CACHE_RUNWAYS_META_FILE,
            json_encode(['airport_count' => 42, 'fetched_at' => 1], JSON_UNESCAPED_SLASHES),
            LOCK_EX
        );
        file_put_contents(
            CACHE_RUNWAYS_DATA_FILE,
            json_encode(['fetched_at' => 1, 'airports' => ['A' => [], 'B' => []]], JSON_UNESCAPED_SLASHES),
            LOCK_EX
        );

        $this->assertSame(42, readPreviousRunwaysCacheAirportCount());
    }

    public function testReadPreviousRunwaysCacheAirportCount_MetaMissing_FallsBackToCacheFile(): void
    {
        file_put_contents(
            CACHE_RUNWAYS_DATA_FILE,
            json_encode(['fetched_at' => 1, 'airports' => ['A' => [], 'B' => [], 'C' => []]], JSON_UNESCAPED_SLASHES),
            LOCK_EX
        );

        $this->assertSame(3, readPreviousRunwaysCacheAirportCount());
    }

    public function testParseOurAirportsRunwaysFromPath_CardinalWithoutCoordinates_KeepsRow(): void
    {
        $path = CACHE_OURAIRPORTS_RUNWAYS_CSV;
        file_put_contents(
            $path,
            "id,airport_ident,length_ft,width_ft,surface,lighted,closed,le_ident,he_ident,le_latitude_deg,le_longitude_deg,he_latitude_deg,he_longitude_deg,le_heading_degT,he_heading_degT,le_displaced_threshold_ft,he_displaced_threshold_ft\n"
            . "1,KBAD,3000,75,ASPH,1,0,09,27,,,,90,270,0,0\n"
            . "2,KCARD,2000,40,TURF,1,0,N,S,,,,,,0,0\n",
            LOCK_EX
        );

        $parsed = parseOurAirportsRunwaysFromPath($path);
        $this->assertArrayNotHasKey('KBAD', $parsed);
        $this->assertArrayHasKey('KCARD', $parsed);
        $this->assertNull($parsed['KCARD'][0]['lat1']);
        $this->assertSame('N', $parsed['KCARD'][0]['le_ident']);
        $this->assertSame('S', $parsed['KCARD'][0]['he_ident']);
    }

    public function testRunwaysToSegments_CardinalRunway_SynthesizesNorthSouthSegment(): void
    {
        $segments = runwaysToSegments(
            [
                [
                    'lat1' => null,
                    'lon1' => null,
                    'lat2' => null,
                    'lon2' => null,
                    'le_ident' => 'N',
                    'he_ident' => 'S',
                    'source' => 'ourairports',
                ],
            ],
            45.0,
            -120.0
        );

        $this->assertCount(1, $segments);
        $this->assertSame('N', $segments[0]['le_ident']);
        $this->assertSame('ourairports', $segments[0]['source']);
        $this->assertTrue($segments[0]['magnetic']);
        $this->assertEqualsWithDelta(0.0, $segments[0]['start'][0], 0.0001);
        $this->assertEqualsWithDelta(0.9, $segments[0]['start'][1], 0.0001);
        $this->assertEqualsWithDelta(0.0, $segments[0]['end'][0], 0.0001);
        $this->assertEqualsWithDelta(-0.9, $segments[0]['end'][1], 0.0001);
    }

    public function testRunwaysToSegments_NonCardinalRunwayWithoutCoordinates_SkipsRow(): void
    {
        $segments = runwaysToSegments(
            [
                [
                    'lat1' => null,
                    'lon1' => null,
                    'lat2' => null,
                    'lon2' => null,
                    'le_ident' => '09',
                    'he_ident' => '27',
                    'source' => 'ourairports',
                ],
            ],
            45.0,
            -120.0
        );

        $this->assertSame([], $segments);
    }

    public function testParseOurAirportsRunwaysFromPath_PartialCardinalWithoutCoordinates_DropsRow(): void
    {
        $path = CACHE_OURAIRPORTS_RUNWAYS_CSV;
        file_put_contents(
            $path,
            "id,airport_ident,length_ft,width_ft,surface,lighted,closed,le_ident,he_ident,le_latitude_deg,le_longitude_deg,he_latitude_deg,he_longitude_deg,le_heading_degT,he_heading_degT,le_displaced_threshold_ft,he_displaced_threshold_ft\n"
            . "1,KMIX,2000,40,TURF,1,0,N,27,,,,,,0,0\n",
            LOCK_EX
        );

        $this->assertSame([], parseOurAirportsRunwaysFromPath($path));
    }

    public function testParseFaaRunways_CardinalWithoutCoordinates_KeepsRow(): void
    {
        $csv = "ARPT_ID,RWY_ID,LAT1_DECIMAL,LONG1_DECIMAL,LAT2_DECIMAL,LONG2_DECIMAL\n"
            . "02ID,N/S,,,,\n"
            . "12ID,18/36,,,,\n";

        $parsed = parseFaaRunways($csv);
        $this->assertArrayHasKey('02ID', $parsed);
        $this->assertSame('N', $parsed['02ID'][0]['le_ident']);
        $this->assertNull($parsed['02ID'][0]['lat1']);
        $this->assertArrayNotHasKey('12ID', $parsed);
    }

    public function testParseOurAirportsRunwaysFromPath_NonOppositeCardinalWithoutCoordinates_DropsRow(): void
    {
        $path = CACHE_OURAIRPORTS_RUNWAYS_CSV;
        file_put_contents(
            $path,
            "id,airport_ident,length_ft,width_ft,surface,lighted,closed,le_ident,he_ident,le_latitude_deg,le_longitude_deg,he_latitude_deg,he_longitude_deg,le_heading_degT,he_heading_degT,le_displaced_threshold_ft,he_displaced_threshold_ft\n"
            . "1,KSKEW,2000,40,TURF,1,0,N,E,,,,,,0,0\n",
            LOCK_EX
        );

        $this->assertSame([], parseOurAirportsRunwaysFromPath($path));
    }

    public function testCardinalRunwayToSegment_NonOpposite_ReturnsNull(): void
    {
        $this->assertNull(cardinalRunwayToSegment('N', 'E', 'ourairports'));
        $this->assertNull(cardinalRunwayToSegment('N', 'N', 'ourairports'));
    }
}
