<?php
/**
 * Unit Tests for Sitemap Library Functions
 * 
 * Tests the shared sitemap URL generation used by both XML and HTML sitemaps.
 */

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../lib/sitemap.php';

class SitemapLibraryTest extends TestCase
{
    /**
     * Test getSitemapUrls returns expected category structure
     */
    public function testGetSitemapUrls_ReturnsExpectedCategories(): void
    {
        $urls = getSitemapUrls();
        
        $this->assertIsArray($urls);
        $this->assertArrayHasKey('main', $urls);
        $this->assertArrayHasKey('airports', $urls);
        $this->assertArrayHasKey('guides', $urls);
        $this->assertArrayHasKey('tools', $urls);
        $this->assertArrayHasKey('legal', $urls);
    }
    
    /**
     * Test getSitemapUrls returns non-empty results
     */
    public function testGetSitemapUrls_ReturnsNonEmptyResults(): void
    {
        $urls = getSitemapUrls();
        
        $this->assertNotEmpty($urls['main'], 'Main category should have URLs');
        $this->assertNotEmpty($urls['tools'], 'Tools category should have URLs');
        $this->assertNotEmpty($urls['legal'], 'Legal category should have URLs');
    }
    
    /**
     * Test each URL entry has required fields
     */
    public function testGetSitemapUrls_EachUrlHasRequiredFields(): void
    {
        $urls = getSitemapUrls();
        $requiredFields = ['loc', 'title', 'lastmod', 'changefreq', 'priority'];
        
        foreach ($urls as $category => $categoryUrls) {
            foreach ($categoryUrls as $index => $url) {
                foreach ($requiredFields as $field) {
                    $this->assertArrayHasKey(
                        $field,
                        $url,
                        "URL at {$category}[{$index}] missing required field: {$field}"
                    );
                    $this->assertNotEmpty(
                        $url[$field],
                        "URL at {$category}[{$index}] has empty {$field}"
                    );
                }
            }
        }
    }
    
    /**
     * Test all URLs use HTTPS protocol
     */
    public function testGetSitemapUrls_AllUrlsUseHttps(): void
    {
        $urls = getSitemapUrls();
        
        foreach ($urls as $category => $categoryUrls) {
            foreach ($categoryUrls as $url) {
                $this->assertStringStartsWith(
                    'https://',
                    $url['loc'],
                    "URL in {$category} should use HTTPS: {$url['loc']}"
                );
            }
        }
    }
    
    /**
     * Test homepage is included in main category
     */
    public function testGetSitemapUrls_IncludesHomepage(): void
    {
        $urls = getSitemapUrls();
        
        $homepageFound = false;
        foreach ($urls['main'] as $url) {
            if ($url['loc'] === 'https://aviationwx.org/') {
                $homepageFound = true;
                $this->assertEquals('1.0', $url['priority'], 'Homepage should have highest priority');
                break;
            }
        }
        
        $this->assertTrue($homepageFound, 'Homepage should be in sitemap');
    }
    
    /**
     * Test HTML sitemap is included in tools category
     */
    public function testGetSitemapUrls_IncludesHtmlSitemap(): void
    {
        $urls = getSitemapUrls();
        
        $sitemapFound = false;
        foreach ($urls['tools'] as $url) {
            if ($url['loc'] === 'https://aviationwx.org/sitemap') {
                $sitemapFound = true;
                $this->assertEquals('Site Map', $url['title']);
                break;
            }
        }
        
        $this->assertTrue($sitemapFound, 'HTML sitemap should be in sitemap');
    }
    
    /**
     * Test airport URLs have correct subdomain format
     */
    public function testGetSitemapUrls_AirportUrlsHaveCorrectFormat(): void
    {
        $urls = getSitemapUrls();
        
        foreach ($urls['airports'] as $url) {
            $this->assertMatchesRegularExpression(
                '/^https:\/\/[a-z0-9]+\.aviationwx\.org\/$/',
                $url['loc'],
                "Airport URL should match subdomain pattern: {$url['loc']}"
            );
        }
    }
    
    /**
     * Test getAllSitemapUrls returns flat array
     */
    public function testGetAllSitemapUrls_ReturnsFlatArray(): void
    {
        $allUrls = getAllSitemapUrls();
        
        $this->assertIsArray($allUrls);
        $this->assertNotEmpty($allUrls);
        
        // Should be a flat array (numeric keys, no nested arrays)
        foreach ($allUrls as $index => $url) {
            $this->assertIsInt($index, 'Keys should be numeric');
            $this->assertIsArray($url, 'Each item should be an array');
            $this->assertArrayHasKey('loc', $url);
        }
    }
    
    /**
     * Test getAllSitemapUrls count matches grouped count
     */
    public function testGetAllSitemapUrls_CountMatchesGroupedCount(): void
    {
        $grouped = getSitemapUrls();
        $flat = getAllSitemapUrls();
        
        $groupedCount = 0;
        foreach ($grouped as $urls) {
            $groupedCount += count($urls);
        }
        
        $this->assertEquals(
            $groupedCount,
            count($flat),
            'Flat URL count should match sum of grouped counts'
        );
    }
    
    /**
     * Test getSitemapCategoryLabels returns all categories
     */
    public function testGetSitemapCategoryLabels_ReturnsAllCategories(): void
    {
        $labels = getSitemapCategoryLabels();
        $urls = getSitemapUrls();
        
        foreach (array_keys($urls) as $category) {
            $this->assertArrayHasKey(
                $category,
                $labels,
                "Category '{$category}' should have a label"
            );
        }
    }
    
    /**
     * Test getSitemapCategoryLabels returns non-empty strings
     */
    public function testGetSitemapCategoryLabels_ReturnsNonEmptyStrings(): void
    {
        $labels = getSitemapCategoryLabels();
        
        foreach ($labels as $category => $label) {
            $this->assertIsString($label, "Label for '{$category}' should be string");
            $this->assertNotEmpty($label, "Label for '{$category}' should not be empty");
        }
    }
    
    /**
     * Test lastmod dates are valid format
     */
    public function testGetSitemapUrls_LastmodDatesAreValidFormat(): void
    {
        $urls = getAllSitemapUrls();
        
        foreach ($urls as $url) {
            // Should be either YYYY-MM-DD or YYYY-MM-DDTHH:MM:SSZ format
            $this->assertMatchesRegularExpression(
                '/^\d{4}-\d{2}-\d{2}(T\d{2}:\d{2}:\d{2}Z)?$/',
                $url['lastmod'],
                "Invalid lastmod format for {$url['loc']}: {$url['lastmod']}"
            );
        }
    }
    
    /**
     * Test priority values are valid
     */
    public function testGetSitemapUrls_PriorityValuesAreValid(): void
    {
        $urls = getAllSitemapUrls();
        
        foreach ($urls as $url) {
            $priority = (float) $url['priority'];
            $this->assertGreaterThanOrEqual(0.0, $priority);
            $this->assertLessThanOrEqual(1.0, $priority);
        }
    }
    
    /**
     * Test changefreq values are valid
     */
    public function testGetSitemapUrls_ChangefreqValuesAreValid(): void
    {
        $validFrequencies = ['always', 'hourly', 'daily', 'weekly', 'monthly', 'yearly', 'never'];
        $urls = getAllSitemapUrls();
        
        foreach ($urls as $url) {
            $this->assertContains(
                $url['changefreq'],
                $validFrequencies,
                "Invalid changefreq for {$url['loc']}: {$url['changefreq']}"
            );
        }
    }
    
    /**
     * Test guides have proper title formatting
     */
    public function testGetSitemapUrls_GuideTitlesAreFormatted(): void
    {
        $urls = getSitemapUrls();
        
        foreach ($urls['guides'] as $url) {
            if ($url['title'] !== 'Guides Index') {
                // Guide titles should be Title Case (first letter of each word capitalized)
                $this->assertMatchesRegularExpression(
                    '/^[A-Z]/',
                    $url['title'],
                    "Guide title should start with capital letter: {$url['title']}"
                );
                // Should not contain hyphens (converted to spaces)
                $this->assertStringNotContainsString(
                    '-',
                    $url['title'],
                    "Guide title should not contain hyphens: {$url['title']}"
                );
            }
        }
    }
}
