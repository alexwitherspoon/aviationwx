<?php
/**
 * Cache-Control contracts for slow-changing internal API endpoints.
 *
 * NOTAM and station power successes carry shared-cache headers (s-maxage)
 * so the CDN can serve them; errors must never be cacheable, or a transient
 * failure could persist at the edge. Weather already follows this pattern.
 */

use PHPUnit\Framework\TestCase;

class InternalApiCacheHeadersTest extends TestCase
{
    private string $baseUrl;

    protected function setUp(): void
    {
        parent::setUp();
        $this->baseUrl = getenv('TEST_API_URL')
            ?: getenv('TEST_BASE_URL')
            ?: 'http://localhost:8080';
    }

    public function testNotamApi_Success_SendsSharedCacheHeaders(): void
    {
        $response = $this->makeRequest('api/notam.php?airport=kspb');

        if ($response['http_code'] === 0) {
            $this->markTestSkipped('Endpoint not available');
        }

        $this->assertSame(200, $response['http_code']);
        $cacheControl = $response['headers']['cache-control'] ?? '';
        $this->assertStringContainsString('public', $cacheControl);
        $this->assertStringContainsString('s-maxage=' . NOTAM_API_CACHE_TTL_SECONDS, $cacheControl);
        $this->assertStringContainsString('stale-while-revalidate=', $cacheControl);
    }

    public function testNotamApi_InvalidAirport_SendsNoCacheHeaders(): void
    {
        $response = $this->makeRequest('api/notam.php?airport=zz!!zz');

        if ($response['http_code'] === 0) {
            $this->markTestSkipped('Endpoint not available');
        }

        $this->assertSame(400, $response['http_code']);
        // No Cache-Control at all means the CDN bypasses under
        // "use cache-control header if present" cache rules
        $cacheControl = $response['headers']['cache-control'] ?? '';
        $this->assertStringNotContainsString('public', $cacheControl);
    }

    public function testStationPowerApi_NotConfigured_SendsNoStore(): void
    {
        // kspb has no station_power in test fixtures, so this exercises the
        // non-200 path; errors must never become cacheable
        $response = $this->makeRequest('api/station-power.php?airport=kspb');

        if ($response['http_code'] === 0) {
            $this->markTestSkipped('Endpoint not available');
        }

        $this->assertSame(404, $response['http_code']);
        $cacheControl = $response['headers']['cache-control'] ?? '';
        $this->assertStringContainsString('no-store', $cacheControl);
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

        return [
            'http_code' => $httpCode,
            'body' => is_string($body) ? $body : '',
            'headers' => $headers,
        ];
    }
}
