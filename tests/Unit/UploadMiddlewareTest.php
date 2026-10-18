<?php
/**
 * Unit tests for WiFiLogger upload middleware helpers (path token + rate limits).
 */

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../lib/upload/keys.php';
require_once __DIR__ . '/../../lib/upload/middleware.php';

class UploadMiddlewareTest extends TestCase
{
    #[\PHPUnit\Framework\Attributes\DataProvider('tokenPathProvider')]
    public function testExtractUploadTokenFromPath(string $path, ?string $expected): void
    {
        $this->assertSame($expected, extractUploadTokenFromPath($path));
    }

    public static function tokenPathProvider(): array
    {
        $token = 'awxu_000000000000000000000000000000000000000000000001';
        return [
            'wifilogger' => ['/upload/weather/wifilogger/' . $token, $token],
            'not_upload' => ['/airports/kspb/weather', null],
            'bridge_weather' => ['/bridge/weather', null],
            'missing_token' => ['/upload/weather/wifilogger/', null],
            'trailing_segment' => ['/upload/weather/wifilogger/' . $token . '/extra', null],
        ];
    }

    public function testUploadApiRateLimitIdentifier_OmitsRawToken(): void
    {
        $token = 'awxu_000000000000000000000000000000000000000000000001';
        $id = uploadApiRateLimitIdentifier($token, 'upload');
        $this->assertStringStartsWith('upload:upload:', $id);
        $this->assertStringNotContainsString($token, $id);
        $this->assertSame('upload:upload:' . hash('sha256', $token), $id);
    }

    public function testGetUploadApiRateLimits_OneSecondIntervalHeadroom(): void
    {
        $limits = getUploadApiRateLimits('upload');
        $this->assertSame(90, $limits['requests_per_minute']);
        $this->assertSame(5400, $limits['requests_per_hour']);
        $this->assertSame(86400, $limits['requests_per_day']);
    }

    public function testClassifyUploadEndpoint_WifiloggerPath(): void
    {
        $this->assertSame('upload', classifyUploadEndpoint('/upload/weather/wifilogger/awxu_x'));
    }

    public function testRedactUploadTokenFromPath_MasksToken(): void
    {
        $token = 'awxu_000000000000000000000000000000000000000000000001';
        $redacted = redactUploadTokenFromPath('/upload/weather/wifilogger/' . $token);
        $this->assertSame('/upload/weather/wifilogger/[redacted]', $redacted);
        $this->assertStringNotContainsString($token, $redacted);
    }

    public function testRedactUploadTokenFromPath_LeavesOtherPathsAlone(): void
    {
        $this->assertSame('/airports/kspb/weather', redactUploadTokenFromPath('/airports/kspb/weather'));
    }
}