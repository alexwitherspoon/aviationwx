<?php
/**
 * Unit Tests for Public API Rate Limiting
 */

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../lib/public-api/rate-limit.php';

class PublicApiRateLimitTest extends TestCase
{
    private $originalConfigPath;
    private $testConfigDir;
    private $testConfigFile;
    private $testCacheDir;
    
    protected function setUp(): void
    {
        parent::setUp();
        
        // Save original config path
        $this->originalConfigPath = getenv('CONFIG_PATH');
        
        // Create test config directory and file
        $this->testConfigDir = sys_get_temp_dir() . '/aviationwx_test_' . uniqid();
        mkdir($this->testConfigDir, 0755, true);
        $this->testConfigFile = $this->testConfigDir . '/airports.json';
        
        // Create test cache directory
        $this->testCacheDir = __DIR__ . '/../../cache/rate_limits';
        if (!is_dir($this->testCacheDir)) {
            @mkdir($this->testCacheDir, 0755, true);
        }
        
        // Create config with API enabled
        $this->createTestConfig([
            'config' => [
                'public_api' => [
                    'enabled' => true,
                    'rate_limits' => [
                        'anonymous' => [
                            'requests_per_minute' => 5,
                            'requests_per_hour' => 50,
                            'requests_per_day' => 500
                        ],
                        'partner' => [
                            'requests_per_minute' => 20,
                            'requests_per_hour' => 200,
                            'requests_per_day' => 2000
                        ]
                    ]
                ]
            ],
            'airports' => []
        ]);
    }
    
    protected function tearDown(): void
    {
        // Restore original config path
        if ($this->originalConfigPath !== false) {
            putenv('CONFIG_PATH=' . $this->originalConfigPath);
        } else {
            putenv('CONFIG_PATH');
        }
        
        // Clean up test config files
        if (file_exists($this->testConfigFile)) {
            @unlink($this->testConfigFile);
        }
        if (is_dir($this->testConfigDir)) {
            @rmdir($this->testConfigDir);
        }
        
        // Clean up rate limit cache files
        $files = glob($this->testCacheDir . '/public_api_*.json');
        foreach ($files as $file) {
            @unlink($file);
        }
        
        // Clear APCu cache
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
     * First request should always be allowed
     */
    public function testCheckPublicApiRateLimit_FirstRequestAllowed(): void
    {
        $identifier = 'test_' . uniqid();
        
        $result = checkPublicApiRateLimit($identifier, 'anonymous', false);
        
        $this->assertTrue($result['allowed'], 'First request should be allowed');
        $this->assertEquals('anonymous', $result['tier']);
        $this->assertArrayHasKey('limits', $result);
        $this->assertArrayHasKey('remaining', $result);
        $this->assertArrayHasKey('reset', $result);
    }
    
    /**
     * Health check requests should bypass rate limiting
     */
    public function testCheckPublicApiRateLimit_HealthCheckBypasses(): void
    {
        $identifier = 'test_' . uniqid();
        
        // Make many requests with health check flag
        for ($i = 0; $i < 100; $i++) {
            $result = checkPublicApiRateLimit($identifier, 'anonymous', true);
            $this->assertTrue($result['allowed'], 'Health check request ' . $i . ' should be allowed');
        }
    }
    
    /**
     * Rate limit should be enforced after exceeding limit
     */
    public function testCheckPublicApiRateLimit_ExceedsLimit(): void
    {
        $identifier = 'test_exceed_' . uniqid();
        $maxRequests = 5; // Configured in setUp
        
        // Make requests up to the limit
        for ($i = 0; $i < $maxRequests; $i++) {
            $result = checkPublicApiRateLimit($identifier, 'anonymous', false);
            $this->assertTrue($result['allowed'], 'Request ' . ($i + 1) . ' should be allowed');
        }
        
        // Next request should be rate limited
        $result = checkPublicApiRateLimit($identifier, 'anonymous', false);
        $this->assertFalse($result['allowed'], 'Request after limit should be denied');
        $this->assertNotNull($result['retry_after'], 'Should have retry_after value');
    }
    
    /**
     * Partner tier should have higher limits
     */
    public function testCheckPublicApiRateLimit_PartnerHasHigherLimits(): void
    {
        $identifier = 'test_partner_' . uniqid();
        $anonymousLimit = 5;
        $partnerLimit = 20;
        
        // Make requests exceeding anonymous limit
        for ($i = 0; $i < $anonymousLimit + 5; $i++) {
            $result = checkPublicApiRateLimit($identifier, 'partner', false);
            $this->assertTrue($result['allowed'], 'Partner request ' . ($i + 1) . ' should be allowed');
            $this->assertEquals('partner', $result['tier']);
        }
    }
    
    /**
     * Rate limit headers should be properly formatted
     */
    public function testGetPublicApiRateLimitHeaders(): void
    {
        $rateLimitResult = [
            'limits' => ['minute' => 20, 'hour' => 200, 'day' => 2000],
            'remaining' => ['minute' => 15, 'hour' => 195, 'day' => 1995],
            'reset' => ['minute' => time() + 60, 'hour' => time() + 3600, 'day' => time() + 86400]
        ];
        
        $headers = getPublicApiRateLimitHeaders($rateLimitResult);
        
        $this->assertArrayHasKey('X-RateLimit-Limit', $headers);
        $this->assertArrayHasKey('X-RateLimit-Remaining', $headers);
        $this->assertArrayHasKey('X-RateLimit-Reset', $headers);
        $this->assertEquals('20', $headers['X-RateLimit-Limit']);
        $this->assertEquals('15', $headers['X-RateLimit-Remaining']);
    }
    
    /**
     * Different identifiers should have separate rate limits
     */
    public function testCheckPublicApiRateLimit_SeparateLimitsPerIdentifier(): void
    {
        $identifier1 = 'test_user1_' . uniqid();
        $identifier2 = 'test_user2_' . uniqid();
        $maxRequests = 5;
        
        // Exhaust limit for identifier1
        for ($i = 0; $i < $maxRequests; $i++) {
            checkPublicApiRateLimit($identifier1, 'anonymous', false);
        }
        
        // Identifier1 should be rate limited
        $result1 = checkPublicApiRateLimit($identifier1, 'anonymous', false);
        $this->assertFalse($result1['allowed'], 'Identifier1 should be rate limited');
        
        // Identifier2 should still be allowed
        $result2 = checkPublicApiRateLimit($identifier2, 'anonymous', false);
        $this->assertTrue($result2['allowed'], 'Identifier2 should still be allowed');
    }
}

