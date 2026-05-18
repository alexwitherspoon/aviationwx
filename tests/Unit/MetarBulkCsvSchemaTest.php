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
    public function testFixtureHeaderLineMatchesPhpCanonical(): void
    {
        require_once __DIR__ . '/../../lib/metar-bulk-csv-schema.php';

        $fixturePath = __DIR__ . '/../Fixtures/metar-bulk-csv-header-line.txt';
        $this->assertFileExists($fixturePath);
        $line = trim((string) file_get_contents($fixturePath));
        $expected = implode(',', metar_bulk_csv_expected_header_columns());
        $this->assertSame($expected, $line);
    }

    public function testGoldenCsvHeaderRowMatchesExpected(): void
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

    public function testHeaderWithUtf8BomStillMatches(): void
    {
        require_once __DIR__ . '/../../lib/metar-bulk-csv-schema.php';

        $header = metar_bulk_csv_expected_header_columns();
        $header[0] = "\xEF\xBB\xBF" . $header[0];
        $this->assertTrue(metar_bulk_csv_header_matches_expected($header));
    }

    public function testIngestRejectsMismatchedHeaderSchema(): void
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
    }
}
