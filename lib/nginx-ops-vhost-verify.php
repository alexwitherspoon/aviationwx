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

/**
 * Extract the server { ... } block for ops.aviationwx.org from raw nginx config.
 *
 * @param string $content Full nginx.conf contents
 * @return string Ops server block including outer braces, or empty string if not found
 */
function nginx_extract_ops_aviationwx_server_block(string $content): string
{
    $marker = 'server_name ops.aviationwx.org;';
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
    if (!preg_match('#proxy_pass\s+http://127\.0\.0\.1:8091#', $block)) {
        $errors[] = 'ops server block must proxy_pass to http://127.0.0.1:8091 (aviationwx-ops web bind)';
    }
    if (preg_match('#proxy_pass\s+http://localhost:8080#', $block)) {
        $errors[] = 'ops server block must not proxy_pass to dashboard port 8080';
    }
    if (str_contains($block, 'auth_request')) {
        $errors[] = 'ops vhost in main nginx must not use auth_request; keep Authelia in the ops stack';
    }
    if (str_contains($block, 'Content-Security-Policy')) {
        $errors[] = 'ops vhost must not copy dashboard CSP headers';
    }

    if ($fullContent !== '') {
        $wildcardMarker = "server_name aviationwx.org *.aviationwx.org;\n";
        $opsPos = strpos($fullContent, 'server_name ops.aviationwx.org;');
        $wildcardPos = strpos($fullContent, $wildcardMarker);
        if ($opsPos === false || $wildcardPos === false || $opsPos > $wildcardPos) {
            $errors[] = 'ops.aviationwx.org server block must appear before the *.aviationwx.org wildcard HTTPS block';
        }
    }

    return $errors;
}
