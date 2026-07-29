<?php

declare(strict_types=1);

use AviationWX\Notam\Airspace\Adapter\FaaTfrWfsAdapter;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../lib/notam/map-aggregate-cache.php';
require_once __DIR__ . '/../../lib/notam/airspace/consumers.php';
require_once __DIR__ . '/../../lib/notam/airspace/adapter/FaaTfrWfsAdapter.php';

/**
 * Unified store banner / runway consumers (#250).
 */
final class AirspaceStoreConsumersTest extends TestCase
{
    private string $cacheDir = '';

    protected function setUp(): void
    {
        $this->cacheDir = sys_get_temp_dir() . '/aviationwx-airspace-consumers-' . bin2hex(random_bytes(4));
        mkdir($this->cacheDir, 0755, true);
        $GLOBALS['notamCacheTestDirectory'] = $this->cacheDir;
    }

    protected function tearDown(): void
    {
        unset($GLOBALS['notamCacheTestDirectory']);
        foreach (scandir($this->cacheDir) ?: [] as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            @unlink($this->cacheDir . '/' . $item);
        }
        @rmdir($this->cacheDir);
    }

    /**
     * @return array<string, mixed>
     */
    private function airportNearOgden(): array
    {
        return [
            'lat' => 41.195,
            'lon' => -112.012,
            'timezone' => 'UTC',
            'icao' => 'KOGD',
            'faa' => 'OGD',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function airportFarAway(): array
    {
        return [
            'lat' => 47.52,
            'lon' => -116.08,
            'timezone' => 'UTC',
            'icao' => 'KS83',
            'faa' => 'S83',
        ];
    }

    private function writeStore(array $records, int $now): void
    {
        notamMapAirspaceAggregateWrite([
            'schema_version' => NOTAM_MAP_AIRSPACE_SCHEMA_VERSION,
            'merge_logic_version' => NOTAM_AIRSPACE_MERGE_LOGIC_VERSION,
            'data_updated_at' => $now,
            'updated_at' => $now,
            'coverage_sources' => ['nms', 'faa_tfr_wfs'],
            'source_status' => [
                'nms' => ['ok' => true, 'updated_at' => $now],
                'faa_tfr_wfs' => ['ok' => true, 'updated_at' => $now],
            ],
            'records' => $records,
            'map_layer_build_token' => 'merge-v' . NOTAM_AIRSPACE_MERGE_LOGIC_VERSION,
        ]);
    }

    public function testBannerCapableStoreRow_RelevantToAirport_IsReturned(): void
    {
        $now = time();
        $notam = [
            'id' => 'F9200/2026',
            'text' => 'ZLC UT..AIRSPACE OGDEN, UT..TEMPORARY FLIGHT RESTRICTIONS '
                . 'WITHIN AN AREA DEFINED AS 5NM RADIUS OF 411140N1120043W (OGD) TEST.',
            'start_time_utc' => gmdate('Y-m-d\TH:i:s\Z', $now - 3600),
            'end_time_utc' => gmdate('Y-m-d\TH:i:s\Z', $now + 7200),
        ];
        $record = notamAirspaceRecordFromNotam($notam, 'ogd', 'UTC');
        $this->assertNotNull($record);
        $this->assertTrue($record['capabilities']['banner']);

        $this->writeStore(['N:9200' => $record], $now);

        $rows = notamAirspaceStoreRelevantNotamsForAirport($this->airportNearOgden(), 'banner', $now);
        $this->assertNotEmpty($rows);
        $this->assertSame('F9200/2026', $rows[0]['id'] ?? null);
    }

    public function testWfsOnlyRow_NeverReturnedForBanner(): void
    {
        $now = time();
        $fixture = file_get_contents(__DIR__ . '/../Fixtures/notam/faa-tfr-wfs-sample.json');
        $this->assertNotFalse($fixture);
        $wfsRecords = FaaTfrWfsAdapter::parseResponse($fixture);
        $this->assertNotNull($wfsRecords);

        $byKey = [];
        foreach ($wfsRecords as $record) {
            $key = (string) ($record['norm_number'] ?? $record['notam_id']);
            $byKey[$key] = $record;
            $this->assertFalse($record['capabilities']['banner']);
        }
        $this->writeStore($byKey, $now);

        $rows = notamAirspaceStoreRelevantNotamsForAirport($this->airportNearOgden(), 'banner', $now);
        $this->assertSame([], $rows);
    }

    public function testDistantStoreTfr_NotRelevantToUnrelatedAirport(): void
    {
        $now = time();
        $notam = [
            'id' => 'F9201/2026',
            'text' => 'ZLC UT..AIRSPACE OGDEN, UT..TEMPORARY FLIGHT RESTRICTIONS '
                . 'WITHIN AN AREA DEFINED AS 5NM RADIUS OF 411140N1120043W (OGD) TEST.',
            'start_time_utc' => gmdate('Y-m-d\TH:i:s\Z', $now - 3600),
            'end_time_utc' => gmdate('Y-m-d\TH:i:s\Z', $now + 7200),
        ];
        $record = notamAirspaceRecordFromNotam($notam, 'ogd', 'UTC');
        $this->assertNotNull($record);
        $this->writeStore(['N:9201' => $record], $now);

        $rows = notamAirspaceStoreRelevantNotamsForAirport($this->airportFarAway(), 'banner', $now);
        $this->assertSame([], $rows);
    }

    public function testRunwayClosureCapability_RequiredForClosureConsumer(): void
    {
        $now = time();
        $tfr = [
            'id' => 'F9202/2026',
            'text' => 'ZLC UT..AIRSPACE OGDEN, UT..TEMPORARY FLIGHT RESTRICTIONS '
                . 'WITHIN AN AREA DEFINED AS 5NM RADIUS OF 411140N1120043W (OGD) TEST.',
            'start_time_utc' => gmdate('Y-m-d\TH:i:s\Z', $now - 3600),
            'end_time_utc' => gmdate('Y-m-d\TH:i:s\Z', $now + 7200),
        ];
        $record = notamAirspaceRecordFromNotam($tfr, 'ogd', 'UTC');
        $this->assertNotNull($record);
        $this->assertFalse($record['capabilities']['runway_closure']);
        $this->writeStore(['N:9202' => $record], $now);

        $rows = notamAirspaceStoreRelevantNotamsForAirport($this->airportNearOgden(), 'runway_closure', $now);
        $this->assertSame([], $rows);
    }

    public function testMergeDedupsAirportAndStoreRows(): void
    {
        $a = ['id' => 'A1/2026', 'text' => 'one'];
        $b = ['id' => 'A1/2026', 'text' => 'one richer'];
        $merged = notamMergeAirportAndStoreNotamRows([$a], [$b]);
        $this->assertCount(1, $merged);
    }
}
