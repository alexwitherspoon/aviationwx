<?php
/**
 * Unit Tests for Airport Status Functions
 * 
 * Tests the enabled and maintenance status functions.
 */

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../lib/config.php';

class AirportStatusTest extends TestCase
{
    /**
     * Test isAirportEnabled - enabled: true
     */
    public function testIsAirportEnabled_EnabledTrue_ReturnsTrue(): void
    {
        $airport = ['enabled' => true];
        $this->assertTrue(isAirportEnabled($airport), 'Airport with enabled: true should return true');
    }
    
    /**
     * Test isAirportEnabled - enabled: false
     */
    public function testIsAirportEnabled_EnabledFalse_ReturnsFalse(): void
    {
        $airport = ['enabled' => false];
        $this->assertFalse(isAirportEnabled($airport), 'Airport with enabled: false should return false');
    }
    
    /**
     * Test isAirportEnabled - enabled missing
     */
    public function testIsAirportEnabled_EnabledMissing_ReturnsFalse(): void
    {
        $airport = ['name' => 'Test Airport'];
        $this->assertFalse(isAirportEnabled($airport), 'Airport without enabled field should return false');
    }
    
    /**
     * Test isAirportEnabled - enabled: "true" (string)
     */
    public function testIsAirportEnabled_EnabledStringTrue_ReturnsFalse(): void
    {
        $airport = ['enabled' => 'true'];
        $this->assertFalse(isAirportEnabled($airport), 'Airport with enabled: "true" (string) should return false (strict check)');
    }
    
    /**
     * Test isAirportEnabled - enabled: 1 (integer)
     */
    public function testIsAirportEnabled_EnabledIntegerOne_ReturnsFalse(): void
    {
        $airport = ['enabled' => 1];
        $this->assertFalse(isAirportEnabled($airport), 'Airport with enabled: 1 (integer) should return false (strict check)');
    }
    
    /**
     * Test isAirportInMaintenance - maintenance: true
     */
    public function testIsAirportInMaintenance_MaintenanceTrue_ReturnsTrue(): void
    {
        $airport = ['maintenance' => true];
        $this->assertTrue(isAirportInMaintenance($airport), 'Airport with maintenance: true should return true');
    }
    
    /**
     * Test isAirportInMaintenance - maintenance: false
     */
    public function testIsAirportInMaintenance_MaintenanceFalse_ReturnsFalse(): void
    {
        $airport = ['maintenance' => false];
        $this->assertFalse(isAirportInMaintenance($airport), 'Airport with maintenance: false should return false');
    }
    
    /**
     * Test isAirportInMaintenance - maintenance missing
     */
    public function testIsAirportInMaintenance_MaintenanceMissing_ReturnsFalse(): void
    {
        $airport = ['name' => 'Test Airport'];
        $this->assertFalse(isAirportInMaintenance($airport), 'Airport without maintenance field should return false');
    }
    
    /**
     * Test isAirportInMaintenance - maintenance: "true" (string)
     */
    public function testIsAirportInMaintenance_MaintenanceStringTrue_ReturnsFalse(): void
    {
        $airport = ['maintenance' => 'true'];
        $this->assertFalse(isAirportInMaintenance($airport), 'Airport with maintenance: "true" (string) should return false (strict check)');
    }
    
    /**
     * Test isAirportLimitedAvailability - limited_availability: true
     */
    public function testIsAirportLimitedAvailability_True_ReturnsTrue(): void
    {
        $airport = ['limited_availability' => true];
        $this->assertTrue(isAirportLimitedAvailability($airport), 'Airport with limited_availability: true should return true');
    }
    
    /**
     * Test isAirportLimitedAvailability - limited_availability: false
     */
    public function testIsAirportLimitedAvailability_False_ReturnsFalse(): void
    {
        $airport = ['limited_availability' => false];
        $this->assertFalse(isAirportLimitedAvailability($airport), 'Airport with limited_availability: false should return false');
    }
    
    /**
     * Test isAirportLimitedAvailability - limited_availability missing
     */
    public function testIsAirportLimitedAvailability_Missing_ReturnsFalse(): void
    {
        $airport = ['name' => 'Test Airport'];
        $this->assertFalse(isAirportLimitedAvailability($airport), 'Airport without limited_availability field should return false');
    }
    
    /**
     * Test getEnabledAirports - filters correctly
     */
    public function testGetEnabledAirports_FiltersCorrectly_ReturnsOnlyEnabled(): void
    {
        $config = [
            'airports' => [
                'kspb' => [
                    'name' => 'Scappoose Airport',
                    'enabled' => true
                ],
                'ksea' => [
                    'name' => 'Seattle Airport',
                    'enabled' => false
                ],
                'kden' => [
                    'name' => 'Denver Airport'
                    // enabled missing (defaults to false)
                ],
                'pdx' => [
                    'name' => 'Portland Airport',
                    'enabled' => true
                ]
            ]
        ];
        
        $enabled = getEnabledAirports($config);
        
        $this->assertCount(2, $enabled, 'Should return 2 enabled airports');
        $this->assertArrayHasKey('kspb', $enabled, 'Should include kspb');
        $this->assertArrayHasKey('pdx', $enabled, 'Should include pdx');
        $this->assertArrayNotHasKey('ksea', $enabled, 'Should not include ksea (disabled)');
        $this->assertArrayNotHasKey('kden', $enabled, 'Should not include kden (enabled missing)');
    }
    
    /**
     * Test getEnabledAirports - empty config
     */
    public function testGetEnabledAirports_EmptyConfig_ReturnsEmptyArray(): void
    {
        $config = [];
        $enabled = getEnabledAirports($config);
        $this->assertIsArray($enabled);
        $this->assertEmpty($enabled);
    }
    
    /**
     * Test getEnabledAirports - no airports key
     */
    public function testGetEnabledAirports_NoAirportsKey_ReturnsEmptyArray(): void
    {
        $config = ['config' => []];
        $enabled = getEnabledAirports($config);
        $this->assertIsArray($enabled);
        $this->assertEmpty($enabled);
    }
    
    /**
     * Test getEnabledAirports - preserves airport structure
     */
    public function testGetEnabledAirports_PreservesStructure_ReturnsFullAirportData(): void
    {
        $config = [
            'airports' => [
                'kspb' => [
                    'name' => 'Scappoose Airport',
                    'enabled' => true,
                    'icao' => 'KSPB',
                    'lat' => 45.7710278,
                    'lon' => -122.8618333
                ]
            ]
        ];
        
        $enabled = getEnabledAirports($config);
        
        $this->assertArrayHasKey('kspb', $enabled);
        $this->assertEquals('Scappoose Airport', $enabled['kspb']['name']);
        $this->assertEquals('KSPB', $enabled['kspb']['icao']);
        $this->assertEquals(45.7710278, $enabled['kspb']['lat']);
    }
    
    // =========================================================================
    // Tests for isAirportUnlisted()
    // =========================================================================
    
    /**
     * Test isAirportUnlisted - unlisted: true
     */
    public function testIsAirportUnlisted_UnlistedTrue_ReturnsTrue(): void
    {
        $airport = ['unlisted' => true];
        $this->assertTrue(isAirportUnlisted($airport), 'Airport with unlisted: true should return true');
    }
    
    /**
     * Test isAirportUnlisted - unlisted: false
     */
    public function testIsAirportUnlisted_UnlistedFalse_ReturnsFalse(): void
    {
        $airport = ['unlisted' => false];
        $this->assertFalse(isAirportUnlisted($airport), 'Airport with unlisted: false should return false');
    }
    
    /**
     * Test isAirportUnlisted - unlisted missing
     */
    public function testIsAirportUnlisted_UnlistedMissing_ReturnsFalse(): void
    {
        $airport = ['name' => 'Test Airport'];
        $this->assertFalse(isAirportUnlisted($airport), 'Airport without unlisted field should return false');
    }
    
    /**
     * Test isAirportUnlisted - unlisted: "true" (string)
     */
    public function testIsAirportUnlisted_UnlistedStringTrue_ReturnsFalse(): void
    {
        $airport = ['unlisted' => 'true'];
        $this->assertFalse(isAirportUnlisted($airport), 'Airport with unlisted: "true" (string) should return false (strict check)');
    }
    
    /**
     * Test isAirportUnlisted - unlisted: 1 (integer)
     */
    public function testIsAirportUnlisted_UnlistedIntegerOne_ReturnsFalse(): void
    {
        $airport = ['unlisted' => 1];
        $this->assertFalse(isAirportUnlisted($airport), 'Airport with unlisted: 1 (integer) should return false (strict check)');
    }
    
    // =========================================================================
    // Tests for getListedAirports()
    // =========================================================================
    
    /**
     * Test getListedAirports - filters correctly
     */
    public function testGetListedAirports_FiltersCorrectly_ExcludesUnlisted(): void
    {
        $config = [
            'airports' => [
                'kspb' => [
                    'name' => 'Scappoose Airport',
                    'enabled' => true
                    // unlisted missing = listed
                ],
                'test' => [
                    'name' => 'Test Site Airport',
                    'enabled' => true,
                    'unlisted' => true
                ],
                'pdx' => [
                    'name' => 'Portland Airport',
                    'enabled' => true,
                    'unlisted' => false
                ]
            ]
        ];
        
        $listed = getListedAirports($config);
        
        $this->assertCount(2, $listed, 'Should return 2 listed airports');
        $this->assertArrayHasKey('kspb', $listed, 'Should include kspb (no unlisted field)');
        $this->assertArrayHasKey('pdx', $listed, 'Should include pdx (unlisted: false)');
        $this->assertArrayNotHasKey('test', $listed, 'Should not include test (unlisted: true)');
    }
    
    /**
     * Test getListedAirports - also excludes disabled airports
     */
    public function testGetListedAirports_ExcludesDisabledAndUnlisted(): void
    {
        $config = [
            'airports' => [
                'kspb' => [
                    'name' => 'Scappoose Airport',
                    'enabled' => true
                ],
                'ksea' => [
                    'name' => 'Seattle Airport',
                    'enabled' => false
                ],
                'test' => [
                    'name' => 'Test Site',
                    'enabled' => true,
                    'unlisted' => true
                ]
            ]
        ];
        
        $listed = getListedAirports($config);
        
        $this->assertCount(1, $listed, 'Should return only 1 listed airport');
        $this->assertArrayHasKey('kspb', $listed, 'Should include kspb');
        $this->assertArrayNotHasKey('ksea', $listed, 'Should not include disabled airport');
        $this->assertArrayNotHasKey('test', $listed, 'Should not include unlisted airport');
    }
    
    /**
     * Test getListedAirports - includes maintenance airports (they're still listed)
     */
    public function testGetListedAirports_IncludesMaintenanceAirports(): void
    {
        $config = [
            'airports' => [
                'kspb' => [
                    'name' => 'Scappoose Airport',
                    'enabled' => true,
                    'maintenance' => true
                ]
            ]
        ];
        
        $listed = getListedAirports($config);
        
        $this->assertCount(1, $listed, 'Maintenance airports should still be listed');
        $this->assertArrayHasKey('kspb', $listed, 'Should include maintenance airport');
    }
    
    /**
     * Test difference between getEnabledAirports and getListedAirports
     */
    public function testEnabledVsListedAirports_UnlistedIsEnabledButNotListed(): void
    {
        $config = [
            'airports' => [
                'kspb' => [
                    'name' => 'Public Airport',
                    'enabled' => true
                ],
                'test' => [
                    'name' => 'Test Site',
                    'enabled' => true,
                    'unlisted' => true
                ]
            ]
        ];
        
        $enabled = getEnabledAirports($config);
        $listed = getListedAirports($config);
        
        // Unlisted airports are enabled (process data, accessible via direct URL)
        $this->assertCount(2, $enabled, 'Both airports should be enabled');
        $this->assertArrayHasKey('test', $enabled, 'Unlisted airport should be in enabled list');
        
        // But not listed (hidden from discovery)
        $this->assertCount(1, $listed, 'Only public airport should be listed');
        $this->assertArrayNotHasKey('test', $listed, 'Unlisted airport should not be in listed');
    }
}

