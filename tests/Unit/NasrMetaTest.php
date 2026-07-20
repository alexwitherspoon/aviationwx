<?php
/**
 * Unit tests for NASR shared metadata locking and merge behavior.
 */

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../lib/cache-paths.php';
require_once __DIR__ . '/../../lib/constants.php';
require_once __DIR__ . '/../../lib/nasr/cache.php';

class NasrMetaTest extends TestCase
{
    private array $backupFiles = [];

    protected function setUp(): void
    {
        parent::setUp();
        foreach ([CACHE_NASR_APT_DATA_FILE, CACHE_NASR_APT_META_FILE] as $path) {
            if (file_exists($path)) {
                $this->backupFiles[$path] = file_get_contents($path);
                @unlink($path);
            }
        }
    }

    protected function tearDown(): void
    {
        foreach ([CACHE_NASR_APT_DATA_FILE, CACHE_NASR_APT_META_FILE] as $path) {
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

    public function testSaveNasrAptCachePreservesFrqMetaFields(): void
    {
        $this->assertTrue(updateNasrAptMetaFields([
            'frq_fetched_at' => '2026-07-10T12:00:00+00:00',
            'frq_airport_count' => 9000,
            'frq_last_fetch_error' => null,
        ]));

        $saved = saveNasrAptCache(
            ['KTEST' => ['arpt_id' => 'KTEST', 'runways' => []]],
            [
                'effective_date' => '2026-07-09',
                'tracked_current_cycle_date' => '2026-07-09',
                'tracked_next_cycle_date' => '2026-08-06',
            ]
        );

        $this->assertTrue($saved);
        $meta = loadNasrAptMeta();
        $this->assertIsArray($meta);
        $this->assertSame('2026-07-10T12:00:00+00:00', $meta['frq_fetched_at'] ?? null);
        $this->assertSame(9000, $meta['frq_airport_count'] ?? null);
        $this->assertSame(1, $meta['airport_count'] ?? null);
        $this->assertSame('2026-07-09', $meta['effective_date'] ?? null);
    }

    public function testUpdateNasrAptMetaFieldsMergesWithoutDroppingExistingKeys(): void
    {
        $this->assertTrue(updateNasrAptMetaFields(['frq_airport_count' => 100]));
        $this->assertTrue(updateNasrAptMetaFields(['airport_count' => 15000]));

        $meta = loadNasrAptMeta();
        $this->assertSame(100, $meta['frq_airport_count'] ?? null);
        $this->assertSame(15000, $meta['airport_count'] ?? null);
    }
}
