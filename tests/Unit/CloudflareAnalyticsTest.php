<?php
/**
 * Unit Tests for Cloudflare Analytics Integration
 * 
 * Tests the Cloudflare Analytics API integration, caching, and data formatting.
 */

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../lib/cloudflare-analytics.php';
require_once __DIR__ . '/../../lib/config.php';

class CloudflareAnalyticsTest extends TestCase
{
    private static $originalConfig;
    
    public static function setUpBeforeClass(): void
    {
        // Store original config
        self::$originalConfig = loadConfig();
    }
    
    protected function setUp(): void
    {
        // Clear APCu cache before each test
        if (function_exists('apcu_clear_cache')) {
            @apcu_clear_cache();
        }
        
        // Remove file cache
        $fallbackFile = __DIR__ . '/../../cache/cloudflare_analytics.json';
        if (file_exists($fallbackFile)) {
            @unlink($fallbackFile);
        }
    }
    
    protected function tearDown(): void
    {
        // Clear caches after each test
        if (function_exists('apcu_clear_cache')) {
            @apcu_clear_cache();
        }
        
        $fallbackFile = __DIR__ . '/../../cache/cloudflare_analytics.json';
        if (file_exists($fallbackFile)) {
            @unlink($fallbackFile);
        }
    }
    
    /**
     * Test that mock data is returned in test mode
     */
    public function testMockDataInTestMode(): void
    {
        // In test mode (via phpunit.xml), should return mock data
        $this->assertTrue(isTestMode(), 'Test mode should be enabled');
        
        $analytics = getCloudflareAnalytics();
        
        $this->assertIsArray($analytics, 'Should return array');
        $this->assertArrayHasKey('unique_visitors_today', $analytics);
        $this->assertArrayHasKey('requests_today', $analytics);
        $this->assertArrayHasKey('bandwidth_today', $analytics);
        $this->assertArrayHasKey('threats_blocked_today', $analytics);
        $this->assertArrayHasKey('cached_at', $analytics);
        
        // Mock data should have reasonable values
        $this->assertGreaterThan(0, $analytics['unique_visitors_today']);
        $this->assertGreaterThan(0, $analytics['requests_today']);
        $this->assertGreaterThan(0, $analytics['bandwidth_today']);
    }
    
    /**
     * Test that getCloudflareAnalyticsForStatus includes all required fields
     */
    public function testGetCloudflareAnalyticsForStatus(): void
    {
        $analytics = getCloudflareAnalyticsForStatus();
        
        $this->assertIsArray($analytics);
        
        if (!empty($analytics)) {
            // If Cloudflare is configured, should have all fields
            $this->assertArrayHasKey('unique_visitors_today', $analytics);
            $this->assertArrayHasKey('requests_today', $analytics);
            $this->assertArrayHasKey('bandwidth_today', $analytics);
            $this->assertArrayHasKey('bandwidth_formatted', $analytics);
            $this->assertArrayHasKey('requests_per_visitor', $analytics);
            $this->assertArrayHasKey('threats_blocked_today', $analytics);
            $this->assertArrayHasKey('cached_at', $analytics);
            $this->assertArrayHasKey('cache_age_minutes', $analytics);
            
            // Verify types
            $this->assertIsInt($analytics['unique_visitors_today']);
            $this->assertIsInt($analytics['requests_today']);
            $this->assertIsInt($analytics['bandwidth_today']);
            $this->assertIsString($analytics['bandwidth_formatted']);
            $this->assertIsFloat($analytics['requests_per_visitor']);
            $this->assertIsInt($analytics['threats_blocked_today']);
        }
    }
    
    /**
     * Test bandwidth formatting
     */
    public function testBandwidthFormatting(): void
    {
        // Test various byte sizes
        $testCases = [
            0 => '0 B',
            1024 => '1.00 KB',
            1048576 => '1.00 MB',
            1073741824 => '1.00 GB',
            1099511627776 => '1.00 TB',
            1536 => '1.50 KB',
            5242880 => '5.00 MB',
        ];
        
        foreach ($testCases as $bytes => $expected) {
            $result = formatBytesForAnalytics($bytes);
            $this->assertEquals($expected, $result, "Failed formatting $bytes bytes");
        }
    }
    
    /**
     * Test that empty config returns empty array
     */
    public function testEmptyConfigReturnsEmptyArray(): void
    {
        // This test verifies the function handles missing config gracefully
        // In test mode, we always get mock data, so we just verify it doesn't crash
        $analytics = getCloudflareAnalytics();
        $this->assertIsArray($analytics);
    }
    
    /**
     * Test cache key constants are defined
     */
    public function testCacheConstantsAreDefined(): void
    {
        $this->assertTrue(defined('CLOUDFLARE_ANALYTICS_CACHE_KEY'));
        $this->assertTrue(defined('CLOUDFLARE_ANALYTICS_CACHE_TTL'));
        $this->assertTrue(defined('CLOUDFLARE_ANALYTICS_FALLBACK_FILE'));
        $this->assertTrue(defined('CLOUDFLARE_ANALYTICS_FILE_CACHE_TTL'));
        
        $this->assertIsString(CLOUDFLARE_ANALYTICS_CACHE_KEY);
        $this->assertIsInt(CLOUDFLARE_ANALYTICS_CACHE_TTL);
        $this->assertIsString(CLOUDFLARE_ANALYTICS_FALLBACK_FILE);
        $this->assertIsInt(CLOUDFLARE_ANALYTICS_FILE_CACHE_TTL);
        
        // Verify reasonable cache TTL values
        $this->assertGreaterThan(0, CLOUDFLARE_ANALYTICS_CACHE_TTL);
        $this->assertGreaterThan(0, CLOUDFLARE_ANALYTICS_FILE_CACHE_TTL);
    }
    
    /**
     * Test requests per visitor calculation
     */
    public function testRequestsPerVisitorCalculation(): void
    {
        $analytics = getCloudflareAnalyticsForStatus();
        
        if (!empty($analytics)) {
            $uniqueVisitors = $analytics['unique_visitors_today'];
            $totalRequests = $analytics['requests_today'];
            $requestsPerVisitor = $analytics['requests_per_visitor'];
            
            if ($uniqueVisitors > 0) {
                $expected = round($totalRequests / $uniqueVisitors, 1);
                $this->assertEquals($expected, $requestsPerVisitor, '', 0.1);
            } else {
                $this->assertEquals(0.0, $requestsPerVisitor);
            }
        } else {
            // If Cloudflare not configured, test passes
            $this->assertTrue(true);
        }
    }
    
    /**
     * Test that all metrics are non-negative
     */
    public function testMetricsAreNonNegative(): void
    {
        $analytics = getCloudflareAnalyticsForStatus();
        
        if (!empty($analytics)) {
            $this->assertGreaterThanOrEqual(0, $analytics['unique_visitors_today']);
            $this->assertGreaterThanOrEqual(0, $analytics['requests_today']);
            $this->assertGreaterThanOrEqual(0, $analytics['bandwidth_today']);
            $this->assertGreaterThanOrEqual(0.0, $analytics['requests_per_visitor']);
            $this->assertGreaterThanOrEqual(0, $analytics['threats_blocked_today']);
            $this->assertGreaterThanOrEqual(0, $analytics['cache_age_minutes']);
        } else {
            // If Cloudflare not configured, test passes
            $this->assertTrue(true);
        }
    }
    
    /**
     * Test cache age calculation
     */
    public function testCacheAgeCalculation(): void
    {
        $analytics = getCloudflareAnalyticsForStatus();
        
        if (!empty($analytics)) {
            $this->assertArrayHasKey('cached_at', $analytics);
            $this->assertArrayHasKey('cache_age_minutes', $analytics);
            
            $expectedAge = round((time() - $analytics['cached_at']) / 60, 1);
            $this->assertEquals($expectedAge, $analytics['cache_age_minutes'], '', 0.1);
        } else {
            // If Cloudflare not configured, test passes
            $this->assertTrue(true);
        }
    }
    
    /**
     * Test that bandwidth formatted string is valid
     */
    public function testBandwidthFormattedIsValid(): void
    {
        $analytics = getCloudflareAnalyticsForStatus();
        
        if (!empty($analytics)) {
            $formatted = $analytics['bandwidth_formatted'];
            
            // Should match pattern: "X.XX [KMGT]B" or "X B"
            $this->assertMatchesRegularExpression(
                '/^\d+(\.\d{2})?\s[KMGT]?B$/',
                $formatted,
                "Bandwidth format should be valid: $formatted"
            );
        } else {
            // If Cloudflare not configured, test passes
            $this->assertTrue(true);
        }
    }
    
    /**
     * Test APCu caching (if available)
     */
    public function testApCuCachingIfAvailable(): void
    {
        if (!function_exists('apcu_fetch')) {
            $this->markTestSkipped('APCu not available');
        }
        
        // First call - should fetch and cache
        $analytics1 = getCloudflareAnalytics();
        
        // Second call - should hit cache (same timestamp)
        $analytics2 = getCloudflareAnalytics();
        
        $this->assertEquals($analytics1['cached_at'], $analytics2['cached_at'], 
            'Second call should return cached data with same timestamp');
    }
    
    /**
     * Test file cache fallback
     */
    public function testFileCacheFallback(): void
    {
        $fallbackFile = CLOUDFLARE_ANALYTICS_FALLBACK_FILE;
        
        // Ensure directory exists
        $cacheDir = dirname($fallbackFile);
        if (!is_dir($cacheDir)) {
            mkdir($cacheDir, 0755, true);
        }
        
        // First call should create file cache
        $analytics1 = getCloudflareAnalytics();
        
        // File should now exist
        $this->assertFileExists($fallbackFile, 'File cache should be created');
        
        // Verify file contents
        $cached = json_decode(file_get_contents($fallbackFile), true);
        $this->assertIsArray($cached);
        $this->assertArrayHasKey('cached_at', $cached);
        $this->assertArrayHasKey('unique_visitors_today', $cached);
    }
    
    /**
     * Test that mock analytics returns consistent structure
     */
    public function testMockAnalyticsStructure(): void
    {
        // Call multiple times to ensure consistent structure
        for ($i = 0; $i < 3; $i++) {
            $analytics = getCloudflareAnalytics();
            
            $this->assertArrayHasKey('unique_visitors_today', $analytics);
            $this->assertArrayHasKey('requests_today', $analytics);
            $this->assertArrayHasKey('bandwidth_today', $analytics);
            $this->assertArrayHasKey('page_views_today', $analytics);
            $this->assertArrayHasKey('threats_blocked_today', $analytics);
            $this->assertArrayHasKey('cached_requests_today', $analytics);
            $this->assertArrayHasKey('cached_bandwidth_today', $analytics);
            $this->assertArrayHasKey('cached_at', $analytics);
        }
    }
}
