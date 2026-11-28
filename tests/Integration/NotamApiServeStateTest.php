<?php
/**
 * NOTAM dashboard endpoint serve-state contract.
 *
 * No cache file: quiet no-data response (null age, no failclosed flag,
 * no per-request alarm). Stale cache file: failclosed with a real age,
 * never a sentinel. The two states must stay distinguishable in the
 * JSON, not just in the logs.
 */

use PHPUnit\Framework\TestCase;

class NotamApiServeStateTest extends TestCase
{
    private string $baseUrl;
    private ?string $notamCacheDir = null;
    private string $staleFile = '';

    protected function setUp(): void
    {
        parent::setUp();
        $this->baseUrl = getenv('TEST_API_URL')
            ?: getenv('TEST_BASE_URL')
            ?: 'http://localhost:8080';

        // The test container bind-mounts TEST_CACHE_DIR as its cache root,
        // so a file written here is the file the endpoint reads.
        $cacheDir = getenv('TEST_CACHE_DIR');
        if (is_string($cacheDir) && $cacheDir !== '') {
            $this->notamCacheDir = rtrim($cacheDir, '/') . '/notam';
        }
    }

    protected function tearDown(): void
    {
        if ($this->staleFile !== '' && is_file($this->staleFile)) {
            @unlink($this->staleFile);
        }
        parent::tearDown();
    }

    /**
     * An airport that has never produced a cache file serves the no-data
     * contract: empty list, null age, no failclosed flag. The test removes
     * any leftover file itself so it does not depend on suite order.
     */
    public function testNoCacheFile_ReturnsNoDataContract(): void
    {
        if ($this->notamCacheDir !== null) {
            @unlink($this->notamCacheDir . '/gic.json');
        }

        $response = $this->makeRequest('api/notam.php?airport=gic');
        if ($response['http_code'] === 0) {
            $this->markTestSkipped('Endpoint not available');
        }

        $this->assertSame(200, $response['http_code']);
        $data = json_decode($response['body'], true);
        $this->assertIsArray($data, 'response body: ' . $response['body']);
        $this->assertSame('success', $data['status']);
        $this->assertSame([], $data['notams']);
        $this->assertNull($data['cache_age']);
        $this->assertTrue($data['no_data']);
        $this->assertFalse($data['refreshing']);
        $this->assertArrayNotHasKey('failclosed', $data);

        // No-data is a success response: the CDN may share it. It is short
        // lived (s-maxage) so a first successful fetch surfaces quickly.
        $cacheControl = $response['headers']['cache-control'] ?? '';
        $this->assertStringContainsString('public', $cacheControl);
        $this->assertStringContainsString('s-maxage=' . NOTAM_API_CACHE_TTL_SECONDS, $cacheControl);
    }

    /**
     * A cache file that has aged past the failclosed threshold serves the
     * failclosed contract with the real age. This is "data went stale",
     * distinct from the no-data state, and must not report a sentinel.
     */
    public function testStaleCacheFile_ReturnsFailclosedWithRealAge(): void
    {
        if ($this->notamCacheDir === null) {
            $this->markTestSkipped('TEST_CACHE_DIR not set; cannot seed the server cache');
        }

        if (!is_dir($this->notamCacheDir)
            && !@mkdir($this->notamCacheDir, 0755, true)
            && !is_dir($this->notamCacheDir)
        ) {
            $this->markTestSkipped('Cannot create NOTAM cache directory');
        }

        $this->staleFile = $this->notamCacheDir . '/kspb.json';
        file_put_contents($this->staleFile, json_encode([
            'fetched_at' => time() - 7200,
            'airport' => 'kspb',
            'notams' => [],
            'status' => 'success',
        ], JSON_THROW_ON_ERROR));
        // mtime drives the served age; backdate past the 1h failclosed threshold
        touch($this->staleFile, time() - 7200);

        $response = $this->makeRequest('api/notam.php?airport=kspb');
        if ($response['http_code'] === 0) {
            $this->markTestSkipped('Endpoint not available');
        }

        $this->assertSame(200, $response['http_code']);
        $data = json_decode($response['body'], true);
        $this->assertIsArray($data, 'response body: ' . $response['body']);
        $this->assertSame('success', $data['status']);
        $this->assertTrue($data['failclosed']);
        $this->assertArrayNotHasKey('no_data', $data);
        $this->assertIsInt($data['cache_age']);
        // A real age near the 7200s we seeded, never an overflow sentinel
        $this->assertGreaterThan(3600, $data['cache_age']);
        $this->assertLessThan(86400, $data['cache_age']);
    }

    /**
     * @return array{http_code: int, body: string, headers: array<string, string>}
     */
    private function makeRequest(string $path): array
    {
        if (!function_exists('curl_init')) {
            $this->markTestSkipped('cURL not available');
        }

        $url = rtrim($this->baseUrl, '/') . '/' . ltrim($path, '/');
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, getenv('CI') ? 15 : 10);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, getenv('CI') ? 10 : 5);
        $headers = [];
        curl_setopt($ch, CURLOPT_HEADERFUNCTION, static function ($ch, $header) use (&$headers) {
            $len = strlen($header);
            $parts = explode(':', $header, 2);
            if (count($parts) === 2) {
                $headers[strtolower(trim($parts[0]))] = trim($parts[1]);
            }

            return $len;
        });
        $body = curl_exec($ch);
        $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        // No curl_close(): the CurlHandle object frees itself at scope exit
        // (curl_close is a no-op since PHP 8.0, deprecated since 8.5)

        return [
            'http_code' => $httpCode,
            'body' => is_string($body) ? $body : '',
            'headers' => $headers,
        ];
    }
}
