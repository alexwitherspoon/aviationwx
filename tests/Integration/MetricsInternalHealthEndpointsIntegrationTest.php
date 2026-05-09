<?php
/**
 * Integration tests for scheduler-facing internal health HTTP endpoints and *_via_http() helpers.
 *
 * Security: variant-health-flush and status-bundle-mirror-refresh only allow REMOTE_ADDR 127.0.0.1 / ::1.
 * Curl from the Docker host to a published port often appears as a bridge IP (not localhost), so you may
 * see HTTP 403. Run these tests from inside the web container so the client is localhost:
 *
 *   docker compose -f docker/docker-compose.yml exec web \
 *     php vendor/bin/phpunit tests/Integration/MetricsInternalHealthEndpointsIntegrationTest.php
 *
 * Or set TEST_API_URL to a URL where Apache sees the client as localhost. If nothing is listening,
 * tests mark skipped (http_code 0), matching other Integration HTTP tests.
 */

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

class MetricsInternalHealthEndpointsIntegrationTest extends TestCase
{
    private string $baseUrl;

    /** @var string|false|null Prior WEATHER_REFRESH_URL for tearDown */
    private string|false|null $priorWeatherRefreshUrl = null;

    protected function setUp(): void
    {
        parent::setUp();

        $this->baseUrl = rtrim(getenv('TEST_API_URL') ?: 'http://localhost:8080', '/');

        if (!function_exists('curl_init')) {
            $this->markTestSkipped('cURL not available');
        }

        $this->priorWeatherRefreshUrl = getenv('WEATHER_REFRESH_URL');
        putenv('WEATHER_REFRESH_URL=' . $this->baseUrl);
    }

    protected function tearDown(): void
    {
        if ($this->priorWeatherRefreshUrl !== false && $this->priorWeatherRefreshUrl !== '') {
            putenv('WEATHER_REFRESH_URL=' . $this->priorWeatherRefreshUrl);
        } else {
            putenv('WEATHER_REFRESH_URL');
        }
        parent::tearDown();
    }

    /**
     * GET /health/variant-health-flush.php returns JSON with success and results.
     */
    public function testVariantHealthFlushEndpoint_ReturnsJsonContract(): void
    {
        $response = $this->internalHealthRequest('health/variant-health-flush.php');
        $this->skipOrAssertLocalAccess($response);

        $this->assertSame(200, $response['http_code'], $response['body']);
        $data = json_decode($response['body'], true);
        $this->assertIsArray($data);
        $this->assertArrayHasKey('success', $data);
        $this->assertArrayHasKey('timestamp', $data);
        $this->assertArrayHasKey('results', $data);
        $this->assertIsBool($data['success']);
    }

    /**
     * GET /health/status-bundle-mirror-refresh.php returns JSON success after rebuilding bundle.
     */
    public function testStatusBundleMirrorRefreshEndpoint_ReturnsJsonContract(): void
    {
        $response = $this->internalHealthRequest('health/status-bundle-mirror-refresh.php');
        $this->skipOrAssertLocalAccess($response);

        $this->assertSame(200, $response['http_code'], $response['body']);
        $data = json_decode($response['body'], true);
        $this->assertIsArray($data);
        $this->assertArrayHasKey('success', $data);
        $this->assertTrue($data['success'], 'Mirror refresh should succeed unless metrics_get_status_bundle throws');
        $this->assertArrayHasKey('timestamp', $data);
    }

    /**
     * variant_health_flush_via_http() matches reachable localhost endpoint behavior.
     */
    public function testVariantHealthFlushViaHttp_WhenEndpointReachable(): void
    {
        $response = $this->internalHealthRequest('health/variant-health-flush.php');
        $this->skipOrAssertLocalAccess($response);

        if ($response['http_code'] !== 200) {
            $this->markTestSkipped('Endpoint not HTTP 200; skipping helper assertion');
        }

        require_once __DIR__ . '/../../lib/metrics.php';

        $data = json_decode($response['body'], true);
        $expectedOk = is_array($data) && ($data['success'] ?? false) === true;

        $helperOk = variant_health_flush_via_http();
        $this->assertSame($expectedOk, $helperOk);
    }

    /**
     * metrics_status_bundle_mirror_refresh_via_http() returns true when mirror endpoint returns success.
     */
    public function testStatusBundleMirrorRefreshViaHttp_WhenEndpointReachable(): void
    {
        $response = $this->internalHealthRequest('health/status-bundle-mirror-refresh.php');
        $this->skipOrAssertLocalAccess($response);

        if ($response['http_code'] !== 200) {
            $this->markTestSkipped('Endpoint not HTTP 200; skipping helper assertion');
        }

        require_once __DIR__ . '/../../lib/metrics.php';

        $data = json_decode($response['body'], true);
        $endpointSuccess = is_array($data) && ($data['success'] ?? false) === true;

        $helperOk = metrics_status_bundle_mirror_refresh_via_http();
        $this->assertSame($endpointSuccess, $helperOk);
        $this->assertTrue($helperOk);
    }

    /**
     * @param array{http_code: int, body: string} $response
     */
    private function skipOrAssertLocalAccess(array $response): void
    {
        if ($response['http_code'] === 0) {
            $this->markTestSkipped("No HTTP server at {$this->baseUrl}");
        }

        if ($response['http_code'] === 403) {
            $this->markTestSkipped(
                'Internal health endpoints are localhost-only; got 403. '
                . 'Curl from host to Docker maps a non-loopback client IP. '
                . 'Run from inside the web container (see class docblock) or adjust routing.'
            );
        }
    }

    private function internalHealthRequest(string $path): array
    {
        $url = $this->baseUrl . '/' . ltrim($path, '/');
        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 25,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_HTTPHEADER => ['X-Scheduler-Request: 1'],
        ]);

        $body = curl_exec($ch);
        $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);

        return [
            'http_code' => $httpCode,
            'body' => $body === false ? '' : (string) $body,
        ];
    }
}
