<?php

declare(strict_types=1);

use AviationWX\Notam\Airspace\Adapter\FaaTfrWfsAdapter;
use AviationWX\Notam\Airspace\AirspaceAggregator;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../lib/notam/map-aggregate-cache.php';
require_once __DIR__ . '/../../lib/notam/map-layer-cache.php';
require_once __DIR__ . '/../../lib/notam/airspace/adapter/FaaTfrWfsAdapter.php';
require_once __DIR__ . '/../../lib/notam/airspace/AirspaceAggregator.php';

/**
 * FAA TFR WFS adapter + record-level AirspaceAggregator (#246).
 */
final class AirspaceWfsMergeTest extends TestCase
{
    private string $cacheDir = '';

    protected function setUp(): void
    {
        $this->cacheDir = sys_get_temp_dir() . '/aviationwx-airspace-wfs-' . bin2hex(random_bytes(4));
        if (!mkdir($this->cacheDir, 0755, true) && !is_dir($this->cacheDir)) {
            self::fail('Could not create WFS test cache directory');
        }
        $GLOBALS['notamCacheTestDirectory'] = $this->cacheDir;
    }

    protected function tearDown(): void
    {
        unset($GLOBALS['notamCacheTestDirectory'], $GLOBALS['faaTfrWfsHttpHandler']);
        if ($this->cacheDir !== '' && is_dir($this->cacheDir)) {
            foreach (scandir($this->cacheDir) ?: [] as $item) {
                if ($item === '.' || $item === '..') {
                    continue;
                }
                @unlink($this->cacheDir . '/' . $item);
            }
            @rmdir($this->cacheDir);
        }
    }

    private function fixtureJson(): string
    {
        $path = __DIR__ . '/../Fixtures/notam/faa-tfr-wfs-sample.json';
        $raw = file_get_contents($path);
        if ($raw === false) {
            self::fail('Missing WFS fixture: ' . $path);
        }

        return $raw;
    }

    public function testFaaTfrWfsAdapter_ParsesFixture_CoalescesMultiPolygonAndSetsCapabilities(): void
    {
        $records = FaaTfrWfsAdapter::parseResponse($this->fixtureJson());
        $this->assertNotNull($records);

        $byNorm = [];
        foreach ($records as $record) {
            $byNorm[(string) $record['norm_number']] = $record;
        }

        $this->assertArrayHasKey('N:543', $byNorm);
        $security = $byNorm['N:543'];
        $this->assertSame('security', $security['restriction_kind']);
        $this->assertSame('multipolygon', $security['geometry_kind']);
        $this->assertSame('MultiPolygon', $security['geometry']['type'] ?? null);
        $this->assertTrue($security['capabilities']['map']);
        $this->assertFalse($security['capabilities']['banner']);
        $this->assertFalse($security['capabilities']['runway_closure']);
        $this->assertSame(FaaTfrWfsAdapter::SOURCE_TYPE, $security['field_sources']['geometry'] ?? null);

        $this->assertArrayHasKey('N:1111', $byNorm);
        $fisb = $byNorm['N:1111'];
        $this->assertSame('fis_b', $fisb['restriction_kind']);
        $this->assertFalse($fisb['capabilities']['banner']);

        $this->assertArrayHasKey('N:8576', $byNorm);
        $this->assertSame('airshow', $byNorm['N:8576']['restriction_kind']);
    }

    public function testAirspaceAggregator_MergesNmsAndWfs_WfsGeometryNmsSchedule(): void
    {
        $now = time();
        $nmsNotam = [
            'id' => 'F0543/2026',
            'text' => 'ZDC DC..AIRSPACE WASHINGTON, DC..TEMPORARY FLIGHT RESTRICTIONS '
                . 'WITHIN AN AREA DEFINED AS 5NM RADIUS OF 385200N0770300W SECURITY.',
            'start_time_utc' => gmdate('Y-m-d\TH:i:s\Z', $now - 3600),
            'end_time_utc' => gmdate('Y-m-d\TH:i:s\Z', $now + 7200),
        ];
        $nms = notamAirspaceRecordFromNotam($nmsNotam, 'dca', 'UTC');
        $this->assertNotNull($nms);
        $this->assertSame('N:543', $nms['norm_number']);
        $this->assertSame('circle', $nms['geometry_kind']);

        $wfsRecords = FaaTfrWfsAdapter::parseResponse($this->fixtureJson());
        $this->assertNotNull($wfsRecords);
        $wfs = null;
        foreach ($wfsRecords as $record) {
            if (($record['norm_number'] ?? null) === 'N:543') {
                $wfs = $record;
                break;
            }
        }
        $this->assertNotNull($wfs);

        $merged = AirspaceAggregator::merge([$nms, $wfs]);
        $this->assertCount(1, array_filter(
            $merged,
            static fn (array $r): bool => ($r['norm_number'] ?? null) === 'N:543'
        ));
        $row = $merged['N:543'];
        $this->assertSame('multipolygon', $row['geometry_kind']);
        $this->assertSame(FaaTfrWfsAdapter::SOURCE_TYPE, $row['field_sources']['geometry'] ?? null);
        $this->assertSame(NOTAM_AIRSPACE_SOURCE_NMS, $row['field_sources']['text'] ?? null);
        $this->assertTrue($row['capabilities']['banner']);
        $this->assertFalse($row['capabilities']['runway_closure']);
        $this->assertContains(NOTAM_AIRSPACE_SOURCE_NMS, $row['record_sources']);
        $this->assertContains(FaaTfrWfsAdapter::SOURCE_TYPE, $row['record_sources']);
        $this->assertNotNull($row['merged_at']);
    }

    public function testAirspaceAggregator_WfsOnly_NeverBannerCapable(): void
    {
        $wfsRecords = FaaTfrWfsAdapter::parseResponse($this->fixtureJson());
        $this->assertNotNull($wfsRecords);
        $merged = AirspaceAggregator::merge($wfsRecords);

        foreach ($merged as $record) {
            $this->assertFalse($record['capabilities']['banner']);
            $this->assertFalse($record['capabilities']['runway_closure']);
        }
    }

    public function testMapServe_WfsOnlyRecord_WithoutNmsIsTfr(): void
    {
        $wfsRecords = FaaTfrWfsAdapter::parseResponse($this->fixtureJson());
        $this->assertNotNull($wfsRecords);
        $hazards = null;
        foreach ($wfsRecords as $record) {
            if (($record['norm_number'] ?? null) === 'N:2698') {
                $hazards = $record;
                break;
            }
        }
        $this->assertNotNull($hazards);

        $now = time();
        $envelope = [
            'schema_version' => NOTAM_MAP_AIRSPACE_SCHEMA_VERSION,
            'merge_logic_version' => NOTAM_AIRSPACE_MERGE_LOGIC_VERSION,
            'data_updated_at' => $now,
            'coverage_sources' => ['faa_tfr_wfs'],
            'source_status' => [
                'faa_tfr_wfs' => ['ok' => true, 'updated_at' => $now],
            ],
            'records' => ['N:2698' => $hazards],
            'map_layer_build_token' => 'merge-v' . NOTAM_AIRSPACE_MERGE_LOGIC_VERSION,
        ];
        notamMapAirspaceAggregateWrite($envelope);

        $payload = notamTfrMapLayerServeOrRebuild();
        $this->assertFalse($payload['failclosed'] ?? false);
        $this->assertCount(1, $payload['features']);
        $this->assertSame('faa_tfr_wfs', $payload['coverage_scope'] ?? null);
        $this->assertSame('active', $payload['features'][0]['properties']['status'] ?? null);
        $this->assertSame('tfr', $payload['features'][0]['properties']['restriction_kind'] ?? null);
    }

    public function testMapServe_FisB_DoesNotForceTfrHeadline(): void
    {
        $wfsRecords = FaaTfrWfsAdapter::parseResponse($this->fixtureJson());
        $this->assertNotNull($wfsRecords);
        $fisb = null;
        foreach ($wfsRecords as $record) {
            if (($record['norm_number'] ?? null) === 'N:1111') {
                $fisb = $record;
                break;
            }
        }
        $this->assertNotNull($fisb);

        $feature = notamTfrMapLayerFeatureFromAirspaceRecord($fisb, time());
        $this->assertNotNull($feature);
        $headline = (string) ($feature['properties']['banner_headline'] ?? '');
        $this->assertStringStartsWith('FIS-B:', $headline);
        $this->assertStringNotContainsString('Temporary flight restriction', $headline);
        $this->assertSame('fis_b', $feature['properties']['restriction_kind'] ?? null);
    }

    public function testWfsFetchFailure_MarksSourceUnhealthy_RetainsNmsRecords(): void
    {
        $now = time();
        $nmsNotam = [
            'id' => 'A7001/2026',
            'text' => 'ZLC UT..AIRSPACE OGDEN, UT..TEMPORARY FLIGHT RESTRICTIONS '
                . 'WITHIN AN AREA DEFINED AS 5NM RADIUS OF 413900N1122300W (OGD319029) TEST.',
            'start_time_utc' => gmdate('Y-m-d\TH:i:s\Z', $now - 3600),
            'end_time_utc' => gmdate('Y-m-d\TH:i:s\Z', $now + 7200),
        ];
        $nms = notamAirspaceRecordFromNotam($nmsNotam, 's83', 'UTC');
        $this->assertNotNull($nms);
        notamMapAirspaceAggregateUpsertFromFetch('s83', [
            'timezone' => 'UTC',
            'lat' => 47.5,
            'lon' => -116.0,
        ], [$nmsNotam]);

        $this->assertTrue(notamMapAirspaceAggregateMarkSourceStatus(FaaTfrWfsAdapter::SOURCE_TYPE, false, 'HTTP 503'));

        $envelope = notamMapAirspaceAggregateRead();
        $this->assertNotNull($envelope);
        $this->assertFalse($envelope['source_status']['faa_tfr_wfs']['ok'] ?? true);
        $this->assertArrayHasKey('N:7001', $envelope['records']);

        $payload = notamTfrMapLayerServeOrRebuild();
        $this->assertFalse($payload['failclosed'] ?? false);
        $this->assertCount(1, $payload['features']);
        $this->assertSame('faa_nms_side_channel', $payload['coverage_scope'] ?? null);
    }

    public function testFetchScriptHandler_PersistsRawAndMerges(): void
    {
        $fixture = $this->fixtureJson();
        $GLOBALS['faaTfrWfsHttpHandler'] = static function (string $url) use ($fixture): array {
            self::assertStringContainsString('V_TFR_LOC', $url);

            return ['ok' => true, 'body' => $fixture, 'http_code' => 200, 'error' => ''];
        };

        $result = FaaTfrWfsAdapter::fetchAndParse(['persist_raw' => true]);
        $this->assertTrue($result['ok']);
        $this->assertFileExists(getNotamFaaTfrWfsCachePath());
        $this->assertTrue(notamMapAirspaceAggregateMergeWfsRecords($result['records']));

        $envelope = notamMapAirspaceAggregateRead();
        $this->assertNotNull($envelope);
        $this->assertContains('faa_tfr_wfs', $envelope['coverage_sources'] ?? []);
        $this->assertGreaterThanOrEqual(4, count($envelope['records']));
    }
}
