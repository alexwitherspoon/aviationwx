<?php

declare(strict_types=1);

namespace AviationWX\Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Unit tests for `lib/metar-bulk.php` (CSV mapping, gzip ingest, slice age gate).
 */
final class MetarBulkTest extends TestCase
{
    public function testRefreshMetarBulkScript_OnDisk_FileExists(): void
    {
        $this->assertFileExists(__DIR__ . '/../../scripts/refresh-metar-bulk.php');
    }

    public function testMetarBulkShouldUseNationalBulk_EnabledAirportCount_ReturnsExpectedThreshold(): void
    {
        require_once __DIR__ . '/../../lib/config.php';
        require_once __DIR__ . '/../../lib/metar-bulk.php';

        $one = [
            'airports' => [
                'kaaa' => ['enabled' => true, 'icao' => 'KAAA', 'weather_sources' => [['type' => 'metar', 'station_id' => 'KAAA']]],
            ],
        ];
        $this->assertSame(1, metarBulkCountEnabledAirports($one));
        $this->assertFalse(metarBulkShouldUseNationalBulk($one));

        $two = [
            'airports' => [
                'kaaa' => ['enabled' => true, 'icao' => 'KAAA', 'weather_sources' => [['type' => 'metar', 'station_id' => 'KAAA']]],
                'kbbb' => ['enabled' => true, 'icao' => 'KBBB', 'weather_sources' => [['type' => 'metar', 'station_id' => 'KBBB']]],
            ],
        ];
        $this->assertSame(2, metarBulkCountEnabledAirports($two));
        $this->assertTrue(metarBulkShouldUseNationalBulk($two));

        $oneEnabledOneDisabled = [
            'airports' => [
                'kaaa' => ['enabled' => true, 'icao' => 'KAAA', 'weather_sources' => [['type' => 'metar', 'station_id' => 'KAAA']]],
                'kbbb' => ['enabled' => false, 'icao' => 'KBBB', 'weather_sources' => [['type' => 'metar', 'station_id' => 'KBBB']]],
            ],
        ];
        $this->assertSame(1, metarBulkCountEnabledAirports($oneEnabledOneDisabled));
        $this->assertFalse(metarBulkShouldUseNationalBulk($oneEnabledOneDisabled));
    }

    public function testMetarBulkCollectConfiguredStationIds_DisabledAirport_ExcludedFromSet(): void
    {
        require_once __DIR__ . '/../../lib/config.php';
        require_once __DIR__ . '/../../lib/metar-bulk.php';

        $config = [
            'airports' => [
                'off' => [
                    'enabled' => false,
                    'icao' => 'KOFF',
                    'weather_sources' => [['type' => 'metar', 'station_id' => 'KOFF']],
                ],
                'on' => [
                    'enabled' => true,
                    'icao' => 'KONN',
                    'weather_sources' => [['type' => 'metar', 'station_id' => 'KONN']],
                ],
            ],
        ];
        $ids = metarBulkCollectConfiguredStationIds($config);
        $this->assertArrayHasKey('KONN', $ids);
        $this->assertArrayNotHasKey('KOFF', $ids);
    }

    public function testMetarBulkSanitizeIcaoForFilename_ValidAndInvalid_ReturnsExpected(): void
    {
        require_once __DIR__ . '/../../lib/metar-bulk.php';
        $this->assertSame('KPDX', metarBulkSanitizeIcaoForFilename('kpdx'));
        $this->assertNull(metarBulkSanitizeIcaoForFilename('../etc'));
        $this->assertNull(metarBulkSanitizeIcaoForFilename('AB'));
    }

    public function testMetarBulkParseObservationTime_ValidIsoAndEmpty_ReturnsUnixOrMin(): void
    {
        require_once __DIR__ . '/../../lib/metar-bulk.php';
        $this->assertSame(
            strtotime('2026-05-18T15:00:00.000Z'),
            metarBulkParseObservationTime('2026-05-18T15:00:00.000Z')
        );
        $this->assertSame(PHP_INT_MIN, metarBulkParseObservationTime(''));
    }

    public function testMetarBulkCsvRowToApiRecord_WindGustKt_MapsToWgstForParseFallback(): void
    {
        require_once __DIR__ . '/../../lib/metar-bulk.php';
        require_once __DIR__ . '/../../lib/weather/adapter/metar-v1.php';

        $row = array_fill(0, 44, '');
        $row[0] = 'METAR KZZZ 181500Z AUTO 18015KT 10SM CLR 15/10 A2990';
        $row[1] = 'KZZZ';
        $row[2] = '2026-05-18T15:00:00.000Z';
        $row[7] = '180';
        $row[8] = '15';
        $row[9] = '22';

        $json = metarBulkCsvRowToJsonEnvelope($row);
        $this->assertNotNull($json);
        $parsed = parseMETARResponse((string) $json, ['icao' => 'KZZZ']);
        $this->assertNotNull($parsed);
        $this->assertSame(22, $parsed['gust_speed']);
    }

    public function testMetarBulkCsvRowToApiRecord_PrecipColumns_MapToDistinctFields(): void
    {
        require_once __DIR__ . '/../../lib/metar-bulk.php';

        $row = array_fill(0, 44, '');
        $row[0] = 'METAR KZZZ 181500Z AUTO 09007KT 10SM CLR 15/10 A2990';
        $row[1] = 'KZZZ';
        $row[2] = '2026-05-18T15:00:00.000Z';
        $row[36] = '0.10';
        $row[39] = '0.25';

        $rec = metarBulkCsvRowToApiRecord($row, metarBulkGetDefaultCsvColumnLists());
        $this->assertIsArray($rec);
        $this->assertEqualsWithDelta(0.25, (float) $rec['pcp24hr'], 0.001);
        $this->assertEqualsWithDelta(0.10, (float) $rec['precip'], 0.001);
    }

    public function testMetarBulkCsvRowToJsonEnvelope_SampleRow_ParseMetarResponseSucceeds(): void
    {
        require_once __DIR__ . '/../../lib/metar-bulk.php';
        require_once __DIR__ . '/../../lib/weather/adapter/metar-v1.php';

        $row = array_fill(0, 44, '');
        $row[0] = 'METAR KZZZ 181448Z 16011G17KT 10SM SCT060 BKN070 25/25 A2993';
        $row[1] = 'KZZZ';
        $row[2] = '2026-05-18T14:48:00.000Z';
        $row[5] = '25';
        $row[6] = '25';
        $row[7] = '160';
        $row[8] = '11';
        $row[9] = '17';
        $row[10] = '10+';
        $row[11] = '29.93';
        $row[22] = 'SCT';
        $row[23] = '6000';
        $row[24] = 'BKN';
        $row[25] = '7000';

        $json = metarBulkCsvRowToJsonEnvelope($row);
        $this->assertNotNull($json);
        $airport = ['icao' => 'KZZZ'];
        $parsed = parseMETARResponse((string) $json, $airport);
        $this->assertNotNull($parsed);
        $this->assertEqualsWithDelta(25.0, (float) $parsed['temperature'], 0.001);
        $this->assertSame(11, $parsed['wind_speed']);
        $this->assertSame(17, $parsed['gust_speed']);
        $this->assertStringContainsString('KZZZ', (string) $parsed['raw_ob']);
    }

    public function testMetarBulkIngestGzipToStationFiles_DuplicateStation_KeepsLatestRow(): void
    {
        require_once __DIR__ . '/../../lib/cache-paths.php';
        require_once __DIR__ . '/../../lib/metar-bulk.php';

        metarBulkEnsureDirectories();
        $stationsDir = getMetarBulkStationsDir();
        $oldFile = $stationsDir . '/KZZZ.json';
        if (is_file($oldFile)) {
            @unlink($oldFile);
        }

        $goldenPath = __DIR__ . '/../Fixtures/metar-bulk-golden.csv';
        $this->assertFileExists($goldenPath);
        $csvBody = (string) file_get_contents($goldenPath);
        $gzPath = sys_get_temp_dir() . '/metar_bulk_test_' . uniqid('', true) . '.gz';
        $gzData = gzencode($csvBody, 9);
        $this->assertNotFalse($gzData);
        file_put_contents($gzPath, $gzData);

        $stats = metarBulkIngestGzipToStationFiles($gzPath, ['KZZZ' => true]);
        @unlink($gzPath);

        $this->assertArrayNotHasKey('error', $stats);
        $this->assertSame(1, $stats['written']);
        $this->assertSame(2, $stats['scanned']);

        $written = (string) file_get_contents($oldFile);
        $this->assertStringContainsString('181500Z', $written);
        $this->assertStringNotContainsString('181200Z', $written);
        @unlink($oldFile);
    }

    public function testMetarBulkIngestGzipToStationFiles_ShortRows_IncrementsSkippedCount(): void
    {
        require_once __DIR__ . '/../../lib/cache-paths.php';
        require_once __DIR__ . '/../../lib/metar-bulk-csv-schema.php';
        require_once __DIR__ . '/../../lib/metar-bulk.php';

        $header = implode(',', metar_bulk_csv_expected_header_columns());
        $good = array_fill(0, 44, '');
        $good[0] = 'METAR KZZZ 181500Z AUTO 18015KT 10SM CLR 15/10 A2990';
        $good[1] = 'KZZZ';
        $good[2] = '2026-05-18T15:00:00.000Z';
        $fh = fopen('php://memory', 'r+b');
        $this->assertNotFalse($fh);
        fputcsv($fh, $good, ',', '"', '\\');
        rewind($fh);
        $goodLine = rtrim((string) stream_get_contents($fh), "\r\n");
        fclose($fh);

        $csvBody = $header . "\n"
            . 'too,few,columns' . "\n"
            . $goodLine . "\n";
        $gzPath = sys_get_temp_dir() . '/metar_bulk_short_' . uniqid('', true) . '.gz';
        file_put_contents($gzPath, gzencode($csvBody, 9));

        metarBulkEnsureDirectories();
        $outFile = getMetarBulkStationsDir() . '/KZZZ.json';
        if (is_file($outFile)) {
            @unlink($outFile);
        }

        $stats = metarBulkIngestGzipToStationFiles($gzPath, ['KZZZ' => true]);
        @unlink($gzPath);
        if (is_file($outFile)) {
            @unlink($outFile);
        }

        $this->assertArrayNotHasKey('error', $stats);
        $this->assertSame(1, $stats['skipped_short_rows']);
        $this->assertSame(1, $stats['written']);
    }

    public function testMetarBulkRefreshRun_TestMode_SkipsNetwork(): void
    {
        require_once __DIR__ . '/../../lib/metar-bulk.php';

        $result = metarBulkRefreshRun();
        $this->assertTrue($result['ok']);
        $this->assertSame('skipped_mock_or_test_mode', $result['note'] ?? null);
    }

    public function testMetarBulkTryReadJsonResponseForStation_StaleMtime_ReturnsNull(): void
    {
        require_once __DIR__ . '/../../lib/cache-paths.php';
        require_once __DIR__ . '/../../lib/constants.php';
        require_once __DIR__ . '/../../lib/metar-bulk.php';

        metarBulkEnsureDirectories();
        $path = getMetarBulkStationJsonPath('KZZZ');
        if (is_file($path)) {
            @unlink($path);
        }
        file_put_contents($path, '[]');
        touch($path, time() - METAR_BULK_STATION_FILE_MAX_AGE_SECONDS - 60);

        $this->assertNull(metarBulkTryReadJsonResponseForStation('KZZZ'));

        touch($path, time());
        $body = metarBulkTryReadJsonResponseForStation('KZZZ');
        $this->assertSame('[]', $body);
        @unlink($path);
    }
}
