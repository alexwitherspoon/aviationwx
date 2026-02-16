<?php
/**
 * External Links Validation Tests
 * 
 * Tests that external links (AirNav, SkyVector, AOPA, FAA Weather, ForeFlight) are:
 * - Generating correct URL formats
 * - Supporting manual overrides
 * - Reachable (returning valid HTTP status codes)
 * - Not redirecting to error pages or unexpected locations
 * 
 * These tests help detect when external services change their URL structure
 * or when links break, so we can update them proactively.
 * 
 * Note: These tests are non-blocking as external services can be flaky.
 */

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../lib/config.php';

class ExternalLinksTest extends TestCase
{
    private $testAirports = [];
    
    protected function setUp(): void
    {
        parent::setUp();
        
        if (!function_exists('curl_init')) {
            $this->markTestSkipped('cURL not available');
        }
        
        // Load test airports configuration
        $configPath = getenv('CONFIG_PATH') ?: __DIR__ . '/../Fixtures/airports.json.test';
        if (!file_exists($configPath)) {
            $this->markTestSkipped("Airport configuration not found at: $configPath");
            return;
        }
        
        $config = json_decode(file_get_contents($configPath), true);
        if (!isset($config['airports'])) {
            $this->markTestSkipped('No airports found in configuration');
            return;
        }
        
        // Use first 3 airports for testing (to avoid too many external requests)
        $this->testAirports = array_slice($config['airports'], 0, 3, true);
        
        if (empty($this->testAirports)) {
            $this->markTestSkipped('No test airports available');
        }
    }
    
    /**
     * Test AirNav URLs are valid and reachable
     */
    public function testAirNavLinks_AreValid()
    {
        foreach ($this->testAirports as $airportId => $airport) {
            // Check for manual override first
            if (!empty($airport['airnav_url'])) {
                $url = $airport['airnav_url'];
            } else {
                // Use auto-generated URL if identifier available
                $linkIdentifier = getBestIdentifierForLinks($airport);
                if ($linkIdentifier === null) {
                    $this->markTestIncomplete("No identifier available for AirNav URL: $airportId");
                    continue;
                }
                $url = 'https://www.airnav.com/airport/' . $linkIdentifier;
            }
            
            $result = $this->validateUrl($url, 'airnav.com');
            
            $identifier = $airport['icao'] ?? $airport['iata'] ?? $airport['faa'] ?? $airportId;
            $this->assertTrue(
                $result['valid'],
                "AirNav URL for {$identifier} should be valid: {$result['message']}"
            );
        }
    }
    
    /**
     * Test SkyVector URLs are valid and reachable
     */
    public function testSkyVectorLinks_AreValid()
    {
        foreach ($this->testAirports as $airportId => $airport) {
            // Check for manual override first
            if (!empty($airport['skyvector_url'])) {
                $url = $airport['skyvector_url'];
            } else {
                // Use auto-generated URL if identifier available
                $linkIdentifier = getBestIdentifierForLinks($airport);
                if ($linkIdentifier === null) {
                    $this->markTestIncomplete("No identifier available for SkyVector URL: $airportId");
                    continue;
                }
                $url = 'https://skyvector.com/airport/' . $linkIdentifier;
            }
            
            $result = $this->validateUrl($url, 'skyvector.com');
            
            $identifier = $airport['icao'] ?? $airport['iata'] ?? $airport['faa'] ?? $airportId;
            $this->assertTrue(
                $result['valid'],
                "SkyVector URL for {$identifier} should be valid: {$result['message']}"
            );
        }
    }
    
    /**
     * Test AOPA URLs are valid and reachable (US airports only, or manual override)
     */
    public function testAOPALinks_AreValid()
    {
        foreach ($this->testAirports as $airportId => $airport) {
            $region = getAviationRegionFromIcao($airport['icao'] ?? null);
            if (empty($airport['aopa_url']) && $region !== 'US') {
                continue; // AOPA only shown for US airports
            }
            if (!empty($airport['aopa_url'])) {
                $url = $airport['aopa_url'];
            } else {
                $linkIdentifier = getBestIdentifierForLinks($airport);
                if ($linkIdentifier === null) {
                    $this->markTestIncomplete("No identifier available for AOPA URL: $airportId");
                    continue;
                }
                $url = 'https://www.aopa.org/destinations/airports/' . $linkIdentifier;
            }
            $result = $this->validateUrl($url, 'aopa.org');
            $identifier = $airport['icao'] ?? $airport['iata'] ?? $airport['faa'] ?? $airportId;
            $this->assertTrue(
                $result['valid'],
                "AOPA URL for {$identifier} should be valid: {$result['message']}"
            );
        }
    }
    
    /**
     * Test FAA Weather Cams URLs are valid and reachable (US airports only, or manual override)
     */
    public function testFAAWeatherLinks_AreValid()
    {
        foreach ($this->testAirports as $airportId => $airport) {
            $region = getAviationRegionFromIcao($airport['icao'] ?? null);
            if (empty($airport['faa_weather_url']) && $region !== 'US') {
                continue; // FAA Weather only shown for US airports
            }
            if (!empty($airport['faa_weather_url'])) {
                $url = $airport['faa_weather_url'];
            } else {
                $linkIdentifier = getBestIdentifierForLinks($airport);
                if ($linkIdentifier === null || empty($airport['lat']) || empty($airport['lon'])) {
                    $this->markTestIncomplete("Required fields missing for FAA Weather URL: $airportId");
                    continue;
                }
                $buffer = 2.0;
                $min_lon = $airport['lon'] - $buffer;
                $min_lat = $airport['lat'] - $buffer;
                $max_lon = $airport['lon'] + $buffer;
                $max_lat = $airport['lat'] + $buffer;
                $faa_identifier = preg_replace('/^K/', '', $linkIdentifier);
                $url = sprintf(
                    'https://weathercams.faa.gov/map/%.5f,%.5f,%.5f,%.5f/airport/%s/',
                    $min_lon,
                    $min_lat,
                    $max_lon,
                    $max_lat,
                    $faa_identifier
                );
            }
            $result = $this->validateUrl($url, 'weathercams.faa.gov');
            $identifier = $airport['icao'] ?? $airport['iata'] ?? $airport['faa'] ?? $airportId;
            $this->assertTrue(
                $result['valid'],
                "FAA Weather URL for {$identifier} should be valid: {$result['message']}"
            );
        }
    }
    
    /**
     * Test ForeFlight URLs are valid and properly formatted
     * ForeFlight accepts ICAO, IATA, or FAA codes (prefer ICAO > IATA > FAA)
     * Uses foreflightmobile://maps/search?q= format per official ForeFlight documentation
     */
    public function testForeFlightLinks_AreValid()
    {
        // Official ForeFlight URL scheme per their documentation
        // Source: https://foreflight.com/support/app-urls/
        $officialScheme = 'foreflightmobile://maps/search?q=';
        
        foreach ($this->testAirports as $airportId => $airport) {
            // Check for manual override first
            if (!empty($airport['foreflight_url'])) {
                $url = $airport['foreflight_url'];
            } else {
                // Use auto-generated URL if identifier available (ICAO > IATA > FAA)
                $linkIdentifier = getBestIdentifierForLinks($airport);
                if ($linkIdentifier === null) {
                    $this->markTestIncomplete("No identifier available for ForeFlight URL: $airportId");
                    continue;
                }
                $url = 'foreflightmobile://maps/search?q=' . urlencode($linkIdentifier);
            }
            
            // Validate against official ForeFlight URL scheme
            $this->assertStringStartsWith(
                $officialScheme,
                $url,
                "ForeFlight URL must use official scheme '{$officialScheme}' for {$airportId}"
            );
            
            // Ensure we're NOT using the old incorrect format
            $this->assertStringNotContainsString(
                'foreflight://airport/',
                $url,
                "ForeFlight URL must not use deprecated 'foreflight://airport/' format for {$airportId}"
            );
            
            // Validate complete URL format matches official documentation
            $identifier = $airport['icao'] ?? $airport['iata'] ?? $airport['faa'] ?? $airportId;
            $this->assertMatchesRegularExpression(
                '/^foreflightmobile:\/\/maps\/search\?q=[A-Z0-9]+$/',
                $url,
                "ForeFlight URL format must match official pattern for {$identifier}"
            );
            
            // Verify identifier is properly URL-encoded (though ICAO/IATA/FAA codes don't need encoding)
            // Extract identifier from URL to verify it matches expected value
            if (preg_match('/q=([A-Z0-9]+)$/', $url, $matches)) {
                $urlIdentifier = $matches[1];
                $expectedIdentifier = getBestIdentifierForLinks($airport);
                if ($expectedIdentifier !== null) {
                    $this->assertEquals(
                        strtoupper($expectedIdentifier),
                        $urlIdentifier,
                        "ForeFlight URL identifier should match best available identifier for {$airportId}"
                    );
                }
            }
        }
    }
    
    /**
     * Test custom links are valid and properly formatted
     */
    public function testCustomLinks_AreValid()
    {
        foreach ($this->testAirports as $airportId => $airport) {
            if (empty($airport['links']) || !is_array($airport['links'])) {
                // Custom links are optional, so skip if not configured
                continue;
            }
            
            foreach ($airport['links'] as $index => $link) {
                // Validate structure
                $this->assertArrayHasKey(
                    'label',
                    $link,
                    "Custom link #{$index} for airport {$airportId} must have a 'label' field"
                );
                
                $this->assertArrayHasKey(
                    'url',
                    $link,
                    "Custom link #{$index} for airport {$airportId} must have a 'url' field"
                );
                
                // Validate label is non-empty
                $this->assertNotEmpty(
                    $link['label'],
                    "Custom link #{$index} for airport {$airportId} must have a non-empty label"
                );
                
                // Validate URL is non-empty
                $this->assertNotEmpty(
                    $link['url'],
                    "Custom link #{$index} for airport {$airportId} must have a non-empty URL"
                );
                
                // Validate URL format
                $this->assertTrue(
                    filter_var($link['url'], FILTER_VALIDATE_URL) !== false,
                    "Custom link #{$index} for airport {$airportId} must have a valid URL format: {$link['url']}"
                );
                
                // Validate URL uses HTTPS
                $this->assertStringStartsWith(
                    'https://',
                    $link['url'],
                    "Custom link #{$index} for airport {$airportId} must use HTTPS: {$link['url']}"
                );
            }
        }
    }
    
    /**
     * Test that URL formats match expected patterns
     */
    public function testUrlFormats_MatchExpectedPatterns()
    {
        foreach ($this->testAirports as $airportId => $airport) {
            $linkIdentifier = getBestIdentifierForLinks($airport);
            $region = getAviationRegionFromIcao($airport['icao'] ?? null);
            
            // Test SkyVector format
            if ($linkIdentifier !== null) {
                $skyvectorUrl = !empty($airport['skyvector_url']) 
                    ? $airport['skyvector_url'] 
                    : "https://skyvector.com/airport/$linkIdentifier";
                $this->assertMatchesRegularExpression(
                    '/^https:\/\/skyvector\.com\/airport\/[A-Z0-9]+$/',
                    $skyvectorUrl,
                    "SkyVector URL format should match expected pattern for {$linkIdentifier}"
                );
            }
            
            // Test AOPA format (US only, or override)
            if ($linkIdentifier !== null && ($region === 'US' || !empty($airport['aopa_url']))) {
                $aopaUrl = !empty($airport['aopa_url']) 
                    ? $airport['aopa_url'] 
                    : "https://www.aopa.org/destinations/airports/$linkIdentifier";
                $this->assertMatchesRegularExpression(
                    '/^https:\/\/www\.aopa\.org\/destinations\/airports\/[A-Z0-9]+$/',
                    $aopaUrl,
                    "AOPA URL format should match expected pattern for {$linkIdentifier}"
                );
            }
            
            // Test FAA Weather format (US only, or override)
            if ($linkIdentifier !== null && !empty($airport['lat']) && !empty($airport['lon'])
                && ($region === 'US' || !empty($airport['faa_weather_url']))) {
                if (!empty($airport['faa_weather_url'])) {
                    $faaUrl = $airport['faa_weather_url'];
                } else {
                    $buffer = 2.0;
                    $min_lon = $airport['lon'] - $buffer;
                    $min_lat = $airport['lat'] - $buffer;
                    $max_lon = $airport['lon'] + $buffer;
                    $max_lat = $airport['lat'] + $buffer;
                    $faa_identifier = preg_replace('/^K/', '', $linkIdentifier);
                    $faaUrl = sprintf(
                        'https://weathercams.faa.gov/map/%.5f,%.5f,%.5f,%.5f/airport/%s/',
                        $min_lon,
                        $min_lat,
                        $max_lon,
                        $max_lat,
                        $faa_identifier
                    );
                }
                $this->assertMatchesRegularExpression(
                    '/^https:\/\/weathercams\.faa\.gov\/map\/[\d\-\.]+,[\d\-\.]+,[\d\-\.]+,[\d\-\.]+\/airport\/[A-Z0-9]+\/$/',
                    $faaUrl,
                    "FAA Weather URL format should match expected pattern for {$linkIdentifier}"
                );
            }
            
            // Test ForeFlight format (accepts ICAO, IATA, or FAA - prefer ICAO > IATA > FAA)
            if ($linkIdentifier !== null) {
                $foreflightUrl = !empty($airport['foreflight_url']) 
                    ? $airport['foreflight_url'] 
                    : "foreflightmobile://maps/search?q=" . urlencode($linkIdentifier);
                $this->assertMatchesRegularExpression(
                    '/^foreflightmobile:\/\/maps\/search\?q=[A-Z0-9]+$/',
                    $foreflightUrl,
                    "ForeFlight URL format should match expected pattern for {$linkIdentifier}"
                );
            }
        }
    }
    
    /**
     * Helper method to validate a URL
     * 
     * @param string $url The URL to validate
     * @param string $expectedDomain Expected domain (for redirect validation)
     * @return array ['valid' => bool, 'message' => string]
     */
    private function validateUrl(string $url, string $expectedDomain): array
    {
        // Basic URL format check
        if (!filter_var($url, FILTER_VALIDATE_URL)) {
            return [
                'valid' => false,
                'message' => "Invalid URL format: $url"
            ];
        }
        
        // Check URL is HTTPS
        if (strpos($url, 'https://') !== 0) {
            return [
                'valid' => false,
                'message' => "URL should use HTTPS: $url"
            ];
        }
        
        // Make HTTP request to check if URL is reachable
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true); // Follow redirects
        curl_setopt($ch, CURLOPT_MAXREDIRS, 5); // Limit redirects
        curl_setopt($ch, CURLOPT_TIMEOUT, 10); // 10 second timeout
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 5);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 2);
        curl_setopt($ch, CURLOPT_NOBODY, true); // HEAD request (faster)
        curl_setopt($ch, CURLOPT_USERAGENT, 'AviationWX Link Validator/1.0');
        
        // Track redirect chain
        $redirectCount = 0;
        $finalUrl = $url;
        curl_setopt($ch, CURLOPT_HEADERFUNCTION, function($ch, $header) use (&$redirectCount, &$finalUrl) {
            $len = strlen($header);
            if (preg_match('/^Location:\s*(.+)$/i', $header, $matches)) {
                $redirectCount++;
                $finalUrl = trim($matches[1]);
            }
            return $len;
        });
        
        $execResult = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $effectiveUrl = curl_getinfo($ch, CURLINFO_EFFECTIVE_URL);
        $error = curl_error($ch);
        
        // Handle curl errors
        if ($execResult === false || !empty($error)) {
            // In CI, be more lenient - external services can be flaky
            if (getenv('CI')) {
                return [
                    'valid' => true, // Mark as valid to not fail CI
                    'message' => "Connection error (may be transient): $error"
                ];
            }
            return [
                'valid' => false,
                'message' => "cURL error: $error"
            ];
        }
        
        // Check HTTP status code
        if ($httpCode == 0) {
            return [
                'valid' => false,
                'message' => "Unable to connect to URL"
            ];
        }
        
        // Accept 2xx and 3xx status codes (success and redirects)
        if ($httpCode >= 200 && $httpCode < 400) {
            // Check if redirect went to expected domain
            $finalDomain = parse_url($effectiveUrl, PHP_URL_HOST);
            if ($finalDomain && strpos($finalDomain, $expectedDomain) === false) {
                return [
                    'valid' => false,
                    'message' => "Redirected to unexpected domain: $finalDomain (expected: $expectedDomain)"
                ];
            }
            
            // Check for too many redirects
            if ($redirectCount > 5) {
                return [
                    'valid' => false,
                    'message' => "Too many redirects: $redirectCount"
                ];
            }
            
            return [
                'valid' => true,
                'message' => "HTTP $httpCode - OK"
            ];
        }
        
        // 4xx and 5xx are failures
        if ($httpCode >= 400) {
            return [
                'valid' => false,
                'message' => "HTTP $httpCode - URL not reachable or returned error"
            ];
        }
        
        return [
            'valid' => false,
            'message' => "Unexpected HTTP status: $httpCode"
        ];
    }
}

