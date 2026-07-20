<?php

/**
 * File locks for OurAirports workers and meta updates.
 */

require_once __DIR__ . '/../cache-paths.php';
require_once __DIR__ . '/../file-locks.php';

/**
 * True when another process holds an exclusive lock on the lock file.
 */
function ourAirportsLockIsHeld(string $lockPath): bool
{
    return exclusiveFileLockIsHeld($lockPath);
}

/**
 * Acquire an exclusive lock or return false when another process holds it.
 *
 * @return resource|false
 */
function ourAirportsAcquireExclusiveLock(string $lockPath)
{
    return acquireExclusiveFileLock($lockPath);
}

/**
 * Release a lock acquired with ourAirportsAcquireExclusiveLock().
 *
 * @param resource $handle
 */
function ourAirportsReleaseExclusiveLock($handle, string $lockPath): void
{
    releaseExclusiveFileLock($handle, $lockPath);
}

/**
 * True when another bulk fetch worker holds the lock.
 */
function ourAirportsBulkFetchInProgress(): bool
{
    return ourAirportsLockIsHeld(CACHE_OURAIRPORTS_BULK_LOCK);
}

/**
 * True when another probe worker holds the lock.
 */
function ourAirportsProbeInProgress(): bool
{
    return ourAirportsLockIsHeld(CACHE_OURAIRPORTS_PROBE_LOCK);
}

/**
 * Run a callback while holding the OurAirports meta lock.
 *
 * @template T
 * @param callable(): T $callback
 * @return T|null Null when the lock could not be acquired
 */
function ourAirportsWithMetaLock(callable $callback)
{
    $fp = ourAirportsAcquireExclusiveLock(CACHE_OURAIRPORTS_META_LOCK);
    if ($fp === false) {
        return null;
    }

    try {
        return $callback();
    } finally {
        ourAirportsReleaseExclusiveLock($fp, CACHE_OURAIRPORTS_META_LOCK);
    }
}
