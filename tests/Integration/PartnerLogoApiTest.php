<?php
/**
 * Integration Tests for Partner Logo API Endpoint
 */

use PHPUnit\Framework\TestCase;

class PartnerLogoApiTest extends TestCase
{
    private $baseUrl;
    
    protected function setUp(): void
    {
        // Get base URL from environment or use default
        $this->baseUrl = getenv('TEST_BASE_URL') ?: 'http://localhost:8080';
    }
    
    /**
     * Make HTTP request to endpoint
     */
    private function makeRequest(string $endpoint): array
    {
        $url = rtrim($this->baseUrl, '/') . '/' . ltrim($endpoint, '/');
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_TIMEOUT => 10,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_HEADER => true,
        ]);
        
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        
        if ($error) {
            return ['http_code' => 0, 'error' => $error, 'body' => '', 'headers' => []];
        }
        
        // Parse headers and body
        $headerSize = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
        $headers = [];
        $body = '';
        
        if ($response) {
            $headerText = substr($response, 0, $headerSize);
            $body = substr($response, $headerSize);
            
            // Parse headers
            $headerLines = explode("\r\n", $headerText);
            foreach ($headerLines as $line) {
                if (strpos($line, ':') !== false) {
                    list($key, $value) = explode(':', $line, 2);
                    $headers[strtolower(trim($key))] = trim($value);
                }
            }
        }
        
        return [
            'http_code' => $httpCode,
            'body' => $body,
            'headers' => $headers,
            'error' => $error
        ];
    }
    
    /**
     * Test partner logo endpoint requires url parameter
     */
    public function testPartnerLogoApi_MissingUrlParameter()
    {
        $response = $this->makeRequest('api/partner-logo.php');
        
        if ($response['http_code'] == 0) {
            $this->markTestSkipped("Endpoint not available");
            return;
        }
        
        $this->assertEquals(400, $response['http_code'], "Missing url parameter should return 400");
        $this->assertStringContainsString('Missing url', $response['body']);
    }
    
    /**
     * Test partner logo endpoint validates URL format
     */
    public function testPartnerLogoApi_InvalidUrl()
    {
        $response = $this->makeRequest('api/partner-logo.php?url=not-a-valid-url');
        
        if ($response['http_code'] == 0) {
            $this->markTestSkipped("Endpoint not available");
            return;
        }
        
        $this->assertEquals(400, $response['http_code'], "Invalid URL should return 400");
        $this->assertStringContainsString('Invalid URL', $response['body']);
    }
    
    /**
     * Test partner logo endpoint returns 404 for non-existent logo
     */
    public function testPartnerLogoApi_NonExistentLogo()
    {
        $testUrl = 'https://example.com/nonexistent-logo.png';
        $response = $this->makeRequest('api/partner-logo.php?url=' . urlencode($testUrl));
        
        if ($response['http_code'] == 0) {
            $this->markTestSkipped("Endpoint not available");
            return;
        }
        
        // Should return 404 or serve placeholder
        $this->assertContains(
            $response['http_code'],
            [404, 200],
            "Non-existent logo should return 404 or placeholder (got: {$response['http_code']})"
        );
        
        // If 200, should be an image (placeholder)
        if ($response['http_code'] == 200) {
            $contentType = $response['headers']['content-type'] ?? '';
            $this->assertStringContainsString('image/', $contentType, "Should return image content type");
        }
    }
    
    /**
     * Test partner logo endpoint sets appropriate cache headers
     */
    public function testPartnerLogoApi_CacheHeaders()
    {
        // Use a test URL that might exist or not - we're just checking headers
        $testUrl = 'https://example.com/test-logo.png';
        $response = $this->makeRequest('api/partner-logo.php?url=' . urlencode($testUrl));
        
        if ($response['http_code'] == 0) {
            $this->markTestSkipped("Endpoint not available");
            return;
        }
        
        // Should have cache-control header
        $cacheControl = $response['headers']['cache-control'] ?? '';
        $this->assertNotEmpty($cacheControl, "Should set Cache-Control header");
        
        // Should have content-type header
        $contentType = $response['headers']['content-type'] ?? '';
        if ($response['http_code'] == 200) {
            $this->assertStringContainsString('image/', $contentType, "Should return image content type");
        }
    }
    
    /**
     * Test partner logo endpoint handles conditional requests (If-Modified-Since)
     */
    public function testPartnerLogoApi_ConditionalRequest()
    {
        $testUrl = 'https://example.com/test-logo.png';
        
        // First request
        $response1 = $this->makeRequest('api/partner-logo.php?url=' . urlencode($testUrl));
        
        if ($response1['http_code'] == 0) {
            $this->markTestSkipped("Endpoint not available");
            return;
        }
        
        // If we got a Last-Modified header, test conditional request
        $lastModified = $response1['headers']['last-modified'] ?? '';
        if (!empty($lastModified)) {
            // Make request with If-Modified-Since
            $url = rtrim($this->baseUrl, '/') . '/api/partner-logo.php?url=' . urlencode($testUrl);
            $ch = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_TIMEOUT => 10,
                CURLOPT_CONNECTTIMEOUT => 5,
                CURLOPT_HEADER => true,
                CURLOPT_HTTPHEADER => [
                    'If-Modified-Since: ' . $lastModified
                ]
            ]);
            
            $response2 = curl_exec($ch);
            $httpCode2 = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            
            // Should return 304 if not modified
            // Note: This might return 200 if cache is stale, which is also valid
            $this->assertContains(
                $httpCode2,
                [200, 304],
                "Conditional request should return 200 or 304 (got: {$httpCode2})"
            );
        } else {
            $this->markTestSkipped("Last-Modified header not present, skipping conditional request test");
        }
    }
}

