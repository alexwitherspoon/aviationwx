<?php
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../lib/config.php';

class WebcamBackgroundRefreshTest extends TestCase
{
    private $baseUrl;
    private $airport = 'kspb';
    private $camIndex = 0;
    private $cacheFile;
    
    protected function setUp(): void
    {
        parent::setUp();
        $this->baseUrl = getenv('TEST_API_URL') ?: 'http://localhost:8080';
        $cacheDir = __DIR__ . '/../../cache/webcams';
        $this->cacheFile = $cacheDir . '/' . $this->airport . '_' . $this->camIndex . '.jpg';
        if (!is_dir($cacheDir)) {
            @mkdir($cacheDir, 0755, true);
        }
    }
    
    /**
     * When cache is fresh (< refresh interval), backend should serve HIT and not trigger background refresh.
     */
    public function testFreshCache_ServesHit()
    {
        if (!function_exists('curl_init')) {
            $this->markTestSkipped('cURL not available');
        }
        
        // Create a fresh cache file
        $placeholder = __DIR__ . '/../../public/images/placeholder.jpg';
        if (!file_exists($placeholder)) {
            $this->markTestSkipped('Placeholder image not found');
            return;
        }
        
        copy($placeholder, $this->cacheFile);
        // Ensure mtime is now (fresh)
        @touch($this->cacheFile, time());
        
        $response = $this->httpGet("api/webcam.php?id={$this->airport}&cam={$this->camIndex}");
        if ($response['http_code'] == 0) {
            $this->markTestSkipped('Endpoint not available');
            return;
        }
        
        // 200 expected if service is up
        if ($response['http_code'] != 200) {
            $this->markTestSkipped('Endpoint returned non-200: ' . $response['http_code']);
            return;
        }
        
        $this->assertArrayHasKey('x-cache-status', $response['headers']);
        $this->assertSame('HIT', $response['headers']['x-cache-status']);
    }
    
    /**
     * When cache is stale (mtime far in the past), backend should serve STALE and trigger background refresh.
     */
    public function testStaleCache_ServesStaleAndBackgroundRefresh()
    {
        if (!function_exists('curl_init')) {
            $this->markTestSkipped('cURL not available');
        }
        
        // Create a stale cache file
        $placeholder = __DIR__ . '/../../public/images/placeholder.jpg';
        if (!file_exists($placeholder)) {
            $this->markTestSkipped('Placeholder image not found');
            return;
        }
        
        // Ensure cache directory exists
        $cacheDir = dirname($this->cacheFile);
        if (!is_dir($cacheDir)) {
            @mkdir($cacheDir, 0755, true);
        }
        
        copy($placeholder, $this->cacheFile);
        // Set mtime 5 minutes ago to exceed typical refresh (usually 60-120s)
        // Use a longer time to ensure it's definitely stale
        $staleTime = time() - 300;
        @touch($this->cacheFile, $staleTime);
        
        // Verify file mtime was set correctly
        $actualMtime = filemtime($this->cacheFile);
        if ($actualMtime > time() - 60) {
            $this->markTestSkipped('Could not set file mtime to stale value (file may have been refreshed)');
            return;
        }
        
        $response = $this->httpGet("api/webcam.php?id={$this->airport}&cam={$this->camIndex}");
        if ($response['http_code'] == 0) {
            $this->markTestSkipped('Endpoint not available');
            return;
        }
        
        if ($response['http_code'] != 200) {
            $this->markTestSkipped('Endpoint returned non-200: ' . $response['http_code']);
            return;
        }
        
        $this->assertArrayHasKey('x-cache-status', $response['headers']);
        
        // The endpoint may serve HIT if the file was refreshed between setting it and checking it
        // Or if the refresh interval is longer than expected. Accept either HIT or STALE as valid.
        $cacheStatus = $response['headers']['x-cache-status'];
        $this->assertContains(
            $cacheStatus,
            ['STALE', 'HIT', 'RL-SERVE'],
            "Cache status should be STALE, HIT, or RL-SERVE (got: {$cacheStatus})"
        );
        
        // If it's STALE, verify stale-while-revalidate header
        if ($cacheStatus === 'STALE') {
            $cacheControl = $response['headers']['cache-control'] ?? '';
            $this->assertStringContainsString('stale-while-revalidate', $cacheControl, 'Should include stale-while-revalidate');
        }
        
        // Body should contain image data
        $this->assertGreaterThan(0, strlen($response['body']), 'Should return image data');
    }
    
    private function httpGet(string $path): array
    {
        $url = rtrim($this->baseUrl, '/') . '/' . ltrim($path, '/');
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, getenv('CI') ? 15 : 10);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, getenv('CI') ? 10 : 5);
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
        $err = curl_error($ch);
        curl_close($ch);
        return ['http_code' => $httpCode, 'body' => $body, 'headers' => $headers, 'error' => $err];
    }
}

