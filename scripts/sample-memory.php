#!/usr/bin/env php
<?php
/**
 * Memory Sampler - Background memory usage sampling
 * 
 * Samples memory usage every 5 seconds and stores in hourly buckets.
 * Designed to be run as a cron job (every minute with internal loop).
 * 
 * Unlike the original memory_history.json which depended on page loads,
 * this sampler runs continuously to provide CPU-load-style rolling averages.
 * 
 * Usage: php scripts/sample-memory.php
 * 
 * @package AviationWX
 */

require_once __DIR__ . '/../lib/config.php';
require_once __DIR__ . '/../lib/sentry.php';
require_once __DIR__ . '/../lib/memory-metrics.php';
require_once __DIR__ . '/../lib/logger.php';

// Start Sentry cron monitor check-in
$checkInId = null;
if (isSentryAvailable()) {
    $checkInId = \Sentry\captureCheckIn(
        slug: 'memory-sampler',
        status: \Sentry\CheckInStatus::inProgress(),
        monitorConfig: new \Sentry\MonitorConfig(
            schedule: new \Sentry\MonitorSchedule(
                type: \Sentry\MonitorScheduleType::crontab(),
                value: '* * * * *', // Every minute
            ),
            checkinMargin: 2, // 2 minutes grace period
            maxRuntime: 2, // Should complete in 2 minutes (12 samples * 5s)
            timezone: 'UTC',
        ),
    );
}

// Prevent multiple instances from running simultaneously
$lockFile = __DIR__ . '/../cache/memory-sampler.lock';
$fp = @fopen($lockFile, 'w');
if (!$fp || !flock($fp, LOCK_EX | LOCK_NB)) {
    // Another instance is running, exit silently
    exit(0);
}

// Sample 12 times (12 * 5 seconds = 60 seconds total)
// This matches cron's 1-minute interval
$sampleCount = 12;
$sampleInterval = 5;

for ($i = 0; $i < $sampleCount; $i++) {
    $startTime = microtime(true);
    
    // Get current memory usage
    $memoryBytes = memory_metrics_get_current();
    
    if ($memoryBytes !== null) {
        // Record sample
        $success = memory_metrics_record_sample($memoryBytes);
        
        if (!$success) {
            // Log failure but continue sampling
            aviationwx_log('warning', 'memory_sampler: failed to record sample', [
                'memory_mb' => round($memoryBytes / 1024 / 1024, 1),
                'iteration' => $i + 1
            ], 'app');
        }
    } else {
        // Memory reading failed - log and continue
        aviationwx_log('warning', 'memory_sampler: failed to read memory', [
            'iteration' => $i + 1
        ], 'app');
    }
    
    // Sleep for remainder of 5-second interval
    // Account for time spent sampling to maintain consistent 5-second spacing
    $elapsed = microtime(true) - $startTime;
    $sleepTime = max(0, $sampleInterval - $elapsed);
    
    if ($i < $sampleCount - 1 && $sleepTime > 0) {
        usleep((int)($sleepTime * 1000000));
    }
}

// Release lock
flock($fp, LOCK_UN);
fclose($fp);
@unlink($lockFile);

// Report success to Sentry
if (isSentryAvailable() && $checkInId) {
    \Sentry\captureCheckIn(
        slug: 'memory-sampler',
        status: \Sentry\CheckInStatus::ok(),
        checkInId: $checkInId,
    );
}

exit(0);
