<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../lib/weather-backoff-headers.php';
require_once __DIR__ . '/../../lib/circuit-breaker.php';
require_once __DIR__ . '/../../lib/cache-paths.php';

/**
 * @covers ::circuitBreakerCollectCurlHeaderLine
 * @covers ::parseRetryAfterSeconds
 * @covers ::weatherBackoffClampSeconds
 * @covers ::weatherBackoffOverrideSeconds
 * @covers ::circuitBreakerComputeBackoffSeconds
 */
class WeatherBackoffHeadersTest extends TestCase
{
    private string $testAirportId = 'retry_hdr_test';

    protected function setUp(): void
    {
        parent::setUp();
        if (is_file(CACHE_BACKOFF_FILE)) {
            @unlink(CACHE_BACKOFF_FILE);
        }
        ensureCacheDir(CACHE_BASE_DIR);
    }

    protected function tearDown(): void
    {
        if (is_file(CACHE_BACKOFF_FILE)) {
            @unlink(CACHE_BACKOFF_FILE);
        }
        parent::tearDown();
    }

    public function testParseRetryAfterSeconds_Integer(): void
    {
        $this->assertSame(60, parseRetryAfterSeconds('60'));
        $this->assertSame(1, parseRetryAfterSeconds('1'));
    }

    public function testParseRetryAfterSeconds_HttpDate(): void
    {
        $now = 1_770_000_000;
        $headerValue = gmdate('D, d M Y H:i:s', $now + 120) . ' GMT';
        $this->assertSame(120, parseRetryAfterSeconds($headerValue, $now));
    }

    public function testParseRetryAfterSeconds_PastHttpDate_ReturnsNull(): void
    {
        $now = 1_770_000_300;
        $headerValue = gmdate('D, d M Y H:i:s', $now - 60) . ' GMT';
        $this->assertNull(parseRetryAfterSeconds($headerValue, $now));
    }

    public function testParseRetryAfterSeconds_Invalid_ReturnsNull(): void
    {
        $this->assertNull(parseRetryAfterSeconds('not-a-date'));
        $this->assertNull(parseRetryAfterSeconds(''));
        $this->assertNull(parseRetryAfterSeconds('0'));
    }

    public function testWeatherBackoffOverrideSeconds_RetryAfter_ClampedToMax(): void
    {
        $seconds = BACKOFF_MAX_RETRY_AFTER_SECONDS + 500;
        $override = weatherBackoffOverrideSeconds(429, ['retry-after' => (string) $seconds]);
        $this->assertSame(BACKOFF_MAX_RETRY_AFTER_SECONDS, $override);
    }

    public function testWeatherBackoffOverrideSeconds_RetryAfter_429(): void
    {
        $override = weatherBackoffOverrideSeconds(429, ['retry-after' => '45']);
        $this->assertSame(45, $override);
    }

    public function testWeatherBackoffOverrideSeconds_XRateLimitReset_WhenNoRetryAfter(): void
    {
        $now = 1_700_000_000;
        $override = weatherBackoffOverrideSeconds(
            429,
            ['x-ratelimit-reset' => (string) ($now + 90)],
            $now
        );
        $this->assertSame(90, $override);
    }

    public function testWeatherBackoffOverrideSeconds_IgnoredFor404(): void
    {
        $this->assertNull(weatherBackoffOverrideSeconds(404, ['retry-after' => '120']));
    }

    public function testWeatherBackoffOverrideSeconds_RetryAfter_503(): void
    {
        $override = weatherBackoffOverrideSeconds(
            HTTP_STATUS_SERVICE_UNAVAILABLE,
            ['retry-after' => '30']
        );
        $this->assertSame(30, $override);
    }

    public function testWeatherBackoffOverrideSeconds_SmallResetIgnored(): void
    {
        $now = 1_700_000_000;
        $this->assertNull(weatherBackoffOverrideSeconds(429, ['x-ratelimit-reset' => '90'], $now));
    }

    public function testCircuitBreakerComputeBackoffSeconds_UsesHeaderWhenLongerThan429Default(): void
    {
        $seconds = circuitBreakerComputeBackoffSeconds(2, 'transient', 429, ['retry-after' => '120']);
        $this->assertSame(120, $seconds);
    }

    public function testCircuitBreakerCollectCurlHeaderLine_NormalizesKeys(): void
    {
        $headers = [];
        circuitBreakerCollectCurlHeaderLine($headers, "Retry-After: 30\r\n");
        circuitBreakerCollectCurlHeaderLine($headers, "X-RateLimit-Reset: 999\r\n");
        $this->assertSame('30', $headers['retry-after']);
        $this->assertSame('999', $headers['x-ratelimit-reset']);
    }

    public function testRecordWeatherFailure_UsesRetryAfterWhenLongerThanDefault(): void
    {
        $headers = ['retry-after' => '90'];
        for ($i = 0; $i < CIRCUIT_BREAKER_FAILURE_THRESHOLD; $i++) {
            recordWeatherFailure($this->testAirportId, 'tempest', 'transient', 429, null, null, $headers);
        }

        $result = checkWeatherCircuitBreaker($this->testAirportId, 'tempest');
        $this->assertTrue($result['skip']);

        $key = $this->testAirportId . '_weather_tempest';
        $data = $this->readBackoffData();
        $this->assertArrayHasKey($key, $data);
        $this->assertSame(90, (int) ($data[$key]['backoff_seconds'] ?? 0));
        $this->assertGreaterThan(time(), (int) ($data[$key]['next_allowed_time'] ?? 0));
    }

    /**
     * @return array<string, mixed>
     */
    private function readBackoffData(): array
    {
        if (!is_file(CACHE_BACKOFF_FILE)) {
            return [];
        }

        return json_decode((string) file_get_contents(CACHE_BACKOFF_FILE), true) ?: [];
    }
}
