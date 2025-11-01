<?php
/**
 * Unit Tests for Rate Limiting
 */

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../rate-limit.php';

class RateLimitTest extends TestCase
{
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
}

