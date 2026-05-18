<?php

declare(strict_types=1);

/**
 * Read-only diagnostic for scheduler daemon and lock file state.
 *
 * Does not send signals or change files. Use when logs show multiple scheduler PIDs or lock drift.
 *
 * Usage (inside web container):
 *   php scripts/diagnose-scheduler-duplicates.php
 */

require_once __DIR__ . '/../lib/process-utils.php';

$lockFile = '/tmp/scheduler.lock';

$pids = listSchedulerDaemonPids();
echo 'Scheduler daemons (scripts/scheduler.php PIDs): ';
echo $pids === [] ? '(none)' : implode(', ', $pids);
echo "\n";

if (!is_readable($lockFile)) {
    echo "Lock file: not present or not readable ({$lockFile})\n";
    exit(0);
}

$raw = @file_get_contents($lockFile);
if ($raw === false || $raw === '') {
    echo "Lock file: empty or unreadable ({$lockFile})\n";
    exit(0);
}

$decoded = json_decode($raw, true);
if (!is_array($decoded)) {
    echo "Lock file: invalid JSON\n";
    echo $raw . "\n";
    exit(0);
}

echo "Lock file ({$lockFile}) JSON keys: " . implode(', ', array_keys($decoded)) . "\n";
$lockPid = isset($decoded['pid']) ? (int) $decoded['pid'] : 0;
if ($lockPid > 0) {
    $running = isProcessRunning($lockPid, 'scheduler');
    echo "Lock pid {$lockPid}: " . ($running ? 'running (cmdline matches scheduler)' : 'not running or cmdline mismatch') . "\n";
} else {
    echo "Lock pid: missing or invalid in JSON\n";
}

echo "\nNext steps: compare PIDs above with the lock pid. Prefer a clean web container restart after deploying scheduler lock fixes. Do not kill PIDs blindly.\n";
