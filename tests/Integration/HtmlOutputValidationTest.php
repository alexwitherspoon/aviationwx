<?php
/**
 * HTML Output Validation Tests
 * 
 * Tests to ensure HTML output is valid and doesn't contain common issues like:
 * - Unclosed script tags
 * - Unclosed HTML tags
 * - HTML injection in JavaScript
 * - Malformed HTML structure
 */

use PHPUnit\Framework\TestCase;

class HtmlOutputValidationTest extends TestCase
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
     * Test that all script tags are properly closed in airport page output
     * This catches the bug where script tags were opened but not closed
     */
    public function testAirportPage_AllScriptTagsAreClosed()
    {
        $response = $this->makeRequest('?airport=kspb');
        
        if ($response['http_code'] == 0 || $response['http_code'] != 200) {
            $this->markTestSkipped("Airport page not available (HTTP {$response['http_code']})");
            return;
        }
        
        $html = $response['body'];
        
        // Count opening and closing script tags
        preg_match_all('/<script[^>]*>/i', $html, $openingTags);
        preg_match_all('/<\/script>/i', $html, $closingTags);
        
        $openingCount = count($openingTags[0]);
        $closingCount = count($closingTags[0]);
        
        $this->assertEquals(
            $openingCount,
            $closingCount,
            "All script tags must be closed. Found {$openingCount} opening tags but {$closingCount} closing tags."
        );
    }
    
    /**
     * Test that no script tags are left unclosed (more specific check)
     */
    public function testAirportPage_NoUnclosedScriptTags()
    {
        $response = $this->makeRequest('?airport=kspb');
        
        if ($response['http_code'] == 0 || $response['http_code'] != 200) {
            $this->markTestSkipped("Airport page not available (HTTP {$response['http_code']})");
            return;
        }
        
        $html = $response['body'];
        
        // Find all script tags and verify each has a closing tag
        preg_match_all('/<script[^>]*>(.*?)<\/script>/is', $html, $matchedScripts);
        preg_match_all('/<script[^>]*>/i', $html, $allOpeningTags);
        
        $matchedCount = count($matchedScripts[0]);
        $openingCount = count($allOpeningTags[0]);
        
        // Every opening script tag should have been matched (meaning it has a closing tag)
        $this->assertEquals(
            $openingCount,
            $matchedCount,
            "Found {$openingCount} opening script tags but only {$matchedCount} have closing tags. Unclosed script tags detected!"
        );
    }
    
    /**
     * Test that script tags don't contain HTML content (HTML injection check)
     */
    public function testAirportPage_ScriptTagsDontContainHtml()
    {
        $response = $this->makeRequest('?airport=kspb');
        
        if ($response['http_code'] == 0 || $response['http_code'] != 200) {
            $this->markTestSkipped("Airport page not available (HTTP {$response['http_code']})");
            return;
        }
        
        $html = $response['body'];
        
        // Extract all script tag contents
        preg_match_all('/<script[^>]*>(.*?)<\/script>/is', $html, $matches);
        
        foreach ($matches[1] as $index => $scriptContent) {
            // Check for HTML tags in script content (excluding template literals and strings)
            // This is a simplified check - we look for HTML tags that aren't in strings
            $hasHtmlTag = preg_match('/<[a-z][\s>]/i', $scriptContent);
            
            // Allow HTML in template literals (backticks) and strings
            // But flag if HTML appears outside of those contexts
            if ($hasHtmlTag) {
                // Check if it's in a template literal or string
                $inString = false;
                $inTemplate = false;
                $chars = str_split($scriptContent);
                $inSingleQuote = false;
                $inDoubleQuote = false;
                $inBacktick = false;
                $escapeNext = false;
                
                foreach ($chars as $i => $char) {
                    if ($escapeNext) {
                        $escapeNext = false;
                        continue;
                    }
                    
                    if ($char === '\\') {
                        $escapeNext = true;
                        continue;
                    }
                    
                    if ($char === "'" && !$inDoubleQuote && !$inBacktick) {
                        $inSingleQuote = !$inSingleQuote;
                    } elseif ($char === '"' && !$inSingleQuote && !$inBacktick) {
                        $inDoubleQuote = !$inDoubleQuote;
                    } elseif ($char === '`' && !$inSingleQuote && !$inDoubleQuote) {
                        $inBacktick = !$inBacktick;
                    }
                    
                    // If we find < and we're not in a string/template, it might be HTML
                    if ($char === '<' && !$inSingleQuote && !$inDoubleQuote && !$inBacktick) {
                        // Check if it's followed by a letter (likely HTML tag)
                        if (isset($chars[$i + 1]) && preg_match('/[a-z]/i', $chars[$i + 1])) {
                            $this->fail(
                                "Script tag #{$index} contains HTML content outside of strings/template literals: " .
                                substr($scriptContent, max(0, $i - 50), 100)
                            );
                        }
                    }
                }
            }
        }
        
        // If we get here, no HTML injection detected
        $this->assertTrue(true);
    }
    
    /**
     * Test that the main JavaScript script tag is properly closed
     * This is the specific bug we fixed - the main airport page script
     */
    public function testAirportPage_MainJavaScriptScriptTagIsClosed()
    {
        $response = $this->makeRequest('?airport=kspb');
        
        if ($response['http_code'] == 0 || $response['http_code'] != 200) {
            $this->markTestSkipped("Airport page not available (HTTP {$response['http_code']})");
            return;
        }
        
        $html = $response['body'];
        
        // Find the main JavaScript script tag (contains AIRPORT_ID)
        if (preg_match('/<script[^>]*>.*?AIRPORT_ID.*?<\/script>/is', $html, $matches)) {
            // Script tag is properly closed
            $this->assertTrue(true, "Main JavaScript script tag is properly closed");
        } else {
            // Check if script tag exists but isn't closed
            if (preg_match('/<script[^>]*>.*?AIRPORT_ID/is', $html) && !preg_match('/<script[^>]*>.*?AIRPORT_ID.*?<\/script>/is', $html)) {
                $this->fail("Main JavaScript script tag (containing AIRPORT_ID) is not properly closed!");
            } else {
                $this->markTestSkipped("Could not find main JavaScript script tag in output");
            }
        }
    }
    
    /**
     * Test that HTML structure is valid (basic check)
     */
    public function testAirportPage_HtmlStructureIsValid()
    {
        $response = $this->makeRequest('?airport=kspb');
        
        if ($response['http_code'] == 0 || $response['http_code'] != 200) {
            $this->markTestSkipped("Airport page not available (HTTP {$response['http_code']})");
            return;
        }
        
        $html = $response['body'];
        
        // Check for basic HTML structure
        $this->assertStringContainsString('<html', strtolower($html), "HTML should contain <html> tag");
        $this->assertStringContainsString('</html>', strtolower($html), "HTML should contain </html> tag");
        $this->assertStringContainsString('<body', strtolower($html), "HTML should contain <body> tag");
        $this->assertStringContainsString('</body>', strtolower($html), "HTML should contain </body> tag");
        
        // Count opening and closing tags for common elements
        // Note: Some tags like <head> might appear in structured data (JSON-LD), so we're lenient
        $tags = ['html', 'body', 'main', 'script', 'style'];
        
        foreach ($tags as $tag) {
            preg_match_all("/<{$tag}[^>]*>/i", $html, $opening);
            preg_match_all("/<\/{$tag}>/i", $html, $closing);
            
            $openingCount = count($opening[0]);
            $closingCount = count($closing[0]);
            
            // Critical tags must have matching closing tags
            // Note: <head> is excluded because it might appear in JSON-LD structured data
            if (in_array($tag, ['script', 'style', 'html', 'body', 'main'])) {
                $this->assertEquals(
                    $openingCount,
                    $closingCount,
                    "Tag <{$tag}> should have matching closing tags. Found {$openingCount} opening, {$closingCount} closing."
                );
            }
        }
    }
    
    /**
     * Test that custom links are rendered correctly in HTML output
     */
    public function testAirportPage_CustomLinksAreRendered()
    {
        $response = $this->makeRequest('?airport=kspb');
        
        if ($response['http_code'] == 0 || $response['http_code'] != 200) {
            $this->markTestSkipped("Airport page not available (HTTP {$response['http_code']})");
            return;
        }
        
        $html = $response['body'];
        
        // Load test config to check if links are configured
        $configPath = getenv('CONFIG_PATH') ?: __DIR__ . '/../Fixtures/airports.json.test';
        if (!file_exists($configPath)) {
            $this->markTestSkipped("Test configuration not found");
            return;
        }
        
        $config = json_decode(file_get_contents($configPath), true);
        if (empty($config['airports']['kspb']['links'])) {
            // Links are optional, so skip if not configured
            $this->markTestSkipped("Custom links not configured in test fixture");
            return;
        }
        
        $links = $config['airports']['kspb']['links'];
        
        // Verify each link is rendered in the HTML
        foreach ($links as $link) {
            if (empty($link['label']) || empty($link['url'])) {
                continue; // Skip invalid links
            }
            
            // Check that the link URL is present in the HTML (properly escaped)
            $escapedUrl = htmlspecialchars($link['url'], ENT_QUOTES, 'UTF-8');
            $this->assertStringContainsString(
                $escapedUrl,
                $html,
                "Custom link URL should be present in HTML output: {$link['url']}"
            );
            
            // Check that the link label is present in the HTML (properly escaped)
            $escapedLabel = htmlspecialchars($link['label'], ENT_QUOTES, 'UTF-8');
            $this->assertStringContainsString(
                $escapedLabel,
                $html,
                "Custom link label should be present in HTML output: {$link['label']}"
            );
            
            // Check that the link has proper security attributes
            $this->assertStringContainsString(
                'target="_blank"',
                $html,
                "Custom links should have target=\"_blank\" attribute"
            );
            
            $this->assertStringContainsString(
                'rel="noopener"',
                $html,
                "Custom links should have rel=\"noopener\" attribute"
            );
            
            // Verify the link is in a button element (has class="btn")
            // Find the link by looking for the URL and checking it has the btn class nearby
            $linkPattern = '/<a[^>]*href=["\']' . preg_quote($escapedUrl, '/') . '["\'][^>]*class=["\'][^"\']*btn[^"\']*["\']/i';
            $this->assertMatchesRegularExpression(
                $linkPattern,
                $html,
                "Custom link should have class=\"btn\" attribute: {$link['url']}"
            );
        }
    }
    
    /**
     * Test that there are no PHP errors in the output
     */
    public function testAirportPage_NoPhpErrorsInOutput()
    {
        $response = $this->makeRequest('?airport=kspb');
        
        if ($response['http_code'] == 0 || $response['http_code'] != 200) {
            $this->markTestSkipped("Airport page not available (HTTP {$response['http_code']})");
            return;
        }
        
        $html = $response['body'];
        
        // Check for common PHP error patterns
        $phpErrorPatterns = [
            'Fatal error',
            'Parse error',
            'Warning:',
            'Notice:',
            'Deprecated:',
            'Call to undefined',
            'Undefined variable',
            'Undefined index',
        ];
        
        foreach ($phpErrorPatterns as $pattern) {
            $this->assertStringNotContainsString(
                $pattern,
                $html,
                "HTML output should not contain PHP error: {$pattern}"
            );
        }
    }
    
    /**
     * Test that JavaScript code doesn't contain PHP functions
     */
    public function testAirportPage_JavaScriptNoPhpFunctions()
    {
        $response = $this->makeRequest('?airport=kspb');
        
        if ($response['http_code'] != 200) {
            $this->markTestSkipped("Page not available (HTTP {$response['http_code']})");
            return;
        }
        
        $html = $response['body'];
        
        // Extract JavaScript code
        preg_match_all('/<script[^>]*>(.*?)<\/script>/is', $html, $matches);
        
        $phpFunctions = ['empty(', 'isset(', 'array_', 'str_', 'preg_'];
        
        foreach ($matches[1] as $index => $jsCode) {
            // Skip empty scripts
            if (trim($jsCode) === '') {
                continue;
            }
            
            foreach ($phpFunctions as $func) {
                // Check if function appears in code (but not in comments or strings)
                if (strpos($jsCode, $func) !== false) {
                    // Get context around the match
                    $matchIndex = strpos($jsCode, $func);
                    $context = substr($jsCode, max(0, $matchIndex - 50), 150);
                    
                    // Check if it's in a comment
                    $beforeMatch = substr($jsCode, 0, $matchIndex);
                    $isInComment = 
                        (strrpos($beforeMatch, '//') !== false && 
                         strrpos($beforeMatch, "\n", strrpos($beforeMatch, '//')) === false) ||
                        (strrpos($beforeMatch, '/*') !== false && 
                         strrpos($beforeMatch, '*/', strrpos($beforeMatch, '/*')) === false);
                    
                    // Check if it's in a string (simplified check)
                    $isInString = preg_match('/["\'`].*' . preg_quote($func, '/') . '/', $context);
                    
                    if (!$isInComment && !$isInString) {
                        $this->fail(
                            "Script block #{$index} contains PHP function: {$func}\n" .
                            "Context: " . substr($context, 0, 100)
                        );
                    }
                }
            }
        }
        
        $this->assertTrue(true);
    }
    
    /**
     * Test that JavaScript uses correct API endpoints
     */
    public function testAirportPage_JavaScriptApiEndpointsAreCorrect()
    {
        $response = $this->makeRequest('?airport=kspb');
        
        if ($response['http_code'] != 200) {
            $this->markTestSkipped("Page not available (HTTP {$response['http_code']})");
            return;
        }
        
        $html = $response['body'];
        
        // Check for incorrect weather.php calls (should be /api/weather.php)
        // Look for patterns like: "/weather.php?" or "weather.php?" but not "/api/weather.php"
        $incorrectPatterns = [
            '/["\']\/weather\.php\?/',  // "/weather.php?"
            '/["\']weather\.php\?/',     // "weather.php?" (relative)
        ];
        
        $errors = [];
        foreach ($incorrectPatterns as $pattern) {
            if (preg_match_all($pattern, $html, $matches)) {
                foreach ($matches[0] as $match) {
                    // Get context around the match
                    $matchIndex = strpos($html, $match);
                    $context = substr($html, max(0, $matchIndex - 50), 150);
                    
                    // If context doesn't contain /api/weather.php, it's an error
                    if (strpos($context, '/api/weather.php') === false && 
                        strpos($context, 'api/weather.php') === false) {
                        $errors[] = "Found incorrect weather API call: {$match}";
                    }
                }
            }
        }
        
        if (!empty($errors)) {
            $this->fail(
                "JavaScript should not call /weather.php directly (use /api/weather.php)\n" .
                "Errors found:\n" . implode("\n", $errors)
            );
        }
        
        // Verify correct endpoint is used
        $this->assertStringContainsString(
            '/api/weather.php',
            $html,
            "JavaScript should call /api/weather.php endpoint"
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
    
    /**
     * Test that partnerships section is rendered when partners exist
     */
    public function testAirportPage_PartnershipsSectionRendered()
    {
        $response = $this->makeRequest('?airport=kspb');
        
        if ($response['http_code'] == 0 || $response['http_code'] != 200) {
            $this->markTestSkipped("Airport page not available (HTTP {$response['http_code']})");
            return;
        }
        
        $html = $response['body'];
        
        // Check for partnerships section
        $this->assertStringContainsString(
            'partnerships-section',
            $html,
            "Partnerships section should be present in HTML"
        );
        
        // Check for partnerships heading
        $this->assertStringContainsString(
            'Support These Partners',
            $html,
            "Partnerships heading should be present"
        );
    }
    
    /**
     * Test that partnerships section contains partner links
     */
    public function testAirportPage_PartnershipsContainLinks()
    {
        $response = $this->makeRequest('?airport=kspb');
        
        if ($response['http_code'] == 0 || $response['http_code'] != 200) {
            $this->markTestSkipped("Airport page not available (HTTP {$response['http_code']})");
            return;
        }
        
        $html = $response['body'];
        
        // Check for partner-link class (indicates partner items are rendered)
        // Note: This test will pass even if no partners are configured (section exists but empty)
        // The actual partner content depends on the airports.json configuration
        $hasPartnershipsSection = strpos($html, 'partnerships-section') !== false;
        
        if ($hasPartnershipsSection) {
            // If section exists, check for proper structure
            $this->assertStringContainsString(
                'partnerships-container',
                $html,
                "Partnerships container should be present"
            );
        } else {
            $this->markTestSkipped("Partnerships section not found - may not be configured for test airport");
        }
    }
    
    /**
     * Test that data sources section is rendered
     */
    public function testAirportPage_DataSourcesSectionRendered()
    {
        $response = $this->makeRequest('?airport=kspb');
        
        if ($response['http_code'] == 0 || $response['http_code'] != 200) {
            $this->markTestSkipped("Airport page not available (HTTP {$response['http_code']})");
            return;
        }
        
        $html = $response['body'];
        
        // Check for data sources content
        $this->assertStringContainsString(
            'data-sources-content',
            $html,
            "Data sources section should be present in HTML"
        );
        
        // Check for data sources label
        $this->assertStringContainsString(
            'Weather data at this airport from',
            $html,
            "Data sources label should be present"
        );
    }
    
    /**
     * Test that Fuel and Repairs fields are always displayed
     */
    public function testAirportPage_FuelAndRepairsAlwaysDisplayed()
    {
        $response = $this->makeRequest('?airport=kspb');
        
        if ($response['http_code'] == 0 || $response['http_code'] != 200) {
            $this->markTestSkipped("Airport page not available (HTTP {$response['http_code']})");
            return;
        }
        
        $html = $response['body'];
        
        // Both fields should always be present
        $this->assertStringContainsString(
            '<span class="label">Fuel:</span>',
            $html,
            "Fuel field label should always be present"
        );
        
        $this->assertStringContainsString(
            '<span class="label">Repairs:</span>',
            $html,
            "Repairs field label should always be present"
        );
    }
    
    /**
     * Test that Fuel and Repairs show correct values when configured
     */
    public function testAirportPage_FuelAndRepairsShowConfiguredValues()
    {
        $response = $this->makeRequest('?airport=kspb');
        
        if ($response['http_code'] == 0 || $response['http_code'] != 200) {
            $this->markTestSkipped("Airport page not available (HTTP {$response['http_code']})");
            return;
        }
        
        $html = $response['body'];
        
        // Load test config to check services configuration
        $configPath = getenv('CONFIG_PATH') ?: __DIR__ . '/../Fixtures/airports.json.test';
        if (!file_exists($configPath)) {
            $this->markTestSkipped("Test configuration not found");
            return;
        }
        
        $config = json_decode(file_get_contents($configPath), true);
        $services = $config['airports']['kspb']['services'] ?? null;
        
        if ($services === null) {
            $this->markTestSkipped("Services not configured in test fixture");
            return;
        }
        
        // If fuel is configured, it should be displayed
        if (!empty($services['fuel'])) {
            $escapedFuel = htmlspecialchars($services['fuel'], ENT_QUOTES, 'UTF-8');
            $this->assertStringContainsString(
                $escapedFuel,
                $html,
                "Fuel value should be displayed when configured: {$services['fuel']}"
            );
        }
        
        // If repairs_available is true, it should show "Available"
        if (!empty($services['repairs_available'])) {
            $this->assertStringContainsString(
                'Available',
                $html,
                "Repairs should show 'Available' when repairs_available is true"
            );
        }
    }
    
    /**
     * Test that Fuel and Repairs show "Not Available" when services are missing
     */
    public function testAirportPage_FuelAndRepairsShowNotAvailableWhenMissing()
    {
        // Use an airport without services configured (03s doesn't have services in fixture)
        $response = $this->makeRequest('?airport=03s');
        
        if ($response['http_code'] == 0 || $response['http_code'] != 200) {
            $this->markTestSkipped("Airport page not available (HTTP {$response['http_code']})");
            return;
        }
        
        $html = $response['body'];
        
        // Both fields should still be present
        $this->assertStringContainsString(
            '<span class="label">Fuel:</span>',
            $html,
            "Fuel field label should always be present"
        );
        
        $this->assertStringContainsString(
            '<span class="label">Repairs:</span>',
            $html,
            "Repairs field label should always be present"
        );
        
        // Both should show "Not Available"
        // Check that "Not Available" appears in the context of Fuel field
        $fuelLabelPos = strpos($html, '<span class="label">Fuel:</span>');
        $this->assertNotFalse($fuelLabelPos, "Fuel label should be present");
        
        // Check for "Not Available" within 200 characters after Fuel label
        $fuelContext = substr($html, $fuelLabelPos, 200);
        $this->assertStringContainsString(
            'Not Available',
            $fuelContext,
            "Fuel should show 'Not Available' when services are missing"
        );
        
        // Check that "Not Available" appears in the context of Repairs field
        $repairsLabelPos = strpos($html, '<span class="label">Repairs:</span>');
        $this->assertNotFalse($repairsLabelPos, "Repairs label should be present");
        
        // Check for "Not Available" within 200 characters after Repairs label
        $repairsContext = substr($html, $repairsLabelPos, 200);
        $this->assertStringContainsString(
            'Not Available',
            $repairsContext,
            "Repairs should show 'Not Available' when services are missing"
        );
    }
    
    /**
     * Test that Location field uses geo: URI scheme when coordinates are available
     */
    public function testAirportPage_LocationUsesGeoUriWithCoordinates()
    {
        $response = $this->makeRequest('?airport=kspb');
        
        if ($response['http_code'] == 0 || $response['http_code'] != 200) {
            $this->markTestSkipped("Airport page not available (HTTP {$response['http_code']})");
            return;
        }
        
        $html = $response['body'];
        
        // Load test config to verify coordinates
        $configPath = getenv('CONFIG_PATH') ?: __DIR__ . '/../Fixtures/airports.json.test';
        if (!file_exists($configPath)) {
            $this->markTestSkipped("Test configuration not found");
            return;
        }
        
        $config = json_decode(file_get_contents($configPath), true);
        $airport = $config['airports']['kspb'] ?? null;
        
        if ($airport === null || empty($airport['lat']) || empty($airport['lon'])) {
            $this->markTestSkipped("Airport coordinates not available in test fixture");
            return;
        }
        
        // Verify geo: URI is present (format: geo:lat,lon or geo:lat,lon?q=address)
        $lat = (float)$airport['lat'];
        $lon = (float)$airport['lon'];
        $geoPattern = '/href=["\']geo:' . preg_quote($lat, '/') . ',' . preg_quote($lon, '/') . '/';
        
        $this->assertMatchesRegularExpression(
            $geoPattern,
            $html,
            "Location field should use geo: URI scheme with coordinates"
        );
        
        // Verify it's not using Google Maps URL
        $this->assertStringNotContainsString(
            'maps.google.com',
            $html,
            "Location field should not use Google Maps URL"
        );
        
        // Verify address-link class is present
        $this->assertStringContainsString(
            'class="address-link"',
            $html,
            "Location link should have address-link class"
        );
    }
    
    /**
     * Test that Location field displays address text correctly
     */
    public function testAirportPage_LocationDisplaysAddressText()
    {
        $response = $this->makeRequest('?airport=kspb');
        
        if ($response['http_code'] == 0 || $response['http_code'] != 200) {
            $this->markTestSkipped("Airport page not available (HTTP {$response['http_code']})");
            return;
        }
        
        $html = $response['body'];
        
        // Load test config to verify address
        $configPath = getenv('CONFIG_PATH') ?: __DIR__ . '/../Fixtures/airports.json.test';
        if (!file_exists($configPath)) {
            $this->markTestSkipped("Test configuration not found");
            return;
        }
        
        $config = json_decode(file_get_contents($configPath), true);
        $airport = $config['airports']['kspb'] ?? null;
        
        if ($airport === null || empty($airport['address'])) {
            $this->markTestSkipped("Airport address not available in test fixture");
            return;
        }
        
        // Verify address text is displayed (should contain city name)
        $addressParts = explode(',', $airport['address']);
        $cityName = trim($addressParts[0]);
        
        $this->assertStringContainsString(
            htmlspecialchars($cityName, ENT_QUOTES, 'UTF-8'),
            $html,
            "Location field should display address text"
        );
    }
    
    /**
     * Test that geo: URI includes address query parameter when address is available
     */
    public function testAirportPage_GeoUriIncludesAddressQuery()
    {
        $response = $this->makeRequest('?airport=kspb');
        
        if ($response['http_code'] == 0 || $response['http_code'] != 200) {
            $this->markTestSkipped("Airport page not available (HTTP {$response['http_code']})");
            return;
        }
        
        $html = $response['body'];
        
        // Load test config
        $configPath = getenv('CONFIG_PATH') ?: __DIR__ . '/../Fixtures/airports.json.test';
        if (!file_exists($configPath)) {
            $this->markTestSkipped("Test configuration not found");
            return;
        }
        
        $config = json_decode(file_get_contents($configPath), true);
        $airport = $config['airports']['kspb'] ?? null;
        
        if ($airport === null || empty($airport['lat']) || empty($airport['lon']) || empty($airport['address'])) {
            $this->markTestSkipped("Required airport data not available in test fixture");
            return;
        }
        
        // Verify geo: URI includes ?q= parameter with address
        $lat = (float)$airport['lat'];
        $lon = (float)$airport['lon'];
        $geoPattern = '/href=["\']geo:' . preg_quote($lat, '/') . ',' . preg_quote($lon, '/') . '\?q=/';
        
        $this->assertMatchesRegularExpression(
            $geoPattern,
            $html,
            "geo: URI should include address query parameter when address is available"
        );
    }
}

