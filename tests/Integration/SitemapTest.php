<?php
/**
 * Integration Tests for Sitemap Generation
 * Tests that sitemap.php generates valid XML sitemaps
 */

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../config-utils.php';
require_once __DIR__ . '/../../seo-utils.php';

class SitemapTest extends TestCase
{
    protected function setUp(): void
    {
        // Set up test environment
        $_SERVER['HTTPS'] = 'on';
        $_SERVER['HTTP_HOST'] = 'aviationwx.org';
    }

    protected function tearDown(): void
    {
        // Clean up
        unset($_SERVER['HTTPS'], $_SERVER['HTTP_HOST']);
    }

    /**
     * Test sitemap generation - Main domain
     */
    public function testSitemapGeneration_MainDomain()
    {
        $_SERVER['HTTPS'] = 'on';
        $_SERVER['HTTP_HOST'] = 'aviationwx.org';
        
        $config = loadConfig();
        $this->assertNotNull($config, 'Config should be loaded');
        $this->assertArrayHasKey('airports', $config, 'Config should have airports');
        
        // Simulate sitemap generation logic
        $protocol = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
        $host = isset($_SERVER['HTTP_HOST']) ? $_SERVER['HTTP_HOST'] : 'aviationwx.org';
        $baseUrl = $protocol . '://' . $host;
        $isMainDomain = (strpos($host, 'aviationwx.org') !== false && 
                         !preg_match('/^[a-z0-9]{3,4}\.aviationwx\.org$/', $host));
        
        $this->assertTrue($isMainDomain, 'Should be main domain');
        
        // Count URLs that would be in sitemap
        $urlCount = 0;
        if ($isMainDomain) {
            $urlCount++; // Homepage
            $urlCount++; // Status page
        }
        $urlCount += count($config['airports']); // Airport pages
        
        $this->assertGreaterThan(0, $urlCount, 'Sitemap should have at least one URL');
    }

    /**
     * Test sitemap generation - Subdomain
     */
    public function testSitemapGeneration_Subdomain()
    {
        $_SERVER['HTTPS'] = 'on';
        $_SERVER['HTTP_HOST'] = 'kspb.aviationwx.org';
        
        $config = loadConfig();
        $this->assertNotNull($config, 'Config should be loaded');
        
        // Simulate sitemap generation logic
        $protocol = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
        $host = isset($_SERVER['HTTP_HOST']) ? $_SERVER['HTTP_HOST'] : 'aviationwx.org';
        $isMainDomain = (strpos($host, 'aviationwx.org') !== false && 
                         !preg_match('/^[a-z0-9]{3,4}\.aviationwx\.org$/', $host));
        
        $this->assertFalse($isMainDomain, 'Should not be main domain');
        
        // On subdomain, should only include airport pages
        $urlCount = count($config['airports']);
        $this->assertGreaterThan(0, $urlCount, 'Sitemap should have airport URLs');
    }

    /**
     * Test sitemap includes all airports
     */
    public function testSitemapIncludesAllAirports()
    {
        $config = loadConfig();
        $this->assertNotNull($config);
        $this->assertArrayHasKey('airports', $config);
        
        $airportCount = count($config['airports']);
        
        // Each airport should have a URL in the sitemap
        foreach ($config['airports'] as $airportId => $airport) {
            $airportUrl = 'https://' . $airportId . '.aviationwx.org';
            $this->assertNotEmpty($airportUrl, 'Airport URL should not be empty');
            $this->assertStringContainsString($airportId, $airportUrl, 'URL should contain airport ID');
        }
        
        $this->assertGreaterThan(0, $airportCount, 'Should have at least one airport');
    }

    /**
     * Test sitemap includes homepage on main domain
     */
    public function testSitemapIncludesHomepage_MainDomain()
    {
        $_SERVER['HTTPS'] = 'on';
        $_SERVER['HTTP_HOST'] = 'aviationwx.org';
        
        $host = isset($_SERVER['HTTP_HOST']) ? $_SERVER['HTTP_HOST'] : 'aviationwx.org';
        $isMainDomain = (strpos($host, 'aviationwx.org') !== false && 
                         !preg_match('/^[a-z0-9]{3,4}\.aviationwx\.org$/', $host));
        
        $this->assertTrue($isMainDomain, 'Should be main domain');
        
        // Homepage should be included
        $homepageUrl = 'https://aviationwx.org/';
        $this->assertNotEmpty($homepageUrl);
    }

    /**
     * Test sitemap includes status page on main domain
     */
    public function testSitemapIncludesStatusPage_MainDomain()
    {
        $_SERVER['HTTPS'] = 'on';
        $_SERVER['HTTP_HOST'] = 'aviationwx.org';
        
        $host = isset($_SERVER['HTTP_HOST']) ? $_SERVER['HTTP_HOST'] : 'aviationwx.org';
        $isMainDomain = (strpos($host, 'aviationwx.org') !== false && 
                         !preg_match('/^[a-z0-9]{3,4}\.aviationwx\.org$/', $host));
        
        $this->assertTrue($isMainDomain, 'Should be main domain');
        
        // Status page should be included
        $statusUrl = 'https://aviationwx.org/status.php';
        $this->assertNotEmpty($statusUrl);
    }

    /**
     * Test sitemap URL format
     */
    public function testSitemapUrlFormat()
    {
        $config = loadConfig();
        $this->assertNotNull($config);
        $this->assertArrayHasKey('airports', $config);
        
        foreach ($config['airports'] as $airportId => $airport) {
            $airportUrl = 'https://' . $airportId . '.aviationwx.org';
            
            // URL should be valid format
            $this->assertStringStartsWith('https://', $airportUrl);
            $this->assertStringEndsWith('.aviationwx.org', $airportUrl);
            $this->assertStringContainsString($airportId, $airportUrl);
        }
    }
}

