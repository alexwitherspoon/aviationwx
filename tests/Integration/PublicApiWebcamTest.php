<?php
/**
 * Integration Tests for Public API Webcam Endpoints
 * 
 * Tests webcam image endpoint including metadata and format handling.
 */

use PHPUnit\Framework\TestCase;

class PublicApiWebcamTest extends TestCase
{
    private static $apiBaseUrl;
    private static $testAirport = 'kspb';
    private static $testCam = 1;
    
    public static function setUpBeforeClass(): void
    {
        self::$apiBaseUrl = getenv('TEST_API_URL') ?: 'http://localhost:8080';
        
        // Ensure test images exist
        self::createTestImages();
    }
    
    /**
     * Create test images for testing
     */
    private static function createTestImages(): void
    {
        $cacheDir = __DIR__ . '/../../cache/webcams/' . self::$testAirport . '/' . self::$testCam;
        if (!is_dir($cacheDir)) {
            mkdir($cacheDir, 0755, true);
        }
        
        $timestamp = time();
        
        // Create original JPG
        $originalJpg = $cacheDir . '/' . $timestamp . '_original.jpg';
        if (!file_exists($originalJpg)) {
            // Create a simple test image
            $img = imagecreatetruecolor(800, 600);
            $blue = imagecolorallocate($img, 0, 0, 255);
            imagefill($img, 0, 0, $blue);
            imagejpeg($img, $originalJpg);
            imagedestroy($img);
        }
        
        // Create sized variants in WebP
        $variants = [1080 => [800, 600], 720 => [720, 540], 360 => [360, 270]];
        foreach ($variants as $height => $dims) {
            $webpFile = $cacheDir . '/' . $timestamp . '_' . $height . '.webp';
            if (!file_exists($webpFile) && function_exists('imagewebp')) {
                $img = imagecreatetruecolor($dims[0], $dims[1]);
                $red = imagecolorallocate($img, 255, 0, 0);
                imagefill($img, 0, 0, $red);
                imagewebp($img, $webpFile);
                imagedestroy($img);
            }
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
        $contentType = curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
        curl_close($ch);
        
        $headerStr = substr($response, 0, $headerSize);
        $body = substr($response, $headerSize);
        
        // Parse response headers
        $headers = [];
        foreach (explode("\r\n", $headerStr) as $line) {
            if (strpos($line, ':') !== false) {
                list($key, $value) = explode(':', $line, 2);
                $headers[trim($key)] = trim($value);
            }
        }
        
        return [
            'status' => $httpCode,
            'headers' => $headers,
            'content_type' => $contentType,
            'body' => $body,
            'json' => json_decode($body, true)
        ];
    }
    
    /**
     * Test metadata endpoint returns correct structure
     */
    public function testMetadataEndpoint_ReturnsCorrectStructure(): void
    {
        $response = $this->apiRequest('/airports/' . self::$testAirport . '/webcams/' . self::$testCam . '/image?metadata=1');
        
        // Should return 200 OK
        $this->assertEquals(200, $response['status'], 'Metadata endpoint should return 200');
        $this->assertStringContainsString('application/json', $response['content_type'], 'Should return JSON');
        
        // Check JSON structure
        $data = $response['json'];
        $this->assertIsArray($data, 'Response should be JSON array');
        $this->assertTrue($data['success'] ?? false, 'Success should be true');
        
        // Check data fields
        $this->assertArrayHasKey('data', $data, 'Should have data field');
        $this->assertArrayHasKey('timestamp', $data['data'], 'Should have timestamp');
        $this->assertArrayHasKey('timestamp_iso', $data['data'], 'Should have timestamp_iso');
        $this->assertArrayHasKey('formats', $data['data'], 'Should have formats');
        $this->assertArrayHasKey('recommended_sizes', $data['data'], 'Should have recommended_sizes');
        $this->assertArrayHasKey('urls', $data['data'], 'Should have urls');
        
        // Check meta fields
        $this->assertArrayHasKey('meta', $data, 'Should have meta field');
        $this->assertArrayHasKey('airport_id', $data['meta'], 'Meta should have airport_id');
        $this->assertArrayHasKey('cam_index', $data['meta'], 'Meta should have cam_index');
        $this->assertArrayHasKey('refresh_seconds', $data['meta'], 'Meta should have refresh_seconds');
        $this->assertArrayHasKey('variant_heights', $data['meta'], 'Meta should have variant_heights');
    }
    
    /**
     * Test metadata endpoint includes available formats
     */
    public function testMetadataEndpoint_IncludesAvailableFormats(): void
    {
        $response = $this->apiRequest('/airports/' . self::$testAirport . '/webcams/' . self::$testCam . '/image?metadata=1');
        
        $data = $response['json'];
        $formats = $data['data']['formats'] ?? [];
        
        $this->assertNotEmpty($formats, 'Formats should not be empty');
        
        // Original should always have at least JPG
        $this->assertArrayHasKey('original', $formats, 'Should have original variant');
        $this->assertContains('jpg', $formats['original'], 'Original should have JPG format');
    }
    
    /**
     * Test metadata endpoint provides working URLs
     */
    public function testMetadataEndpoint_ProvidesWorkingUrls(): void
    {
        $response = $this->apiRequest('/airports/' . self::$testAirport . '/webcams/' . self::$testCam . '/image?metadata=1');
        
        $data = $response['json'];
        $urls = $data['data']['urls'] ?? [];
        
        $this->assertNotEmpty($urls, 'URLs should not be empty');
        
        // Test at least one URL works
        foreach ($urls as $key => $url) {
            // Test the URL
            $imageResponse = $this->apiRequest($url);
            
            // Should return image or valid error
            $this->assertContains($imageResponse['status'], [200, 400, 503], 
                "URL $key should return valid status code");
            
            // If 200, should be an image
            if ($imageResponse['status'] === 200) {
                $this->assertStringContainsString('image/', $imageResponse['content_type'],
                    "URL $key should return image content type");
            }
            
            // Only test first URL to avoid rate limiting
            break;
        }
    }
    
    /**
     * Test explicit format request for unavailable format returns error
     * 
     * This tests Issue #1 fix: when WebP is requested but doesn't exist for original,
     * should return helpful error instead of silently falling back to JPG.
     */
    public function testExplicitFormatRequest_UnavailableFormat_ReturnsError(): void
    {
        // Request WebP for original (which typically doesn't exist - only variants have WebP)
        $response = $this->apiRequest('/airports/' . self::$testAirport . '/webcams/' . self::$testCam . '/image?fmt=webp');
        
        // Should return 400 Bad Request with helpful error
        $this->assertEquals(400, $response['status'], 
            'Explicit WebP request for unavailable format should return 400');
        
        $data = $response['json'];
        $this->assertFalse($data['success'] ?? true, 'Success should be false');
        $this->assertArrayHasKey('error', $data, 'Should have error field');
        $this->assertArrayHasKey('message', $data['error'], 'Error should have message');
        
        // Error message should mention available sizes
        $message = $data['error']['message'];
        $this->assertStringContainsString('not available', $message, 
            'Error message should mention format not available');
        $this->assertStringContainsString('size', strtolower($message), 
            'Error message should mention sizes');
    }
    
    /**
     * Test explicit format request with size parameter works
     */
    public function testExplicitFormatRequest_WithSize_ReturnsImage(): void
    {
        // First get metadata to find an available WebP variant
        $metaResponse = $this->apiRequest('/airports/' . self::$testAirport . '/webcams/' . self::$testCam . '/image?metadata=1');
        $formats = $metaResponse['json']['data']['formats'] ?? [];
        
        // Find a variant that has WebP
        $webpSize = null;
        foreach ($formats as $variant => $variantFormats) {
            if ($variant !== 'original' && in_array('webp', $variantFormats)) {
                $webpSize = $variant;
                break;
            }
        }
        
        if ($webpSize === null) {
            $this->markTestSkipped('No WebP variants available for testing');
        }
        
        // Request the WebP variant
        $response = $this->apiRequest('/airports/' . self::$testAirport . '/webcams/' . self::$testCam . '/image?fmt=webp&size=' . $webpSize);
        
        // Should return 200 with WebP image
        $this->assertEquals(200, $response['status'], 
            'Explicit WebP request with size should return 200');
        $this->assertStringContainsString('image/webp', $response['content_type'],
            'Should return WebP content type');
    }
    
    /**
     * Test default request (no fmt parameter) returns JPG
     */
    public function testDefaultRequest_NoFmtParameter_ReturnsJpg(): void
    {
        $response = $this->apiRequest('/airports/' . self::$testAirport . '/webcams/' . self::$testCam . '/image');
        
        // Should return 200 with JPG (or 503 if no image available)
        $this->assertContains($response['status'], [200, 503], 
            'Default request should return 200 or 503');
        
        if ($response['status'] === 200) {
            $this->assertStringContainsString('image/jpeg', $response['content_type'],
                'Default request should return JPEG content type');
        }
    }
    
    /**
     * Test recommended_sizes are sorted descending
     */
    public function testMetadataEndpoint_RecommendedSizesSorted(): void
    {
        $response = $this->apiRequest('/airports/' . self::$testAirport . '/webcams/' . self::$testCam . '/image?metadata=1');
        
        $data = $response['json'];
        $sizes = $data['data']['recommended_sizes'] ?? [];
        
        if (count($sizes) > 1) {
            $sortedSizes = $sizes;
            rsort($sortedSizes);
            $this->assertEquals($sortedSizes, $sizes, 
                'Recommended sizes should be sorted in descending order');
        }
    }
    
    /**
     * Test metadata endpoint returns 503 when no image available
     */
    public function testMetadataEndpoint_NoImage_Returns503(): void
    {
        // Use a non-existent webcam
        $response = $this->apiRequest('/airports/' . self::$testAirport . '/webcams/99/image?metadata=1');
        
        // Should return 503 or 404
        $this->assertContains($response['status'], [404, 503], 
            'Non-existent webcam should return 404 or 503');
    }
}
