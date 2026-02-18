<?php
/**
 * Wind Rose Safety-Critical Tests
 *
 * SAFETY OF FLIGHT: Wind rose petals display last-hour wind distribution.
 * Incorrect sector assignment or averaging could mislead pilots.
 *
 * Reference: WMO/ICAO wind direction convention - direction is FROM (meteorological).
 * Sectors: N=0, NE=1, E=2, SE=3, S=4, SW=5, W=6, NW=7 (each 45° wide).
 */

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../lib/weather/history.php';

class WindRoseSafetyTest extends TestCase
{
    private $originalConfigPath;
    private $testConfigDir;
    private $testConfigFile;
    private $testAirportId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->originalConfigPath = getenv('CONFIG_PATH');
        $this->testConfigDir = sys_get_temp_dir() . '/aviationwx_windrose_' . uniqid();
        mkdir($this->testConfigDir, 0755, true);
        $this->testConfigFile = $this->testConfigDir . '/airports.json';
        $this->testAirportId = 'wrt' . substr(uniqid(), -4);
        $this->createTestConfig([
            'config' => [
                'public_api' => [
                    'enabled' => true,
                    'weather_history_enabled' => true,
                    'weather_history_retention_hours' => 24,
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

    private function createTestConfig(array $config): void
    {
        file_put_contents($this->testConfigFile, json_encode($config));
        putenv('CONFIG_PATH=' . $this->testConfigFile);
        if (function_exists('apcu_clear_cache')) {
            @apcu_clear_cache();
        }
    }

    private function appendObs(int $obsTime, float $windSpeed, ?float $windDirection): void
    {
        appendWeatherHistory($this->testAirportId, [
            'obs_time_primary' => $obsTime,
            'wind_speed' => $windSpeed,
            'wind_direction' => $windDirection,
        ]);
    }

    // ============================================================================
    // SECTOR ASSIGNMENT - Reference values per WMO/ICAO convention
    // ============================================================================

    /**
     * Wind from 0° (North) must map to sector 0 (N)
     */
    public function testSectorAssignment_NorthWind_GoesToSector0(): void
    {
        $baseTime = time();
        $this->appendObs($baseTime - 600, 10.0, 0.0);

        $petals = computeLastHourWindRose($this->testAirportId);
        $this->assertNotNull($petals);
        $this->assertEqualsWithDelta(10.0, $petals[0], 0.1, '0° (N) must map to sector 0');
    }

    /**
     * Wind from 90° (East) must map to sector 4 (E)
     */
    public function testSectorAssignment_EastWind_GoesToSector4(): void
    {
        $baseTime = time();
        $this->appendObs($baseTime - 600, 12.0, 90.0);

        $petals = computeLastHourWindRose($this->testAirportId);
        $this->assertNotNull($petals);
        $this->assertEqualsWithDelta(12.0, $petals[4], 0.1, '90° (E) must map to sector 4');
    }

    /**
     * Wind from 180° (South) must map to sector 8 (S)
     */
    public function testSectorAssignment_SouthWind_GoesToSector8(): void
    {
        $baseTime = time();
        $this->appendObs($baseTime - 600, 8.0, 180.0);

        $petals = computeLastHourWindRose($this->testAirportId);
        $this->assertNotNull($petals);
        $this->assertEqualsWithDelta(8.0, $petals[8], 0.1, '180° (S) must map to sector 8');
    }

    /**
     * Wind from 270° (West) must map to sector 12 (W)
     */
    public function testSectorAssignment_WestWind_GoesToSector12(): void
    {
        $baseTime = time();
        $this->appendObs($baseTime - 600, 15.0, 270.0);

        $petals = computeLastHourWindRose($this->testAirportId);
        $this->assertNotNull($petals);
        $this->assertEqualsWithDelta(15.0, $petals[12], 0.1, '270° (W) must map to sector 12');
    }

    /**
     * Negative wind direction normalizes to 0-360 (e.g. -20° = 340° -> sector 15 NNW)
     */
    public function testSectorAssignment_NegativeDirection_Normalizes(): void
    {
        $baseTime = time();
        $this->appendObs($baseTime - 600, 7.0, -20.0); // -20° = 340° -> sector 15 (NNW)

        $petals = computeLastHourWindRose($this->testAirportId);
        $this->assertNotNull($petals);
        $this->assertEqualsWithDelta(7.0, $petals[15], 0.1, '-20° must normalize to 340° -> sector 15 (NNW)');
    }

    /**
     * Wind from 360° wraps to sector 0 (N) - boundary case
     */
    public function testSectorAssignment_360Degrees_WrapsToNorth(): void
    {
        $baseTime = time();
        $this->appendObs($baseTime - 600, 7.0, 360.0);

        $petals = computeLastHourWindRose($this->testAirportId);
        $this->assertNotNull($petals);
        $this->assertEqualsWithDelta(7.0, $petals[0], 0.1, '360° must wrap to sector 0 (N)');
    }

    /**
     * Sector boundary: 11° should go to N (sector 0), 12° to NNE (sector 1)
     */
    public function testSectorAssignment_Boundary11Degrees_GoesToNorth(): void
    {
        $baseTime = time();
        $this->appendObs($baseTime - 600, 5.0, 11.0);

        $petals = computeLastHourWindRose($this->testAirportId);
        $this->assertNotNull($petals);
        $this->assertEqualsWithDelta(5.0, $petals[0], 0.1, '11° must map to N (sector 0)');
    }

    /**
     * Sector boundary: 12° goes to NNE (sector 1)
     */
    public function testSectorAssignment_Boundary12Degrees_GoesToNNE(): void
    {
        $baseTime = time();
        $this->appendObs($baseTime - 600, 6.0, 12.0);

        $petals = computeLastHourWindRose($this->testAirportId);
        $this->assertNotNull($petals);
        $this->assertEqualsWithDelta(6.0, $petals[1], 0.1, '12° must map to NNE (sector 1)');
    }

    // ============================================================================
    // AVERAGING - Correct mean per sector
    // ============================================================================

    /**
     * Multiple obs in same sector: average must be correct
     */
    public function testAveraging_MultipleObsInSector_CorrectMean(): void
    {
        $baseTime = time();
        $this->appendObs($baseTime - 1800, 10.0, 0.0);
        $this->appendObs($baseTime - 900, 14.0, 0.0);
        $this->appendObs($baseTime - 300, 6.0, 0.0);

        $petals = computeLastHourWindRose($this->testAirportId);
        $this->assertNotNull($petals);
        $expected = (10.0 + 14.0 + 6.0) / 3;
        $this->assertEqualsWithDelta($expected, $petals[0], 0.1, 'Sector 0 avg must be 10');
    }

    /**
     * All 16 sectors populated: each must have correct value
     */
    public function testAveraging_AllSectorsPopulated(): void
    {
        $baseTime = time();
        $expected = [5.0, 6.0, 7.0, 8.0, 9.0, 10.0, 11.0, 12.0, 13.0, 14.0, 15.0, 16.0, 17.0, 18.0, 19.0, 20.0];
        $dirs = [0, 22.5, 45, 67.5, 90, 112.5, 135, 157.5, 180, 202.5, 225, 247.5, 270, 292.5, 315, 337.5];
        foreach ($dirs as $i => $dir) {
            $this->appendObs($baseTime - (600 - $i * 30), $expected[$i], (float) $dir);
        }

        $petals = computeLastHourWindRose($this->testAirportId);
        $this->assertNotNull($petals);
        $this->assertCount(16, $petals);
        for ($i = 0; $i < 16; $i++) {
            $this->assertEqualsWithDelta($expected[$i], $petals[$i], 0.1, "Sector $i must match");
        }
    }

    // ============================================================================
    // FAIL-SAFE: Never display misleading data
    // ============================================================================

    /**
     * Observations older than 1 hour must be excluded
     */
    public function testFailSafe_ObsOlderThanOneHour_Excluded(): void
    {
        $baseTime = time();
        $this->appendObs($baseTime - 3700, 20.0, 0.0); // 61+ min ago

        $petals = computeLastHourWindRose($this->testAirportId);
        $this->assertNull($petals, 'Obs older than 1 hour must be excluded - return null');
    }

    /**
     * Observations with null wind_direction must be skipped (not misassigned)
     */
    public function testFailSafe_NullWindDirection_Skipped(): void
    {
        $baseTime = time();
        appendWeatherHistory($this->testAirportId, [
            'obs_time_primary' => $baseTime - 600,
            'wind_speed' => 10.0,
            'wind_direction' => null,
        ]);

        $petals = computeLastHourWindRose($this->testAirportId);
        $this->assertNull($petals, 'Obs with null wind_direction must not produce petals');
    }

    /**
     * Observations with null wind_speed must be skipped
     */
    public function testFailSafe_NullWindSpeed_Skipped(): void
    {
        $baseTime = time();
        appendWeatherHistory($this->testAirportId, [
            'obs_time_primary' => $baseTime - 600,
            'wind_speed' => null,
            'wind_direction' => 0.0,
        ]);

        $petals = computeLastHourWindRose($this->testAirportId);
        $this->assertNull($petals, 'Obs with null wind_speed must not produce petals');
    }

    /**
     * Negative wind speed must be rejected
     */
    public function testFailSafe_NegativeWindSpeed_Rejected(): void
    {
        $baseTime = time();
        appendWeatherHistory($this->testAirportId, [
            'obs_time_primary' => $baseTime - 600,
            'wind_speed' => -5.0,
            'wind_direction' => 0.0,
        ]);

        $petals = computeLastHourWindRose($this->testAirportId);
        $this->assertNull($petals, 'Negative wind_speed must be rejected');
    }

    /**
     * All calm (zero speed) in last hour must return null
     */
    public function testFailSafe_AllCalm_ReturnsNull(): void
    {
        $baseTime = time();
        $this->appendObs($baseTime - 600, 0.0, 0.0);
        $this->appendObs($baseTime - 300, 0.0, 90.0);

        $petals = computeLastHourWindRose($this->testAirportId);
        $this->assertNull($petals, 'All calm must return null (no petals to display)');
    }

    /**
     * Weather history disabled must return null
     */
    public function testFailSafe_HistoryDisabled_ReturnsNull(): void
    {
        $this->createTestConfig([
            'config' => [
                'public_api' => [
                    'enabled' => true,
                    'weather_history_enabled' => false,
                    'weather_history_retention_hours' => 24,
                ],
            ],
            'airports' => [],
        ]);
        $baseTime = time();
        $this->appendObs($baseTime - 600, 10.0, 0.0);

        $petals = computeLastHourWindRose($this->testAirportId);
        $this->assertNull($petals, 'When history disabled, must return null');
    }

    // ============================================================================
    // OUTPUT CONTRACT
    // ============================================================================

    /**
     * Return must be exactly 16 elements when non-null
     */
    public function testOutputContract_Exactly16Elements(): void
    {
        $baseTime = time();
        $this->appendObs($baseTime - 600, 5.0, 0.0);

        $petals = computeLastHourWindRose($this->testAirportId);
        $this->assertNotNull($petals);
        $this->assertCount(16, $petals, 'Must return exactly 16 sectors');
    }

    /**
     * All petal values must be non-negative
     */
    public function testOutputContract_AllValuesNonNegative(): void
    {
        $baseTime = time();
        $this->appendObs($baseTime - 600, 10.0, 0.0);
        $this->appendObs($baseTime - 300, 8.0, 90.0);

        $petals = computeLastHourWindRose($this->testAirportId);
        $this->assertNotNull($petals);
        foreach ($petals as $i => $v) {
            $this->assertGreaterThanOrEqual(0, $v, "Sector $i must be non-negative");
        }
    }

    /**
     * Values must be rounded to 1 decimal place
     */
    public function testOutputContract_ValuesRoundedToOneDecimal(): void
    {
        $baseTime = time();
        $this->appendObs($baseTime - 600, 10.0, 0.0);
        $this->appendObs($baseTime - 300, 11.0, 0.0); // avg = 10.5

        $petals = computeLastHourWindRose($this->testAirportId);
        $this->assertNotNull($petals);
        $this->assertEquals(10.5, $petals[0], 'Values must be rounded to 1 decimal');
    }
}
