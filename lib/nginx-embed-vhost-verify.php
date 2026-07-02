<?php
/**
 * Validates embed.aviationwx.org nginx server block for Public API v1 routing.
 *
 * Ensures third-party fetch() to /api/v1 on the embed host does not hit an off-host
 * redirect before CORS headers apply (see docker/nginx.conf embed server block).
 *
 * @package AviationWX
 */

declare(strict_types=1);

require_once __DIR__ . '/nginx-server-block-extract.php';

/**
 * Extract the server { ... } block for embed.aviationwx.org from raw nginx config.
 *
 * @param string $content Full nginx.conf contents
 * @return string Embed server block including outer braces, or empty string if not found
 */
function nginx_extract_embed_aviationwx_server_block(string $content): string
{
    return nginx_extract_server_block($content, 'server_name embed.aviationwx.org;');
}

/**
 * Verify the embed vhost routes Public API v1 on-host (rewrite + location /v1/), not via off-host 301.
 *
 * @param string $block Extracted embed.aviationwx.org server block
 * @return array<int, string> Human-readable errors; empty when valid
 */
function nginx_verify_embed_server_block_public_api_v1(string $block): array
{
    $errors = [];
    if ($block === '') {
        $errors[] = 'Could not extract embed.aviationwx.org server block';

        return $errors;
    }

    $bad = 'return 301 https://api.aviationwx.org/v1';
    if (str_contains($block, $bad)) {
        $errors[] = 'embed.aviationwx.org must not use off-host 301 for /api/v1 (use internal rewrite + location /v1/)';
    }
    if (!str_contains($block, 'location /v1/')) {
        $errors[] = 'embed server block should include location /v1/ for Public API routing';
    }
    if (!str_contains($block, '/api/v1/router.php')) {
        $errors[] = 'embed server block should rewrite /v1/ to api/v1/router.php';
    }

    return $errors;
}
