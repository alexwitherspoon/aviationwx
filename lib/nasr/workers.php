<?php

/**
 * NASR background worker scheduling helpers.
 */

require_once __DIR__ . '/../cache-paths.php';
require_once __DIR__ . '/../constants.php';
require_once __DIR__ . '/../file-locks.php';
require_once __DIR__ . '/cache.php';
require_once __DIR__ . '/frequencies-cache.php';

/**
 * True when another NASR APT fetch worker holds the lock.
 */
function nasrAptFetchInProgress(): bool
{
    return exclusiveFileLockIsHeld(getNasrAptFetchLockPath());
}

/**
 * True when another NASR FRQ fetch worker holds the lock.
 */
function nasrFrqFetchInProgress(): bool
{
    return exclusiveFileLockIsHeld(getNasrFrqFetchLockPath());
}

/**
 * True when the NASR APT data file is present and readable.
 */
function nasrAptCacheDataPresent(): bool
{
    return is_readable(CACHE_NASR_APT_DATA_FILE);
}

/**
 * True when the NASR FRQ data file is present and readable.
 */
function nasrFrqCacheDataPresent(): bool
{
    return is_readable(CACHE_NASR_FRQ_DATA_FILE);
}

/**
 * True when the scheduler should spawn fetch-nasr-apt.php.
 */
function nasrAptWorkerShouldRun(): bool
{
    return nasrAptCacheNeedsRefresh() && !nasrAptFetchInProgress();
}

/**
 * True when the scheduler should spawn fetch-nasr-frq.php.
 *
 * FRQ waits for APT fetch so cycle metadata is not updated mid-download.
 */
function nasrFrqWorkerShouldRun(): bool
{
    return nasrAptCacheDataPresent()
        && nasrFrqCacheNeedsRefresh()
        && !nasrFrqFetchInProgress()
        && !nasrAptFetchInProgress();
}

/**
 * Scheduler backoff until the next APT spawn attempt.
 *
 * Missing data uses a short retry so cold start / wipe recovers without waiting a week.
 * Present caches that still need refresh (age / cycle) keep the weekly gate.
 */
function nasrAptSchedulerRetryInterval(): int
{
    if (!nasrAptCacheDataPresent()) {
        return (int) NASR_MISSING_RETRY_INTERVAL;
    }

    return (int) NASR_FETCH_CHECK_INTERVAL;
}

/**
 * Scheduler backoff until the next FRQ spawn attempt.
 *
 * @see nasrAptSchedulerRetryInterval()
 */
function nasrFrqSchedulerRetryInterval(): int
{
    if (!nasrFrqCacheDataPresent()) {
        return (int) NASR_MISSING_RETRY_INTERVAL;
    }

    return (int) NASR_FETCH_CHECK_INTERVAL;
}

/**
 * Whether the scheduler should enqueue an APT fetch on this tick.
 *
 * Early gate is presence + needs-refresh (validity/age/cycle) via
 * {@see nasrAptWorkerShouldRun()}, then interval since last attempt.
 * `$lastAttemptAt === 0` means never attempted in this process (due immediately).
 */
function nasrAptSchedulerShouldEnqueue(int $now, int $lastAttemptAt): bool
{
    if (!nasrAptWorkerShouldRun()) {
        return false;
    }

    return ($now - $lastAttemptAt) >= nasrAptSchedulerRetryInterval();
}

/**
 * Whether the scheduler should enqueue an FRQ fetch on this tick.
 *
 * @see nasrAptSchedulerShouldEnqueue()
 */
function nasrFrqSchedulerShouldEnqueue(int $now, int $lastAttemptAt): bool
{
    if (!nasrFrqWorkerShouldRun()) {
        return false;
    }

    return ($now - $lastAttemptAt) >= nasrFrqSchedulerRetryInterval();
}
