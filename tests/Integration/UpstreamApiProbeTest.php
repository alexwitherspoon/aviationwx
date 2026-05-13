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

        if (!function_exists('curl_init')) {
            $this->markTestSkipped('cURL not available');
        }

        $url = 'https://api.rainviewer.com/public/weather-maps.json';

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 10);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 5);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);

        $this->assertEquals(
            200,
            $httpCode,
            "RainViewer API should return 200 OK (got: $httpCode, error: $error)"
        );

        $this->assertNotEmpty($response, 'RainViewer API should return a non-empty body');
        $this->assertGreaterThan(400, strlen($response), 'RainViewer weather-maps JSON should be a substantial payload');

        $data = json_decode($response, true);
        $this->assertNotNull($data, 'RainViewer response should be valid JSON');
        $this->assertIsArray($data, 'RainViewer response should be an array');

        // Check for expected structure
        $this->assertArrayHasKey('radar', $data, 'Response should have radar key');
        $this->assertArrayHasKey('past', $data['radar'], 'Radar should have past frames');
        $this->assertIsArray($data['radar']['past'], 'Past frames should be an array');
        $this->assertNotEmpty($data['radar']['past'], 'Should have at least one past radar frame');

        // First and latest frames must include path + tile frame id (tilecache uses id, not Unix time in URL)
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

        if (!function_exists('curl_init')) {
            $this->markTestSkipped('cURL not available');
        }

        // Load config to get API key
        $config = loadConfig();
        $apiKey = $config['config']['openweathermap_api_key'] ?? '';

        if (empty($apiKey)) {
            $this->markTestSkipped('OpenWeatherMap API key not configured in airports.json');
        }

        // Test a sample tile (zoom 0, x 0, y 0 - world view) with full GET so we validate a non-empty image body
        $url = 'https://tile.openweathermap.org/map/clouds_new/0/0/0.png?appid=' . rawurlencode($apiKey);

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 10);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 5);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $contentType = curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
        $error = curl_error($ch);

        $this->assertEquals(
            200,
            $httpCode,
            "OpenWeatherMap tiles should return 200 OK (got: $httpCode, error: $error)"
        );

        $this->assertStringContainsString(
            'image/',
            (string) $contentType,
            "OpenWeatherMap tiles should return image content type (got: $contentType)"
        );
        $this->assertNotEmpty($response, 'OpenWeatherMap tile body should be non-empty');
        $this->assertGreaterThan(200, strlen($response), 'OpenWeatherMap tile should return a substantial image payload');
        $this->assertSame("\x89PNG", substr($response, 0, 4), 'OpenWeatherMap tile body should start with PNG signature');
    }

    /**
     * Test aviationweather.gov METAR API is accessible
     */
    public function testAviationWeatherGov_MetarApiIsAccessible()
    {
        $this->requireLiveUpstreamProbeEnvironment();

        if (!function_exists('curl_init')) {
            $this->markTestSkipped('cURL not available');
        }

        // Use a well-known airport (KJFK - JFK International)
        $url = 'https://aviationweather.gov/api/data/metar?ids=KJFK&format=json&taf=false&hours=0';

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 10);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 5);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'User-Agent: AviationWX/1.0 (Test Suite)',
        ]);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $contentType = curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
        $error = curl_error($ch);

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

        $data = json_decode($response, true);
        $this->assertNotNull($data, 'METAR response should be valid JSON');
        $this->assertIsArray($data, 'METAR response should be an array');
        $this->assertNotEmpty($data, 'Should have at least one METAR record');

        // Check first METAR has expected fields
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

        if (!function_exists('curl_init')) {
            $this->markTestSkipped('cURL not available');
        }

        // Fetch manifest with explicit success checks (non-empty JSON payload)
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, 'https://api.rainviewer.com/public/weather-maps.json');
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 10);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 5);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
        $wmBody = curl_exec($ch);
        $wmCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $this->assertSame(200, $wmCode, 'RainViewer weather-maps should return HTTP 200');
        $this->assertNotEmpty($wmBody, 'RainViewer weather-maps body should be non-empty');
        $this->assertGreaterThan(400, strlen($wmBody), 'RainViewer weather-maps should be a substantial JSON payload');

        $data = json_decode($wmBody, true);
        if (!is_array($data) || empty($data['radar']['past'][0])) {
            $this->markTestSkipped('Could not parse radar.past from weather-maps');
        }

        $frameId = self::rainViewerFrameIdFromPastEntry($data['radar']['past'][0]);
        if ($frameId === null) {
            $this->markTestSkipped('Unexpected RainViewer path format in first past frame');
        }

        // Full GET on tile: validate non-empty PNG body (HEAD alone does not prove payload)
        $tileUrl = "https://tilecache.rainviewer.com/v2/radar/{$frameId}/256/0/0/0/6/1_1.png";

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $tileUrl);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 10);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 5);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);

        $tileBody = curl_exec($ch);
        $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $contentType = (string) curl_getinfo($ch, CURLINFO_CONTENT_TYPE);

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
        $this->assertGreaterThan(500, strlen($tileBody), 'RainViewer tile should return a substantial image payload');
        $this->assertSame("\x89PNG", substr($tileBody, 0, 4), 'RainViewer tile body should start with PNG signature');
    }
}
