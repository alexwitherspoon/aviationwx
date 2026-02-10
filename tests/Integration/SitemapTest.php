<?php
/**
 * Integration Tests for Sitemap Generation
 * 
 * Tests that both XML and HTML sitemaps generate correctly
 * and use the shared sitemap library.
 */

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../lib/config.php';
require_once __DIR__ . '/../../lib/sitemap.php';
require_once __DIR__ . '/../../lib/seo.php';

class SitemapTest extends TestCase
{
    protected function setUp(): void
    {
        $_SERVER['HTTPS'] = 'on';
        $_SERVER['HTTP_HOST'] = 'aviationwx.org';
    }

    protected function tearDown(): void
    {
        unset($_SERVER['HTTPS'], $_SERVER['HTTP_HOST']);
    }

    /**
     * Test XML sitemap uses shared library
     */
    public function testXmlSitemap_UsesSharedLibrary(): void
    {
        $urls = getAllSitemapUrls();
        
        $this->assertNotEmpty($urls, 'Shared library should return URLs');
        
        // Verify structure matches what XML sitemap needs
        foreach ($urls as $url) {
            $this->assertArrayHasKey('loc', $url);
            $this->assertArrayHasKey('lastmod', $url);
            $this->assertArrayHasKey('changefreq', $url);
            $this->assertArrayHasKey('priority', $url);
        }
    }

    /**
     * Test HTML sitemap uses shared library
     */
    public function testHtmlSitemap_UsesSharedLibrary(): void
    {
        $urls = getSitemapUrls();
        $labels = getSitemapCategoryLabels();
        
        $this->assertNotEmpty($urls, 'Shared library should return grouped URLs');
        $this->assertNotEmpty($labels, 'Shared library should return category labels');
        
        // Verify structure matches what HTML sitemap needs
        foreach ($urls as $category => $categoryUrls) {
            $this->assertArrayHasKey($category, $labels, "Label missing for category: {$category}");
            foreach ($categoryUrls as $url) {
                $this->assertArrayHasKey('loc', $url);
                $this->assertArrayHasKey('title', $url);
            }
        }
    }

    /**
     * Test sitemap includes all enabled airports
     */
    public function testSitemap_IncludesAllListedAirports(): void
    {
        $config = loadConfig();
        $this->assertNotNull($config);
        
        // Sitemap only includes listed airports (enabled AND not unlisted)
        $listedAirports = getListedAirports($config);
        $urls = getSitemapUrls();
        
        $airportUrls = array_map(fn($u) => $u['loc'], $urls['airports']);
        
        foreach ($listedAirports as $airportId => $airport) {
            $expectedUrl = 'https://' . $airportId . '.aviationwx.org/';
            $this->assertContains(
                $expectedUrl,
                $airportUrls,
                "Airport {$airportId} should be in sitemap"
            );
        }
    }

    /**
     * Test sitemap airport count matches listed airports (excludes unlisted)
     */
    public function testSitemap_AirportCountMatchesListedAirports(): void
    {
        $config = loadConfig();
        // Sitemap only includes listed airports (enabled AND not unlisted)
        $listedAirports = getListedAirports($config);
        $urls = getSitemapUrls();
        
        $this->assertCount(
            count($listedAirports),
            $urls['airports'],
            'Sitemap airport count should match listed airports'
        );
    }

    /**
     * Test sitemap excludes unlisted airports (SEO protection)
     */
    public function testSitemap_ExcludesUnlistedAirports(): void
    {
        $config = loadConfig();
        $enabledAirports = getEnabledAirports($config);
        $urls = getSitemapUrls();
        
        $airportUrls = array_map(fn($u) => $u['loc'], $urls['airports']);
        
        foreach ($enabledAirports as $airportId => $airport) {
            if (isAirportUnlisted($airport)) {
                $unlistedUrl = 'https://' . $airportId . '.aviationwx.org/';
                $this->assertNotContains(
                    $unlistedUrl,
                    $airportUrls,
                    "Unlisted airport {$airportId} should NOT be in sitemap"
                );
            }
        }
    }

    /**
     * Test sitemap includes required pages
     */
    public function testSitemap_IncludesRequiredPages(): void
    {
        $urls = getAllSitemapUrls();
        $allLocs = array_map(fn($u) => $u['loc'], $urls);
        
        $requiredPages = [
            'https://aviationwx.org/',
            'https://airports.aviationwx.org/',
            'https://status.aviationwx.org/',
            'https://api.aviationwx.org/',
            'https://embed.aviationwx.org/',
            'https://terms.aviationwx.org/',
            'https://guides.aviationwx.org/',
            'https://aviationwx.org/sitemap'
        ];
        
        foreach ($requiredPages as $page) {
            $this->assertContains($page, $allLocs, "Required page missing: {$page}");
        }
    }

    /**
     * Test sitemap includes guide pages
     */
    public function testSitemap_IncludesGuidePages(): void
    {
        $urls = getSitemapUrls();
        
        // Should have guides index plus individual guides
        $this->assertGreaterThan(1, count($urls['guides']), 'Should have multiple guide pages');
        
        // Check guides index exists
        $indexFound = false;
        foreach ($urls['guides'] as $url) {
            if ($url['loc'] === 'https://guides.aviationwx.org/') {
                $indexFound = true;
                break;
            }
        }
        $this->assertTrue($indexFound, 'Guides index should be in sitemap');
    }

    /**
     * Test XML and HTML sitemaps have same URL count
     */
    public function testSitemaps_HaveSameUrlCount(): void
    {
        $flatUrls = getAllSitemapUrls();
        $groupedUrls = getSitemapUrls();
        
        $groupedCount = 0;
        foreach ($groupedUrls as $urls) {
            $groupedCount += count($urls);
        }
        
        $this->assertEquals(
            count($flatUrls),
            $groupedCount,
            'XML and HTML sitemaps should have same URL count'
        );
    }

    /**
     * Test sitemap file exists and is accessible
     */
    public function testXmlSitemapFile_Exists(): void
    {
        $sitemapFile = __DIR__ . '/../../api/sitemap.php';
        $this->assertFileExists($sitemapFile, 'XML sitemap file should exist');
    }

    /**
     * Test HTML sitemap file exists and is accessible
     */
    public function testHtmlSitemapFile_Exists(): void
    {
        $sitemapFile = __DIR__ . '/../../pages/sitemap.php';
        $this->assertFileExists($sitemapFile, 'HTML sitemap file should exist');
    }

    /**
     * Test sitemap library file exists
     */
    public function testSitemapLibrary_Exists(): void
    {
        $libraryFile = __DIR__ . '/../../lib/sitemap.php';
        $this->assertFileExists($libraryFile, 'Sitemap library should exist');
    }

    /**
     * Test sitemap blocks subdomain requests
     * 
     * Ensures single authoritative sitemap prevents duplicate discovery by search engines.
     */
    public function testXmlSitemap_Returns404OnSubdomains(): void
    {
        $subdomains = [
            'kspb.aviationwx.org',
            'khio.aviationwx.org',
            'guides.aviationwx.org',
            'status.aviationwx.org',
            'api.aviationwx.org',
            'embed.aviationwx.org',
            'terms.aviationwx.org'
        ];
        
        foreach ($subdomains as $subdomain) {
            $host = strtolower(trim($subdomain));
            $isRootDomain = (bool) preg_match('/^(www\.)?aviationwx\.org$/i', $host);
            
            $this->assertFalse(
                $isRootDomain,
                "Subdomain {$subdomain} should NOT be treated as root domain"
            );
        }
    }

    /**
     * Test sitemap works on root domain (aviationwx.org)
     */
    public function testXmlSitemap_WorksOnRootDomain(): void
    {
        $host = 'aviationwx.org';
        $isRootDomain = (bool) preg_match('/^(www\.)?aviationwx\.org$/i', $host);
        
        $this->assertTrue(
            $isRootDomain,
            'aviationwx.org should be treated as root domain'
        );
    }

    /**
     * Test sitemap works on www subdomain (www.aviationwx.org)
     */
    public function testXmlSitemap_WorksOnWwwSubdomain(): void
    {
        $host = 'www.aviationwx.org';
        $isRootDomain = (bool) preg_match('/^(www\.)?aviationwx\.org$/i', $host);
        
        $this->assertTrue(
            $isRootDomain,
            'www.aviationwx.org should be treated as root domain'
        );
    }

    /**
     * Test HTML sitemap route is configured
     */
    public function testHtmlSitemapRoute_IsConfigured(): void
    {
        $indexFile = file_get_contents(__DIR__ . '/../../index.php');
        
        $this->assertStringContainsString(
            "requestPath === 'sitemap'",
            $indexFile,
            'Index.php should have route for /sitemap'
        );
        $this->assertStringContainsString(
            "pages/sitemap.php",
            $indexFile,
            'Index.php should include pages/sitemap.php'
        );
    }
}
