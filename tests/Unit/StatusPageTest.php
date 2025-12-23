<?php
/**
 * Unit Tests for Status Page Functions
 * 
 * Tests status page utility functions and METAR status threshold logic
 */

use PHPUnit\Framework\TestCase;

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
        // Create mock airport config
        $airportId = 'test';
        $airport = [
            'weather_source' => [
                'type' => 'tempest'
            ],
            'metar_station' => 'KSPB'
        ];
        
        // Create temporary weather cache file with METAR data
        $cacheDir = __DIR__ . '/../../cache';
        if (!is_dir($cacheDir)) {
            mkdir($cacheDir, 0755, true);
        }
        $cacheFile = $cacheDir . '/weather_' . $airportId . '.json';
        
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
        // Create mock airport config
        $airportId = 'test2';
        $airport = [
            'weather_source' => [
                'type' => 'tempest'
            ],
            'metar_station' => 'KSPB'
        ];
        
        // Create temporary weather cache file with METAR data
        $cacheDir = __DIR__ . '/../../cache';
        if (!is_dir($cacheDir)) {
            mkdir($cacheDir, 0755, true);
        }
        $cacheFile = $cacheDir . '/weather_' . $airportId . '.json';
        
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
        // Create mock airport config
        $airportId = 'test3';
        $airport = [
            'weather_source' => [
                'type' => 'tempest'
            ],
            'metar_station' => 'KSPB'
        ];
        
        // Create temporary weather cache file with METAR data
        $cacheDir = __DIR__ . '/../../cache';
        if (!is_dir($cacheDir)) {
            mkdir($cacheDir, 0755, true);
        }
        $cacheFile = $cacheDir . '/weather_' . $airportId . '.json';
        
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
        // Create mock airport config with Tempest source
        $airportId = 'test_primary_operational';
        $airport = [
            'weather_source' => [
                'type' => 'tempest'
            ],
            'weather_refresh_seconds' => 60 // 60-second refresh
        ];
        
        // Create temporary weather cache file
        $cacheDir = __DIR__ . '/../../cache';
        if (!is_dir($cacheDir)) {
            mkdir($cacheDir, 0755, true);
        }
        $cacheFile = $cacheDir . '/weather_' . $airportId . '.json';
        
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
        // Create mock airport config with Tempest source
        $airportId = 'test_primary_degraded';
        $airport = [
            'weather_source' => [
                'type' => 'tempest'
            ],
            'weather_refresh_seconds' => 60 // 60-second refresh
        ];
        
        // Create temporary weather cache file
        $cacheDir = __DIR__ . '/../../cache';
        if (!is_dir($cacheDir)) {
            mkdir($cacheDir, 0755, true);
        }
        $cacheFile = $cacheDir . '/weather_' . $airportId . '.json';
        
        // Test: Data 6 minutes old (5x to 10x threshold) should be degraded
        $weatherData = [
            'obs_time_primary' => time() - 360, // 6 minutes ago (between 5x and 10x)
            'temperature' => 15.0
        ];
        file_put_contents($cacheFile, json_encode($weatherData));
        
        $health = checkAirportHealth($airportId, $airport);
        
        $primarySource = $health['components']['weather']['sources'][0] ?? null;
        $this->assertNotNull($primarySource, 'Primary source should be present');
        $this->assertEquals('degraded', $primarySource['status'], 'Should be degraded at 6 minutes (between 5x and 10x)');
        $this->assertEquals('Stale (warning)', $primarySource['message']);
        
        // Test: Data 9 minutes old (still within 10x threshold) should still be degraded
        $weatherData['obs_time_primary'] = time() - 540; // 9 minutes ago
        file_put_contents($cacheFile, json_encode($weatherData));
        
        $health = checkAirportHealth($airportId, $airport);
        $primarySource = $health['components']['weather']['sources'][0] ?? null;
        $this->assertEquals('degraded', $primarySource['status'], 'Should be degraded at 9 minutes (within 10x threshold)');
        
        // Cleanup
        if (file_exists($cacheFile)) {
            unlink($cacheFile);
        }
    }
    
    public function testCheckAirportHealth_PrimaryWeather_DownAfterErrorThreshold(): void
    {
        // Create mock airport config with Tempest source
        $airportId = 'test_primary_down';
        $airport = [
            'weather_source' => [
                'type' => 'tempest'
            ],
            'weather_refresh_seconds' => 60 // 60-second refresh
        ];
        
        // Create temporary weather cache file
        $cacheDir = __DIR__ . '/../../cache';
        if (!is_dir($cacheDir)) {
            mkdir($cacheDir, 0755, true);
        }
        $cacheFile = $cacheDir . '/weather_' . $airportId . '.json';
        
        // Test: Data 11 minutes old (after 10x threshold) should be down
        $weatherData = [
            'obs_time_primary' => time() - 660, // 11 minutes ago (after 10x threshold)
            'temperature' => 15.0
        ];
        file_put_contents($cacheFile, json_encode($weatherData));
        
        $health = checkAirportHealth($airportId, $airport);
        
        $primarySource = $health['components']['weather']['sources'][0] ?? null;
        $this->assertNotNull($primarySource, 'Primary source should be present');
        $this->assertEquals('down', $primarySource['status'], 'Should be down at 11 minutes (after 10x threshold)');
        $this->assertEquals('Stale (error)', $primarySource['message']);
        
        // Cleanup
        if (file_exists($cacheFile)) {
            unlink($cacheFile);
        }
    }
    
    public function testCheckAirportHealth_PrimaryWeather_RespectsMaxStaleSeconds(): void
    {
        // Create mock airport config with very long refresh interval
        $airportId = 'test_primary_maxstale';
        $airport = [
            'weather_source' => [
                'type' => 'tempest'
            ],
            'weather_refresh_seconds' => 1800 // 30-minute refresh (error threshold would be 5 hours, but maxStaleSeconds is 3 hours)
        ];
        
        // Create temporary weather cache file
        $cacheDir = __DIR__ . '/../../cache';
        if (!is_dir($cacheDir)) {
            mkdir($cacheDir, 0755, true);
        }
        $cacheFile = $cacheDir . '/weather_' . $airportId . '.json';
        
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
        $this->assertEquals('Expired', $primarySource['message'], 'Should show Expired when maxStaleSeconds exceeded');
        
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
            'weather_source' => [
                'type' => 'metar'
            ],
            'weather_refresh_seconds' => 60
        ];
        
        // Create temporary weather cache file
        $cacheDir = __DIR__ . '/../../cache';
        if (!is_dir($cacheDir)) {
            mkdir($cacheDir, 0755, true);
        }
        $cacheFile = $cacheDir . '/weather_' . $airportId . '.json';
        
        // Test: METAR data 1 hour old should be operational (uses METAR thresholds, not multipliers)
        $weatherData = [
            'obs_time_primary' => time() - 3600, // 1 hour ago
            'temperature' => 15.0,
            'visibility' => 10.0
        ];
        file_put_contents($cacheFile, json_encode($weatherData));
        
        $health = checkAirportHealth($airportId, $airport);
        
        $primarySource = $health['components']['weather']['sources'][0] ?? null;
        $this->assertNotNull($primarySource, 'Primary METAR source should be present');
        $this->assertEquals('operational', $primarySource['status'], 'METAR should be operational at 1 hour (uses hourly thresholds)');
        $this->assertEquals('Recent', $primarySource['message'], 'METAR should show Recent message');
        
        // Cleanup
        if (file_exists($cacheFile)) {
            unlink($cacheFile);
        }
    }
}




