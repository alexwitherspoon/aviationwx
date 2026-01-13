#!/usr/bin/env php
<?php
/**
 * Webcam Rejection Cleanup Worker
 * 
 * Removes rejected webcam images and logs older than 7 days.
 * Backup cron cleanup handles files older than 30 days.
 * 
 * Run via cron: 0 STAR/6 * * * (every 6 hours, replace STAR with asterisk)
 */

require_once __DIR__ . '/../lib/config.php';
require_once __DIR__ . '/../lib/logger.php';
require_once __DIR__ . '/../lib/webcam-rejection-logger.php';

// Set up invocation tracking
$invocationId = aviationwx_get_invocation_id();
$triggerInfo = aviationwx_detect_trigger_type();

aviationwx_log('info', 'webcam rejection cleanup worker started', [
    'invocation_id' => $invocationId,
    'trigger' => $triggerInfo['trigger'],
    'context' => $triggerInfo['context']
], 'app');

// Run cleanup (7 day retention)
$cleanupStats = cleanupOldRejections(7);

// Log results
if ($cleanupStats['files_removed'] > 0) {
    aviationwx_log('info', 'rejection cleanup completed', [
        'cameras_checked' => $cleanupStats['cameras_checked'],
        'files_removed' => $cleanupStats['files_removed'],
        'bytes_freed' => $cleanupStats['bytes_freed'],
        'mb_freed' => round($cleanupStats['bytes_freed'] / 1024 / 1024, 2)
    ], 'app');
    
    if (php_sapi_name() === 'cli') {
        echo "✓ Cleanup completed:\n";
        echo "  Cameras checked: {$cleanupStats['cameras_checked']}\n";
        echo "  Files removed: {$cleanupStats['files_removed']}\n";
        echo "  Space freed: " . round($cleanupStats['bytes_freed'] / 1024 / 1024, 2) . " MB\n";
    }
} else {
    aviationwx_log('debug', 'rejection cleanup found no files to remove', [
        'cameras_checked' => $cleanupStats['cameras_checked']
    ], 'app');
    
    if (php_sapi_name() === 'cli') {
        echo "✓ No old rejections to clean up (cameras checked: {$cleanupStats['cameras_checked']})\n";
    }
}

// Log any errors
if (!empty($cleanupStats['errors'])) {
    aviationwx_log('warning', 'rejection cleanup had errors', [
        'errors' => $cleanupStats['errors']
    ], 'app');
    
    if (php_sapi_name() === 'cli') {
        echo "⚠ Errors encountered:\n";
        foreach ($cleanupStats['errors'] as $error) {
            echo "  - $error\n";
        }
    }
}

aviationwx_log('info', 'webcam rejection cleanup worker completed', [
    'invocation_id' => $invocationId
], 'app');

exit(0);
