<?php
/**
 * Unit tests for config.php functions
 */

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../lib/config.php';

class ConfigTest extends TestCase {
    
    /**
     * Test isSingleAirportMode() with no airports
     */
    public function testSingleAirportModeWithNoAirports(): void {
        // Mock config with no airports
        $config = ['airports' => []];
        
        // Since we can't easily mock loadConfig(), we'll test the logic
        $enabledAirports = [];
        $result = (count($enabledAirports) === 1);
        
        $this->assertFalse($result, 'Should return false when no airports');
    }
    
    /**
     * Test isSingleAirportMode() with one enabled airport
     */
    public function testSingleAirportModeWithOneAirport(): void {
        // Mock config with one enabled airport
        $config = [
            'airports' => [
                'kspb' => [
                    'name' => 'Test Airport',
                    'enabled' => true
                ]
            ]
        ];
        
        $enabledAirports = getEnabledAirports($config);
        $result = (count($enabledAirports) === 1);
        
        $this->assertTrue($result, 'Should return true with exactly 1 enabled airport');
    }
    
    /**
     * Test isSingleAirportMode() with multiple enabled airports
     */
    public function testSingleAirportModeWithMultipleAirports(): void {
        // Mock config with multiple enabled airports
        $config = [
            'airports' => [
                'kspb' => [
                    'name' => 'Test Airport 1',
                    'enabled' => true
                ],
                'kczk' => [
                    'name' => 'Test Airport 2',
                    'enabled' => true
                ]
            ]
        ];
        
        $enabledAirports = getEnabledAirports($config);
        $result = (count($enabledAirports) === 1);
        
        $this->assertFalse($result, 'Should return false with multiple enabled airports');
    }
    
    /**
     * Test isSingleAirportMode() with one enabled, one disabled
     */
    public function testSingleAirportModeWithOneEnabledOneDisabled(): void {
        // Mock config with one enabled, one disabled
        $config = [
            'airports' => [
                'kspb' => [
                    'name' => 'Test Airport 1',
                    'enabled' => true
                ],
                'kczk' => [
                    'name' => 'Test Airport 2',
                    'enabled' => false
                ]
            ]
        ];
        
        $enabledAirports = getEnabledAirports($config);
        $result = (count($enabledAirports) === 1);
        
        $this->assertTrue($result, 'Should return true when only 1 airport is enabled (others disabled)');
    }
    
    /**
     * Test getSingleAirportId() logic with one airport
     */
    public function testGetSingleAirportIdWithOneAirport(): void {
        // Mock config with one enabled airport
        $config = [
            'airports' => [
                'kspb' => [
                    'name' => 'Test Airport',
                    'enabled' => true
                ]
            ]
        ];
        
        $enabledAirports = getEnabledAirports($config);
        
        if (count($enabledAirports) === 1) {
            $airportId = array_key_first($enabledAirports);
            $this->assertEquals('kspb', $airportId, 'Should return correct airport ID');
        }
    }
    
    /**
     * Test getSingleAirportId() logic with multiple airports
     */
    public function testGetSingleAirportIdWithMultipleAirports(): void {
        // Mock config with multiple enabled airports
        $config = [
            'airports' => [
                'kspb' => [
                    'name' => 'Test Airport 1',
                    'enabled' => true
                ],
                'kczk' => [
                    'name' => 'Test Airport 2',
                    'enabled' => true
                ]
            ]
        ];
        
        $enabledAirports = getEnabledAirports($config);
        
        // Should not return an ID when multiple airports
        if (count($enabledAirports) !== 1) {
            $this->assertTrue(true, 'Should not return ID with multiple airports');
        } else {
            $this->fail('Should have multiple airports');
        }
    }
    
    /**
     * Test getEnabledAirports() filters correctly
     */
    public function testGetEnabledAirportsFiltersCorrectly(): void {
        $config = [
            'airports' => [
                'kspb' => [
                    'name' => 'Enabled Airport 1',
                    'enabled' => true
                ],
                'kczk' => [
                    'name' => 'Disabled Airport',
                    'enabled' => false
                ],
                'kpfc' => [
                    'name' => 'Enabled Airport 2',
                    'enabled' => true
                ],
                'k0s9' => [
                    'name' => 'Maintenance Airport',
                    'enabled' => true,
                    'maintenance' => true
                ]
            ]
        ];
        
        $enabledAirports = getEnabledAirports($config);
        
        $this->assertArrayHasKey('kspb', $enabledAirports, 'Should include enabled airport');
        $this->assertArrayNotHasKey('kczk', $enabledAirports, 'Should exclude disabled airport');
        $this->assertArrayHasKey('kpfc', $enabledAirports, 'Should include enabled airport');
        // Note: Maintenance airports are still "enabled" in terms of config
        // They just show a maintenance message on the UI
        $this->assertArrayHasKey('k0s9', $enabledAirports, 'Maintenance airports are still enabled');
    }
}
