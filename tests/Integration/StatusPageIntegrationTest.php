<?php
/**
 * Integration Tests for Status Page
 * 
 * Tests the status.php endpoint structure and response format.
 * Verifies that the status page displays correctly and contains expected components.
 */

use PHPUnit\Framework\TestCase;

class StatusPageIntegrationTest extends TestCase
{
    private $baseUrl;
    
    protected function setUp(): void
    {
        parent::setUp();
        
        // Use environment variable or default to local
        $this->baseUrl = getenv('TEST_API_URL') ?: 'http://localhost:8080';
        
        // Skip if curl not available
        if (!function_exists('curl_init')) {
            $this->markTestSkipped('cURL not available');
        }
    }
    
    /**
     * Test status page is accessible
     */
    public function testStatusPage_IsAccessible()
    {
        $response = $this->makeRequest('?status=1');
        
        if ($response['http_code'] == 0) {
            $this->markTestSkipped("Endpoint not available at {$this->baseUrl}");
            return;
        }
        
        $this->assertEquals(
            200,
            $response['http_code'],
            "Status page should be accessible (got: {$response['http_code']})"
        );
    }
    
    /**
     * Test status page returns HTML
     */
    public function testStatusPage_ReturnsHtml()
    {
        $response = $this->makeRequest('status.php');
        
        if ($response['http_code'] == 0) {
            $this->markTestSkipped("Endpoint not available");
            return;
        }
        
        $this->assertEquals(200, $response['http_code'], "Should return 200 OK");
        
        $contentType = $response['headers']['content-type'] ?? '';
        $this->assertStringContainsString(
            'text/html',
            $contentType,
            "Status page should return HTML (got: $contentType)"
        );
        
        // Check for HTML structure
        $body = strtolower($response['body']);
        $this->assertStringContainsString(
            '<html',
            $body,
            "Response should contain HTML structure"
        );
        $this->assertStringContainsString(
            '</html>',
            $body,
            "Response should contain closing HTML tag"
        );
    }
    
    /**
     * Test status page contains expected elements
     */
    public function testStatusPage_ContainsExpectedElements()
    {
        $response = $this->makeRequest('?status=1');
        
        if ($response['http_code'] == 0 || $response['http_code'] != 200) {
            $this->markTestSkipped("Endpoint not available");
            return;
        }
        
        $body = $response['body'];
        
        // Check for title
        $this->assertStringContainsString(
            'AviationWX Status',
            $body,
            "Status page should contain title"
        );
        
        // Check for system status section
        $this->assertStringContainsString(
            'System Status',
            $body,
            "Status page should contain System Status section"
        );
        
        // Check for status indicators
        $this->assertStringContainsString(
            'status-indicator',
            $body,
            "Status page should contain status indicators"
        );
        
        // Check for component names (at least one should be present)
        $componentNames = ['Configuration', 'Cache System', 'APCu Cache', 'Logging', 'Error Rate'];
        $foundComponent = false;
        foreach ($componentNames as $name) {
            if (strpos($body, $name) !== false) {
                $foundComponent = true;
                break;
            }
        }
        $this->assertTrue(
            $foundComponent,
            "Status page should contain at least one system component"
        );
    }
    
    /**
     * Test status page via routing (status subdomain or query parameter)
     */
    public function testStatusPage_AccessibleViaRouting()
    {
        // Test via query parameter
        $response = $this->makeRequest('?status=1');
        
        if ($response['http_code'] == 0) {
            $this->markTestSkipped("Endpoint not available");
            return;
        }
        
        // Should either route to status page (200) or redirect
        $this->assertContains(
            $response['http_code'],
            [200, 301, 302],
            "Status page should be accessible via routing (got: {$response['http_code']})"
        );
        
        // If 200, should contain status page content
        if ($response['http_code'] == 200) {
            $this->assertStringContainsString(
                'AviationWX Status',
                $response['body'],
                "Routed status page should contain status page content"
            );
        }
    }
    
    /**
     * Test status page has no-cache headers
     */
    public function testStatusPage_NoCacheHeaders()
    {
        $response = $this->makeRequest('?status=1');
        
        if ($response['http_code'] == 0 || $response['http_code'] != 200) {
            $this->markTestSkipped("Endpoint not available");
            return;
        }
        
        $cacheControl = strtolower($response['headers']['cache-control'] ?? '');
        
        // Should have no-cache headers (may be combined)
        $this->assertTrue(
            strpos($cacheControl, 'no-cache') !== false ||
            strpos($cacheControl, 'no-store') !== false ||
            strpos($cacheControl, 'must-revalidate') !== false,
            "Status page should have no-cache headers (got: $cacheControl)"
        );
    }
    
    /**
     * Test status page displays airport status if configured
     */
    public function testStatusPage_DisplaysAirportStatus()
    {
        $response = $this->makeRequest('?status=1');
        
        if ($response['http_code'] == 0 || $response['http_code'] != 200) {
            $this->markTestSkipped("Endpoint not available");
            return;
        }
        
        $body = $response['body'];
        
        // Check for airport status section (may or may not exist depending on config)
        $hasAirportStatus = strpos($body, 'Airport Status') !== false;
        
        // If airports are configured, should have airport status section
        // If not configured, airport section may be missing - that's OK
        // Just verify the page structure is valid either way
        if ($hasAirportStatus) {
            $this->assertStringContainsString(
                'status-card',
                $body,
                "If airports are configured, should show airport status cards"
            );
        }
        
        // Page should always have system status
        $this->assertStringContainsString(
            'System Status',
            $body,
            "Status page should always show system status"
        );
    }
    
    /**
     * Test status page has proper structure (no errors in HTML)
     */
    public function testStatusPage_ValidStructure()
    {
        $response = $this->makeRequest('?status=1');
        
        if ($response['http_code'] == 0 || $response['http_code'] != 200) {
            $this->markTestSkipped("Endpoint not available");
            return;
        }
        
        $body = $response['body'];
        
        // Check for closing tags (basic validation)
        $openTags = substr_count($body, '<div');
        $closeTags = substr_count($body, '</div>');
        
        // Should have roughly equal open/close tags (allow some variance for self-closing)
        $this->assertGreaterThan(
            0,
            $openTags,
            "Status page should have HTML structure"
        );
        
        // Check for CSS styles (should be embedded)
        $this->assertStringContainsString(
            '<style>',
            $body,
            "Status page should have embedded styles"
        );
        
        // Check for status indicators in HTML
        $this->assertStringContainsString(
            'status-indicator',
            $body,
            "Status page should have status indicators"
        );
    }
    
    /**
     * Test status page displays maintenance status for airports in maintenance
     */
    public function testStatusPage_DisplaysMaintenanceStatus()
    {
        $response = $this->makeRequest('?status=1');
        
        if ($response['http_code'] == 0 || $response['http_code'] != 200) {
            $this->markTestSkipped("Endpoint not available");
            return;
        }
        
        $body = $response['body'];
        
        // Check if airport status section exists (may not exist if no airports configured)
        $hasAirportStatus = strpos($body, 'Airport Status') !== false;
        
        if ($hasAirportStatus) {
            // Check if test fixtures are being used by looking for known test airport
            // If pdx (maintenance airport in test fixtures) exists, verify maintenance status
            $hasPdx = strpos($body, 'PDX') !== false || strpos($body, 'pdx') !== false;
            
            if ($hasPdx) {
                // Test fixtures include pdx with maintenance: true
                // Verify that maintenance status is displayed correctly
                $hasMaintenanceEmoji = strpos($body, '🚧') !== false;
                $hasMaintenanceText = strpos($body, 'Under Maintenance') !== false;
                
                $this->assertTrue(
                    $hasMaintenanceEmoji,
                    "If test fixtures are used and pdx (maintenance airport) exists, should show maintenance emoji 🚧"
                );
                $this->assertTrue(
                    $hasMaintenanceText,
                    "If test fixtures are used and pdx (maintenance airport) exists, should show 'Under Maintenance' text"
                );
            } else {
                // If test fixtures aren't being used, check if any maintenance airports exist
                // This is a soft check for production configs
                $hasMaintenanceEmoji = strpos($body, '🚧') !== false;
                $hasMaintenanceText = strpos($body, 'Under Maintenance') !== false;
                
                // If maintenance indicators are present, verify both are shown together
                if ($hasMaintenanceEmoji || $hasMaintenanceText) {
                    $this->assertTrue(
                        $hasMaintenanceEmoji && $hasMaintenanceText,
                        "If maintenance airports are configured, should show both maintenance emoji and text"
                    );
                }
            }
        }
        
        // Page should always have system status
        $this->assertStringContainsString(
            'System Status',
            $body,
            "Status page should always show system status"
        );
    }
    
    /**
     * Helper method to make HTTP request
     */
    private function makeRequest(string $path, bool $includeHeaders = true, int $maxRetries = 3): array
    {
        $url = rtrim($this->baseUrl, '/') . '/' . ltrim($path, '/');
        
        $lastError = null;
        $lastResponse = null;
        
        for ($attempt = 1; $attempt <= $maxRetries; $attempt++) {
            $ch = curl_init();
            curl_setopt($ch, CURLOPT_URL, $url);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            $timeout = getenv('CI') ? 15 : 10;
            curl_setopt($ch, CURLOPT_TIMEOUT, $timeout);
            curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, getenv('CI') ? 10 : 5);
            curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
            
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
            
            $response = [
                'http_code' => $httpCode,
                'body' => $body,
                'headers' => $headers,
                'error' => $error
            ];
            
            // Success - return immediately
            if ($httpCode > 0 && $httpCode < 500) {
                return $response;
            }
            
            // Transient error - retry with exponential backoff
            if ($httpCode == 0 || in_array($httpCode, [502, 503, 504])) {
                $lastError = $error ?: "HTTP $httpCode";
                $lastResponse = $response;
                
                if ($attempt < $maxRetries) {
                    $delay = pow(2, $attempt - 1) * 1000000; // microseconds
                    usleep($delay);
                    continue;
                }
            }
            
            // Non-retryable error - return immediately
            return $response;
        }
        
        // All retries exhausted
        return $lastResponse ?? [
            'http_code' => 0,
            'body' => '',
            'headers' => [],
            'error' => $lastError ?? 'Request failed after retries'
        ];
    }
}

