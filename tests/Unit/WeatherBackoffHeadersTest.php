<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../lib/weather-backoff-headers.php';
require_once __DIR__ . '/../../lib/circuit-breaker.php';
require_once __DIR__ . '/../../lib/cache-paths.php';

/**
 * @covers weather-backoff-headers.php
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
        $this->assertSame(60, parse_retry_after_seconds('60'));
        $this->assertSame(1, parse_retry_after_seconds('1'));
    }

    public function testParseRetryAfterSeconds_HttpDate(): void
    {
        $now = 1_770_000_000;
        $headerValue = gmdate('D, d M Y H:i:s', $now + 120) . ' GMT';
        $this->assertSame(120, parse_retry_after_seconds($headerValue, $now));
    }

    public function testParseRetryAfterSeconds_PastHttpDate_ReturnsNull(): void
    {
        $now = 1_770_000_300;
        $headerValue = gmdate('D, d M Y H:i:s', $now - 60) . ' GMT';
        $this->assertNull(parse_retry_after_seconds($headerValue, $now));
    }

    public function testParseRetryAfterSeconds_Invalid_ReturnsNull(): void
    {
        $this->assertNull(parse_retry_after_seconds('not-a-date'));
        $this->assertNull(parse_retry_after_seconds(''));
        $this->assertNull(parse_retry_after_seconds('0'));
    }

    public function testWeatherBackoffOverrideSeconds_RetryAfter_ClampedToMax(): void
    {
        $seconds = BACKOFF_MAX_RETRY_AFTER_SECONDS + 500;
        $override = weather_backoff_override_seconds(429, ['retry-after' => (string) $seconds]);
        $this->assertSame(BACKOFF_MAX_RETRY_AFTER_SECONDS, $override);
    }

    public function testWeatherBackoffOverrideSeconds_RetryAfter_429(): void
    {
        $override = weather_backoff_override_seconds(429, ['retry-after' => '45']);
        $this->assertSame(45, $override);
    }

    public function testWeatherBackoffOverrideSeconds_XRateLimitReset_WhenNoRetryAfter(): void
    {
        $now = 1_700_000_000;
        $override = weather_backoff_override_seconds(
            429,
            ['x-ratelimit-reset' => (string) ($now + 90)],
            $now
        );
        $this->assertSame(90, $override);
    }

    public function testWeatherBackoffOverrideSeconds_IgnoredFor404(): void
    {
        $this->assertNull(weather_backoff_override_seconds(404, ['retry-after' => '120']));
    }

    public function testWeatherBackoffOverrideSeconds_RetryAfter_503(): void
    {
        $override = weather_backoff_override_seconds(
            HTTP_STATUS_SERVICE_UNAVAILABLE,
            ['retry-after' => '30']
        );
        $this->assertSame(30, $override);
    }

    public function testWeatherBackoffOverrideSeconds_SmallResetIgnored(): void
    {
        $now = 1_700_000_000;
        $this->assertNull(weather_backoff_override_seconds(429, ['x-ratelimit-reset' => '90'], $now));
    }

    public function testCircuitBreakerComputeBackoffSeconds_UsesHeaderWhenLongerThan429Default(): void
    {
        $seconds = circuit_breaker_compute_backoff_seconds(2, 'transient', 429, ['retry-after' => '120']);
        $this->assertSame(120, $seconds);
    }

    public function testCircuitBreakerCollectCurlHeaderLine_NormalizesKeys(): void
    {
        $headers = [];
        circuit_breaker_collect_curl_header_line($headers, "Retry-After: 30\r\n");
        circuit_breaker_collect_curl_header_line($headers, "X-RateLimit-Reset: 999\r\n");
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
        $this->assertGreaterThanOrEqual(85, $result['backoff_remaining']);
        $this->assertLessThanOrEqual(90, $result['backoff_remaining']);
    }
}
