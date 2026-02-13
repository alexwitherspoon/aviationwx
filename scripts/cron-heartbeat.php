<?php
/**
 * Cron Heartbeat Script
 * Writes a JSON-formatted heartbeat message to the log file
 * This script is called by cron to ensure reliable execution
 */

require_once __DIR__ . '/../lib/config.php';
require_once __DIR__ . '/../lib/sentry.php';

// Start Sentry cron monitor check-in
$checkInId = null;
if (isSentryAvailable()) {
    $checkInId = \Sentry\captureCheckIn(
        slug: 'cron-heartbeat',
        status: \Sentry\CheckInStatus::inProgress(),
        monitorConfig: new \Sentry\MonitorConfig(
            schedule: new \Sentry\MonitorSchedule(
                type: \Sentry\MonitorScheduleType::crontab(),
                value: '* * * * *', // Every minute
            ),
            checkinMargin: 2, // 2 minutes grace period
            maxRuntime: 1, // Should complete in 1 minute
            timezone: 'UTC',
        ),
    );
}

$logFile = '/var/log/aviationwx/cron-heartbeat.log';
$timestamp = gmdate('Y-m-d\TH:i:s+00:00');
$message = json_encode([
    'ts' => $timestamp,
    'level' => 'info',
    'message' => 'cron heartbeat',
    'source' => 'cron'
]) . "\n";

file_put_contents($logFile, $message, FILE_APPEND | LOCK_EX);

// Report success to Sentry
if (isSentryAvailable() && $checkInId) {
    \Sentry\captureCheckIn(
        slug: 'cron-heartbeat',
        status: \Sentry\CheckInStatus::ok(),
        checkInId: $checkInId,
    );
}

