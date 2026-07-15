<?php
/**
 * Unit tests for configured NASR slice (airports.json subset).
 */

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../lib/config.php';
require_once __DIR__ . '/../../lib/constants.php';
require_once __DIR__ . '/../../lib/cache-paths.php';
require_once __DIR__ . '/../../lib/nasr/cache.php';

class NasrConfiguredCacheTest extends TestCase
{
    private array $backupFiles = [];

    protected function setUp(): void
    {
        parent::setUp();
        resetNasrAptCacheMemo();

        foreach ([
            CACHE_NASR_APT_DATA_FILE,
            CACHE_NASR_APT_META_FILE,
            CACHE_NASR_APT_CONFIGURED_FILE,
        ] as $path) {
            if (file_exists($path)) {
                $this->backupFiles[$path] = file_get_contents($path);
                @unlink($path);
            }
        }
    }

    protected function tearDown(): void
    {
        resetNasrAptCacheMemo();

        foreach ([
            CACHE_NASR_APT_DATA_FILE,
            CACHE_NASR_APT_META_FILE,
            CACHE_NASR_APT_CONFIGURED_FILE,
        ] as $path) {
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

    public function testCollectArptIdsFromPlatformConfig(): void
    {
        $config = [
            'airports' => [
                'id76' => ['faa' => 'ID76', 'elevation_ft' => 4925],
                'khio' => ['icao' => 'KHIO', 'faa' => 'HIO'],
            ],
        ];

        $ids = nasrCollectArptIdsForPlatformAirports($config);

        $this->assertContains('ID76', $ids);
        $this->assertContains('HIO', $ids);
        $this->assertContains('KHIO', $ids);
        $this->assertNotContains('03S', $ids);
        $this->assertCount(3, $ids);
    }

    public function testRebuildConfiguredSliceIncludesOnlyPlatformAirports(): void
    {
        $this->writeFullNasrCache([
            '03S' => ['arpt_id' => '03S', 'runways' => []],
            'ID76' => ['arpt_id' => 'ID76', 'runways' => []],
            'HIO' => ['arpt_id' => 'HIO', 'runways' => []],
            'ZZZZ' => ['arpt_id' => 'ZZZZ', 'runways' => []],
        ]);

        $config = [
            'airports' => [
                'id76' => ['id' => 'id76', 'faa' => 'ID76'],
                'khio' => ['id' => 'khio', 'icao' => 'KHIO', 'faa' => 'HIO'],
            ],
        ];

        $payload = nasrRebuildConfiguredAptSlice($config);
        $this->assertNotNull($payload);
        $this->assertArrayHasKey('ID76', $payload['airports']);
        $this->assertArrayHasKey('HIO', $payload['airports']);
        $this->assertArrayNotHasKey('03S', $payload['airports']);
        $this->assertArrayNotHasKey('ZZZZ', $payload['airports']);
        $this->assertSame(2, count($payload['airports']));

        $meta = loadNasrAptMeta();
        $this->assertNotNull($meta);
        $this->assertSame(2, $meta['configured_arpt_count']);
        $this->assertSame(nasrGetConfigShaForSlice(), $meta['configured_config_sha']);
    }

    public function testLoadNasrAptCacheReadsConfiguredSliceNotFullIndex(): void
    {
        $this->writeFullNasrCache([
            '03S' => ['arpt_id' => '03S', 'runways' => []],
            'ID76' => ['arpt_id' => 'ID76', 'runways' => []],
            'HIO' => ['arpt_id' => 'HIO', 'runways' => []],
            'ZZZZ' => ['arpt_id' => 'ZZZZ', 'runways' => []],
        ]);

        $config = [
            'airports' => [
                'id76' => ['id' => 'id76', 'faa' => 'ID76'],
            ],
        ];

        nasrRebuildConfiguredAptSlice($config);
        resetNasrAptCacheMemo();

        $loaded = loadNasrAptCache();
        $this->assertNotNull($loaded);
        $this->assertSame(['ID76'], array_keys($loaded['airports']));
    }

    public function testSaveNasrAptCacheRebuildsConfiguredSlice(): void
    {
        $fullAirports = [
            'ID76' => ['arpt_id' => 'ID76', 'runways' => []],
            'ZZZZ' => ['arpt_id' => 'ZZZZ', 'runways' => []],
        ];

        $saved = saveNasrAptCache($fullAirports, [
            'effective_date' => gmdate('Y-m-d'),
            'tracked_current_cycle_date' => gmdate('Y-m-d'),
        ]);
        $this->assertTrue($saved);
        $this->assertFileExists(CACHE_NASR_APT_CONFIGURED_FILE);

        resetNasrAptCacheMemo();
        $loaded = loadNasrAptCache();
        $this->assertNotNull($loaded);
        $this->assertArrayNotHasKey('ZZZZ', $loaded['airports']);
        $this->assertLessThanOrEqual(2, count($loaded['airports']));
    }

    /**
     * @param array<string, array<string, mixed>> $airports
     */
    private function writeFullNasrCache(array $airports): void
    {
        $dir = dirname(CACHE_NASR_APT_DATA_FILE);
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        file_put_contents(
            CACHE_NASR_APT_DATA_FILE,
            json_encode([
                'schema_version' => NASR_APT_SCHEMA_VERSION,
                'airports' => $airports,
            ], JSON_UNESCAPED_SLASHES)
        );
        touch(CACHE_NASR_APT_DATA_FILE, time());

        file_put_contents(
            CACHE_NASR_APT_META_FILE,
            json_encode([
                'schema_version' => NASR_APT_SCHEMA_VERSION,
                'effective_date' => gmdate('Y-m-d'),
            ], JSON_UNESCAPED_SLASHES)
        );
    }
}
