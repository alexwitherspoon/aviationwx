<?php
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../lib/config.php';

/**
 * Test that webcam refresh JavaScript is properly initialized
 * This verifies the PHP-side initialization of CAM_TS and data attributes
 */
class WebcamRefreshInitializationTest extends TestCase
{
    private $baseUrl;
    private $airport = 'kspb';
    
    protected function setUp(): void
    {
        parent::setUp();
        $this->baseUrl = getenv('TEST_BASE_URL') ?: 'http://localhost:8080';
    }
    
    /**
     * Test that the airport page HTML includes CAM_TS initialization
     */
    public function testAirportPage_IncludesCamTsInitialization()
    {
        $response = $this->makeRequest("?airport={$this->airport}");
        
        if ($response['http_code'] == 0 || $response['http_code'] != 200) {
            $this->markTestSkipped("Airport page not available (HTTP {$response['http_code']})");
            return;
        }
        
        $html = $response['body'];
        
        // Check that CAM_TS initialization code exists
        // Should have: const CAM_TS = {}; followed by initialization
        $hasCamTsDeclaration = preg_match('/const\s+CAM_TS\s*=\s*\{\s*\}/', $html);
        $this->assertTrue($hasCamTsDeclaration, 'CAM_TS should be declared');
        
        // Check that CAM_TS is initialized with values
        // Should have: CAM_TS[0] = <timestamp>;
        $hasCamTsInit = preg_match('/CAM_TS\[\d+\]\s*=\s*\d+/', $html);
        $this->assertTrue($hasCamTsInit, 'CAM_TS should be initialized with timestamps');
    }
    
    /**
     * Test that webcam images have data-initial-timestamp attribute
     */
    public function testAirportPage_WebcamImagesHaveDataInitialTimestamp()
    {
        $response = $this->makeRequest("?airport={$this->airport}");
        
        if ($response['http_code'] == 0 || $response['http_code'] != 200) {
            $this->markTestSkipped("Airport page not available (HTTP {$response['http_code']})");
            return;
        }
        
        $html = $response['body'];
        
        // Find all webcam images
        preg_match_all('/<img[^>]*id=["\']webcam-\d+["\'][^>]*>/i', $html, $matches);
        
        if (empty($matches[0])) {
            $this->markTestSkipped('No webcam images found on page');
            return;
        }
        
        // Check each webcam image has data-initial-timestamp
        foreach ($matches[0] as $imgTag) {
            $hasAttribute = preg_match('/data-initial-timestamp=["\']\d+["\']/', $imgTag);
            $this->assertTrue(
                $hasAttribute,
                "Webcam image should have data-initial-timestamp attribute: " . substr($imgTag, 0, 100)
            );
            
            // Extract and validate timestamp value
            if (preg_match('/data-initial-timestamp=["\'](\d+)["\']/', $imgTag, $tsMatch)) {
                $timestamp = (int)$tsMatch[1];
                $this->assertGreaterThan(0, $timestamp, 'Timestamp should be greater than 0');
                $this->assertGreaterThan(1000000000, $timestamp, 'Timestamp should be a valid Unix timestamp');
            }
        }
    }
    
    /**
     * Test that safeSwapCameraImage function is defined
     */
    public function testAirportPage_DefinesSafeSwapCameraImage()
    {
        $response = $this->makeRequest("?airport={$this->airport}");
        
        if ($response['http_code'] == 0 || $response['http_code'] != 200) {
            $this->markTestSkipped("Airport page not available (HTTP {$response['http_code']})");
            return;
        }
        
        $html = $response['body'];
        
        // Check that safeSwapCameraImage function is defined
        $hasFunction = preg_match('/function\s+safeSwapCameraImage\s*\(/', $html);
        $this->assertTrue($hasFunction, 'safeSwapCameraImage function should be defined');
    }
    
    /**
     * Test that setInterval is called for webcam refresh
     */
    public function testAirportPage_SetsWebcamRefreshInterval()
    {
        $response = $this->makeRequest("?airport={$this->airport}");
        
        if ($response['http_code'] == 0 || $response['http_code'] != 200) {
            $this->markTestSkipped("Airport page not available (HTTP {$response['http_code']})");
            return;
        }
        
        $html = $response['body'];
        
        // Check that setInterval is called with safeSwapCameraImage
        // Should have: setInterval(() => { safeSwapCameraImage(...); }, ...);
        $hasInterval = preg_match(
            '/setInterval\s*\([^)]*safeSwapCameraImage/',
            $html
        );
        $this->assertTrue($hasInterval, 'setInterval should be called for webcam refresh');
    }
    
    /**
     * Test that CAM_TS initialization uses correct cache file timestamps
     */
    public function testAirportPage_CamTsUsesCorrectTimestamps()
    {
        $cacheDir = __DIR__ . '/../../cache/webcams';
        $cacheFile = $cacheDir . '/' . $this->airport . '_0.jpg';
        
        // Ensure cache file exists for testing
        if (!file_exists($cacheFile)) {
            // Try webp
            $cacheFile = $cacheDir . '/' . $this->airport . '_0.webp';
        }
        
        if (!file_exists($cacheFile)) {
            $this->markTestSkipped('Cache file not found for timestamp validation');
            return;
        }
        
        $expectedMtime = filemtime($cacheFile);
        
        $response = $this->makeRequest("?airport={$this->airport}");
        
        if ($response['http_code'] == 0 || $response['http_code'] != 200) {
            $this->markTestSkipped("Airport page not available (HTTP {$response['http_code']})");
            return;
        }
        
        $html = $response['body'];
        
        // Extract CAM_TS[0] value from HTML
        if (preg_match('/CAM_TS\[0\]\s*=\s*(\d+)/', $html, $matches)) {
            $actualMtime = (int)$matches[1];
            
            // Allow small difference (within 5 seconds) due to timing
            $diff = abs($actualMtime - $expectedMtime);
            $this->assertLessThan(
                5,
                $diff,
                "CAM_TS[0] should match cache file mtime (expected: {$expectedMtime}, got: {$actualMtime}, diff: {$diff})"
            );
        } else {
            $this->fail('Could not find CAM_TS[0] initialization in HTML');
        }
    }
    
    /**
     * Test that data-initial-timestamp matches CAM_TS initialization
     */
    public function testAirportPage_DataAttributeMatchesCamTs()
    {
        $response = $this->makeRequest("?airport={$this->airport}");
        
        if ($response['http_code'] == 0 || $response['http_code'] != 200) {
            $this->markTestSkipped("Airport page not available (HTTP {$response['http_code']})");
            return;
        }
        
        $html = $response['body'];
        
        // Extract CAM_TS[0] value
        $camTsValue = null;
        if (preg_match('/CAM_TS\[0\]\s*=\s*(\d+)/', $html, $matches)) {
            $camTsValue = (int)$matches[1];
        }
        
        // Extract data-initial-timestamp from webcam-0 image
        $dataTimestamp = null;
        if (preg_match('/id=["\']webcam-0["\'][^>]*data-initial-timestamp=["\'](\d+)["\']/', $html, $matches)) {
            $dataTimestamp = (int)$matches[1];
        }
        
        if ($camTsValue === null || $dataTimestamp === null) {
            $this->markTestSkipped('Could not extract CAM_TS or data-initial-timestamp values');
            return;
        }
        
        // They should match (or be very close due to timing)
        $diff = abs($camTsValue - $dataTimestamp);
        $this->assertLessThan(
            5,
            $diff,
            "data-initial-timestamp should match CAM_TS[0] (CAM_TS: {$camTsValue}, data: {$dataTimestamp}, diff: {$diff})"
        );
    }
    
    private function makeRequest(string $path): array
    {
        $url = rtrim($this->baseUrl, '/') . '/' . ltrim($path, '/');
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, getenv('CI') ? 15 : 10);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, getenv('CI') ? 10 : 5);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
        $body = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err = curl_error($ch);
        curl_close($ch);
        return ['http_code' => $httpCode, 'body' => $body, 'error' => $err];
    }
}

