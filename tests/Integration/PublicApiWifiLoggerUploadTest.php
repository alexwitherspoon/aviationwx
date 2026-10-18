<?php
/**
 * Integration tests for the WiFiLogger direct upload endpoint.
 *
 * Exercises the middleware-to-handler path against the isolated HTTP test
 * container (make test-local / test-up), which serves the fixture config with a
 * seeded wifilogger row. Skipped unless TEST_CACHE_DIR identifies that cache.
 */

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../lib/cache-paths.php';

class PublicApiWifiLoggerUploadTest extends TestCase
{
    private const TOKEN = 'awxu_000000000000000000000000000000000000000000000001';
    private const WID = 'station-davis-01';
    private const MAC = 'aa:bb:cc:dd:ee:ff';

    private static string $apiBaseUrl;
    private static bool $fixtureReady = false;

    public static function setUpBeforeClass(): void
    {
        self::$apiBaseUrl = getenv('TEST_API_URL') ?: 'http://localhost:8080';

        $testCacheDir = getenv('TEST_CACHE_DIR');
        if (is_string($testCacheDir) && $testCacheDir !== '' && rtrim($testCacheDir, '/') === CACHE_BASE_DIR) {
            self::$fixtureReady = true;
        }
    }

    protected function setUp(): void
    {
        parent::setUp();
        if (!self::$fixtureReady) {
            $this->markTestSkipped('TEST_CACHE_DIR must identify the isolated HTTP test cache');
        }
        @unlink(CACHE_UPLOAD_WEATHER_DIR . '/kspb/' . self::WID . '/latest.json');
    }

    private function post(string $token, array $body, ?string $mac): array
    {
        $headers = ['Content-Type: application/json'];
        if ($mac !== null) {
            $headers[] = 'X-WiFiLogger-Sta-MAC: ' . $mac;
        }
        $ch = curl_init(self::$apiBaseUrl . '/api/v1/upload/weather/wifilogger/' . $token);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => json_encode($body),
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_TIMEOUT => 10,
        ]);
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        $decoded = json_decode((string) $response, true);
        return ['http_code' => $httpCode, 'body' => is_array($decoded) ? $decoded : []];
    }

    public function testRtdSuccess_ReturnsVendorEnvelopeAndPersists(): void
    {
        $result = $this->post(self::TOKEN, [
            'type' => 'rtd',
            'wid' => self::WID,
            'tutc' => time(),
            'tempout' => 678,
        ], self::MAC);

        $this->assertSame(200, $result['http_code']);
        $this->assertSame(0, $result['body']['error']);
        $this->assertSame('rtd_ok', $result['body']['status']);
        $this->assertSame(1, $result['body']['results']);

        $latest = json_decode((string) file_get_contents(
            CACHE_UPLOAD_WEATHER_DIR . '/kspb/' . self::WID . '/latest.json'
        ), true);
        $this->assertSame('wifilogger', $latest['provider']);
        $this->assertSame(678, $latest['provider_meta']['raw']['tempout']);
    }

    public function testRegistrationSuccess_ReturnsRegOk(): void
    {
        $result = $this->post(self::TOKEN, [
            'type' => 'registration',
            'wid' => self::WID,
            'mac' => self::MAC,
            'model' => 'VANTAGE-VUE',
        ], self::MAC);

        $this->assertSame(200, $result['http_code']);
        $this->assertSame('reg_ok', $result['body']['status']);
    }

    public function testUnknownToken_Returns401(): void
    {
        $result = $this->post('awxu_' . str_repeat('9', 48), [
            'type' => 'rtd',
            'wid' => self::WID,
        ], self::MAC);

        $this->assertSame(401, $result['http_code']);
    }

    public function testWrongMac_Returns403(): void
    {
        $result = $this->post(self::TOKEN, [
            'type' => 'rtd',
            'wid' => self::WID,
            'tutc' => time(),
        ], '11:22:33:44:55:66');

        $this->assertSame(403, $result['http_code']);
    }

    public function testMissingMac_Returns403(): void
    {
        $result = $this->post(self::TOKEN, [
            'type' => 'rtd',
            'wid' => self::WID,
            'tutc' => time(),
        ], null);

        $this->assertSame(403, $result['http_code']);
    }
}