<?php
/**
 * Scheduler watchdog (cron): confirm health and recover only when needed
 *
 * Startup model: docker-entrypoint.sh starts one scheduler daemon after cache setup (initial start).
 * This script runs every minute as www-data. It reads /tmp/scheduler.lock and /proc, then:
 * - Does nothing when the daemon is healthy and consistent with the lock file.
 * - Recovers when appropriate: missing process, lock marked unhealthy, stale lock with no live
 *   daemon to own the path, etc. It is not a second routine starter when a healthy daemon exists.
 *
 * Uses /proc for PID checks (works across user boundaries). Kills only when the lock reports
 * unhealthy, not for lock_stale alone against a live PID (see in-script comments).
 *
 * Usage:
 *   Via cron: * * * * * www-data cd /var/www/html && /usr/local/bin/php scripts/scheduler-health-check.php 2>&1
 */

require_once __DIR__ . '/../lib/config.php';
require_once __DIR__ . '/../lib/logger.php';
require_once __DIR__ . '/../lib/process-utils.php';

$hadFailure = false;

try {
    $lockFile = '/tmp/scheduler.lock';
    $schedulerScript = __DIR__ . '/scheduler.php';
    $maxLockAge = 120; // Consider stale if lock >2 minutes old

    $daemonPidsSnapshot = listSchedulerDaemonPids();
    if (count($daemonPidsSnapshot) > 1) {
        aviationwx_log('error', 'scheduler health check: multiple scheduler daemons detected', [
            'daemon_pids' => $daemonPidsSnapshot,
            'hint' => 'Read-only inspect: php scripts/diagnose-scheduler-duplicates.php; then deploy plus web restart (see docs/OPERATIONS.md)'
        ], 'app');
    }

    // Check if scheduler is running
    $schedulerRunning = false;
    $schedulerPid = null;
    $lockData = null;
    $needsRestart = false;
    $restartReason = '';

    // Read lock file
    if (file_exists($lockFile)) {
        $lockContent = @file_get_contents($lockFile);
        if ($lockContent) {
            $lockData = json_decode($lockContent, true);
            if ($lockData && isset($lockData['pid'])) {
                $schedulerPid = (int)$lockData['pid'];

                // Verify PID is running using /proc filesystem (works across user boundaries)
                if ($schedulerPid > 0) {
                    $schedulerRunning = isProcessRunning($schedulerPid, 'scheduler');
                }
            }
        }

        // Check lock age
        $lockAge = time() - filemtime($lockFile);
        if ($lockAge > $maxLockAge) {
            $needsRestart = true;
            $restartReason = 'lock_stale';
        }
    }

    // Check health status
    if ($lockData && isset($lockData['health'])) {
        if ($lockData['health'] !== 'healthy') {
            $needsRestart = true;
            $restartReason = 'unhealthy';
        }
    }

    // Check if scheduler is missing
    if (!$schedulerRunning && !$needsRestart) {
        $needsRestart = true;
        $restartReason = 'not_running';
    }

    // Recovery path: entrypoint already started the daemon at boot; this block only repairs outages.
    if ($needsRestart) {
        // Check if we can signal the process (have permission to kill it)
        $canKill = $schedulerPid && canSignalProcess($schedulerPid);

        // Kill only when the lock reports unhealthy; lock_stale alone must not SIGTERM a live PID
        // (first scheduler loop can exceed maxLockAge before the first updateLockFile write).
        if ($schedulerPid && $schedulerRunning && $canKill && $restartReason === 'unhealthy') {
            aviationwx_log('info', 'scheduler health check: killing unhealthy scheduler', [
                'pid' => $schedulerPid,
                'reason' => $restartReason
            ], 'app');

            posix_kill($schedulerPid, SIGTERM);
            sleep(2);

            // Force kill if still running
            if (isProcessRunning($schedulerPid, 'scheduler')) {
                posix_kill($schedulerPid, SIGKILL);
            }
        } elseif ($schedulerPid && $schedulerRunning && !$canKill) {
            // Process is running but we can't kill it (permission denied); unexpected if scheduler is www-data
            aviationwx_log('warning', 'scheduler health check: cannot kill scheduler - permission denied', [
                'pid' => $schedulerPid,
                'reason' => $restartReason,
                'hint' => 'Scheduler process is not owned by www-data; investigate startup user'
            ], 'app');
            $hadFailure = true;
            exit(1);
        }

        $daemonPids = listSchedulerDaemonPids();

        // Never unlink or spawn while any scheduler daemon is alive: the lock path can point at a
        // different inode than the running process holds (unlink + second flock creates duplicates).
        if ($daemonPids !== []) {
            $lockMismatch = $schedulerPid === null || !in_array($schedulerPid, $daemonPids, true);
            if (count($daemonPids) > 1 || $lockMismatch) {
                aviationwx_log('warning', 'scheduler health check: restart suppressed - scheduler daemon already running', [
                    'daemon_pids' => $daemonPids,
                    'restart_reason' => $restartReason,
                    'lock_pid' => $schedulerPid
                ], 'app');
            }
        } elseif (!isProcessRunning($schedulerPid ?? 0, 'scheduler')) {
            @unlink($lockFile);

            // Start new scheduler (explicit cwd: cron provides it today, but stay correct if invoked elsewhere)
            $projectRoot = dirname(__DIR__);
            $command = sprintf(
                'cd %s && nohup /usr/local/bin/php %s > /dev/null 2>&1 &',
                escapeshellarg($projectRoot),
                escapeshellarg($schedulerScript)
            );
            exec($command);

            aviationwx_log('info', 'scheduler health check: restarted scheduler', [
                'reason' => $restartReason,
                'old_pid' => $schedulerPid
            ], 'app');
        }
    }

    // Note: We don't log healthy status to avoid log spam
    // Health check runs every 60s, so logging would be very verbose
} catch (Exception $e) {
    $hadFailure = true;

    aviationwx_log('error', 'scheduler health check: exception during check', [
        'error' => $e->getMessage(),
        'trace' => $e->getTraceAsString()
    ], 'app');
}
