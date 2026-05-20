<?php

declare(strict_types=1);

namespace AviationWX\Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Phase 6-7 observability: METAR bulk snapshot age and upstream 429 health counters.
 */
final class UpstreamObservabilityTest extends TestCase
{
    private ?string $cacheRoot = null;

    private ?string $healthBackup = null;

    protected function setUp(): void
    {
        parent::setUp();
        $this->cacheRoot = sys_get_temp_dir() . '/metar-bulk-obs-' . bin2hex(random_bytes(4));
        mkdir($this->cacheRoot, 0755, true);
        $GLOBALS['metarBulkTestCacheRoot'] = $this->cacheRoot;

        require_once __DIR__ . '/../../lib/cache-paths.php';
        require_once __DIR__ . '/../../lib/metar-bulk.php';
        require_once __DIR__ . '/../../lib/weather-health.php';

        if (is_file(WEATHER_HEALTH_CACHE_FILE)) {
            $this->healthBackup = (string) file_get_contents(WEATHER_HEALTH_CACHE_FILE);
            @unlink(WEATHER_HEALTH_CACHE_FILE);
        }
    }

    protected function tearDown(): void
    {
        unset($GLOBALS['metarBulkTestCacheRoot']);

        if ($this->healthBackup !== null) {
            file_put_contents(WEATHER_HEALTH_CACHE_FILE, $this->healthBackup);
        } elseif (is_file(WEATHER_HEALTH_CACHE_FILE)) {
            @unlink(WEATHER_HEALTH_CACHE_FILE);
        }

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

    public function testWeatherHealthTrackFetch_429IncrementsUpstreamCounter(): void
    {
        weather_health_track_fetch('kspb', 'tempest', false, 429);
        weather_health_flush();

        $status = weather_health_get_status();
        $metrics = $status['metrics'] ?? [];
        $this->assertSame(1, $metrics['upstream_429_last_hour'] ?? null);
    }
}
