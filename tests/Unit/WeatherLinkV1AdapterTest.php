<?php
/**
 * WeatherLink v1 adapter tests (URL auth + NOAA Ext parse path).
 *
 * Incorrect pass encoding blocks stations that require a password; empty pass
 * must remain valid for stations that allow it.
 */

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../lib/config.php';
require_once __DIR__ . '/../../lib/weather/adapter/weatherlink-v1-api.php';

class WeatherLinkV1AdapterTest extends TestCase
{
    public function testBuildUrl_MissingCredentials_ReturnsNull(): void
    {
        $this->assertNull(WeatherLinkV1Adapter::buildUrl(['device_id' => '001D0ATEST01']));
        $this->assertNull(WeatherLinkV1Adapter::buildUrl(['api_token' => 'test_api_token']));
        $this->assertNull(WeatherLinkV1Adapter::buildUrl([]));
    }

    public function testBuildUrl_LegacyWithoutPassword_UsesEmptyPass(): void
    {
        $url = WeatherLinkV1Adapter::buildUrl([
            'device_id' => '001D0ATEST01',
            'api_token' => 'test_api_token',
        ]);

        $this->assertNotNull($url);
        $this->assertSame(
            'https://api.weatherlink.com/v1/NoaaExt.json?user=001D0ATEST01&pass=&apiToken=test_api_token',
            $url
        );
    }

    public function testBuildUrl_WithPassword_UrlEncodesPassParam(): void
    {
        $url = WeatherLinkV1Adapter::buildUrl([
            'device_id' => '001D0ATEST01',
            'api_token' => 'test_api_token',
            'password' => '@TestRanch',
        ]);

        $this->assertNotNull($url);
        $this->assertSame(
            'https://api.weatherlink.com/v1/NoaaExt.json?user=001D0ATEST01&pass=%40TestRanch&apiToken=test_api_token',
            $url
        );
    }

    public function testBuildUrl_PasswordWithAmpersand_UrlEncodesWithoutBreakingQuery(): void
    {
        $url = WeatherLinkV1Adapter::buildUrl([
            'device_id' => '001D0ATEST01',
            'api_token' => 'test_api_token',
            'password' => 'a&b=c',
        ]);

        $this->assertNotNull($url);
        $this->assertStringContainsString('pass=a%26b%3Dc&apiToken=test_api_token', $url);
        $this->assertStringNotContainsString('pass=a&b=c', $url);
    }

    public function testBuildUrl_EmptyPassword_UsesEmptyPassLikeLegacy(): void
    {
        $url = WeatherLinkV1Adapter::buildUrl([
            'device_id' => '001D0ATEST01',
            'api_token' => 'test_api_token',
            'password' => '',
        ]);

        $this->assertNotNull($url);
        $this->assertSame(
            'https://api.weatherlink.com/v1/NoaaExt.json?user=001D0ATEST01&pass=&apiToken=test_api_token',
            $url
        );
    }

    public function testParseResponse_MinimalNoaaExtJson_MapsCoreFields(): void
    {
        $json = json_encode([
            'temp_c' => 20.5,
            'dewpoint_c' => 10.0,
            'relative_humidity' => 55,
            'pressure_in' => 29.92,
            'wind_kt' => 8,
            'wind_degrees' => 270,
            'observation_time_rfc822' => 'Tue, 04 Aug 2026 12:00:00 +0000',
            'davis_current_observation' => [
                'wind_ten_min_gust_mph' => 12.0,
                'rain_day_in' => 0.05,
            ],
        ], JSON_THROW_ON_ERROR);

        $parsed = WeatherLinkV1Adapter::parseResponse($json);
        $this->assertIsArray($parsed);
        $this->assertEqualsWithDelta(20.5, $parsed['temperature'], 0.0001);
        $this->assertEqualsWithDelta(10.0, $parsed['dewpoint'], 0.0001);
        $this->assertSame(55.0, $parsed['humidity']);
        $this->assertEqualsWithDelta(29.92, $parsed['pressure'], 0.0001);
        $this->assertSame(8, $parsed['wind_speed']);
        $this->assertSame(270, $parsed['wind_direction']);
        // Davis gust is mph; adapter converts to knots
        $this->assertSame(10, $parsed['gust_speed']);
        $this->assertEqualsWithDelta(0.05, $parsed['precip_accum'], 0.0001);
        $this->assertNotNull($parsed['obs_time']);
    }

    public function testParseResponse_InvalidJson_ReturnsNull(): void
    {
        $this->assertNull(WeatherLinkV1Adapter::parseResponse(''));
        $this->assertNull(WeatherLinkV1Adapter::parseResponse('not-json'));
        $this->assertNull(WeatherLinkV1Adapter::parseResponse(null));
        // WeatherLink auth failures often return plain text with HTTP 200
        $this->assertNull(WeatherLinkV1Adapter::parseResponse('Invalid Request!'));
    }

    public function testLogSafeBodyPrefix_RedactsPassAndApiToken(): void
    {
        $raw = 'fail ?user=001D0ATEST01&pass=sekret&apiToken=tok123 trailing detail';
        $redacted = WeatherLinkV1Adapter::logSafeBodyPrefix($raw);

        $this->assertStringContainsString('pass=[redacted]', $redacted);
        $this->assertStringContainsString('apiToken=[redacted]', $redacted);
        $this->assertStringNotContainsString('sekret', $redacted);
        $this->assertStringNotContainsString('tok123', $redacted);
        $this->assertLessThanOrEqual(80, strlen($redacted));
    }
}
