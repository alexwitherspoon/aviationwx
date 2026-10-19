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

    /**
     * Test airport webcams produce image sitemap entries
     */
    public function testGetSitemapUrls_AirportWebcamsProduceImageEntries(): void
    {
        $urls = getSitemapUrls();

        $airportsWithImages = 0;
        foreach ($urls['airports'] as $url) {
            if (!isset($url['images'])) {
                continue;
            }
            $airportsWithImages++;
            $this->assertIsArray($url['images']);
            $this->assertNotEmpty($url['images']);
            foreach ($url['images'] as $image) {
                $this->assertArrayHasKey('loc', $image);
                $this->assertArrayHasKey('title', $image);
                $this->assertArrayHasKey('caption', $image);
                $this->assertStringStartsWith('https://', $image['loc']);
                $this->assertNotEmpty($image['title']);
                $this->assertNotEmpty($image['caption']);
                $this->assertStringContainsString($image['title'], $image['caption']);
            }
        }

        $this->assertGreaterThan(
            0,
            $airportsWithImages,
            'At least one listed airport should carry webcam image entries'
        );
    }

    /**
     * Test webcam image URLs point at the Public API host, which Google can crawl.
     *
     * Airport hosts block /webcam.php?* in robots.txt, so the sitemap must not
     * advertise those URLs. The Public API host serves images with no robots
     * restriction, so its canonical image URLs are the crawlable target.
     */
    public function testGetSitemapUrls_WebcamImageUrlsUsePublicApiHost(): void
    {
        $urls = getSitemapUrls();

        foreach ($urls['airports'] as $url) {
            if (!isset($url['images'])) {
                continue;
            }
            foreach ($url['images'] as $image) {
                $this->assertMatchesRegularExpression(
                    '#^https://api\.aviationwx\.org/v1/airports/[a-z0-9-]+/webcams/\d+/image$#',
                    $image['loc'],
                    "Webcam image URL should use the Public API host: {$image['loc']}"
                );
            }
        }
    }

    /**
     * Test disabled webcam slots are skipped when building image entries
     */
    public function testBuildSitemapWebcamImages_AllDisabledYieldsNoImages(): void
    {
        $airport = [
            'name' => 'Test Airport',
            'webcams' => [
                ['name' => 'Cam A', 'enabled' => false],
                ['name' => 'Cam B', 'enabled' => false],
            ],
        ];

        $this->assertSame(
            [],
            buildSitemapWebcamImages($airport, 'ktst', 'Test Airport')
        );
    }

    /**
     * Test a disabled slot between two enabled cameras is skipped and indexes are preserved
     */
    public function testBuildSitemapWebcamImages_SkipsDisabledSlotBetweenEnabled(): void
    {
        $airport = [
            'name' => 'Test Airport',
            'webcams' => [
                0 => ['name' => 'North Camera'],
                1 => ['name' => 'South Camera', 'enabled' => false],
                2 => ['name' => 'Tower Camera'],
            ],
        ];

        $images = buildSitemapWebcamImages($airport, 'ktst', 'Test Airport');

        $this->assertCount(2, $images);
        $this->assertStringEndsWith('/airports/ktst/webcams/0/image', $images[0]['loc']);
        $this->assertStringEndsWith('/airports/ktst/webcams/2/image', $images[1]['loc']);
        $this->assertSame('North Camera', $images[0]['title']);
        $this->assertSame('Tower Camera', $images[1]['title']);
    }

    /**
     * Test image entries are omitted when the Public API is disabled, while airport pages remain
     */
    public function testGetSitemapUrls_OmitsImagesWhenPublicApiDisabled(): void
    {
        $fixture = __DIR__ . '/../Fixtures/airports.json.test';
        $config = json_decode((string) file_get_contents($fixture), true);
        $this->assertIsArray($config, 'Fixture config should parse');
        $config['config']['public_api']['enabled'] = false;

        $tempDir = sys_get_temp_dir() . '/aviationwx_sitemap_' . uniqid();
        @mkdir($tempDir, 0755, true);
        $tempFile = $tempDir . '/airports.json';
        file_put_contents($tempFile, json_encode($config, JSON_UNESCAPED_SLASHES));

        $originalPath = getenv('CONFIG_PATH');
        putenv('CONFIG_PATH=' . $tempFile);
        clearConfigCache();

        try {
            $urls = getSitemapUrls();

            $this->assertNotEmpty($urls['airports'], 'Airport pages should remain when the API is disabled');
            foreach ($urls['airports'] as $url) {
                $this->assertArrayNotHasKey(
                    'images',
                    $url,
                    'Image entries should be omitted when the Public API is disabled'
                );
            }
        } finally {
            putenv($originalPath !== false ? 'CONFIG_PATH=' . $originalPath : 'CONFIG_PATH');
            clearConfigCache();
            @unlink($tempFile);
            @rmdir($tempDir);
        }
    }

    /**
     * Test the image XML renderer escapes XML-special characters
     */
    public function testRenderSitemapImageXml_EscapesXmlSpecialChars(): void
    {
        $xml = renderSitemapImageXml([
            'loc' => 'https://api.aviationwx.org/v1/airports/ktst/webcams/0/image',
            'title' => 'North<Tower & "Gate">',
            'caption' => 'Live North<Tower & "Gate"> webcam',
        ]);

        $this->assertStringContainsString('<image:image>', $xml);
        $this->assertStringContainsString('<image:loc>', $xml);
        $this->assertStringContainsString('&lt;', $xml);
        $this->assertStringContainsString('&gt;', $xml);
        $this->assertStringContainsString('&amp;', $xml);
        $this->assertStringContainsString('&quot;', $xml);
        $this->assertStringNotContainsString('<Tower', $xml, 'Special characters should be entity-encoded');
    }

    /**
     * Test non-airport entries never carry images
     */
    public function testGetSitemapUrls_NonAirportEntriesHaveNoImages(): void
    {
        $urls = getSitemapUrls();

        foreach (['main', 'guides', 'tools', 'legal'] as $category) {
            foreach ($urls[$category] as $url) {
                $this->assertArrayNotHasKey(
                    'images',
                    $url,
                    "Non-airport entry in {$category} should not carry images"
                );
            }
        }
    }

    /**
     * Test webcam names are cleaned of their trailing stream-type annotation
     */
    public function testCleanWebcamName_StripsTrailingAnnotations(): void
    {
        $this->assertSame('North Runway Camera', cleanWebcamName('North Runway Camera (MJPEG)'));
        $this->assertSame('West Camera', cleanWebcamName('West Camera (Static JPEG)'));
        $this->assertSame('West Camera', cleanWebcamName('West Camera (Static PNG)'));
        $this->assertSame('Tower Camera', cleanWebcamName('Tower Camera (RTSP)'));
        $this->assertSame('Tower Camera', cleanWebcamName('Tower Camera (RTSP Auth)'));
    }

    /**
     * Test meaningful trailing qualifiers are preserved, not stripped
     */
    public function testCleanWebcamName_PreservesMeaningfulQualifiers(): void
    {
        $this->assertSame('Camera (North)', cleanWebcamName('Camera (North)'));
        $this->assertSame('Camera (South)', cleanWebcamName('Camera (South)'));
        $this->assertSame('Tower Camera (PGA)', cleanWebcamName('Tower Camera (PGA)'));
    }

    /**
     * Test plain names without an annotation pass through unchanged
     */
    public function testCleanWebcamName_LeavesPlainNamesUnchanged(): void
    {
        $this->assertSame('Test Camera 1', cleanWebcamName('Test Camera 1'));
        $this->assertSame('WSDOT Test Camera', cleanWebcamName('WSDOT Test Camera'));
    }

    /**
     * Test only a trailing annotation is stripped, never a mid-string parenthetical
     */
    public function testCleanWebcamName_OnlyTouchesTrailingAnnotation(): void
    {
        $this->assertSame('Camera (North) view', cleanWebcamName('Camera (North) view'));
    }

    /**
     * Test whitespace adjacent to the annotation is stripped cleanly
     */
    public function testCleanWebcamName_HandlesAdjacentWhitespace(): void
    {
        $this->assertSame('West Camera', cleanWebcamName('West Camera(Static JPEG)'));
        $this->assertSame('West Camera', cleanWebcamName('West Camera (Static JPEG) '));
    }

    /**
     * Test a name that is only an annotation never becomes an empty string
     */
    public function testCleanWebcamName_NeverReturnsEmpty(): void
    {
        $this->assertSame('(MJPEG)', cleanWebcamName('(MJPEG)'));
    }
}
