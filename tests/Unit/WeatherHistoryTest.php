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
}

