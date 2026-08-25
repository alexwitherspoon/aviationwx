<?php
/**
 * Integration tests for Public API operator filtering.
 *
 * Airport list matches airports.json equipment. Weathercam list filters by
 * operator. Weather remains the fused observation.
 */

use PHPUnit\Framework\TestCase;

class PublicApiOperatorTest extends TestCase
{
    private static string $apiBaseUrl;

    public static function setUpBeforeClass(): void
    {
        self::$apiBaseUrl = getenv('TEST_API_URL') ?: 'http://localhost:8080';
    }

    /**
     * GET JSON from a Public API v1 path.
     *
     * @param string $endpoint Path beginning with / (for example /airports)
     * @return array{status: int, json: array|null}
     */
    private function apiRequest(string $endpoint): array
    {
        usleep(100000);

        $url = self::$apiBaseUrl . '/api/v1' . $endpoint;
        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 10);
        curl_setopt($ch, CURLOPT_HTTPHEADER, ['Accept: application/json']);
        $body = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);

        return [
            'status' => $status,
            'json' => is_string($body) ? json_decode($body, true) : null,
        ];
    }

    /**
     * Skip when the test web server is not running or the Public API is disabled.
     *
     * @param array{status: int, json: array|null} $response API response
     */
    private function skipUnlessPublicApiOk(array $response): void
    {
        if ($response['status'] === 0) {
            $this->markTestSkipped('Web server not available at ' . self::$apiBaseUrl);
        }
        if ($response['status'] === 429) {
            $this->markTestSkipped('Rate limited - test requires API key');
        }
        if ($response['status'] === 404 && (($response['json']['error']['code'] ?? '') === 'API_NOT_ENABLED')) {
            $this->markTestSkipped('Public API is not enabled in configuration');
        }
    }

    /**
     * @param list<array<string, mixed>> $airports
     * @return array<string, mixed>|null
     */
    private function findAirport(array $airports, string $id): ?array
    {
        foreach ($airports as $airport) {
            if (($airport['id'] ?? '') === $id) {
                return $airport;
            }
        }
        return null;
    }

    public function testListAirports_WithoutOperatorKeepsFullWebcamCount(): void
    {
        $response = $this->apiRequest('/airports');
        $this->skipUnlessPublicApiOk($response);
        $this->assertSame(200, $response['status']);

        $airports = $response['json']['airports'] ?? [];
        $kspb = $this->findAirport($airports, 'kspb');
        $this->assertNotNull($kspb);
        $this->assertTrue($kspb['has_webcams']);
        $this->assertSame(2, $kspb['webcam_count']);
    }

    public function testListAirports_OperatorAviationwxIncludesKspbWithFilteredWebcamCount(): void
    {
        $response = $this->apiRequest('/airports?operator=aviationwx');
        $this->skipUnlessPublicApiOk($response);
        $this->assertSame(200, $response['status']);

        $airports = $response['json']['airports'] ?? [];
        $kspb = $this->findAirport($airports, 'kspb');
        $this->assertNotNull($kspb, 'kspb has AviationWX weathercams and weather sources');
        $this->assertTrue($kspb['has_weather']);
        $this->assertTrue($kspb['has_webcams']);
        $this->assertSame(1, $kspb['webcam_count']);
    }

    public function testListAirports_OperatorWsdotIncludesKspb(): void
    {
        $response = $this->apiRequest('/airports?operator=wsdot');
        $this->skipUnlessPublicApiOk($response);
        $this->assertSame(200, $response['status']);

        $airports = $response['json']['airports'] ?? [];
        $kspb = $this->findAirport($airports, 'kspb');
        $this->assertNotNull($kspb, 'kspb fixture has a wsdot weathercam');
        $this->assertTrue($kspb['has_weather'], 'Fused weather still exists at the airport');
        $this->assertTrue($kspb['has_webcams']);
        $this->assertSame(1, $kspb['webcam_count']);
    }

    public function testListAirports_OperatorNavcanadaIncludesCyav(): void
    {
        $response = $this->apiRequest('/airports?operator=navcanada');
        $this->skipUnlessPublicApiOk($response);
        $this->assertSame(200, $response['status']);

        $airports = $response['json']['airports'] ?? [];
        $this->assertNotNull($this->findAirport($airports, 'cyav'));
        $this->assertNull($this->findAirport($airports, 'kspb'));
    }

    public function testListAirports_HasWebcamsTrueIsIndependentOfOperator(): void
    {
        $response = $this->apiRequest('/airports?operator=faa&has_webcams=true');
        $this->skipUnlessPublicApiOk($response);
        $this->assertSame(200, $response['status']);

        $airports = $response['json']['airports'] ?? [];
        $kspb = $this->findAirport($airports, 'kspb');
        $this->assertNotNull($kspb, 'kspb matches faa via METAR and has weathercams');
        $this->assertTrue($kspb['has_webcams']);
        $this->assertSame(0, $kspb['webcam_count'], 'webcam_count stays operator-sliced');
        $this->assertNull($this->findAirport($airports, '03s'));
    }

    public function testListAirports_HasWebcamsTrueKeepsAviationwxCameras(): void
    {
        $response = $this->apiRequest('/airports?operator=aviationwx&has_webcams=true');
        $this->skipUnlessPublicApiOk($response);
        $this->assertSame(200, $response['status']);

        $airports = $response['json']['airports'] ?? [];
        $kspb = $this->findAirport($airports, 'kspb');
        $this->assertNotNull($kspb);
        $this->assertTrue($kspb['has_webcams']);
        $this->assertSame(1, $kspb['webcam_count']);
    }

    public function testListAirports_HasWeatherTrueKeepsKspb(): void
    {
        $response = $this->apiRequest('/airports?has_weather=true');
        $this->skipUnlessPublicApiOk($response);
        $this->assertSame(200, $response['status']);

        $airports = $response['json']['airports'] ?? [];
        $kspb = $this->findAirport($airports, 'kspb');
        $this->assertNotNull($kspb);
        $this->assertTrue($kspb['has_weather']);
    }

    public function testListAirports_HasWeatherFalseOmitsKspb(): void
    {
        $response = $this->apiRequest('/airports?has_weather=false');
        $this->skipUnlessPublicApiOk($response);
        $this->assertSame(200, $response['status']);

        $airports = $response['json']['airports'] ?? [];
        $this->assertNull($this->findAirport($airports, 'kspb'));
    }

    public function testListAirports_InvalidOperatorReturns400(): void
    {
        $response = $this->apiRequest('/airports?operator=not%20valid');
        $this->skipUnlessPublicApiOk($response);
        $this->assertSame(400, $response['status']);
        $this->assertSame('INVALID_REQUEST', $response['json']['error']['code'] ?? null);
    }

    public function testListWebcams_WithoutOperatorReturnsAllCamerasWithStableIndexes(): void
    {
        $response = $this->apiRequest('/airports/kspb/webcams');
        $this->skipUnlessPublicApiOk($response);
        $this->assertSame(200, $response['status']);
        $webcams = $response['json']['webcams'] ?? [];
        $this->assertCount(2, $webcams);
        $this->assertSame(0, $webcams[0]['index']);
        $this->assertSame('aviationwx', $webcams[0]['operator']);
        $this->assertSame(1, $webcams[1]['index']);
        $this->assertSame('wsdot', $webcams[1]['operator']);
    }

    public function testListWebcams_OperatorEqualsOnlyMatchingCameras(): void
    {
        $aviationwx = $this->apiRequest('/airports/kspb/webcams?operator=aviationwx');
        $this->skipUnlessPublicApiOk($aviationwx);
        $this->assertSame(200, $aviationwx['status']);
        $aviationwxCams = $aviationwx['json']['webcams'] ?? [];
        $this->assertCount(1, $aviationwxCams);
        $this->assertSame(0, $aviationwxCams[0]['index']);
        $this->assertSame('aviationwx', $aviationwxCams[0]['operator']);

        $wsdot = $this->apiRequest('/airports/kspb/webcams?operator=wsdot');
        $this->skipUnlessPublicApiOk($wsdot);
        $this->assertSame(200, $wsdot['status']);
        $wsdotCams = $wsdot['json']['webcams'] ?? [];
        $this->assertCount(1, $wsdotCams);
        $this->assertSame(1, $wsdotCams[0]['index']);
        $this->assertSame('wsdot', $wsdotCams[0]['operator']);
    }

    public function testListWebcams_InvalidOperatorReturns400(): void
    {
        $response = $this->apiRequest('/airports/kspb/webcams?operator=not%20valid');
        $this->skipUnlessPublicApiOk($response);
        $this->assertSame(400, $response['status']);
        $this->assertSame('INVALID_REQUEST', $response['json']['error']['code'] ?? null);
    }

    public function testGetWeather_IgnoresOperatorAndReturnsFusedRecord(): void
    {
        $plain = $this->apiRequest('/airports/kspb/weather');
        $this->skipUnlessPublicApiOk($plain);
        if ($plain['status'] === 503) {
            $this->markTestSkipped('Weather cache unavailable in this environment');
        }
        $this->assertSame(200, $plain['status']);

        $filtered = $this->apiRequest('/airports/kspb/weather?operator=wsdot');
        $this->skipUnlessPublicApiOk($filtered);
        $this->assertSame(200, $filtered['status']);

        $this->assertSame(
            $plain['json']['weather'] ?? null,
            $filtered['json']['weather'] ?? false,
            'Weather must stay fused and ignore operator'
        );
    }

    public function testWeathercamBulk_OperatorAviationwxIsCamOnlyWithAbsoluteUrls(): void
    {
        $response = $this->apiRequest('/weathercam/bulk?operator=aviationwx');
        $this->skipUnlessPublicApiOk($response);
        $this->assertSame(200, $response['status']);

        $airports = $response['json']['airports'] ?? [];
        $kspb = $this->findAirport($airports, 'kspb');
        $this->assertNotNull($kspb);
        $this->assertCount(1, $kspb['webcams']);
        $this->assertSame(0, $kspb['webcams'][0]['index']);
        $this->assertSame('aviationwx', $kspb['webcams'][0]['operator']);
        $this->assertSame(1, $kspb['webcam_count']);
        $this->assertStringContainsString('/airports/kspb/webcams/0/image', $kspb['webcams'][0]['image_url']);
        $this->assertMatchesRegularExpression('#^https?://#', $kspb['webcams'][0]['image_url']);
        $this->assertSame($kspb['webcams'][0]['image_url'], $kspb['webcams'][0]['images'][0]['url'] ?? null);
        $this->assertNull($this->findAirport($airports, '03s'));
    }

    public function testWeathercamBulk_OperatorFaaOmitsWeatherSourceOnlyMatches(): void
    {
        $response = $this->apiRequest('/weathercam/bulk?operator=faa');
        $this->skipUnlessPublicApiOk($response);
        $this->assertSame(200, $response['status']);
        $airports = $response['json']['airports'] ?? [];
        $this->assertNull($this->findAirport($airports, 'kspb'));
        $this->assertNull($this->findAirport($airports, '03s'));
    }

    public function testWeathercamBulk_InvalidOperatorReturns400(): void
    {
        $response = $this->apiRequest('/weathercam/bulk?operator=not%20valid');
        $this->skipUnlessPublicApiOk($response);
        $this->assertSame(400, $response['status']);
        $this->assertSame('INVALID_REQUEST', $response['json']['error']['code'] ?? null);
    }
}
