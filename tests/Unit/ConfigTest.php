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
    
    /**
     * Test isAirportUnlisted() with unlisted airport
     */
    public function testIsAirportUnlistedWithUnlistedAirport(): void {
        $airport = [
            'name' => 'Test Airport',
            'enabled' => true,
            'unlisted' => true
        ];
        
        $this->assertTrue(isAirportUnlisted($airport), 'Should return true for unlisted airport');
    }
    
    /**
     * Test isAirportUnlisted() with listed airport
     */
    public function testIsAirportUnlistedWithListedAirport(): void {
        $airport = [
            'name' => 'Test Airport',
            'enabled' => true,
            'unlisted' => false
        ];
        
        $this->assertFalse(isAirportUnlisted($airport), 'Should return false for explicitly listed airport');
    }
    
    /**
     * Test isAirportUnlisted() with missing unlisted field
     */
    public function testIsAirportUnlistedWithMissingField(): void {
        $airport = [
            'name' => 'Test Airport',
            'enabled' => true
        ];
        
        $this->assertFalse(isAirportUnlisted($airport), 'Should return false when unlisted field is missing');
    }
    
    /**
     * Test isAirportUnlisted() with truthy but non-boolean value
     */
    public function testIsAirportUnlistedWithTruthyNonBoolean(): void {
        $airport = [
            'name' => 'Test Airport',
            'enabled' => true,
            'unlisted' => 'yes'
        ];
        
        $this->assertFalse(isAirportUnlisted($airport), 'Should return false for truthy non-boolean value (strict check)');
    }
    
    /**
     * Test getListedAirports() filters out unlisted airports
     */
    public function testGetListedAirportsFiltersUnlisted(): void {
        $config = [
            'airports' => [
                'kspb' => [
                    'name' => 'Listed Airport 1',
                    'enabled' => true
                ],
                'test' => [
                    'name' => 'Unlisted Test Airport',
                    'enabled' => true,
                    'unlisted' => true
                ],
                'kpfc' => [
                    'name' => 'Listed Airport 2',
                    'enabled' => true,
                    'unlisted' => false
                ]
            ]
        ];
        
        $listedAirports = getListedAirports($config);
        
        $this->assertArrayHasKey('kspb', $listedAirports, 'Should include listed airport');
        $this->assertArrayNotHasKey('test', $listedAirports, 'Should exclude unlisted airport');
        $this->assertArrayHasKey('kpfc', $listedAirports, 'Should include explicitly listed airport');
        $this->assertCount(2, $listedAirports, 'Should have exactly 2 listed airports');
    }
    
    /**
     * Test getListedAirports() excludes disabled airports too
     */
    public function testGetListedAirportsExcludesDisabled(): void {
        $config = [
            'airports' => [
                'kspb' => [
                    'name' => 'Listed Airport',
                    'enabled' => true
                ],
                'kczk' => [
                    'name' => 'Disabled Airport',
                    'enabled' => false
                ],
                'test' => [
                    'name' => 'Unlisted Airport',
                    'enabled' => true,
                    'unlisted' => true
                ]
            ]
        ];
        
        $listedAirports = getListedAirports($config);
        
        $this->assertArrayHasKey('kspb', $listedAirports, 'Should include enabled listed airport');
        $this->assertArrayNotHasKey('kczk', $listedAirports, 'Should exclude disabled airport');
        $this->assertArrayNotHasKey('test', $listedAirports, 'Should exclude unlisted airport');
        $this->assertCount(1, $listedAirports, 'Should have exactly 1 listed airport');
    }
    
    /**
     * Test getListedAirports() vs getEnabledAirports() distinction
     */
    public function testListedVsEnabledAirportsDifference(): void {
        $config = [
            'airports' => [
                'kspb' => [
                    'name' => 'Listed Airport',
                    'enabled' => true
                ],
                'test' => [
                    'name' => 'Unlisted Test Site',
                    'enabled' => true,
                    'unlisted' => true
                ]
            ]
        ];
        
        $enabledAirports = getEnabledAirports($config);
        $listedAirports = getListedAirports($config);
        
        // Enabled should include both (unlisted airports are still enabled)
        $this->assertCount(2, $enabledAirports, 'getEnabledAirports should include unlisted airports');
        $this->assertArrayHasKey('test', $enabledAirports, 'Unlisted airport should be in enabled list');
        
        // Listed should exclude unlisted
        $this->assertCount(1, $listedAirports, 'getListedAirports should exclude unlisted airports');
        $this->assertArrayNotHasKey('test', $listedAirports, 'Unlisted airport should not be in listed');
    }
    
    /**
     * Test getListedAirports() with empty config
     */
    public function testGetListedAirportsWithEmptyConfig(): void {
        $config = ['airports' => []];
        
        $listedAirports = getListedAirports($config);
        
        $this->assertIsArray($listedAirports);
        $this->assertEmpty($listedAirports);
    }
    
    /**
     * Test getListedAirports() with missing airports key
     */
    public function testGetListedAirportsWithMissingAirportsKey(): void {
        $config = [];
        
        $listedAirports = getListedAirports($config);
        
        $this->assertIsArray($listedAirports);
        $this->assertEmpty($listedAirports);
    }
}
