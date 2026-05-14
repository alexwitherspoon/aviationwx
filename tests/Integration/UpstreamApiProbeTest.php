<?php
/**
 * Live upstream HTTPS probes (RainViewer, OpenWeatherMap, aviationweather.gov).
 *
 * These tests are **not** part of the default Integration suite (see phpunit.xml exclude).
 * They require outbound network and are opt-in via RUN_EXTERNAL_UPSTREAM_TESTS=1.
 *
 * Run locally or in a scheduled job:
 *
 *   make test-external-apis
 *
 * @see docs/TESTING.md
 */

use PHPUnit\Framework\TestCase;

class UpstreamApiProbeTest extends TestCase
{
    /**
     * Live upstream probes require explicit opt-in and must not use the PHPUnit fixture config path.
     */
    private function requireLiveUpstreamProbeEnvironment(): void
    {
        if (getenv('RUN_EXTERNAL_UPSTREAM_TESTS') !== '1') {
            $this->markTestSkipped(
                'Live upstream API checks are opt-in. Run: make test-external-apis (see docs/TESTING.md).'
            );
        }

        $configPath = (string) (getenv('CONFIG_PATH') ?: '');
        if (strpos($configPath, 'airports.json.test') !== false) {
            $this->markTestSkipped(
                'Refusing live upstream probes while CONFIG_PATH uses airports.json.test. Use phpunit.external-apis.xml or a non-fixture CONFIG_PATH.'
            );
        }
    }

    /**
     * Skip the rest of the test when the curl extension is missing.
     */
    private function assertCurlExtensionLoaded(): void
    {
        if (!function_exists('curl_init')) {
            $this->markTestSkipped('cURL not available');
        }
    }

    /**
     * Single HTTPS GET with shared probe timeouts.
     *
     * CurlHandle is released in a finally block (avoid curl_close; deprecated as a no-op in PHP 8.5).
     *
     * @param list<string>|null $headerLines CURLOPT_HTTPHEADER lines, or null to omit
     * @return array{body: string|false, http_code: int, curl_error: string, content_type: string}
     */
    private function curlGet(string $url, ?array $headerLines = null): array
    {
        $ch = curl_init();
        try {
            curl_setopt($ch, CURLOPT_URL, $url);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_TIMEOUT, 10);
            curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 5);
            curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
            if ($headerLines !== null) {
                curl_setopt($ch, CURLOPT_HTTPHEADER, $headerLines);
            }
            $body = curl_exec($ch);
            $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $curlError = (string) curl_error($ch);
            $rawType = curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
            $contentType = is_string($rawType) ? $rawType : '';

            return [
                'body' => $body,
                'http_code' => $httpCode,
                'curl_error' => $curlError,
                'content_type' => $contentType,
            ];
        } finally {
            unset($ch);
        }
    }

    /**
     * Extract the 12-character hex radar frame id from a weather-maps radar.past entry.
     *
     * @param array<string, mixed> $frame One element of radar.past
     */
    private static function rainViewerFrameIdFromPastEntry(array $frame): ?string
    {
        if (!isset($frame['path']) || !is_string($frame['path']) || $frame['path'] === '') {
            return null;
        }
        $parts = array_values(array_filter(explode('/', $frame['path'])));
        $last = strtolower(end($parts) ?: '');

        return preg_match('/^[0-9a-f]{12}$/', $last) === 1 ? $last : null;
    }

    /**
     * Test RainViewer API is accessible and returns expected data
     */
    public function testRainViewerApi_IsAccessibleAndReturnsExpectedData()
    {
        $this->requireLiveUpstreamProbeEnvironment();
        $this->assertCurlExtensionLoaded();

        $r = $this->curlGet('https://api.rainviewer.com/public/weather-maps.json');
        $response = $r['body'];
        $httpCode = $r['http_code'];
        $error = $r['curl_error'];

        $this->assertEquals(
            200,
            $httpCode,
            "RainViewer API should return 200 OK (got: $httpCode, error: $error)"
        );

        $this->assertNotEmpty($response, 'RainViewer API should return a non-empty body');
        $this->assertGreaterThan(400, strlen((string) $response), 'RainViewer weather-maps JSON should be a substantial payload');

        $data = json_decode((string) $response, true);
        $this->assertNotNull($data, 'RainViewer response should be valid JSON');
        $this->assertIsArray($data, 'RainViewer response should be an array');

        $this->assertArrayHasKey('radar', $data, 'Response should have radar key');
        $this->assertArrayHasKey('past', $data['radar'], 'Radar should have past frames');
        $this->assertIsArray($data['radar']['past'], 'Past frames should be an array');
        $this->assertNotEmpty($data['radar']['past'], 'Should have at least one past radar frame');

        $past = $data['radar']['past'];
        $firstFrame = $past[0];
        $this->assertArrayHasKey('time', $firstFrame, 'Frame should have time field');
        $this->assertIsInt($firstFrame['time'], 'Frame time should be an integer');
        $this->assertArrayHasKey('path', $firstFrame, 'Frame should have path field');
        $this->assertIsString($firstFrame['path'], 'Frame path should be a string');
        $this->assertMatchesRegularExpression(
            '#^/v2/radar/[0-9a-f]{12}$#i',
            (string) $firstFrame['path'],
            'First frame path should match /v2/radar/{12hex}'
        );
        $this->assertNotNull(self::rainViewerFrameIdFromPastEntry($firstFrame), 'First frame path should yield a 12 hex frame id');

        $lastKey = array_key_last($past);
        $this->assertNotNull($lastKey, 'Past frames should have a last key');
        $lastFrame = $past[$lastKey];
        $this->assertArrayHasKey('time', $lastFrame, 'Latest frame should have time field');
        $this->assertIsInt($lastFrame['time'], 'Latest frame time should be an integer');
        $this->assertArrayHasKey('path', $lastFrame, 'Latest frame should have path field');
        $this->assertIsString($lastFrame['path'], 'Latest frame path should be a string');
        $this->assertMatchesRegularExpression(
            '#^/v2/radar/[0-9a-f]{12}$#i',
            (string) $lastFrame['path'],
            'Latest frame path should match /v2/radar/{12hex}'
        );
        $this->assertNotNull(self::rainViewerFrameIdFromPastEntry($lastFrame), 'Latest frame path should yield a 12 hex frame id');
    }

    /**
     * Test OpenWeatherMap clouds tile layer is accessible
     */
    public function testOpenWeatherMapCloudsTiles_AreAccessible()
    {
        $this->requireLiveUpstreamProbeEnvironment();
        $this->assertCurlExtensionLoaded();

        $config = loadConfig();
        $apiKey = $config['config']['openweathermap_api_key'] ?? '';

        if (empty($apiKey)) {
            $this->markTestSkipped('OpenWeatherMap API key not configured in airports.json');
        }

        $url = 'https://tile.openweathermap.org/map/clouds_new/0/0/0.png?appid=' . rawurlencode($apiKey);
        $r = $this->curlGet($url);
        $response = $r['body'];
        $httpCode = $r['http_code'];
        $contentType = $r['content_type'];
        $error = $r['curl_error'];

        $this->assertEquals(
            200,
            $httpCode,
            "OpenWeatherMap tiles should return 200 OK (got: $httpCode, error: $error)"
        );

        $this->assertStringContainsString(
            'image/',
            $contentType,
            "OpenWeatherMap tiles should return image content type (got: $contentType)"
        );
        $this->assertNotEmpty($response, 'OpenWeatherMap tile body should be non-empty');
        $this->assertGreaterThan(200, strlen((string) $response), 'OpenWeatherMap tile should return a substantial image payload');
        $this->assertSame("\x89PNG", substr((string) $response, 0, 4), 'OpenWeatherMap tile body should start with PNG signature');
    }

    /**
     * Test aviationweather.gov METAR API is accessible
     */
    public function testAviationWeatherGov_MetarApiIsAccessible()
    {
        $this->requireLiveUpstreamProbeEnvironment();
        $this->assertCurlExtensionLoaded();

        $url = 'https://aviationweather.gov/api/data/metar?ids=KJFK&format=json&taf=false&hours=0';
        $r = $this->curlGet($url, ['User-Agent: AviationWX/1.0 (Test Suite)']);
        $response = $r['body'];
        $httpCode = $r['http_code'];
        $contentType = $r['content_type'];
        $error = $r['curl_error'];

        $this->assertEquals(
            200,
            $httpCode,
            "aviationweather.gov METAR API should return 200 OK (got: $httpCode, error: $error)"
        );

        $this->assertStringContainsString(
            'application/json',
            $contentType,
            "METAR API should return JSON (got: $contentType)"
        );

        $this->assertNotEmpty($response, 'METAR API should return data');

        $data = json_decode((string) $response, true);
        $this->assertNotNull($data, 'METAR response should be valid JSON');
        $this->assertIsArray($data, 'METAR response should be an array');
        $this->assertNotEmpty($data, 'Should have at least one METAR record');

        $firstMetar = $data[0];
        $this->assertArrayHasKey('icaoId', $firstMetar, 'METAR should have icaoId field');
        $this->assertArrayHasKey('rawOb', $firstMetar, 'METAR should have rawOb field');
        $this->assertArrayHasKey('obsTime', $firstMetar, 'METAR should have obsTime field');

        $this->assertEquals('KJFK', $firstMetar['icaoId'], 'Should return data for requested station');
    }

    /**
     * Test RainViewer tiles are accessible (actual tile request)
     */
    public function testRainViewerTiles_AreAccessible()
    {
        $this->requireLiveUpstreamProbeEnvironment();
        $this->assertCurlExtensionLoaded();

        $wm = $this->curlGet('https://api.rainviewer.com/public/weather-maps.json');
        $this->assertSame(200, $wm['http_code'], 'RainViewer weather-maps should return HTTP 200');
        $wmBody = $wm['body'];
        $this->assertNotEmpty($wmBody, 'RainViewer weather-maps body should be non-empty');
        $this->assertGreaterThan(400, strlen((string) $wmBody), 'RainViewer weather-maps should be a substantial JSON payload');

        $data = json_decode((string) $wmBody, true);
        if (!is_array($data) || empty($data['radar']['past'][0])) {
            $this->markTestSkipped('Could not parse radar.past from weather-maps');
        }

        $frameId = self::rainViewerFrameIdFromPastEntry($data['radar']['past'][0]);
        if ($frameId === null) {
            $this->markTestSkipped('Unexpected RainViewer path format in first past frame');
        }

        $tileUrl = "https://tilecache.rainviewer.com/v2/radar/{$frameId}/256/0/0/0/6/1_1.png";
        $tile = $this->curlGet($tileUrl);
        $tileBody = $tile['body'];
        $httpCode = $tile['http_code'];
        $contentType = $tile['content_type'];

        $this->assertSame(
            200,
            $httpCode,
            "RainViewer tiles should return 200 OK (got: $httpCode)"
        );

        $this->assertStringContainsString(
            'image/',
            $contentType,
            "RainViewer tiles should return image content type (got: $contentType)"
        );
        $this->assertNotEmpty($tileBody, 'RainViewer tile body should be non-empty');
        $this->assertGreaterThan(500, strlen((string) $tileBody), 'RainViewer tile should return a substantial image payload');
        $this->assertSame("\x89PNG", substr((string) $tileBody, 0, 4), 'RainViewer tile body should start with PNG signature');
    }
}
