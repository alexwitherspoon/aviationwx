<?php
/**
 * Integration Tests for Time API Endpoint
 *
 * Tests the /api/time.php endpoint that provides server UTC timestamp
 * for client-side clock skew detection.
 */

use PHPUnit\Framework\TestCase;

class TimeApiTest extends TestCase
{
    private static string $baseUrl;
    private static bool $serverAvailable = false;

    public static function setUpBeforeClass(): void
    {
        self::$baseUrl = getenv('TEST_API_URL') ?: 'http://localhost:8080';

        $ch = curl_init(self::$baseUrl);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 5);
        curl_setopt($ch, CURLOPT_NOBODY, true);
        curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);

        self::$serverAvailable = ($httpCode > 0);
    }

    private function skipIfServerUnavailable(): void
    {
        if (!self::$serverAvailable) {
            $this->markTestSkipped('Test server not running at ' . self::$baseUrl);
        }
    }

    /**
     * Fetch time endpoint response
     *
     * @param string $method HTTP method
     * @return array{code: int, body: string, json: ?array}
     */
    private function fetchTime(string $method = 'GET'): array
    {
        $url = self::$baseUrl . '/api/time.php';
        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 10);
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);

        return [
            'code' => $httpCode,
            'body' => $response,
            'json' => json_decode($response, true)
        ];
    }

    public function testTimeEndpoint_GetRequest_ReturnsValidJson(): void
    {
        $this->skipIfServerUnavailable();

        $response = $this->fetchTime('GET');

        $this->assertEquals(200, $response['code'], 'Time endpoint should return 200');
        $this->assertNotNull($response['json'], 'Response should be valid JSON');
        $this->assertArrayHasKey('time', $response['json'], 'Response should contain time');
        $this->assertIsInt($response['json']['time'], 'Time should be integer');
    }

    public function testTimeEndpoint_GetRequest_TimestampIsReasonable(): void
    {
        $this->skipIfServerUnavailable();

        $response = $this->fetchTime('GET');
        $timestamp = $response['json']['time'] ?? null;

        $this->assertNotNull($timestamp, 'Timestamp should not be null');
        $minTimestamp = strtotime('2024-01-01');
        $maxTimestamp = time() + 5;

        $this->assertGreaterThan($minTimestamp, $timestamp, 'Timestamp should be reasonable');
        $this->assertLessThanOrEqual($maxTimestamp, $timestamp, 'Timestamp should not be far in future');
    }

    public function testTimeEndpoint_PostRequest_Returns405(): void
    {
        $this->skipIfServerUnavailable();

        $response = $this->fetchTime('POST');

        $this->assertEquals(405, $response['code'], 'POST request should return 405');
    }
}
