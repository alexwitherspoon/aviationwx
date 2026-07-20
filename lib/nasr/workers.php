<?php

/**
 * NASR background worker scheduling helpers.
 */

require_once __DIR__ . '/../cache-paths.php';
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
    return nasrFrqCacheNeedsRefresh() && !nasrFrqFetchInProgress() && !nasrAptFetchInProgress();
}
