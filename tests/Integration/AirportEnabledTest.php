<?php
/**
 * Integration Tests for Airport Enabled/Maintenance Features
 * 
 * Tests the enabled and maintenance behavior across the application.
 */

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../Helpers/TestHelper.php';

class AirportEnabledTest extends TestCase
{
    private $baseUrl;
    
    protected function setUp(): void
    {
        parent::setUp();
        $this->baseUrl = getenv('TEST_BASE_URL') ?: 'http://localhost:8080';
    }
    
    /**
     * Test disabled airport returns 404
     */
    public function testDisabledAirport_Returns404(): void
    {
        // ksea is disabled in test fixtures
        $response = $this->makeRequest('?airport=ksea');
        
        if ($response['http_code'] == 0) {
            $this->markTestSkipped("Endpoint not available");
            return;
        }
        
        $this->assertEquals(
            404,
            $response['http_code'],
            "Disabled airport should return 404 (got: {$response['http_code']})"
        );
    }
    
    /**
     * Test enabled airport without maintenance works normally
     */
    public function testEnabledAirport_WithoutMaintenance_WorksNormally(): void
    {
        // kspb is enabled and not in maintenance in test fixtures
        $response = $this->makeRequest('?airport=kspb');
        
        if ($response['http_code'] == 0) {
            $this->markTestSkipped("Endpoint not available");
            return;
        }
        
        $this->assertEquals(
            200,
            $response['http_code'],
            "Enabled airport should return 200 (got: {$response['http_code']})"
        );
        
        $html = $response['body'];
        $this->assertStringNotContainsString(
            'maintenance',
            strtolower($html),
            "Enabled airport without maintenance should not show maintenance banner"
        );
    }
    
    /**
     * Test enabled airport with maintenance shows banner
     */
    public function testEnabledAirport_WithMaintenance_ShowsBanner(): void
    {
        // pdx is enabled and in maintenance in test fixtures
        $response = $this->makeRequest('?airport=pdx');
        
        if ($response['http_code'] == 0) {
            $this->markTestSkipped("Endpoint not available");
            return;
        }
        
        $this->assertEquals(
            200,
            $response['http_code'],
            "Enabled airport in maintenance should return 200 (got: {$response['http_code']})"
        );
        
        $html = $response['body'];
        $this->assertStringContainsString(
            'maintenance-banner',
            $html,
            "Airport in maintenance should show maintenance banner"
        );
        
        $this->assertStringContainsString(
            'This airport is currently under maintenance',
            $html,
            "Maintenance banner should contain correct message"
        );
    }
    
    /**
     * Test homepage only lists enabled airports
     */
    public function testHomepage_OnlyListsEnabledAirports(): void
    {
        $response = $this->makeRequest('/');
        
        if ($response['http_code'] == 0 || $response['http_code'] != 200) {
            $this->markTestSkipped("Homepage not available");
            return;
        }
        
        $html = $response['body'];
        
        // kspb is enabled - should appear
        $this->assertStringContainsString(
            'kspb',
            strtolower($html),
            "Homepage should list enabled airport kspb"
        );
        
        // ksea is disabled - should not appear
        $this->assertStringNotContainsString(
            'ksea',
            strtolower($html),
            "Homepage should not list disabled airport ksea"
        );
    }
    
    /**
     * Test weather API returns 404 for disabled airports
     */
    public function testWeatherApi_DisabledAirport_Returns404(): void
    {
        // ksea is disabled in test fixtures
        $response = $this->makeRequest('/api/weather.php?airport=ksea');
        
        if ($response['http_code'] == 0) {
            $this->markTestSkipped("Weather API not available");
            return;
        }
        
        $this->assertEquals(
            404,
            $response['http_code'],
            "Weather API should return 404 for disabled airport (got: {$response['http_code']})"
        );
        
        $data = json_decode($response['body'], true);
        $this->assertIsArray($data);
        $this->assertFalse($data['success'] ?? true);
    }
    
    /**
     * Test webcam API returns placeholder for disabled airports
     */
    public function testWebcamApi_DisabledAirport_ReturnsPlaceholder(): void
    {
        // ksea is disabled in test fixtures
        $response = $this->makeRequest('/api/webcam.php?airport=ksea&cam=0');
        
        if ($response['http_code'] == 0) {
            $this->markTestSkipped("Webcam API not available");
            return;
        }
        
        // Webcam API serves placeholder for disabled airports (doesn't return 404)
        // We just verify it doesn't crash
        $this->assertNotEquals(500, $response['http_code'], "Webcam API should not return 500 for disabled airport");
    }
    
    /**
     * Test sitemap only includes enabled airports
     */
    public function testSitemap_OnlyIncludesEnabledAirports(): void
    {
        $response = $this->makeRequest('/api/sitemap.php');
        
        if ($response['http_code'] == 0 || $response['http_code'] != 200) {
            $this->markTestSkipped("Sitemap not available");
            return;
        }
        
        $xml = $response['body'];
        
        // kspb is enabled - should appear in sitemap
        $this->assertStringContainsString(
            'kspb',
            strtolower($xml),
            "Sitemap should include enabled airport kspb"
        );
        
        // ksea is disabled - should not appear in sitemap
        $this->assertStringNotContainsString(
            'ksea',
            strtolower($xml),
            "Sitemap should not include disabled airport ksea"
        );
    }
    
    /**
     * Helper method to make HTTP requests
     */
    private function makeRequest(string $path): array
    {
        $url = rtrim($this->baseUrl, '/') . '/' . ltrim($path, '/');
        
        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_TIMEOUT => 10,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_HEADER => false,
        ]);
        
        $body = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);
        
        if ($error) {
            return ['http_code' => 0, 'body' => '', 'error' => $error];
        }
        
        return ['http_code' => $httpCode, 'body' => $body ?: ''];
    }
}

