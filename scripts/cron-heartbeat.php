<?php
/**
 * Cron Heartbeat Script
 * Writes a JSON-formatted heartbeat message to the log file
 * This script is called by cron to ensure reliable execution
 */

require_once __DIR__ . '/../lib/config.php';

$logFile = '/var/log/aviationwx/cron-heartbeat.log';
$timestamp = gmdate('Y-m-d\TH:i:s+00:00');
$message = json_encode([
    'ts' => $timestamp,
    'level' => 'info',
    'message' => 'cron heartbeat',
    'source' => 'cron'
]) . "\n";

file_put_contents($logFile, $message, FILE_APPEND | LOCK_EX);

