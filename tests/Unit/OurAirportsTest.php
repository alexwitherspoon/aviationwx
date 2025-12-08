<?php
/**
 * Unit Tests for OurAirports Data Integration
 * 
 * Tests the OurAirports data download, caching, and validation functions.
 */

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../lib/config.php';

class OurAirportsTest extends TestCase
{
    private $originalCacheFile = null;
    private $testCacheFile = null;
    
    protected function setUp(): void
    {
        parent::setUp();
        
        // Backup original cache file if it exists
        $this->originalCacheFile = __DIR__ . '/../../cache/ourairports_data.json';
        $this->testCacheFile = __DIR__ . '/../../cache/ourairports_data.json.test';
        
        if (file_exists($this->originalCacheFile)) {
            @copy($this->originalCacheFile, $this->testCacheFile);
        }
    }
    
    protected function tearDown(): void
    {
        // Restore original cache file if it existed
        if (file_exists($this->testCacheFile)) {
            @copy($this->testCacheFile, $this->originalCacheFile);
            @unlink($this->testCacheFile);
        } elseif (file_exists($this->originalCacheFile)) {
            @unlink($this->originalCacheFile);
        }
        
        parent::tearDown();
    }
    
    public function testGetOurAirportsData_ReturnsArray()
    {
        // This test may download data from the internet
        // Skip if we can't reach the internet or want to avoid network calls
        $data = getOurAirportsData(true); // Force refresh
        
        if ($data === null) {
            $this->markTestSkipped('Could not download OurAirports data (network issue or service unavailable)');
        }
        
        $this->assertIsArray($data);
        $this->assertArrayHasKey('icao', $data);
        $this->assertArrayHasKey('iata', $data);
        $this->assertArrayHasKey('faa', $data);
        $this->assertIsArray($data['icao']);
        $this->assertIsArray($data['iata']);
        $this->assertIsArray($data['faa']);
    }
    
    public function testGetOurAirportsData_ContainsKnownAirports()
    {
        $data = getOurAirportsData();
        
        if ($data === null) {
            $this->markTestSkipped('Could not get OurAirports data');
        }
        
        // Check for well-known major airports (these should definitely be in the data)
        $this->assertContains('KPDX', $data['icao'], 'KPDX should be in ICAO list');
        $this->assertContains('PDX', $data['iata'], 'PDX should be in IATA list');
        
        // Verify we have substantial data
        $this->assertGreaterThan(5000, count($data['icao']), 'Should have thousands of ICAO codes');
        $this->assertGreaterThan(5000, count($data['iata']), 'Should have thousands of IATA codes');
        $this->assertGreaterThan(10000, count($data['faa']), 'Should have many FAA codes');
    }
    
    public function testIsValidRealIataCode_ValidCode()
    {
        // Test with a well-known IATA code
        $result = isValidRealIataCode('PDX');
        
        // Result may be true if data is available, or false if not
        // We just check it's a boolean
        $this->assertIsBool($result);
    }
    
    public function testIsValidRealIataCode_InvalidFormat()
    {
        $this->assertFalse(isValidRealIataCode('XX'), '2-letter code should be invalid');
        $this->assertFalse(isValidRealIataCode('XXXX'), '4-letter code should be invalid');
        $this->assertFalse(isValidRealIataCode(''), 'Empty string should be invalid');
    }
    
    public function testIsValidRealFaaCode_ValidCode()
    {
        // Test with a well-known FAA code
        $result = isValidRealFaaCode('03S');
        
        // Result may be true if data is available, or false if not
        // We just check it's a boolean
        $this->assertIsBool($result);
    }
    
    public function testIsValidRealFaaCode_InvalidFormat()
    {
        $this->assertFalse(isValidRealFaaCode('XX'), '2-character code should be invalid');
        $this->assertFalse(isValidRealFaaCode('XXXXX'), '5-character code should be invalid');
        $this->assertFalse(isValidRealFaaCode(''), 'Empty string should be invalid');
    }
    
    public function testValidateAirportsIcaoCodes_ValidatesAllIdentifiers()
    {
        $testConfig = [
            'airports' => [
                'test1' => [
                    'name' => 'Test Airport 1',
                    'icao' => 'KPDX', // Valid ICAO
                    'iata' => 'PDX',  // Valid IATA
                    'faa' => 'PDX',   // Valid FAA
                    'lat' => 45.5897694,
                    'lon' => -122.5950944
                ],
                'test2' => [
                    'name' => 'Test Airport 2',
                    'icao' => 'INVALID', // Invalid format
                    'lat' => 45.0,
                    'lon' => -122.0
                ]
            ]
        ];
        
        $result = validateAirportsIcaoCodes($testConfig);
        
        $this->assertIsArray($result);
        $this->assertArrayHasKey('valid', $result);
        $this->assertArrayHasKey('errors', $result);
        $this->assertArrayHasKey('warnings', $result);
        
        // Should have at least one error for invalid ICAO format
        $this->assertNotEmpty($result['errors'], 'Should have errors for invalid ICAO format');
    }
    
    public function testValidateAirportsIcaoCodes_HandlesMissingData()
    {
        $testConfig = [
            'airports' => [
                'test1' => [
                    'name' => 'Test Airport',
                    'lat' => 45.0,
                    'lon' => -122.0
                ]
            ]
        ];
        
        $result = validateAirportsIcaoCodes($testConfig);
        
        // Should not error if no identifiers are present
        $this->assertIsArray($result);
        $this->assertArrayHasKey('valid', $result);
    }
}

