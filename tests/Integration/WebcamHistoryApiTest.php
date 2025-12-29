<?php
/**
 * Integration Tests for Webcam History API Endpoint
 * 
 * Tests the /api/webcam-history.php endpoint response structure,
 * including the new refresh_interval field for rolling window support.
 */

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../lib/config.php';
require_once __DIR__ . '/../../lib/webcam-history.php';

class WebcamHistoryApiTest extends TestCase
{
    private $baseUrl;
    private $testAirport = 'kspb';
    private $testCamIndex = 0;
    
    protected function setUp(): void
    {
        parent::setUp();
        $this->baseUrl = getenv('TEST_BASE_URL') ?: 'http://localhost:8080';
    }
    
    /**
     * Make HTTP request to endpoint
     */
    private function makeRequest(string $endpoint): array
    {
        if (!function_exists('curl_init')) {
            $this->markTestSkipped('cURL not available');
            return ['http_code' => 0, 'body' => '', 'headers' => []];
        }
        
        $url = $this->baseUrl . '/' . $endpoint;
        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 10);
        curl_setopt($ch, CURLOPT_HEADER, true);
        
        $response = curl_exec($ch);
        $headerSize = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        
        $headerStr = substr($response, 0, $headerSize);
        $body = substr($response, $headerSize);
        
        // Parse headers
        $headers = [];
        foreach (explode("\r\n", $headerStr) as $line) {
            if (strpos($line, ':') !== false) {
                list($key, $value) = explode(':', $line, 2);
                $headers[strtolower(trim($key))] = trim($value);
            }
        }
        
        return [
            'http_code' => $httpCode,
            'headers' => $headers,
            'body' => $body
        ];
    }
    
    /**
     * Test that webcam history API returns valid JSON structure
     */
    public function testWebcamHistoryApi_ReturnsValidJson(): void
    {
        $response = $this->makeRequest("api/webcam-history.php?id={$this->testAirport}&cam={$this->testCamIndex}");
        
        if ($response['http_code'] == 0) {
            $this->markTestSkipped('Endpoint not available');
            return;
        }
        
        // Endpoint may return 200 (enabled) or 404 (not found/disabled)
        if ($response['http_code'] == 404) {
            $this->markTestSkipped('Webcam history not enabled for test airport');
            return;
        }
        
        $this->assertEquals(200, $response['http_code'], 'Should return 200 when history is enabled');
        
        // Verify Content-Type
        $contentType = $response['headers']['content-type'] ?? '';
        $this->assertStringContainsString('application/json', $contentType, 'Should return JSON');
        
        // Parse JSON
        $data = json_decode($response['body'], true);
        $this->assertNotNull($data, 'Response should be valid JSON');
        $this->assertIsArray($data, 'Response should be an array');
    }
    
    /**
     * Test that webcam history API response includes refresh_interval field
     */
    public function testWebcamHistoryApi_IncludesRefreshInterval(): void
    {
        $response = $this->makeRequest("api/webcam-history.php?id={$this->testAirport}&cam={$this->testCamIndex}");
        
        if ($response['http_code'] == 0) {
            $this->markTestSkipped('Endpoint not available');
            return;
        }
        
        if ($response['http_code'] == 404) {
            $this->markTestSkipped('Webcam history not enabled for test airport');
            return;
        }
        
        $data = json_decode($response['body'], true);
        
        if (!$data['enabled']) {
            $this->markTestSkipped('Webcam history not enabled for test airport');
            return;
        }
        
        // Verify refresh_interval field is present
        $this->assertArrayHasKey('refresh_interval', $data, 
            'Response should include refresh_interval field for rolling window support');
        
        // Verify refresh_interval is a positive integer >= 60
        $this->assertIsInt($data['refresh_interval'], 'refresh_interval should be an integer');
        $this->assertGreaterThanOrEqual(60, $data['refresh_interval'], 
            'refresh_interval should be at least 60 seconds (minimum enforced)');
    }
    
    /**
     * Test that refresh_interval is calculated correctly from config hierarchy
     */
    public function testWebcamHistoryApi_RefreshIntervalFromConfig(): void
    {
        $response = $this->makeRequest("api/webcam-history.php?id={$this->testAirport}&cam={$this->testCamIndex}");
        
        if ($response['http_code'] == 0) {
            $this->markTestSkipped('Endpoint not available');
            return;
        }
        
        if ($response['http_code'] == 404) {
            $this->markTestSkipped('Webcam history not enabled for test airport');
            return;
        }
        
        $data = json_decode($response['body'], true);
        
        if (!$data['enabled']) {
            $this->markTestSkipped('Webcam history not enabled for test airport');
            return;
        }
        
        // Load config to verify refresh_interval calculation
        $config = loadConfig();
        if ($config === null || !isset($config['airports'][$this->testAirport])) {
            $this->markTestSkipped('Config not available for verification');
            return;
        }
        
        $airport = $config['airports'][$this->testAirport];
        $cam = $airport['webcams'][$this->testCamIndex] ?? null;
        
        if ($cam === null) {
            $this->markTestSkipped('Camera not found in config');
            return;
        }
        
        // Calculate expected refresh interval (same logic as API)
        $defaultWebcamRefresh = getDefaultWebcamRefresh();
        $airportWebcamRefresh = isset($airport['webcam_refresh_seconds']) 
            ? intval($airport['webcam_refresh_seconds']) 
            : $defaultWebcamRefresh;
        $perCamRefresh = isset($cam['refresh_seconds']) 
            ? intval($cam['refresh_seconds']) 
            : $airportWebcamRefresh;
        $expectedRefresh = max(60, $perCamRefresh);
        
        // Verify API returns the correct refresh interval
        $this->assertEquals($expectedRefresh, $data['refresh_interval'], 
            'refresh_interval should match config hierarchy (per-cam > airport > global default)');
    }
    
    /**
     * Test that webcam history API response includes all required fields
     */
    public function testWebcamHistoryApi_IncludesRequiredFields(): void
    {
        $response = $this->makeRequest("api/webcam-history.php?id={$this->testAirport}&cam={$this->testCamIndex}");
        
        if ($response['http_code'] == 0) {
            $this->markTestSkipped('Endpoint not available');
            return;
        }
        
        if ($response['http_code'] == 404) {
            $this->markTestSkipped('Webcam history not enabled for test airport');
            return;
        }
        
        $data = json_decode($response['body'], true);
        
        // Required fields for enabled history
        $requiredFields = [
            'enabled',
            'airport',
            'cam',
            'frames',
            'current_index',
            'timezone',
            'max_frames',
            'enabledFormats',
            'refresh_interval'  // New field for rolling window
        ];
        
        foreach ($requiredFields as $field) {
            $this->assertArrayHasKey($field, $data, 
                "Response should include required field: {$field}");
        }
        
        // Verify field types
        if ($data['enabled']) {
            $this->assertIsArray($data['frames'], 'frames should be an array');
            $this->assertIsInt($data['current_index'], 'current_index should be an integer');
            $this->assertIsInt($data['max_frames'], 'max_frames should be an integer');
            $this->assertIsInt($data['refresh_interval'], 'refresh_interval should be an integer');
            $this->assertIsArray($data['enabledFormats'], 'enabledFormats should be an array');
        }
    }
    
    /**
     * Test that webcam history API handles invalid airport ID
     */
    public function testWebcamHistoryApi_InvalidAirportId(): void
    {
        $response = $this->makeRequest("api/webcam-history.php?id=invalid_airport_123&cam=0");
        
        if ($response['http_code'] == 0) {
            $this->markTestSkipped('Endpoint not available');
            return;
        }
        
        // Should return 404 for invalid airport
        $this->assertEquals(404, $response['http_code'], 
            'Should return 404 for invalid airport ID');
        
        $data = json_decode($response['body'], true);
        $this->assertNotNull($data, 'Error response should be valid JSON');
        $this->assertArrayHasKey('error', $data, 'Error response should have error field');
    }
    
    /**
     * Test that webcam history API handles invalid camera index
     */
    public function testWebcamHistoryApi_InvalidCameraIndex(): void
    {
        $response = $this->makeRequest("api/webcam-history.php?id={$this->testAirport}&cam=999");
        
        if ($response['http_code'] == 0) {
            $this->markTestSkipped('Endpoint not available');
            return;
        }
        
        // Should return 404 for invalid camera index
        $this->assertEquals(404, $response['http_code'], 
            'Should return 404 for invalid camera index');
        
        $data = json_decode($response['body'], true);
        $this->assertNotNull($data, 'Error response should be valid JSON');
        $this->assertArrayHasKey('error', $data, 'Error response should have error field');
    }
}

