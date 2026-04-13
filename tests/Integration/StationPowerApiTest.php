<?php
/**
 * Integration tests for internal station power JSON API.
 */

use PHPUnit\Framework\TestCase;

class StationPowerApiTest extends TestCase
{
    private string $baseUrl;

    protected function setUp(): void
    {
        parent::setUp();
        $this->baseUrl = getenv('TEST_BASE_URL') ?: 'http://localhost:8080';
    }

    /**
     * kspb has no station_power in test fixtures; endpoint should not expose configuration details.
     */
    public function testStationPowerApi_NotConfigured_Returns404(): void
    {
        if (!function_exists('curl_init')) {
            $this->markTestSkipped('cURL not available');
        }

        $response = $this->makeRequest('api/station-power.php?airport=kspb');

        if ($response['http_code'] === 0) {
            $this->markTestSkipped('Endpoint not available');
        }

        $this->assertSame(404, $response['http_code']);
        $decoded = json_decode($response['body'], true);
        $this->assertIsArray($decoded);
        $this->assertArrayHasKey('success', $decoded);
        $this->assertFalse($decoded['success']);
    }

    public function testStationPowerApi_MissingAirport_Returns400(): void
    {
        if (!function_exists('curl_init')) {
            $this->markTestSkipped('cURL not available');
        }

        $response = $this->makeRequest('api/station-power.php');

        if ($response['http_code'] === 0) {
            $this->markTestSkipped('Endpoint not available');
        }

        $this->assertSame(400, $response['http_code']);
    }

    public function testStationPowerApi_Post_Returns405(): void
    {
        if (!function_exists('curl_init')) {
            $this->markTestSkipped('cURL not available');
        }

        $response = $this->makeRequest('api/station-power.php?airport=kspb', 'POST');

        if ($response['http_code'] === 0) {
            $this->markTestSkipped('Endpoint not available');
        }

        $this->assertSame(405, $response['http_code']);
        $decoded = json_decode($response['body'], true);
        $this->assertIsArray($decoded);
        $this->assertFalse($decoded['success']);
    }

    /**
     * @return array{http_code: int, body: string, headers: array<string, string>, error: string}
     */
    private function makeRequest(string $path, string $method = 'GET'): array
    {
        $url = rtrim($this->baseUrl, '/') . '/' . ltrim($path, '/');
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        if ($method !== 'GET') {
            curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
        }
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, getenv('CI') ? 15 : 10);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, getenv('CI') ? 10 : 5);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
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
        $error = curl_error($ch);

        return [
            'http_code' => $httpCode,
            'body' => is_string($body) ? $body : '',
            'headers' => $headers,
            'error' => $error,
        ];
    }
}
