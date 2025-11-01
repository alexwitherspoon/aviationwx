<?php
/**
 * Smoke Tests for Production Endpoint
 * 
 * Basic health checks and endpoint availability tests.
 * These tests verify that critical endpoints are accessible and functioning.
 * 
 * These tests can be run against production to verify deployment health.
 */

use PHPUnit\Framework\TestCase;

class SmokeTest extends TestCase
{
    private $baseUrl;
    
    protected function setUp(): void
    {
        parent::setUp();
        
        // Use environment variable or default to local
        // Set TEST_PROD_URL=https://aviationwx.org for production testing (manual only)
        // Default: localhost for automatic CI/CD runs
        $this->baseUrl = getenv('TEST_PROD_URL') ?: getenv('TEST_API_URL') ?: 'http://localhost:8080';
        
        if (!function_exists('curl_init')) {
            $this->markTestSkipped('cURL not available');
        }
    }
    
    /**
     * Test homepage is accessible
     */
    public function testHomepage_IsAccessible()
    {
        $response = $this->makeRequest('');
        
        if ($response['http_code'] == 0) {
            $this->markTestSkipped("Endpoint not available at {$this->baseUrl}");
            return;
        }
        
        $this->assertContains(
            $response['http_code'],
            [200, 301, 302],
            "Homepage should be accessible (got: {$response['http_code']})"
        );
    }
    
    /**
     * Test health endpoint (if available)
     */
    public function testHealthEndpoint_IsAccessible()
    {
        $response = $this->makeRequest('health.php');
        
        // Health endpoint may not exist, so skip if 404
        if ($response['http_code'] == 404) {
            $this->markTestSkipped('Health endpoint not available');
            return;
        }
        
        if ($response['http_code'] == 0) {
            $this->markTestSkipped("Endpoint not available");
            return;
        }
        
        $this->assertEquals(
            200,
            $response['http_code'],
            "Health endpoint should return 200"
        );
    }
    
    /**
     * Test weather API endpoint is accessible
     */
    public function testWeatherEndpoint_IsAccessible()
    {
        $response = $this->makeRequest('weather.php?airport=kspb');
        
        if ($response['http_code'] == 0) {
            $this->markTestSkipped("Weather endpoint not available at {$this->baseUrl}");
            return;
        }
        
        // Should return 200 (success) or 400/404 (validation error, but endpoint works)
        $this->assertContains(
            $response['http_code'],
            [200, 400, 404, 429],
            "Weather endpoint should be accessible (got: {$response['http_code']})"
        );
        
        // If we got a response, check it's JSON
        if (!empty($response['body'])) {
            $contentType = $response['headers']['content-type'] ?? '';
            if ($response['http_code'] == 200) {
                $this->assertStringContainsString(
                    'application/json',
                    $contentType,
                    "Weather endpoint should return JSON"
                );
                
                $data = json_decode($response['body'], true);
                $this->assertNotNull($data, "Response should be valid JSON");
            }
        }
    }
    
    /**
     * Test airport page is accessible
     */
    public function testAirportPage_IsAccessible()
    {
        $response = $this->makeRequest('?airport=kspb');
        
        if ($response['http_code'] == 0) {
            $this->markTestSkipped("Endpoint not available");
            return;
        }
        
        // Should return 200 (found) or 404 (not found, but endpoint works)
        $this->assertContains(
            $response['http_code'],
            [200, 404],
            "Airport page endpoint should be accessible (got: {$response['http_code']})"
        );
        
        // If 200, should be HTML
        if ($response['http_code'] == 200 && !empty($response['body'])) {
            $this->assertStringContainsString(
                '<html',
                strtolower($response['body']),
                "Airport page should return HTML"
            );
        }
    }
    
    /**
     * Test webcam endpoint is accessible
     */
    public function testWebcamEndpoint_IsAccessible()
    {
        $response = $this->makeRequest('webcam.php?airport=kspb&index=0');
        
        if ($response['http_code'] == 0) {
            $this->markTestSkipped("Endpoint not available");
            return;
        }
        
        // Should return 200 (image) or 404 (not found)
        $this->assertContains(
            $response['http_code'],
            [200, 404],
            "Webcam endpoint should be accessible (got: {$response['http_code']})"
        );
        
        // If 200, should be an image
        if ($response['http_code'] == 200) {
            $contentType = $response['headers']['content-type'] ?? '';
            $this->assertStringContainsString(
                'image/',
                $contentType,
                "Webcam endpoint should return image (got: $contentType)"
            );
        }
    }
    
    /**
     * Test SSL/HTTPS (if production URL)
     */
    public function testHttps_IsEnabled()
    {
        // Only test if using HTTPS URL
        if (!str_starts_with($this->baseUrl, 'https://')) {
            $this->markTestSkipped('Not testing HTTPS (using HTTP URL)');
            return;
        }
        
        $response = $this->makeRequest('');
        
        if ($response['http_code'] == 0) {
            $this->markTestSkipped("Endpoint not available");
            return;
        }
        
        // HTTPS should work without certificate errors
        $this->assertNotEquals(
            'CURLE_SSL_CONNECT_ERROR',
            curl_error($ch ?? null),
            "HTTPS should work without SSL errors"
        );
    }
    
    /**
     * Test response time is acceptable
     */
    public function testResponseTime_IsAcceptable()
    {
        $start = microtime(true);
        $response = $this->makeRequest('weather.php?airport=kspb');
        $elapsed = microtime(true) - $start;
        
        if ($response['http_code'] == 0) {
            $this->markTestSkipped("Endpoint not available");
            return;
        }
        
        // Production responses should be reasonably fast
        // Allow more time for first request (cache warming)
        $maxTime = 5.0; // 5 seconds max
        
        $this->assertLessThan(
            $maxTime,
            $elapsed,
            "Endpoint should respond in reasonable time (took: {$elapsed}s, max: {$maxTime}s)"
        );
    }
    
    /**
     * Helper method to make HTTP request
     */
    private function makeRequest(string $path, bool $includeHeaders = true): array
    {
        $url = rtrim($this->baseUrl, '/') . '/' . ltrim($path, '/');
        
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 10);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 5);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 2);
        
        $headers = [];
        if ($includeHeaders) {
            curl_setopt($ch, CURLOPT_HEADERFUNCTION, function($ch, $header) use (&$headers) {
                $len = strlen($header);
                $header = explode(':', $header, 2);
                if (count($header) == 2) {
                    $headers[strtolower(trim($header[0]))] = trim($header[1]);
                }
                return $len;
            });
        }
        
        $body = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);
        
        return [
            'http_code' => $httpCode,
            'body' => $body,
            'headers' => $headers,
            'error' => $error
        ];
    }
}

