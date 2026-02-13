<?php
/**
 * Scheduler Health Check
 * Validates scheduler is running and healthy
 * Restarts if unhealthy or missing
 * 
 * Runs via cron every 60 seconds to ensure scheduler stays running.
 * 
 * Note: This script uses /proc filesystem to detect process existence,
 * which works even when running as www-data checking a root-owned process.
 * However, killing/restarting a root-owned scheduler requires root privileges.
 * 
 * Usage:
 *   Via cron: * * * * * www-data cd /var/www/html && /usr/local/bin/php scripts/scheduler-health-check.php 2>&1
 */

require_once __DIR__ . '/../lib/config.php';
require_once __DIR__ . '/../lib/sentry.php';
require_once __DIR__ . '/../lib/logger.php';
require_once __DIR__ . '/../lib/process-utils.php';

// Start Sentry cron monitor check-in
$checkInId = null;
if (isSentryAvailable()) {
    $checkInId = \Sentry\captureCheckIn(
        slug: 'scheduler-health-check',
        status: \Sentry\CheckInStatus::inProgress(),
        monitorConfig: new \Sentry\MonitorConfig(
            schedule: new \Sentry\MonitorSchedule(
                type: \Sentry\MonitorScheduleType::crontab(),
                value: '* * * * *', // Every minute
            ),
            checkinMargin: 2, // 2 minutes grace period
            maxRuntime: 2, // Should complete in 2 minutes
            timezone: 'UTC',
        ),
    );
}

try {
    $lockFile = '/tmp/scheduler.lock';
    $schedulerScript = __DIR__ . '/scheduler.php';
    $maxLockAge = 120; // Consider stale if lock >2 minutes old

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

    // Restart if needed
    if ($needsRestart) {
        // Check if we can signal the process (have permission to kill it)
        $canKill = $schedulerPid && canSignalProcess($schedulerPid);
        
        // Kill existing process if running but unhealthy (only if we have permission)
        if ($schedulerPid && $schedulerRunning && $canKill) {
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
            // Process is running but we can't kill it (permission denied)
            // This happens when www-data tries to kill root-owned scheduler
            aviationwx_log('warning', 'scheduler health check: cannot kill scheduler - permission denied', [
                'pid' => $schedulerPid,
                'reason' => $restartReason,
                'hint' => 'Scheduler is owned by root but health check runs as www-data'
            ], 'app');
            // Don't try to restart - the existing scheduler is still running
            // A stale lock with running process likely means lock file wasn't updated
            return;
        }
        
        // Clean up lock file (only if process is actually dead or we killed it)
        if (!isProcessRunning($schedulerPid ?? 0, 'scheduler')) {
            @unlink($lockFile);
            
            // Start new scheduler
            $command = sprintf(
                'nohup /usr/local/bin/php %s > /dev/null 2>&1 &',
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
} finally {
    // Report success to Sentry (always report, even on early return/error)
    if (isSentryAvailable() && $checkInId) {
        \Sentry\captureCheckIn(
            slug: 'scheduler-health-check',
            status: \Sentry\CheckInStatus::ok(),
            checkInId: $checkInId,
        );
    }
}

