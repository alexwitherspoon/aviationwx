#!/usr/bin/env php
<?php
/**
 * Hourly cleanup of push FTP/SFTP upload inbox debris.
 *
 * Removes stale files whose extensions are not in the config-derived keep-list
 * (see getPushUploadAllowedExtensionsForCleanup()). Same behavior as the push debris
 * step in scripts/cleanup-cache.php Layer 1.
 *
 * Usage:
 *   php cleanup-push-upload-debris.php [--dry-run] [--verbose] [--help]
 */

if (php_sapi_name() !== 'cli') {
    http_response_code(403);
    die('This script must be run from the command line');
}

$options = getopt('', ['dry-run', 'verbose', 'help']);
if (isset($options['help'])) {
    echo "Usage: php cleanup-push-upload-debris.php [--dry-run] [--verbose]\n";
    exit(0);
}

$dryRun = isset($options['dry-run']);
$verbose = isset($options['verbose']);

chdir(__DIR__ . '/..');

require_once __DIR__ . '/../lib/push-upload-debris-cleanup.php';

$stats = [
    'files_checked' => 0,
    'files_deleted' => 0,
    'bytes_freed' => 0,
    'errors' => 0,
];

echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n";
echo 'Push upload debris cleanup - ' . gmdate('Y-m-d H:i:s') . " UTC\n";
echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n";

if ($dryRun) {
    echo "DRY RUN - No files will be deleted\n\n";
}

cleanupPushUploadDebris(
    getCleanupPushUploadDebrisMaxAgeSeconds(),
    $stats,
    $dryRun,
    $verbose
);

aviationwx_log('info', 'push upload debris cleanup finished', [
    'files_checked' => $stats['files_checked'],
    'files_deleted' => $stats['files_deleted'],
    'bytes_freed' => $stats['bytes_freed'],
    'errors' => $stats['errors'],
    'dry_run' => $dryRun,
], 'app');

exit($stats['errors'] > 0 ? 1 : 0);
