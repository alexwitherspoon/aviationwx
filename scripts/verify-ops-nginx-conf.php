#!/usr/bin/env php
<?php
/**
 * CLI: verify docker/nginx.conf ops vhost proxies to 127.0.0.1:8091.
 *
 * Exit 0 when valid, 1 when validation fails, 2 when file/path error.
 * Used by deploy (docker compose exec on the web container) and locally.
 *
 * Usage: php scripts/verify-ops-nginx-conf.php [path/to/nginx.conf]
 *
 * @package AviationWX
 */

declare(strict_types=1);

require_once dirname(__DIR__) . '/lib/nginx-ops-vhost-verify.php';

$path = $argv[1] ?? 'docker/nginx.conf';
if (!is_readable($path)) {
    fwrite(STDERR, "Cannot read nginx config: {$path}\n");

    exit(2);
}

$content = file_get_contents($path);
if ($content === false) {
    fwrite(STDERR, "Failed to read nginx config: {$path}\n");

    exit(2);
}

$block = nginx_extract_ops_aviationwx_server_block($content);
$errors = nginx_verify_ops_server_block($block, $content);

if ($errors !== []) {
    fwrite(STDERR, "Ops nginx vhost verification failed for {$path}:\n");
    foreach ($errors as $msg) {
        fwrite(STDERR, "  - {$msg}\n");
    }

    exit(1);
}

fwrite(STDOUT, "OK: ops.aviationwx.org server block passes ops proxy checks ({$path})\n");

exit(0);
