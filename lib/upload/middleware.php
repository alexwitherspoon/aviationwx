<?php
/**
 * WiFiLogger upload middleware: path-token resolution and per-token rate limits.
 *
 * Upload differs from the bridge path in one way that matters: the device cannot
 * send an X-Api-Key header, so the credential rides in the URL path segment. The
 * resolved token still binds to airport + wid via resolveUploadApiKey().
 */

require_once __DIR__ . '/keys.php';
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/../public-api/rate-limit.php';
require_once __DIR__ . '/../public-api/config.php';
require_once __DIR__ . '/../public-api/response.php';

if (!defined('UPLOAD_API_ERROR_INVALID_TOKEN')) {
    define('UPLOAD_API_ERROR_INVALID_TOKEN', 'INVALID_UPLOAD_TOKEN');
}

/**
 * Extract the token from an upload path, or null when the path is not a
 * wifilogger push URL.
 *
 * @param string $path Path after /v1 (e.g. /upload/weather/wifilogger/awxu_...)
 * @return string|null Token segment, or null
 */
function extractUploadTokenFromPath(string $path): ?string
{
    $prefix = '/upload/weather/wifilogger/';
    if (!str_starts_with($path, $prefix)) {
        return null;
    }
    $token = substr($path, strlen($prefix));
    if ($token === '' || str_contains($token, '/')) {
        return null;
    }
    return $token;
}

/**
 * Classify an upload path into a rate-limit class.
 *
 * A single upload class covers all providers for now; future providers
 * (Weather Display, WeeWX) can split here without changing callers.
 *
 * @param string $path Path after /v1
 * @return string upload
 */
function classifyUploadEndpoint(string $path): string
{
    return 'upload';
}

/**
 * Rate limits by upload endpoint class.
 *
 * Scaled for a WiFiLogger's ~1s interval (86400/day) plus headroom for bursts
 * after an outage. Shared across a per-token bucket.
 *
 * @param string $endpointClass upload
 * @return array{requests_per_minute: int, requests_per_hour: int, requests_per_day: int}
 */
function getUploadApiRateLimits(string $endpointClass): array
{
    return [
        'requests_per_minute' => 90,
        'requests_per_hour' => 5400,
        'requests_per_day' => 86400,
    ];
}

/**
 * Opaque rate-limit bucket id for an upload token.
 *
 * Hash so raw token material never appears in logs while buckets stay per
 * token + endpoint class.
 *
 * @param string $token Upload token
 * @param string $endpointClass upload
 * @return string Identifier for checkAndIncrementWindow()
 */
function uploadApiRateLimitIdentifier(string $token, string $endpointClass): string
{
    return 'upload:' . $endpointClass . ':' . hash('sha256', $token);
}

/**
 * Check upload rate limits using Public API window helpers with an upload-prefixed key.
 *
 * @param string $token Upload token
 * @param string $endpointClass upload
 * @return array{allowed: bool, limits: array, remaining: array, reset: array, retry_after: int|null}
 */
function checkUploadApiRateLimit(string $token, string $endpointClass): array
{
    $limits = getUploadApiRateLimits($endpointClass);
    $identifier = uploadApiRateLimitIdentifier($token, $endpointClass);
    $now = time();
    $windows = [
        'minute' => ['limit' => $limits['requests_per_minute'], 'seconds' => 60],
        'hour' => ['limit' => $limits['requests_per_hour'], 'seconds' => 3600],
        'day' => ['limit' => $limits['requests_per_day'], 'seconds' => 86400],
    ];

    $remaining = [];
    $reset = [];
    $allowed = true;
    $retryAfter = null;

    foreach ($windows as $windowName => $windowConfig) {
        $result = checkAndIncrementWindow(
            $identifier,
            $windowName,
            $windowConfig['limit'],
            $windowConfig['seconds']
        );
        $remaining[$windowName] = $result['remaining'];
        $reset[$windowName] = $result['reset'];
        if (!$result['allowed']) {
            $allowed = false;
            $windowRetry = $result['reset'] - $now;
            if ($retryAfter === null || $windowRetry < $retryAfter) {
                $retryAfter = max(1, $windowRetry);
            }
        }
    }

    return [
        'allowed' => $allowed,
        'limits' => [
            'minute' => $limits['requests_per_minute'],
            'hour' => $limits['requests_per_hour'],
            'day' => $limits['requests_per_day'],
        ],
        'remaining' => $remaining,
        'reset' => $reset,
        'retry_after' => $retryAfter,
    ];
}