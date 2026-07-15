<?php
/**
 * Unit tests for NASR APT cache health status checks.
 */

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../lib/config.php';
require_once __DIR__ . '/../../lib/constants.php';
require_once __DIR__ . '/../../lib/cache-paths.php';
require_once __DIR__ . '/../../lib/status-checks.php';

class NasrCacheHealthTest extends TestCase
{
    private array $backupFiles = [];

    protected function setUp(): void
    {
        parent::setUp();
        foreach ([CACHE_NASR_APT_DATA_FILE, CACHE_NASR_APT_META_FILE, CACHE_NASR_APT_CONFIGURED_FILE] as $path) {
            if (file_exists($path)) {
                $this->backupFiles[$path] = file_get_contents($path);
                @unlink($path);
            }
        }
    }

    protected function tearDown(): void
    {
        foreach ([CACHE_NASR_APT_DATA_FILE, CACHE_NASR_APT_META_FILE, CACHE_NASR_APT_CONFIGURED_FILE] as $path) {
            if (isset($this->backupFiles[$path])) {
                $dir = dirname($path);
                if (!is_dir($dir)) {
                    @mkdir($dir, 0755, true);
                }
                file_put_contents($path, $this->backupFiles[$path]);
            } elseif (file_exists($path)) {
                @unlink($path);
            }
        }
        parent::tearDown();
    }

    public function testCheckNasrAptCacheHealthWhenMissing(): void
    {
        $health = checkNasrAptCacheHealth();

        $this->assertSame('NASR APT Cache', $health['name']);
        $this->assertSame('down', $health['status']);
        $this->assertStringContainsString('missing', $health['message']);
    }

    public function testCheckNasrAptCacheHealthWhenOperational(): void
    {
        $this->writeNasrCacheFixture(
            [
                'schema_version' => NASR_APT_SCHEMA_VERSION,
                'airports' => ['KTEST' => ['arpt_id' => 'KTEST', 'runways' => []]],
            ],
            [
                'schema_version' => NASR_APT_SCHEMA_VERSION,
                'effective_date' => gmdate('Y-m-d'),
                'tracked_current_cycle_date' => gmdate('Y-m-d'),
                'tracked_next_cycle_date' => gmdate('Y-m-d', time() + (NASR_CYCLE_PERIOD_DAYS * 86400)),
                'airport_count' => 15000,
                'fetched_at' => gmdate('c'),
            ]
        );

        $health = checkNasrAptCacheHealth();

        $this->assertSame('operational', $health['status']);
        $this->assertStringContainsString('15000 US airports', $health['message']);
    }

    public function testCheckNasrAptCacheHealthWhenLastFetchFailed(): void
    {
        $this->writeNasrCacheFixture(
            [
                'schema_version' => NASR_APT_SCHEMA_VERSION,
                'airports' => ['KTEST' => ['arpt_id' => 'KTEST', 'runways' => []]],
            ],
            [
                'schema_version' => NASR_APT_SCHEMA_VERSION,
                'effective_date' => gmdate('Y-m-d'),
                'tracked_current_cycle_date' => gmdate('Y-m-d'),
                'tracked_next_cycle_date' => gmdate('Y-m-d', time() + (NASR_CYCLE_PERIOD_DAYS * 86400)),
                'airport_count' => 15000,
                'fetched_at' => gmdate('c'),
                'last_fetch_error' => 'NASR APT download failed, retaining previous cache',
            ]
        );

        $health = checkNasrAptCacheHealth();

        $this->assertSame('degraded', $health['status']);
        $this->assertStringContainsString('Last fetch failed', $health['message']);
    }

    /**
     * @param array<string, mixed> $dataPayload
     * @param array<string, mixed> $metaPayload
     */
    private function writeNasrCacheFixture(array $dataPayload, array $metaPayload): void
    {
        $dir = dirname(CACHE_NASR_APT_DATA_FILE);
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        file_put_contents(CACHE_NASR_APT_DATA_FILE, json_encode($dataPayload, JSON_UNESCAPED_SLASHES));
        file_put_contents(CACHE_NASR_APT_META_FILE, json_encode($metaPayload, JSON_UNESCAPED_SLASHES));
        touch(CACHE_NASR_APT_DATA_FILE, time());
    }
}
