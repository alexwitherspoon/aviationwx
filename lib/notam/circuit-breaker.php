<?php
/**
 * Shared NMS credential backoff after upstream rate-limit responses.
 */

declare(strict_types=1);

require_once __DIR__ . '/../cache-paths.php';
require_once __DIR__ . '/../circuit-breaker.php';
require_once __DIR__ . '/../constants.php';
require_once __DIR__ . '/rate-limit.php';

/**
 * Backoff key for the configured NMS API credential.
 */
function notamGlobalBackoffKey(): string
{
    return 'global_notam_' . notamRateLimitFingerprint();
}

/**
 * Whether an HTTP status should pause all NMS traffic for this credential.
 */
function notamGlobalBackoffShouldRecord(?int $httpCode): bool
{
    return $httpCode === 429 || $httpCode === HTTP_STATUS_SERVICE_UNAVAILABLE;
}

/**
 * Check whether NMS requests should be deferred for this credential.
 *
 * @param int|null $now Reference Unix timestamp for tests
 * @return array{skip: bool, reason: string, backoff_remaining: int, failures: int, last_failure_reason: string|null}
 */
function checkNotamGlobalBackoff(?int $now = null): array
{
    $default = [
        'skip' => false,
        'reason' => '',
        'backoff_remaining' => 0,
        'failures' => 0,
        'last_failure_reason' => null,
    ];

    if (!ensureCacheDir(CACHE_BASE_DIR)) {
        return $default;
    }

    $now = $now ?? time();
    $backoffFile = CACHE_BACKOFF_FILE;
    if (!file_exists($backoffFile)) {
        return $default;
    }

    clearstatcache(true, $backoffFile);
    $decoded = @json_decode((string) file_get_contents($backoffFile), true);
    $backoffData = is_array($decoded) ? $decoded : [];
    $key = notamGlobalBackoffKey();
    if (!isset($backoffData[$key])) {
        return $default;
    }

    $state = $backoffData[$key];
    if (!is_array($state)) {
        return $default;
    }
    $failures = (int) ($state['failures'] ?? 0);
    $nextAllowed = (int) ($state['next_allowed_time'] ?? 0);

    if ($failures < CIRCUIT_BREAKER_FAILURE_THRESHOLD || $nextAllowed <= $now) {
        return $default;
    }

    return [
        'skip' => true,
        'reason' => 'global_nms_backoff',
        'backoff_remaining' => $nextAllowed - $now,
        'failures' => $failures,
        'last_failure_reason' => $state['last_failure_reason'] ?? null,
    ];
}

/**
 * Pause all NMS requests until Retry-After, default backoff, or existing deadline (whichever is latest).
 *
 * @param int|null $httpCode HTTP status from NMS
 * @param array<string, string>|null $responseHeaders Lowercase upstream headers
 * @param int|null $now Reference Unix timestamp for tests
 */
function recordNotamGlobalRateLimitFailure(
    ?int $httpCode,
    ?array $responseHeaders = null,
    ?int $now = null
): void {
    if (!notamGlobalBackoffShouldRecord($httpCode) || !ensureCacheDir(CACHE_BASE_DIR)) {
        return;
    }

    $now = $now ?? time();
    $headers = $responseHeaders ?? [];
    $backoffSeconds = circuitBreakerComputeBackoffSeconds(1, 'transient', $httpCode, $headers, $now);
    if (weatherBackoffOverrideSeconds($httpCode, $headers, $now) === null) {
        $backoffSeconds = max($backoffSeconds, NOTAM_GLOBAL_BACKOFF_DEFAULT_SECONDS);
    }

    notamGlobalBackoffSetUntil($now + $backoffSeconds, $now, $httpCode);
}

/**
 * Clear shared NMS backoff after a successful upstream response.
 */
function clearNotamGlobalBackoff(): void
{
    if (!ensureCacheDir(CACHE_BASE_DIR)) {
        return;
    }

    recordCircuitBreakerSuccessBase(notamGlobalBackoffKey(), CACHE_BACKOFF_FILE);
}

/**
 * Persist a fleet-wide NMS pause with circuit-open failure count.
 *
 * @param int $nextAllowedTime Unix timestamp when NMS calls may resume
 * @param int|null $now Reference Unix timestamp for tests
 * @param int|null $httpCode HTTP status that triggered the pause
 */
function notamGlobalBackoffSetUntil(int $nextAllowedTime, ?int $now = null, ?int $httpCode = null): void
{
    if (!ensureCacheDir(CACHE_BASE_DIR)) {
        return;
    }

    $now = $now ?? time();
    $key = notamGlobalBackoffKey();
    $backoffFile = CACHE_BACKOFF_FILE;

    $fp = @fopen($backoffFile, 'c+');
    if ($fp === false) {
        return;
    }

    if (!@flock($fp, LOCK_EX)) {
        @fclose($fp);

        return;
    }

    $content = @stream_get_contents($fp);
    $decoded = ($content !== false && $content !== '')
        ? @json_decode($content, true)
        : null;
    $backoffData = is_array($decoded) ? $decoded : [];

    $existingNext = 0;
    $existingState = $backoffData[$key] ?? null;
    if (is_array($existingState)) {
        $existingNext = (int) ($existingState['next_allowed_time'] ?? 0);
    }
    $nextAllowedTime = max($nextAllowedTime, $existingNext);
    $backoffSeconds = max(0, $nextAllowedTime - $now);

    $backoffData[$key] = [
        'failures' => CIRCUIT_BREAKER_FAILURE_THRESHOLD,
        'next_allowed_time' => $nextAllowedTime,
        'last_attempt' => $now,
        'backoff_seconds' => $backoffSeconds,
        'last_http_code' => $httpCode,
        'last_error_time' => $now,
        'last_failure_reason' => $httpCode !== null ? "HTTP {$httpCode}" : 'NMS rate limit',
    ];

    @ftruncate($fp, 0);
    @rewind($fp);
    @fwrite($fp, json_encode($backoffData, JSON_PRETTY_PRINT));
    @fflush($fp);
    @flock($fp, LOCK_UN);
    @fclose($fp);
}
