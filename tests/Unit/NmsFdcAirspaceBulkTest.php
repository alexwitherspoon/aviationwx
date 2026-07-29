<?php

declare(strict_types=1);

use AviationWX\Notam\Airspace\Adapter\NmsFdcAirspaceAdapter;
use AviationWX\Notam\Airspace\UnifiedNotamFetcher;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../lib/notam/map-aggregate-cache.php';
require_once __DIR__ . '/../../lib/notam/map-layer-cache.php';
require_once __DIR__ . '/../../lib/notam/airspace/UnifiedNotamFetcher.php';

/**
 * NMS FDC airspace bulk ingest (#247).
 */
final class NmsFdcAirspaceBulkTest extends TestCase
{
    private string $cacheDir = '';

    protected function setUp(): void
    {
        $this->cacheDir = sys_get_temp_dir() . '/aviationwx-fdc-bulk-' . bin2hex(random_bytes(4));
        mkdir($this->cacheDir, 0755, true);
        $GLOBALS['notamCacheTestDirectory'] = $this->cacheDir;
    }

    protected function tearDown(): void
    {
        unset($GLOBALS['notamCacheTestDirectory'], $GLOBALS['nmsFdcAirspaceHttpHandler']);
        foreach (scandir($this->cacheDir) ?: [] as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            @unlink($this->cacheDir . '/' . $item);
        }
        @rmdir($this->cacheDir);
    }

    private function fixtureBody(): string
    {
        $raw = file_get_contents(__DIR__ . '/../Fixtures/notam/nms-fdc-airspace-sample.json');
        if ($raw === false) {
            self::fail('Missing FDC fixture');
        }

        return $raw;
    }

    public function testBuildFdcQueryParams_UsesClassificationAndAirspaceFeature(): void
    {
        $params = notamBuildFdcAirspaceQueryParams();
        $this->assertSame('FDC', $params['classification'] ?? null);
        $this->assertSame(NOTAM_GEO_QUERY_FEATURE, $params['feature'] ?? null);
    }

    public function testAdapter_ParsesNmsEnvelope_EmitsDrawableNmsRecords(): void
    {
        $records = NmsFdcAirspaceAdapter::parseResponse($this->fixtureBody());
        $this->assertNotNull($records);
        $this->assertNotEmpty($records);

        foreach ($records as $record) {
            $this->assertTrue($record['capabilities']['map']);
            $this->assertSame(NOTAM_AIRSPACE_SOURCE_NMS, $record['field_sources']['geometry'] ?? null);
            $this->assertContains(NmsFdcAirspaceAdapter::SOURCE_TYPE, $record['record_sources']);
            $this->assertSame(NmsFdcAirspaceAdapter::SOURCE_TYPE, $record['ingest_path'] ?? null);
        }

        $ids = array_map(static fn (array $r): string => (string) $r['notam_id'], $records);
        $this->assertContains('F9001/2026', $ids);
        $this->assertContains('F9100/2026', $ids);
    }

    public function testUnifiedFetch_MergesIntoStore_AndFailureRetainsPrior(): void
    {
        $GLOBALS['nmsFdcAirspaceHttpHandler'] = function (): array {
            return [
                'ok' => true,
                'body' => $this->fixtureBody(),
                'http_code' => 200,
                'error' => '',
            ];
        };

        $result = UnifiedNotamFetcher::fetchSource(NmsFdcAirspaceAdapter::SOURCE_TYPE);
        $this->assertTrue($result['ok']);
        $this->assertTrue(notamMapAirspaceAggregateMergeRecords(
            $result['records'],
            NmsFdcAirspaceAdapter::SOURCE_TYPE
        ));

        $envelope = notamMapAirspaceAggregateRead();
        $this->assertNotNull($envelope);
        $this->assertTrue($envelope['source_status']['nms_fdc_bulk']['ok'] ?? false);
        $this->assertArrayHasKey('N:9001', $envelope['records']);
        $countAfterOk = count($envelope['records']);

        $payload = notamTfrMapLayerServeOrRebuild();
        $this->assertFalse($payload['failclosed'] ?? false);
        $this->assertGreaterThanOrEqual(1, count($payload['features']));

        $GLOBALS['nmsFdcAirspaceHttpHandler'] = static function (): array {
            return ['ok' => false, 'body' => '', 'http_code' => 503, 'error' => 'HTTP 503'];
        };
        $failed = UnifiedNotamFetcher::fetchSource(NmsFdcAirspaceAdapter::SOURCE_TYPE);
        $this->assertFalse($failed['ok']);
        notamMapAirspaceAggregateMarkSourceStatus(NmsFdcAirspaceAdapter::SOURCE_TYPE, false, $failed['error']);

        $afterFail = notamMapAirspaceAggregateRead();
        $this->assertNotNull($afterFail);
        $this->assertFalse($afterFail['source_status']['nms_fdc_bulk']['ok'] ?? true);
        $this->assertCount($countAfterOk, $afterFail['records']);

        $payload2 = notamTfrMapLayerServeOrRebuild();
        $this->assertFalse($payload2['failclosed'] ?? false);
        $this->assertGreaterThanOrEqual(1, count($payload2['features']));
    }

    public function testSchedulerRegistersFdcBulkTick(): void
    {
        $scheduler = file_get_contents(dirname(__DIR__, 2) . '/scripts/scheduler.php');
        $this->assertIsString($scheduler);
        $this->assertStringContainsString("registerEnqueueTick('nms_fdc_airspace'", $scheduler);
        $this->assertStringContainsString('fetch-nms-fdc-airspace.php', $scheduler);
    }
}
