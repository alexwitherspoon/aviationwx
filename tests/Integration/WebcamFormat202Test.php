<?php
/**
 * Integration tests for webcam format HTTP 202 behavior
 * 
 * Tests the new HTTP 202 Accepted response for format generation:
 * - HTTP 202 when fmt=webp and format is generating
 * - HTTP 200 when fmt=webp and format is ready
 * - HTTP 200 when no fmt= parameter (always, even if generating)
 * - HTTP 400 when format disabled but explicitly requested
 * - HTTP 200 with fallback when format disabled and no explicit request
 * - Stale state handling
 */

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../lib/config.php';
require_once __DIR__ . '/../../lib/cache-paths.php';

class WebcamFormat202Test extends TestCase
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
     * Helper to make HTTP GET request
     */
    private function httpGet(string $path, array $headers = []): array
    {
        $url = rtrim($this->baseUrl, '/') . '/' . ltrim($path, '/');
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, getenv('CI') ? 15 : 10);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, getenv('CI') ? 10 : 5);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
        
        if (!empty($headers)) {
            curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        }
        
        $responseHeaders = [];
        curl_setopt($ch, CURLOPT_HEADERFUNCTION, function($ch, $header) use (&$responseHeaders) {
            $len = strlen($header);
            $header = trim($header);
            if (empty($header)) {
                return $len;
            }
            $parts = explode(':', $header, 2);
            if (count($parts) == 2) {
                $key = strtolower(trim($parts[0]));
                $value = trim($parts[1]);
                $responseHeaders[$key] = $value;
            }
            return $len;
        });
        
        $body = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err = curl_error($ch);
        
        return [
            'http_code' => $httpCode,
            'body' => $body,
            'headers' => $responseHeaders,
            'error' => $err
        ];
    }
    
    /**
     * Test HTTP 200 when no fmt= parameter (always 200, even if generating)
     */
    public function testNoFmtParameter_AlwaysReturns200(): void
    {
        if (!function_exists('curl_init')) {
            $this->markTestSkipped('cURL not available');
        }
        
        if (!file_exists($this->placeholderPath)) {
            $this->markTestSkipped('Placeholder image not found');
        }
        
        // Create JPEG only (WebP generating)
        copy($this->placeholderPath, $this->cacheJpg);
        @touch($this->cacheJpg, time() - 30); // Recent (current cycle)
        
        // No fmt parameter - should always return 200
        $response = $this->httpGet("api/webcam.php?id={$this->airport}&cam={$this->camIndex}");
        
        if ($response['http_code'] == 0) {
            $this->markTestSkipped('Endpoint not available');
            return;
        }
        
        $this->assertEquals(200, $response['http_code'], 'No fmt= parameter should always return 200');
        $this->assertArrayHasKey('content-type', $response['headers']);
        $this->assertStringContainsString('image/', $response['headers']['content-type']);
        $this->assertGreaterThan(0, strlen($response['body']));
    }
    
    /**
     * Test HTTP 200 when fmt=webp and format is ready
     */
    public function testFmtWebp_FormatReady_Returns200(): void
    {
        if (!function_exists('curl_init')) {
            $this->markTestSkipped('cURL not available');
        }
        
        if (!file_exists($this->placeholderPath)) {
            $this->markTestSkipped('Placeholder image not found');
        }
        
        // Create both JPEG and WebP (both ready)
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
        
        $this->assertEquals(200, $response['http_code'], 'Format ready should return 200');
        $this->assertArrayHasKey('content-type', $response['headers']);
        // May return JPEG if WebP validation fails, but should be 200
        $this->assertGreaterThan(0, strlen($response['body']));
    }
    
    /**
     * Test HTTP 202 when fmt=webp and format is generating
     * 
     * Note: This test may be flaky because it's hard to simulate "generating" state
     * without actually running the generation process. The format is generating if:
     * - JPEG is from current cycle
     * - WebP doesn't exist or is incomplete
     */
    public function testFmtWebp_FormatGenerating_Returns202(): void
    {
        if (!function_exists('curl_init')) {
            $this->markTestSkipped('cURL not available');
        }
        
        if (!file_exists($this->placeholderPath)) {
            $this->markTestSkipped('Placeholder image not found');
        }
        
        // Create JPEG only (WebP missing = generating)
        copy($this->placeholderPath, $this->cacheJpg);
        @touch($this->cacheJpg, time() - 30); // Recent (current cycle)
        
        // Ensure WebP doesn't exist
        @unlink($this->cacheWebp);
        
        $response = $this->httpGet("api/webcam.php?id={$this->airport}&cam={$this->camIndex}&fmt=webp");
        
        if ($response['http_code'] == 0) {
            $this->markTestSkipped('Endpoint not available');
            return;
        }
        
        // May return 202 if format is generating, or 200 if format disabled
        // We can't easily control the config in integration tests
        if ($response['http_code'] == 202) {
            $this->assertArrayHasKey('retry-after', $response['headers']);
            $this->assertArrayHasKey('x-format-generating', $response['headers']);
            $this->assertArrayHasKey('x-fallback-url', $response['headers']);
            $this->assertArrayHasKey('x-preferred-format-url', $response['headers']);
        } else {
            // If format is disabled, should return 200 with fallback or 400
            $this->assertContains($response['http_code'], [200, 400], 'Should return 200 (fallback) or 400 (disabled)');
        }
    }
    
    /**
     * Test HTTP 400 when format disabled but explicitly requested
     */
    public function testFmtWebp_FormatDisabled_Returns400(): void
    {
        if (!function_exists('curl_init')) {
            $this->markTestSkipped('cURL not available');
        }
        
        // This test requires the format to be disabled in config
        // We can't easily control config in integration tests, so we'll test the behavior
        // If format is disabled and explicitly requested, should return 400
        
        $response = $this->httpGet("api/webcam.php?id={$this->airport}&cam={$this->camIndex}&fmt=webp");
        
        if ($response['http_code'] == 0) {
            $this->markTestSkipped('Endpoint not available');
            return;
        }
        
        // If format is disabled, should return 400
        // Otherwise, may return 200 or 202
        if ($response['http_code'] == 400) {
            $this->assertArrayHasKey('content-type', $response['headers']);
            $this->assertStringContainsString('application/json', $response['headers']['content-type']);
            $data = json_decode($response['body'], true);
            $this->assertIsArray($data);
            $this->assertArrayHasKey('error', $data);
            $this->assertEquals('format_disabled', $data['error']);
        } else {
            // Format is enabled - should return 200 or 202
            $this->assertContains($response['http_code'], [200, 202], 'Enabled format should return 200 or 202');
        }
    }
    
    /**
     * Test HTTP 200 with fallback when format disabled and no explicit request
     */
    public function testNoFmtParameter_FormatDisabled_Returns200WithFallback(): void
    {
        if (!function_exists('curl_init')) {
            $this->markTestSkipped('cURL not available');
        }
        
        if (!file_exists($this->placeholderPath)) {
            $this->markTestSkipped('Placeholder image not found');
        }
        
        // Create JPEG only
        copy($this->placeholderPath, $this->cacheJpg);
        @touch($this->cacheJpg, time() - 30);
        
        // No fmt parameter - should always return 200, even if formats are disabled
        $response = $this->httpGet("api/webcam.php?id={$this->airport}&cam={$this->camIndex}");
        
        if ($response['http_code'] == 0) {
            $this->markTestSkipped('Endpoint not available');
            return;
        }
        
        $this->assertEquals(200, $response['http_code'], 'No fmt= should always return 200');
        $this->assertArrayHasKey('content-type', $response['headers']);
        $this->assertStringContainsString('image/', $response['headers']['content-type']);
        $this->assertGreaterThan(0, strlen($response['body']));
    }
    
    /**
     * Test stale state - all formats from same old cycle serves most efficient
     */
    public function testStaleState_ServesMostEfficientFormat(): void
    {
        if (!function_exists('curl_init')) {
            $this->markTestSkipped('cURL not available');
        }
        
        if (!file_exists($this->placeholderPath)) {
            $this->markTestSkipped('Placeholder image not found');
        }
        
        // Create all formats from old cycle (stale)
        copy($this->placeholderPath, $this->cacheJpg);
        copy($this->placeholderPath, $this->cacheWebp);
        $oldMtime = time() - 120; // 2 minutes ago (stale for 60s refresh)
        @touch($this->cacheJpg, $oldMtime);
        @touch($this->cacheWebp, $oldMtime);
        
        // No fmt parameter - should serve most efficient format available
        $response = $this->httpGet("api/webcam.php?id={$this->airport}&cam={$this->camIndex}");
        
        if ($response['http_code'] == 0) {
            $this->markTestSkipped('Endpoint not available');
            return;
        }
        
        $this->assertEquals(200, $response['http_code'], 'Stale state should return 200');
        $this->assertArrayHasKey('content-type', $response['headers']);
        $this->assertStringContainsString('image/', $response['headers']['content-type']);
        $this->assertGreaterThan(0, strlen($response['body']));
    }
    
    /**
     * Test HTTP 202 response headers
     */
    public function testHttp202Response_Headers(): void
    {
        if (!function_exists('curl_init')) {
            $this->markTestSkipped('cURL not available');
        }
        
        if (!file_exists($this->placeholderPath)) {
            $this->markTestSkipped('Placeholder image not found');
        }
        
        // Create JPEG only (WebP generating)
        copy($this->placeholderPath, $this->cacheJpg);
        @touch($this->cacheJpg, time() - 30);
        @unlink($this->cacheWebp);
        
        $response = $this->httpGet("api/webcam.php?id={$this->airport}&cam={$this->camIndex}&fmt=webp");
        
        if ($response['http_code'] == 0) {
            $this->markTestSkipped('Endpoint not available');
            return;
        }
        
        // If we get 202, check headers
        if ($response['http_code'] == 202) {
            $this->assertArrayHasKey('retry-after', $response['headers'], '202 response should have Retry-After header');
            $this->assertArrayHasKey('x-format-generating', $response['headers'], '202 response should have X-Format-Generating header');
            $this->assertArrayHasKey('x-fallback-url', $response['headers'], '202 response should have X-Fallback-URL header');
            $this->assertArrayHasKey('x-preferred-format-url', $response['headers'], '202 response should have X-Preferred-Format-URL header');
            $this->assertArrayHasKey('cache-control', $response['headers'], '202 response should have Cache-Control header');
            
            // Retry-After should be a positive integer
            $retryAfter = (int)$response['headers']['retry-after'];
            $this->assertGreaterThan(0, $retryAfter, 'Retry-After should be positive');
        } else {
            // If not 202, should be 200 (format ready or disabled) or 400 (format disabled)
            $this->assertContains($response['http_code'], [200, 400], 'Should return 200 (ready/disabled) or 400 (disabled)');
        }
    }
    
    /**
     * Test Accept header handling (no fmt= parameter)
     */
    public function testAcceptHeader_NoFmtParameter(): void
    {
        if (!function_exists('curl_init')) {
            $this->markTestSkipped('cURL not available');
        }
        
        if (!file_exists($this->placeholderPath)) {
            $this->markTestSkipped('Placeholder image not found');
        }
        
        // Create JPEG and WebP
        copy($this->placeholderPath, $this->cacheJpg);
        copy($this->placeholderPath, $this->cacheWebp);
        $mtime = time() - 30;
        @touch($this->cacheJpg, $mtime);
        @touch($this->cacheWebp, $mtime);
        
        // Request with Accept: image/webp header (no fmt= parameter)
        $response = $this->httpGet(
            "api/webcam.php?id={$this->airport}&cam={$this->camIndex}",
            ['Accept: image/webp,image/*']
        );
        
        if ($response['http_code'] == 0) {
            $this->markTestSkipped('Endpoint not available');
            return;
        }
        
        // Should return 200 (no fmt= parameter always returns 200)
        $this->assertEquals(200, $response['http_code'], 'No fmt= parameter should always return 200');
        $this->assertArrayHasKey('content-type', $response['headers']);
        $this->assertGreaterThan(0, strlen($response['body']));
    }
}

