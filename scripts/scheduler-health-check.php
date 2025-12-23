<?php
/**
 * Scheduler Health Check
 * Validates scheduler is running and healthy
 * Restarts if unhealthy or missing
 * 
 * Runs via cron every 60 seconds to ensure scheduler stays running.
 * 
 * Usage:
 *   Via cron: * * * * * www-data cd /var/www/html && /usr/local/bin/php scripts/scheduler-health-check.php 2>&1
 */

require_once __DIR__ . '/../lib/config.php';
require_once __DIR__ . '/../lib/logger.php';

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
            
            // Verify PID is running
            if ($schedulerPid > 0) {
                $schedulerRunning = posix_kill($schedulerPid, 0);
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
    // Kill existing process if running but unhealthy
    if ($schedulerPid && $schedulerRunning) {
        aviationwx_log('info', 'scheduler health check: killing unhealthy scheduler', [
            'pid' => $schedulerPid,
            'reason' => $restartReason
        ], 'app');
        
        posix_kill($schedulerPid, SIGTERM);
        sleep(2);
        
        // Force kill if still running
        if (posix_kill($schedulerPid, 0)) {
            posix_kill($schedulerPid, SIGKILL);
        }
    }
    
    // Clean up lock file
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

// Note: We don't log healthy status to avoid log spam
// Health check runs every 60s, so logging would be very verbose

