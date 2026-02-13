<?php
/**
 * Integration Tests for Public API Endpoints
 * 
 * These tests verify the API endpoints work end-to-end.
 * Requires a running server (via Docker or PHP dev server).
 */

use PHPUnit\Framework\TestCase;

class PublicApiIntegrationTest extends TestCase
{
    private static $apiBaseUrl;
    private static $apiEnabled;
    
    public static function setUpBeforeClass(): void
    {
        // Get base URL from environment or use default
        self::$apiBaseUrl = getenv('TEST_API_URL') ?: 'http://localhost:8080';
        
        // Check if API is accessible
        $ch = curl_init(self::$apiBaseUrl . '/api/v1/status');
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 5);
        curl_setopt($ch, CURLOPT_HTTPHEADER, ['X-Health-Check: internal']);
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        
        // API is enabled if we get 200 or 404 with API_NOT_ENABLED error
        // (404 with API_NOT_ENABLED means the router works but API is disabled)
        self::$apiEnabled = ($httpCode === 200);
        
        if (!self::$apiEnabled) {
            // Check if server is running at all
            $ch = curl_init(self::$apiBaseUrl);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_TIMEOUT, 5);
            $rootResponse = curl_exec($ch);
            $rootCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            
            if ($rootCode === 0) {
                self::markTestSkipped('Test server not running at ' . self::$apiBaseUrl);
            }
        }
    }
    
    /**
     * Skip test if API is not enabled
     */
    private function skipIfApiDisabled(): void
    {
        if (!self::$apiEnabled) {
            $this->markTestSkipped('Public API is not enabled in configuration');
        }
    }
    
    /**
     * Make an API request
     */
    private function apiRequest(string $endpoint, array $headers = []): array
    {
        $url = self::$apiBaseUrl . '/api/v1' . $endpoint;
        
        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 10);
        curl_setopt($ch, CURLOPT_HTTPHEADER, array_merge([
            'Accept: application/json'
        ], $headers));
        curl_setopt($ch, CURLOPT_HEADER, true);
        
        $response = curl_exec($ch);
        $headerSize = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        
        $headerStr = substr($response, 0, $headerSize);
        $body = substr($response, $headerSize);
        
        // Parse headers
        $responseHeaders = [];
        foreach (explode("\r\n", $headerStr) as $line) {
            if (strpos($line, ':') !== false) {
                list($key, $value) = explode(':', $line, 2);
                $responseHeaders[trim($key)] = trim($value);
            }
        }
        
        return [
            'code' => $httpCode,
            'headers' => $responseHeaders,
            'body' => $body,
            'json' => json_decode($body, true)
        ];
    }
    
    /**
     * GET /v1/status should return API status
     */
    public function testStatusEndpoint_ReturnsStatus(): void
    {
        $this->skipIfApiDisabled();
        
        $response = $this->apiRequest('/status');
        
        $this->assertEquals(200, $response['code']);
        $this->assertNotNull($response['json']);
        $this->assertTrue($response['json']['success']);
        $this->assertArrayHasKey('status', $response['json']);
        $this->assertArrayHasKey('status', $response['json']['status']);
    }
    
    /**
     * GET /v1/airports should return list of airports
     */
    public function testListAirports_ReturnsArray(): void
    {
        $this->skipIfApiDisabled();
        
        $response = $this->apiRequest('/airports');
        
        $this->assertEquals(200, $response['code']);
        $this->assertNotNull($response['json']);
        $this->assertTrue($response['json']['success']);
        $this->assertArrayHasKey('airports', $response['json']);
        $this->assertIsArray($response['json']['airports']);
    }
    
    /**
     * API responses should include rate limit headers
     */
    public function testRateLimitHeaders_Present(): void
    {
        $this->skipIfApiDisabled();
        
        $response = $this->apiRequest('/status');
        
        $this->assertArrayHasKey('X-RateLimit-Limit', $response['headers']);
        $this->assertArrayHasKey('X-RateLimit-Remaining', $response['headers']);
        $this->assertArrayHasKey('X-RateLimit-Reset', $response['headers']);
    }
    
    /**
     * API responses should include CORS headers
     */
    public function testCorsHeaders_Present(): void
    {
        $this->skipIfApiDisabled();
        
        $response = $this->apiRequest('/status');
        
        $this->assertArrayHasKey('Access-Control-Allow-Origin', $response['headers']);
        $this->assertEquals('*', $response['headers']['Access-Control-Allow-Origin']);
    }
    
    /**
     * API responses should include proper content type
     */
    public function testContentType_Json(): void
    {
        $this->skipIfApiDisabled();
        
        $response = $this->apiRequest('/status');
        
        $this->assertArrayHasKey('Content-Type', $response['headers']);
        $this->assertStringContainsString('application/json', $response['headers']['Content-Type']);
    }
    
    /**
     * Invalid airport should return 404
     */
    public function testGetAirport_NotFound(): void
    {
        $this->skipIfApiDisabled();
        
        $response = $this->apiRequest('/airports/notexist123');
        
        $this->assertEquals(404, $response['code']);
        $this->assertNotNull($response['json']);
        $this->assertFalse($response['json']['success']);
        $this->assertEquals('AIRPORT_NOT_FOUND', $response['json']['error']['code']);
    }
    
    /**
     * Unknown endpoint should return 404
     */
    public function testUnknownEndpoint_Returns404(): void
    {
        $this->skipIfApiDisabled();
        
        $response = $this->apiRequest('/unknown/endpoint');
        
        $this->assertEquals(404, $response['code']);
        $this->assertNotNull($response['json']);
        $this->assertFalse($response['json']['success']);
    }
    
    /**
     * API should return attribution in meta
     */
    public function testAttribution_InMeta(): void
    {
        $this->skipIfApiDisabled();
        
        $response = $this->apiRequest('/airports');
        
        $this->assertArrayHasKey('meta', $response['json']);
        $this->assertArrayHasKey('attribution', $response['json']['meta']);
        $this->assertNotEmpty($response['json']['meta']['attribution']);
    }
    
    /**
     * Bulk weather with no airports parameter should return error
     */
    public function testWeatherBulk_RequiresAirportsParam(): void
    {
        $this->skipIfApiDisabled();
        
        $response = $this->apiRequest('/weather/bulk');
        
        $this->assertEquals(400, $response['code']);
        $this->assertNotNull($response['json']);
        $this->assertFalse($response['json']['success']);
        $this->assertEquals('INVALID_REQUEST', $response['json']['error']['code']);
    }
}

