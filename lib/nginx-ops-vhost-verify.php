<?php
/**
 * Validates ops.aviationwx.org nginx server block in docker/nginx.conf.
 *
 * Ensures the operator console vhost proxies to the ops stack on loopback :8091,
 * does not reuse dashboard port 8080, and does not embed Authelia in main nginx.
 *
 * @package AviationWX
 */

declare(strict_types=1);

require_once __DIR__ . '/nginx-server-block-extract.php';

/**
 * Extract the server { ... } block for ops.aviationwx.org from raw nginx config.
 *
 * @param string $content Full nginx.conf contents
 * @return string Ops server block including outer braces, or empty string if not found
 */
function nginx_extract_ops_aviationwx_server_block(string $content): string
{
    return nginx_extract_server_block($content, 'server_name ops.aviationwx.org;');
}

/**
 * Verify the ops vhost is a pass-through proxy to the ops compose bind port.
 *
 * @param string $block Extracted ops.aviationwx.org server block
 * @param string $fullContent Full nginx.conf for ordering checks
 * @return array<int, string> Human-readable errors; empty when valid
 */
function nginx_verify_ops_server_block(string $block, string $fullContent = ''): array
{
    $errors = [];
    if ($block === '') {
        $errors[] = 'Could not extract ops.aviationwx.org server block';

        return $errors;
    }

    if (!str_contains($block, 'server_name ops.aviationwx.org;')) {
        $errors[] = 'ops server block must declare server_name ops.aviationwx.org';
    }
    if (!preg_match('#proxy_pass\s+http://127\.0\.0\.1:8091(?=[;/\s]|$)#', $block)) {
        $errors[] = 'ops server block must proxy_pass to http://127.0.0.1:8091 (aviationwx-ops web bind)';
    }
    if (preg_match('#proxy_pass\s+http://(localhost|127\.0\.0\.1):8080#', $block)) {
        $errors[] = 'ops server block must not proxy_pass to dashboard port 8080';
    }
    if (preg_match('#return\s+301\s+https?://[^;]*:8080/#', $block)) {
        $errors[] = 'ops server block must not redirect to dashboard port 8080';
    }
    if (str_contains($block, 'airport=ops')) {
        $errors[] = 'ops server block must not use airport-style routing';
    }
    if (str_contains($block, 'auth_request')) {
        $errors[] = 'ops vhost in main nginx must not use auth_request; keep Authelia in the ops stack';
    }
    if (str_contains($block, 'Content-Security-Policy')) {
        $errors[] = 'ops vhost must not copy dashboard CSP headers';
    }
    $rootLocation = nginx_extract_location_block($block, 'location / {');
    if ($rootLocation === '') {
        $errors[] = 'ops vhost must define a location / block for proxy_pass';
    } elseif (!preg_match('#proxy_pass\s+http://127\.0\.0\.1:8091(?=[;/\s]|$)#', $rootLocation)) {
        $errors[] = 'ops location / must proxy_pass to http://127.0.0.1:8091';
    } elseif (!preg_match('/add_header\s+X-Robots-Tag\s+"noindex,\s*nofollow"/', $rootLocation)) {
        $errors[] = 'ops location / must set X-Robots-Tag "noindex, nofollow" on proxied responses';
    }
    $robotsLocation = nginx_extract_location_block($block, 'location = /robots.txt');
    if ($robotsLocation === '') {
        $errors[] = 'ops vhost must serve /robots.txt with Disallow: /';
    } elseif (!preg_match('/Disallow:\s*\//', $robotsLocation)) {
        $errors[] = 'ops location = /robots.txt must return Disallow: /';
    }

    if ($fullContent !== '') {
        $opsPos = strpos($fullContent, 'server_name ops.aviationwx.org;');
        $wildcardPos = null;
        if (preg_match('/^\s*server_name aviationwx\.org \*\.aviationwx\.org;/m', $fullContent, $m, PREG_OFFSET_CAPTURE)) {
            $wildcardPos = $m[0][1];
        }
        if ($opsPos === false || $wildcardPos === null || $opsPos > $wildcardPos) {
            $errors[] = 'ops.aviationwx.org server block must appear before the *.aviationwx.org wildcard HTTPS block';
        }
    }

    return $errors;
}
