<?php

/**
 * File locks for OurAirports workers and meta updates.
 */

require_once __DIR__ . '/../cache-paths.php';

/**
 * True when another process holds an exclusive lock on the lock file.
 */
function ourAirportsLockIsHeld(string $lockPath): bool
{
    if (!is_file($lockPath)) {
        return false;
    }

    $fp = @fopen($lockPath, 'c+');
    if ($fp === false) {
        return false;
    }

    $held = !@flock($fp, LOCK_EX | LOCK_NB);
    if (!$held) {
        @flock($fp, LOCK_UN);
    }
    fclose($fp);

    return $held;
}

/**
 * Acquire an exclusive lock or return false when another process holds it.
 *
 * @return resource|false
 */
function ourAirportsAcquireExclusiveLock(string $lockPath)
{
    $dir = dirname($lockPath);
    if (!is_dir($dir)) {
        ensureCacheDir($dir);
    }

    $fp = @fopen($lockPath, 'c+');
    if ($fp === false) {
        return false;
    }

    if (!@flock($fp, LOCK_EX | LOCK_NB)) {
        fclose($fp);
        return false;
    }

    return $fp;
}

/**
 * Release a lock acquired with ourAirportsAcquireExclusiveLock().
 *
 * @param resource $handle
 */
function ourAirportsReleaseExclusiveLock($handle, string $lockPath): void
{
    @flock($handle, LOCK_UN);
    fclose($handle);
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
