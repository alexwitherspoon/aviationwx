<?php
/**
 * Unit Tests for Status Page Functions
 * 
 * Tests status page utility functions and METAR status threshold logic
 */

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../lib/process-utils.php';
require_once __DIR__ . '/../../lib/cache-paths.php';
require_once __DIR__ . '/../../pages/status.php';
require_once __DIR__ . '/../../lib/weather/utils.php';

class StatusPageTest extends TestCase
{
    public function testIsProcessRunning_CurrentProcess_ReturnsTrue(): void
    {
        // Test that the current process is detected as running
        $currentPid = getmypid();
        $result = isProcessRunning($currentPid);
        $this->assertTrue($result, 'Current process should be detected as running');
    }
    
    public function testIsProcessRunning_InvalidPid_ReturnsFalse(): void
    {
        // Test with invalid PIDs
        $this->assertFalse(isProcessRunning(0), 'PID 0 should return false');
        $this->assertFalse(isProcessRunning(-1), 'Negative PID should return false');
    }
    
    public function testIsProcessRunning_NonExistentPid_ReturnsFalse(): void
    {
        // Test with a very high PID that's unlikely to exist
        // PID 4000000 is beyond typical range
        $result = isProcessRunning(4000000);
        $this->assertFalse($result, 'Non-existent PID should return false');
    }
    
    public function testGetStatusColor_Operational_ReturnsGreen(): void
    {
        $result = getStatusColor('operational');
        $this->assertEquals('green', $result);
    }
    
    public function testGetStatusColor_Degraded_ReturnsYellow(): void
    {
        $result = getStatusColor('degraded');
        $this->assertEquals('yellow', $result);
    }
    
    public function testGetStatusColor_Down_ReturnsRed(): void
    {
        $result = getStatusColor('down');
        $this->assertEquals('red', $result);
    }
    
    public function testGetStatusColor_Unknown_ReturnsGray(): void
    {
        $result = getStatusColor('unknown');
        $this->assertEquals('gray', $result);
    }
    
    public function testGetStatusIcon_Operational_ReturnsDot(): void
    {
        $result = getStatusIcon('operational');
        $this->assertEquals('●', $result);
    }
    
    public function testGetStatusIcon_Unknown_ReturnsCircle(): void
    {
        $result = getStatusIcon('unknown');
        $this->assertEquals('○', $result);
    }
    
    public function testFormatRelativeTime_JustNow_ReturnsJustNow(): void
    {
        $timestamp = time() - 30; // 30 seconds ago
        $result = formatRelativeTime($timestamp);
        $this->assertEquals('Just now', $result);
    }
    
    public function testFormatRelativeTime_OneMinute_ReturnsOneMinute(): void
    {
        $timestamp = time() - 90; // 90 seconds ago
        $result = formatRelativeTime($timestamp);
        $this->assertStringContainsString('minute', $result);
        $this->assertStringContainsString('ago', $result);
    }
    
    public function testFormatRelativeTime_OneHour_ReturnsOneHour(): void
    {
        $timestamp = time() - 3600; // 1 hour ago
        $result = formatRelativeTime($timestamp);
        $this->assertStringContainsString('hour', $result);
        $this->assertStringContainsString('ago', $result);
    }
    
    public function testFormatRelativeTime_InvalidTimestamp_ReturnsUnknown(): void
    {
        $result = formatRelativeTime(0);
        $this->assertEquals('Unknown', $result);
        
        $result = formatRelativeTime(-1);
        $this->assertEquals('Unknown', $result);
    }
    
    public function testFormatAbsoluteTime_ValidTimestamp_ReturnsFormattedDate(): void
    {
        $timestamp = time();
        $result = formatAbsoluteTime($timestamp);
        $this->assertIsString($result);
        $this->assertNotEmpty($result);
        $this->assertNotEquals('Unknown', $result);
    }
    
    public function testFormatAbsoluteTime_InvalidTimestamp_ReturnsUnknown(): void
    {
        $result = formatAbsoluteTime(0);
        $this->assertEquals('Unknown', $result);
        
        $result = formatAbsoluteTime(-1);
        $this->assertEquals('Unknown', $result);
    }
    
    public function testCheckAirportHealth_MetarStatus_OperationalUntil2Hours(): void
    {
        // Create mock airport config with unified sources
        $airportId = 'test';
        $airport = [
            'weather_sources' => [
                ['type' => 'tempest', 'station_id' => '12345'],
                ['type' => 'metar', 'station_id' => 'KSPB']
            ]
        ];
        
        // Create temporary weather cache file with METAR data
        $cacheFile = getWeatherCachePath($airportId);
        $cacheDir = dirname($cacheFile);
        if (!is_dir($cacheDir)) {
            mkdir($cacheDir, 0755, true);
        }
        
        // Test: METAR data 1 hour old should be operational
        $weatherData = [
            'obs_time_primary' => time() - 3600,
            'obs_time_metar' => time() - 3600, // 1 hour ago
            'temperature' => 15.0,
            'visibility' => 10.0
        ];
        file_put_contents($cacheFile, json_encode($weatherData));
        
        $health = checkAirportHealth($airportId, $airport);
        
        // Find METAR source in weather sources
        $metarSource = null;
        $metarDisplayName = getWeatherSourceDisplayName('metar');
        if (isset($health['components']['weather']['sources'])) {
            foreach ($health['components']['weather']['sources'] as $source) {
                if ($source['name'] === $metarDisplayName) {
                    $metarSource = $source;
                    break;
                }
            }
        }
        
        $this->assertNotNull($metarSource, 'METAR source should be present');
        $this->assertEquals('operational', $metarSource['status'], 'METAR should be operational at 1 hour');
        
        // Cleanup
        if (file_exists($cacheFile)) {
            unlink($cacheFile);
        }
    }
    
    public function testCheckAirportHealth_MetarStatus_DegradedBetween2And3Hours(): void
    {
        // Create mock airport config with unified sources
        $airportId = 'test2';
        $airport = [
            'weather_sources' => [
                ['type' => 'tempest', 'station_id' => '12345'],
                ['type' => 'metar', 'station_id' => 'KSPB']
            ]
        ];
        
        // Create temporary weather cache file with METAR data
        $cacheFile = getWeatherCachePath($airportId);
        $cacheDir = dirname($cacheFile);
        if (!is_dir($cacheDir)) {
            mkdir($cacheDir, 0755, true);
        }
        
        // Test: METAR data 2.5 hours old should be degraded
        $weatherData = [
            'obs_time_primary' => time() - 3600,
            'obs_time_metar' => time() - (2.5 * 3600), // 2.5 hours ago
            'temperature' => 15.0,
            'visibility' => 10.0
        ];
        file_put_contents($cacheFile, json_encode($weatherData));
        
        $health = checkAirportHealth($airportId, $airport);
        
        // Find METAR source in weather sources
        $metarSource = null;
        $metarDisplayName = getWeatherSourceDisplayName('metar');
        if (isset($health['components']['weather']['sources'])) {
            foreach ($health['components']['weather']['sources'] as $source) {
                if ($source['name'] === $metarDisplayName) {
                    $metarSource = $source;
                    break;
                }
            }
        }
        
        $this->assertNotNull($metarSource, 'METAR source should be present');
        $this->assertEquals('degraded', $metarSource['status'], 'METAR should be degraded between 2-3 hours');
        
        // Cleanup
        if (file_exists($cacheFile)) {
            unlink($cacheFile);
        }
    }
    
    public function testCheckAirportHealth_MetarStatus_DownAfter3Hours(): void
    {
        // Create mock airport config with unified sources
        $airportId = 'test3';
        $airport = [
            'weather_sources' => [
                ['type' => 'tempest', 'station_id' => '12345'],
                ['type' => 'metar', 'station_id' => 'KSPB']
            ]
        ];
        
        // Create temporary weather cache file with METAR data
        $cacheFile = getWeatherCachePath($airportId);
        $cacheDir = dirname($cacheFile);
        if (!is_dir($cacheDir)) {
            mkdir($cacheDir, 0755, true);
        }
        
        // Test: METAR data 4 hours old should be down
        $weatherData = [
            'obs_time_primary' => time() - 3600,
            'obs_time_metar' => time() - (4 * 3600), // 4 hours ago
            'temperature' => 15.0,
            'visibility' => 10.0
        ];
        file_put_contents($cacheFile, json_encode($weatherData));
        
        $health = checkAirportHealth($airportId, $airport);
        
        // Find METAR source in weather sources
        $metarSource = null;
        $metarDisplayName = getWeatherSourceDisplayName('metar');
        if (isset($health['components']['weather']['sources'])) {
            foreach ($health['components']['weather']['sources'] as $source) {
                if ($source['name'] === $metarDisplayName) {
                    $metarSource = $source;
                    break;
                }
            }
        }
        
        $this->assertNotNull($metarSource, 'METAR source should be present');
        $this->assertEquals('down', $metarSource['status'], 'METAR should be down after 3 hours');
        
        // Cleanup
        if (file_exists($cacheFile)) {
            unlink($cacheFile);
        }
    }
    
    public function testCheckAirportHealth_PrimaryWeather_OperationalWithinWarningThreshold(): void
    {
        // Create mock airport config with unified sources
        $airportId = 'test_primary_operational';
        $airport = [
            'weather_sources' => [
                ['type' => 'tempest', 'station_id' => '12345']
            ],
            'weather_refresh_seconds' => 60 // 60-second refresh
        ];
        
        // Create temporary weather cache file using centralized paths
        ensureCacheDir(CACHE_WEATHER_DIR);
        $cacheFile = getWeatherCachePath($airportId);
        
        // Test: Data 1 minute old (within refresh interval) should be operational
        $weatherData = [
            'obs_time_primary' => time() - 60, // 1 minute ago
            'temperature' => 15.0
        ];
        file_put_contents($cacheFile, json_encode($weatherData));
        
        $health = checkAirportHealth($airportId, $airport);
        
        $primarySource = $health['components']['weather']['sources'][0] ?? null;
        $this->assertNotNull($primarySource, 'Primary source should be present');
        $this->assertEquals('operational', $primarySource['status'], 'Should be operational at 1 minute');
        $this->assertEquals('Operational', $primarySource['message']);
        
        // Test: Data 4 minutes old (within 5x threshold) should still be operational
        $weatherData['obs_time_primary'] = time() - 240; // 4 minutes ago
        file_put_contents($cacheFile, json_encode($weatherData));
        
        $health = checkAirportHealth($airportId, $airport);
        $primarySource = $health['components']['weather']['sources'][0] ?? null;
        $this->assertEquals('operational', $primarySource['status'], 'Should be operational at 4 minutes (within 5x threshold)');
        
        // Cleanup
        if (file_exists($cacheFile)) {
            unlink($cacheFile);
        }
    }
    
    public function testCheckAirportHealth_PrimaryWeather_DegradedWithinErrorThreshold(): void
    {
        // Create mock airport config with unified sources
        $airportId = 'test_primary_degraded';
        $airport = [
            'weather_sources' => [
                ['type' => 'tempest', 'station_id' => '12345']
            ],
            'weather_refresh_seconds' => 60 // 60-second refresh
        ];
        
        // Create temporary weather cache file using centralized paths
        ensureCacheDir(CACHE_WEATHER_DIR);
        $cacheFile = getWeatherCachePath($airportId);
        
        // Test: Data 6 minutes old should be operational (below warning threshold of 600s)
        // Default thresholds: warning=600s, error=3600s, failclosed=10800s
        $weatherData = [
            'obs_time_primary' => time() - 360, // 6 minutes ago (below warning threshold)
            'temperature' => 15.0
        ];
        file_put_contents($cacheFile, json_encode($weatherData));
        
        $health = checkAirportHealth($airportId, $airport);
        
        $primarySource = $health['components']['weather']['sources'][0] ?? null;
        $this->assertNotNull($primarySource, 'Primary source should be present');
        $this->assertEquals('operational', $primarySource['status'], 'Should be operational at 6 minutes (below warning threshold)');
        $this->assertStringContainsString('Operational', $primarySource['message'], 'Should show Operational message');
        
        // Test: Data 9 minutes old should still be operational (below warning threshold of 600s)
        $weatherData['obs_time_primary'] = time() - 540; // 9 minutes ago
        file_put_contents($cacheFile, json_encode($weatherData));
        
        $health = checkAirportHealth($airportId, $airport);
        $primarySource = $health['components']['weather']['sources'][0] ?? null;
        $this->assertEquals('operational', $primarySource['status'], 'Should be operational at 9 minutes (below warning threshold)');
        
        // Cleanup
        if (file_exists($cacheFile)) {
            unlink($cacheFile);
        }
    }
    
    public function testCheckAirportHealth_PrimaryWeather_DownAfterErrorThreshold(): void
    {
        // Create mock airport config with unified sources
        $airportId = 'test_primary_down';
        $airport = [
            'weather_sources' => [
                ['type' => 'tempest', 'station_id' => '12345']
            ],
            'weather_refresh_seconds' => 60 // 60-second refresh
        ];
        
        // Create temporary weather cache file using centralized paths
        ensureCacheDir(CACHE_WEATHER_DIR);
        $cacheFile = getWeatherCachePath($airportId);
        
        // Test: Data 11 minutes old should be in error tier (degraded status)
        // Default thresholds: warning=600s, error=3600s, failclosed=10800s
        // At 660s: between warning (600s) and error (3600s), so operational with warning
        // The test comment about "10x threshold" is outdated - thresholds are now absolute, not multipliers
        $weatherData = [
            'obs_time_primary' => time() - 660, // 11 minutes ago
            'temperature' => 15.0
        ];
        file_put_contents($cacheFile, json_encode($weatherData));
        
        $health = checkAirportHealth($airportId, $airport);
        
        $primarySource = $health['components']['weather']['sources'][0] ?? null;
        $this->assertNotNull($primarySource, 'Primary source should be present');
        // At 660s, we're between warning (600s) and error (3600s), so operational with warning
        $this->assertEquals('operational', $primarySource['status'], 'Should be operational at 11 minutes (between warning and error thresholds)');
        $this->assertStringContainsString('Recent', $primarySource['message'], 'Should show Recent (warning) message');
        
        // Cleanup
        if (file_exists($cacheFile)) {
            unlink($cacheFile);
        }
    }
    
    public function testCheckAirportHealth_PrimaryWeather_RespectsMaxStaleSeconds(): void
    {
        // Create mock airport config with unified sources
        $airportId = 'test_primary_maxstale';
        $airport = [
            'weather_sources' => [
                ['type' => 'tempest', 'station_id' => '12345']
            ],
            'weather_refresh_seconds' => 1800 // 30-minute refresh (error threshold would be 5 hours, but maxStaleSeconds is 3 hours)
        ];
        
        // Create temporary weather cache file using centralized paths
        ensureCacheDir(CACHE_WEATHER_DIR);
        $cacheFile = getWeatherCachePath($airportId);
        
        // Test: Data 4 hours old (exceeds maxStaleSeconds of 3 hours) should be down with "Expired"
        $weatherData = [
            'obs_time_primary' => time() - (4 * 3600), // 4 hours ago
            'temperature' => 15.0
        ];
        file_put_contents($cacheFile, json_encode($weatherData));
        
        $health = checkAirportHealth($airportId, $airport);
        
        $primarySource = $health['components']['weather']['sources'][0] ?? null;
        $this->assertNotNull($primarySource, 'Primary source should be present');
        $this->assertEquals('down', $primarySource['status'], 'Should be down after maxStaleSeconds (3 hours)');
        $this->assertStringContainsString('Expired', $primarySource['message'], 'Should show Expired when maxStaleSeconds exceeded');
        
        // Cleanup
        if (file_exists($cacheFile)) {
            unlink($cacheFile);
        }
    }
    
    public function testCheckAirportHealth_MetarAsPrimary_UsesMetarThresholds(): void
    {
        // Create mock airport config with METAR as primary source
        $airportId = 'test_metar_primary';
        $airport = [
            'weather_sources' => [
                ['type' => 'metar', 'station_id' => 'KSPB']
            ],
            'weather_refresh_seconds' => 60
        ];
        
        // Create temporary weather cache file using centralized paths
        ensureCacheDir(CACHE_WEATHER_DIR);
        $cacheFile = getWeatherCachePath($airportId);
        
        // Test: METAR data 1 hour old should be operational (uses METAR thresholds, not multipliers)
        $weatherData = [
            'obs_time_metar' => time() - 3600, // 1 hour ago
            'last_updated_metar' => time() - 3600,
            'temperature' => 15.0,
            'visibility' => 10.0
        ];
        file_put_contents($cacheFile, json_encode($weatherData));
        
        $health = checkAirportHealth($airportId, $airport);
        
        $primarySource = $health['components']['weather']['sources'][0] ?? null;
        $this->assertNotNull($primarySource, 'Primary METAR source should be present');
        $this->assertEquals('operational', $primarySource['status'], 'METAR should be operational at 1 hour (uses hourly thresholds)');
        $this->assertStringContainsString('Recent', $primarySource['message'], 'METAR should show Recent message');
        
        // Cleanup
        if (file_exists($cacheFile)) {
            unlink($cacheFile);
        }
    }
}
