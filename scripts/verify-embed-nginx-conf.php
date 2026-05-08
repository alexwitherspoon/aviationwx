#!/usr/bin/env php
<?php
/**
 * CLI: verify docker/nginx.conf embed vhost has correct Public API v1 routing.
 *
 * Exit 0 when valid, 1 when validation fails, 2 when file/path error.
 * Used by deploy-docker.yml (pre-deploy + post-rsync on server).
 *
 * Usage: php scripts/verify-embed-nginx-conf.php [path/to/nginx.conf]
 *
 * @package AviationWX
 */

declare(strict_types=1);

require_once dirname(__DIR__) . '/lib/nginx-embed-vhost-verify.php';

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

$block = nginx_extract_embed_aviationwx_server_block($content);
$errors = nginx_verify_embed_server_block_public_api_v1($block);

if ($errors !== []) {
    fwrite(STDERR, "Embed nginx vhost verification failed for {$path}:\n");
    foreach ($errors as $msg) {
        fwrite(STDERR, "  - {$msg}\n");
    }

    exit(1);
}

fwrite(STDOUT, "OK: embed.aviationwx.org server block passes Public API v1 routing checks ({$path})\n");

exit(0);
