<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../lib/notam/cache.php';
require_once __DIR__ . '/../../lib/notam/map-layer-cache.php';

/**
 * NOTAM TFR map layer (GeoJSON aggregation) unit tests.
 */
final class NotamMapLayerTest extends TestCase
{
    private string $cacheDir = '';

    /** @var (callable(): void)|null Active rebuild lock holder subprocess release hook */
    private $rebuildLockHolderRelease = null;

    protected function setUp(): void
    {
        $this->cacheDir = sys_get_temp_dir() . '/aviationwx-notam-map-' . bin2hex(random_bytes(4));
        if (!mkdir($this->cacheDir, 0755, true) && !is_dir($this->cacheDir)) {
            self::fail('Could not create NOTAM map test cache directory: ' . $this->cacheDir);
        }
        $GLOBALS['notamCacheTestDirectory'] = $this->cacheDir;
    }

    protected function tearDown(): void
    {
        if ($this->rebuildLockHolderRelease !== null) {
            ($this->rebuildLockHolderRelease)();
            $this->rebuildLockHolderRelease = null;
        }

        unset($GLOBALS['notamCacheTestDirectory']);
        if ($this->cacheDir === '' || !is_dir($this->cacheDir)) {
            return;
        }

        foreach (scandir($this->cacheDir) ?: [] as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            $path = $this->cacheDir . '/' . $item;
            is_dir($path) ? self::removeTree($path) : @unlink($path);
        }
        @rmdir($this->cacheDir);
    }

    /**
     * @param array<int, array<string, mixed>> $features
     */
    private function writeAggregateCache(array $features, int $mtime, ?string $buildToken = null): void
    {
        $mapPath = getNotamTfrMapLayerCachePath();
        $json = json_encode([
            'type' => 'FeatureCollection',
            'features' => $features,
            'generated_at' => $mtime,
            'cache_ttl_seconds' => 3600,
            'map_layer_build_token' => $buildToken ?? notamTfrMapLayerCurrentBuildToken(),
        ], JSON_UNESCAPED_SLASHES);
        if ($json === false) {
            self::fail('Could not encode NOTAM map aggregate test cache JSON');
        }
        if (file_put_contents($mapPath, $json) === false) {
            self::fail('Could not write NOTAM map aggregate test cache: ' . $mapPath);
        }
        if (!touch($mapPath, $mtime)) {
            self::fail('Could not set mtime on NOTAM map aggregate test cache: ' . $mapPath);
        }
    }

    /**
     * @param array<int, array<string, mixed>> $notams
     */
    private function writePerAirportNotamCache(string $airportId, array $notams): void
    {
        $path = notamCacheFilePath($airportId);
        $fetchedAt = time();
        $payload = [
            'notams' => $notams,
            'fetched_at' => $fetchedAt,
        ];
        $json = json_encode($payload, JSON_UNESCAPED_SLASHES);
        if ($json === false) {
            self::fail('Could not encode per-airport NOTAM test cache JSON for ' . $airportId);
        }
        if (file_put_contents($path, $json) === false) {
            self::fail('Could not write per-airport NOTAM test cache: ' . $path);
        }
        if (!touch($path, $fetchedAt)) {
            self::fail('Could not set mtime on per-airport NOTAM test cache: ' . $path);
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function minimalListedAirportConfig(string $airportId = 's83'): array
    {
        return [
            'airports' => [
                $airportId => [
                    'enabled' => true,
                    'listed' => true,
                    'lat' => 47.52,
                    'lon' => -116.08,
                    'timezone' => 'UTC',
                ],
            ],
        ];
    }

    private static function removeTree(string $dir): void
    {
        foreach (scandir($dir) ?: [] as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            $path = $dir . '/' . $item;
            is_dir($path) ? self::removeTree($path) : @unlink($path);
        }
        @rmdir($dir);
    }

    /**
     * Start a child PHP process that holds the rebuild flock until released.
     *
     * Uses a separate process so LOCK_NB contention matches another worker (not
     * same-process flock upgrade behavior on some platforms).
     *
     * @return callable Release function; also invoked from tearDown on failure.
     */
    private function startRebuildLockHolder(): callable
    {
        if ($this->rebuildLockHolderRelease !== null) {
            self::fail('Rebuild lock holder subprocess already active');
        }

        $lockPath = getNotamTfrMapLayerRebuildLockPath();
        $lockDir = dirname($lockPath);
        if (!is_dir($lockDir) && !mkdir($lockDir, 0755, true) && !is_dir($lockDir)) {
            self::fail('Could not create NOTAM map rebuild lock directory: ' . $lockDir);
        }

        $helperScript = $this->cacheDir . '/hold-rebuild-lock.php';
        $readyPath = $this->cacheDir . '/hold-rebuild-lock.ready';
        $written = file_put_contents($helperScript, <<<'PHP'
<?php

declare(strict_types=1);

$lockPath = $argv[1] ?? '';
$readyPath = $argv[2] ?? '';
if ($lockPath === '' || $readyPath === '') {
    fwrite(STDERR, "missing lock or ready path\n");
    exit(1);
}

$fp = fopen($lockPath, 'c+');
if ($fp === false || !flock($fp, LOCK_EX)) {
    fwrite(STDERR, "lock acquire failed\n");
    exit(1);
}

if (file_put_contents($readyPath, 'ready', LOCK_EX) === false) {
    fwrite(STDERR, "ready marker write failed\n");
    exit(1);
}

while (!feof(STDIN)) {
    $chunk = fread(STDIN, 1024);
    if ($chunk === false) {
        break;
    }
}

@unlink($readyPath);
flock($fp, LOCK_UN);
fclose($fp);
PHP
        );
        if ($written === false) {
            self::fail('Could not write rebuild lock holder script: ' . $helperScript);
        }

        $phpBinary = defined('PHP_BINARY') ? PHP_BINARY : 'php';
        $descriptors = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'r'],
            2 => ['pipe', 'w'],
        ];
        $process = proc_open([$phpBinary, $helperScript, $lockPath, $readyPath], $descriptors, $pipes);
        if (!is_resource($process)) {
            @unlink($helperScript);
            self::fail('Could not start rebuild lock holder subprocess');
        }

        $deadline = microtime(true) + 5.0;
        while (!is_file($readyPath) && microtime(true) < $deadline) {
            usleep(10_000);
        }
        if (!is_file($readyPath)) {
            fclose($pipes[0]);
            fclose($pipes[1]);
            $stderr = stream_get_contents($pipes[2]);
            fclose($pipes[2]);
            proc_close($process);
            @unlink($helperScript);
            self::fail('Rebuild lock holder subprocess did not become ready: ' . trim((string) $stderr));
        }

        $release = function () use ($process, $pipes, $helperScript, $readyPath): void {
            if (isset($pipes[0]) && is_resource($pipes[0])) {
                fclose($pipes[0]);
            }
            if (isset($pipes[1]) && is_resource($pipes[1])) {
                fclose($pipes[1]);
            }
            if (isset($pipes[2]) && is_resource($pipes[2])) {
                fclose($pipes[2]);
            }
            proc_close($process);
            @unlink($helperScript);
            @unlink($readyPath);
        };

        $this->rebuildLockHolderRelease = $release;

        return function () use ($release): void {
            if ($this->rebuildLockHolderRelease === null) {
                return;
            }
            $release();
            $this->rebuildLockHolderRelease = null;
        };
    }

    /**
     * @return array<int, array<string, mixed>> NOTAM rows for a drawable circle TFR
     */
    private function drawableTfrNotamRows(int $now, string $notamId = 'LOCK1/2026'): array
    {
        return [
            [
                'id' => $notamId,
                'text' => $this->sampleTfrText(),
                'start_time_utc' => gmdate('Y-m-d\TH:i:s\Z', $now - 3600),
                'end_time_utc' => gmdate('Y-m-d\TH:i:s\Z', $now + 7200),
            ],
        ];
    }

    public function testNotamTfrMapLayerAggregateNeedsRebuild_MissingMap_ReturnsTrue(): void
    {
        $config = $this->minimalListedAirportConfig();
        $this->assertTrue(notamTfrMapLayerAggregateNeedsRebuild(0, $config, 3600));
    }

    public function testNotamTfrMapLayerAggregateNeedsRebuild_FreshMapAndSources_ReturnsFalse(): void
    {
        $config = $this->minimalListedAirportConfig();
        $now = time();
        $this->writePerAirportNotamCache('s83', []);
        $this->writeAggregateCache([], $now);

        $this->assertFalse(notamTfrMapLayerAggregateNeedsRebuild(
            $now,
            $config,
            3600,
            notamTfrMapLayerReadAggregateCache(),
            $now
        ));
    }

    public function testNotamTfrMapLayerAggregateNeedsRebuild_StaleBuildToken_ReturnsTrue(): void
    {
        $config = $this->minimalListedAirportConfig();
        $now = time();
        $this->writePerAirportNotamCache('s83', []);
        $this->writeAggregateCache([], $now, 'stale-token');

        $this->assertTrue(notamTfrMapLayerAggregateNeedsRebuild(
            $now,
            $config,
            3600,
            notamTfrMapLayerReadAggregateCache(),
            $now
        ));
    }

    public function testNotamTfrMapLayerCurrentBuildToken_WithGitSha_IncludesLogicVersion(): void
    {
        $originalGitSha = getenv('GIT_SHA');
        putenv('GIT_SHA=abcdef12');
        try {
            $this->assertSame(
                'abcdef1-v' . NOTAM_TFR_MAP_LAYER_LOGIC_VERSION,
                notamTfrMapLayerCurrentBuildToken()
            );
        } finally {
            if ($originalGitSha === false) {
                putenv('GIT_SHA');
            } else {
                putenv('GIT_SHA=' . $originalGitSha);
            }
        }
    }

    public function testNotamTfrMapLayerAggregateBuildTokenMatches_RejectsLegacyShaOnlyToken(): void
    {
        $originalGitSha = getenv('GIT_SHA');
        putenv('GIT_SHA=abcdef12');
        try {
            $legacy = [
                'type' => 'FeatureCollection',
                'features' => [],
                'map_layer_build_token' => 'abcdef1',
            ];
            $this->assertFalse(notamTfrMapLayerAggregateBuildTokenMatches($legacy));

            $legacy['map_layer_build_token'] = notamTfrMapLayerCurrentBuildToken();
            $this->assertTrue(notamTfrMapLayerAggregateBuildTokenMatches($legacy));
        } finally {
            if ($originalGitSha === false) {
                putenv('GIT_SHA');
            } else {
                putenv('GIT_SHA=' . $originalGitSha);
            }
        }
    }

    public function testNotamTfrMapLayerAggregateNeedsRebuild_LegacyShaOnlyToken_ReturnsTrue(): void
    {
        $originalGitSha = getenv('GIT_SHA');
        putenv('GIT_SHA=abcdef12');
        try {
            $config = $this->minimalListedAirportConfig();
            $now = time();
            $this->writePerAirportNotamCache('s83', []);
            $this->writeAggregateCache([], $now, 'abcdef1');

            $this->assertTrue(notamTfrMapLayerAggregateNeedsRebuild(
                $now,
                $config,
                3600,
                notamTfrMapLayerReadAggregateCache(),
                $now
            ));
        } finally {
            if ($originalGitSha === false) {
                putenv('GIT_SHA');
            } else {
                putenv('GIT_SHA=' . $originalGitSha);
            }
        }
    }

    public function testNotamTfrMapLayerAggregateNeedsRebuild_NewerSource_ReturnsTrue(): void
    {
        $config = $this->minimalListedAirportConfig();
        $mapTime = time() - 100;
        $this->writeAggregateCache([], $mapTime);

        $this->writePerAirportNotamCache('s83', []);
        touch(notamCacheFilePath('s83'), time());

        $this->assertTrue(notamTfrMapLayerAggregateNeedsRebuild(
            $mapTime,
            $config,
            3600,
            notamTfrMapLayerReadAggregateCache(),
            time()
        ));
    }

    public function testNotamTfrMapLayerAggregateNeedsRebuild_ExpiredTtl_ReturnsTrue(): void
    {
        $config = $this->minimalListedAirportConfig();
        $mapTime = time() - 4000;
        $this->writeAggregateCache([], $mapTime);
        $this->writePerAirportNotamCache('s83', []);
        touch(notamCacheFilePath('s83'), $mapTime);

        $this->assertTrue(notamTfrMapLayerAggregateNeedsRebuild(
            $mapTime,
            $config,
            3600,
            notamTfrMapLayerReadAggregateCache(),
            $mapTime
        ));
    }

    public function testNotamTfrMapLayerAggregateNeedsRebuild_EmptyAggregateWithDrawableSource_ReturnsTrue(): void
    {
        $config = $this->minimalListedAirportConfig();
        $now = time();
        $tfrText = $this->sampleTfrText();
        $this->writePerAirportNotamCache('s83', [
            [
                'id' => 'GAP1/2026',
                'text' => $tfrText,
                'start_time_utc' => gmdate('Y-m-d\TH:i:s\Z', $now - 3600),
                'end_time_utc' => gmdate('Y-m-d\TH:i:s\Z', $now + 7200),
            ],
        ]);
        touch(notamCacheFilePath('s83'), $now - 120);
        $this->writeAggregateCache([], $now);
        $listedCaches = notamTfrMapLayerLoadListedAirportCaches($config);

        $this->assertTrue(notamTfrMapLayerAggregateNeedsRebuild(
            $now,
            $config,
            3600,
            notamTfrMapLayerReadAggregateCache(),
            $listedCaches['newest_mtime'],
            $listedCaches,
            $now
        ));
    }

    public function testNotamTfrMapLayerAggregateNeedsRebuild_EmptyAggregateWithoutDrawableSource_ReturnsFalse(): void
    {
        $config = $this->minimalListedAirportConfig();
        $now = time();
        $this->writePerAirportNotamCache('s83', []);
        touch(notamCacheFilePath('s83'), $now - 120);
        $this->writeAggregateCache([], $now);
        $listedCaches = notamTfrMapLayerLoadListedAirportCaches($config);

        $this->assertFalse(notamTfrMapLayerAggregateNeedsRebuild(
            $now,
            $config,
            3600,
            notamTfrMapLayerReadAggregateCache(),
            $listedCaches['newest_mtime'],
            $listedCaches,
            $now
        ));
    }

    public function testNotamTfrMapLayerResolveCachedGeometry_RebuildsWhenAggregateEmptyButSourcesHaveDrawableTfr(): void
    {
        $config = $this->minimalListedAirportConfig();
        $now = time();
        $tfrText = $this->sampleTfrText();
        $this->writePerAirportNotamCache('s83', [
            [
                'id' => 'GAP2/2026',
                'text' => $tfrText,
                'start_time_utc' => gmdate('Y-m-d\TH:i:s\Z', $now - 3600),
                'end_time_utc' => gmdate('Y-m-d\TH:i:s\Z', $now + 7200),
            ],
        ]);
        touch(notamCacheFilePath('s83'), $now - 120);
        $this->writeAggregateCache([], $now);
        $listedCaches = notamTfrMapLayerLoadListedAirportCaches($config);

        $geometry = notamTfrMapLayerResolveCachedGeometry($config, 3600, $listedCaches);

        $this->assertNotEmpty($geometry['features']);
        $this->assertSame('GAP2/2026', $geometry['features'][0]['properties']['notam_id'] ?? null);
    }

    public function testNotamTfrMapLayerRebuildAggregateLocked_ReturnsNullWhenRebuildLockHeld(): void
    {
        $config = $this->minimalListedAirportConfig();
        $now = time();
        $this->writePerAirportNotamCache('s83', $this->drawableTfrNotamRows($now));
        touch(notamCacheFilePath('s83'), $now);
        $this->writeAggregateCache([], $now - 120);
        $listedCaches = notamTfrMapLayerLoadListedAirportCaches($config);
        $mapMtime = notamTfrMapLayerAggregateCacheMtime();

        $releaseLock = $this->startRebuildLockHolder();
        try {
            $this->assertNull(notamTfrMapLayerRebuildAggregateLocked(
                $config,
                $mapMtime,
                3600,
                $listedCaches,
                notamTfrMapLayerReadAggregateCache()
            ));
        } finally {
            $releaseLock();
        }
    }

    public function testNotamTfrMapLayerResolveCachedGeometry_WhenRebuildLockHeld_ServesExistingStaleAggregate(): void
    {
        $config = $this->minimalListedAirportConfig();
        $now = time();
        $this->writePerAirportNotamCache('s83', $this->drawableTfrNotamRows($now, 'WAIT1/2026'));
        touch(notamCacheFilePath('s83'), $now);
        $this->writeAggregateCache([], $now - 120);
        $listedCaches = notamTfrMapLayerLoadListedAirportCaches($config);

        $releaseLock = $this->startRebuildLockHolder();
        try {
            $geometry = notamTfrMapLayerResolveCachedGeometry($config, 3600, $listedCaches);

            $this->assertSame([], $geometry['features']);
        } finally {
            $releaseLock();
        }

        $geometryAfterLock = notamTfrMapLayerResolveCachedGeometry($config, 3600, $listedCaches);
        $this->assertNotEmpty($geometryAfterLock['features']);
        $this->assertSame('WAIT1/2026', $geometryAfterLock['features'][0]['properties']['notam_id'] ?? null);
    }

    public function testNotamTfrMapLayerResolveCachedGeometry_WhenRebuildLockHeld_ReturnsRefreshedAggregateFromDisk(): void
    {
        $config = $this->minimalListedAirportConfig();
        $now = time();
        $this->writePerAirportNotamCache('s83', $this->drawableTfrNotamRows($now, 'WAIT2/2026'));
        touch(notamCacheFilePath('s83'), $now);
        $this->writeAggregateCache([], $now - 120);

        $releaseLock = $this->startRebuildLockHolder();
        try {
            $refreshedFeatures = [
                [
                    'type' => 'Feature',
                    'id' => 'tfr-WAIT2',
                    'geometry' => [
                        'type' => 'Point',
                        'coordinates' => [-112.38, 41.65],
                    ],
                    'properties' => [
                        'notam_id' => 'WAIT2/2026',
                        'status' => 'active',
                        'geometry_kind' => 'circle',
                        'radius_nm' => 5.0,
                    ],
                ],
            ];
            $this->writeAggregateCache($refreshedFeatures, $now - 60);

            $listedCaches = notamTfrMapLayerLoadListedAirportCaches($config);
            $geometry = notamTfrMapLayerResolveCachedGeometry($config, 3600, $listedCaches);

            $this->assertCount(1, $geometry['features']);
            $this->assertSame('WAIT2/2026', $geometry['features'][0]['properties']['notam_id'] ?? null);
        } finally {
            $releaseLock();
        }
    }

    public function testNotamTfrMapLayerRebuildAggregateBlocking_ColdStartWritesAggregateFile(): void
    {
        $config = $this->minimalListedAirportConfig();
        $now = time();
        $mapPath = getNotamTfrMapLayerCachePath();
        $this->assertFileDoesNotExist($mapPath);

        $this->writePerAirportNotamCache('s83', $this->drawableTfrNotamRows($now, 'COLD1/2026'));
        touch(notamCacheFilePath('s83'), $now);
        $listedCaches = notamTfrMapLayerLoadListedAirportCaches($config);

        $payload = notamTfrMapLayerRebuildAggregateBlocking($config, 3600, $listedCaches);

        $this->assertNotNull($payload);
        $this->assertFileExists($mapPath);
        $this->assertNotEmpty($payload['features']);
        $this->assertSame('COLD1/2026', $payload['features'][0]['properties']['notam_id'] ?? null);
        $aggregate = notamTfrMapLayerReadAggregateCache();
        $this->assertNotNull($aggregate);
        $this->assertSame(notamTfrMapLayerCurrentBuildToken(), $aggregate['map_layer_build_token'] ?? null);
    }

    public function testNotamTfrMapLayerResolveCachedGeometry_ColdStartWhenAggregateMissingRebuildsOnDisk(): void
    {
        $config = $this->minimalListedAirportConfig();
        $now = time();
        $mapPath = getNotamTfrMapLayerCachePath();
        $this->assertFileDoesNotExist($mapPath);

        $this->writePerAirportNotamCache('s83', $this->drawableTfrNotamRows($now, 'COLD2/2026'));
        touch(notamCacheFilePath('s83'), $now);
        $listedCaches = notamTfrMapLayerLoadListedAirportCaches($config);

        $geometry = notamTfrMapLayerResolveCachedGeometry($config, 3600, $listedCaches);

        $this->assertFileExists($mapPath);
        $this->assertNotEmpty($geometry['features']);
        $this->assertSame('COLD2/2026', $geometry['features'][0]['properties']['notam_id'] ?? null);
    }

    private function sampleTfrText(): string
    {
        return 'ZLC UT..AIRSPACE OGDEN, UT..TEMPORARY FLIGHT RESTRICTIONS '
            . 'WITHIN AN AREA DEFINED AS 5NM RADIUS OF 413900N1122300W (OGD319029) STATIC GROUND BASED ROCKET ENGINE TEST.';
    }

    public function testNotamTfrMapLayerBuildPayload_UsesCachedStatusWhenStartTimeMissing(): void
    {
        $config = $this->minimalListedAirportConfig();
        $tfrText = $this->sampleTfrText();
        $this->writePerAirportNotamCache('s83', [
            [
                'id' => 'CACHE1/2026',
                'text' => $tfrText,
                'start_time_utc' => '',
                'end_time_utc' => gmdate('Y-m-d\TH:i:s\Z', time() + 7200),
                'status' => 'active',
            ],
        ]);

        $payload = notamTfrMapLayerBuildPayload($config);

        $this->assertCount(1, $payload['features']);
        $this->assertSame('active', $payload['features'][0]['properties']['status'] ?? null);
        $this->assertSame('active', $payload['features'][0]['properties']['map_layer_style'] ?? null);
    }

    public function testNotamTfrMapLayerBuildPayload_SkipsUnknownCachedStatusWhenStartTimeMissing(): void
    {
        $config = $this->minimalListedAirportConfig();
        $tfrText = $this->sampleTfrText();
        $this->writePerAirportNotamCache('s83', [
            [
                'id' => 'CACHE2/2026',
                'text' => $tfrText,
                'start_time_utc' => '',
                'end_time_utc' => gmdate('Y-m-d\TH:i:s\Z', time() + 7200),
                'status' => 'unknown',
            ],
        ]);

        $payload = notamTfrMapLayerBuildPayload($config);

        $this->assertSame([], $payload['features']);
    }

    public function testNotamTfrMapLayerRevalidatePayload_UpdatesStatusAndPromotesActiveOnDedup(): void
    {
        $config = $this->minimalListedAirportConfig();
        $now = strtotime('2026-05-15T20:00:00Z');
        $tfrText = $this->sampleTfrText();
        $segment = [
            'start_time_utc' => '2026-05-15T18:00:00Z',
            'end_time_utc' => '2026-05-15T22:00:00Z',
        ];
        $this->writePerAirportNotamCache('s83', [
            [
                'id' => 'A3389/2026',
                'text' => $tfrText,
                'start_time_utc' => '2026-05-15T18:00:00Z',
                'end_time_utc' => '2026-05-15T22:00:00Z',
                'effective_segments' => [$segment],
            ],
            [
                'id' => '8821/2026',
                'text' => $tfrText,
                'start_time_utc' => '2026-05-16T18:00:00Z',
                'end_time_utc' => '2026-05-16T22:00:00Z',
                'effective_segments' => [
                    [
                        'start_time_utc' => '2026-05-16T18:00:00Z',
                        'end_time_utc' => '2026-05-16T22:00:00Z',
                    ],
                ],
            ],
        ]);

        $cached = [
            'type' => 'FeatureCollection',
            'features' => [
                [
                    'type' => 'Feature',
                    'geometry' => ['type' => 'Point', 'coordinates' => [-116.08, 47.52]],
                    'properties' => [
                        'geometry_kind' => 'circle',
                        'radius_nm' => 7.0,
                        'status' => 'upcoming_future',
                        'map_layer_style' => 'upcoming',
                        'notam_id' => '8821/2026',
                        'airport_id' => 's83',
                    ],
                ],
                [
                    'type' => 'Feature',
                    'geometry' => ['type' => 'Point', 'coordinates' => [-116.08, 47.52]],
                    'properties' => [
                        'geometry_kind' => 'circle',
                        'radius_nm' => 7.0,
                        'status' => 'upcoming_future',
                        'map_layer_style' => 'upcoming',
                        'notam_id' => 'A3389/2026',
                        'airport_id' => 's83',
                    ],
                ],
            ],
            'generated_at' => $now - 500,
            'cache_ttl_seconds' => 3600,
        ];

        $out = notamTfrMapLayerRevalidatePayload($cached, $config, $now);

        $this->assertCount(1, $out['features']);
        $this->assertSame('active', $out['features'][0]['properties']['status']);
        $this->assertSame('active', $out['features'][0]['properties']['map_layer_style']);
        $this->assertSame('A3389/2026', $out['features'][0]['properties']['notam_id']);
        $this->assertSame($now, $out['generated_at']);
    }

    public function testNotamTfrMapLayerRevalidatePayload_DropsExpiredFeature(): void
    {
        $config = $this->minimalListedAirportConfig();
        $now = strtotime('2026-05-16T10:00:00Z');
        $this->writePerAirportNotamCache('s83', [
            [
                'id' => 'T1/2026',
                'text' => $this->sampleTfrText(),
                'start_time_utc' => '2026-05-15T18:00:00Z',
                'end_time_utc' => '2026-05-15T22:00:00Z',
                'effective_segments' => [
                    [
                        'start_time_utc' => '2026-05-15T18:00:00Z',
                        'end_time_utc' => '2026-05-15T22:00:00Z',
                    ],
                ],
            ],
        ]);

        $cached = [
            'type' => 'FeatureCollection',
            'features' => [
                [
                    'type' => 'Feature',
                    'geometry' => ['type' => 'Point', 'coordinates' => [-116.08, 47.52]],
                    'properties' => [
                        'geometry_kind' => 'circle',
                        'radius_nm' => 7.0,
                        'status' => 'active',
                        'map_layer_style' => 'active',
                        'notam_id' => 'T1/2026',
                        'airport_id' => 's83',
                    ],
                ],
            ],
            'generated_at' => $now - 500,
            'cache_ttl_seconds' => 3600,
        ];

        $out = notamTfrMapLayerRevalidatePayload($cached, $config, $now);

        $this->assertSame([], $out['features']);
    }

    public function testNotamTfrMapLayerRevalidatePayload_DropsNonTfrRow(): void
    {
        $config = $this->minimalListedAirportConfig();
        $now = time();
        $this->writePerAirportNotamCache('s83', [
            [
                'id' => 'RWY1/2026',
                'text' => 'RWY 17 CLSD',
                'start_time_utc' => gmdate('Y-m-d\TH:i:s\Z', $now - 3600),
                'end_time_utc' => gmdate('Y-m-d\TH:i:s\Z', $now + 3600),
            ],
        ]);

        $cached = [
            'type' => 'FeatureCollection',
            'features' => [
                [
                    'type' => 'Feature',
                    'geometry' => ['type' => 'Point', 'coordinates' => [-116.08, 47.52]],
                    'properties' => [
                        'geometry_kind' => 'circle',
                        'radius_nm' => 7.0,
                        'status' => 'active',
                        'map_layer_style' => 'active',
                        'notam_id' => 'RWY1/2026',
                        'airport_id' => 's83',
                    ],
                ],
            ],
            'generated_at' => $now - 500,
            'cache_ttl_seconds' => 3600,
        ];

        $out = notamTfrMapLayerRevalidatePayload($cached, $config, $now);

        $this->assertSame([], $out['features']);
    }

    public function testNotamTfrMapLayerServeOrRebuild_BuildsFeatureFromListedAirportCache(): void
    {
        $config = loadConfig();
        if ($config === null || !isset($config['airports']['kspb'])) {
            $this->markTestSkipped('Fixture config missing kspb');
        }

        $now = time();
        $tfrText = $this->sampleTfrText();
        $this->writePerAirportNotamCache('kspb', [
            [
                'id' => 'TFR1/2026',
                'text' => $tfrText,
                'start_time_utc' => gmdate('Y-m-d\TH:i:s\Z', $now - 3600),
                'end_time_utc' => gmdate('Y-m-d\TH:i:s\Z', $now + 7200),
            ],
        ]);

        $payload = notamTfrMapLayerServeOrRebuild();

        $this->assertNotEmpty($payload['features']);
        $this->assertSame('TFR1/2026', $payload['features'][0]['properties']['notam_id'] ?? null);
        $aggregate = notamTfrMapLayerReadAggregateCache();
        $this->assertNotNull($aggregate);
        $this->assertSame(notamTfrMapLayerCurrentBuildToken(), $aggregate['map_layer_build_token'] ?? null);
    }

    public function testNotamTfrMapLayerGeoJsonRingFromVertices_ClosedSquare_ReturnsClosedRing(): void
    {
        $vertices = [
            ['lat' => 45.0, 'lon' => -122.0],
            ['lat' => 45.0, 'lon' => -123.0],
            ['lat' => 46.0, 'lon' => -123.0],
            ['lat' => 46.0, 'lon' => -122.0],
        ];
        $ring = notamTfrMapLayerGeoJsonRingFromVertices($vertices, true);
        $this->assertNotNull($ring);
        $this->assertGreaterThanOrEqual(4, count($ring));
        $first = $ring[0];
        $last = $ring[count($ring) - 1];
        $this->assertEqualsWithDelta($first[0], $last[0], 1e-9);
        $this->assertEqualsWithDelta($first[1], $last[1], 1e-9);
    }

    public function testNotamTfrMapLayerGeoJsonRingFromCircle_DefaultSegments_ReturnsClosedRing(): void
    {
        $ring = notamTfrMapLayerGeoJsonRingFromCircle(45.0, -122.0, 5.0);
        $this->assertCount(NOTAM_TFR_MAP_CIRCLE_SEGMENTS + 1, $ring);
        $this->assertEqualsWithDelta($ring[0][0], $ring[count($ring) - 1][0], 1e-9);
        $this->assertEqualsWithDelta($ring[0][1], $ring[count($ring) - 1][1], 1e-9);
    }

    public function testNotamTfrMapLayerStyleBucket_VariousStatuses_MapsToActiveOrUpcoming(): void
    {
        $this->assertSame('active', notamTfrMapLayerStyleBucket('active'));
        $this->assertSame('upcoming', notamTfrMapLayerStyleBucket('inactive_scheduled'));
        $this->assertSame('upcoming', notamTfrMapLayerStyleBucket('upcoming_today'));
    }

    public function testNotamTfrMapLayerTooltipStatusLine_ActiveSegment_ReturnsActiveUntilLine(): void
    {
        $notam = [
            'id' => 'T1',
            'text' => '',
            'effective_segments' => [
                ['start_time_utc' => '2026-05-15T18:00:00Z', 'end_time_utc' => '2026-05-15T22:00:00Z'],
            ],
        ];
        $now = strtotime('2026-05-15T20:00:00Z');
        $line = notamTfrMapLayerTooltipStatusLine($notam, 'active', 'UTC', $now);
        $this->assertNotNull($line);
        $this->assertStringStartsWith('Active now until', $line);
        $this->assertStringContainsString('10:00 PM', $line);
        $this->assertStringContainsString('May 15, 2026', $line);
    }

    public function testNotamTfrMapLayerTooltipStatusLine_UpcomingFirstWindow_ReturnsUpcomingFromLine(): void
    {
        $notam = [
            'id' => 'T1',
            'text' => '',
            'effective_segments' => [
                ['start_time_utc' => '2026-05-15T18:00:00Z', 'end_time_utc' => '2026-05-15T22:00:00Z'],
            ],
        ];
        $now = strtotime('2026-05-15T10:00:00Z');
        $line = notamTfrMapLayerTooltipStatusLine($notam, 'upcoming_future', 'UTC', $now);
        $this->assertNotNull($line);
        $this->assertStringStartsWith('Upcoming from', $line);
        $this->assertStringContainsString(' to ', $line);
    }

    public function testNotamTfrMapLayerTooltipStatusLine_InactiveScheduledGap_ReturnsUpcomingFromLine(): void
    {
        $notam = [
            'id' => 'T1',
            'text' => '',
            'effective_segments' => [
                ['start_time_utc' => '2026-05-15T18:00:00Z', 'end_time_utc' => '2026-05-15T20:00:00Z'],
                ['start_time_utc' => '2026-05-15T22:00:00Z', 'end_time_utc' => '2026-05-16T02:00:00Z'],
            ],
        ];
        $now = strtotime('2026-05-15T21:00:00Z');
        $line = notamTfrMapLayerTooltipStatusLine($notam, 'inactive_scheduled', 'UTC', $now);
        $this->assertNotNull($line);
        $this->assertStringStartsWith('Upcoming from', $line);
        $this->assertStringContainsString('10:00 PM', $line);
    }

    public function testNotamTfrMapLayerTooltipStatusLine_EnvelopeOnlyActive_ReturnsActiveUntilLine(): void
    {
        $notam = [
            'id' => 'T2',
            'text' => '',
            'start_time_utc' => '2026-05-15T12:00:00Z',
            'end_time_utc' => '2026-05-15T23:59:59Z',
        ];
        $now = strtotime('2026-05-15T15:00:00Z');
        $line = notamTfrMapLayerTooltipStatusLine($notam, 'active', 'UTC', $now);
        $this->assertNotNull($line);
        $this->assertStringStartsWith('Active now until', $line);
    }

    public function testNotamTfrMapLayerDeduplicateFeaturesByGeometry_ActiveAndUpcomingCircle_KeepsActive(): void
    {
        $active = [
            'type' => 'Feature',
            'geometry' => ['type' => 'Point', 'coordinates' => [-116.08, 47.52]],
            'properties' => [
                'geometry_kind' => 'circle',
                'radius_nm' => 7.0,
                'status' => 'active',
                'map_layer_style' => 'active',
                'notam_id' => 'A3389/2026',
            ],
        ];
        $upcoming = [
            'type' => 'Feature',
            'geometry' => ['type' => 'Point', 'coordinates' => [-116.08, 47.52]],
            'properties' => [
                'geometry_kind' => 'circle',
                'radius_nm' => 7.0,
                'status' => 'upcoming_future',
                'map_layer_style' => 'upcoming',
                'notam_id' => '8821/2026',
            ],
        ];

        $deduped = notamTfrMapLayerDeduplicateFeaturesByGeometry([$upcoming, $active]);

        $this->assertCount(1, $deduped);
        $this->assertSame('active', $deduped[0]['properties']['status']);
        $this->assertSame('A3389/2026', $deduped[0]['properties']['notam_id']);
    }

    public function testNotamTfrMapLayerDeduplicateFeaturesByGeometry_ScheduledGapAndUpcomingCircle_KeepsScheduledGap(): void
    {
        $scheduled = [
            'type' => 'Feature',
            'geometry' => ['type' => 'Point', 'coordinates' => [-116.08, 47.52]],
            'properties' => [
                'geometry_kind' => 'circle',
                'radius_nm' => 7.0,
                'status' => 'inactive_scheduled',
                'notam_id' => 'A1001/2026',
            ],
        ];
        $upcoming = [
            'type' => 'Feature',
            'geometry' => ['type' => 'Point', 'coordinates' => [-116.08, 47.52]],
            'properties' => [
                'geometry_kind' => 'circle',
                'radius_nm' => 7.0,
                'status' => 'upcoming_future',
                'notam_id' => 'A1002/2026',
            ],
        ];

        $deduped = notamTfrMapLayerDeduplicateFeaturesByGeometry([$upcoming, $scheduled]);

        $this->assertCount(1, $deduped);
        $this->assertSame('inactive_scheduled', $deduped[0]['properties']['status']);
    }

    public function testNotamTfrMapLayerDeduplicateFeaturesByGeometry_DistinctCircles_KeepsBoth(): void
    {
        $a = [
            'type' => 'Feature',
            'geometry' => ['type' => 'Point', 'coordinates' => [-116.08, 47.52]],
            'properties' => [
                'geometry_kind' => 'circle',
                'radius_nm' => 7.0,
                'status' => 'upcoming_today',
                'notam_id' => 'A1',
            ],
        ];
        $b = [
            'type' => 'Feature',
            'geometry' => ['type' => 'Point', 'coordinates' => [-117.08, 48.52]],
            'properties' => [
                'geometry_kind' => 'circle',
                'radius_nm' => 7.0,
                'status' => 'upcoming_today',
                'notam_id' => 'A2',
            ],
        ];

        $deduped = notamTfrMapLayerDeduplicateFeaturesByGeometry([$a, $b]);

        $this->assertCount(2, $deduped);
    }
}
