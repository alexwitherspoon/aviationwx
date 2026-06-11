<?php
/**
 * NMS HTTP client: global backoff gate, header capture, and one bounded 429 retry.
 */

declare(strict_types=1);

require_once __DIR__ . '/../constants.php';
require_once __DIR__ . '/../logger.php';
require_once __DIR__ . '/../weather-backoff-headers.php';
require_once __DIR__ . '/circuit-breaker.php';
require_once __DIR__ . '/rate-limit.php';

/**
 * Seconds to sleep before one in-fetch 429 retry (capped).
 *
 * @param array<string, string> $responseHeaders Lowercase upstream headers
 */
function notamCompute429RetryWaitSeconds(array $responseHeaders): int
{
    $override = weatherBackoffOverrideSeconds(429, $responseHeaders);
    $wait = $override ?? BACKOFF_BASE_RATE_LIMIT;

    return min(max(1, $wait), NOTAM_429_RETRY_MAX_WAIT_SECONDS);
}

/**
 * Sleep before an in-fetch 429 retry (no-op in tests when notamTestSkipSleep is set).
 */
function notamSleepFor429Retry(int $seconds): void
{
    if (!empty($GLOBALS['notamTestSkipSleep'])) {
        $GLOBALS['notamTestSleepAccumulatedSeconds'] = (int) ($GLOBALS['notamTestSleepAccumulatedSeconds'] ?? 0) + $seconds;

        return;
    }

    sleep($seconds);
}

/**
 * Perform one NMS HTTP GET (overridable in tests via notamTestNmsHttpHandler).
 *
 * @return array{body: string|false, http_code: int, headers: array<string, string>, error: string}
 */
function notamPerformNmsHttpGet(string $url, string $bearerToken): array
{
    if (isset($GLOBALS['notamTestNmsHttpHandler']) && is_callable($GLOBALS['notamTestNmsHttpHandler'])) {
        $result = ($GLOBALS['notamTestNmsHttpHandler'])($url, $bearerToken);
        if (!is_array($result)) {
            return ['body' => false, 'http_code' => 0, 'headers' => [], 'error' => 'invalid test handler'];
        }

        return [
            'body' => $result['body'] ?? false,
            'http_code' => (int) ($result['http_code'] ?? 0),
            'headers' => is_array($result['headers'] ?? null) ? $result['headers'] : [],
            'error' => (string) ($result['error'] ?? ''),
        ];
    }

    $responseHeaders = [];
    $headerCollector = static function ($ch, string $line) use (&$responseHeaders): int {
        return circuitBreakerCollectCurlHeaderLine($responseHeaders, $line);
    };

    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL => $url,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 30,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_HTTPHEADER => [
            'Authorization: Bearer ' . $bearerToken,
            'nmsResponseFormat: AIXM',
            'Accept: application/json',
        ],
        CURLOPT_HEADERFUNCTION => $headerCollector,
    ]);

    $body = curl_exec($ch);
    $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error = curl_error($ch);

    return [
        'body' => $body,
        'http_code' => $httpCode,
        'headers' => $responseHeaders,
        'error' => $error,
    ];
}

/**
 * Execute one NMS GET with rate limiting, global backoff, and one 429 retry.
 *
 * @param string $endpoint Health endpoint key (location, geo, auth)
 * @param float $lastRequestTime Updated by rateLimitWait()
 * @return array{
 *   ok: bool,
 *   deferred: bool,
 *   http_code: int|null,
 *   body: string|false,
 *   headers: array<string, string>,
 *   error: string
 * }
 */
function notamExecuteNmsQuery(string $url, string $endpoint, float &$lastRequestTime, string $bearerToken): array
{
    $backoff = checkNotamGlobalBackoff();
    if ($backoff['skip']) {
        aviationwx_log('info', 'notam fetcher: deferred during global NMS backoff', [
            'endpoint' => $endpoint,
            'backoff_remaining' => $backoff['backoff_remaining'],
        ], 'app');

        return [
            'ok' => false,
            'deferred' => true,
            'http_code' => null,
            'body' => false,
            'headers' => [],
            'error' => '',
        ];
    }

    notamRateLimitAcquire();
    $lastRequestTime = microtime(true);

    $attempts = 0;
    while ($attempts < 2) {
        $attempts++;
        $response = notamPerformNmsHttpGet($url, $bearerToken);
        $httpCode = $response['http_code'];
        $headers = $response['headers'];

        if ($httpCode === 200 && $response['body'] !== false) {
            clearNotamGlobalBackoff();

            return [
                'ok' => true,
                'deferred' => false,
                'http_code' => $httpCode,
                'body' => $response['body'],
                'headers' => $headers,
                'error' => $response['error'],
            ];
        }

        $isRateLimit = notamGlobalBackoffShouldRecord($httpCode);
        if ($isRateLimit && $attempts === 1) {
            $waitSeconds = notamCompute429RetryWaitSeconds($headers);
            notamSleepFor429Retry($waitSeconds);
            notamRateLimitAcquire();
            $lastRequestTime = microtime(true);

            continue;
        }

        if ($isRateLimit) {
            recordNotamGlobalRateLimitFailure($httpCode, $headers);
        }

        return [
            'ok' => false,
            'deferred' => false,
            'http_code' => $httpCode > 0 ? $httpCode : null,
            'body' => $response['body'],
            'headers' => $headers,
            'error' => $response['error'],
        ];
    }

    return [
        'ok' => false,
        'deferred' => false,
        'http_code' => null,
        'body' => false,
        'headers' => [],
        'error' => 'unexpected retry loop exit',
    ];
}
