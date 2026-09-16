<?php
/**
 * Client-IP resolution.
 *
 * Single source of truth for "who is this client." The trust boundary is nginx: real_ip
 * (Cloudflare ranges, real_ip_header CF-Connecting-IP) rewrites $remote_addr to the true client,
 * and every vhost sends it as X-Real-IP, which Apache passes through as HTTP_X_REAL_IP. PHP reads
 * that validated value when nginx forwarded it, and the direct peer REMOTE_ADDR otherwise.
 * A client-set X-Real-IP is overwritten by nginx before it reaches the backend in the deployed
 * topology; when Apache is exposed directly (local/CI), REMOTE_ADDR is the peer and the local
 * identity is as trustworthy as the old forwarded-header path was.
 */

/**
 * The client IP for the current request.
 *
 * Proxied mode: nginx set X-Real-IP from the validated $remote_addr. Direct mode (Apache exposed
 * without nginx): the TCP peer REMOTE_ADDR is the client.
 *
 * @return string Client IP, or 'unknown' when none is available
 */
function getClientIp(): string
{
    $xRealIp = trim($_SERVER['HTTP_X_REAL_IP'] ?? '');
    if ($xRealIp !== '') {
        return $xRealIp;
    }
    $remoteAddr = trim($_SERVER['REMOTE_ADDR'] ?? '');
    return $remoteAddr !== '' ? $remoteAddr : 'unknown';
}
