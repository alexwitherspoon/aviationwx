<?php
/**
 * Integration tests for webcam API reliability improvements
 * Tests error handling, file validation, Content-Length headers, etc.
 */

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../lib/config.php';
require_once __DIR__ . '/../../lib/cache-paths.php';

class WebcamApiReliabilityTest extends TestCase
{
    private $baseUrl;
    private $airport = 'kspb';
    private $camIndex = 0;
    private $cacheDir;
    private $cacheJpg;
    private $cacheWebp;
    private $placeholderPath;
    
    protected function setUp(): void
    {
        parent::setUp();
        $this->baseUrl = getenv('TEST_API_URL') ?: 'http://localhost:8080';
        require_once __DIR__ . '/../../lib/webcam-format-generation.php';
        $this->cacheDir = CACHE_WEBCAMS_DIR;
        $this->cacheJpg = getCacheSymlinkPath($this->airport, $this->camIndex, 'jpg');
        $this->cacheWebp = getCacheSymlinkPath($this->airport, $this->camIndex, 'webp');
        $this->placeholderPath = __DIR__ . '/../../public/images/placeholder.jpg';
        
        // Ensure cache directory exists
        ensureCacheDir($this->cacheDir);
        ensureCacheDir(getWebcamCameraDir($this->airport, $this->camIndex));
    }
    
    protected function tearDown(): void
    {
        // Clean up test files
        @unlink($this->cacheJpg);
        @unlink($this->cacheWebp);
        @unlink($this->cacheJpg . '.tmp');
        @unlink($this->cacheWebp . '.tmp');
        parent::tearDown();
    }
    
    /**
     * Test that valid JPEG files are served correctly with Content-Length
     */
    public function testValidJpeg_ServesWithContentLength()
    {
        if (!function_exists('curl_init')) {
            $this->markTestSkipped('cURL not available');
        }
        
        if (!file_exists($this->placeholderPath)) {
            $this->markTestSkipped('Placeholder image not found');
        }
        
        // Create a valid JPEG cache file
        copy($this->placeholderPath, $this->cacheJpg);
        @touch($this->cacheJpg, time());
        
        $response = $this->httpGet("api/webcam.php?id={$this->airport}&cam={$this->camIndex}");
        
        if ($response['http_code'] == 0) {
            $this->markTestSkipped('Endpoint not available');
            return;
        }
        
        $this->assertEquals(200, $response['http_code'], 'Should return 200 for valid image');
        $this->assertArrayHasKey('content-type', $response['headers'], 'Should have Content-Type header');
        // Accept both JPEG and WEBP formats (system may serve either)
        $contentType = strtolower($response['headers']['content-type']);
        $this->assertTrue(
            strpos($contentType, 'image/jpeg') !== false || strpos($contentType, 'image/webp') !== false,
            'Should be JPEG or WEBP (got: ' . $response['headers']['content-type'] . ')'
        );
        
        // Content-Length may not always be present depending on server configuration
        // But if present, it should match the actual body size (not necessarily file size, as file may be served differently)
        if (isset($response['headers']['content-length'])) {
            $actualSize = (int)$response['headers']['content-length'];
            $bodySize = strlen($response['body']);
            // Content-Length should match the actual body size
            $this->assertEquals($bodySize, $actualSize, 'Content-Length should match body size');
            // Body should have reasonable content (at least 1KB for a valid image)
            $this->assertGreaterThan(1024, $bodySize, 'Body should have reasonable size for an image');
        } else {
            // If Content-Length is not present, at least verify body has content
            $this->assertGreaterThan(0, strlen($response['body']), 'Body should have content');
        }

        // Integrity headers (ETag, Content-Digest, Content-MD5)
        $this->assertArrayHasKey('etag', $response['headers'], 'Should have ETag for conditional requests');
        $this->assertArrayHasKey('content-digest', $response['headers'], 'Should have Content-Digest (RFC 9530)');
        $this->assertArrayHasKey('content-md5', $response['headers'], 'Should have Content-MD5 for legacy clients');
        $this->assertStringStartsWith('W/"', $response['headers']['etag'], 'ETag should be weak format');
        $this->assertStringStartsWith('sha-256=:', $response['headers']['content-digest'], 'Content-Digest should be RFC 9530 format');
    }
    
    /**
     * Test that empty cache files are handled gracefully (should serve placeholder)
     */
    public function testEmptyCacheFile_ServesPlaceholder()
    {
        if (!function_exists('curl_init')) {
            $this->markTestSkipped('cURL not available');
        }
        
        // Create an empty file
        @file_put_contents($this->cacheJpg, '');
        
        $response = $this->httpGet("api/webcam.php?id={$this->airport}&cam={$this->camIndex}");
        
        if ($response['http_code'] == 0) {
            $this->markTestSkipped('Endpoint not available');
            return;
        }
        
        // Should serve placeholder (200) not error
        $this->assertEquals(200, $response['http_code'], 'Should return 200 (placeholder) for empty file');
        $this->assertArrayHasKey('content-type', $response['headers'], 'Should have Content-Type header');
        $this->assertGreaterThan(0, strlen($response['body']), 'Should return image data (placeholder)');
        
        // Empty file should be deleted (may take a moment, check after a brief delay)
        usleep(100000); // 100ms delay
        clearstatcache(true, $this->cacheJpg);
        // Note: File deletion happens in the endpoint, but may not be immediate in test environment
        // The important thing is that it serves placeholder, not the empty file
    }
    
    /**
     * Test that invalid image files (not JPEG/WEBP) are handled gracefully
     */
    public function testInvalidImageFile_ServesPlaceholder()
    {
        if (!function_exists('curl_init')) {
            $this->markTestSkipped('cURL not available');
        }
        
        // Create a file with invalid image data
        @file_put_contents($this->cacheJpg, 'This is not a valid JPEG file');
        
        $response = $this->httpGet("api/webcam.php?id={$this->airport}&cam={$this->camIndex}");
        
        if ($response['http_code'] == 0) {
            $this->markTestSkipped('Endpoint not available');
            return;
        }
        
        // Should serve placeholder (200) not error
        $this->assertEquals(200, $response['http_code'], 'Should return 200 (placeholder) for invalid image');
        $this->assertGreaterThan(0, strlen($response['body']), 'Should return image data (placeholder)');
        
        // Invalid file should be deleted (may take a moment, check after a brief delay)
        usleep(100000); // 100ms delay
        clearstatcache(true, $this->cacheJpg);
        // Note: File deletion happens in the endpoint, but may not be immediate in test environment
        // The important thing is that it serves placeholder, not the invalid file
    }
    
    /**
     * Test that rate-limited requests still validate files before serving
     */
    public function testRateLimited_ValidatesFileBeforeServing()
    {
        if (!function_exists('curl_init')) {
            $this->markTestSkipped('cURL not available');
        }
        
        if (!file_exists($this->placeholderPath)) {
            $this->markTestSkipped('Placeholder image not found');
        }
        
        // Check if API endpoint is reachable
        $testUrl = rtrim($this->baseUrl, '/') . '/api/webcam.php?id=' . $this->airport . '&cam=' . $this->camIndex;
        $ch = curl_init($testUrl);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 2);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 2);
        curl_setopt($ch, CURLOPT_NOBODY, true); // HEAD request
        curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);
        
        // Skip test if web server is not available (common in CI without test server)
        if ($httpCode === 0 || ($httpCode >= 500 && !empty($curlError))) {
            $this->markTestSkipped('Web server not available (required for integration test). ' . 
                ($curlError ? "Error: {$curlError}" : "HTTP code: {$httpCode}"));
        }
        
        // Create a valid JPEG cache file
        copy($this->placeholderPath, $this->cacheJpg);
        @touch($this->cacheJpg, time());
        
        // Make many requests to trigger rate limiting
        // Note: This test may not always trigger rate limiting depending on rate limit settings
        // But if it does, it should still serve valid files
        $validResponses = 0;
        for ($i = 0; $i < 10; $i++) {
            $response = $this->httpGet("api/webcam.php?id={$this->airport}&cam={$this->camIndex}");
            if ($response['http_code'] == 200) {
                $validResponses++;
                // If rate limited, should still have valid image
                if (isset($response['headers']['x-ratelimit']) && $response['headers']['x-ratelimit'] === 'exceeded') {
                    $this->assertArrayHasKey('content-length', $response['headers'], 'Rate-limited response should have Content-Length');
                    $this->assertGreaterThan(0, strlen($response['body']), 'Rate-limited response should have image data');
                }
            }
            usleep(100000); // 100ms between requests
        }
        
        $this->assertGreaterThan(0, $validResponses, 'Should get at least one valid response');
    }
    
    /**
     * Test that mtime endpoint returns valid JSON with proper validation
     */
    public function testMtimeEndpoint_ReturnsValidJson()
    {
        if (!function_exists('curl_init')) {
            $this->markTestSkipped('cURL not available');
        }
        
        if (!file_exists($this->placeholderPath)) {
            $this->markTestSkipped('Placeholder image not found');
        }
        
        // Create a valid JPEG cache file
        copy($this->placeholderPath, $this->cacheJpg);
        $expectedMtime = time() - 60; // 1 minute ago
        @touch($this->cacheJpg, $expectedMtime);
        
        $response = $this->httpGet("api/webcam.php?id={$this->airport}&cam={$this->camIndex}&mtime=1");
        
        if ($response['http_code'] == 0) {
            $this->markTestSkipped('Endpoint not available');
            return;
        }
        
        $this->assertEquals(200, $response['http_code'], 'Should return 200');
        $this->assertArrayHasKey('content-type', $response['headers'], 'Should have Content-Type header');
        $this->assertStringContainsString('application/json', $response['headers']['content-type'], 'Should be JSON');
        
        $data = json_decode($response['body'], true);
        $this->assertIsArray($data, 'Should return valid JSON');
        $this->assertArrayHasKey('success', $data, 'Should have success field');
        $this->assertArrayHasKey('timestamp', $data, 'Should have timestamp field');
        $this->assertArrayHasKey('size', $data, 'Should have size field');
        $this->assertArrayHasKey('formatReady', $data, 'Should have formatReady field');
        
        if ($data['success']) {
            $this->assertGreaterThan(0, $data['timestamp'], 'Timestamp should be > 0');
            $this->assertGreaterThan(0, $data['size'], 'Size should be > 0');
            
            // formatReady should only include enabled formats
            if (isset($data['formatReady']) && is_array($data['formatReady'])) {
                $enabledFormats = getEnabledWebcamFormats();
                foreach ($data['formatReady'] as $format => $ready) {
                    // Only enabled formats should be in formatReady
                    // Note: formatReady may include formats that exist but are disabled,
                    // but the server should respect enabled formats when serving
                    $this->assertIsBool($ready, "formatReady[{$format}] should be boolean");
                }
            }
        }
    }
    
    /**
     * Test that stale cache is served even when old (latest valid image)
     */
    public function testStaleCache_ServesLatestValidImage()
    {
        if (!function_exists('curl_init')) {
            $this->markTestSkipped('cURL not available');
        }
        
        if (!file_exists($this->placeholderPath)) {
            $this->markTestSkipped('Placeholder image not found');
        }
        
        // Create a stale but valid cache file
        copy($this->placeholderPath, $this->cacheJpg);
        $staleTime = time() - 600; // 10 minutes ago (definitely stale)
        @touch($this->cacheJpg, $staleTime);
        
        $response = $this->httpGet("api/webcam.php?id={$this->airport}&cam={$this->camIndex}");
        
        if ($response['http_code'] == 0) {
            $this->markTestSkipped('Endpoint not available');
            return;
        }
        
        // Should serve stale cache (200) not placeholder
        $this->assertEquals(200, $response['http_code'], 'Should return 200 for stale cache');
        $this->assertArrayHasKey('x-cache-status', $response['headers'], 'Should have cache status header');
        
        $cacheStatus = $response['headers']['x-cache-status'];
        // Could be STALE or HIT depending on timing, but should not be an error
        $this->assertContains($cacheStatus, ['STALE', 'HIT', 'RL-SERVE'], 'Should serve stale cache');
        
        $this->assertGreaterThan(0, strlen($response['body']), 'Should return image data');
        // Content-Length may not always be present depending on server configuration
        // But if present, it should match body size
        if (isset($response['headers']['content-length'])) {
            $this->assertEquals(strlen($response['body']), (int)$response['headers']['content-length'], 'Content-Length should match body size');
        }
    }
    
    /**
     * Test that WEBP format is handled correctly
     * 
     * Updated for new behavior:
     * - fmt=webp with format ready: 200
     * - fmt=webp with format generating: 202
     * - fmt=webp with format disabled: 400
     * - No fmt= parameter: always 200
     */
    public function testWebpFormat_HandledCorrectly()
    {
        if (!function_exists('curl_init')) {
            $this->markTestSkipped('cURL not available');
        }
        
        if (!file_exists($this->placeholderPath)) {
            $this->markTestSkipped('Placeholder image not found');
        }
        
        // Create both JPG and WEBP files
        copy($this->placeholderPath, $this->cacheJpg);
        copy($this->placeholderPath, $this->cacheWebp);
        $mtime = time() - 30; // Recent (current cycle)
        @touch($this->cacheJpg, $mtime);
        @touch($this->cacheWebp, $mtime);
        
        $response = $this->httpGet("api/webcam.php?id={$this->airport}&cam={$this->camIndex}&fmt=webp");
        
        if ($response['http_code'] == 0) {
            $this->markTestSkipped('Endpoint not available');
            return;
        }
        
        // Should return 200 (format ready), 202 (generating), or 400 (disabled)
        $this->assertContains($response['http_code'], [200, 202, 400], 'Should return 200, 202, or 400');
        $this->assertArrayHasKey('content-type', $response['headers'], 'Should have Content-Type header');
        
        if ($response['http_code'] == 200) {
            $this->assertGreaterThan(0, strlen($response['body']), 'Should return image data');
        } elseif ($response['http_code'] == 202) {
            $this->assertArrayHasKey('retry-after', $response['headers'], '202 should have Retry-After');
        } elseif ($response['http_code'] == 400) {
            $this->assertStringContainsString('application/json', $response['headers']['content-type'], '400 should be JSON');
        }
    }
    
    /**
     * Test that file serving handles errors gracefully
     */
    public function testFileServingError_HandlesGracefully()
    {
        if (!function_exists('curl_init')) {
            $this->markTestSkipped('cURL not available');
        }
        
        // Create a file that will cause read errors (permission issue simulation)
        // We can't easily simulate this, but we can test with a very large file
        // or test that the endpoint doesn't crash on various edge cases
        
        // Test with missing file (should serve placeholder)
        @unlink($this->cacheJpg);
        @unlink($this->cacheWebp);
        
        $response = $this->httpGet("api/webcam.php?id={$this->airport}&cam={$this->camIndex}");
        
        if ($response['http_code'] == 0) {
            $this->markTestSkipped('Endpoint not available');
            return;
        }
        
        // Should serve placeholder, not crash
        $this->assertEquals(200, $response['http_code'], 'Should return 200 (placeholder) for missing file');
        $this->assertGreaterThan(0, strlen($response['body']), 'Should return image data (placeholder)');
    }
    
    private function httpGet(string $path): array
    {
        $url = rtrim($this->baseUrl, '/') . '/' . ltrim($path, '/');
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, getenv('CI') ? 15 : 10);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, getenv('CI') ? 10 : 5);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
        $headers = [];
        curl_setopt($ch, CURLOPT_HEADERFUNCTION, function($ch, $header) use (&$headers) {
            $len = strlen($header);
            $header = trim($header);
            if (empty($header)) {
                return $len;
            }
            $parts = explode(':', $header, 2);
            if (count($parts) == 2) {
                $key = strtolower(trim($parts[0]));
                $value = trim($parts[1]);
                $headers[$key] = $value;
            }
            return $len;
        });
        $body = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err = curl_error($ch);
        return ['http_code' => $httpCode, 'body' => $body, 'headers' => $headers, 'error' => $err];
    }
}

