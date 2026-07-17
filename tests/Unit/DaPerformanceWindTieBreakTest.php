<?php
/**
 * Wind-based tie-breaking for equal-risk departure ends.
 */

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../lib/config.php';
require_once __DIR__ . '/../../lib/weather/history.php';
require_once __DIR__ . '/../../lib/weather/density-altitude-performance.php';

class DaPerformanceWindTieBreakTest extends TestCase
{
    private $originalConfigPath;
    private string $testConfigDir;
    private string $testConfigFile;
    private string $testAirportId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->originalConfigPath = getenv('CONFIG_PATH');
        $this->testConfigDir = sys_get_temp_dir() . '/aviationwx_dawind_' . uniqid();
        mkdir($this->testConfigDir, 0755, true);
        $this->testConfigFile = $this->testConfigDir . '/airports.json';
        $this->testAirportId = 'daw' . substr(uniqid(), -4);
        $this->createTestConfig([
            'config' => [
                'public_api' => [
                    'enabled' => true,
                    'weather_history_enabled' => true,
                    'weather_history_retention_hours' => 24,
                    'wind_rose_window_hours' => 1,
                ],
            ],
            'airports' => [],
        ]);
    }

    protected function tearDown(): void
    {
        if ($this->originalConfigPath !== false) {
            putenv('CONFIG_PATH=' . $this->originalConfigPath);
        } else {
            putenv('CONFIG_PATH');
        }
        $historyFile = getWeatherHistoryFilePath($this->testAirportId);
        if (file_exists($historyFile)) {
            @unlink($historyFile);
        }
        if (is_dir($this->testConfigDir)) {
            @array_map('unlink', glob($this->testConfigDir . '/*'));
            @rmdir($this->testConfigDir);
        }
        if (function_exists('apcu_clear_cache')) {
            @apcu_clear_cache();
        }
        parent::tearDown();
    }

    /**
     * @param array<string, mixed> $config
     */
    private function createTestConfig(array $config): void
    {
        file_put_contents($this->testConfigFile, json_encode($config));
        putenv('CONFIG_PATH=' . $this->testConfigFile);
        if (function_exists('apcu_clear_cache')) {
            @apcu_clear_cache();
        }
    }

    private function appendObs(int $obsTime, float $windSpeed, $windDirection): void
    {
        appendWeatherHistory($this->testAirportId, [
            'obs_time_primary' => $obsTime,
            'wind_speed' => $windSpeed,
            'wind_direction' => $windDirection,
        ]);
    }

    public function testCrosswindKtsForPureCrosswind(): void
    {
        $cross = densityAltitudePerformanceCrosswindKts(90.0, 10.0, 0);
        $this->assertEqualsWithDelta(10.0, $cross, 0.001);
    }

    public function testHeadwindKtsForRunwayIntoWind(): void
    {
        $head = densityAltitudePerformanceHeadwindKts(270.0, 12.0, 270);
        $this->assertGreaterThan(11.0, $head);
    }

    public function testPickBestEndPrefersHeadwindWhenCrosswindEqual(): void
    {
        $scoredEnds = [
            [
                'total_risk' => 1.5,
                'end_id' => '25',
                'rwy_id' => '07/25',
                'true_alignment' => 250,
                'risk152' => 0.0,
                'risk172' => 0.0,
                'risk182' => 0.0,
            ],
            [
                'total_risk' => 1.5,
                'end_id' => '07',
                'rwy_id' => '07/25',
                'true_alignment' => 70,
                'risk152' => 0.0,
                'risk172' => 0.0,
                'risk182' => 0.0,
            ],
        ];

        $picked = pickBestWorstScoredEnds($scoredEnds, 250.0, 10.0);

        $this->assertSame('25', $picked['best']['end_id']);
    }

    public function testPickWorstEndSelectsHighestRisk(): void
    {
        $scoredEnds = [
            [
                'total_risk' => 1.0,
                'end_id' => '07',
                'rwy_id' => '07/25',
                'true_alignment' => 70,
                'risk152' => 0.0,
                'risk172' => 0.0,
                'risk182' => 0.0,
            ],
            [
                'total_risk' => 2.5,
                'end_id' => '25',
                'rwy_id' => '07/25',
                'true_alignment' => 250,
                'risk152' => 0.0,
                'risk172' => 0.0,
                'risk182' => 0.0,
            ],
        ];

        $picked = pickBestWorstScoredEnds($scoredEnds, null, null);

        $this->assertSame('07', $picked['best']['end_id']);
        $this->assertSame('25', $picked['worst']['end_id']);
        $this->assertGreaterThan($picked['best']['total_risk'], $picked['worst']['total_risk']);
    }

    public function testPickBestEndFallsBackToEndIdWithoutWind(): void
    {
        $scoredEnds = [
            [
                'total_risk' => 1.0,
                'end_id' => '25',
                'rwy_id' => '07/25',
                'true_alignment' => 250,
                'risk152' => 0.0,
                'risk172' => 0.0,
                'risk182' => 0.0,
            ],
            [
                'total_risk' => 1.0,
                'end_id' => '07',
                'rwy_id' => '07/25',
                'true_alignment' => 70,
                'risk152' => 0.0,
                'risk172' => 0.0,
                'risk182' => 0.0,
            ],
        ];

        $picked = pickBestWorstScoredEnds($scoredEnds, null, null);

        $this->assertSame('07', $picked['best']['end_id']);
    }

    public function testResolveWindFromPublicApiShapeUsesSnapshotWhenNoHistory(): void
    {
        $wind = resolveDensityAltitudePerformanceWind([
            'wind_direction' => [
                'true_north' => 270,
                'magnetic_north' => 255,
                'variable' => false,
            ],
            'wind_speed' => 8,
        ], ['id' => $this->testAirportId], $this->testAirportId);

        $this->assertEqualsWithDelta(270.0, $wind['direction'], 0.001);
        $this->assertEqualsWithDelta(8.0, $wind['speed'], 0.001);
    }

    public function testResolveWindPrefersWindowMeanOverSnapshot(): void
    {
        $baseTime = time();
        $this->appendObs($baseTime - 900, 10.0, 90.0);
        $this->appendObs($baseTime - 600, 10.0, 90.0);
        $this->appendObs($baseTime - 300, 10.0, 90.0);

        $wind = resolveDensityAltitudePerformanceWind([
            'wind_direction' => 250,
            'wind_speed' => 12,
        ], ['id' => $this->testAirportId], $this->testAirportId);

        $this->assertEqualsWithDelta(90.0, $wind['direction'], 1.0);
        $this->assertEqualsWithDelta(10.0, $wind['speed'], 0.5);
    }

    public function testPickBestEndUsesWindowMeanWindForEqualRisk(): void
    {
        $baseTime = time();
        $this->appendObs($baseTime - 900, 10.0, 90.0);
        $this->appendObs($baseTime - 600, 10.0, 90.0);
        $this->appendObs($baseTime - 300, 10.0, 90.0);

        $scoredEnds = [
            [
                'total_risk' => 1.5,
                'end_id' => '25',
                'rwy_id' => '07/25',
                'true_alignment' => 250,
                'risk152' => 0.0,
                'risk172' => 0.0,
                'risk182' => 0.0,
            ],
            [
                'total_risk' => 1.5,
                'end_id' => '07',
                'rwy_id' => '07/25',
                'true_alignment' => 70,
                'risk152' => 0.0,
                'risk172' => 0.0,
                'risk182' => 0.0,
            ],
        ];

        $wind = resolveDensityAltitudePerformanceWind([
            'wind_direction' => 250,
            'wind_speed' => 12,
        ], ['id' => $this->testAirportId], $this->testAirportId);
        $picked = pickBestWorstScoredEnds($scoredEnds, $wind['direction'], $wind['speed']);
        $this->assertSame('07', $picked['best']['end_id']);

        $snapshot = resolveDensityAltitudePerformanceSnapshotWind([
            'wind_direction' => 250,
            'wind_speed' => 12,
        ]);
        $pickedSnapshot = pickBestWorstScoredEnds(
            $scoredEnds,
            $snapshot['direction'],
            $snapshot['speed']
        );
        $this->assertSame('25', $pickedSnapshot['best']['end_id']);
    }

    public function testComputeUsesWindowMeanWindTieBreakForEqualRiskOr81Ends(): void
    {
        $baseTime = time();
        foreach ([900, 600, 300] as $offsetSeconds) {
            appendWeatherHistory('or81', [
                'obs_time_primary' => $baseTime - $offsetSeconds,
                'wind_speed' => 10.0,
                'wind_direction' => 90.0,
            ]);
        }

        require_once __DIR__ . '/../../lib/nasr/cache.php';
        resetNasrAptCacheMemo();
        setNasrAptCacheForTesting([
            'schema_version' => NASR_APT_SCHEMA_VERSION,
            'airports' => [
                'OR81' => [
                    'runways' => [[
                        'rwy_id' => '07/25',
                        'length_ft' => 2000,
                        'surface' => 'TURF-GRVL',
                        'condition' => '',
                        'ends' => [
                            ['end_id' => '07', 'true_alignment' => 70, 'obstruction' => []],
                            [
                                'end_id' => '25',
                                'true_alignment' => 250,
                                'obstruction' => ['type' => 'TREES', 'hgt_ft' => 100.0, 'dist_ft' => 2000.0],
                            ],
                        ],
                    ]],
                ],
            ],
        ]);

        $result = computeDensityAltitudePerformance([
            'density_altitude' => 1531,
            'pressure_altitude' => 85,
            'temperature' => 26.0,
            'wind_direction' => 250,
            'wind_speed' => 12,
        ], [
            'id' => 'or81',
            'faa' => 'OR81',
            'elevation_ft' => 185,
            'magnetic_declination' => 13,
            'runways' => [
                ['name' => '07/25', 'heading_1' => 70, 'heading_2' => 250],
            ],
        ], 'or81');

        $this->assertIsArray($result);
        $this->assertEqualsWithDelta(
            $result['best_end']['total_risk'],
            $result['worst_end']['total_risk'],
            0.001
        );
        $this->assertSame('07', $result['best_end']['end_id']);

        $historyFile = getWeatherHistoryFilePath('or81');
        if (file_exists($historyFile)) {
            @unlink($historyFile);
        }
    }
}
