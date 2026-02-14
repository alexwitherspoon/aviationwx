<?php
/**
 * Unit Tests for Data Outage Detection
 * 
 * Tests the checkDataOutageStatus function including outage state file
 * persistence, grace period handling, and fallback timestamp logic.
 */

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../lib/config.php';
require_once __DIR__ . '/../../lib/cache-paths.php';
require_once __DIR__ . '/../../lib/constants.php';
require_once __DIR__ . '/../../lib/logger.php';
require_once __DIR__ . '/../../lib/weather/source-timestamps.php';
require_once __DIR__ . '/../../lib/weather/outage-detection.php';

class OutageDetectionTest extends TestCase
{
    private $cacheDir;
    private $testAirportId = 'test-outage';
    
    protected function setUp(): void
    {
        parent::setUp();
        $this->cacheDir = CACHE_BASE_DIR;
        if (!is_dir($this->cacheDir)) {
            ensureCacheDir($this->cacheDir);
        }
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
            getOutageCachePath($this->testAirportId),
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
     * Test that outage banner is not shown when airport is in maintenance mode
     */
    public function testCheckDataOutageStatus_MaintenanceMode_ReturnsNull(): void
    {
        $airport = [
            'maintenance' => true,
            'weather_sources' => [['type' => 'tempest', 'station_id' => '12345']]
        ];
        
        $result = checkDataOutageStatus($this->testAirportId, $airport);
        
        $this->assertNull($result, 'Should return null when airport is in maintenance mode');
    }
    
    /**
     * Test that outage is detected when all sources are stale
     */
    public function testCheckDataOutageStatus_AllSourcesStale_ReturnsOutageStatus(): void
    {
        $airport = [
            'weather_sources' => [['type' => 'tempest', 'station_id' => '12345']]
        ];
        
        // Create stale weather cache (4 hours old - exceeds failclosed threshold of 3 hours)
        $weatherCacheFile = getWeatherCachePath($this->testAirportId);
        $staleTimestamp = time() - (4 * 3600);
        $weatherData = [
            'obs_time_primary' => $staleTimestamp,
            'last_updated_primary' => $staleTimestamp,
            'temperature' => 15.0
        ];
        file_put_contents($weatherCacheFile, json_encode($weatherData));
        
        $result = checkDataOutageStatus($this->testAirportId, $airport);
        
        $this->assertNotNull($result, 'Should return outage status when all sources are stale');
        $this->assertIsArray($result);
        $this->assertArrayHasKey('newest_timestamp', $result);
        $this->assertArrayHasKey('all_stale', $result);
        $this->assertArrayHasKey('limited_availability', $result);
        $this->assertTrue($result['all_stale']);
        $this->assertGreaterThan(0, $result['newest_timestamp']);
        $this->assertFalse($result['limited_availability'], 'Default airport should have limited_availability false');
    }
    
    /**
     * Test that limited_availability is true when airport has limited_availability config
     */
    public function testCheckDataOutageStatus_LimitedAvailability_ReturnsTrue(): void
    {
        $airport = [
            'limited_availability' => true,
            'weather_sources' => [['type' => 'tempest', 'station_id' => '12345']]
        ];
        
        $weatherCacheFile = getWeatherCachePath($this->testAirportId);
        $staleTimestamp = time() - (4 * 3600);
        $weatherData = [
            'obs_time_primary' => $staleTimestamp,
            'last_updated_primary' => $staleTimestamp
        ];
        file_put_contents($weatherCacheFile, json_encode($weatherData));
        
        $result = checkDataOutageStatus($this->testAirportId, $airport);
        
        $this->assertNotNull($result);
        $this->assertTrue($result['limited_availability']);
    }
    
    /**
     * Test that outage state file is created on first detection
     */
    public function testCheckDataOutageStatus_FirstDetection_CreatesOutageFile(): void
    {
        $airport = [
            'weather_sources' => [['type' => 'tempest', 'station_id' => '12345']]
        ];
        
        $weatherCacheFile = getWeatherCachePath($this->testAirportId);
        $outageStateFile = getOutageCachePath($this->testAirportId);
        $staleTimestamp = time() - (4 * 3600); // 4 hours old - exceeds failclosed threshold
        $weatherData = [
            'obs_time_primary' => $staleTimestamp,
            'last_updated_primary' => $staleTimestamp
        ];
        file_put_contents($weatherCacheFile, json_encode($weatherData));
        
        // Verify file doesn't exist before
        $this->assertFileDoesNotExist($outageStateFile);
        
        $result = checkDataOutageStatus($this->testAirportId, $airport);
        
        // Verify file is created
        $this->assertFileExists($outageStateFile);
        
        // Verify file contents
        $outageState = json_decode(file_get_contents($outageStateFile), true);
        $this->assertIsArray($outageState);
        $this->assertArrayHasKey('outage_start', $outageState);
        $this->assertArrayHasKey('last_checked', $outageState);
        $this->assertEquals($staleTimestamp, $outageState['outage_start']);
    }
    
    /**
     * Test that existing outage state file preserves original start time
     */
    public function testCheckDataOutageStatus_OngoingOutage_PreservesOriginalStartTime(): void
    {
        $airport = [
            'weather_sources' => [['type' => 'tempest', 'station_id' => '12345']]
        ];
        
        $weatherCacheFile = getWeatherCachePath($this->testAirportId);
        $outageStateFile = getOutageCachePath($this->testAirportId);
        
        // Create existing outage state file with original start time
        $originalStartTime = time() - (3 * 3600); // 3 hours ago
        $outageState = [
            'outage_start' => $originalStartTime,
            'last_checked' => time() - 3600 // 1 hour ago
        ];
        file_put_contents($outageStateFile, json_encode($outageState));
        
        // Create stale weather cache (4 hours old - exceeds failclosed threshold)
        $staleTimestamp = time() - (4 * 3600);
        $weatherData = [
            'obs_time_primary' => $staleTimestamp,
            'last_updated_primary' => $staleTimestamp
        ];
        file_put_contents($weatherCacheFile, json_encode($weatherData));
        
        $result = checkDataOutageStatus($this->testAirportId, $airport);
        
        // Should preserve original start time, not use newer timestamp
        $this->assertNotNull($result);
        $this->assertEquals($originalStartTime, $result['newest_timestamp']);
        
        // Verify file still has original start time
        $outageState = json_decode(file_get_contents($outageStateFile), true);
        $this->assertEquals($originalStartTime, $outageState['outage_start']);
    }
    
    /**
     * Test that outage file is kept during grace period after recovery
     */
    public function testCheckDataOutageStatus_RecoveryWithinGracePeriod_KeepsFile(): void
    {
        $airport = [
            'weather_sources' => [['type' => 'tempest', 'station_id' => '12345']]
        ];
        
        $outageStateFile = getOutageCachePath($this->testAirportId);
        
        // Create outage state file
        $outageStartTime = time() - (3 * 3600);
        $outageState = [
            'outage_start' => $outageStartTime,
            'last_checked' => time() - 1800 // 30 minutes ago (within grace period)
        ];
        file_put_contents($outageStateFile, json_encode($outageState));
        
        // Create fresh weather cache (recovery)
        $weatherCacheFile = getWeatherCachePath($this->testAirportId);
        $freshTimestamp = time() - 60; // 1 minute ago
        $weatherData = [
            'obs_time_primary' => $freshTimestamp,
            'last_updated_primary' => $freshTimestamp
        ];
        file_put_contents($weatherCacheFile, json_encode($weatherData));
        
        $result = checkDataOutageStatus($this->testAirportId, $airport);
        
        // Should return null (no banner) but keep file
        $this->assertNull($result);
        $this->assertFileExists($outageStateFile);
        
        // Verify last_checked was updated (should be recent, within last 5 seconds)
        $outageState = json_decode(file_get_contents($outageStateFile), true);
        $this->assertIsArray($outageState);
        $this->assertArrayHasKey('last_checked', $outageState);
        $this->assertGreaterThanOrEqual(time() - 5, $outageState['last_checked'], 'last_checked should be updated recently');
        $this->assertLessThanOrEqual(time() + 1, $outageState['last_checked'], 'last_checked should not be in the future');
    }
    
    /**
     * Test that outage file is deleted after grace period
     */
    public function testCheckDataOutageStatus_RecoveryAfterGracePeriod_DeletesFile(): void
    {
        $airport = [
            'weather_sources' => [['type' => 'tempest', 'station_id' => '12345']]
        ];
        
        $outageStateFile = getOutageCachePath($this->testAirportId);
        
        // Create outage state file with last_checked beyond grace period
        // Use failclosed threshold as the grace period
        $outageStartTime = time() - (3 * 3600);
        $gracePeriodSeconds = DEFAULT_STALE_FAILCLOSED_SECONDS;
        $outageState = [
            'outage_start' => $outageStartTime,
            'last_checked' => time() - $gracePeriodSeconds - 100 // Beyond grace period
        ];
        file_put_contents($outageStateFile, json_encode($outageState));
        
        // Create fresh weather cache (recovery)
        $weatherCacheFile = getWeatherCachePath($this->testAirportId);
        $freshTimestamp = time() - 60;
        $weatherData = [
            'obs_time_primary' => $freshTimestamp,
            'last_updated_primary' => $freshTimestamp
        ];
        file_put_contents($weatherCacheFile, json_encode($weatherData));
        
        $result = checkDataOutageStatus($this->testAirportId, $airport);
        
        // Should return null and delete file
        $this->assertNull($result);
        $this->assertFileDoesNotExist($outageStateFile);
    }
    
    /**
     * Test webcam cache file fallback when weather cache is missing
     */
    public function testCheckDataOutageStatus_WeatherCacheMissing_UsesWebcamFallback(): void
    {
        $airport = [
            'webcams' => [
                ['name' => 'Test Cam', 'url' => 'http://example.com']
            ]
        ];
        
        // Create webcam cache file (no weather cache)
        $webcamDir = CACHE_WEBCAMS_DIR;
        ensureCacheDir($webcamDir);
        require_once __DIR__ . '/../../lib/webcam-format-generation.php';
        $webcamFile = getCacheSymlinkPath($this->testAirportId, 0, 'jpg');
        $webcamDir = dirname($webcamFile);
        if (!is_dir($webcamDir)) {
            mkdir($webcamDir, 0755, true);
        }
        $webcamTimestamp = time() - (4 * 3600); // 4 hours ago - exceeds failclosed threshold
        touch($webcamFile, $webcamTimestamp);
        
        $result = checkDataOutageStatus($this->testAirportId, $airport);
        
        // Should detect outage and use webcam file modification time
        $this->assertNotNull($result);
        $this->assertGreaterThan(0, $result['newest_timestamp']);
        
        // Verify outage state file was created with webcam timestamp
        $outageStateFile = getOutageCachePath($this->testAirportId);
        $this->assertFileExists($outageStateFile);
        $outageState = json_decode(file_get_contents($outageStateFile), true);
        // Allow small tolerance for file system timestamp precision
        $this->assertLessThan(5, abs($outageState['outage_start'] - $webcamTimestamp));
    }
    
    /**
     * Test that invalid outage state file is cleaned up
     */
    public function testCheckDataOutageStatus_InvalidOutageFile_DeletesFile(): void
    {
        $airport = [
            'weather_sources' => [['type' => 'tempest', 'station_id' => '12345']]
        ];
        
        $outageStateFile = getOutageCachePath($this->testAirportId);
        
        // Create invalid outage state file
        file_put_contents($outageStateFile, 'invalid json{');
        
        // Create fresh weather cache
        $weatherCacheFile = getWeatherCachePath($this->testAirportId);
        $freshTimestamp = time() - 60;
        $weatherData = [
            'obs_time_primary' => $freshTimestamp,
            'last_updated_primary' => $freshTimestamp
        ];
        file_put_contents($weatherCacheFile, json_encode($weatherData));
        
        $result = checkDataOutageStatus($this->testAirportId, $airport);
        
        // Should return null and delete invalid file
        $this->assertNull($result);
        $this->assertFileDoesNotExist($outageStateFile);
    }
    
    /**
     * Test that limited_availability airport shows outage when local sources (webcams) are stale
     * even if METAR from nearby airport is fresh
     */
    public function testCheckDataOutageStatus_LimitedAvailability_WebcamsStaleMetarFresh_ReturnsOutage(): void
    {
        $airport = [
            'limited_availability' => true,
            'weather_sources' => [['type' => 'metar', 'station_id' => 'KBOI']],
            'webcams' => [
                ['name' => 'North', 'url' => 'http://example.com/north.jpg']
            ]
        ];
        
        // Fresh METAR (from nearby KBOI - always reporting)
        $weatherCacheFile = getWeatherCachePath($this->testAirportId);
        $freshMetarTimestamp = time() - 60;
        $weatherData = [
            'obs_time_metar' => $freshMetarTimestamp,
            'last_updated_metar' => $freshMetarTimestamp
        ];
        file_put_contents($weatherCacheFile, json_encode($weatherData));
        
        // Stale webcam (local site powered down)
        $webcamDir = CACHE_WEBCAMS_DIR;
        ensureCacheDir($webcamDir);
        require_once __DIR__ . '/../../lib/webcam-format-generation.php';
        $webcamFile = getCacheSymlinkPath($this->testAirportId, 0, 'jpg');
        $webcamDir = dirname($webcamFile);
        if (!is_dir($webcamDir)) {
            mkdir($webcamDir, 0755, true);
        }
        $staleWebcamTimestamp = time() - (4 * 3600);
        touch($webcamFile, $staleWebcamTimestamp);
        
        $result = checkDataOutageStatus($this->testAirportId, $airport);
        
        // Should return outage (local webcams down) despite fresh METAR
        $this->assertNotNull($result, 'Limited-availability airport should show outage when local sources are stale');
        $this->assertTrue($result['limited_availability']);
        $this->assertGreaterThan(0, $result['newest_timestamp']);
    }
    
    /**
     * Test that limited_availability uses 30-minute default threshold (not 3-hour failclosed)
     */
    public function testCheckDataOutageStatus_LimitedAvailability_Uses30MinuteThreshold(): void
    {
        $airport = [
            'limited_availability' => true,
            'weather_sources' => [['type' => 'tempest', 'station_id' => '12345']]
        ];
        
        // Data 45 minutes old - exceeds 30-min limited_availability threshold
        $weatherCacheFile = getWeatherCachePath($this->testAirportId);
        $staleTimestamp = time() - (45 * 60);
        $weatherData = [
            'obs_time_primary' => $staleTimestamp,
            'last_updated_primary' => $staleTimestamp,
            'temperature' => 15.0
        ];
        file_put_contents($weatherCacheFile, json_encode($weatherData));
        
        $result = checkDataOutageStatus($this->testAirportId, $airport);
        
        $this->assertNotNull($result, 'Limited-availability should show outage after 30 min (45 min > 30 min)');
        $this->assertTrue($result['limited_availability']);
    }
    
    /**
     * Test that limited_availability does not show outage when data is under 30 minutes old
     */
    public function testCheckDataOutageStatus_LimitedAvailability_NoOutageUnder30Min(): void
    {
        $airport = [
            'limited_availability' => true,
            'weather_sources' => [['type' => 'tempest', 'station_id' => '12345']]
        ];
        
        // Data 20 minutes old - under 30-min threshold
        $weatherCacheFile = getWeatherCachePath($this->testAirportId);
        $recentTimestamp = time() - (20 * 60);
        $weatherData = [
            'obs_time_primary' => $recentTimestamp,
            'last_updated_primary' => $recentTimestamp,
            'temperature' => 15.0
        ];
        file_put_contents($weatherCacheFile, json_encode($weatherData));
        
        $result = checkDataOutageStatus($this->testAirportId, $airport);
        
        $this->assertNull($result, 'Limited-availability should NOT show outage when data is under 30 min old');
    }
    
    /**
     * Test that no banner is shown when some sources are fresh
     */
    public function testCheckDataOutageStatus_SomeSourcesFresh_ReturnsNull(): void
    {
        $airport = [
            'weather_sources' => [['type' => 'tempest', 'station_id' => '12345']],
            'webcams' => [
                ['name' => 'Test Cam', 'url' => 'http://example.com']
            ]
        ];
        
        // Create fresh weather cache
        $weatherCacheFile = getWeatherCachePath($this->testAirportId);
        $freshTimestamp = time() - 60;
        $weatherData = [
            'obs_time_primary' => $freshTimestamp,
            'last_updated_primary' => $freshTimestamp
        ];
        file_put_contents($weatherCacheFile, json_encode($weatherData));
        
        // Create stale webcam (but weather is fresh, so no outage)
        $webcamDir = CACHE_WEBCAMS_DIR;
        ensureCacheDir($webcamDir);
        require_once __DIR__ . '/../../lib/webcam-format-generation.php';
        $webcamFile = getCacheSymlinkPath($this->testAirportId, 0, 'jpg');
        $webcamDir = dirname($webcamFile);
        if (!is_dir($webcamDir)) {
            mkdir($webcamDir, 0755, true);
        }
        $staleTimestamp = time() - (4 * 3600); // 4 hours old - exceeds failclosed threshold
        touch($webcamFile, $staleTimestamp);
        
        $result = checkDataOutageStatus($this->testAirportId, $airport);
        
        // Should return null (not all sources are stale)
        $this->assertNull($result);
    }
}

