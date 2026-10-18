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
require_once __DIR__ . '/ingest.php';
require_once __DIR__ . '/reject-log.php';
require_once __DIR__ . '/../public-api/middleware.php';
require_once __DIR__ . '/../public-api/rate-limit.php';
require_once __DIR__ . '/../public-api/config.php';
require_once __DIR__ . '/../public-api/response.php';
require_once __DIR__ . '/../logger.php';

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
 * Redact the upload token from a path for logging.
 *
 * The awxu_ token is a URL-path credential; logs must not persist it. The reject
 * log keeps the full token on disk under cache/ (denied from web access).
 *
 * @param string $path Path after /v1 (or a full request path)
 * @return string Path with the token replaced
 */
function redactUploadTokenFromPath(string $path): string
{
    return preg_replace(
        '#/upload/weather/wifilogger/awxu_[A-Za-z0-9]+#',
        '/upload/weather/wifilogger/[redacted]',
        $path
    ) ?? $path;
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
            // Blocked until every exhausted window resets, so advertise the longest.
            if ($retryAfter === null || $windowRetry > $retryAfter) {
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
 * Process an upload API request: public API enabled, path token, rate limit.
 *
 * Exits with an error response on failure. The token is resolved from the path
 * (the device cannot send an X-Api-Key header).
 *
 * @param string $path Normalized path (e.g. /upload/weather/wifilogger/awxu_...)
 * @return array{
 *   airport_id: string,
 *   source_id: string,
 *   wid: string,
 *   mac: string,
 *   token: string,
 *   rate_limit: array,
 *   ip: string
 * }
 */
function processUploadApiRequest(string $path): array
{
    if (!isPublicApiEnabled()) {
        sendPublicApiError(
            PUBLIC_API_ERROR_API_NOT_ENABLED,
            'Public API is not enabled',
            404
        );
        exit;
    }

    $ip = getPublicApiClientIp();

    $token = extractUploadTokenFromPath($path);
    if ($token === null) {
        uploadLogUnauthorizedAttempt('MISSING_TOKEN', null, $ip);
        sendPublicApiError(
            UPLOAD_API_ERROR_INVALID_TOKEN,
            'Missing upload token in path',
            401
        );
        exit;
    }

    $binding = resolveUploadApiKey($token);
    if ($binding === null) {
        uploadLogUnauthorizedAttempt('INVALID_UPLOAD_TOKEN', $token, $ip);
        sendPublicApiError(
            UPLOAD_API_ERROR_INVALID_TOKEN,
            'Invalid or unknown upload token',
            401
        );
        exit;
    }

    $endpointClass = classifyUploadEndpoint($path);
    $rateLimitResult = checkUploadApiRateLimit($token, $endpointClass);
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

    aviationwx_log('info', 'upload api request', [
        'path' => redactUploadTokenFromPath($path),
        'airport_id' => $binding['airport_id'],
        'source_id' => $binding['source_id'],
        'endpoint_class' => $endpointClass,
        'ip' => $ip,
    ], 'upload');

    return [
        'airport_id' => $binding['airport_id'],
        'source_id' => $binding['source_id'],
        'wid' => $binding['wid'],
        'mac' => $binding['mac'],
        'token' => $token,
        'rate_limit' => $rateLimitResult,
        'ip' => $ip,
    ];
}

/**
 * Read and decode a JSON request body for upload POSTs.
 *
 * @param int $maxBytes Maximum body size
 * @return array{ok: bool, body?: array, error?: string}
 */
function uploadReadJsonBody(int $maxBytes = 65536): array
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
 * Log an unauthorized upload attempt (missing or unknown token) to the reject index.
 *
 * Also reads the MAC header and body so a unit aimed at the wrong URL still shows
 * up under its own key with its payload.
 *
 * @param string $reason MISSING_TOKEN|INVALID_UPLOAD_TOKEN
 * @param string|null $token Path token (null when missing)
 * @param string $ip Client IP
 * @return void
 */
function uploadLogUnauthorizedAttempt(string $reason, ?string $token, string $ip): void
{
    $mac = getWifiLoggerMacHeader();
    $body = null;
    $wid = null;
    $parsed = uploadReadJsonBody(131072);
    if ($parsed['ok'] && is_array($parsed['body'])) {
        $body = $parsed['body'];
        if (isset($body['wid']) && is_string($body['wid'])) {
            $wid = $body['wid'];
        }
    }
    uploadRecordReject($reason, $wid, $mac, $token, null, null, $ip, $body);
}

/**
 * Send the vendor reply envelope as JSON and exit.
 *
 * WiFiLogger expects a JSON body with error/status/... fields on any reply.
 *
 * @param array $envelope Envelope from uploadEnvelope()
 * @param int $httpCode HTTP status code
 * @return never
 */
function sendUploadJsonEnvelope(array $envelope, int $httpCode): never
{
    http_response_code($httpCode);
    header('Content-Type: application/json');
    echo json_encode($envelope, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}