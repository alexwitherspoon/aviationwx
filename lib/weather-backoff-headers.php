<?php
/**
 * Upstream rate-limit response headers for circuit breaker backoff.
 *
 * Pure helpers for Retry-After and optional X-RateLimit-* hints on 429/503.
 */

require_once __DIR__ . '/constants.php';

/**
 * Append one CURLOPT_HEADERFUNCTION line into a lowercase header map.
 *
 * @param array<string, string> $headers Accumulator (last value wins per name)
 * @param string $line Raw header line from curl
 * @return int Bytes processed (required by CURLOPT_HEADERFUNCTION)
 */
function circuit_breaker_collect_curl_header_line(array &$headers, string $line): int
{
    $trimmed = rtrim($line, "\r\n");
    if ($trimmed === '' || strpos($trimmed, ':') === false) {
        return strlen($line);
    }

    [$name, $value] = explode(':', $trimmed, 2);
    $headers[strtolower(trim($name))] = trim($value);

    return strlen($line);
}

/**
 * Parse Retry-After as seconds until retry (RFC 7231 delay-seconds or HTTP-date).
 *
 * HTTP-date values use PHP strtotime (GMT suffix in the value is honored).
 *
 * @param string $value Raw Retry-After header value
 * @param int|null $now Reference time for HTTP-date deltas (default: time())
 * @return int|null Positive seconds, or null when absent/invalid/past
 */
function parse_retry_after_seconds(string $value, ?int $now = null): ?int
{
    $value = trim($value);
    if ($value === '') {
        return null;
    }

    $now = $now ?? time();

    if (ctype_digit($value)) {
        $seconds = (int) $value;

        return $seconds > 0 ? $seconds : null;
    }

    $target = strtotime($value);
    if ($target === false) {
        return null;
    }

    $delta = $target - $now;

    return $delta > 0 ? $delta : null;
}

/**
 * Clamp upstream-suggested wait to a safe maximum (15 minutes).
 *
 * @param int $seconds Unclamped wait from Retry-After or X-RateLimit-Reset delta
 * @return int Seconds in [1, BACKOFF_MAX_RETRY_AFTER_SECONDS]
 */
function weather_backoff_clamp_seconds(int $seconds): int
{
    return max(1, min(BACKOFF_MAX_RETRY_AFTER_SECONDS, $seconds));
}

/**
 * Suggested backoff from upstream headers for rate-limit style responses.
 *
 * Retry-After takes precedence; otherwise X-RateLimit-Reset (Unix epoch seconds).
 * Only applies to HTTP 429 and 503. Relative reset values (small integers) are ignored.
 *
 * @param int|null $httpCode HTTP status from the failed response
 * @param array<string, string> $responseHeaders Lowercase header name to value
 * @param int|null $now Reference time for reset delta (default: time())
 * @return int|null Clamped seconds, or null when no usable hint
 */
function weather_backoff_override_seconds(?int $httpCode, array $responseHeaders, ?int $now = null): ?int
{
    if ($httpCode !== 429 && $httpCode !== HTTP_STATUS_SERVICE_UNAVAILABLE) {
        return null;
    }

    if ($responseHeaders === []) {
        return null;
    }

    $now = $now ?? time();

    $retryAfter = $responseHeaders['retry-after'] ?? null;
    if (is_string($retryAfter) && $retryAfter !== '') {
        $parsed = parse_retry_after_seconds($retryAfter, $now);
        if ($parsed !== null) {
            return weather_backoff_clamp_seconds($parsed);
        }
    }

    $reset = $responseHeaders['x-ratelimit-reset'] ?? null;
    if (is_string($reset) && ctype_digit(trim($reset))) {
        $resetTs = (int) trim($reset);
        if ($resetTs > $now) {
            return weather_backoff_clamp_seconds($resetTs - $now);
        }
    }

    return null;
}

/**
 * Compute circuit breaker backoff seconds for one failure event.
 *
 * When upstream sends Retry-After or X-RateLimit-Reset on 429/503, uses the
 * longer of the default strategy and the header hint (header clamped to 15 min).
 * Skip logic still requires CIRCUIT_BREAKER_FAILURE_THRESHOLD consecutive failures.
 *
 * @param int $failures Consecutive failure count after this event is recorded
 * @param string $severity transient or permanent
 * @param int|null $httpCode HTTP status when available
 * @param array<string, string>|null $responseHeaders Lowercase upstream headers
 * @param int|null $now Reference time for header hints (default: time())
 * @return int Backoff seconds before next_allowed_time
 */
function circuit_breaker_compute_backoff_seconds(
    int $failures,
    string $severity,
    ?int $httpCode,
    ?array $responseHeaders = null,
    ?int $now = null
): int {
    if ($httpCode === 429) {
        $base = BACKOFF_BASE_RATE_LIMIT;
        $backoffSeconds = min(10, $base + ($failures - 1));
    } elseif ($severity === 'permanent') {
        $base = max(BACKOFF_BASE_SECONDS, pow(2, min($failures - 1, BACKOFF_MAX_FAILURES)) * BACKOFF_BASE_SECONDS);
        $backoffSeconds = min(BACKOFF_MAX_PERMANENT, (int) round($base * 2.0));
    } else {
        $base = max(BACKOFF_BASE_TRANSIENT, pow(2, min($failures - 1, BACKOFF_MAX_FAILURES)) * BACKOFF_BASE_TRANSIENT);
        $backoffSeconds = min(BACKOFF_MAX_TRANSIENT, (int) round($base));
    }

    if ($responseHeaders !== null && $responseHeaders !== []) {
        $override = weather_backoff_override_seconds($httpCode, $responseHeaders, $now);
        if ($override !== null) {
            $backoffSeconds = max($backoffSeconds, $override);
        }
    }

    return $backoffSeconds;
}
