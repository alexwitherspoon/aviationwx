<?php
/**
 * Unit Tests for Weather History Storage
 */

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../lib/weather/history.php';

class WeatherHistoryTest extends TestCase
{
    private $originalConfigPath;
    private $testConfigDir;
    private $testConfigFile;
    private $testAirportId;
    
    protected function setUp(): void
    {
        parent::setUp();
        
        // Save original config path
        $this->originalConfigPath = getenv('CONFIG_PATH');
        
        // Create test config directory and file
        $this->testConfigDir = sys_get_temp_dir() . '/aviationwx_test_' . uniqid();
        mkdir($this->testConfigDir, 0755, true);
        $this->testConfigFile = $this->testConfigDir . '/airports.json';
        
        // Generate unique airport ID for each test
        $this->testAirportId = 'test' . substr(uniqid(), -4);
        
        // Create config with weather history enabled
        $this->createTestConfig([
            'config' => [
                'public_api' => [
                    'enabled' => true,
                    'weather_history_enabled' => true,
                    'weather_history_retention_hours' => 24
                ]
            ],
            'airports' => []
        ]);
    }
    
    protected function tearDown(): void
    {
        // Restore original config path
        if ($this->originalConfigPath !== false) {
            putenv('CONFIG_PATH=' . $this->originalConfigPath);
        } else {
            putenv('CONFIG_PATH');
        }
        
        // Clean up test config files
        if (file_exists($this->testConfigFile)) {
            @unlink($this->testConfigFile);
        }
        if (is_dir($this->testConfigDir)) {
            @rmdir($this->testConfigDir);
        }
        
        // Clean up weather history cache files
        $historyFile = getWeatherHistoryFilePath($this->testAirportId);
        if (file_exists($historyFile)) {
            @unlink($historyFile);
        }
        
        // Clear APCu cache
        if (function_exists('apcu_clear_cache')) {
            @apcu_clear_cache();
        }
        
        parent::tearDown();
    }
    
    /**
     * Helper to create a test config file
     */
    private function createTestConfig(array $config): void
    {
        file_put_contents($this->testConfigFile, json_encode($config));
        putenv('CONFIG_PATH=' . $this->testConfigFile);
        
        // Clear config cache
        if (function_exists('apcu_clear_cache')) {
            @apcu_clear_cache();
        }
    }
    
    /**
     * Appending weather should create history file
     */
    public function testAppendWeatherHistory_CreatesFile(): void
    {
        $weather = [
            'obs_time_primary' => time(),
            'temperature' => 15.5,
            'humidity' => 75
        ];
        
        $result = appendWeatherHistory($this->testAirportId, $weather);
        
        $this->assertTrue($result, 'Should successfully append weather');
        $this->assertFileExists(
            getWeatherHistoryFilePath($this->testAirportId),
            'History file should be created'
        );
    }
    
    /**
     * Appending same observation twice should not duplicate
     */
    public function testAppendWeatherHistory_NoDuplicates(): void
    {
        $obsTime = time();
        $weather = [
            'obs_time_primary' => $obsTime,
            'temperature' => 15.5
        ];
        
        // Append same observation twice
        appendWeatherHistory($this->testAirportId, $weather);
        appendWeatherHistory($this->testAirportId, $weather);
        
        $history = getWeatherHistory($this->testAirportId);
        
        $this->assertEquals(1, $history['observation_count'], 'Should only have one observation');
    }
    
    /**
     * Different observation times should add new entries
     */
    public function testAppendWeatherHistory_AddsDifferentObservations(): void
    {
        $baseTime = time();
        
        appendWeatherHistory($this->testAirportId, [
            'obs_time_primary' => $baseTime - 120,
            'temperature' => 14.0
        ]);
        
        appendWeatherHistory($this->testAirportId, [
            'obs_time_primary' => $baseTime - 60,
            'temperature' => 15.0
        ]);
        
        appendWeatherHistory($this->testAirportId, [
            'obs_time_primary' => $baseTime,
            'temperature' => 16.0
        ]);
        
        $history = getWeatherHistory($this->testAirportId);
        
        $this->assertEquals(3, $history['observation_count'], 'Should have three observations');
    }
    
    /**
     * Get history should return empty for non-existent airport
     */
    public function testGetWeatherHistory_EmptyForNonExistent(): void
    {
        $history = getWeatherHistory('nonexistent_' . uniqid());
        
        $this->assertEquals(0, $history['observation_count']);
        $this->assertEmpty($history['observations']);
    }
    
    /**
     * Time filter should work on history
     */
    public function testGetWeatherHistory_TimeFilter(): void
    {
        $baseTime = time();
        
        // Add observations at different times
        appendWeatherHistory($this->testAirportId, [
            'obs_time_primary' => $baseTime - 7200, // 2 hours ago
            'temperature' => 10.0
        ]);
        
        appendWeatherHistory($this->testAirportId, [
            'obs_time_primary' => $baseTime - 3600, // 1 hour ago
            'temperature' => 12.0
        ]);
        
        appendWeatherHistory($this->testAirportId, [
            'obs_time_primary' => $baseTime,
            'temperature' => 14.0
        ]);
        
        // Get only last hour
        $history = getWeatherHistory(
            $this->testAirportId,
            $baseTime - 3600, // start: 1 hour ago
            $baseTime         // end: now
        );
        
        $this->assertEquals(2, $history['observation_count'], 'Should have two observations in last hour');
    }
    
    /**
     * Hourly resolution should downsample
     */
    public function testGetWeatherHistory_HourlyResolution(): void
    {
        $baseTime = time();
        $hourBucket = floor($baseTime / 3600) * 3600; // Start of current hour
        
        // Add multiple observations in same hour
        for ($i = 0; $i < 10; $i++) {
            appendWeatherHistory($this->testAirportId, [
                'obs_time_primary' => $hourBucket + ($i * 60), // Every minute
                'temperature' => 15.0 + ($i * 0.1)
            ]);
        }
        
        $history = getWeatherHistory($this->testAirportId, null, null, 'hourly');
        
        // Should only have 1 observation (all in same hour bucket)
        $this->assertEquals(1, $history['observation_count'], 'Hourly resolution should downsample to 1');
    }
    
    /**
     * computeLastHourWindRose returns 16-sector petal data from last hour
     */
    public function testComputeLastHourWindRose_ReturnsPetals(): void
    {
        $baseTime = time();
        appendWeatherHistory($this->testAirportId, [
            'obs_time_primary' => $baseTime - 1800,
            'wind_speed' => 10,
            'wind_direction' => 0,  // N
        ]);
        appendWeatherHistory($this->testAirportId, [
            'obs_time_primary' => $baseTime - 900,
            'wind_speed' => 8,
            'wind_direction' => 0,  // N
        ]);
        appendWeatherHistory($this->testAirportId, [
            'obs_time_primary' => $baseTime - 300,
            'wind_speed' => 15,
            'wind_direction' => 90,  // E
        ]);

        $petals = computeLastHourWindRose($this->testAirportId);
        $this->assertNotNull($petals);
        $this->assertCount(16, $petals);
        // Sector 0 = N: avg (10+8)/2 = 9
        $this->assertEqualsWithDelta(9.0, $petals[0], 0.1);
        // Sector 4 = E (90°): 15
        $this->assertEqualsWithDelta(15.0, $petals[4], 0.1);
    }

    /**
     * computeLastHourWindRose returns null when no history
     */
    public function testComputeLastHourWindRose_NoHistory_ReturnsNull(): void
    {
        $petals = computeLastHourWindRose($this->testAirportId);
        $this->assertNull($petals);
    }

    /**
     * Old observations should be pruned
     */
    public function testPruneWeatherHistory_RemovesOld(): void
    {
        $baseTime = time();
        
        // Add old observation (beyond 24h retention)
        appendWeatherHistory($this->testAirportId, [
            'obs_time_primary' => $baseTime - 90000, // 25 hours ago
            'temperature' => 10.0
        ]);
        
        // Add recent observation
        appendWeatherHistory($this->testAirportId, [
            'obs_time_primary' => $baseTime,
            'temperature' => 15.0
        ]);
        
        // Get history (should auto-prune on append)
        $history = getWeatherHistory($this->testAirportId);
        
        // Should only have the recent observation
        $this->assertEquals(1, $history['observation_count'], 'Old observations should be pruned');
    }
    
    /**
     * Weather history should capture key fields
     */
    public function testAppendWeatherHistory_CapturesKeyFields(): void
    {
        $weather = [
            'obs_time_primary' => time(),
            'temperature' => 15.5,
            'temperature_f' => 60.0,
            'dewpoint' => 10.0,
            'humidity' => 75,
            'wind_speed' => 8,
            'wind_direction' => 270,
            'gust_speed' => 12,
            'pressure' => 30.12,
            'visibility' => 10.0,
            'ceiling' => 3500,
            'cloud_cover' => 'SCT',
            'flight_category' => 'VFR',
            'density_altitude' => 1234,
            'pressure_altitude' => 100
        ];
        
        appendWeatherHistory($this->testAirportId, $weather);
        $history = getWeatherHistory($this->testAirportId);
        
        $obs = $history['observations'][0];
        
        $this->assertEquals(15.5, $obs['temperature']);
        $this->assertEquals(60.0, $obs['temperature_f']);
        $this->assertEquals(10.0, $obs['dewpoint']);
        $this->assertEquals(75, $obs['humidity']);
        $this->assertEquals(8, $obs['wind_speed']);
        $this->assertEquals(270, $obs['wind_direction']);
        $this->assertEquals('VFR', $obs['flight_category']);
    }
    
    /**
     * Weather history should store source attribution
     */
    public function testAppendWeatherHistory_StoresSourceAttribution(): void
    {
        $weather = [
            'obs_time_primary' => time(),
            'temperature' => 15.5,
            'wind_speed' => 8,
            'visibility' => 10.0
        ];
        
        $fieldSourceMap = [
            'temperature' => 'tempest',
            'wind_speed' => 'tempest',
            'wind_direction' => 'tempest',
            'visibility' => 'metar',
            'ceiling' => 'metar'
        ];
        
        appendWeatherHistory($this->testAirportId, $weather, $fieldSourceMap);
        $history = getWeatherHistory($this->testAirportId);
        
        $obs = $history['observations'][0];
        
        // Should have field_sources map
        $this->assertArrayHasKey('field_sources', $obs, 'Should have field_sources');
        $this->assertIsArray($obs['field_sources'], 'field_sources should be an array');
        $this->assertEquals('tempest', $obs['field_sources']['temperature'], 'Temperature should be from tempest');
        $this->assertEquals('metar', $obs['field_sources']['visibility'], 'Visibility should be from metar');
        
        // Should have sources array
        $this->assertArrayHasKey('sources', $obs, 'Should have sources array');
        $this->assertIsArray($obs['sources'], 'sources should be an array');
        $this->assertContains('tempest', $obs['sources'], 'Should include tempest in sources');
        $this->assertContains('metar', $obs['sources'], 'Should include metar in sources');
        $this->assertCount(2, $obs['sources'], 'Should have 2 unique sources');
    }
    
    /**
     * computeDailyExtremesFromHistory should compute min/max from observations for date
     */
    public function testComputeDailyExtremesFromHistory_ComputesFromObservations(): void
    {
        $airportId = 'test_extremes_' . uniqid();
        $timezone = 'America/Los_Angeles';
        $tz = new DateTimeZone($timezone);
        $now = new DateTime('now', $tz);
        $dateKey = $now->format('Y-m-d');

        // Create history file with observations for today
        $obs1 = $now->getTimestamp() - 7200; // 2 hours ago
        $obs2 = $now->getTimestamp() - 3600; // 1 hour ago
        $obs3 = $now->getTimestamp();

        $history = [
            'airport_id' => $airportId,
            'updated_at' => time(),
            'retention_hours' => 24,
            'observations' => [
                ['obs_time' => $obs1, 'temperature' => 10.0, 'gust_speed' => 15],
                ['obs_time' => $obs2, 'temperature' => 20.0, 'gust_speed' => 25],
                ['obs_time' => $obs3, 'temperature' => 15.0, 'gust_speed' => 18],
            ],
        ];

        $file = getWeatherHistoryFilePath($airportId);
        ensureCacheDir(dirname($file));
        file_put_contents($file, json_encode($history));

        $computed = computeDailyExtremesFromHistory($airportId, $dateKey, $timezone);

        $this->assertEquals(20.0, $computed['temp_high']);
        $this->assertEquals($obs2, $computed['temp_high_ts']);
        $this->assertEquals(10.0, $computed['temp_low']);
        $this->assertEquals($obs1, $computed['temp_low_ts']);
        $this->assertEquals(25.0, $computed['peak_gust']);
        $this->assertEquals($obs2, $computed['peak_gust_ts']);

        @unlink($file);
    }

    /**
     * computeDailyExtremesFromHistory should return nulls when no observations for date
     */
    public function testComputeDailyExtremesFromHistory_EmptyWhenNoObservationsForDate(): void
    {
        $airportId = 'test_empty_' . uniqid();
        $timezone = 'America/Los_Angeles';
        $tz = new DateTimeZone($timezone);
        $now = new DateTime('now', $tz);
        $yesterday = (clone $now)->modify('-1 day');
        $dateKey = $yesterday->format('Y-m-d'); // Use yesterday - no observations

        $history = [
            'airport_id' => $airportId,
            'observations' => [
                ['obs_time' => $now->getTimestamp(), 'temperature' => 15.0, 'gust_speed' => 10],
            ],
        ];

        $file = getWeatherHistoryFilePath($airportId);
        ensureCacheDir(dirname($file));
        file_put_contents($file, json_encode($history));

        $computed = computeDailyExtremesFromHistory($airportId, $dateKey, $timezone);

        $this->assertNull($computed['temp_high']);
        $this->assertNull($computed['temp_low']);
        $this->assertNull($computed['peak_gust']);

        @unlink($file);
    }

    /**
     * Weather history should handle missing source attribution gracefully
     */
    public function testAppendWeatherHistory_HandlesMissingSourceAttribution(): void
    {
        $weather = [
            'obs_time_primary' => time(),
            'temperature' => 15.5
        ];
        
        // Append without source map
        appendWeatherHistory($this->testAirportId, $weather);
        $history = getWeatherHistory($this->testAirportId);
        
        $obs = $history['observations'][0];
        
        // Should still have the weather data
        $this->assertEquals(15.5, $obs['temperature']);
        
        // Should not have source attribution if not provided
        $this->assertArrayNotHasKey('field_sources', $obs, 'Should not have field_sources if not provided');
        $this->assertArrayNotHasKey('sources', $obs, 'Should not have sources if not provided');
    }
}

