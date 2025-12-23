<?php
/**
 * Integration Tests for Homepage and 404 Pages
 * 
 * Tests the homepage and 404 error pages to ensure:
 * - Pages load correctly
 * - Anchor links are present and valid
 * - Email CTA buttons are properly formatted
 * - No PHP errors in output
 * - HTML structure is valid
 */

use PHPUnit\Framework\TestCase;

class HomepageAnd404Test extends TestCase
{
    private $baseUrl;
    
    protected function setUp(): void
    {
        parent::setUp();
        
        $this->baseUrl = getenv('TEST_API_URL') ?: 'http://localhost:8080';
        
        if (!function_exists('curl_init')) {
            $this->markTestSkipped('cURL not available');
        }
    }
    
    /**
     * Test homepage is accessible and returns HTML
     */
    public function testHomepage_IsAccessible()
    {
        $response = $this->makeRequest('');
        
        if ($response['http_code'] == 0) {
            $this->markTestSkipped("Endpoint not available at {$this->baseUrl}");
            return;
        }
        
        $this->assertEquals(
            200,
            $response['http_code'],
            "Homepage should return 200 (got: {$response['http_code']})"
        );
        
        $html = $response['body'];
        $this->assertStringContainsString('<html', strtolower($html), "Homepage should contain HTML");
        $this->assertStringContainsString('AviationWX', $html, "Homepage should contain 'AviationWX'");
    }
    
    /**
     * Test homepage contains required anchor links
     */
    public function testHomepage_ContainsAnchorLinks()
    {
        $response = $this->makeRequest('');
        
        if ($response['http_code'] != 200) {
            $this->markTestSkipped("Homepage not available");
            return;
        }
        
        $html = $response['body'];
        
        // Check for required anchor IDs
        $requiredAnchors = [
            'participating-airports',
            'for-airport-owners',
            'about-the-project',
            'how-it-works'
        ];
        
        foreach ($requiredAnchors as $anchor) {
            $this->assertStringContainsString(
                "id=\"{$anchor}\"",
                $html,
                "Homepage should contain anchor ID: {$anchor}"
            );
        }
    }
    
    /**
     * Test homepage email CTA buttons are properly formatted
     */
    public function testHomepage_EmailCtaButtonsAreFormatted()
    {
        $response = $this->makeRequest('');
        
        if ($response['http_code'] != 200) {
            $this->markTestSkipped("Homepage not available");
            return;
        }
        
        $html = $response['body'];
        
        // Check for email CTA button in airport owners section
        $this->assertStringContainsString(
            'mailto:contact@aviationwx.org',
            $html,
            "Homepage should contain email CTA button for airport owners"
        );
        
        // Check that mailto links have subject parameter
        $this->assertMatchesRegularExpression(
            '/mailto:contact@aviationwx\.org\?subject=/',
            $html,
            "Email CTA should have subject parameter"
        );
    }
    
    /**
     * Test general 404 page is accessible
     */
    public function testGeneral404_IsAccessible()
    {
        $response = $this->makeRequest('nonexistent-page');
        
        if ($response['http_code'] == 0) {
            $this->markTestSkipped("Endpoint not available");
            return;
        }
        
        $this->assertEquals(
            404,
            $response['http_code'],
            "General 404 should return 404 status (got: {$response['http_code']})"
        );
        
        $html = $response['body'];
        $this->assertStringContainsString('404', $html, "404 page should contain '404'");
        $this->assertStringContainsString('Page Not Found', $html, "404 page should contain 'Page Not Found'");
    }
    
    /**
     * Test airport-specific 404 page is accessible
     */
    public function testAirport404_IsAccessible()
    {
        // Test with a non-existent airport code
        $response = $this->makeRequest('?airport=xxxx');
        
        if ($response['http_code'] == 0) {
            $this->markTestSkipped("Endpoint not available");
            return;
        }
        
        $this->assertEquals(
            404,
            $response['http_code'],
            "Airport 404 should return 404 status (got: {$response['http_code']})"
        );
        
        $html = $response['body'];
        $this->assertStringContainsString('404', $html, "Airport 404 page should contain '404'");
    }
    
    /**
     * Test airport 404 page contains email CTA
     */
    public function testAirport404_ContainsEmailCta()
    {
        $response = $this->makeRequest('?airport=xxxx');
        
        if ($response['http_code'] != 404) {
            $this->markTestSkipped("Airport 404 not available");
            return;
        }
        
        $html = $response['body'];
        
        // Check for email CTA buttons
        $this->assertStringContainsString(
            'mailto:',
            $html,
            "Airport 404 should contain email CTA buttons"
        );
    }
    
    /**
     * Test airport 404 page recognizes IATA codes and shows real airport message
     * 
     * When an IATA code like PDX is requested but the airport is not in the network,
     * it should recognize it as a valid airport and show the "real airport but not in network" message.
     */
    public function testAirport404_RecognizesIataCode()
    {
        // Test with PDX (Portland International Airport) - a real airport not in the network
        $response = $this->makeRequest('?airport=pdx');
        
        if ($response['http_code'] == 0) {
            $this->markTestSkipped("Endpoint not available");
            return;
        }
        
        // Should return 404 (airport not in network)
        $this->assertEquals(
            404,
            $response['http_code'],
            "Airport 404 should return 404 status for PDX (got: {$response['http_code']})"
        );
        
        $html = $response['body'];
        
        // Should recognize PDX as a real airport and show the "real airport but not in network" message
        // The page should contain "KPDX" (the ICAO code) or "isn't online yet" or similar message
        $this->assertStringContainsString(
            'isn\'t online yet',
            strtolower($html),
            "Airport 404 should recognize PDX as a real airport and show 'isn't online yet' message"
        );
        
        // Should contain the ICAO code (KPDX) in the response
        $this->assertStringContainsString(
            'KPDX',
            $html,
            "Airport 404 should display ICAO code KPDX for PDX IATA code"
        );
    }
    
    /**
     * Test airport 404 page recognizes FAA codes and shows real airport message
     */
    public function testAirport404_RecognizesFaaCode()
    {
        // Test with a real FAA code that's not in the network
        // Using PDX as FAA identifier (same as IATA for this airport)
        $response = $this->makeRequest('?airport=PDX');
        
        if ($response['http_code'] == 0) {
            $this->markTestSkipped("Endpoint not available");
            return;
        }
        
        // Should return 404 (airport not in network)
        $this->assertEquals(
            404,
            $response['http_code'],
            "Airport 404 should return 404 status for PDX FAA code (got: {$response['http_code']})"
        );
        
        $html = $response['body'];
        
        // Should recognize as a real airport
        $this->assertStringContainsString(
            'isn\'t online yet',
            strtolower($html),
            "Airport 404 should recognize PDX FAA code as a real airport"
        );
    }
    
    /**
     * Test airport 404 page handles invalid codes correctly
     */
    public function testAirport404_HandlesInvalidCode()
    {
        // Test with an invalid code
        $response = $this->makeRequest('?airport=INVALID123');
        
        if ($response['http_code'] == 0) {
            $this->markTestSkipped("Endpoint not available");
            return;
        }
        
        // Should return 404
        $this->assertEquals(
            404,
            $response['http_code'],
            "Airport 404 should return 404 status for invalid code (got: {$response['http_code']})"
        );
        
        $html = $response['body'];
        
        // Should show "doesn't appear to be a valid airport code" message
        // The message varies slightly based on whether it's a valid ICAO format
        $this->assertStringContainsString(
            'doesn\'t appear to be',
            strtolower($html),
            "Airport 404 should show invalid code message for invalid codes"
        );
        $this->assertStringContainsString(
            'valid',
            strtolower($html),
            "Airport 404 should mention validity for invalid codes"
        );
    }
    
    /**
     * Test homepage has no PHP errors
     */
    public function testHomepage_NoPhpErrors()
    {
        $response = $this->makeRequest('');
        
        if ($response['http_code'] != 200) {
            $this->markTestSkipped("Homepage not available");
            return;
        }
        
        $html = $response['body'];
        
        $phpErrorPatterns = [
            'Fatal error',
            'Parse error',
            'Warning:',
            'Notice:',
            'Call to undefined',
        ];
        
        foreach ($phpErrorPatterns as $pattern) {
            $this->assertStringNotContainsString(
                $pattern,
                $html,
                "Homepage should not contain PHP error: {$pattern}"
            );
        }
    }
    
    /**
     * Test 404 pages have no PHP errors
     */
    public function test404Pages_NoPhpErrors()
    {
        // Test general 404
        $response = $this->makeRequest('nonexistent-page');
        if ($response['http_code'] == 0) {
            $this->markTestSkipped("Endpoint not available");
            return;
        }
        
        // Always assert - even if not 404, should not have PHP errors
        $html = $response['body'] ?? '';
        $this->assertStringNotContainsString(
            'Fatal error',
            $html,
            "404 page should not contain PHP fatal errors"
        );
        $this->assertStringNotContainsString(
            'Parse error',
            $html,
            "404 page should not contain PHP parse errors"
        );
        
        if ($response['http_code'] == 404) {
            // Additional check for 404 page structure
            $this->assertNotEmpty($html, "404 page should have content");
        }
        
        // Test airport 404
        $response = $this->makeRequest('?airport=xxxx');
        if ($response['http_code'] == 0) {
            $this->markTestSkipped("Endpoint not available");
            return;
        }
        
        $html = $response['body'] ?? '';
        $this->assertStringNotContainsString(
            'Fatal error',
            $html,
            "Airport 404 page should not contain PHP fatal errors"
        );
        $this->assertStringNotContainsString(
            'Parse error',
            $html,
            "Airport 404 page should not contain PHP parse errors"
        );
        
        if ($response['http_code'] == 404) {
            // Additional check for 404 page structure
            $this->assertNotEmpty($html, "Airport 404 page should have content");
        }
    }
    
    /**
     * Test homepage HTML structure is valid
     */
    public function testHomepage_HtmlStructureIsValid()
    {
        $response = $this->makeRequest('');
        
        if ($response['http_code'] != 200) {
            $this->markTestSkipped("Homepage not available");
            return;
        }
        
        $html = $response['body'];
        
        // Check for basic HTML structure
        $this->assertStringContainsString('<html', strtolower($html), "Homepage should contain <html> tag");
        $this->assertStringContainsString('</html>', strtolower($html), "Homepage should contain </html> tag");
        $this->assertStringContainsString('<body', strtolower($html), "Homepage should contain <body> tag");
        $this->assertStringContainsString('</body>', strtolower($html), "Homepage should contain </body> tag");
        
        // Count opening and closing script tags
        preg_match_all('/<script[^>]*>/i', $html, $openingTags);
        preg_match_all('/<\/script>/i', $html, $closingTags);
        
        $this->assertEquals(
            count($openingTags[0]),
            count($closingTags[0]),
            "All script tags must be closed on homepage"
        );
    }
    
    /**
     * Helper method to make HTTP request
     */
    private function makeRequest(string $path): array
    {
        $url = rtrim($this->baseUrl, '/') . '/' . ltrim($path, '/');
        
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 10);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
        
        $body = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        
        return [
            'http_code' => $httpCode,
            'body' => $body ?: ''
        ];
    }
}

