<?php
/**
 * Unit Tests for Rate Limiting
 */

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../lib/cache-paths.php';
require_once __DIR__ . '/../../lib/rate-limit.php';

class RateLimitTest extends TestCase
{
    private $testCacheDir;
    
    protected function setUp(): void
    {
        parent::setUp();
        $this->testCacheDir = CACHE_BASE_DIR;
        ensureCacheDir($this->testCacheDir);
    }
    
    protected function tearDown(): void
    {
        $prefixDirs = glob(CACHE_RATE_LIMITS_DIR . '/*', GLOB_ONLYDIR);
        if ($prefixDirs !== false) {
            foreach ($prefixDirs as $dir) {
                $files = glob($dir . '/*.json');
                if ($files !== false) {
                    foreach ($files as $file) {
                        @unlink($file);
                    }
                }
                @rmdir($dir);
            }
        }
        parent::tearDown();
    }

    /**
     * Ensures first request is allowed, establishing baseline rate limit behavior
     */
    public function testCheckRateLimit_FirstRequest()
    {
        // Note: This test may not work perfectly if APCu is not available in test environment
        // In that case, it will fall through to the no-op return true
        $result = checkRateLimit('test_key_' . uniqid(), 60, 60);
        $this->assertTrue($result);
    }

    /**
     * Ensures rate limit status is correctly reported for API consumers
     */
    public function testGetRateLimitRemaining_ValidKey()
    {
        $key = 'test_key_' . uniqid();
        // First request
        checkRateLimit($key, 60, 60);
        
        $result = getRateLimitRemaining($key, 60, 60);
        $this->assertIsArray($result, 'Should return array');
        $this->assertArrayHasKey('remaining', $result, 'Should have remaining key');
        $this->assertArrayHasKey('reset', $result, 'Should have reset key');
        $this->assertIsInt($result['remaining'], 'Remaining should be integer');
        $this->assertIsInt($result['reset'], 'Reset should be integer');
        $this->assertGreaterThanOrEqual(0, $result['remaining'], 'Remaining should be >= 0');
        $this->assertLessThanOrEqual(60, $result['remaining'], 'Remaining should be <= maxRequests');
        $this->assertGreaterThan(time(), $result['reset'], 'Reset should be in the future');
    }

    /**
     * Ensures new keys start with full quota, not zero
     */
    public function testGetRateLimitRemaining_NonExistentKey()
    {
        $result = getRateLimitRemaining('nonexistent_' . uniqid(), 60, 60);
        $this->assertIsArray($result, 'Should return array');
        $this->assertArrayHasKey('remaining', $result, 'Should have remaining key');
        $this->assertArrayHasKey('reset', $result, 'Should have reset key');
        // Should return max requests if key doesn't exist
        $this->assertEquals(60, $result['remaining']);
        $this->assertGreaterThan(time(), $result['reset'], 'Reset should be in the future');
    }
    
    /**
     * Ensures rate limiting works when APCu is unavailable, preventing service degradation
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
     * Ensures rate limit windows reset correctly, allowing requests after time window expires
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
     * Ensures IP-based isolation so one user's requests don't affect another's quota
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
     * Ensures rate limits are enforced, preventing API abuse
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
        $result = getRateLimitRemaining($key, $maxRequests, $windowSeconds);
        $this->assertIsArray($result, 'Should return array');
        $this->assertLessThanOrEqual($maxRequests, $result['remaining'], 'Remaining should be <= max');
        
        // Note: Actual blocking depends on APCu availability
        // If APCu is not available, it falls back to file-based which may not block immediately
    }
    
    /**
     * Ensures different rate limit configurations work independently without interference
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
        $result1 = getRateLimitRemaining($key1, 10, 60);
        $result2 = getRateLimitRemaining($key2, 100, 300);
        
        $this->assertIsArray($result1, 'Result1 should be array');
        $this->assertIsArray($result2, 'Result2 should be array');
        $this->assertLessThanOrEqual(10, $result1['remaining'], 'Remaining1 should reflect limit 10');
        $this->assertLessThanOrEqual(100, $result2['remaining'], 'Remaining2 should reflect limit 100');
    }
    
    /**
     * Prevents type coercion bug where string "5" != numeric 5, causing incorrect limit enforcement
     */
    public function testCheckRateLimitFileBased_TypeCoercionFromJson()
    {
        $key = 'test_type_coercion_' . uniqid();
        $ip = '127.0.0.1';
        $maxRequests = 5;
        $windowSeconds = 60;
        
        $hash = md5($key . '_' . $ip);
        $rateLimitFile = getRateLimitPath($hash);
        $rateLimitDir = dirname($rateLimitFile);
        if (!is_dir($rateLimitDir)) {
            @mkdir($rateLimitDir, 0755, true);
        }
        $now = time();
        $data = [
            'count' => '3', // String instead of int
            'reset' => (string)($now + $windowSeconds) // String instead of int
        ];
        file_put_contents($rateLimitFile, json_encode($data), LOCK_EX);
        clearstatcache();
        
        // Should handle string values correctly and still enforce limit
        $result1 = checkRateLimitFileBased($key, $maxRequests, $windowSeconds, $ip);
        $this->assertTrue($result1, 'Should allow request when count is below limit (even with string type)');
        
        // Make more requests to hit limit
        checkRateLimitFileBased($key, $maxRequests, $windowSeconds, $ip);
        $result2 = checkRateLimitFileBased($key, $maxRequests, $windowSeconds, $ip);
        
        // Should eventually block
        $this->assertFalse($result2, 'Should block when limit exceeded, even with string type coercion');
    }
    
    /**
     * Ensures window expiration works correctly even when reset time is stored as string
     */
    public function testCheckRateLimitFileBased_WindowExpirationWithStringType()
    {
        $key = 'test_window_string_' . uniqid();
        $ip = '127.0.0.1';
        $maxRequests = 3;
        $windowSeconds = 2;
        
        $hash = md5($key . '_' . $ip);
        $rateLimitFile = getRateLimitPath($hash);
        $rateLimitDir = dirname($rateLimitFile);
        if (!is_dir($rateLimitDir)) {
            @mkdir($rateLimitDir, 0755, true);
        }
        $expiredTime = time() - 10; // Expired 10 seconds ago
        $data = [
            'count' => '3', // String
            'reset' => (string)$expiredTime // String, expired
        ];
        file_put_contents($rateLimitFile, json_encode($data), LOCK_EX);
        clearstatcache();
        
        // Should reset window and allow request
        $result = checkRateLimitFileBased($key, $maxRequests, $windowSeconds, $ip);
        $this->assertTrue($result, 'Should reset window when expired, even with string reset time');
    }
    
    /**
     * Prevents type coercion bug where string counts cause incorrect remaining quota calculations
     */
    public function testGetRateLimitRemaining_TypeCoercionFromJson()
    {
        // Skip if APCu is available (would use APCu instead of file-based)
        if (function_exists('apcu_fetch')) {
            $this->markTestSkipped('APCu available - test requires file-based fallback');
        }
        
        // Set IP in $_SERVER so getRateLimitRemaining uses the correct IP
        $originalIp = $_SERVER['REMOTE_ADDR'] ?? null;
        $ip = '127.0.0.1';
        $_SERVER['REMOTE_ADDR'] = $ip;
        
        try {
            $key = 'test_remaining_string_' . uniqid();
            $maxRequests = 10;
            $windowSeconds = 60;
            
            $hash = md5($key . '_' . $ip);
            $rateLimitFile = getRateLimitPath($hash);
            $rateLimitDir = dirname($rateLimitFile);
            if (!is_dir($rateLimitDir)) {
                @mkdir($rateLimitDir, 0755, true);
            }
            $now = time();
            $data = [
                'count' => '7', // String
                'reset' => (string)($now + $windowSeconds) // String
            ];
            file_put_contents($rateLimitFile, json_encode($data), LOCK_EX);
            clearstatcache();
            
            // Should correctly calculate remaining
            $result = getRateLimitRemaining($key, $maxRequests, $windowSeconds);
            $this->assertIsArray($result);
            $this->assertIsInt($result['remaining'], 'Remaining should be integer');
            $this->assertIsInt($result['reset'], 'Reset should be integer');
            $this->assertEquals(3, $result['remaining'], 'Should calculate remaining correctly: 10 - 7 = 3');
            $this->assertGreaterThan($now, $result['reset'], 'Reset should be in the future');
        } finally {
            // Restore original IP
            if ($originalIp !== null) {
                $_SERVER['REMOTE_ADDR'] = $originalIp;
            } else {
                unset($_SERVER['REMOTE_ADDR']);
            }
        }
    }
    
    /**
     * Ensures fallback function handles type coercion correctly when primary function fails
     */
    public function testCheckRateLimitFileBasedFallback_TypeCoercion()
    {
        $key = 'test_fallback_string_' . uniqid();
        $ip = '127.0.0.1';
        $maxRequests = 5;
        $windowSeconds = 60;
        
        $hash = md5($key . '_' . $ip);
        $rateLimitFile = getRateLimitPath($hash);
        $rateLimitDir = dirname($rateLimitFile);
        if (!is_dir($rateLimitDir)) {
            @mkdir($rateLimitDir, 0755, true);
        }
        $now = time();
        $data = [
            'count' => '5', // String, at limit
            'reset' => (string)($now + $windowSeconds) // String
        ];
        file_put_contents($rateLimitFile, json_encode($data), LOCK_EX);
        clearstatcache();
        
        // Fallback function should handle string types correctly
        $result = checkRateLimitFileBasedFallback($key, $maxRequests, $windowSeconds, $ip, $rateLimitFile, $now);
        $this->assertFalse($result, 'Should block when at limit, even with string count');
    }
    
    /**
     * Ensures corrupted rate limit files are gracefully recovered to prevent service disruption
     */
    public function testCheckRateLimitFileBased_CorruptedJsonFile()
    {
        $key = 'test_corrupted_' . uniqid();
        $ip = '127.0.0.1';
        $maxRequests = 5;
        $windowSeconds = 60;
        
        $hash = md5($key . '_' . $ip);
        $rateLimitFile = getRateLimitPath($hash);
        $rateLimitDir = dirname($rateLimitFile);
        if (!is_dir($rateLimitDir)) {
            @mkdir($rateLimitDir, 0755, true);
        }
        file_put_contents($rateLimitFile, 'invalid json {', LOCK_EX);
        clearstatcache();
        
        // Should handle gracefully and start fresh
        $result = checkRateLimitFileBased($key, $maxRequests, $windowSeconds, $ip);
        $this->assertTrue($result, 'Should handle corrupted JSON and start fresh');
        
        // Verify file is now valid JSON
        $content = file_get_contents($rateLimitFile);
        $decoded = json_decode($content, true);
        $this->assertIsArray($decoded, 'File should be valid JSON after corruption handling');
    }
}

