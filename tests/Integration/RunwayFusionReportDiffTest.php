<?php

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../lib/nasr/cache.php';
require_once __DIR__ . '/../../lib/runway-fusion.php';
require_once __DIR__ . '/../../lib/runways.php';

/**
 * report-runway-fusion-diff.php maintainer script: per-airport diff output,
 * focused on the diagram-additions rule for manual compass airports.
 */
class RunwayFusionReportDiffTest extends TestCase
{
    private string $root;
    private string $tmpDir = '';
    private array $nasr = [];
    private array $runways = [];
    private array $airports = [];

    protected function setUp(): void
    {
        $this->root = dirname(__DIR__, 2);
        $this->tmpDir = sys_get_temp_dir() . '/aviationwx_fusion_report_' . getmypid() . '_' . mt_rand();
        $this->assertDirectoryDoesNotExist($this->tmpDir);
    }

    protected function tearDown(): void
    {
        $this->removeDir($this->tmpDir);
        parent::tearDown();
    }

    public function testManualCompassAirport_ReportsNoDiagramAdditions(): void
    {
        $cacheDir = $this->seedReportInputs();

        [$exitCode, $stdout, $stderr] = $this->runReport($cacheDir);
        $this->assertSame(0, $exitCode, "report failed:\n{$stdout}\n{$stderr}");

        $out = json_decode(trim($stdout), true);
        $this->assertIsArray($out);

        $byAirport = [];
        foreach ($out['diffs'] as $diff) {
            $byAirport[$diff['airport']] = $diff;
        }

        // Precondition: the fused inventory derives a segment for 42b's
        // geometry-less 14/32 NASR pair. Without the manual-compass guard the
        // report would list that as a diagram addition, even though the live
        // compass for a manual airport draws only the manual runways.
        $manualCompass = $this->airports['42b'];
        $fused = runwayFusionBuildAirportEntry('42b', $manualCompass, $this->nasr, $this->runways);
        $this->assertIsArray($fused);
        $derived = [];
        foreach ($fused['segments'] as $seg) {
            $derived[] = trim((string) $seg['le_ident']) . '/' . trim((string) $seg['he_ident']);
        }
        $this->assertContains('14/32', $derived);

        // The guard means 42b reports no diff at all, diagram included.
        $this->assertArrayNotHasKey('42b', $byAirport);

        // Non-manual airport, same cache: the water pair without geometry is
        // still reported as a derived addition next to the cached 14/32 line.
        $this->assertArrayHasKey('42c', $byAirport);
        $pairs = [];
        foreach ($byAirport['42c']['diagram_added'] ?? [] as $seg) {
            $pairs[$seg['pair']] = $seg;
        }
        $this->assertArrayHasKey('16W/34W', $pairs);
        $this->assertTrue($pairs['16W/34W']['water']);
    }

    // --- Helpers ------------------------------------------------------------

    /**
     * Seed a temp cache with a configured NASR slice and a runways merge
     * cache, and a config with one manual-compass airport (42b) and one
     * geometry-backed airport (42c). Returns the cache directory.
     */
    private function seedReportInputs(): string
    {
        $cacheDir = $this->tmpDir . '/cache';
        @mkdir($cacheDir . '/nasr', 0755, true);
        @mkdir($cacheDir . '/runways', 0755, true);

        $this->nasr = [
            'schema_version' => NASR_APT_SCHEMA_VERSION,
            'airports' => [
                '42B' => [
                    'arpt_id' => '42B',
                    'lat' => 41.44565444,
                    'lon' => -72.4556425,
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
                    ],
                ],
                '42C' => [
                    'arpt_id' => '42C',
                    'lat' => 42.0,
                    'lon' => -72.0,
                    'runways' => [
                        [
                            'rwy_id' => '14/32',
                            'length_ft' => 5000,
                            'width_ft' => 100,
                            'surface' => 'ASPH',
                            'condition' => 'GOOD',
                            'lights_code' => 'FULL',
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
        file_put_contents($cacheDir . '/nasr/nasr_apt_configured.json', json_encode($this->nasr));

        $this->runways = [
            'airports' => [
                '42C' => [
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
                ],
            ],
        ];
        file_put_contents($cacheDir . '/runways/runways_data.json', json_encode($this->runways));

        $this->airports = [
            '42b' => [
                'name' => 'Manual Compass Field',
                'enabled' => true,
                'faa' => '42B',
                'lat' => 41.44565444,
                'lon' => -72.4556425,
                'magnetic_declination' => -14.0,
                'runways' => [
                    ['name' => '16W/34W', 'heading_1' => 160, 'heading_2' => 340],
                ],
            ],
            '42c' => [
                'name' => 'Cached Geometry Field',
                'enabled' => true,
                'faa' => '42C',
                'lat' => 42.0,
                'lon' => -72.0,
                'magnetic_declination' => -14.0,
            ],
        ];
        $this->writeConfig($this->airports);

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
    private function runReport(string $cacheDir): array
    {
        $configPath = $this->tmpDir . '/airports.json';

        $php = PHP_BINARY !== '' && PHP_BINARY !== false ? PHP_BINARY : 'php';
        $cmd = sprintf(
            '%s %s --json',
            escapeshellarg($php),
            escapeshellarg($this->root . '/scripts/report-runway-fusion-diff.php')
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