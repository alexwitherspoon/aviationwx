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

    public function testLoadNasrAptCacheUsesConfiguredSliceWhenFullMissing(): void
    {
        $this->writeFullNasrCache([
            'ID76' => ['arpt_id' => 'ID76', 'runways' => []],
        ]);

        $config = [
            'airports' => [
                'id76' => ['id' => 'id76', 'faa' => 'ID76'],
            ],
        ];

        nasrRebuildConfiguredAptSlice($config);
        @unlink(CACHE_NASR_APT_DATA_FILE);
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

    public function testRebuildRecordsMtimeOfCycleItRead(): void
    {
        $this->writeFullNasrCache([
            'ID76' => ['arpt_id' => 'ID76', 'runways' => []],
        ]);
        $sourceMtime = time() - 3600;
        touch(CACHE_NASR_APT_DATA_FILE, $sourceMtime);

        $config = [
            'airports' => [
                'id76' => ['id' => 'id76', 'faa' => 'ID76'],
            ],
        ];

        $payload = nasrRebuildConfiguredAptSlice($config);

        $this->assertNotNull($payload);
        $meta = loadNasrAptMeta();
        $this->assertNotNull($meta);
        $this->assertSame($sourceMtime, $meta['configured_source_mtime']);
    }

    public function testGuardRejectsReadWhenSourceRepublishedMidRead(): void
    {
        $this->writeFullNasrCache([
            'ID76' => ['arpt_id' => 'ID76', 'runways' => [], 'elev_ft' => 100],
        ]);

        $guarded = nasrReadFullAptCacheWithSourceMtime(static function (): ?array {
            // Read the current cycle, then land a new one: write tmp, rename
            // over the path, the fetch job's own publish shape.
            $current = nasrReadFullAptCacheFromDisk();
            $published = [
                'schema_version' => NASR_APT_SCHEMA_VERSION,
                'airports' => ['ID76' => ['arpt_id' => 'ID76', 'runways' => [], 'elev_ft' => 999]],
            ];
            $tmp = CACHE_NASR_APT_DATA_FILE . '.tmp.' . getmypid();
            file_put_contents($tmp, json_encode($published, JSON_UNESCAPED_SLASHES), LOCK_EX);
            touch($tmp, time() + 1);
            rename($tmp, CACHE_NASR_APT_DATA_FILE);
            return $current;
        });

        $this->assertNull($guarded);
    }

    public function testSliceRebuildsWhenSourceMtimeAdvances(): void
    {
        // loadNasrAptCache rebuilds via loadConfig(), so the full cache must
        // cover the fixture config's platform airports to show through.
        $config = loadConfig();
        $ids = nasrCollectArptIdsForPlatformAirports($config);
        $this->assertNotEmpty($ids);

        $airports = [];
        foreach ($ids as $id) {
            $airports[$id] = ['arpt_id' => $id, 'runways' => [], 'elev_ft' => 100];
        }

        $this->writeFullNasrCache($airports);
        touch(CACHE_NASR_APT_DATA_FILE, time() - 3600);

        nasrRebuildConfiguredAptSlice($config);
        resetNasrAptCacheMemo();

        // New cycle published with the same rows, a different elevation, and a newer mtime.
        foreach ($airports as $id => $row) {
            $airports[$id] = ['arpt_id' => $id, 'runways' => [], 'elev_ft' => 200];
        }
        $this->writeFullNasrCache($airports);
        touch(CACHE_NASR_APT_DATA_FILE, time() - 60);

        $loaded = loadNasrAptCache();
        $this->assertNotNull($loaded);
        $first = reset($ids);
        $this->assertSame(200, $loaded['airports'][$first]['elev_ft']);
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
