<?php

declare(strict_types=1);

namespace AviationWX\Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Contract tests for AWC `metars.cache.csv.gz` header layout (`lib/metar-bulk-csv-schema.php`).
 *
 * When AWC changes the public CSV schema, update `lib/metar-bulk-csv-schema.php` and
 * `tests/Fixtures/metar-bulk-csv-header-line.txt` together so these tests stay aligned.
 */
final class MetarBulkCsvSchemaTest extends TestCase
{
    public function testMetarBulkCsvSchema_FixtureHeaderLine_MatchesCanonicalList(): void
    {
        require_once __DIR__ . '/../../lib/metar-bulk-csv-schema.php';

        $fixturePath = __DIR__ . '/../Fixtures/metar-bulk-csv-header-line.txt';
        $this->assertFileExists($fixturePath);
        $line = trim((string) file_get_contents($fixturePath));
        $expected = implode(',', metar_bulk_csv_expected_header_columns());
        $this->assertSame($expected, $line);
    }

    public function testMetarBulkCsvSchema_GoldenCsvFirstRow_MatchesExpected(): void
    {
        require_once __DIR__ . '/../../lib/metar-bulk-csv-schema.php';

        $path = __DIR__ . '/../Fixtures/metar-bulk-golden.csv';
        $this->assertFileExists($path);
        $fh = fopen($path, 'rb');
        $this->assertNotFalse($fh);
        $header = fgetcsv($fh, 0, ',', '"', '\\');
        fclose($fh);
        $this->assertIsArray($header);
        $this->assertTrue(metar_bulk_csv_header_matches_expected($header));
    }

    public function testMetarBulkCsvSchema_HeaderWithUtf8Bom_MatchesExpected(): void
    {
        require_once __DIR__ . '/../../lib/metar-bulk-csv-schema.php';

        $header = metar_bulk_csv_expected_header_columns();
        $header[0] = "\xEF\xBB\xBF" . $header[0];
        $this->assertTrue(metar_bulk_csv_header_matches_expected($header));
    }

    public function testMetarBulkIngestGzipToStationFiles_WrongHeader_ReturnsBadCsvHeaderSchema(): void
    {
        require_once __DIR__ . '/../../lib/cache-paths.php';
        require_once __DIR__ . '/../../lib/metar-bulk.php';

        $badHeader = 'station_id,raw_text';
        $row = 'KZZZ,"METAR KZZZ 181500Z AUTO"';
        $csvBody = $badHeader . "\n" . $row . "\n";
        $gzPath = sys_get_temp_dir() . '/metar_bulk_schema_bad_' . uniqid('', true) . '.gz';
        $gzData = gzencode($csvBody, 9);
        $this->assertNotFalse($gzData);
        file_put_contents($gzPath, $gzData);

        $stats = metarBulkIngestGzipToStationFiles($gzPath, ['KZZZ' => true]);
        @unlink($gzPath);

        $this->assertSame('bad_csv_header_schema', $stats['error'] ?? null);
        $this->assertSame(0, $stats['written']);
        $this->assertArrayHasKey('header_mismatch', $stats);
        $this->assertStringContainsString('column_count', (string) $stats['header_mismatch']);
    }

    public function testMetarBulkCsvDescribeHeaderMismatch_WrongFirstColumn_ReportsColumnDiff(): void
    {
        require_once __DIR__ . '/../../lib/metar-bulk-csv-schema.php';

        $header = metar_bulk_csv_expected_header_columns();
        $header[0] = 'station_id';
        $summary = metar_bulk_csv_describe_header_mismatch($header);
        $this->assertStringContainsString('col0:station_id!=raw_text', $summary);
    }

    public function testMetarBulkIngestGzipToStationFiles_Utf8BomHeader_IngestsGoldenRows(): void
    {
        require_once __DIR__ . '/../../lib/cache-paths.php';
        require_once __DIR__ . '/../../lib/metar-bulk.php';

        $goldenPath = __DIR__ . '/../Fixtures/metar-bulk-golden.csv';
        $csvBody = "\xEF\xBB\xBF" . (string) file_get_contents($goldenPath);
        $gzPath = sys_get_temp_dir() . '/metar_bulk_bom_' . uniqid('', true) . '.gz';
        file_put_contents($gzPath, gzencode($csvBody, 9));

        metarBulkEnsureDirectories();
        $outFile = getMetarBulkStationsDir() . '/KZZZ.json';
        if (is_file($outFile)) {
            @unlink($outFile);
        }

        $stats = metarBulkIngestGzipToStationFiles($gzPath, ['KZZZ' => true]);
        @unlink($gzPath);

        $this->assertArrayNotHasKey('error', $stats);
        $this->assertSame(1, $stats['written']);
        $written = (string) file_get_contents($outFile);
        $this->assertStringContainsString('"wgst":22', $written);
        $this->assertStringContainsString('"pcp24hr":0.25', $written);
        $this->assertStringContainsString('"precip":0.1', $written); // JSON encodes 0.10 as 0.1
        @unlink($outFile);
    }
}
