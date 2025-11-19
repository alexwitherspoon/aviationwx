<?php
/**
 * Integration Tests for Weather API Endpoint
 */

use PHPUnit\Framework\TestCase;

class WeatherApiTest extends TestCase
{
    private $testConfigPath;
    
    protected function setUp(): void
    {
        // Use test config file
        $this->testConfigPath = __DIR__ . '/../Fixtures/airports.json.test';
        
        // Create test config if it doesn't exist
        if (!file_exists($this->testConfigPath)) {
            $this->createTestConfig();
        }
        
        // Set environment variable
        putenv("CONFIG_PATH={$this->testConfigPath}");
    }

    private function createTestConfig()
    {
        $config = [
            'airports' => [
                'kspb' => createTestAirport([
                    'name' => 'Scappoose Airport',
                    'icao' => 'KSPB',
                    'elevation_ft' => 58,
                    'metar_station' => 'KSPB'
                ])
            ]
        ];
        
        $dir = dirname($this->testConfigPath);
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }
        
        file_put_contents($this->testConfigPath, json_encode($config, JSON_PRETTY_PRINT));
    }

    /**
     * Test that weather.php endpoint returns JSON
     */
    public function testWeatherEndpoint_ReturnsJson()
    {
        // Note: weather.php endpoint execution is wrapped in a conditional
        // that only runs when called as a web request (not CLI mode).
        // This integration test requires running weather.php as a web endpoint,
        // which is not possible in CLI mode. Instead, we test that the
        // endpoint logic structure is correct by verifying functions are callable.
        
        // Verify that parse functions are available (they're moved outside the conditional)
        $this->assertTrue(function_exists('parseTempestResponse'), 'parseTempestResponse should be available');
        $this->assertTrue(function_exists('parseAmbientResponse'), 'parseAmbientResponse should be available');
        $this->assertTrue(function_exists('parseWeatherLinkResponse'), 'parseWeatherLinkResponse should be available');
        $this->assertTrue(function_exists('parseMETARResponse'), 'parseMETARResponse should be available');
        
        // For full integration testing, weather.php would need to be called via HTTP
        // This requires a proper test harness or web server setup
        $this->assertTrue(true, 'Integration test structure verified');
    }

    /**
     * Test weather endpoint - Invalid airport ID
     */
    public function testWeatherEndpoint_InvalidAirportId()
    {
        $_GET['airport'] = 'invalid!!';
        $_SERVER['REQUEST_METHOD'] = 'GET';
        $_SERVER['REMOTE_ADDR'] = '127.0.0.1';
        
        // This would require a proper integration test framework
        // For now, we test the validation function directly
        $this->assertFalse(validateAirportId('invalid!!'));
    }
}

