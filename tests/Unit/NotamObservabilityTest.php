<?php

declare(strict_types=1);

namespace AviationWX\Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * NMS NOTAM upstream health counters.
 *
 * @covers ::getNotamHealthCacheFilePath
 * @covers ::notamHealthEnsureCacheDirectory
 * @covers ::notamHealthTrackRequest
 * @covers ::notamHealthFlush
 * @covers ::notamHealthGetStatus
 * @covers ::notamHealthGetProviders
 * @covers ::notamHealthEndpointDisplayName
 */
final class NotamObservabilityTest extends TestCase
{
    private ?string $cacheRoot = null;

    private ?string $healthCacheFile = null;

    protected function setUp(): void
    {
        parent::setUp();
        $this->cacheRoot = sys_get_temp_dir() . '/notam-obs-' . bin2hex(random_bytes(4));
        mkdir($this->cacheRoot, 0755, true);

        $this->healthCacheFile = $this->cacheRoot . '/notam_health.json';
        $GLOBALS['notamHealthTestCacheFile'] = $this->healthCacheFile;

        require_once __DIR__ . '/../../lib/cache-paths.php';
        require_once __DIR__ . '/../../lib/notam-health.php';
    }

    protected function tearDown(): void
    {
        unset($GLOBALS['notamHealthTestCacheFile']);

        if ($this->cacheRoot !== null && is_dir($this->cacheRoot)) {
            $files = glob($this->cacheRoot . '/*') ?: [];
            foreach ($files as $file) {
                if (is_file($file)) {
                    unlink($file);
                }
            }
            @rmdir($this->cacheRoot);
        }

        parent::tearDown();
    }

    public function testNotamHealthTrackRequest_429IncrementsUpstreamCounter(): void
    {
        notamHealthTrackRequest('location', false, 429);
        notamHealthFlush();

        $status = notamHealthGetStatus();
        $metrics = $status['metrics'] ?? [];
        $this->assertSame(1, $metrics['upstream_429_last_hour'] ?? null);
        $this->assertSame('degraded', $status['status'] ?? null);
        $this->assertStringContainsString('429', (string) ($status['message'] ?? ''));
    }

    public function testNotamHealthGetProviders_IncludesEndpointBreakdown(): void
    {
        notamHealthTrackRequest('location', false, 429);
        notamHealthTrackRequest('geo', true, 200);
        notamHealthFlush();

        $providers = notamHealthGetProviders();
        $this->assertCount(2, $providers);

        $byId = [];
        foreach ($providers as $row) {
            $byId[$row['id']] = $row;
        }

        $this->assertSame(1, $byId['location']['upstream_429'] ?? null);
        $this->assertSame(0, $byId['geo']['upstream_429'] ?? null);
        $this->assertSame('NMS location query', $byId['location']['name'] ?? null);
    }

    public function testNotamHealthFlush_CreatesCacheWhenMissing(): void
    {
        $this->assertFileDoesNotExist($this->healthCacheFile);
        $this->assertTrue(notamHealthFlush());
        $this->assertFileExists($this->healthCacheFile);

        $decoded = json_decode((string) file_get_contents($this->healthCacheFile), true);
        $this->assertIsArray($decoded);
        $this->assertArrayHasKey('health', $decoded);
        $this->assertArrayHasKey('providers', $decoded);
    }

    public function testNotamHealthTrackRequest_EmptyNmsPayloadCountsAsLocationSuccess(): void
    {
        require_once __DIR__ . '/../../lib/notam/fetcher.php';

        $rows = notamExtractAixmRowsFromNmsResponse(['status' => 'Success', 'data' => []]);
        $this->assertSame([], $rows);

        notamHealthTrackRequest('location', true, 200);
        notamHealthFlush();

        $providers = notamHealthGetProviders();
        $byId = [];
        foreach ($providers as $row) {
            $byId[$row['id']] = $row;
        }

        $this->assertSame(100, $byId['location']['success_rate'] ?? null);
        $this->assertSame(1, $byId['location']['attempts'] ?? null);
    }
}
