<?php

declare(strict_types=1);

namespace AviationWX\Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * METAR station response resolution: `metarResolveStationResponse()` and `fetchMETARFromStation()`.
 */
final class FetchMetarFromStationBulkTest extends TestCase
{
    private ?string $rateLimitRoot = null;

    protected function tearDown(): void
    {
        require_once __DIR__ . '/../../lib/test-mocks.php';
        require_once __DIR__ . '/../../lib/upstream-rate-limit.php';
        metarBulkTestSetSkipMetarHttpMock(false);
        upstreamRateLimitTestClearForceEnforcement();
        unset($GLOBALS['upstreamRateLimitTestRoot']);
        if ($this->rateLimitRoot !== null && is_dir($this->rateLimitRoot)) {
            $files = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($this->rateLimitRoot, \FilesystemIterator::SKIP_DOTS),
                \RecursiveIteratorIterator::CHILD_FIRST
            );
            foreach ($files as $file) {
                if ($file->isDir()) {
                    rmdir($file->getPathname());
                } else {
                    unlink($file->getPathname());
                }
            }
            rmdir($this->rateLimitRoot);
        }

        parent::tearDown();
    }

    public function testMetarResolveStationResponse_FreshBulkSlice_SkipHttpMock_ReturnsOkWithBody(): void
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

        $resolved = metarResolveStationResponse('KZZZ');
        @unlink($path);

        $this->assertSame(METAR_RESOLVE_OK, $resolved['outcome']);
        $this->assertIsString($resolved['body']);
        $this->assertStringContainsString('"temp":42', $resolved['body']);
    }

    public function testMetarResolveStationResponse_NoBulkSlice_UsesHttpMock_ReturnsOk(): void
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

        $resolved = metarResolveStationResponse('KSPB');
        $this->assertSame(METAR_RESOLVE_OK, $resolved['outcome']);
        $this->assertStringContainsString('KSPB', (string) $resolved['body']);
    }

    public function testMetarResolveStationResponse_ThrottleExhausted_ReturnsThrottledNotFailed(): void
    {
        require_once __DIR__ . '/../../lib/cache-paths.php';
        require_once __DIR__ . '/../../lib/config.php';
        require_once __DIR__ . '/../../lib/metar-bulk.php';
        require_once __DIR__ . '/../../lib/test-mocks.php';
        require_once __DIR__ . '/../../lib/upstream-rate-limit.php';
        require_once __DIR__ . '/../../lib/weather/adapter/metar-v1.php';

        metarBulkTestSetSkipMetarHttpMock(true);
        metarBulkEnsureDirectories();
        $path = getMetarBulkStationJsonPath('KTHR');
        if (is_file($path)) {
            @unlink($path);
        }

        $this->rateLimitRoot = sys_get_temp_dir() . '/metar-throttle-' . bin2hex(random_bytes(4));
        mkdir($this->rateLimitRoot, 0755, true);
        $GLOBALS['upstreamRateLimitTestRoot'] = $this->rateLimitRoot;
        upstreamRateLimitTestForceEnforcement();

        $source = ['type' => 'metar', 'station_id' => 'KTHR'];
        $fingerprint = upstreamRateFingerprint('metar', $source);
        $t0 = microtime(true);
        $this->assertTrue(upstreamRateTryTake($fingerprint, 60, 1, $t0));

        $resolved = metarResolveStationResponse('KTHR', 'kthr');
        $this->assertSame(METAR_RESOLVE_THROTTLED, $resolved['outcome']);
        $this->assertNull($resolved['body']);
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
