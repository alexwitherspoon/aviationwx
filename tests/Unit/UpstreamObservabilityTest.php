<?php

declare(strict_types=1);

namespace AviationWX\Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * METAR bulk snapshot age helpers and upstream HTTP health counters.
 *
 * @covers ::metarBulkWriteRefreshMeta
 * @covers ::metarBulkSnapshotAgeSeconds
 * @covers ::metarBulkObservabilityContext
 * @covers ::getWeatherHealthCacheFilePath
 * @covers ::weatherHealthEnsureCacheDirectory
 * @covers ::weatherHealthTrackFetch
 * @covers ::weatherHealthTrackMetarBulkDownloadFailure
 * @covers ::weatherHealthTrackUpstreamThrottleSkip
 * @covers ::weatherHealthTrackUpstreamRateLimitFailOpen
 * @covers ::weatherHealthFlush
 * @covers ::weatherHealthGetStatus
 * @covers ::weatherHealthGetSources
 * @covers ::weatherHealthGetProviderBreakdown
 * @covers ::weatherHealthProviderDisplayName
 */
final class UpstreamObservabilityTest extends TestCase
{
    private ?string $cacheRoot = null;

    private ?string $healthCacheFile = null;

    protected function setUp(): void
    {
        parent::setUp();
        $this->cacheRoot = sys_get_temp_dir() . '/metar-bulk-obs-' . bin2hex(random_bytes(4));
        mkdir($this->cacheRoot, 0755, true);
        $GLOBALS['metarBulkTestCacheRoot'] = $this->cacheRoot;

        $this->healthCacheFile = $this->cacheRoot . '/weather_health.json';
        $GLOBALS['weatherHealthTestCacheFile'] = $this->healthCacheFile;

        require_once __DIR__ . '/../../lib/cache-paths.php';
        require_once __DIR__ . '/../../lib/metar-bulk.php';
        require_once __DIR__ . '/../../lib/weather-health.php';
    }

    protected function tearDown(): void
    {
        unset($GLOBALS['metarBulkTestCacheRoot'], $GLOBALS['weatherHealthTestCacheFile']);

        if ($this->cacheRoot !== null && is_dir($this->cacheRoot)) {
            $files = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($this->cacheRoot, \FilesystemIterator::SKIP_DOTS),
                \RecursiveIteratorIterator::CHILD_FIRST
            );
            foreach ($files as $file) {
                if ($file->isDir()) {
                    rmdir($file->getPathname());
                } else {
                    unlink($file->getPathname());
                }
            }
            rmdir($this->cacheRoot);
        }

        parent::tearDown();
    }

    public function testMetarBulkSnapshotAgeSeconds_ReturnsNullWhenMetaMissing(): void
    {
        $this->assertNull(metarBulkSnapshotAgeSeconds());
    }

    public function testMetarBulkSnapshotAgeSeconds_ReturnsNullWhenMetaFileEmpty(): void
    {
        file_put_contents(getMetarBulkMetaPath(), '');

        $this->assertNull(metarBulkSnapshotAgeSeconds());
    }

    public function testMetarBulkSnapshotAgeSeconds_ReturnsAgeFromMeta(): void
    {
        $fetchedAt = 1_700_000_000;
        $this->assertTrue(metarBulkWriteRefreshMeta($fetchedAt, 3, 100));
        $this->assertSame(120, metarBulkSnapshotAgeSeconds($fetchedAt + 120));
    }

    public function testMetarBulkSnapshotAgeSeconds_RejectsFutureFetchedAt(): void
    {
        $now = 1_700_000_000;
        file_put_contents(getMetarBulkMetaPath(), json_encode([
            'fetched_at' => $now + 3600,
            'written' => 1,
            'scanned' => 1,
        ], JSON_UNESCAPED_SLASHES));

        $this->assertNull(metarBulkSnapshotAgeSeconds($now));
    }

    public function testMetarBulkWriteRefreshMeta_ReturnsFalseWhenPathIsDirectory(): void
    {
        $metaPath = getMetarBulkMetaPath();
        mkdir($metaPath, 0755, true);

        $this->assertFalse(metarBulkWriteRefreshMeta(time(), 1, 1));
    }

    public function testMetarBulkObservabilityContext_IncludesAgeWhenMetaPresent(): void
    {
        $now = 1_700_000_000;
        $this->assertTrue(metarBulkWriteRefreshMeta($now - 90, 1, 1));

        $context = metarBulkObservabilityContext(['note' => 'test'], $now);
        $this->assertSame('test', $context['note']);
        $this->assertSame(90, $context['metar_bulk_age_seconds']);
    }

    public function testWeatherHealthTrackFetch_429IncrementsUpstreamCounter(): void
    {
        weatherHealthTrackFetch('kspb', 'tempest', false, 429);
        weatherHealthFlush();

        $status = weatherHealthGetStatus();
        $metrics = $status['metrics'] ?? [];
        $this->assertSame(1, $metrics['upstream_429_last_hour'] ?? null);
    }

    public function testWeatherHealthTrackFetch_429DoesNotIncrementHttp4xxBucket(): void
    {
        weatherHealthTrackFetch('kspb', 'tempest', false, 429);
        weatherHealthFlush();

        $sources = weatherHealthGetSources();
        $this->assertSame(0, $sources['tempest']['metrics']['http_4xx'] ?? -1);
        $this->assertSame(1, $sources['tempest']['metrics']['upstream_429'] ?? null);
    }

    public function testWeatherHealthTrackFetch_503IncrementsHttp5xxBucket(): void
    {
        weatherHealthTrackFetch('kspb', 'metar', false, 503);
        weatherHealthFlush();

        $sources = weatherHealthGetSources();
        $this->assertSame(1, $sources['metar']['metrics']['http_5xx'] ?? null);
        $this->assertSame(0, $sources['metar']['metrics']['upstream_429'] ?? -1);
    }

    public function testWeatherHealthTrackFetch_SuccessIncrementsSuccessCounters(): void
    {
        weatherHealthTrackFetch('kspb', 'tempest', true, 200);
        weatherHealthFlush();

        $status = weatherHealthGetStatus();
        $metrics = $status['metrics'] ?? [];
        $this->assertSame(1, $metrics['total_attempts_last_hour'] ?? null);
        $this->assertSame(1, $metrics['total_successes_last_hour'] ?? null);
        $this->assertSame(0, $metrics['total_failures_last_hour'] ?? null);
        $this->assertSame('operational', $status['status'] ?? null);
    }

    public function testWeatherHealthTrackMetarBulkDownloadFailure_429RollsIntoGlobalHealth(): void
    {
        weatherHealthTrackMetarBulkDownloadFailure(429);
        weatherHealthFlush();

        $status = weatherHealthGetStatus();
        $metrics = $status['metrics'] ?? [];
        $this->assertSame(1, $metrics['upstream_429_last_hour'] ?? null);
        $this->assertSame(1, $metrics['metar_bulk_download_failures_last_hour'] ?? null);
        $this->assertSame('degraded', $status['status'] ?? null);
        $this->assertStringContainsString('METAR bulk download', (string) ($status['message'] ?? ''));
    }

    public function testWeatherHealthTrackUpstreamThrottleSkip_WithFetchesStaysOperational(): void
    {
        weatherHealthTrackFetch('kspb', 'tempest', true, 200);
        weatherHealthTrackUpstreamThrottleSkip('kspb', 'tempest');
        weatherHealthFlush();

        $status = weatherHealthGetStatus();
        $this->assertSame('operational', $status['status'] ?? null);
        $this->assertStringContainsString('throttle skip', (string) ($status['message'] ?? ''));
        $this->assertSame(1, $status['metrics']['upstream_throttle_skips_last_hour'] ?? null);
    }

    public function testWeatherHealthTrackUpstreamThrottleSkip_OnlySkipsShowsOperationalMessage(): void
    {
        weatherHealthTrackUpstreamThrottleSkip('kspb', 'tempest');
        weatherHealthFlush();

        $status = weatherHealthGetStatus();
        $this->assertSame('operational', $status['status'] ?? null);
        $this->assertStringContainsString('throttle skip', (string) ($status['message'] ?? ''));
        $this->assertSame(0, $status['metrics']['total_attempts_last_hour'] ?? -1);
    }

    public function testWeatherHealthTrackUpstreamRateLimitFailOpen_DegradesWhenNoFetches(): void
    {
        weatherHealthTrackUpstreamRateLimitFailOpen('state_dir_unavailable');
        weatherHealthFlush();

        $status = weatherHealthGetStatus();
        $this->assertSame('degraded', $status['status'] ?? null);
        $this->assertStringContainsString('fail-open', (string) ($status['message'] ?? ''));
        $this->assertSame(1, $status['metrics']['upstream_rate_limit_fail_open_last_hour'] ?? null);
    }

    public function testWeatherHealthFlush_CreatesCacheWhenMissing(): void
    {
        $this->assertFileDoesNotExist($this->healthCacheFile);
        $this->assertTrue(weatherHealthFlush());
        $this->assertFileExists($this->healthCacheFile);

        $decoded = json_decode((string) file_get_contents($this->healthCacheFile), true);
        $this->assertIsArray($decoded);
        $this->assertArrayHasKey('health', $decoded);
    }

    public function testWeatherHealthFlush_CreatesParentDirectoryWhenMissing(): void
    {
        $nestedDir = $this->cacheRoot . '/nested/sub';
        $nestedFile = $nestedDir . '/weather_health.json';
        $this->assertDirectoryDoesNotExist($nestedDir);

        $previousPath = $GLOBALS['weatherHealthTestCacheFile'];
        $GLOBALS['weatherHealthTestCacheFile'] = $nestedFile;

        try {
            $this->assertTrue(weatherHealthFlush());
            $this->assertDirectoryExists($nestedDir);
            $this->assertFileExists($nestedFile);
        } finally {
            $GLOBALS['weatherHealthTestCacheFile'] = $previousPath;
        }
    }

    public function testWeatherHealthAtomicUpdate_PrunesBucketsOlderThanTwoHours(): void
    {
        $oldHour = gmdate('Y-m-d-H', time() - 10800);
        $data = [
            'hourly_buckets' => [
                $oldHour => ['total_attempts' => 99],
            ],
        ];
        file_put_contents($this->healthCacheFile, json_encode($data));

        weatherHealthTrackFetch('kspb', 'tempest', true, 200);

        $decoded = json_decode((string) file_get_contents($this->healthCacheFile), true);
        $this->assertIsArray($decoded);
        $this->assertArrayNotHasKey($oldHour, $decoded['hourly_buckets'] ?? []);
    }

    public function testWeatherHealthGetProviderBreakdown_SortsByUpstream429(): void
    {
        weatherHealthTrackFetch('kspb', 'ambient', false, 429);
        weatherHealthTrackFetch('kspb', 'ambient', false, 429);
        weatherHealthTrackFetch('kspb', 'tempest', true, 200);
        weatherHealthTrackMetarBulkDownloadFailure(429);
        weatherHealthFlush();

        $providers = weatherHealthGetProviderBreakdown();
        $this->assertNotEmpty($providers);
        $this->assertSame('ambient', $providers[0]['id'] ?? null);
        $this->assertSame(2, $providers[0]['upstream_429'] ?? null);

        $byId = [];
        foreach ($providers as $row) {
            $byId[$row['id']] = $row;
        }
        $this->assertSame(1, $byId['metar_bulk']['upstream_429'] ?? null);
        $this->assertSame('METAR bulk (AWC national gzip)', $byId['metar_bulk']['name'] ?? null);
    }
}
