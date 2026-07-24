<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * NMS map side-channel (#244): fetch hook, airspace store, and map serve contracts.
 *
 * @covers ::fetchNotamsForAirport
 * @covers ::notamAirspaceNormNumberFromId
 * @covers ::notamMapAirspaceAggregateUpsertFromFetch
 * @covers ::notamTfrMapLayerServeOrRebuild
 */
final class NotamMapSideChannelTest extends TestCase
{
    private string $cacheDir = '';

    private ?string $rateLimitRoot = null;

    protected function setUp(): void
    {
        $this->cacheDir = sys_get_temp_dir() . '/aviationwx-notam-sidechannel-' . bin2hex(random_bytes(4));
        if (!mkdir($this->cacheDir, 0755, true) && !is_dir($this->cacheDir)) {
            self::fail('Could not create NOTAM side-channel test cache directory: ' . $this->cacheDir);
        }

        $this->rateLimitRoot = sys_get_temp_dir() . '/aviationwx-notam-sidechannel-rl-' . bin2hex(random_bytes(4));
        mkdir($this->rateLimitRoot, 0755, true);

        $GLOBALS['notamCacheTestDirectory'] = $this->cacheDir;
        $GLOBALS['upstreamRateLimitTestRoot'] = $this->rateLimitRoot;
        $GLOBALS['notamRateLimitTestClientId'] = 'sidechannel-test-client';
        $GLOBALS['notamRateLimitTestClientSecret'] = 'sidechannel-test-secret';
        $GLOBALS['notamRateLimitTestBaseUrl'] = 'https://example.test/nms';
        $GLOBALS['notamRateLimitTestSkipSleep'] = true;
        $GLOBALS['notamRateLimitTestPollMicroseconds'] = 50_000;
        $GLOBALS['upstreamRateLimitTestNow'] = 1_700_000_000.0;
        $GLOBALS['notamTestSkipSleep'] = true;
        $GLOBALS['notamTestBearerToken'] = 'test-bearer-token';

        require_once __DIR__ . '/../../lib/notam/http.php';
        require_once __DIR__ . '/../../lib/notam/circuit-breaker.php';
        require_once __DIR__ . '/../../lib/notam/fetcher.php';
        require_once __DIR__ . '/../../lib/notam/map-aggregate-cache.php';
        require_once __DIR__ . '/../../lib/notam/map-layer-cache.php';

        notamRateLimitTestForceEnforcement();
        clearNotamGlobalBackoff();
    }

    protected function tearDown(): void
    {
        clearNotamGlobalBackoff();
        notamRateLimitTestClearForceEnforcement();

        unset(
            $GLOBALS['notamCacheTestDirectory'],
            $GLOBALS['upstreamRateLimitTestRoot'],
            $GLOBALS['notamRateLimitTestClientId'],
            $GLOBALS['notamRateLimitTestClientSecret'],
            $GLOBALS['notamRateLimitTestBaseUrl'],
            $GLOBALS['notamRateLimitTestSkipSleep'],
            $GLOBALS['notamRateLimitTestPollMicroseconds'],
            $GLOBALS['upstreamRateLimitTestNow'],
            $GLOBALS['notamTestSkipSleep'],
            $GLOBALS['notamTestBearerToken'],
            $GLOBALS['notamTestNmsHttpHandler'],
        );

        if ($this->cacheDir !== '' && is_dir($this->cacheDir)) {
            $this->removeTree($this->cacheDir);
        }
        if ($this->rateLimitRoot !== null && is_dir($this->rateLimitRoot)) {
            $this->removeTree($this->rateLimitRoot);
        }
    }

    /**
     * Airport near Coeur d'Alene, ID - far from the Ogden, UT circle TFR fixture.
     *
     * @return array<string, mixed>
     */
    private function airportWithoutLocationIdentifiers(): array
    {
        return [
            'enabled' => true,
            'listed' => true,
            'lat' => 47.52,
            'lon' => -116.08,
            'timezone' => 'UTC',
        ];
    }

    private function distantCircleTfrAixmXml(): string
    {
        return (string) file_get_contents(__DIR__ . '/../Fixtures/notam/distant-circle-tfr-ogden.xml');
    }

    /**
     * @param array<int, string> $geoAixmRows
     */
    private function installNmsHandlerReturningGeoTfr(array $geoAixmRows): void
    {
        $GLOBALS['notamTestNmsHttpHandler'] = static function (string $url, string $bearerToken) use ($geoAixmRows): array {
            if (str_contains($url, 'latitude=')) {
                $body = json_encode([
                    'status' => 'Success',
                    'data' => ['aixm' => $geoAixmRows],
                ], JSON_THROW_ON_ERROR);

                return [
                    'body' => $body,
                    'http_code' => 200,
                    'headers' => [],
                    'error' => '',
                ];
            }

            return [
                'body' => '{"status":"Success","data":{}}',
                'http_code' => 200,
                'headers' => [],
                'error' => '',
            ];
        };
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

    public function testFetchNotamsForAirport_GeoDrawableTfrOutsideAirportRelevance_UpsertsMapStoreExcludesBannerList(): void
    {
        $this->installNmsHandlerReturningGeoTfr([$this->distantCircleTfrAixmXml()]);

        $airport = $this->airportWithoutLocationIdentifiers();
        $fetchSucceeded = false;
        $bannerNotams = fetchNotamsForAirport('s83', $airport, $fetchSucceeded);

        $this->assertTrue($fetchSucceeded);
        $this->assertSame([], $bannerNotams, 'TFR outside airport relevance must not enter the banner list');

        $envelope = notamMapAirspaceAggregateRead();
        $this->assertNotNull($envelope);
        $this->assertArrayHasKey('F9001/2026', $envelope['records'] ?? []);
        $record = $envelope['records']['F9001/2026'];
        $this->assertTrue($record['capabilities']['map'] ?? false);
        $this->assertSame('s83', $record['source_airport_id'] ?? null);

        $payload = notamTfrMapLayerServeOrRebuild();
        $this->assertCount(1, $payload['features']);
        $this->assertSame('F9001/2026', $payload['features'][0]['properties']['notam_id'] ?? null);
        $this->assertSame('faa_nms_side_channel', $payload['coverage_scope'] ?? null);
    }

    public function testFetchNotamsForAirport_WhenGeoReturnsNoDrawableTfr_DoesNotWriteMapStore(): void
    {
        $this->installNmsHandlerReturningGeoTfr([]);

        $airport = $this->airportWithoutLocationIdentifiers();
        fetchNotamsForAirport('s83', $airport);

        $this->assertFileDoesNotExist(getNotamMapAirspaceAggregatePath());
    }

    public function testMapLayerServe_EndToEndAfterFetch_IncludesCoverageMetadata(): void
    {
        $this->installNmsHandlerReturningGeoTfr([$this->distantCircleTfrAixmXml()]);
        fetchNotamsForAirport('s83', $this->airportWithoutLocationIdentifiers());

        $payload = notamTfrMapLayerServeOrRebuild();

        $this->assertArrayHasKey('coverage_note', $payload);
        $this->assertArrayHasKey('map_layer_build_token', $payload);
        $this->assertFalse($payload['failclosed'] ?? false);
    }

    public function testAirspaceRecord_FieldSourcesTrackNmsFieldsNotConfigDerivedMetadata(): void
    {
        $now = time();
        $notam = [
            'id' => 'FIELD1/2026',
            'text' => 'ZLC UT..AIRSPACE OGDEN, UT..TEMPORARY FLIGHT RESTRICTIONS '
                . 'WITHIN AN AREA DEFINED AS 5NM RADIUS OF 413900N1122300W (OGD319029) TEST.',
            'start_time_utc' => gmdate('Y-m-d\TH:i:s\Z', $now - 3600),
            'end_time_utc' => gmdate('Y-m-d\TH:i:s\Z', $now + 7200),
        ];

        $record = notamAirspaceRecordFromNotam($notam, 's83', 'America/Los_Angeles');
        $this->assertNotNull($record);

        $sources = $record['field_sources'];
        $this->assertSame(NOTAM_AIRSPACE_SOURCE_NMS, $sources['geometry'] ?? null);
        $this->assertArrayNotHasKey('timezone', $sources, 'timezone comes from airport config, not NMS');
        $this->assertArrayNotHasKey('source_airport_id', $sources, 'source_airport_id is ingest metadata, not an NMS field');
        $this->assertSame('America/Los_Angeles', $record['timezone']);
        $this->assertSame('s83', $record['source_airport_id']);
    }

    public function testMapLayerBuild_SkipsRecordsWithoutMapCapability(): void
    {
        $now = time();
        $notam = [
            'id' => 'NMAP1/2026',
            'text' => 'ZLC UT..AIRSPACE OGDEN, UT..TEMPORARY FLIGHT RESTRICTIONS '
                . 'WITHIN AN AREA DEFINED AS 5NM RADIUS OF 413900N1122300W (OGD319029) TEST.',
            'start_time_utc' => gmdate('Y-m-d\TH:i:s\Z', $now - 3600),
            'end_time_utc' => gmdate('Y-m-d\TH:i:s\Z', $now + 7200),
        ];
        $record = notamAirspaceRecordFromNotam($notam, 's83', 'UTC');
        $this->assertNotNull($record);
        $record['capabilities']['map'] = false;

        $envelope = [
            'schema_version' => NOTAM_MAP_AIRSPACE_SCHEMA_VERSION,
            'records' => ['NMAP1/2026' => $record],
            'map_layer_build_token' => notamTfrMapLayerCurrentBuildToken(),
        ];

        $payload = notamTfrMapLayerBuildPayloadFromAirspaceStore($envelope, $now);

        $this->assertSame([], $payload['features']);
    }

    public function testNotamAirspaceNormNumberFromId_LetterSeries_ExtractsNumericPart(): void
    {
        $this->assertSame('N:3389', notamAirspaceNormNumberFromId('A3389/2026'));
        $this->assertSame('N:2698', notamAirspaceNormNumberFromId('2698/2026'));
        $this->assertSame('N:8339', notamAirspaceNormNumberFromId('8339/2026'));
        $this->assertNull(notamAirspaceNormNumberFromId(''));
        $this->assertNull(notamAirspaceNormNumberFromId('invalid'));
    }
}
