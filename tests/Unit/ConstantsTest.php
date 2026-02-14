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
            // 3-tier staleness constants (general)
            'DEFAULT_STALE_WARNING_SECONDS',
            'DEFAULT_STALE_ERROR_SECONDS',
            'DEFAULT_STALE_FAILCLOSED_SECONDS',
            'MIN_STALE_WARNING_SECONDS',
            'MIN_STALE_ERROR_SECONDS',
            'MIN_STALE_FAILCLOSED_SECONDS',
            // Limited-availability outage banner
            'DEFAULT_LIMITED_AVAILABILITY_OUTAGE_SECONDS',
            'MIN_LIMITED_AVAILABILITY_OUTAGE_SECONDS',
            // 3-tier staleness constants (METAR)
            'DEFAULT_METAR_STALE_WARNING_SECONDS',
            'DEFAULT_METAR_STALE_ERROR_SECONDS',
            'DEFAULT_METAR_STALE_FAILCLOSED_SECONDS',
            // 3-tier staleness constants (NOTAM)
            'DEFAULT_NOTAM_STALE_WARNING_SECONDS',
            'DEFAULT_NOTAM_STALE_ERROR_SECONDS',
            'DEFAULT_NOTAM_STALE_FAILCLOSED_SECONDS',
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
        
        // 3-tier staleness thresholds should be positive
        $this->assertGreaterThan(0, DEFAULT_STALE_WARNING_SECONDS, 'DEFAULT_STALE_WARNING_SECONDS should be positive');
        $this->assertGreaterThan(0, DEFAULT_STALE_ERROR_SECONDS, 'DEFAULT_STALE_ERROR_SECONDS should be positive');
        $this->assertGreaterThan(0, DEFAULT_STALE_FAILCLOSED_SECONDS, 'DEFAULT_STALE_FAILCLOSED_SECONDS should be positive');
        $this->assertGreaterThan(0, DEFAULT_METAR_STALE_WARNING_SECONDS, 'DEFAULT_METAR_STALE_WARNING_SECONDS should be positive');
        $this->assertGreaterThan(0, DEFAULT_METAR_STALE_ERROR_SECONDS, 'DEFAULT_METAR_STALE_ERROR_SECONDS should be positive');
        $this->assertGreaterThan(0, DEFAULT_METAR_STALE_FAILCLOSED_SECONDS, 'DEFAULT_METAR_STALE_FAILCLOSED_SECONDS should be positive');
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
     * Test that 3-tier staleness thresholds are reasonable
     */
    public function testStalenessThresholds_AreReasonable()
    {
        // General staleness thresholds should follow: warning < error < failclosed
        $this->assertLessThan(DEFAULT_STALE_ERROR_SECONDS, DEFAULT_STALE_WARNING_SECONDS, 
            'DEFAULT_STALE_WARNING_SECONDS should be < DEFAULT_STALE_ERROR_SECONDS');
        $this->assertLessThan(DEFAULT_STALE_FAILCLOSED_SECONDS, DEFAULT_STALE_ERROR_SECONDS, 
            'DEFAULT_STALE_ERROR_SECONDS should be < DEFAULT_STALE_FAILCLOSED_SECONDS');
        
        // Warning should be reasonable (5 min to 1 hour)
        $this->assertGreaterThanOrEqual(300, DEFAULT_STALE_WARNING_SECONDS, 'DEFAULT_STALE_WARNING_SECONDS should be >= 5 min');
        $this->assertLessThanOrEqual(3600, DEFAULT_STALE_WARNING_SECONDS, 'DEFAULT_STALE_WARNING_SECONDS should be <= 1 hour');
        
        // Error should be reasonable (30 min to 6 hours)
        $this->assertGreaterThanOrEqual(1800, DEFAULT_STALE_ERROR_SECONDS, 'DEFAULT_STALE_ERROR_SECONDS should be >= 30 min');
        $this->assertLessThanOrEqual(21600, DEFAULT_STALE_ERROR_SECONDS, 'DEFAULT_STALE_ERROR_SECONDS should be <= 6 hours');
        
        // Failclosed should be reasonable (1 hour to 24 hours)
        $this->assertGreaterThanOrEqual(3600, DEFAULT_STALE_FAILCLOSED_SECONDS, 'DEFAULT_STALE_FAILCLOSED_SECONDS should be >= 1 hour');
        $this->assertLessThanOrEqual(86400, DEFAULT_STALE_FAILCLOSED_SECONDS, 'DEFAULT_STALE_FAILCLOSED_SECONDS should be <= 24 hours');
        
        // STALE_WHILE_REVALIDATE_SECONDS should be reasonable (60s to 1 hour)
        $this->assertGreaterThanOrEqual(60, STALE_WHILE_REVALIDATE_SECONDS, 'STALE_WHILE_REVALIDATE_SECONDS should be >= 60 seconds');
        $this->assertLessThanOrEqual(3600, STALE_WHILE_REVALIDATE_SECONDS, 'STALE_WHILE_REVALIDATE_SECONDS should be <= 1 hour');
    }
    
    /**
     * Test that METAR staleness thresholds are reasonable
     */
    public function testMetarStalenessThresholds_AreReasonable()
    {
        // METAR staleness thresholds should follow: warning < error < failclosed
        $this->assertLessThan(DEFAULT_METAR_STALE_ERROR_SECONDS, DEFAULT_METAR_STALE_WARNING_SECONDS, 
            'DEFAULT_METAR_STALE_WARNING_SECONDS should be < DEFAULT_METAR_STALE_ERROR_SECONDS');
        $this->assertLessThan(DEFAULT_METAR_STALE_FAILCLOSED_SECONDS, DEFAULT_METAR_STALE_ERROR_SECONDS, 
            'DEFAULT_METAR_STALE_ERROR_SECONDS should be < DEFAULT_METAR_STALE_FAILCLOSED_SECONDS');
        
        // METAR thresholds should be longer than general thresholds (since METAR is hourly)
        $this->assertGreaterThanOrEqual(DEFAULT_STALE_WARNING_SECONDS, DEFAULT_METAR_STALE_WARNING_SECONDS,
            'DEFAULT_METAR_STALE_WARNING_SECONDS should be >= DEFAULT_STALE_WARNING_SECONDS');
    }
    
    /**
     * Test that NOTAM staleness thresholds are reasonable
     */
    public function testNotamStalenessThresholds_AreReasonable()
    {
        // NOTAM staleness thresholds should follow: warning < error < failclosed
        $this->assertLessThan(DEFAULT_NOTAM_STALE_ERROR_SECONDS, DEFAULT_NOTAM_STALE_WARNING_SECONDS, 
            'DEFAULT_NOTAM_STALE_WARNING_SECONDS should be < DEFAULT_NOTAM_STALE_ERROR_SECONDS');
        $this->assertLessThan(DEFAULT_NOTAM_STALE_FAILCLOSED_SECONDS, DEFAULT_NOTAM_STALE_ERROR_SECONDS, 
            'DEFAULT_NOTAM_STALE_ERROR_SECONDS should be < DEFAULT_NOTAM_STALE_FAILCLOSED_SECONDS');
    }
    
    /**
     * Test that minimum staleness thresholds are enforced
     */
    public function testMinimumStalenessThresholds_AreEnforced()
    {
        // Minimums should be positive
        $this->assertGreaterThan(0, MIN_STALE_WARNING_SECONDS, 'MIN_STALE_WARNING_SECONDS should be positive');
        $this->assertGreaterThan(0, MIN_STALE_ERROR_SECONDS, 'MIN_STALE_ERROR_SECONDS should be positive');
        $this->assertGreaterThan(0, MIN_STALE_FAILCLOSED_SECONDS, 'MIN_STALE_FAILCLOSED_SECONDS should be positive');
        
        // Defaults should be >= minimums
        $this->assertGreaterThanOrEqual(MIN_STALE_WARNING_SECONDS, DEFAULT_STALE_WARNING_SECONDS,
            'DEFAULT_STALE_WARNING_SECONDS should be >= MIN_STALE_WARNING_SECONDS');
        $this->assertGreaterThanOrEqual(MIN_STALE_ERROR_SECONDS, DEFAULT_STALE_ERROR_SECONDS,
            'DEFAULT_STALE_ERROR_SECONDS should be >= MIN_STALE_ERROR_SECONDS');
        $this->assertGreaterThanOrEqual(MIN_STALE_FAILCLOSED_SECONDS, DEFAULT_STALE_FAILCLOSED_SECONDS,
            'DEFAULT_STALE_FAILCLOSED_SECONDS should be >= MIN_STALE_FAILCLOSED_SECONDS');
        // Limited-availability outage banner
        $this->assertGreaterThanOrEqual(MIN_LIMITED_AVAILABILITY_OUTAGE_SECONDS, DEFAULT_LIMITED_AVAILABILITY_OUTAGE_SECONDS,
            'DEFAULT_LIMITED_AVAILABILITY_OUTAGE_SECONDS should be >= MIN_LIMITED_AVAILABILITY_OUTAGE_SECONDS');
    }
    
    /**
     * Test that sentinel value constants are defined
     */
    public function testSentinelConstants_AreDefined()
    {
        $this->assertTrue(defined('UNLIMITED_VISIBILITY_SM'), 'UNLIMITED_VISIBILITY_SM should be defined');
        $this->assertTrue(defined('UNLIMITED_CEILING_FT'), 'UNLIMITED_CEILING_FT should be defined');
    }
    
    /**
     * Test that sentinel values are outside normal climate bounds
     */
    public function testSentinelValues_AreOutsideClimateBounds()
    {
        require_once __DIR__ . '/../../lib/constants.php';
        
        // Sentinel visibility should be > max visibility
        $this->assertGreaterThan(CLIMATE_VISIBILITY_MAX_SM, UNLIMITED_VISIBILITY_SM, 'UNLIMITED_VISIBILITY_SM should be > CLIMATE_VISIBILITY_MAX_SM');
        
        // Sentinel ceiling should be > max ceiling
        $this->assertGreaterThan(CLIMATE_CEILING_MAX_FT, UNLIMITED_CEILING_FT, 'UNLIMITED_CEILING_FT should be > CLIMATE_CEILING_MAX_FT');
        
        // Sentinel values should have expected values
        $this->assertEquals(999.0, UNLIMITED_VISIBILITY_SM, 'UNLIMITED_VISIBILITY_SM should be 999.0');
        $this->assertEquals(99999, UNLIMITED_CEILING_FT, 'UNLIMITED_CEILING_FT should be 99999');
    }
}

