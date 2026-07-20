<?php

/**
 * Shared exclusive file lock helpers for background workers.
 */

require_once __DIR__ . '/cache-paths.php';

/**
 * True when another process holds an exclusive lock on the lock file.
 */
function exclusiveFileLockIsHeld(string $lockPath): bool
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
function acquireExclusiveFileLock(string $lockPath)
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
 * Release a lock acquired with acquireExclusiveFileLock().
 *
 * @param resource $handle
 */
function releaseExclusiveFileLock($handle, string $lockPath): void
{
    @flock($handle, LOCK_UN);
    fclose($handle);
}
