<?php
/**
 * Unit Tests for Public API Configuration
 */

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../lib/public-api/config.php';

class PublicApiConfigTest extends TestCase
{
    private $originalConfigPath;
    private $testConfigDir;
    private $testConfigFile;
    
    protected function setUp(): void
    {
        parent::setUp();
        
        // Save original config path
        $this->originalConfigPath = getenv('CONFIG_PATH');
        
        // Create test config directory and file
        $this->testConfigDir = sys_get_temp_dir() . '/aviationwx_test_' . uniqid();
        mkdir($this->testConfigDir, 0755, true);
        $this->testConfigFile = $this->testConfigDir . '/airports.json';
    }
    
    protected function tearDown(): void
    {
        // Restore original config path
        if ($this->originalConfigPath !== false) {
            putenv('CONFIG_PATH=' . $this->originalConfigPath);
        } else {
            putenv('CONFIG_PATH');
        }
        
        // Clean up test files
        if (file_exists($this->testConfigFile)) {
            @unlink($this->testConfigFile);
        }
        if (is_dir($this->testConfigDir)) {
            @rmdir($this->testConfigDir);
        }
        
        // Clear config cache
        if (function_exists('apcu_clear_cache')) {
            @apcu_clear_cache();
        }
        
        parent::tearDown();
    }
    
    /**
     * Helper to create a test config file
     */
    private function createTestConfig(array $config): void
    {
        file_put_contents($this->testConfigFile, json_encode($config));
        putenv('CONFIG_PATH=' . $this->testConfigFile);
        
        // Clear config cache
        if (function_exists('apcu_clear_cache')) {
            @apcu_clear_cache();
        }
    }
    
    /**
     * API should be disabled by default when not configured
     */
    public function testIsPublicApiEnabled_DefaultDisabled(): void
    {
        $this->createTestConfig([
            'config' => [],
            'airports' => []
        ]);
        
        $this->assertFalse(isPublicApiEnabled(), 'API should be disabled when not configured');
    }
    
    /**
     * API should be enabled when configured
     */
    public function testIsPublicApiEnabled_WhenEnabled(): void
    {
        $this->createTestConfig([
            'config' => [
                'public_api' => [
                    'enabled' => true
                ]
            ],
            'airports' => []
        ]);
        
        $this->assertTrue(isPublicApiEnabled(), 'API should be enabled when configured');
    }
    
    /**
     * API should be disabled when explicitly disabled
     */
    public function testIsPublicApiEnabled_WhenExplicitlyDisabled(): void
    {
        $this->createTestConfig([
            'config' => [
                'public_api' => [
                    'enabled' => false
                ]
            ],
            'airports' => []
        ]);
        
        $this->assertFalse(isPublicApiEnabled(), 'API should be disabled when explicitly disabled');
    }
    
    /**
     * Rate limits should return defaults when not configured
     */
    public function testGetPublicApiRateLimits_DefaultsForAnonymous(): void
    {
        $this->createTestConfig([
            'config' => [],
            'airports' => []
        ]);
        
        $limits = getPublicApiRateLimits('anonymous');
        
        $this->assertEquals(20, $limits['requests_per_minute']);
        $this->assertEquals(200, $limits['requests_per_hour']);
        $this->assertEquals(2000, $limits['requests_per_day']);
    }
    
    /**
     * Rate limits should return defaults for partner tier
     */
    public function testGetPublicApiRateLimits_DefaultsForPartner(): void
    {
        $this->createTestConfig([
            'config' => [],
            'airports' => []
        ]);
        
        $limits = getPublicApiRateLimits('partner');
        
        $this->assertEquals(120, $limits['requests_per_minute']);
        $this->assertEquals(5000, $limits['requests_per_hour']);
        $this->assertEquals(50000, $limits['requests_per_day']);
    }
    
    /**
     * Rate limits should use configured values when set
     */
    public function testGetPublicApiRateLimits_UsesConfiguredValues(): void
    {
        $this->createTestConfig([
            'config' => [
                'public_api' => [
                    'enabled' => true,
                    'rate_limits' => [
                        'anonymous' => [
                            'requests_per_minute' => 10,
                            'requests_per_hour' => 100,
                            'requests_per_day' => 1000
                        ]
                    ]
                ]
            ],
            'airports' => []
        ]);
        
        $limits = getPublicApiRateLimits('anonymous');
        
        $this->assertEquals(10, $limits['requests_per_minute']);
        $this->assertEquals(100, $limits['requests_per_hour']);
        $this->assertEquals(1000, $limits['requests_per_day']);
    }
    
    /**
     * API key validation should return null for empty key
     */
    public function testValidatePublicApiKey_EmptyKey(): void
    {
        $this->createTestConfig([
            'config' => [
                'public_api' => [
                    'enabled' => true,
                    'partner_keys' => []
                ]
            ],
            'airports' => []
        ]);
        
        $this->assertNull(validatePublicApiKey(''), 'Empty key should return null');
    }
    
    /**
     * API key validation should return null for unknown key
     */
    public function testValidatePublicApiKey_UnknownKey(): void
    {
        $this->createTestConfig([
            'config' => [
                'public_api' => [
                    'enabled' => true,
                    'partner_keys' => [
                        'ak_known_key' => [
                            'name' => 'Known Partner',
                            'enabled' => true
                        ]
                    ]
                ]
            ],
            'airports' => []
        ]);
        
        $this->assertNull(validatePublicApiKey('ak_unknown_key'), 'Unknown key should return null');
    }
    
    /**
     * API key validation should return null for disabled key
     */
    public function testValidatePublicApiKey_DisabledKey(): void
    {
        $this->createTestConfig([
            'config' => [
                'public_api' => [
                    'enabled' => true,
                    'partner_keys' => [
                        'ak_disabled_key' => [
                            'name' => 'Disabled Partner',
                            'enabled' => false
                        ]
                    ]
                ]
            ],
            'airports' => []
        ]);
        
        $this->assertNull(validatePublicApiKey('ak_disabled_key'), 'Disabled key should return null');
    }
    
    /**
     * API key validation should return partner info for valid key
     */
    public function testValidatePublicApiKey_ValidKey(): void
    {
        $this->createTestConfig([
            'config' => [
                'public_api' => [
                    'enabled' => true,
                    'partner_keys' => [
                        'ak_valid_key' => [
                            'name' => 'Valid Partner',
                            'contact' => 'test@example.com',
                            'enabled' => true
                        ]
                    ]
                ]
            ],
            'airports' => []
        ]);
        
        $result = validatePublicApiKey('ak_valid_key');
        
        $this->assertIsArray($result, 'Valid key should return partner info');
        $this->assertEquals('Valid Partner', $result['name']);
        $this->assertEquals('test@example.com', $result['contact']);
        $this->assertTrue($result['enabled']);
    }
    
    /**
     * Attribution text should return default when not configured
     */
    public function testGetPublicApiAttributionText_Default(): void
    {
        $this->createTestConfig([
            'config' => [],
            'airports' => []
        ]);
        
        $attribution = getPublicApiAttributionText();
        
        $this->assertEquals('Weather data from AviationWX.org', $attribution);
    }
    
    /**
     * Attribution text should return configured value
     */
    public function testGetPublicApiAttributionText_Configured(): void
    {
        $this->createTestConfig([
            'config' => [
                'public_api' => [
                    'enabled' => true,
                    'attribution_text' => 'Custom attribution'
                ]
            ],
            'airports' => []
        ]);
        
        $attribution = getPublicApiAttributionText();
        
        $this->assertEquals('Custom attribution', $attribution);
    }
    
    /**
     * Bulk max airports should return default when not configured
     */
    public function testGetPublicApiBulkMaxAirports_Default(): void
    {
        $this->createTestConfig([
            'config' => [],
            'airports' => []
        ]);
        
        $max = getPublicApiBulkMaxAirports();
        
        $this->assertEquals(10, $max);
    }
}

