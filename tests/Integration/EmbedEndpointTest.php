<?php
/**
 * Integration Tests for Embed Endpoints
 * Tests embed configurator and embed renderer endpoints
 */

use PHPUnit\Framework\TestCase;

class EmbedEndpointTest extends TestCase
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
     * Test embed configurator page is accessible
     */
    public function testEmbedConfigurator_IsAccessible()
    {
        $response = $this->makeRequest('?embed');
        
        if ($response['http_code'] == 0) {
            $this->markTestSkipped("Endpoint not available at {$this->baseUrl}");
            return;
        }
        
        $this->assertEquals(
            200,
            $response['http_code'],
            "Embed configurator should return 200 (got: {$response['http_code']})"
        );
        
        // Should be HTML
        $this->assertStringContainsString(
            '<html',
            strtolower($response['body']),
            "Embed configurator should return HTML"
        );
        
        // Should contain configurator elements
        $this->assertStringContainsString(
            'Embed Generator',
            $response['body'],
            "Page should contain 'Embed Generator' title"
        );
    }
    
    /**
     * Test embed configurator contains required UI elements
     */
    public function testEmbedConfigurator_HasRequiredElements()
    {
        $response = $this->makeRequest('?embed');
        
        if ($response['http_code'] == 0) {
            $this->markTestSkipped("Endpoint not available");
            return;
        }
        
        // Check for widget style options
        $this->assertStringContainsString(
            'Mini Airport Card',
            $response['body'],
            "Should have Mini Airport Card style option"
        );
        
        $this->assertStringContainsString(
            'Single Webcam',
            $response['body'],
            "Should have Single Webcam style option"
        );
        
        $this->assertStringContainsString(
            'Dual Camera',
            $response['body'],
            "Should have Dual Camera style option"
        );
        
        $this->assertStringContainsString(
            '4 Camera Grid',
            $response['body'],
            "Should have 4 Camera Grid style option"
        );
        
        $this->assertStringContainsString(
            'Full Widget',
            $response['body'],
            "Should have Full Widget style option"
        );
        
        // Check for theme options
        $this->assertStringContainsString(
            'Theme',
            $response['body'],
            "Should have Theme section"
        );
        
        // Check for embed code section
        $this->assertStringContainsString(
            'iframe',
            $response['body'],
            "Should have iframe embed option"
        );
    }
    
    /**
     * Test embed renderer with card style
     */
    public function testEmbedRenderer_CardStyle()
    {
        $response = $this->makeRequest('?embed&airport=kspb&style=card&theme=light&render=1');
        
        if ($response['http_code'] == 0) {
            $this->markTestSkipped("Endpoint not available");
            return;
        }
        
        // Allow 200 (success) or 404 (airport not found in test env)
        $this->assertContains(
            $response['http_code'],
            [200, 404],
            "Embed renderer should return 200 or 404 (got: {$response['http_code']})"
        );
        
        if ($response['http_code'] == 200) {
            // Should contain card styling
            $this->assertStringContainsString(
                'style-card',
                $response['body'],
                "Should render card style embed"
            );
            
            // Should contain weather data elements
            $this->assertStringContainsString(
                'Wind',
                $response['body'],
                "Should display wind data"
            );
        }
    }
    
    /**
     * Test embed renderer with webcam style
     */
    public function testEmbedRenderer_WebcamStyle()
    {
        $response = $this->makeRequest('?embed&airport=kspb&style=webcam&theme=light&webcam=0&render=1');
        
        if ($response['http_code'] == 0) {
            $this->markTestSkipped("Endpoint not available");
            return;
        }
        
        $this->assertContains(
            $response['http_code'],
            [200, 404],
            "Embed renderer should return 200 or 404"
        );
        
        if ($response['http_code'] == 200) {
            $this->assertStringContainsString(
                'style-webcam',
                $response['body'],
                "Should render webcam style embed"
            );
        }
    }
    
    /**
     * Test embed renderer with dual camera style
     */
    public function testEmbedRenderer_DualStyle()
    {
        $response = $this->makeRequest('?embed&airport=kspb&style=dual&theme=light&cams=0,1&render=1');
        
        if ($response['http_code'] == 0) {
            $this->markTestSkipped("Endpoint not available");
            return;
        }
        
        $this->assertContains(
            $response['http_code'],
            [200, 404],
            "Embed renderer should return 200 or 404"
        );
        
        if ($response['http_code'] == 200) {
            $this->assertStringContainsString(
                'style-dual',
                $response['body'],
                "Should render dual camera style embed"
            );
        }
    }
    
    /**
     * Test embed renderer with multi camera style
     */
    public function testEmbedRenderer_MultiStyle()
    {
        $response = $this->makeRequest('?embed&airport=kspb&style=multi&theme=light&cams=0,1,2,3&render=1');
        
        if ($response['http_code'] == 0) {
            $this->markTestSkipped("Endpoint not available");
            return;
        }
        
        $this->assertContains(
            $response['http_code'],
            [200, 404],
            "Embed renderer should return 200 or 404"
        );
        
        if ($response['http_code'] == 200) {
            $this->assertStringContainsString(
                'style-multi',
                $response['body'],
                "Should render multi camera style embed"
            );
        }
    }
    
    /**
     * Test embed renderer with full widget style
     */
    public function testEmbedRenderer_FullStyle()
    {
        $response = $this->makeRequest('?embed&airport=kspb&style=full&theme=light&webcam=0&render=1');
        
        if ($response['http_code'] == 0) {
            $this->markTestSkipped("Endpoint not available");
            return;
        }
        
        $this->assertContains(
            $response['http_code'],
            [200, 404],
            "Embed renderer should return 200 or 404"
        );
        
        if ($response['http_code'] == 200) {
            $this->assertStringContainsString(
                'style-full',
                $response['body'],
                "Should render full widget style embed"
            );
        }
    }
    
    /**
     * Test embed renderer with dark theme
     */
    public function testEmbedRenderer_DarkTheme()
    {
        $response = $this->makeRequest('?embed&airport=kspb&style=card&theme=dark&render=1');
        
        if ($response['http_code'] == 0) {
            $this->markTestSkipped("Endpoint not available");
            return;
        }
        
        $this->assertContains(
            $response['http_code'],
            [200, 404],
            "Embed renderer should return 200 or 404"
        );
        
        if ($response['http_code'] == 200) {
            $this->assertStringContainsString(
                'theme-dark',
                $response['body'],
                "Should apply dark theme"
            );
        }
    }
    
    /**
     * Test embed renderer with unit parameters
     */
    public function testEmbedRenderer_WithUnitParameters()
    {
        $response = $this->makeRequest('?embed&airport=kspb&style=card&theme=light&temp=C&dist=m&wind=kmh&baro=hPa&render=1');
        
        if ($response['http_code'] == 0) {
            $this->markTestSkipped("Endpoint not available");
            return;
        }
        
        $this->assertContains(
            $response['http_code'],
            [200, 404],
            "Embed renderer should accept unit parameters"
        );
    }
    
    /**
     * Test embed renderer with invalid airport
     */
    public function testEmbedRenderer_InvalidAirport()
    {
        $response = $this->makeRequest('?embed&airport=invalid_airport_xyz&style=card&theme=light&render=1');
        
        if ($response['http_code'] == 0) {
            $this->markTestSkipped("Endpoint not available");
            return;
        }
        
        // Should return 404 for invalid airport
        $this->assertEquals(
            404,
            $response['http_code'],
            "Should return 404 for invalid airport"
        );
    }
    
    /**
     * Test embed renderer without required parameters
     */
    public function testEmbedRenderer_MissingParameters()
    {
        $response = $this->makeRequest('?embed&airport=');
        
        if ($response['http_code'] == 0) {
            $this->markTestSkipped("Endpoint not available");
            return;
        }
        
        // Should return configurator page when airport is missing/empty
        $this->assertEquals(
            200,
            $response['http_code'],
            "Should return configurator when airport missing"
        );
        
        $this->assertStringContainsString(
            'Embed Generator',
            $response['body'],
            "Should show configurator when airport missing"
        );
    }
    
    /**
     * Test embed has proper footer with attribution
     */
    public function testEmbedRenderer_HasProperAttribution()
    {
        $response = $this->makeRequest('?embed&airport=kspb&style=card&theme=light&render=1');
        
        if ($response['http_code'] == 0) {
            $this->markTestSkipped("Endpoint not available");
            return;
        }
        
        if ($response['http_code'] == 200) {
            $this->assertStringContainsString(
                'AviationWX',
                $response['body'],
                "Should contain AviationWX attribution"
            );
            
            $this->assertStringContainsString(
                'View Dashboard',
                $response['body'],
                "Should contain link to full dashboard"
            );
        }
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
        
        $headers = [];
        curl_setopt($ch, CURLOPT_HEADERFUNCTION, function($ch, $header) use (&$headers) {
            $len = strlen($header);
            $header = explode(':', $header, 2);
            if (count($header) == 2) {
                $headers[strtolower(trim($header[0]))] = trim($header[1]);
            }
            return $len;
        });
        
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

