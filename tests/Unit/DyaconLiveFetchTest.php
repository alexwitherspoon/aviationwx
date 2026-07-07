<?php
/**
 * DyaconLive HTTP fetch tests (401 retry, auth failures).
 */

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../lib/weather/dyaconlive-fetch.php';
require_once __DIR__ . '/../../lib/weather/dyaconlive-auth.php';
require_once __DIR__ . '/../mock-weather-responses.php';

class DyaconLiveFetchTest extends TestCase
{
    protected function tearDown(): void
    {
        unset($GLOBALS['dyaconliveTestHttpGetCallback'], $GLOBALS['dyaconliveTestBearerToken']);
        parent::tearDown();
    }

    public function testFetchDataResponse_401Then200_RetriesOnce(): void
    {
        $calls = 0;
        $GLOBALS['dyaconliveTestBearerToken'] = 'test-token';
        $GLOBALS['dyaconliveTestHttpGetCallback'] = static function (string $url, string $token) use (&$calls) {
            $calls++;
            if ($calls === 1) {
                return ['body' => null, 'http_code' => 401, 'response_headers' => []];
            }

            return [
                'body' => getMockDyaconLiveDataResponse(),
                'http_code' => 200,
                'response_headers' => [],
            ];
        };

        $source = [
            'type' => 'dyaconlive',
            'station_id' => 130114,
            'username' => 'user@example.com',
            'password' => 'secret',
        ];
        $airport = ['timezone' => 'America/Boise', 'elevation_ft' => 5335];

        $result = dyaconliveFetchDataResponse($source, $airport);
        $this->assertSame(2, $calls);
        $this->assertSame(200, $result['http_code']);
        $this->assertStringContainsString('air_temp', (string) $result['body']);
    }

    public function testFetchDataResponse_Persistent401_ReturnsFailure(): void
    {
        $calls = 0;
        $GLOBALS['dyaconliveTestBearerToken'] = 'test-token';
        $GLOBALS['dyaconliveTestHttpGetCallback'] = static function () use (&$calls) {
            $calls++;
            return ['body' => null, 'http_code' => 401, 'response_headers' => []];
        };

        $source = [
            'type' => 'dyaconlive',
            'station_id' => 130114,
            'username' => 'user@example.com',
            'password' => 'secret',
        ];
        $airport = ['timezone' => 'America/Boise'];

        $result = dyaconliveFetchDataResponse($source, $airport);
        $this->assertSame(2, $calls);
        $this->assertSame(401, $result['http_code']);
        $this->assertNull($result['body']);
    }

    public function testFetchDataResponse_InvalidConfig_ReturnsNullBody(): void
    {
        $result = dyaconliveFetchDataResponse(
            ['type' => 'dyaconlive', 'username' => 'u', 'password' => 'p'],
            ['timezone' => 'America/Boise']
        );
        $this->assertNull($result['body']);
        $this->assertNull($result['http_code']);
    }
}
