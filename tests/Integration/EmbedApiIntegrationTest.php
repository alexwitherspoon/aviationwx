<?php
/**
 * Integration Tests for Embed API Endpoint
 *
 * GET /v1/airports/{id}/embed - full payload and refresh=1 differential.
 * TDD-style: verify endpoint contract, CORS, and response structure.
 *
 * @package AviationWX\Tests\Integration
 */

use PHPUnit\Framework\TestCase;

class EmbedApiIntegrationTest extends TestCase
{
    private static $apiBaseUrl;
    private static $apiEnabled;

    public static function setUpBeforeClass(): void
    {
        self::$apiBaseUrl = getenv('TEST_API_URL') ?: 'http://localhost:8080';

        $ch = curl_init(self::$apiBaseUrl . '/api/v1/status');
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 5);
        curl_setopt($ch, CURLOPT_HTTPHEADER, ['X-Health-Check: internal']);
        curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        self::$apiEnabled = ($httpCode === 200);

        if (!self::$apiEnabled) {
            $ch = curl_init(self::$apiBaseUrl);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_TIMEOUT, 5);
            curl_exec($ch);
            if (curl_getinfo($ch, CURLINFO_HTTP_CODE) === 0) {
                self::markTestSkipped('Test server not running at ' . self::$apiBaseUrl);
            }
        }
    }

    private function skipIfApiDisabled(): void
    {
        if (!self::$apiEnabled) {
            $this->markTestSkipped('Public API is not enabled');
        }
    }

    private function embedRequest(string $endpoint, array $headers = []): array
    {
        $url = self::$apiBaseUrl . '/api/v1' . $endpoint;
        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 10);
        curl_setopt($ch, CURLOPT_HTTPHEADER, array_merge(['Accept: application/json'], $headers));
        curl_setopt($ch, CURLOPT_HEADER, true);

        $response = curl_exec($ch);
        $headerSize = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
        $body = substr($response, $headerSize);
        $headerStr = substr($response, 0, $headerSize);

        $headers = [];
        foreach (explode("\r\n", $headerStr) as $line) {
            if (strpos($line, ':') !== false) {
                [$key, $value] = explode(':', $line, 2);
                $headers[strtolower(trim($key))] = trim($value);
            }
        }

        return [
            'code' => curl_getinfo($ch, CURLINFO_HTTP_CODE),
            'headers' => $headers,
            'json' => json_decode($body, true),
        ];
    }

    public function testEmbedEndpoint_FullRequest_Returns200(): void
    {
        $this->skipIfApiDisabled();

        $r = $this->embedRequest('/airports/kspb/embed');

        $this->assertSame(200, $r['code']);
        $this->assertIsArray($r['json']);
        $this->assertTrue($r['json']['success'] ?? false);
    }

    public function testEmbedEndpoint_FullRequest_IncludesEmbedData(): void
    {
        $this->skipIfApiDisabled();

        $r = $this->embedRequest('/airports/kspb/embed');

        $this->assertArrayHasKey('data', $r['json']);
        $this->assertArrayHasKey('embed', $r['json']['data']);
        $this->assertArrayHasKey('diff', $r['json']['data']);
        $this->assertFalse($r['json']['data']['diff']);

        $embed = $r['json']['data']['embed'];
        $this->assertArrayHasKey('weather', $embed);
        $this->assertArrayHasKey('airport', $embed);
        $this->assertArrayHasKey('weather_observed_at', $embed);
        $this->assertArrayHasKey('airport_observed_at', $embed);

        $airport = $embed['airport'];
        $this->assertArrayHasKey('runways', $airport);
        $this->assertArrayHasKey('weather_sources', $airport);
        $this->assertIsArray($airport['runways']);
        $this->assertIsArray($airport['weather_sources']);
    }

    public function testEmbedEndpoint_Refresh1_ReturnsDiffOrFull(): void
    {
        $this->skipIfApiDisabled();

        $r = $this->embedRequest('/airports/kspb/embed?refresh=1');

        $this->assertSame(200, $r['code']);
        $this->assertTrue($r['json']['success']);
        $this->assertArrayHasKey('data', $r['json']);
        $this->assertArrayHasKey('embed', $r['json']['data']);
        $this->assertArrayHasKey('diff', $r['json']['data']);
    }

    public function testEmbedEndpoint_NotFound_Returns404(): void
    {
        $this->skipIfApiDisabled();

        $r = $this->embedRequest('/airports/nonexistent999/embed');

        $this->assertSame(404, $r['code']);
        $this->assertFalse($r['json']['success']);
        $this->assertSame('AIRPORT_NOT_FOUND', $r['json']['error']['code'] ?? null);
    }

    public function testEmbedEndpoint_InvalidId_Returns400(): void
    {
        $this->skipIfApiDisabled();

        $r = $this->embedRequest('/airports/ab/embed');

        $this->assertSame(400, $r['code']);
        $this->assertSame('INVALID_REQUEST', $r['json']['error']['code'] ?? null);
    }

    public function testEmbedEndpoint_ReturnsCorsForThirdParty(): void
    {
        $this->skipIfApiDisabled();

        $r = $this->embedRequest('/airports/kspb/embed', ['Origin: https://example.com']);

        $this->assertSame(200, $r['code'], 'Embed endpoint should return 200');
        $corsHeader = null;
        foreach ($r['headers'] as $k => $v) {
            if (strtolower($k) === 'access-control-allow-origin') {
                $corsHeader = $v;
                break;
            }
        }
        $this->assertNotNull($corsHeader, 'Embed endpoint should include CORS header for third-party');
        $this->assertSame('*', $corsHeader);
    }
}
