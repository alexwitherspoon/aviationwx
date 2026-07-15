<?php
/**
 * SAFETY-CRITICAL: Window mean wind for density altitude operational end selection.
 */

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../lib/weather/history.php';
require_once __DIR__ . '/../../lib/config.php';

class WindowMeanWindTest extends TestCase
{
    private $originalConfigPath;
    private string $testConfigDir;
    private string $testConfigFile;
    private string $testAirportId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->originalConfigPath = getenv('CONFIG_PATH');
        $this->testConfigDir = sys_get_temp_dir() . '/aviationwx_wmwind_' . uniqid();
        mkdir($this->testConfigDir, 0755, true);
        $this->testConfigFile = $this->testConfigDir . '/airports.json';
        $this->testAirportId = 'wmw' . substr(uniqid(), -4);
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

    public function testReturnsNullWhenInsufficientObservations(): void
    {
        $baseTime = time();
        $this->appendObs($baseTime - 600, 10.0, 270.0);
        $this->appendObs($baseTime - 300, 10.0, 270.0);

        $this->assertNull(computeWindowMeanWind($this->testAirportId));
    }

    public function testReturnsNullWhenMeanSpeedBelowMinimum(): void
    {
        $baseTime = time();
        $this->appendObs($baseTime - 900, 4.0, 270.0);
        $this->appendObs($baseTime - 600, 4.0, 270.0);
        $this->appendObs($baseTime - 300, 4.0, 270.0);

        $this->assertNull(computeWindowMeanWind($this->testAirportId));
    }

    public function testReturnsNullWhenWindHighlyVariable(): void
    {
        $baseTime = time();
        $this->appendObs($baseTime - 900, 10.0, 0.0);
        $this->appendObs($baseTime - 600, 10.0, 90.0);
        $this->appendObs($baseTime - 300, 10.0, 180.0);

        $this->assertNull(computeWindowMeanWind($this->testAirportId));
    }

    public function testConsistentWindReturnsVectorMean(): void
    {
        $baseTime = time();
        $this->appendObs($baseTime - 900, 10.0, 270.0);
        $this->appendObs($baseTime - 600, 12.0, 270.0);
        $this->appendObs($baseTime - 300, 8.0, 270.0);

        $result = computeWindowMeanWind($this->testAirportId);
        $this->assertNotNull($result);
        $this->assertSame(3, $result['observation_count']);
        $this->assertEqualsWithDelta(270.0, $result['direction_magnetic'], 1.0);
        $this->assertEqualsWithDelta(10.0, $result['speed_kts'], 0.5);
        $this->assertLessThanOrEqual(DA_PERF_VARIABLE_WIND_RATIO, $result['dispersion_ratio']);
    }

    public function testPrefersMagneticNorthFromWindDirectionObject(): void
    {
        $baseTime = time();
        $airport = ['magnetic_declination' => 14.0];
        $this->appendObs($baseTime - 900, 10.0, [
            'true_north' => 284,
            'magnetic_north' => 270,
            'variable' => false,
        ]);
        $this->appendObs($baseTime - 600, 10.0, [
            'true_north' => 284,
            'magnetic_north' => 270,
            'variable' => false,
        ]);
        $this->appendObs($baseTime - 300, 10.0, [
            'true_north' => 284,
            'magnetic_north' => 270,
            'variable' => false,
        ]);

        $result = computeWindowMeanWind($this->testAirportId, $airport);
        $this->assertNotNull($result);
        $this->assertEqualsWithDelta(270.0, $result['direction_magnetic'], 1.0);
    }

    public function testUsesObsTimeNotObservationTime(): void
    {
        $baseTime = time();
        $history = loadWeatherHistory($this->testAirportId);
        $history['observations'][] = [
            'obs_time' => $baseTime - 300,
            'observation_time' => $baseTime - 7200,
            'wind_speed' => 10.0,
            'wind_direction' => 270.0,
        ];
        $history['observations'][] = [
            'obs_time' => $baseTime - 600,
            'observation_time' => $baseTime - 7200,
            'wind_speed' => 10.0,
            'wind_direction' => 270.0,
        ];
        $history['observations'][] = [
            'obs_time' => $baseTime - 900,
            'observation_time' => $baseTime - 7200,
            'wind_speed' => 10.0,
            'wind_direction' => 270.0,
        ];
        saveWeatherHistory($this->testAirportId, $history);

        $result = computeWindowMeanWind($this->testAirportId);
        $this->assertNotNull($result);
        $this->assertSame(3, $result['observation_count']);
    }

    public function testIgnoresMalformedHistoryObservations(): void
    {
        $baseTime = time();
        $history = [
            'airport_id' => $this->testAirportId,
            'observations' => [
                'not-an-array',
                ['obs_time' => 'bad', 'wind_speed' => 10.0, 'wind_direction' => 270.0],
                [
                    'obs_time' => $baseTime - 900,
                    'wind_speed' => 10.0,
                    'wind_direction' => 270.0,
                ],
                [
                    'obs_time' => $baseTime - 600,
                    'wind_speed' => 10.0,
                    'wind_direction' => 270.0,
                ],
                [
                    'obs_time' => $baseTime - 300,
                    'wind_speed' => 10.0,
                    'wind_direction' => 270.0,
                ],
            ],
        ];
        saveWeatherHistory($this->testAirportId, $history);

        $result = computeWindowMeanWind($this->testAirportId);
        $this->assertNotNull($result);
        $this->assertSame(3, $result['observation_count']);
    }
}
