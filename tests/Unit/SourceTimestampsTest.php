<?php
/**
 * Unit Tests for Source Timestamp Extraction
 * 
 * Tests the getSourceTimestamps function for extracting timestamps
 * from all configured data sources.
 */

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../lib/cache-paths.php';
require_once __DIR__ . '/../../lib/weather/source-timestamps.php';
require_once __DIR__ . '/../../lib/constants.php';

class SourceTimestampsTest extends TestCase
{
    private $cacheDir;
    private $testAirportId = 'test_timestamps';
    
    protected function setUp(): void
    {
        parent::setUp();
        $this->cacheDir = CACHE_BASE_DIR;
        ensureCacheDir($this->cacheDir);
        ensureCacheDir(CACHE_WEATHER_DIR);
        
        // Clean up any existing test files
        $this->cleanupTestFiles();
    }
    
    protected function tearDown(): void
    {
        $this->cleanupTestFiles();
        parent::tearDown();
    }
    
    private function cleanupTestFiles(): void
    {
        require_once __DIR__ . '/../../lib/webcam-format-generation.php';
        $files = [
            getWeatherCachePath($this->testAirportId),
            getCacheSymlinkPath($this->testAirportId, 0, 'jpg'),
            getCacheSymlinkPath($this->testAirportId, 0, 'webp')
        ];
        
        foreach ($files as $file) {
            if (file_exists($file)) {
                @unlink($file);
            }
        }
    }
    
    /**
     * Test that primary source timestamp is extracted correctly
     */
    public function testGetSourceTimestamps_PrimarySource_ExtractsTimestamp(): void
    {
        $airport = [
            'weather_sources' => [['type' => 'tempest', 'station_id' => '12345']]
        ];
        
        // Create weather cache file with primary timestamp
        $weatherCacheFile = getWeatherCachePath($this->testAirportId);
        $timestamp = time() - 3600; // 1 hour ago
        $weatherData = [
            'obs_time_primary' => $timestamp,
            'last_updated_primary' => $timestamp,
            'last_updated' => $timestamp,
            'temperature' => 15.0
        ];
        file_put_contents($weatherCacheFile, json_encode($weatherData));
        
        $result = getSourceTimestamps($this->testAirportId, $airport);
        
        $this->assertTrue($result['primary']['available']);
        $this->assertEquals($timestamp, $result['primary']['timestamp']);
        $this->assertGreaterThan(0, $result['primary']['age']);
        $this->assertLessThan(3700, $result['primary']['age']); // Should be ~3600 seconds
    }
    
    /**
     * Test that primary source returns 0 when obs_time_primary is missing
     */
    public function testGetSourceTimestamps_PrimarySource_NoObsTime_ReturnsZero(): void
    {
        $airport = [
            'weather_sources' => [['type' => 'tempest', 'station_id' => '12345']]
        ];
        
        $weatherCacheFile = getWeatherCachePath($this->testAirportId);
        $weatherData = [
            'last_updated_primary' => time() - 1800,
            'last_updated' => time() - 60,
            'temperature' => 15.0
        ];
        file_put_contents($weatherCacheFile, json_encode($weatherData));
        
        $result = getSourceTimestamps($this->testAirportId, $airport);
        
        $this->assertTrue($result['primary']['available']);
        $this->assertEquals(0, $result['primary']['timestamp']);
        $this->assertEquals(PHP_INT_MAX, $result['primary']['age']);
    }
    
    /**
     * Test that primary source returns 0 when cache file is missing
     */
    public function testGetSourceTimestamps_PrimarySource_MissingCache_ReturnsZero(): void
    {
        $airport = [
            'weather_sources' => [['type' => 'tempest', 'station_id' => '12345']]
        ];
        
        $result = getSourceTimestamps($this->testAirportId, $airport);
        
        $this->assertTrue($result['primary']['available']);
        $this->assertEquals(0, $result['primary']['timestamp']);
        $this->assertEquals(PHP_INT_MAX, $result['primary']['age']);
    }
    
    /**
     * Test that METAR source timestamp is extracted correctly
     */
    public function testGetSourceTimestamps_MetarSource_ExtractsTimestamp(): void
    {
        $airport = [
            'weather_sources' => [['type' => 'metar', 'station_id' => 'KSPB']]
        ];
        
        $weatherCacheFile = getWeatherCachePath($this->testAirportId);
        $timestamp = time() - 7200; // 2 hours ago
        $weatherData = [
            'obs_time_metar' => $timestamp,
            'last_updated_metar' => $timestamp,
            'visibility' => 10.0
        ];
        file_put_contents($weatherCacheFile, json_encode($weatherData));
        
        $result = getSourceTimestamps($this->testAirportId, $airport);
        
        $this->assertTrue($result['metar']['available']);
        $this->assertEquals($timestamp, $result['metar']['timestamp']);
        $this->assertGreaterThan(7100, $result['metar']['age']);
        $this->assertLessThan(7300, $result['metar']['age']);
    }
    
    /**
     * Test that METAR is detected when it's the only source
     */
    public function testGetSourceTimestamps_MetarAsPrimary_DetectsMetar(): void
    {
        $airport = [
            'weather_sources' => [['type' => 'metar', 'station_id' => 'KSPB']]
        ];
        
        $weatherCacheFile = getWeatherCachePath($this->testAirportId);
        $timestamp = time() - 3600;
        $weatherData = [
            'obs_time_metar' => $timestamp,
            'last_updated_metar' => $timestamp
        ];
        file_put_contents($weatherCacheFile, json_encode($weatherData));
        
        $result = getSourceTimestamps($this->testAirportId, $airport);
        
        $this->assertTrue($result['metar']['available']);
        $this->assertEquals($timestamp, $result['metar']['timestamp']);
    }
    
    /**
     * Test that webcam timestamps are extracted correctly
     */
    public function testGetSourceTimestamps_Webcams_ExtractsTimestamps(): void
    {
        $airport = [
            'webcams' => [
                ['name' => 'Test Cam', 'url' => 'http://example.com']
            ]
        ];
        
        // Create webcam cache file
        require_once __DIR__ . '/../../lib/webcam-format-generation.php';
        $webcamFile = getCacheSymlinkPath($this->testAirportId, 0, 'jpg');
        $webcamDir = dirname($webcamFile);
        if (!is_dir($webcamDir)) {
            mkdir($webcamDir, 0755, true);
        }
        $webcamTimestamp = time() - 1800; // 30 minutes ago
        touch($webcamFile, $webcamTimestamp);
        
        $result = getSourceTimestamps($this->testAirportId, $airport);
        
        $this->assertTrue($result['webcams']['available']);
        $this->assertEquals(1, $result['webcams']['total']);
        // Allow small tolerance for file system timestamp precision
        $this->assertLessThan(5, abs($result['webcams']['newest_timestamp'] - $webcamTimestamp));
    }
    
    /**
     * Test that webcam returns 0 when files are missing
     */
    public function testGetSourceTimestamps_Webcams_MissingFiles_ReturnsZero(): void
    {
        $airport = [
            'webcams' => [
                ['name' => 'Test Cam', 'url' => 'http://example.com']
            ]
        ];
        
        $result = getSourceTimestamps($this->testAirportId, $airport);
        
        $this->assertTrue($result['webcams']['available']);
        $this->assertEquals(1, $result['webcams']['total']);
        $this->assertEquals(0, $result['webcams']['newest_timestamp']);
        $this->assertEquals(1, $result['webcams']['stale_count']);
    }
    
    /**
     * Test that unavailable sources return available: false
     */
    public function testGetSourceTimestamps_NoSources_ReturnsUnavailable(): void
    {
        $airport = []; // No sources configured
        
        $result = getSourceTimestamps($this->testAirportId, $airport);
        
        $this->assertFalse($result['primary']['available']);
        $this->assertFalse($result['metar']['available']);
        $this->assertFalse($result['webcams']['available']);
    }
    
    /**
     * Test that corrupted cache file is handled gracefully
     */
    public function testGetSourceTimestamps_CorruptedCache_HandlesGracefully(): void
    {
        $airport = [
            'weather_sources' => [['type' => 'tempest', 'station_id' => '12345']]
        ];
        
        $weatherCacheFile = getWeatherCachePath($this->testAirportId);
        file_put_contents($weatherCacheFile, 'invalid json{');
        
        $result = getSourceTimestamps($this->testAirportId, $airport);
        
        $this->assertTrue($result['primary']['available']);
        $this->assertEquals(0, $result['primary']['timestamp']);
        $this->assertEquals(PHP_INT_MAX, $result['primary']['age']);
    }
    
    /**
     * Test that multiple webcams return newest timestamp
     */
    public function testGetSourceTimestamps_MultipleWebcams_ReturnsNewest(): void
    {
        $airport = [
            'webcams' => [
                ['name' => 'Cam 1', 'url' => 'http://example.com/1'],
                ['name' => 'Cam 2', 'url' => 'http://example.com/2']
            ]
        ];
        
        $webcamDir = CACHE_WEBCAMS_DIR;
        ensureCacheDir($webcamDir);
        
        $olderTimestamp = time() - 3600; // 1 hour ago
        $newerTimestamp = time() - 1800; // 30 minutes ago
        
        $webcamFile = getCacheSymlinkPath($this->testAirportId, 0, 'jpg');
        touch($webcamFile, $olderTimestamp);
        $cam1Dir = getWebcamCameraDir($this->testAirportId, 1);
        ensureCacheDir($cam1Dir);
        touch($cam1Dir . '/current.jpg', $newerTimestamp);
        
        $result = getSourceTimestamps($this->testAirportId, $airport);
        
        $this->assertTrue($result['webcams']['available']);
        $this->assertEquals(2, $result['webcams']['total']);
        // Should return newest timestamp
        $this->assertLessThan(5, abs($result['webcams']['newest_timestamp'] - $newerTimestamp));
    }
}
