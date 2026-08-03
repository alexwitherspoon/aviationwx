<?php
/**
 * Bridge Public API middleware (required X-Api-Key, bridge-specific rate limits).
 */

require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/../public-api/config.php';
require_once __DIR__ . '/../public-api/response.php';
require_once __DIR__ . '/../public-api/rate-limit.php';
require_once __DIR__ . '/../logger.php';

if (!defined('BRIDGE_API_ERROR_INVALID_API_KEY')) {
    define('BRIDGE_API_ERROR_INVALID_API_KEY', 'INVALID_API_KEY');
}
if (!defined('BRIDGE_API_ERROR_METHOD_NOT_ALLOWED')) {
    define('BRIDGE_API_ERROR_METHOD_NOT_ALLOWED', 'METHOD_NOT_ALLOWED');
}

/**
 * Rate limits by bridge endpoint class.
 *
 * @param string $endpointClass bootstrap|health|weather
 * @return array{requests_per_minute: int, requests_per_hour: int, requests_per_day: int}
 */
function getBridgeApiRateLimits(string $endpointClass): array
{
    return match ($endpointClass) {
        'health' => [
            'requests_per_minute' => 3,
            'requests_per_hour' => 120,
            'requests_per_day' => 2000,
        ],
        'weather' => [
            'requests_per_minute' => 90,
            'requests_per_hour' => 4000,
            'requests_per_day' => 50000,
        ],
        default => [ // bootstrap
            'requests_per_minute' => 10,
            'requests_per_hour' => 120,
            'requests_per_day' => 1000,
        ],
    };
}

/**
 * Classify a bridge path into an endpoint class for rate limiting.
 *
 * @param string $path Path after /v1 (e.g. /bridge/health)
 * @return string bootstrap|health|weather
 */
function classifyBridgeEndpoint(string $path): string
{
    return match ($path) {
        '/bridge/health' => 'health',
        '/bridge/weather' => 'weather',
        default => 'bootstrap',
    };
}

/**
 * Check bridge rate limits using Public API window helpers with a bridge-prefixed key.
 *
 * @param string $apiKey Bridge API key
 * @param string $endpointClass bootstrap|health|weather
 * @return array{allowed: bool, limits: array, remaining: array, reset: array, retry_after: int|null}
 */
function checkBridgeApiRateLimit(string $apiKey, string $endpointClass): array
{
    $limits = getBridgeApiRateLimits($endpointClass);
    $identifier = 'bridge:' . $endpointClass . ':' . $apiKey;
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

/**
 * Process a bridge API request: CORS, Public API enabled, required key, rate limit.
 *
 * Exits with an error response on failure.
 *
 * @param string $path Normalized path (e.g. /bridge/bootstrap)
 * @return array{
 *   airport_id: string,
 *   bridge_id: string,
 *   label: string|null,
 *   bridge: array,
 *   api_key: string,
 *   rate_limit: array,
 *   ip: string
 * }
 */
function processBridgeApiRequest(string $path): array
{
    if (handlePublicApiCorsPreflightIfNeeded()) {
        exit;
    }

    if (!isPublicApiEnabled()) {
        sendPublicApiError(
            PUBLIC_API_ERROR_API_NOT_ENABLED,
            'Public API is not enabled',
            404
        );
        exit;
    }

    $apiKey = getBridgeApiKeyFromRequest();
    if ($apiKey === null) {
        sendPublicApiError(
            BRIDGE_API_ERROR_INVALID_API_KEY,
            'Missing X-Api-Key header',
            401
        );
        exit;
    }

    $binding = resolveBridgeApiKey($apiKey);
    if ($binding === null) {
        sendPublicApiError(
            BRIDGE_API_ERROR_INVALID_API_KEY,
            'Invalid or unknown bridge API key',
            401
        );
        exit;
    }

    $endpointClass = classifyBridgeEndpoint($path);
    $rateLimitResult = checkBridgeApiRateLimit($apiKey, $endpointClass);
    foreach (getPublicApiRateLimitHeaders($rateLimitResult) as $name => $value) {
        header($name . ': ' . $value);
    }
    if (!$rateLimitResult['allowed']) {
        sendPublicApiError(
            PUBLIC_API_ERROR_RATE_LIMITED,
            'Rate limit exceeded. Try again in ' . $rateLimitResult['retry_after'] . ' seconds.',
            429,
            $rateLimitResult['retry_after']
        );
        exit;
    }

    $ip = getPublicApiClientIp();
    aviationwx_log('info', 'bridge api request', [
        'path' => $path,
        'airport_id' => $binding['airport_id'],
        'bridge_id' => $binding['bridge_id'],
        'endpoint_class' => $endpointClass,
        'ip' => $ip,
    ], 'bridge');

    return [
        'airport_id' => $binding['airport_id'],
        'bridge_id' => $binding['bridge_id'],
        'label' => $binding['label'],
        'bridge' => $binding['bridge'],
        'api_key' => $apiKey,
        'rate_limit' => $rateLimitResult,
        'ip' => $ip,
    ];
}

/**
 * Read and decode a JSON request body for bridge POSTs.
 *
 * @param int $maxBytes Maximum body size
 * @return array{ok: bool, body?: array, error?: string}
 */
function bridgeReadJsonBody(int $maxBytes = 65536): array
{
    $raw = file_get_contents('php://input', false, null, 0, $maxBytes + 1);
    if ($raw === false) {
        return ['ok' => false, 'error' => 'Could not read request body'];
    }
    if (strlen($raw) > $maxBytes) {
        return ['ok' => false, 'error' => 'Request body too large'];
    }
    if (trim($raw) === '') {
        return ['ok' => false, 'error' => 'Request body is empty'];
    }
    $body = json_decode($raw, true);
    if (!is_array($body) || json_last_error() !== JSON_ERROR_NONE) {
        return ['ok' => false, 'error' => 'Request body must be JSON object'];
    }
    return ['ok' => true, 'body' => $body];
}

/**
 * Send HTTP 204 for an accepted bridge POST and exit.
 *
 * @param array<string, string> $extraHeaders Optional response headers
 * @return never
 */
function sendBridgeApiNoContent(array $extraHeaders = []): void
{
    http_response_code(204);
    $allowedOrigin = getCorsAllowOriginForAviationWx($_SERVER['HTTP_ORIGIN'] ?? null);
    if ($allowedOrigin !== null) {
        header('Access-Control-Allow-Origin: ' . $allowedOrigin);
    }
    header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
    header('Access-Control-Allow-Headers: X-API-Key, X-Api-Key, Content-Type');
    foreach ($extraHeaders as $name => $value) {
        header($name . ': ' . $value);
    }
    exit;
}
