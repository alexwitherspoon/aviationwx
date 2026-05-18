<?php
/**
 * Exclusive non-blocking flock for the scheduler daemon lock file.
 *
 * Used by scripts/scheduler.php only. Kept in a small library so flock behavior is unit-tested.
 */

/**
 * Open the lock path and attempt LOCK_EX | LOCK_NB.
 *
 * Does not unlink the path. Unlinking while another process still holds an open file description
 * can rebind the path to a new inode and allow a second daemon to flock successfully.
 * A dead process releases flock automatically when its fds close.
 *
 * @param string $lockFile Absolute path to the lock file
 * @return array{ok: true, fp: resource}|array{ok: false, reason: 'fopen'|'flock'}
 */
function scheduler_lock_acquire_exclusive_nb(string $lockFile): array {
    $fp = @fopen($lockFile, 'c+');
    if ($fp === false) {
        return ['ok' => false, 'reason' => 'fopen'];
    }

    if (!@flock($fp, LOCK_EX | LOCK_NB)) {
        fclose($fp);

        return ['ok' => false, 'reason' => 'flock'];
    }

    return ['ok' => true, 'fp' => $fp];
}
