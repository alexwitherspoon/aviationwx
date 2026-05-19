<?php

declare(strict_types=1);

namespace AviationWX\Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * METAR station response resolution: `metarResolveStationResponseBody()` and `fetchMETARFromStation()`.
 */
final class FetchMetarFromStationBulkTest extends TestCase
{
    protected function tearDown(): void
    {
        require_once __DIR__ . '/../../lib/test-mocks.php';
        metarBulkTestSetSkipMetarHttpMock(false);

        parent::tearDown();
    }

    public function testMetarResolveStationResponseBody_FreshBulkSlice_SkipHttpMock_ReturnsSliceJson(): void
    {
        require_once __DIR__ . '/../../lib/cache-paths.php';
        require_once __DIR__ . '/../../lib/config.php';
        require_once __DIR__ . '/../../lib/metar-bulk.php';
        require_once __DIR__ . '/../../lib/test-mocks.php';
        require_once __DIR__ . '/../../lib/weather/adapter/metar-v1.php';

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

        $body = metarResolveStationResponseBody('KZZZ');
        @unlink($path);

        $this->assertNotNull($body);
        $this->assertStringContainsString('"temp":42', $body);
    }

    public function testMetarResolveStationResponseBody_NoBulkSlice_UsesHttpMock(): void
    {
        require_once __DIR__ . '/../../lib/cache-paths.php';
        require_once __DIR__ . '/../../lib/metar-bulk.php';
        require_once __DIR__ . '/../../lib/test-mocks.php';
        require_once __DIR__ . '/../../lib/weather/adapter/metar-v1.php';

        metarBulkTestSetSkipMetarHttpMock(false);
        metarBulkEnsureDirectories();
        $path = getMetarBulkStationJsonPath('KSPB');
        if (is_file($path)) {
            @unlink($path);
        }

        $body = metarResolveStationResponseBody('KSPB');
        $this->assertNotNull($body);
        $this->assertStringContainsString('KSPB', $body);
    }

    public function testFetchMETARFromStation_FreshBulkSlice_SkipHttpMock_UsesSliceTemperature(): void
    {
        require_once __DIR__ . '/../../lib/cache-paths.php';
        require_once __DIR__ . '/../../lib/config.php';
        require_once __DIR__ . '/../../lib/metar-bulk.php';
        require_once __DIR__ . '/../../lib/test-mocks.php';
        require_once __DIR__ . '/../../lib/weather/adapter/metar-v1.php';

        $this->assertTrue(metarBulkShouldUseNationalBulk());

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
        $row[6] = '40';
        $row[7] = '90';
        $row[8] = '7';
        $row[10] = '10';
        $row[11] = '30.00';

        $json = metarBulkCsvRowToJsonEnvelope($row);
        $this->assertNotNull($json);
        file_put_contents($path, $json);
        touch($path, time());

        $parsed = fetchMETARFromStation('KZZZ', ['icao' => 'KZZZ']);
        @unlink($path);

        $this->assertIsArray($parsed);
        $this->assertEqualsWithDelta(42.0, (float) $parsed['temperature'], 0.001);
        $this->assertSame('KZZZ', $parsed['_metar_station_used'] ?? null);
    }

    public function testFetchMETARFromStation_NoBulkSlice_UsesHttpMockResponse(): void
    {
        require_once __DIR__ . '/../../lib/cache-paths.php';
        require_once __DIR__ . '/../../lib/config.php';
        require_once __DIR__ . '/../../lib/metar-bulk.php';
        require_once __DIR__ . '/../../lib/test-mocks.php';
        require_once __DIR__ . '/../../lib/weather/adapter/metar-v1.php';

        metarBulkTestSetSkipMetarHttpMock(false);
        metarBulkEnsureDirectories();
        $path = getMetarBulkStationJsonPath('KSPB');
        if (is_file($path)) {
            @unlink($path);
        }

        $parsed = fetchMETARFromStation('KSPB', ['icao' => 'KSPB']);

        $this->assertIsArray($parsed);
        $this->assertEqualsWithDelta(6.0, (float) $parsed['temperature'], 0.001);
        $this->assertStringContainsString('KSPB', (string) ($parsed['raw_ob'] ?? ''));
    }

    public function testFetchMETARFromStation_StaleBulkSlice_SkipHttpMock_ReturnsNull(): void
    {
        require_once __DIR__ . '/../../lib/cache-paths.php';
        require_once __DIR__ . '/../../lib/constants.php';
        require_once __DIR__ . '/../../lib/config.php';
        require_once __DIR__ . '/../../lib/metar-bulk.php';
        require_once __DIR__ . '/../../lib/test-mocks.php';
        require_once __DIR__ . '/../../lib/weather/adapter/metar-v1.php';

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
        touch($path, time() - METAR_BULK_STATION_FILE_MAX_AGE_SECONDS - 60);

        $parsed = fetchMETARFromStation('KZZZ', ['icao' => 'KZZZ']);
        @unlink($path);

        $this->assertNull($parsed);
    }
}
