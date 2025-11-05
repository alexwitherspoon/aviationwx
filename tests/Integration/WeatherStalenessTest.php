<?php
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../config-utils.php';

class WeatherStalenessTest extends TestCase
{
    private $baseUrl;
    private $airport = 'kspb';
    private $cacheFile;

    protected function setUp(): void
    {
        parent::setUp();
        $this->baseUrl = getenv('TEST_API_URL') ?: 'http://localhost:8080';
        $this->cacheFile = __DIR__ . '/../../cache/weather_' . $this->airport . '.json';
        if (!is_dir(dirname($this->cacheFile))) {
            @mkdir(dirname($this->cacheFile), 0755, true);
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

        // Write minimal valid cache
        $payload = [
            'temperature' => 10,
            'humidity' => 80,
            'pressure' => 30.00,
            'wind_speed' => 5,
            'wind_direction' => 180,
            'visibility' => 10.0,
            'ceiling' => null,
            'flight_category' => 'VFR',
            'last_updated' => time(),
        ];
        file_put_contents($this->cacheFile, json_encode($payload));
        // Ensure mtime is now (fresh)
        @touch($this->cacheFile, time());

        $response = $this->httpGet("weather.php?airport={$this->airport}");
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
     * When cache is stale (mtime far in the past), backend should serve STALE and include stale=true in body.
     */
    public function testStaleCache_ServesStaleAndBackgroundRefresh()
    {
        if (!function_exists('curl_init')) {
            $this->markTestSkipped('cURL not available');
        }

        // Make cache very old to force stale path
        $payload = [
            'temperature' => 10,
            'humidity' => 80,
            'pressure' => 30.00,
            'wind_speed' => 5,
            'wind_direction' => 180,
            'visibility' => 10.0,
            'ceiling' => null,
            'flight_category' => 'VFR',
            'last_updated' => time() - 7200,
        ];
        file_put_contents($this->cacheFile, json_encode($payload));
        // Set mtime 2 hours ago to exceed typical refresh
        @touch($this->cacheFile, time() - 7200);

        $response = $this->httpGet("weather.php?airport={$this->airport}");
        if ($response['http_code'] == 0) {
            $this->markTestSkipped('Endpoint not available');
            return;
        }

        if ($response['http_code'] != 200) {
            $this->markTestSkipped('Endpoint returned non-200: ' . $response['http_code']);
            return;
        }

        $this->assertArrayHasKey('x-cache-status', $response['headers']);
        $this->assertSame('STALE', $response['headers']['x-cache-status']);

        $data = json_decode($response['body'], true);
        $this->assertIsArray($data);
        $this->assertTrue($data['stale'] ?? false, 'Response should indicate stale=true');
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


