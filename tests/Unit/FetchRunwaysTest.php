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
}
