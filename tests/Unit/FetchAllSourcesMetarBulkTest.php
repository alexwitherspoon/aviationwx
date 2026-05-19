<?php

declare(strict_types=1);

namespace AviationWX\Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * UnifiedFetcher METAR path uses bulk/mock resolution instead of curl_multi.
 */
final class FetchAllSourcesMetarBulkTest extends TestCase
{
    protected function tearDown(): void
    {
        require_once __DIR__ . '/../../lib/test-mocks.php';
        metarBulkTestSetSkipMetarHttpMock(false);

        parent::tearDown();
    }

    public function testFetchAllSources_MetarSource_UsesBulkSliceWithoutCurl(): void
    {
        require_once __DIR__ . '/../../lib/cache-paths.php';
        require_once __DIR__ . '/../../lib/config.php';
        require_once __DIR__ . '/../../lib/metar-bulk.php';
        require_once __DIR__ . '/../../lib/test-mocks.php';
        require_once __DIR__ . '/../../lib/weather/adapter/metar-v1.php';
        require_once __DIR__ . '/../../lib/weather/UnifiedFetcher.php';

        metarBulkTestSetSkipMetarHttpMock(true);
        metarBulkEnsureDirectories();
        $path = getMetarBulkStationJsonPath('KZZZ');
        if (is_file($path)) {
            @unlink($path);
        }

        $row = array_fill(0, 44, '');
        $row[0] = 'METAR KZZZ 181500Z AUTO 09007KT 10SM CLR 42/40 A3000';
        $row[1] = 'KZZZ';
        $row[2] = '2026-05-18T15:00:00.000Z';
        $row[5] = '42';
        $json = metarBulkCsvRowToJsonEnvelope($row);
        $this->assertNotNull($json);
        file_put_contents($path, $json);
        touch($path, time());

        $sources = [
            'source_0' => [
                'type' => 'metar',
                'station_id' => 'KZZZ',
            ],
        ];

        $responses = fetchAllSources($sources, 'kzzz');
        @unlink($path);

        $this->assertArrayHasKey('source_0', $responses);
        $this->assertIsString($responses['source_0']);
        $this->assertStringContainsString('"temp":42', $responses['source_0']);
    }
}
