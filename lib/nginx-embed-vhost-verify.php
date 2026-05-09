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

/**
 * Extract the server { ... } block for embed.aviationwx.org from raw nginx config.
 *
 * @param string $content Full nginx.conf contents
 * @return string Embed server block including outer braces, or empty string if not found
 */
function nginx_extract_embed_aviationwx_server_block(string $content): string
{
    $marker = 'server_name embed.aviationwx.org;';
    $pos = strpos($content, $marker);
    if ($pos === false) {
        return '';
    }
    $slice = substr($content, 0, $pos);
    $serverKw = strrpos($slice, 'server {');
    if ($serverKw === false) {
        return '';
    }
    $prefix = substr($content, $serverKw, 12);
    $braceRel = strpos($prefix, '{');
    if ($braceRel === false) {
        return '';
    }
    $braceOpen = $serverKw + $braceRel;
    $depth = 0;
    $len = strlen($content);
    for ($i = $braceOpen; $i < $len; $i++) {
        $c = $content[$i];
        if ($c === '{') {
            $depth++;
        } elseif ($c === '}') {
            $depth--;
            if ($depth === 0) {
                return substr($content, $serverKw, $i - $serverKw + 1);
            }
        }
    }

    return '';
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
