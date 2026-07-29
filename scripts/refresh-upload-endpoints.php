#!/usr/bin/env php
<?php
/**
 * Refresh upload endpoint cache and ProFTPD masquerade configuration.
 *
 * Usage: refresh-upload-endpoints.php [--no-reload] [--json]
 */

declare(strict_types=1);

require_once __DIR__ . '/../lib/config.php';
require_once __DIR__ . '/../lib/proftpd-auth.php';

$reload = true;
$jsonOut = false;

foreach (array_slice($argv, 1) as $arg) {
    if ($arg === '--no-reload') {
        $reload = false;
    } elseif ($arg === '--json') {
        $jsonOut = true;
    } elseif ($arg === '--help' || $arg === '-h') {
        fwrite(STDOUT, "Usage: refresh-upload-endpoints.php [--no-reload] [--json]\n");
        exit(0);
    } else {
        fwrite(STDERR, "Unknown argument: {$arg}\n");
        exit(2);
    }
}

$result = syncProftpdUploadDaemonConfig($reload);

if ($jsonOut) {
    fwrite(STDOUT, json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");
} elseif (!$result['ok']) {
    fwrite(STDERR, 'refresh-upload-endpoints: ' . ($result['error'] ?? 'failed') . "\n");
} else {
    $endpoints = $result['endpoints'];
    fwrite(
        STDOUT,
        sprintf(
            "upload endpoints refreshed (changed=%s) ipv4=%s ipv6=%s\n",
            $result['changed'] ? 'yes' : 'no',
            $endpoints['ipv4'] ?? '-',
            $endpoints['ipv6'] ?? '-'
        )
    );
}

exit($result['ok'] ? 0 : 1);
