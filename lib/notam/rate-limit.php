<?php
/**
 * Cross-process NMS NOTAM API rate limiting (1 req/s shared credential).
 *
 * Scheduler workers each run fetchNotamsForAirport() in separate processes; a
 * per-process sleep is not enough when one worker finishes and the next starts
 * immediately. Uses the same flock-backed token buckets as weather upstream limits.
 */

require_once __DIR__ . '/../constants.php';
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../logger.php';
require_once __DIR__ . '/../upstream-rate-limit.php';

/**
 * Stable fingerprint for the configured NMS API credential (no secrets in logs).
 *
 * @return string 64-char hex digest
 */
function notamRateLimitFingerprint(): string
{
    $clientId = isset($GLOBALS['notamRateLimitTestClientId'])
        && is_string($GLOBALS['notamRateLimitTestClientId'])
        ? $GLOBALS['notamRateLimitTestClientId']
        : getNotamApiClientId();
    $baseUrl = isset($GLOBALS['notamRateLimitTestBaseUrl'])
        && is_string($GLOBALS['notamRateLimitTestBaseUrl'])
        ? $GLOBALS['notamRateLimitTestBaseUrl']
        : getNotamApiBaseUrl();

    $material = [
        'client_id' => trim($clientId),
        'base_url' => trim($baseUrl),
    ];
    ksort($material);

    return hash('sha256', 'notam_nms' . "\n" . json_encode($material, JSON_UNESCAPED_UNICODE));
}

/**
 * Poll interval while waiting for a NOTAM API token (microseconds).
 */
function notamRateLimitPollMicroseconds(): int
{
    if (isset($GLOBALS['notamRateLimitTestPollMicroseconds'])
        && is_int($GLOBALS['notamRateLimitTestPollMicroseconds'])
    ) {
        return $GLOBALS['notamRateLimitTestPollMicroseconds'];
    }

    return NOTAM_RATE_LIMIT_POLL_MICROSECONDS;
}

/**
 * Whether NOTAM rate limiting should enforce buckets in the current environment.
 */
function notamRateLimitShouldEnforce(): bool
{
    if (!empty($GLOBALS['notamRateLimitTestForceEnforcement'])) {
        return true;
    }

    return !isTestMode() && !shouldMockExternalServices();
}

/**
 * PHPUnit only: enforce NOTAM buckets while APP_ENV=testing.
 */
function notamRateLimitTestForceEnforcement(): void
{
    $GLOBALS['notamRateLimitTestForceEnforcement'] = true;
}

/**
 * PHPUnit only: restore default test-mode bypass for NOTAM throttling.
 */
function notamRateLimitTestClearForceEnforcement(): void
{
    unset($GLOBALS['notamRateLimitTestForceEnforcement']);
}

/**
 * Block until one NMS NOTAM request token is available (or fail open on timeout).
 *
 * Waits rather than skipping so location + geo queries for one airport still complete.
 */
function notamRateLimitAcquire(): void
{
    if (!notamRateLimitShouldEnforce()) {
        return;
    }

    $fingerprint = notamRateLimitFingerprint();
    if ($fingerprint === hash('sha256', 'notam_nms' . "\n" . json_encode([], JSON_UNESCAPED_UNICODE))) {
        // No client_id/base_url configured; fetch will fail at auth anyway.
        return;
    }

    $rpm = (int) (60 / max(NOTAM_RATE_LIMIT_SECONDS, 1));
    $burst = 1;
    $deadline = microtime(true) + NOTAM_RATE_LIMIT_MAX_WAIT_SECONDS;

    while (microtime(true) < $deadline) {
        if (upstreamRateTryTake($fingerprint, $rpm, $burst)) {
            return;
        }

        if (!empty($GLOBALS['notamRateLimitTestSkipSleep'])) {
            if (isset($GLOBALS['upstreamRateLimitTestNow']) && is_numeric($GLOBALS['upstreamRateLimitTestNow'])) {
                $GLOBALS['upstreamRateLimitTestNow'] = (float) $GLOBALS['upstreamRateLimitTestNow']
                    + (notamRateLimitPollMicroseconds() / 1_000_000);
            }

            continue;
        }

        usleep(notamRateLimitPollMicroseconds());
    }

    aviationwx_log('warning', 'notam rate limit wait timed out, allowing request', [
        'fingerprint_prefix' => substr($fingerprint, 0, 8),
        'max_wait_seconds' => NOTAM_RATE_LIMIT_MAX_WAIT_SECONDS,
    ], 'app');
}
