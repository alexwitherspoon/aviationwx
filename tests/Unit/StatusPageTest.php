<?php
/**
 * Unit Tests for Status Page Functions
 * 
 * Tests status page utility functions and METAR status threshold logic
 */

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../pages/status.php';

class StatusPageTest extends TestCase
{
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
        if (isset($health['components']['weather']['sources'])) {
            foreach ($health['components']['weather']['sources'] as $source) {
                if ($source['name'] === 'Aviation Weather') {
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
        if (isset($health['components']['weather']['sources'])) {
            foreach ($health['components']['weather']['sources'] as $source) {
                if ($source['name'] === 'Aviation Weather') {
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
        if (isset($health['components']['weather']['sources'])) {
            foreach ($health['components']['weather']['sources'] as $source) {
                if ($source['name'] === 'Aviation Weather') {
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
}
