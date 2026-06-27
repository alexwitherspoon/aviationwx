<?php
/**
 * Extract nginx server { ... } blocks from docker/nginx.conf by server_name marker.
 *
 * @package AviationWX
 */

declare(strict_types=1);

/**
 * Extract a server block that contains the given server_name line.
 *
 * @param string $content Full nginx.conf contents
 * @param string $serverNameMarker Unique marker inside the block (e.g. "server_name embed.aviationwx.org;")
 * @return string Server block including outer braces, or empty string if not found
 */
function nginx_extract_server_block(string $content, string $serverNameMarker): string
{
    $pos = strpos($content, $serverNameMarker);
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
