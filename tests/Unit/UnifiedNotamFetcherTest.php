<?php

declare(strict_types=1);

use AviationWX\Notam\Airspace\Adapter\FaaTfrWfsAdapter;
use AviationWX\Notam\Airspace\Adapter\NmsAixmAdapter;
use AviationWX\Notam\Airspace\Adapter\NotamSourceAdapter;
use AviationWX\Notam\Airspace\AirspaceAggregator;
use AviationWX\Notam\Airspace\UnifiedNotamFetcher;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../lib/notam/map-aggregate-cache.php';
require_once __DIR__ . '/../../lib/notam/airspace/UnifiedNotamFetcher.php';
require_once __DIR__ . '/../../lib/notam/airspace/adapter/NmsAixmAdapter.php';

/**
 * UnifiedNotamFetcher + NotamSourceAdapter platform (#248).
 */
final class UnifiedNotamFetcherTest extends TestCase
{
    public function testAdapterMap_IncludesNmsAndWfs(): void
    {
        $map = UnifiedNotamFetcher::adapterMap();
        $this->assertArrayHasKey('nms', $map);
        $this->assertArrayHasKey('faa_tfr_wfs', $map);
        $this->assertArrayHasKey('nms_fdc_bulk', $map);
        $this->assertTrue(is_a($map['nms'], NotamSourceAdapter::class, true));
        $this->assertTrue(is_a($map['faa_tfr_wfs'], NotamSourceAdapter::class, true));
        $this->assertTrue(is_a($map['nms_fdc_bulk'], NotamSourceAdapter::class, true));
    }

    public function testFetchSource_UnknownType_FailsClosed(): void
    {
        $result = UnifiedNotamFetcher::fetchSource('not-a-real-source');
        $this->assertFalse($result['ok']);
        $this->assertSame([], $result['records']);
        $this->assertStringContainsString('Unknown', $result['error']);
    }

    public function testFetchSource_Wfs_UsesAdapterHttpHandler(): void
    {
        $fixture = file_get_contents(__DIR__ . '/../Fixtures/notam/faa-tfr-wfs-sample.json');
        $this->assertNotFalse($fixture);
        $GLOBALS['faaTfrWfsHttpHandler'] = static function (string $url) use ($fixture): array {
            return ['ok' => true, 'body' => $fixture, 'http_code' => 200, 'error' => ''];
        };

        try {
            $result = UnifiedNotamFetcher::fetchSource(FaaTfrWfsAdapter::SOURCE_TYPE, [
                'persist_raw' => false,
            ]);
            $this->assertTrue($result['ok']);
            $this->assertNotEmpty($result['records']);
            $this->assertSame('faa_tfr_wfs', $result['source']);
        } finally {
            unset($GLOBALS['faaTfrWfsHttpHandler']);
        }
    }

    public function testRecordsFromNmsNotams_MatchesDirectRecordBuilder(): void
    {
        $now = time();
        $notam = [
            'id' => 'A5100/2026',
            'text' => 'ZLC UT..AIRSPACE OGDEN, UT..TEMPORARY FLIGHT RESTRICTIONS '
                . 'WITHIN AN AREA DEFINED AS 5NM RADIUS OF 413900N1122300W (OGD319029) TEST.',
            'start_time_utc' => gmdate('Y-m-d\TH:i:s\Z', $now - 3600),
            'end_time_utc' => gmdate('Y-m-d\TH:i:s\Z', $now + 7200),
        ];

        $viaUnified = UnifiedNotamFetcher::recordsFromNmsNotams([$notam], [
            'airport_id' => 's83',
            'timezone' => 'UTC',
        ]);
        $direct = notamAirspaceRecordFromNotam($notam, 's83', 'UTC');

        $this->assertCount(1, $viaUnified);
        $this->assertNotNull($direct);
        $this->assertSame($direct['notam_id'], $viaUnified[0]['notam_id']);
        $this->assertSame($direct['norm_number'], $viaUnified[0]['norm_number']);
        $this->assertSame(NmsAixmAdapter::SOURCE_TYPE, $viaUnified[0]['record_sources'][0] ?? null);
    }

    public function testMerge_ThroughUnifiedParse_PreservesFieldSources(): void
    {
        $fixture = file_get_contents(__DIR__ . '/../Fixtures/notam/faa-tfr-wfs-sample.json');
        $this->assertNotFalse($fixture);
        $wfs = UnifiedNotamFetcher::parseSource(FaaTfrWfsAdapter::SOURCE_TYPE, $fixture);
        $this->assertNotNull($wfs);

        $now = time();
        $nmsRows = UnifiedNotamFetcher::recordsFromNmsNotams([
            [
                'id' => 'F0543/2026',
                'text' => 'ZDC DC..AIRSPACE WASHINGTON, DC..TEMPORARY FLIGHT RESTRICTIONS '
                    . 'WITHIN AN AREA DEFINED AS 5NM RADIUS OF 385200N0770300W SECURITY.',
                'start_time_utc' => gmdate('Y-m-d\TH:i:s\Z', $now - 3600),
                'end_time_utc' => gmdate('Y-m-d\TH:i:s\Z', $now + 7200),
            ],
        ], ['airport_id' => 'dca', 'timezone' => 'UTC']);

        $merged = AirspaceAggregator::merge(array_merge($nmsRows, $wfs));
        $this->assertArrayHasKey('N:543', $merged);
        $row = $merged['N:543'];
        $this->assertSame(FaaTfrWfsAdapter::SOURCE_TYPE, $row['field_sources']['geometry'] ?? null);
        $this->assertSame(NOTAM_AIRSPACE_SOURCE_NMS, $row['field_sources']['text'] ?? null);
        $this->assertTrue($row['capabilities']['map']);
        $this->assertTrue($row['capabilities']['banner']);
    }
}
