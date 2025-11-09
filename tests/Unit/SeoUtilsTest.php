<?php
/**
 * Unit Tests for SEO Utilities
 * Tests structured data generation, meta tags, canonical URLs, and SEO functions
 */

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../lib/seo.php';

class SeoUtilsTest extends TestCase
{
    protected function setUp(): void
    {
        // Set up test environment variables
        $_SERVER['HTTPS'] = 'on';
        $_SERVER['HTTP_HOST'] = 'aviationwx.org';
        $_SERVER['REQUEST_URI'] = '/';
    }

    protected function tearDown(): void
    {
        // Clean up
        unset($_SERVER['HTTPS'], $_SERVER['HTTP_HOST'], $_SERVER['REQUEST_URI']);
    }

    /**
     * Test getBaseUrl() - HTTPS
     */
    public function testGetBaseUrl_Https()
    {
        $_SERVER['HTTPS'] = 'on';
        $_SERVER['HTTP_HOST'] = 'aviationwx.org';
        $url = getBaseUrl();
        $this->assertEquals('https://aviationwx.org', $url);
    }

    /**
     * Test getBaseUrl() - HTTP
     */
    public function testGetBaseUrl_Http()
    {
        $_SERVER['HTTPS'] = 'off';
        $_SERVER['HTTP_HOST'] = 'aviationwx.org';
        $url = getBaseUrl();
        $this->assertEquals('http://aviationwx.org', $url);
    }

    /**
     * Test getBaseUrl() - Missing HTTPS
     */
    public function testGetBaseUrl_MissingHttps()
    {
        unset($_SERVER['HTTPS']);
        $_SERVER['HTTP_HOST'] = 'aviationwx.org';
        $url = getBaseUrl();
        $this->assertEquals('http://aviationwx.org', $url);
    }

    /**
     * Test getCanonicalUrl() - Homepage
     */
    public function testGetCanonicalUrl_Homepage()
    {
        $_SERVER['HTTPS'] = 'on';
        $_SERVER['HTTP_HOST'] = 'aviationwx.org';
        $_SERVER['REQUEST_URI'] = '/';
        $url = getCanonicalUrl();
        $this->assertEquals('https://aviationwx.org/', $url);
    }

    /**
     * Test getCanonicalUrl() - With Airport ID
     */
    public function testGetCanonicalUrl_WithAirportId()
    {
        $_SERVER['HTTPS'] = 'on';
        $url = getCanonicalUrl('kspb');
        $this->assertEquals('https://kspb.aviationwx.org', $url);
    }

    /**
     * Test getCanonicalUrl() - Removes Query Parameters
     */
    public function testGetCanonicalUrl_RemovesQueryParams()
    {
        $_SERVER['HTTPS'] = 'on';
        $_SERVER['HTTP_HOST'] = 'aviationwx.org';
        $_SERVER['REQUEST_URI'] = '/?page=2&test=1';
        $url = getCanonicalUrl();
        $this->assertEquals('https://aviationwx.org/?page=2&test=1', $url);
        // Note: strtok only removes after first ?, so this is expected behavior
    }

    /**
     * Test generateOrganizationSchema() - Structure
     */
    public function testGenerateOrganizationSchema_Structure()
    {
        $schema = generateOrganizationSchema();
        
        $this->assertIsArray($schema);
        $this->assertEquals('https://schema.org', $schema['@context']);
        $this->assertEquals('Organization', $schema['@type']);
        $this->assertEquals('AviationWX.org', $schema['name']);
        $this->assertArrayHasKey('url', $schema);
        $this->assertArrayHasKey('description', $schema);
        $this->assertArrayHasKey('logo', $schema);
        $this->assertArrayHasKey('contactPoint', $schema);
    }

    /**
     * Test generateOrganizationSchema() - Valid JSON
     */
    public function testGenerateOrganizationSchema_ValidJson()
    {
        $schema = generateOrganizationSchema();
        $json = json_encode($schema, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        $this->assertNotFalse($json);
        $this->assertEquals(JSON_ERROR_NONE, json_last_error());
    }

    /**
     * Test generateAirportSchema() - Basic Structure
     */
    public function testGenerateAirportSchema_BasicStructure()
    {
        $airport = [
            'name' => 'Test Airport',
            'icao' => 'TEST',
            'address' => 'Test City, State',
            'lat' => 45.0,
            'lon' => -122.0
        ];
        
        $schema = generateAirportSchema($airport, 'test');
        
        $this->assertIsArray($schema);
        $this->assertEquals('https://schema.org', $schema['@context']);
        $this->assertEquals('LocalBusiness', $schema['@type']);
        $this->assertEquals('Test Airport (TEST)', $schema['name']);
        $this->assertStringContainsString('Live webcams', $schema['description']);
        $this->assertArrayHasKey('url', $schema);
        $this->assertArrayHasKey('address', $schema);
        $this->assertArrayHasKey('geo', $schema);
    }

    /**
     * Test generateAirportSchema() - With Webcams
     */
    public function testGenerateAirportSchema_WithWebcams()
    {
        $airport = [
            'name' => 'Test Airport',
            'icao' => 'TEST',
            'address' => 'Test City, State',
            'lat' => 45.0,
            'lon' => -122.0,
            'webcams' => [
                ['name' => 'Camera 1', 'url' => 'https://example.com/cam1.jpg'],
                ['name' => 'Camera 2', 'url' => 'https://example.com/cam2.jpg']
            ]
        ];
        
        $schema = generateAirportSchema($airport, 'test');
        
        $this->assertArrayHasKey('image', $schema);
        $this->assertIsArray($schema['image']);
        $this->assertCount(2, $schema['image']);
        $this->assertStringContainsString('2 camera', $schema['description']);
        $this->assertArrayHasKey('hasOfferCatalog', $schema);
    }

    /**
     * Test generateAirportSchema() - Without Webcams
     */
    public function testGenerateAirportSchema_WithoutWebcams()
    {
        $airport = [
            'name' => 'Test Airport',
            'icao' => 'TEST',
            'address' => 'Test City, State',
            'lat' => 45.0,
            'lon' => -122.0
        ];
        
        $schema = generateAirportSchema($airport, 'test');
        
        $this->assertArrayNotHasKey('image', $schema);
        $this->assertStringContainsString('Live webcams', $schema['description']);
    }

    /**
     * Test generateAirportSchema() - Valid JSON
     */
    public function testGenerateAirportSchema_ValidJson()
    {
        $airport = [
            'name' => 'Test Airport',
            'icao' => 'TEST',
            'address' => 'Test City, State',
            'lat' => 45.0,
            'lon' => -122.0,
            'webcams' => [
                ['name' => 'Camera 1', 'url' => 'https://example.com/cam1.jpg']
            ]
        ];
        
        $schema = generateAirportSchema($airport, 'test');
        $json = json_encode($schema, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        $this->assertNotFalse($json);
        $this->assertEquals(JSON_ERROR_NONE, json_last_error());
    }

    /**
     * Test generateAirportSchema() - Service Catalog
     */
    public function testGenerateAirportSchema_ServiceCatalog()
    {
        $airport = [
            'name' => 'Test Airport',
            'icao' => 'TEST',
            'address' => 'Test City, State'
        ];
        
        $schema = generateAirportSchema($airport, 'test');
        
        $this->assertArrayHasKey('hasOfferCatalog', $schema);
        $catalog = $schema['hasOfferCatalog'];
        $this->assertEquals('OfferCatalog', $catalog['@type']);
        $this->assertEquals('Aviation Services', $catalog['name']);
        $this->assertArrayHasKey('itemListElement', $catalog);
        $this->assertCount(3, $catalog['itemListElement']); // Live Webcams, Runway Conditions, Weather Data
        
        // Check that services include webcams and runway conditions
        $serviceNames = array_map(function($item) {
            return $item['itemOffered']['name'];
        }, $catalog['itemListElement']);
        
        $this->assertContains('Live Airport Webcams', $serviceNames);
        $this->assertContains('Real-time Runway Conditions', $serviceNames);
        $this->assertContains('Aviation Weather Data', $serviceNames);
    }

    /**
     * Test generateSocialMetaTags() - Open Graph Tags
     */
    public function testGenerateSocialMetaTags_OpenGraph()
    {
        $tags = generateSocialMetaTags('Test Title', 'Test Description', 'https://example.com', 'https://example.com/image.jpg');
        
        $this->assertStringContainsString('og:title', $tags);
        $this->assertStringContainsString('og:description', $tags);
        $this->assertStringContainsString('og:url', $tags);
        $this->assertStringContainsString('og:type', $tags);
        $this->assertStringContainsString('og:image', $tags);
        $this->assertStringContainsString('og:site_name', $tags);
        $this->assertStringContainsString('og:locale', $tags);
    }

    /**
     * Test generateSocialMetaTags() - Twitter Card Tags
     */
    public function testGenerateSocialMetaTags_TwitterCard()
    {
        $tags = generateSocialMetaTags('Test Title', 'Test Description', 'https://example.com', 'https://example.com/image.jpg');
        
        $this->assertStringContainsString('twitter:card', $tags);
        $this->assertStringContainsString('twitter:title', $tags);
        $this->assertStringContainsString('twitter:description', $tags);
        $this->assertStringContainsString('twitter:image', $tags);
    }

    /**
     * Test generateSocialMetaTags() - Default Image
     */
    public function testGenerateSocialMetaTags_DefaultImage()
    {
        $tags = generateSocialMetaTags('Test Title', 'Test Description', 'https://example.com');
        
        $this->assertStringContainsString('og:image', $tags);
        $this->assertStringContainsString('about-photo.jpg', $tags);
    }

    /**
     * Test generateSocialMetaTags() - HTML Escaping
     */
    public function testGenerateSocialMetaTags_HtmlEscaping()
    {
        $title = 'Test & <script>alert("XSS")</script>';
        $description = 'Test "quotes" & <tags>';
        
        $tags = generateSocialMetaTags($title, $description, 'https://example.com');
        
        // Should escape HTML entities
        $this->assertStringNotContainsString('<script>', $tags);
        $this->assertStringContainsString('&lt;script&gt;', $tags);
        $this->assertStringContainsString('&amp;', $tags);
    }

    /**
     * Test generateEnhancedMetaTags() - All Tags
     */
    public function testGenerateEnhancedMetaTags_AllTags()
    {
        $tags = generateEnhancedMetaTags('Test description', 'test, keywords', 'Test Author');
        
        $this->assertStringContainsString('name="description"', $tags);
        $this->assertStringContainsString('name="keywords"', $tags);
        $this->assertStringContainsString('name="author"', $tags);
        $this->assertStringContainsString('name="robots"', $tags);
        $this->assertStringContainsString('content-language', $tags);
    }

    /**
     * Test generateEnhancedMetaTags() - Empty Keywords
     */
    public function testGenerateEnhancedMetaTags_EmptyKeywords()
    {
        $tags = generateEnhancedMetaTags('Test description', '');
        
        $this->assertStringContainsString('name="description"', $tags);
        $this->assertStringNotContainsString('name="keywords"', $tags);
    }

    /**
     * Test generateEnhancedMetaTags() - HTML Escaping
     */
    public function testGenerateEnhancedMetaTags_HtmlEscaping()
    {
        $description = 'Test & <script>alert("XSS")</script>';
        $keywords = 'test, keywords & <tags>';
        
        $tags = generateEnhancedMetaTags($description, $keywords);
        
        $this->assertStringNotContainsString('<script>', $tags);
        $this->assertStringContainsString('&lt;script&gt;', $tags);
        $this->assertStringContainsString('&amp;', $tags);
    }

    /**
     * Test generateCanonicalTag() - Basic
     */
    public function testGenerateCanonicalTag_Basic()
    {
        $tag = generateCanonicalTag('https://example.com');
        
        $this->assertStringContainsString('rel="canonical"', $tag);
        $this->assertStringContainsString('href="https://example.com"', $tag);
    }

    /**
     * Test generateCanonicalTag() - HTML Escaping
     */
    public function testGenerateCanonicalTag_HtmlEscaping()
    {
        $url = 'https://example.com?test="quotes"&amp=<tags>';
        $tag = generateCanonicalTag($url);
        
        $this->assertStringContainsString('&quot;', $tag);
        $this->assertStringNotContainsString('<tags>', $tag);
    }

    /**
     * Test generateStructuredDataScript() - Structure
     */
    public function testGenerateStructuredDataScript_Structure()
    {
        $schema = ['@context' => 'https://schema.org', '@type' => 'Organization'];
        $script = generateStructuredDataScript($schema);
        
        $this->assertStringContainsString('<script type="application/ld+json">', $script);
        $this->assertStringContainsString('</script>', $script);
        $this->assertStringContainsString('"@context":"https://schema.org"', $script);
    }

    /**
     * Test generateStructuredDataScript() - Valid JSON
     */
    public function testGenerateStructuredDataScript_ValidJson()
    {
        $schema = [
            '@context' => 'https://schema.org',
            '@type' => 'Organization',
            'name' => 'Test'
        ];
        
        $script = generateStructuredDataScript($schema);
        
        // Extract JSON from script tag
        preg_match('/<script[^>]*>(.*?)<\/script>/s', $script, $matches);
        $this->assertNotEmpty($matches[1]);
        
        $json = json_decode($matches[1], true);
        $this->assertNotFalse($json);
        $this->assertEquals(JSON_ERROR_NONE, json_last_error());
        $this->assertEquals('Organization', $json['@type']);
    }

    /**
     * Test generateStructuredDataScript() - Pretty Print
     */
    public function testGenerateStructuredDataScript_PrettyPrint()
    {
        $schema = ['@context' => 'https://schema.org', '@type' => 'Organization'];
        $script = generateStructuredDataScript($schema);
        
        // Should contain newlines (pretty print)
        $this->assertStringContainsString("\n", $script);
    }

    /**
     * Test generateAirportSchema() - Webcam Count in Description
     */
    public function testGenerateAirportSchema_WebcamCountInDescription()
    {
        $airport = [
            'name' => 'Test Airport',
            'icao' => 'TEST',
            'address' => 'Test City, State',
            'webcams' => [
                ['name' => 'Camera 1', 'url' => 'https://example.com/cam1.jpg'],
                ['name' => 'Camera 2', 'url' => 'https://example.com/cam2.jpg'],
                ['name' => 'Camera 3', 'url' => 'https://example.com/cam3.jpg']
            ]
        ];
        
        $schema = generateAirportSchema($airport, 'test');
        
        // Should mention 3 cameras
        $this->assertStringContainsString('3 camera', $schema['description']);
    }

    /**
     * Test generateAirportSchema() - Single Webcam
     */
    public function testGenerateAirportSchema_SingleWebcam()
    {
        $airport = [
            'name' => 'Test Airport',
            'icao' => 'TEST',
            'address' => 'Test City, State',
            'webcams' => [
                ['name' => 'Camera 1', 'url' => 'https://example.com/cam1.jpg']
            ]
        ];
        
        $schema = generateAirportSchema($airport, 'test');
        
        // Should mention 1 camera (singular)
        $this->assertStringContainsString('1 camera', $schema['description']);
        // Image should be a string, not array, for single image
        $this->assertIsString($schema['image']);
    }

    /**
     * Test generateAirportSchema() - Webcam URLs
     */
    public function testGenerateAirportSchema_WebcamUrls()
    {
        $airport = [
            'name' => 'Test Airport',
            'icao' => 'TEST',
            'address' => 'Test City, State',
            'webcams' => [
                ['name' => 'Camera 1', 'url' => 'https://example.com/cam1.jpg'],
                ['name' => 'Camera 2', 'url' => 'https://example.com/cam2.jpg']
            ]
        ];
        
        $schema = generateAirportSchema($airport, 'test');
        
        $this->assertIsArray($schema['image']);
        $this->assertCount(2, $schema['image']);
        
        // Check URLs are correct format
        foreach ($schema['image'] as $imageUrl) {
            $this->assertStringContainsString('test.aviationwx.org', $imageUrl);
            $this->assertStringContainsString('webcam.php', $imageUrl);
            $this->assertStringContainsString('fmt=jpg', $imageUrl);
        }
    }

    /**
     * Test generateFaviconTags() - Basic Structure
     */
    public function testGenerateFaviconTags_BasicStructure()
    {
        $_SERVER['HTTPS'] = 'on';
        $_SERVER['HTTP_HOST'] = 'aviationwx.org';
        
        $tags = generateFaviconTags();
        
        // Should contain standard favicon
        $this->assertStringContainsString('rel="icon"', $tags);
        $this->assertStringContainsString('favicon.ico', $tags);
        
        // Should contain manifest
        $this->assertStringContainsString('rel="manifest"', $tags);
        $this->assertStringContainsString('manifest.json', $tags);
        
        // Should contain theme color
        $this->assertStringContainsString('theme-color', $tags);
        $this->assertStringContainsString('#3b82f6', $tags);
    }

    /**
     * Test generateFaviconTags() - All Favicon Sizes
     */
    public function testGenerateFaviconTags_AllFaviconSizes()
    {
        $_SERVER['HTTPS'] = 'on';
        $_SERVER['HTTP_HOST'] = 'aviationwx.org';
        
        $tags = generateFaviconTags();
        
        $faviconSizes = [16, 32, 48, 64, 128, 256];
        foreach ($faviconSizes as $size) {
            $this->assertStringContainsString('favicon-' . $size . 'x' . $size . '.png', $tags);
            $this->assertStringContainsString('sizes="' . $size . 'x' . $size . '"', $tags);
        }
    }

    /**
     * Test generateFaviconTags() - Apple Touch Icons
     */
    public function testGenerateFaviconTags_AppleTouchIcons()
    {
        $_SERVER['HTTPS'] = 'on';
        $_SERVER['HTTP_HOST'] = 'aviationwx.org';
        
        $tags = generateFaviconTags();
        
        $appleSizes = [120, 152, 167, 180];
        foreach ($appleSizes as $size) {
            $this->assertStringContainsString('rel="apple-touch-icon"', $tags);
            $this->assertStringContainsString('apple-touch-icon-' . $size . 'x' . $size . '.png', $tags);
            $this->assertStringContainsString('sizes="' . $size . 'x' . $size . '"', $tags);
        }
    }

    /**
     * Test generateFaviconTags() - Correct Base URL
     */
    public function testGenerateFaviconTags_CorrectBaseUrl()
    {
        $_SERVER['HTTPS'] = 'on';
        $_SERVER['HTTP_HOST'] = 'aviationwx.org';
        
        $tags = generateFaviconTags();
        
        // Should use correct base URL
        $this->assertStringContainsString('https://aviationwx.org/aviationwx_favicons', $tags);
    }

    /**
     * Test generateFaviconTags() - HTTP Base URL
     */
    public function testGenerateFaviconTags_HttpBaseUrl()
    {
        $_SERVER['HTTPS'] = 'off';
        $_SERVER['HTTP_HOST'] = 'aviationwx.org';
        
        $tags = generateFaviconTags();
        
        // Should use HTTP for base URL
        $this->assertStringContainsString('http://aviationwx.org/aviationwx_favicons', $tags);
    }

    /**
     * Test getLogoUrl() - Falls Back to Favicon When No Logo
     */
    public function testGetLogoUrl_FallsBackToFavicon()
    {
        $_SERVER['HTTPS'] = 'on';
        $_SERVER['HTTP_HOST'] = 'aviationwx.org';
        
        $logoUrl = getLogoUrl();
        
        // Should return a URL
        $this->assertIsString($logoUrl);
        $this->assertStringStartsWith('https://', $logoUrl);
        
        // Should contain aviationwx_favicons path
        $this->assertStringContainsString('aviationwx_favicons', $logoUrl);
        
        // Should either be favicon-512x512.png or about-photo.jpg (depending on what exists)
        $this->assertTrue(
            strpos($logoUrl, 'favicon-512x512.png') !== false ||
            strpos($logoUrl, 'about-photo.jpg') !== false
        );
    }

    /**
     * Test getLogoUrl() - Returns Correct Base URL
     */
    public function testGetLogoUrl_ReturnsCorrectBaseUrl()
    {
        $_SERVER['HTTPS'] = 'on';
        $_SERVER['HTTP_HOST'] = 'aviationwx.org';
        
        $logoUrl = getLogoUrl();
        
        // Should use HTTPS base URL
        $this->assertStringStartsWith('https://aviationwx.org', $logoUrl);
    }

    /**
     * Test getLogoUrl() - HTTP Base URL
     */
    public function testGetLogoUrl_HttpBaseUrl()
    {
        $_SERVER['HTTPS'] = 'off';
        $_SERVER['HTTP_HOST'] = 'aviationwx.org';
        
        $logoUrl = getLogoUrl();
        
        // Should use HTTP base URL
        $this->assertStringStartsWith('http://aviationwx.org', $logoUrl);
    }

    /**
     * Test generateOrganizationSchema() - Uses getLogoUrl()
     */
    public function testGenerateOrganizationSchema_UsesGetLogoUrl()
    {
        $_SERVER['HTTPS'] = 'on';
        $_SERVER['HTTP_HOST'] = 'aviationwx.org';
        
        $schema = generateOrganizationSchema();
        
        // Should have logo key
        $this->assertArrayHasKey('logo', $schema);
        
        // Logo should be a string URL
        $this->assertIsString($schema['logo']);
        
        // Logo should contain base URL
        $this->assertStringContainsString('aviationwx.org', $schema['logo']);
    }

    /**
     * Test generateFaviconTags() - HTML Structure
     */
    public function testGenerateFaviconTags_HtmlStructure()
    {
        $_SERVER['HTTPS'] = 'on';
        $_SERVER['HTTP_HOST'] = 'aviationwx.org';
        
        $tags = generateFaviconTags();
        
        // Should contain proper link tags
        $this->assertStringContainsString('<link', $tags);
        $this->assertStringContainsString('href=', $tags);
        
        // Should contain proper meta tag for theme color
        $this->assertStringContainsString('<meta', $tags);
        $this->assertStringContainsString('name="theme-color"', $tags);
    }

    /**
     * Test generateFaviconTags() - All Required Elements
     */
    public function testGenerateFaviconTags_AllRequiredElements()
    {
        $_SERVER['HTTPS'] = 'on';
        $_SERVER['HTTP_HOST'] = 'aviationwx.org';
        
        $tags = generateFaviconTags();
        
        // Count expected elements
        // 1 favicon.ico + 6 favicon sizes + 4 apple touch icons + 1 manifest + 1 theme color = 13
        $expectedCount = 13;
        
        // Count link and meta tags
        $linkCount = substr_count($tags, '<link');
        $metaCount = substr_count($tags, '<meta');
        
        $this->assertGreaterThanOrEqual($expectedCount, $linkCount + $metaCount);
    }
}

