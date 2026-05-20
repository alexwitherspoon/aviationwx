<?php

declare(strict_types=1);

namespace AviationWX\Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * METAR bulk snapshot age helpers and upstream HTTP 429 health counters.
 *
 * @covers ::metarBulkWriteRefreshMeta
 * @covers ::metarBulkSnapshotAgeSeconds
 * @covers ::metarBulkObservabilityContext
 * @covers ::getWeatherHealthCacheFilePath
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
        $now = time();
        $this->assertTrue(metarBulkWriteRefreshMeta($now - 90, 1, 1));

        $context = metarBulkObservabilityContext(['note' => 'test']);
        $this->assertSame('test', $context['note']);
        $this->assertSame(90, $context['metar_bulk_age_seconds']);
    }

    public function testWeatherHealthTrackFetch_429IncrementsUpstreamCounter(): void
    {
        weather_health_track_fetch('kspb', 'tempest', false, 429);
        weather_health_flush();

        $status = weather_health_get_status();
        $metrics = $status['metrics'] ?? [];
        $this->assertSame(1, $metrics['upstream_429_last_hour'] ?? null);
    }

    public function testWeatherHealthTrackFetch_429DoesNotIncrementHttp4xxBucket(): void
    {
        weather_health_track_fetch('kspb', 'tempest', false, 429);
        weather_health_flush();

        $sources = weather_health_get_sources();
        $this->assertSame(0, $sources['tempest']['metrics']['http_4xx'] ?? -1);
        $this->assertSame(1, $sources['tempest']['metrics']['upstream_429'] ?? null);
    }
}
