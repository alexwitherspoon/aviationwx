<?php

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../lib/nasr/cache.php';

/**
 * build-runway-fusion.php worker: end-to-end build, not-due skip, and
 * fail-closed publish against a disposable cache (TEST_CACHE_DIR seam).
 */
class RunwayFusionWorkerTest extends TestCase
{
    private string $root;
    private string $tmpDir = '';

    protected function setUp(): void
    {
        $this->root = dirname(__DIR__, 2);
        $this->tmpDir = sys_get_temp_dir() . '/aviationwx_fusion_worker_' . getmypid() . '_' . mt_rand();
        $this->assertDirectoryDoesNotExist($this->tmpDir);
    }

    protected function tearDown(): void
    {
        $this->removeDir($this->tmpDir);
        parent::tearDown();
    }

    public function testWorker_BuildsArtifactFromNasrAndMergeCache(): void
    {
        $cacheDir = $this->seedWorkerInputs();

        [$exitCode, $stdout, $stderr] = $this->runWorker($cacheDir);

        $this->assertSame(0, $exitCode, "worker failed:\n{$stdout}\n{$stderr}");

        $artifactPath = $cacheDir . '/runways/runway_fusion.json';
        $this->assertFileExists($artifactPath);

        $artifact = json_decode((string) file_get_contents($artifactPath), true);
        $this->assertIsArray($artifact);
        $this->assertSame(1, $artifact['schema_version']);
        $this->assertSame('2026-10-01', $artifact['nasr_effective_date']);
        $this->assertArrayHasKey('42b', $artifact['airports']);

        $entry = $artifact['airports']['42b'];
        $byKey = [];
        foreach ($entry['rows'] as $row) {
            $byKey[$row['key']] = $row;
        }
        $this->assertCount(2, $byKey);
        $this->assertSame('nasr', $byKey['14/32']['source']);
        $this->assertSame('2026-10-01', $byKey['14/32']['effective_date']);
        $this->assertTrue($byKey['16W/34W']['is_water']);

        // 14/32 has FAA geometry; only the water strip derives a segment.
        $this->assertCount(1, $entry['segments']);
        $this->assertSame('16W', $entry['segments'][0]['le_ident']);
        $this->assertTrue($entry['segments'][0]['water']);
    }

    public function testWorker_SkipsRebuildWhenArtifactCurrent(): void
    {
        $cacheDir = $this->seedWorkerInputs();

        [$exitCode] = $this->runWorker($cacheDir);
        $this->assertSame(0, $exitCode);

        $artifactPath = $cacheDir . '/runways/runway_fusion.json';
        $this->assertFileExists($artifactPath);

        // Age the file mtime (the policy keys off built_at and input stamps,
        // not the file mtime): a skip leaves it untouched, a rebuild rewrites.
        $agedMtime = time() - 100;
        $this->assertTrue(touch($artifactPath, $agedMtime, $agedMtime));

        [$exitCode, $stdout, $stderr] = $this->runWorker($cacheDir);
        $this->assertSame(0, $exitCode, "worker failed on second run:\n{$stdout}\n{$stderr}");

        $this->assertSame(
            $agedMtime,
            (int) filemtime($artifactPath),
            'second run must not rewrite a current artifact'
        );
    }

    public function testWorker_FailsClosedWhenNoAirportProducesRows(): void
    {
        $cacheDir = $this->tmpDir . '/cache';
        $configPath = $this->writeConfig(['emptyfield' => [
            'name' => 'Empty Field',
            'enabled' => true,
            'lat' => 35.0,
            'lon' => -100.0,
        ]]);

        [$exitCode, $stdout, $stderr] = $this->runWorker($cacheDir, $configPath);

        $this->assertSame(1, $exitCode, "worker must fail closed without rows:\n{$stdout}\n{$stderr}");
        $this->assertFileDoesNotExist($cacheDir . '/runways/runway_fusion.json');
    }

    // --- Helpers ------------------------------------------------------------

    /**
     * Seed the temp cache with a NASR full cache and a runways merge cache
     * for 42B, and a config with one enabled 42B airport. Returns the cache
     * directory.
     */
    private function seedWorkerInputs(): string
    {
        $cacheDir = $this->tmpDir . '/cache';
        @mkdir($cacheDir . '/nasr', 0755, true);
        @mkdir($cacheDir . '/runways', 0755, true);

        $nasr = [
            'schema_version' => NASR_APT_SCHEMA_VERSION,
            'effective_date' => '2026-10-01',
            'airports' => [
                '42B' => [
                    'arpt_id' => '42B',
                    'icao_id' => null,
                    'notam_id' => 'BDR',
                    'elev_ft' => 9,
                    'lat' => 41.44565444,
                    'lon' => -72.4556425,
                    'mag_declination_deg' => -14.0,
                    'mag_declination_year' => 1985,
                    'runways' => [
                        [
                            'rwy_id' => '14/32',
                            'length_ft' => 2120,
                            'width_ft' => 50,
                            'surface' => 'ASPH',
                            'condition' => 'GOOD',
                            'lights_code' => 'LOW',
                            'ends' => [
                                ['end_id' => '14', 'true_alignment' => null],
                                ['end_id' => '32', 'true_alignment' => null],
                            ],
                        ],
                        [
                            'rwy_id' => '16W/34W',
                            'length_ft' => 4500,
                            'width_ft' => 1000,
                            'surface' => 'WATER',
                            'condition' => 'GOOD',
                            'lights_code' => null,
                            'ends' => [
                                ['end_id' => '16W', 'true_alignment' => null],
                                ['end_id' => '34W', 'true_alignment' => null],
                            ],
                        ],
                    ],
                ],
            ],
        ];
        file_put_contents($cacheDir . '/nasr/nasr_apt.json', json_encode($nasr));

        // Meta as the fetch worker leaves it: the configured slice carries no
        // cycle date, so the fusion worker reads it from here.
        file_put_contents($cacheDir . '/nasr/nasr_meta.json', json_encode([
            'effective_date' => '2026-10-01',
        ]));

        $runways = [
            'airports' => [
                '42B' => [
                    'segments' => [
                        [
                            'start' => [0.9, -0.035],
                            'end' => [-0.9, 0.035],
                            'le_ident' => '14',
                            'he_ident' => '32',
                            'ident_at_start' => '32',
                            'ident_at_end' => '14',
                            'source' => 'faa',
                        ],
                    ],
                    'display_runways' => [
                        [
                            'rwy_id' => '14/32',
                            'length_ft' => 2120,
                            'width_ft' => 50,
                            'surface' => 'ASPH',
                            'lighted' => true,
                            'closed' => false,
                            'ends' => [['end_id' => '14'], ['end_id' => '32']],
                        ],
                        [
                            'rwy_id' => '16W/34W',
                            'length_ft' => 4500,
                            'width_ft' => 1000,
                            'surface' => 'WATER',
                            'lighted' => false,
                            'closed' => false,
                            'ends' => [['end_id' => '16W'], ['end_id' => '34W']],
                        ],
                    ],
                ],
            ],
        ];
        file_put_contents($cacheDir . '/runways/runways_data.json', json_encode($runways));

        $this->writeConfig(['42b' => [
            'name' => 'Goodspeed Airport',
            'enabled' => true,
            'faa' => '42B',
            'ourairports_ident' => '42B',
            'lat' => 41.44565444,
            'lon' => -72.4556425,
            'elevation_ft' => 9,
            'magnetic_declination' => -14.0,
        ]]);

        return $cacheDir;
    }

    /**
     * Write a temp airports.json from the repo test fixture (valid config
     * block) with the given airports. Returns the config path.
     */
    private function writeConfig(array $airports): string
    {
        $baseRaw = (string) file_get_contents($this->root . '/tests/Fixtures/airports.json.test');
        $base = json_decode($baseRaw, true);
        $this->assertIsArray($base, 'airports.json.test fixture must decode');
        $base['airports'] = $airports;

        $configPath = $this->tmpDir . '/airports.json';
        if (!is_dir($this->tmpDir)) {
            @mkdir($this->tmpDir, 0755, true);
        }
        file_put_contents($configPath, json_encode($base, JSON_PRETTY_PRINT));

        return $configPath;
    }

    /**
     * @return array{0: int, 1: string, 2: string} exit code, stdout, stderr
     */
    private function runWorker(string $cacheDir, ?string $configPath = null): array
    {
        if ($configPath === null) {
            $configPath = $this->tmpDir . '/airports.json';
        }

        $php = PHP_BINARY !== '' && PHP_BINARY !== false ? PHP_BINARY : 'php';
        $cmd = sprintf(
            '%s %s',
            escapeshellarg($php),
            escapeshellarg($this->root . '/scripts/build-runway-fusion.php')
        );

        $env = array_merge($_ENV, [
            'TEST_CACHE_DIR' => $cacheDir,
            'CONFIG_PATH' => $configPath,
            'APP_ENV' => '',
        ]);

        $descriptors = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];
        $proc = proc_open($cmd, $descriptors, $pipes, $this->root, $env);
        $this->assertIsResource($proc);
        fclose($pipes[0]);
        $stdout = (string) stream_get_contents($pipes[1]);
        $stderr = (string) stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);

        return [proc_close($proc), $stdout, $stderr];
    }

    private function removeDir(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        $items = scandir($dir) ?: [];
        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            $path = $dir . '/' . $item;
            if (is_dir($path)) {
                $this->removeDir($path);
            } else {
                @unlink($path);
            }
        }
        @rmdir($dir);
    }
}
