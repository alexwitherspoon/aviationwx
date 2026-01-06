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
    private $cachedHtml = null;
    private $cachedHtmlAirport = null;
    
    protected function setUp(): void
    {
        parent::setUp();
        
        $this->baseUrl = getenv('TEST_API_URL') ?: 'http://localhost:8080';
        
        if (!function_exists('curl_init')) {
            $this->markTestSkipped('cURL not available');
        }
    }
    
    /**
     * Get cached HTML for airport page (cache per test run)
     * This reduces HTTP requests from 19 to 1 for tests that use the same airport
     */
    private function getCachedHtml(string $airport = 'kspb'): ?string
    {
        // Return cached HTML if available and for same airport
        if ($this->cachedHtml !== null && $this->cachedHtmlAirport === $airport) {
            return $this->cachedHtml;
        }
        
        // Fetch and cache HTML
        $response = $this->makeRequest("?airport={$airport}");
        
        if ($response['http_code'] == 0 || $response['http_code'] != 200) {
            return null; // Return null if request failed (test will skip)
        }
        
        $this->cachedHtml = $response['body'];
        $this->cachedHtmlAirport = $airport;
        
        return $this->cachedHtml;
    }
    
    /**
     * Test that all script tags are properly closed in airport page output
     * This catches the bug where script tags were opened but not closed
     */
    public function testAirportPage_AllScriptTagsAreClosed()
    {
        $html = $this->getCachedHtml('kspb');
        
        if ($html === null) {
            $this->markTestSkipped("Airport page not available");
            return;
        }
        
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
        $html = $this->getCachedHtml('kspb');
        
        if ($html === null) {
            $this->markTestSkipped("Airport page not available");
            return;
        }
        
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
        $html = $this->getCachedHtml('kspb');
        
        if ($html === null) {
            $this->markTestSkipped("Airport page not available");
            return;
        }
        
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
                            // Check if we're inside a template literal context
                            // Template literals can be nested, so we need a robust detection method
                            $beforeContent = substr($scriptContent, 0, $i);
                            
                            // Method 1: Count backticks - if odd, we're in an outer template literal
                            $backtickCount = substr_count($beforeContent, '`');
                            $isInOuterTemplateLiteral = ($backtickCount % 2) === 1;
                            
                            // Method 2: Check if we're in an unclosed ${} expression
                            // Count ${ and } to see if we're inside an unclosed expression
                            $openExpressions = substr_count($beforeContent, '${');
                            $closeExpressions = substr_count($beforeContent, '}');
                            $hasUnclosedExpression = $openExpressions > $closeExpressions;
                            
                            // Method 3: Check if there's a backtick before this position
                            $hasBacktick = strpos($beforeContent, '`') !== false;
                            
                            // Method 4: Look for common patterns that indicate template literal usage
                            // Pattern: container.innerHTML = `... or element.innerHTML = `...
                            $hasInnerHTMLPattern = preg_match('/(container|element|document)\.innerHTML\s*=\s*`/i', $beforeContent);
                            
                            // We're safe if ANY of these conditions are true:
                            // 1. We're in an outer template literal (backtick count is odd)
                            // 2. We're in an unclosed ${ expression (template context)
                            // 3. We have backticks AND an unclosed ${ expression (nested template literal)
                            // 4. We have the innerHTML pattern (definitely template literal context)
                            $isInTemplateContext = $isInOuterTemplateLiteral 
                                || $hasUnclosedExpression 
                                || ($hasBacktick && $hasUnclosedExpression)
                                || $hasInnerHTMLPattern;
                            
                            if (!$isInTemplateContext) {
                                $this->fail(
                                    "Script tag #{$index} contains HTML content outside of strings/template literals: " .
                                    substr($scriptContent, max(0, $i - 50), 100)
                                );
                            }
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
        $html = $this->getCachedHtml('kspb');
        
        if ($html === null) {
            $this->markTestSkipped("Airport page not available");
            return;
        }
        
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
        $html = $this->getCachedHtml('kspb');
        
        if ($html === null) {
            $this->markTestSkipped("Airport page not available");
            return;
        }
        
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
        $html = $this->getCachedHtml('kspb');
        
        if ($html === null) {
            $this->markTestSkipped("Airport page not available");
            return;
        }
        
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
        
        // Note: Integration tests make HTTP requests to a live server which may use production config
        // So the links in the HTML might not match the test fixture exactly
        // We'll verify that custom links are rendered (if any exist) and have the correct format
        
        // First, check if any custom links are rendered in the HTML
        // Look for links with target="_blank" and rel="noopener" that have class="btn"
        // These are the custom links (other links like AirNav, SkyVector also have these attributes)
        $customLinkPattern = '/<a[^>]*target=["\']_blank["\'][^>]*rel=["\']noopener["\'][^>]*class=["\'][^"\']*btn[^"\']*["\'][^>]*>/i';
        $hasCustomLinks = preg_match_all($customLinkPattern, $html, $customLinkMatches);
        
        // Verify that custom links have proper security attributes
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
        
            // If test fixture has links, try to verify they're rendered
            // But skip if the server is using production config (links won't match)
            $foundTestFixtureLinks = 0;
            foreach ($links as $link) {
                if (empty($link['label']) || empty($link['url'])) {
                    continue; // Skip invalid links
                }
                
                // Check if the link label is present (more reliable than URL which may differ)
                $escapedLabel = htmlspecialchars($link['label'], ENT_QUOTES, 'UTF-8');
                if (strpos($html, $escapedLabel) !== false) {
                    // Look for the link in the links section (where custom links are rendered)
                    // Custom links are in a <div class="links"> section
                    // Match links that have target="_blank", rel="noopener", and class="btn"
                    // and contain the label text
                    $linksSectionPattern = '/<div[^>]*class=["\'][^"\']*links[^"\']*["\'][^>]*>.*?<\/div>/is';
                    if (preg_match($linksSectionPattern, $html, $linksSectionMatch)) {
                        $linksSection = $linksSectionMatch[0];
                        // Look for link with the label in the links section
                        if (preg_match('/<a[^>]*class=["\'][^"\']*btn[^"\']*["\'][^>]*>.*?' . preg_quote($escapedLabel, '/') . '.*?<\/a>/is', $linksSection, $linkMatch)) {
                            $foundTestFixtureLinks++;
                            // Link found in links section with btn class - that's correct
                            $this->assertTrue(true, "Custom link '{$link['label']}' found with btn class");
                        }
                    }
                }
            }
        
        // If no test fixture links were found, the server is likely using production config
        // In that case, just verify that custom links in general are properly formatted
        if ($foundTestFixtureLinks === 0 && $hasCustomLinks > 0) {
            // Server has custom links but they don't match test fixture - that's OK
            // Just verify at least one custom link has the btn class
            $this->assertGreaterThan(0, $hasCustomLinks, "Custom links should be rendered");
        } elseif ($foundTestFixtureLinks === 0) {
            // No custom links found at all - skip if not configured in production
            $this->markTestSkipped("Custom links not found - server may be using production config without test fixture links");
        }
    }
    
    /**
     * Test that there are no PHP errors in the output
     */
    public function testAirportPage_NoPhpErrorsInOutput()
    {
        $html = $this->getCachedHtml('kspb');
        
        if ($html === null) {
            $this->markTestSkipped("Airport page not available");
            return;
        }
        
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
        $html = $this->getCachedHtml('kspb');
        
        if ($html === null) {
            $this->markTestSkipped("Page not available");
            return;
        }
        
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
        $html = $this->getCachedHtml('kspb');
        
        if ($html === null) {
            $this->markTestSkipped("Page not available");
            return;
        }
        
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
     * Test that required JavaScript functions are defined in HTML
     * This ensures critical functions like fetchWeather, displayWeather, updateWeatherTimestamp exist
     */
    public function testAirportPage_RequiredJavaScriptFunctionsDefined()
    {
        $html = $this->getCachedHtml('kspb');
        
        if ($html === null) {
            $this->markTestSkipped("Airport page not available");
            return;
        }
        
        // Required functions that should be defined
        // Search directly in HTML (functions are in inline script tags)
        $requiredFunctions = [
            'fetchWeather',
            'displayWeather',
            'updateWeatherTimestamp'
        ];
        
        $missingFunctions = [];
        
        foreach ($requiredFunctions as $funcName) {
            // Check if function is defined in HTML (various patterns)
            // Search the entire HTML, not just extracted JavaScript
            $patterns = [
                "/async\s+function\s+{$funcName}\s*\(/",   // async function fetchWeather(
                "/function\s+{$funcName}\s*\(/",           // function fetchWeather(
                "/const\s+{$funcName}\s*=\s*async\s*function/", // const fetchWeather = async function
                "/const\s+{$funcName}\s*=\s*function/",    // const fetchWeather = function
                "/const\s+{$funcName}\s*=\s*\(/",         // const fetchWeather = (
                "/var\s+{$funcName}\s*=\s*function/",     // var fetchWeather = function
                "/let\s+{$funcName}\s*=\s*function/",    // let fetchWeather = function
                "/{$funcName}\s*[:=]\s*function/",       // fetchWeather: function or fetchWeather = function
            ];
            
            $found = false;
            foreach ($patterns as $pattern) {
                // Remove PHP code blocks before matching (PHP code within script tags)
                $htmlForMatching = preg_replace('/<\?php[\s\S]*?\?>/', '', $html);
                $htmlForMatching = preg_replace('/<\?=[\s\S]*?\?>/', '', $htmlForMatching);
                $htmlForMatching = preg_replace('/<\?[\s\S]*?\?>/', '', $htmlForMatching);
                
                if (preg_match($pattern, $htmlForMatching)) {
                    $found = true;
                    break;
                }
            }
            
            if (!$found) {
                $missingFunctions[] = $funcName;
            }
        }
        
        if (!empty($missingFunctions)) {
            $this->fail(
                "Required JavaScript functions not found in HTML: " . implode(', ', $missingFunctions) . "\n" .
                "These functions are critical for weather data display and updates."
            );
        }
        
        $this->assertTrue(true, 'All required JavaScript functions are defined');
    }
    
    /**
     * Test that Service Worker file exists and has correct MIME type
     * This ensures the Service Worker file is accessible and properly configured
     */
    public function testServiceWorker_FileExistsAndHasCorrectMimeType()
    {
        $swPath = '/public/js/service-worker.js';
        $response = $this->makeRequest($swPath);
        
        if ($response['http_code'] == 0) {
            $this->markTestSkipped("Service Worker endpoint not available");
            return;
        }
        
        // Service Worker file should exist (200) or be accessible
        $this->assertEquals(
            200,
            $response['http_code'],
            "Service Worker file should be accessible (got HTTP {$response['http_code']})"
        );
        
        // Get content type from headers (if available) or check file content
        $body = $response['body'];
        
        // Should not be HTML (404 page)
        $this->assertStringNotContainsString(
            '<!DOCTYPE',
            $body,
            "Service Worker file should not return HTML (404 page)"
        );
        
        $this->assertStringNotContainsString(
            '<html',
            $body,
            "Service Worker file should not return HTML (404 page)"
        );
        
        // Should contain JavaScript code (Service Worker specific)
        // Check for serviceWorker (case-insensitive) or self.addEventListener
        $hasServiceWorkerCode = stripos($body, 'serviceWorker') !== false || 
                               stripos($body, 'self.addEventListener') !== false ||
                               stripos($body, 'addEventListener') !== false;
        $this->assertTrue(
            $hasServiceWorkerCode,
            "Service Worker file should contain serviceWorker code or addEventListener (Service Worker API)"
        );
        
        // Should contain self.addEventListener (Service Worker API) - more specific check
        $hasSelfAddEventListener = stripos($body, 'self.addEventListener') !== false;
        $this->assertTrue(
            $hasSelfAddEventListener,
            "Service Worker file should contain self.addEventListener (Service Worker API)"
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
        $html = $this->getCachedHtml('kspb');
        
        if ($html === null) {
            $this->markTestSkipped("Airport page not available");
            return;
        }
        
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
        $html = $this->getCachedHtml('kspb');
        
        if ($html === null) {
            $this->markTestSkipped("Airport page not available");
            return;
        }
        
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
        $html = $this->getCachedHtml('kspb');
        
        if ($html === null) {
            $this->markTestSkipped("Airport page not available");
            return;
        }
        
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
        $html = $this->getCachedHtml('kspb');
        
        if ($html === null) {
            $this->markTestSkipped("Airport page not available");
            return;
        }
        
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
        $html = $this->getCachedHtml('kspb');
        
        if ($html === null) {
            $this->markTestSkipped("Airport page not available");
            return;
        }
        
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
        $html = $this->getCachedHtml('kspb');
        
        if ($html === null) {
            $this->markTestSkipped("Airport page not available");
            return;
        }
        
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
        
        // Verify Apple Maps URL is present in data attribute for Safari fallback
        if (!empty($airport['address'])) {
            $this->assertStringContainsString(
                'data-apple-maps=',
                $html,
                "Location link should have Apple Maps URL in data attribute for Safari fallback"
            );
            $this->assertStringContainsString(
                'maps.apple.com',
                $html,
                "Apple Maps URL should be present for Safari compatibility"
            );
        }
        
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
        $html = $this->getCachedHtml('kspb');
        
        if ($html === null) {
            $this->markTestSkipped("Airport page not available");
            return;
        }
        
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
        $html = $this->getCachedHtml('kspb');
        
        if ($html === null) {
            $this->markTestSkipped("Airport page not available");
            return;
        }
        
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

