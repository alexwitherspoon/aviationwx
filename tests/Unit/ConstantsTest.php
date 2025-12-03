<?php
/**
 * Unit Tests for Constants
 * 
 * Tests that all required constants are defined and have valid values
 */

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../lib/constants.php';

class ConstantsTest extends TestCase
{
    /**
     * Test that key constants are defined
     */
    public function testKeyConstants_AreDefined()
    {
        $requiredConstants = [
            'DEFAULT_WEBCAM_REFRESH',
            'DEFAULT_WEATHER_REFRESH',
            'RATE_LIMIT_WEATHER_MAX',
            'RATE_LIMIT_WEATHER_WINDOW',
            'RATE_LIMIT_WEBCAM_MAX',
            'RATE_LIMIT_WEBCAM_WINDOW',
            'BACKOFF_BASE_SECONDS',
            'CACHE_FILE_MAX_SIZE',
            'CURL_TIMEOUT',
            'CONFIG_CACHE_TTL',
            'MAX_STALE_HOURS',
            'STALE_WHILE_REVALIDATE_SECONDS',
        ];
        
        foreach ($requiredConstants as $const) {
            $this->assertTrue(
                defined($const),
                "Constant {$const} should be defined"
            );
        }
    }
    
    /**
     * Test that constants have reasonable values
     */
    public function testConstants_HaveReasonableValues()
    {
        // Refresh intervals should be positive
        $this->assertGreaterThan(0, DEFAULT_WEBCAM_REFRESH, 'DEFAULT_WEBCAM_REFRESH should be positive');
        $this->assertGreaterThan(0, DEFAULT_WEATHER_REFRESH, 'DEFAULT_WEATHER_REFRESH should be positive');
        
        // Rate limits should be positive
        $this->assertGreaterThan(0, RATE_LIMIT_WEATHER_MAX, 'RATE_LIMIT_WEATHER_MAX should be positive');
        $this->assertGreaterThan(0, RATE_LIMIT_WEATHER_WINDOW, 'RATE_LIMIT_WEATHER_WINDOW should be positive');
        $this->assertGreaterThan(0, RATE_LIMIT_WEBCAM_MAX, 'RATE_LIMIT_WEBCAM_MAX should be positive');
        $this->assertGreaterThan(0, RATE_LIMIT_WEBCAM_WINDOW, 'RATE_LIMIT_WEBCAM_WINDOW should be positive');
        
        // Backoff should be positive
        $this->assertGreaterThan(0, BACKOFF_BASE_SECONDS, 'BACKOFF_BASE_SECONDS should be positive');
        
        // File sizes should be positive
        $this->assertGreaterThan(0, CACHE_FILE_MAX_SIZE, 'CACHE_FILE_MAX_SIZE should be positive');
        
        // Timeouts should be positive
        $this->assertGreaterThan(0, CURL_TIMEOUT, 'CURL_TIMEOUT should be positive');
        
        // Cache TTL should be positive
        $this->assertGreaterThan(0, CONFIG_CACHE_TTL, 'CONFIG_CACHE_TTL should be positive');
        
        // Staleness thresholds should be positive
        $this->assertGreaterThan(0, MAX_STALE_HOURS, 'MAX_STALE_HOURS should be positive');
        $this->assertGreaterThan(0, STALE_WHILE_REVALIDATE_SECONDS, 'STALE_WHILE_REVALIDATE_SECONDS should be positive');
    }
    
    /**
     * Test that rate limit windows are reasonable
     */
    public function testRateLimitWindows_AreReasonable()
    {
        // Windows should be at least 1 second
        $this->assertGreaterThanOrEqual(1, RATE_LIMIT_WEATHER_WINDOW, 'RATE_LIMIT_WEATHER_WINDOW should be >= 1');
        $this->assertGreaterThanOrEqual(1, RATE_LIMIT_WEBCAM_WINDOW, 'RATE_LIMIT_WEBCAM_WINDOW should be >= 1');
        
        // Windows should not be unreasonably long (e.g., > 1 day)
        $this->assertLessThanOrEqual(86400, RATE_LIMIT_WEATHER_WINDOW, 'RATE_LIMIT_WEATHER_WINDOW should be <= 1 day');
        $this->assertLessThanOrEqual(86400, RATE_LIMIT_WEBCAM_WINDOW, 'RATE_LIMIT_WEBCAM_WINDOW should be <= 1 day');
    }
    
    /**
     * Test that file size limits are reasonable
     */
    public function testFileSizeLimits_AreReasonable()
    {
        // Cache file max size should be reasonable (e.g., between 1MB and 100MB)
        $this->assertGreaterThanOrEqual(1024 * 1024, CACHE_FILE_MAX_SIZE, 'CACHE_FILE_MAX_SIZE should be >= 1MB');
        $this->assertLessThanOrEqual(100 * 1024 * 1024, CACHE_FILE_MAX_SIZE, 'CACHE_FILE_MAX_SIZE should be <= 100MB');
    }
    
    /**
     * Test that timeout values are reasonable
     */
    public function testTimeoutValues_AreReasonable()
    {
        // Timeouts should be at least 1 second
        $this->assertGreaterThanOrEqual(1, CURL_TIMEOUT, 'CURL_TIMEOUT should be >= 1');
        
        // Timeouts should not be unreasonably long (e.g., > 5 minutes)
        $this->assertLessThanOrEqual(300, CURL_TIMEOUT, 'CURL_TIMEOUT should be <= 5 minutes');
    }
    
    /**
     * Test that staleness thresholds are reasonable
     */
    public function testStalenessThresholds_AreReasonable()
    {
        // MAX_STALE_HOURS should be reasonable (e.g., between 1 and 24 hours)
        $this->assertGreaterThanOrEqual(1, MAX_STALE_HOURS, 'MAX_STALE_HOURS should be >= 1 hour');
        $this->assertLessThanOrEqual(24, MAX_STALE_HOURS, 'MAX_STALE_HOURS should be <= 24 hours');
        
        // STALE_WHILE_REVALIDATE_SECONDS should be reasonable (e.g., between 60 and 3600 seconds)
        $this->assertGreaterThanOrEqual(60, STALE_WHILE_REVALIDATE_SECONDS, 'STALE_WHILE_REVALIDATE_SECONDS should be >= 60 seconds');
        $this->assertLessThanOrEqual(3600, STALE_WHILE_REVALIDATE_SECONDS, 'STALE_WHILE_REVALIDATE_SECONDS should be <= 1 hour');
    }
}

