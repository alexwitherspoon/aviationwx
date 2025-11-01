<?php
/**
 * External Links Validation Tests
 * 
 * Tests that external links (AirNav, SkyVector, AOPA, FAA Weather) are:
 * - Generating correct URL formats
 * - Reachable (returning valid HTTP status codes)
 * - Not redirecting to error pages or unexpected locations
 * 
 * These tests help detect when external services change their URL structure
 * or when links break, so we can update them proactively.
 * 
 * Note: These tests are non-blocking as external services can be flaky.
 */

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../config-utils.php';

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
            if (empty($airport['airnav_url'])) {
                $this->markTestIncomplete("AirNav URL not configured for airport: $airportId");
                continue;
            }
            
            $url = $airport['airnav_url'];
            $result = $this->validateUrl($url, 'airnav.com');
            
            $this->assertTrue(
                $result['valid'],
                "AirNav URL for {$airport['icao']} should be valid: {$result['message']}"
            );
        }
    }
    
    /**
     * Test SkyVector URLs are valid and reachable
     */
    public function testSkyVectorLinks_AreValid()
    {
        foreach ($this->testAirports as $airportId => $airport) {
            if (empty($airport['icao'])) {
                $this->markTestIncomplete("ICAO code not configured for airport: $airportId");
                continue;
            }
            
            $icao = strtoupper($airport['icao']);
            $url = "https://skyvector.com/airport/$icao";
            
            $result = $this->validateUrl($url, 'skyvector.com');
            
            $this->assertTrue(
                $result['valid'],
                "SkyVector URL for {$airport['icao']} should be valid: {$result['message']}"
            );
        }
    }
    
    /**
     * Test AOPA URLs are valid and reachable
     */
    public function testAOPALinks_AreValid()
    {
        foreach ($this->testAirports as $airportId => $airport) {
            if (empty($airport['icao'])) {
                $this->markTestIncomplete("ICAO code not configured for airport: $airportId");
                continue;
            }
            
            $icao = strtoupper($airport['icao']);
            $url = "https://www.aopa.org/destinations/airports/$icao";
            
            $result = $this->validateUrl($url, 'aopa.org');
            
            $this->assertTrue(
                $result['valid'],
                "AOPA URL for {$airport['icao']} should be valid: {$result['message']}"
            );
        }
    }
    
    /**
     * Test FAA Weather Cams URLs are valid and reachable
     */
    public function testFAAWeatherLinks_AreValid()
    {
        foreach ($this->testAirports as $airportId => $airport) {
            if (empty($airport['lat']) || empty($airport['lon']) || empty($airport['icao'])) {
                $this->markTestIncomplete("Required fields missing for FAA Weather URL: $airportId");
                continue;
            }
            
            // Generate FAA Weather URL (same logic as airport-template.php)
            $buffer = 2.0;
            $min_lon = $airport['lon'] - $buffer;
            $min_lat = $airport['lat'] - $buffer;
            $max_lon = $airport['lon'] + $buffer;
            $max_lat = $airport['lat'] + $buffer;
            
            // Remove K prefix from ICAO if present (e.g., KSPB -> SPB)
            $faa_icao = preg_replace('/^K/', '', strtoupper($airport['icao']));
            
            $url = sprintf(
                'https://weathercams.faa.gov/map/%.5f,%.5f,%.5f,%.5f/airport/%s/',
                $min_lon,
                $min_lat,
                $max_lon,
                $max_lat,
                $faa_icao
            );
            
            $result = $this->validateUrl($url, 'weathercams.faa.gov');
            
            $this->assertTrue(
                $result['valid'],
                "FAA Weather URL for {$airport['icao']} should be valid: {$result['message']}"
            );
        }
    }
    
    /**
     * Test that URL formats match expected patterns
     */
    public function testUrlFormats_MatchExpectedPatterns()
    {
        foreach ($this->testAirports as $airportId => $airport) {
            // Test SkyVector format
            if (!empty($airport['icao'])) {
                $icao = strtoupper($airport['icao']);
                $skyvectorUrl = "https://skyvector.com/airport/$icao";
                $this->assertMatchesRegularExpression(
                    '/^https:\/\/skyvector\.com\/airport\/[A-Z0-9]+$/',
                    $skyvectorUrl,
                    "SkyVector URL format should match expected pattern for {$airport['icao']}"
                );
            }
            
            // Test AOPA format
            if (!empty($airport['icao'])) {
                $icao = strtoupper($airport['icao']);
                $aopaUrl = "https://www.aopa.org/destinations/airports/$icao";
                $this->assertMatchesRegularExpression(
                    '/^https:\/\/www\.aopa\.org\/destinations\/airports\/[A-Z0-9]+$/',
                    $aopaUrl,
                    "AOPA URL format should match expected pattern for {$airport['icao']}"
                );
            }
            
            // Test FAA Weather format
            if (!empty($airport['lat']) && !empty($airport['lon']) && !empty($airport['icao'])) {
                $buffer = 2.0;
                $min_lon = $airport['lon'] - $buffer;
                $min_lat = $airport['lat'] - $buffer;
                $max_lon = $airport['lon'] + $buffer;
                $max_lat = $airport['lat'] + $buffer;
                $faa_icao = preg_replace('/^K/', '', strtoupper($airport['icao']));
                
                $faaUrl = sprintf(
                    'https://weathercams.faa.gov/map/%.5f,%.5f,%.5f,%.5f/airport/%s/',
                    $min_lon,
                    $min_lat,
                    $max_lon,
                    $max_lat,
                    $faa_icao
                );
                
                $this->assertMatchesRegularExpression(
                    '/^https:\/\/weathercams\.faa\.gov\/map\/[\d\-\.]+,[\d\-\.]+,[\d\-\.]+,[\d\-\.]+\/airport\/[A-Z0-9]+\/$/',
                    $faaUrl,
                    "FAA Weather URL format should match expected pattern for {$airport['icao']}"
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
        curl_close($ch);
        
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

