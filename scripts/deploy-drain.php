#!/usr/bin/env php
<?php
/**
 * Deploy worker drain CLI (host or container).
 *
 * Prefer the rsynced host tree + shared cache volume so CD does not depend on
 * PHP inside the image being replaced.
 *
 * Exit codes: request/clear/status 0|1; wait 0 (done) or 2 (timeout, CD may proceed)
 */

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "CLI only\n");
    exit(1);
}

$args = array_slice($argv, 1);
$command = null;
$cacheDir = null;
$maxWait = null;

foreach ($args as $arg) {
    if (str_starts_with($arg, '--cache-dir=')) {
        $cacheDir = substr($arg, strlen('--cache-dir='));
        continue;
    }
    if (str_starts_with($arg, '--max-wait=')) {
        $maxWait = (int) substr($arg, strlen('--max-wait='));
        continue;
    }
    if ($arg === '--help' || $arg === '-h') {
        $command = 'help';
        continue;
    }
    if ($command === null && $arg !== '' && !str_starts_with($arg, '--')) {
        $command = $arg;
    }
}

if ($command === null || $command === 'help') {
    echo <<<HELP
Deploy worker drain

Usage:
  php scripts/deploy-drain.php request [--cache-dir=PATH]
  php scripts/deploy-drain.php wait [--cache-dir=PATH] [--max-wait=SECONDS]
  php scripts/deploy-drain.php status [--cache-dir=PATH]
  php scripts/deploy-drain.php clear [--cache-dir=PATH]

HELP;
    exit($command === 'help' ? 0 : 1);
}

require_once __DIR__ . '/../lib/deploy-drain.php';

if ($cacheDir !== null && $cacheDir !== '') {
    deploy_drain_set_cache_base($cacheDir);
}

switch ($command) {
    case 'request':
        if (!deploy_drain_request()) {
            fwrite(STDERR, "deploy-drain: failed to write request flag\n");
            exit(1);
        }
        echo "requested\n";
        echo 'flag=' . deploy_drain_flag_path() . "\n";
        exit(0);

    case 'wait':
        // Explicit --max-wait=0 is a single poll (tests / check-once). Default adds grace.
        if ($maxWait === null) {
            $waitSeconds = deploy_drain_cd_wait_seconds();
        } elseif ($maxWait <= 0) {
            $waitSeconds = 0;
        } else {
            $waitSeconds = $maxWait + DEPLOY_WORKER_DRAIN_WAIT_GRACE_SECONDS;
        }
        $ok = deploy_drain_wait_until_complete($waitSeconds);
        if ($ok) {
            $payload = deploy_drain_read_done_payload();
            $reason = is_array($payload) && isset($payload['reason']) ? (string) $payload['reason'] : 'unknown';
            echo "complete reason={$reason}\n";
            exit(0);
        }
        fwrite(STDERR, "deploy-drain: wait timed out after {$waitSeconds}s - proceeding without done marker\n");
        exit(2);

    case 'status':
        $status = [
            'requested' => deploy_drain_is_requested(),
            'complete' => deploy_drain_is_complete(),
            'started_at' => deploy_drain_started_at(),
            'elapsed_seconds' => deploy_drain_elapsed_seconds(),
            'flag' => deploy_drain_flag_path(),
            'done' => deploy_drain_done_path(),
            'done_payload' => deploy_drain_read_done_payload(),
            'max_seconds' => DEPLOY_WORKER_DRAIN_MAX_SECONDS,
            'reference_max_seconds' => DEPLOY_WORKER_DRAIN_REFERENCE_MAX_SECONDS,
            'abandon_seconds' => DEPLOY_WORKER_DRAIN_ABANDON_SECONDS,
            'ttl_seconds' => deploy_drain_ttl_seconds(deploy_drain_reference_aware_max_seconds()),
            'wait_grace_seconds' => DEPLOY_WORKER_DRAIN_WAIT_GRACE_SECONDS,
            'cd_wait_seconds' => deploy_drain_cd_wait_seconds(),
        ];
        echo json_encode($status, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";
        exit(0);

    case 'clear':
        if (!deploy_drain_clear_markers()) {
            fwrite(STDERR, "deploy-drain: failed to clear markers\n");
            exit(1);
        }
        echo "cleared\n";
        exit(0);

    default:
        fwrite(STDERR, "deploy-drain: unknown command: {$command}\n");
        exit(1);
}
