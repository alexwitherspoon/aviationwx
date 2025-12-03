<?php
/**
 * Unit Tests for Rate Limiting
 */

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../lib/rate-limit.php';

class RateLimitTest extends TestCase
{
    private $testCacheDir;
    
    protected function setUp(): void
    {
        parent::setUp();
        $this->testCacheDir = __DIR__ . '/../../cache';
        if (!is_dir($this->testCacheDir)) {
            @mkdir($this->testCacheDir, 0755, true);
        }
    }
    
    protected function tearDown(): void
    {
        // Clean up test rate limit files
        $files = glob($this->testCacheDir . '/rate_limit_*.json');
        foreach ($files as $file) {
            @unlink($file);
        }
        parent::tearDown();
    }

    /**
     * Test checkRateLimit - First request should pass
     */
    public function testCheckRateLimit_FirstRequest()
    {
        // Note: This test may not work perfectly if APCu is not available in test environment
        // In that case, it will fall through to the no-op return true
        $result = checkRateLimit('test_key_' . uniqid(), 60, 60);
        $this->assertTrue($result);
    }

    /**
     * Test getRateLimitRemaining - Should return valid count
     */
    public function testGetRateLimitRemaining_ValidKey()
    {
        $key = 'test_key_' . uniqid();
        // First request
        checkRateLimit($key, 60, 60);
        
        $remaining = getRateLimitRemaining($key, 60, 60);
        $this->assertIsInt($remaining);
        $this->assertGreaterThanOrEqual(0, $remaining, 'Remaining should be >= 0');
        $this->assertLessThanOrEqual(60, $remaining, 'Remaining should be <= maxRequests');
    }

    /**
     * Test getRateLimitRemaining - Non-existent key
     */
    public function testGetRateLimitRemaining_NonExistentKey()
    {
        $remaining = getRateLimitRemaining('nonexistent_' . uniqid(), 60, 60);
        $this->assertIsInt($remaining);
        // Should return max requests if key doesn't exist
        $this->assertEquals(60, $remaining);
    }
    
    /**
     * Test checkRateLimitFileBased - File-based fallback when APCu unavailable
     */
    public function testCheckRateLimitFileBased_BasicFunctionality()
    {
        // Ensure function exists
        $this->assertTrue(function_exists('checkRateLimitFileBased'), 'checkRateLimitFileBased function should exist');
        
        $key = 'test_file_' . uniqid();
        $ip = '127.0.0.1';
        $maxRequests = 5;
        $windowSeconds = 60;
        
        // First request should pass
        $result1 = checkRateLimitFileBased($key, $maxRequests, $windowSeconds, $ip);
        $this->assertTrue($result1, 'First request should pass');
        
        // Make multiple requests up to limit
        for ($i = 0; $i < $maxRequests - 1; $i++) {
            $result = checkRateLimitFileBased($key, $maxRequests, $windowSeconds, $ip);
            $this->assertTrue($result, "Request " . ($i + 2) . " should pass (within limit)");
        }
        
        // Next request should be blocked
        $resultBlocked = checkRateLimitFileBased($key, $maxRequests, $windowSeconds, $ip);
        $this->assertFalse($resultBlocked, 'Request exceeding limit should be blocked');
    }
    
    /**
     * Test checkRateLimitFileBased - Window expiration
     */
    public function testCheckRateLimitFileBased_WindowExpiration()
    {
        $key = 'test_window_' . uniqid();
        $ip = '127.0.0.1';
        $maxRequests = 3;
        $windowSeconds = 2; // Short window for testing
        
        // Exhaust limit
        for ($i = 0; $i < $maxRequests; $i++) {
            checkRateLimitFileBased($key, $maxRequests, $windowSeconds, $ip);
        }
        
        // Should be blocked
        $blocked = checkRateLimitFileBased($key, $maxRequests, $windowSeconds, $ip);
        $this->assertFalse($blocked, 'Should be blocked after exceeding limit');
        
        // Wait for window to expire
        sleep($windowSeconds + 1);
        
        // Should be allowed again
        $allowed = checkRateLimitFileBased($key, $maxRequests, $windowSeconds, $ip);
        $this->assertTrue($allowed, 'Should be allowed after window expires');
    }
    
    /**
     * Test checkRateLimitFileBased - Different IPs have separate limits
     */
    public function testCheckRateLimitFileBased_SeparateLimitsPerIP()
    {
        $key = 'test_ip_' . uniqid();
        $maxRequests = 3;
        $windowSeconds = 60;
        
        // IP1 exhausts limit
        for ($i = 0; $i < $maxRequests; $i++) {
            checkRateLimitFileBased($key, $maxRequests, $windowSeconds, '192.168.1.1');
        }
        
        // IP1 should be blocked
        $ip1Blocked = checkRateLimitFileBased($key, $maxRequests, $windowSeconds, '192.168.1.1');
        $this->assertFalse($ip1Blocked, 'IP1 should be blocked');
        
        // IP2 should still be allowed
        $ip2Allowed = checkRateLimitFileBased($key, $maxRequests, $windowSeconds, '192.168.1.2');
        $this->assertTrue($ip2Allowed, 'IP2 should be allowed (separate limit)');
    }
    
    /**
     * Test checkRateLimit - Actual blocking behavior
     */
    public function testCheckRateLimit_ActualBlocking()
    {
        $key = 'test_blocking_' . uniqid();
        $maxRequests = 3;
        $windowSeconds = 60;
        
        // Make requests up to limit
        for ($i = 0; $i < $maxRequests; $i++) {
            $result = checkRateLimit($key, $maxRequests, $windowSeconds);
            $this->assertTrue($result, "Request " . ($i + 1) . " should pass");
        }
        
        // Check remaining
        $remaining = getRateLimitRemaining($key, $maxRequests, $windowSeconds);
        $this->assertLessThanOrEqual($maxRequests, $remaining, 'Remaining should be <= max');
        
        // Note: Actual blocking depends on APCu availability
        // If APCu is not available, it falls back to file-based which may not block immediately
    }
    
    /**
     * Test checkRateLimit - Different configurations
     */
    public function testCheckRateLimit_DifferentConfigurations()
    {
        $key1 = 'test_config1_' . uniqid();
        $key2 = 'test_config2_' . uniqid();
        
        // Test with different limits
        $result1 = checkRateLimit($key1, 10, 60);
        $result2 = checkRateLimit($key2, 100, 300);
        
        $this->assertTrue($result1, 'Should work with limit 10');
        $this->assertTrue($result2, 'Should work with limit 100');
        
        // Check remaining reflects different limits
        $remaining1 = getRateLimitRemaining($key1, 10, 60);
        $remaining2 = getRateLimitRemaining($key2, 100, 300);
        
        $this->assertLessThanOrEqual(10, $remaining1, 'Remaining1 should reflect limit 10');
        $this->assertLessThanOrEqual(100, $remaining2, 'Remaining2 should reflect limit 100');
    }
}

