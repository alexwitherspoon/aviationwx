<?php

declare(strict_types=1);

namespace AviationWX\Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * UnifiedFetcher METAR path uses bulk/mock resolution instead of curl_multi.
 */
final class FetchAllSourcesMetarBulkTest extends TestCase
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

        $this->assertArrayHasKey('source_0', $responses['responses']);
        $this->assertIsString($responses['responses']['source_0']);
        $this->assertStringContainsString('"temp":42', $responses['responses']['source_0']);
    }

    public function testFetchAllSources_MetarThrottled_DoesNotRecordCircuitFailure(): void
    {
        require_once __DIR__ . '/../../lib/cache-paths.php';
        require_once __DIR__ . '/../../lib/config.php';
        require_once __DIR__ . '/../../lib/metar-bulk.php';
        require_once __DIR__ . '/../../lib/test-mocks.php';
        require_once __DIR__ . '/../../lib/upstream-rate-limit.php';
        require_once __DIR__ . '/../../lib/constants.php';
        require_once __DIR__ . '/../../lib/circuit-breaker.php';
        require_once __DIR__ . '/../../lib/weather/adapter/metar-v1.php';
        require_once __DIR__ . '/../../lib/weather/UnifiedFetcher.php';

        metarBulkTestSetSkipMetarHttpMock(true);
        metarBulkEnsureDirectories();
        $path = getMetarBulkStationJsonPath('KTHR');
        if (is_file($path)) {
            @unlink($path);
        }

        if (is_file(CACHE_BACKOFF_FILE)) {
            @unlink(CACHE_BACKOFF_FILE);
        }

        $this->rateLimitRoot = sys_get_temp_dir() . '/fetchall-throttle-' . bin2hex(random_bytes(4));
        mkdir($this->rateLimitRoot, 0755, true);
        $GLOBALS['upstreamRateLimitTestRoot'] = $this->rateLimitRoot;
        upstreamRateLimitTestForceEnforcement();

        $source = ['type' => 'metar', 'station_id' => 'KTHR'];
        $fingerprint = upstreamRateFingerprint('metar', $source);
        $t0 = microtime(true);
        $this->assertTrue(upstreamRateTryTake($fingerprint, 60, 1, $t0));

        $sources = [
            'source_0' => [
                'type' => 'metar',
                'station_id' => 'KTHR',
            ],
        ];

        $responses = fetchAllSources($sources, 'kthr');

        $this->assertArrayNotHasKey('source_0', $responses['responses']);
        if (is_file(CACHE_BACKOFF_FILE)) {
            $data = json_decode((string) file_get_contents(CACHE_BACKOFF_FILE), true);
            $this->assertIsArray($data);
            $this->assertArrayNotHasKey('kthr_weather_metar', $data);
        }
    }
}
