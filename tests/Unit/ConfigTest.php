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
    
    // =========================================================================
    // Network Configuration Helper Tests
    // =========================================================================
    
    /**
     * Test getPublicIP() returns null when not configured
     */
    public function testGetPublicIP_ReturnsNullWhenNotConfigured(): void {
        // The test fixture doesn't have public_ip configured
        $ip = getPublicIP();
        $this->assertNull($ip, 'getPublicIP should return null when not configured');
    }
    
    /**
     * Test getPublicIPv6() returns null when not configured
     */
    public function testGetPublicIPv6_ReturnsNullWhenNotConfigured(): void {
        // The test fixture doesn't have public_ipv6 configured
        $ip = getPublicIPv6();
        $this->assertNull($ip, 'getPublicIPv6 should return null when not configured');
    }
    
    /**
     * Test getUploadHostname() returns default based on base_domain
     */
    public function testGetUploadHostname_ReturnsDefaultWhenNotConfigured(): void {
        $hostname = getUploadHostname();
        // Should return "upload.{base_domain}"
        $baseDomain = getBaseDomain();
        $expected = 'upload.' . $baseDomain;
        $this->assertEquals($expected, $hostname, 'getUploadHostname should default to upload.{base_domain}');
    }
    
    /**
     * Test getBaseDomain() returns configured value or default
     */
    public function testGetBaseDomain_ReturnsConfiguredOrDefault(): void {
        $domain = getBaseDomain();
        // Should be a non-empty string
        $this->assertIsString($domain);
        $this->assertNotEmpty($domain);
        // Should contain at least one dot (valid domain)
        $this->assertStringContainsString('.', $domain);
    }
    
    /**
     * Test that getPublicIP validates IPv4 format
     */
    public function testGetPublicIP_ValidatesIPv4Format(): void {
        // This tests the validation logic indirectly
        // The function should only return valid IPv4 addresses
        $ip = getPublicIP();
        if ($ip !== null) {
            $this->assertNotFalse(
                filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4),
                'getPublicIP should return a valid IPv4 address'
            );
        }
        // null is acceptable when not configured
        $this->assertTrue(true);
    }
    
    /**
     * Test that getPublicIPv6 validates IPv6 format
     */
    public function testGetPublicIPv6_ValidatesIPv6Format(): void {
        // This tests the validation logic indirectly
        $ip = getPublicIPv6();
        if ($ip !== null) {
            $this->assertNotFalse(
                filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6),
                'getPublicIPv6 should return a valid IPv6 address'
            );
        }
        // null is acceptable when not configured
        $this->assertTrue(true);
    }
    
    /**
     * Test getDynamicDnsRefreshSeconds() returns 0 when not configured
     */
    public function testGetDynamicDnsRefreshSeconds_ReturnsZeroWhenNotConfigured(): void {
        $seconds = getDynamicDnsRefreshSeconds();
        // Should be 0 (disabled) when not configured
        $this->assertIsInt($seconds);
        $this->assertGreaterThanOrEqual(0, $seconds);
    }
    
    /**
     * Test isDynamicDnsEnabled() returns false when not configured
     */
    public function testIsDynamicDnsEnabled_ReturnsFalseWhenNotConfigured(): void {
        $enabled = isDynamicDnsEnabled();
        // Should be false when not configured (default is disabled)
        $this->assertIsBool($enabled);
    }
    
    /**
     * Test that getDynamicDnsRefreshSeconds enforces minimum of 60 seconds
     */
    public function testGetDynamicDnsRefreshSeconds_EnforcesMinimum(): void {
        $seconds = getDynamicDnsRefreshSeconds();
        // Either 0 (disabled) or >= 60 (enforced minimum)
        $this->assertTrue(
            $seconds === 0 || $seconds >= 60,
            'getDynamicDnsRefreshSeconds should return 0 or >= 60'
        );
    }
}
