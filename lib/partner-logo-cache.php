<?php
/**
 * Partner Logo Caching Utility
 * 
 * Downloads and caches partner logos locally with long TTL (30 days).
 * Supports JPEG and PNG formats, converts PNG to JPEG for consistency.
 * Uses atomic file operations to prevent corruption.
 */

require_once __DIR__ . '/constants.php';
require_once __DIR__ . '/cache-paths.php';
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/logger.php';

/**
 * Whether an IP address is private, loopback, or link-local.
 *
 * @param string $ip IP address to check
 * @return bool True when the IP is not publicly routable
 */
function isPrivateIp(string $ip): bool
{
    if ($ip === '::1') {
        return true;
    }

    // Normalize IPv6 to canonical form so ::ffff:7f00:1 -> ::ffff:127.0.0.1
    if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) !== false) {
        $packed = @inet_pton($ip);
        if ($packed !== false && strlen($packed) === 16) {
            $normalized = @inet_ntop($packed);
            if (is_string($normalized) && $normalized !== $ip) {
                return isPrivateIp($normalized);
            }
        }
    }

    // IPv4-mapped IPv6 (::ffff:127.0.0.1) — decode and check the embedded IPv4
    if (str_starts_with($ip, '::ffff:')) {
        $mapped = substr($ip, 7);
        if (filter_var($mapped, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false) {
            return isPrivateIp($mapped);
        }
    }

    if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false) {
        // FILTER_FLAG_NO_PRIV_RANGE blocks RFC 1918 (10.x, 172.16-31.x, 192.168.x)
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4 | FILTER_FLAG_NO_PRIV_RANGE) === false) {
            return true;
        }
        // Also block loopback (127.0.0.0/8) and link-local (169.254.0.0/16)
        $octets = explode('.', $ip);
        $first = (int) $octets[0];
        if ($first === 127) {
            return true;
        }
        if ($first === 169 && (int) $octets[1] === 254) {
            return true;
        }
        // CGNAT (100.64/10), 0/8, 192.0.0/24, 198.18/15, 198.51.100/24, 203.0.113/24
        if ($first === 0
            || ($first === 100 && (int) $octets[1] >= 64 && (int) $octets[1] < 128)
            || ($first === 192 && (int) $octets[1] === 0)
            || ($first === 198 && ((int) $octets[1] === 18 || (int) $octets[1] === 19))
            || ($first === 198 && (int) $octets[1] === 51 && (int) $octets[2] === 100)
            || ($first === 203 && (int) $octets[1] === 0 && (int) $octets[2] === 113)
            || $first >= 240
        ) {
            return true;
        }
        return false;
    }

    if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) !== false) {
        $lower = strtolower($ip);
        // Reject IPv6 loopback (::1), unspecified (::), and link-local (fe80::/10)
        if ($lower === '::1' || $lower === '' || $lower === '::') {
            return true;
        }
        if ($lower[0] === 'f' && $lower[1] === 'e' && (
            in_array($lower[2], ['8','9','a','b','c','d','e','f'])
        )) {
            return true;
        }
        // IPv6 unique local addresses (fc00::/7: fc00-fdff)
        if ($lower[0] === 'f' && ($lower[1] === 'c' || $lower[1] === 'd')) {
            return true;
        }
        return false;
    }

    return true;
}

/**
 * Check whether a URL's host resolves to a public IP.
 *
 * Prevents SSRF by rejecting loopback, link-local, RFC 1918, and
 * other non-public addresses before any outbound HTTP request.
 *
 * @param string $logoUrl Logo URL to check
 * @return bool True when the URL is safe to fetch (public IP), false when blocked
 */
function isLogoUrlSafe(string $logoUrl): bool
{
    return resolveLogoUrlHost($logoUrl) !== null;
}

/**
 * Resolve a logo URL's host to verified-safe public IPs.
 *
 * Returns the list of verified public IPs, or null if the URL is unsafe
 * or unresolvable. The caller should use these IPs via CURLOPT_RESOLVE to
 * prevent DNS rebinding between the safety check and the actual request.
 *
 * @param string $logoUrl Logo URL to check
 * @return string[]|null Verified public IPs, or null if unsafe
 */
function resolveLogoUrlHost(string $logoUrl): ?array
{
    $parsed = parse_url($logoUrl);
    if ($parsed === false || !isset($parsed['host']) || $parsed['host'] === '') {
        return null;
    }

    // Only allow http and https schemes
    $scheme = strtolower($parsed['scheme'] ?? '');
    if (!in_array($scheme, ['http', 'https'], true)) {
        return null;
    }

    $host = strtolower($parsed['host']);
    $port = isset($parsed['port']) ? (int) $parsed['port'] : ($scheme === 'https' ? 443 : 80);

    // If host is a literal IP, check it directly
    if (filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4 | FILTER_FLAG_IPV6) !== false) {
        return isPrivateIp($host) ? null : [$host];
    }

    // Resolve both A and AAAA records; gethostbynamel() only returns A records.
    // Don't return false early if A records are absent — AAAA-only hosts are valid.
    $ips = [];
    $resolvedAny = false;

    $arecords = @gethostbynamel($host);
    if (is_array($arecords)) {
        foreach ($arecords as $ip) {
            $resolvedAny = true;
            if (isPrivateIp($ip)) {
                return null;
            }
            $ips[] = $ip;
        }
    }

    $dnsRecords = @dns_get_record($host, DNS_A | DNS_AAAA);
    if (is_array($dnsRecords)) {
        foreach ($dnsRecords as $rec) {
            if (isset($rec['ip'])) {
                $resolvedAny = true;
                if (isPrivateIp($rec['ip'])) {
                    return null;
                }
                if (!in_array($rec['ip'], $ips, true)) {
                    $ips[] = $rec['ip'];
                }
            }
            if (isset($rec['ipv6'])) {
                $resolvedAny = true;
                if (isPrivateIp($rec['ipv6'])) {
                    return null;
                }
                if (!in_array($rec['ipv6'], $ips, true)) {
                    $ips[] = $rec['ipv6'];
                }
            }
        }
    }

    if (!$resolvedAny) {
        return null;
    }

    return $ips;
}

/**
 * Get cache directory for partner logos
 *
 * Uses CACHE_PARTNERS_DIR from cache-paths.php so layout stays consistent with the rest of the app.
 *
 * @return string Cache directory path
 */
function getPartnerLogoCacheDir(): string {
    $cacheDir = CACHE_PARTNERS_DIR;
    if (!is_dir($cacheDir)) {
        @mkdir($cacheDir, 0755, true);
    }
    return $cacheDir;
}

/**
 * Generate cache filename from logo URL
 *
 * When `isTestMode()` is true and the host is `example.com`, the on-disk extension is
 * always `jpg` because `getMockHttpResponse()` serves a JPEG placeholder (test mode only), so
 * `api/partner-logo.php` Content-Type matches file contents.
 *
 * @param string $logoUrl Logo URL
 * @return string Cache file path
 */
function getPartnerLogoCacheFile(string $logoUrl): string {
    getPartnerLogoCacheDir();

    $hash = md5($logoUrl);

    // Try to extract extension from URL
    $ext = 'jpg'; // default
    $parsed = parse_url($logoUrl);
    if (isset($parsed['path'])) {
        $pathExt = strtolower(pathinfo($parsed['path'], PATHINFO_EXTENSION));
        if (in_array($pathExt, ['jpg', 'jpeg', 'png', 'gif', 'webp'])) {
            $ext = ($pathExt === 'jpeg') ? 'jpg' : $pathExt;
        }
    }

    // Mocked responses for example.com are JPEG placeholders in test mode only (see lib/test-mocks.php
    // and getMockHttpResponse() which returns null when not isTestMode()).
    if (isTestMode()) {
        $host = strtolower($parsed['host'] ?? '');
        if ($host === 'example.com' || str_ends_with($host, '.example.com')) {
            $ext = 'jpg';
        }
    }

    return getPartnerLogoCachedFilePath($hash, $ext);
}

/**
 * Check if cached logo is fresh
 * 
 * @param string $cacheFile Cache file path
 * @return bool True if cache is fresh (within TTL)
 */
function isPartnerLogoCacheFresh(string $cacheFile): bool {
    if (!file_exists($cacheFile)) {
        return false;
    }
    
    $age = time() - filemtime($cacheFile);
    return $age < PARTNER_LOGO_CACHE_TTL;
}

/**
 * Download and cache partner logo
 *
 * In test mode (`isTestMode()`), uses `getMockHttpResponse()` when available (e.g. example.com fixture)
 * so PHPUnit and subprocess tests do not open real outbound cURL connections. `getMockHttpResponse()`
 * itself only returns fixtures when `isTestMode()` is true; contributor mock mode without test mode
 * still uses cURL.
 *
 * Downloads logo from URL, validates it's an image, and saves to cache.
 * Converts PNG to JPEG for consistency. Uses atomic file operations.
 *
 * @param string $logoUrl Logo URL to download
 * @return bool True on success, false on failure
 */
function downloadPartnerLogo(string $logoUrl): bool {
    $cacheFile = getPartnerLogoCacheFile($logoUrl);

    // Check if already cached and fresh
    if (isPartnerLogoCacheFresh($cacheFile)) {
        return true;
    }

    // SSRF protection: reject internal/private IPs before any network request
    $verifiedIps = resolveLogoUrlHost($logoUrl);
    if ($verifiedIps === null) {
        aviationwx_log('warning', 'partner logo download blocked: unsafe URL', [
            'url' => $logoUrl,
        ], 'app');
        return false;
    }

    $parsed = parse_url($logoUrl);
    $host = $parsed['host'] ?? '';
    $port = isset($parsed['port']) ? (int) $parsed['port'] : (strtolower($parsed['scheme'] ?? '') === 'https' ? 443 : 80);

    $data = null;
    $httpCode = 0;
    $error = '';

    if (isTestMode()) {
        require_once __DIR__ . '/test-mocks.php';
        $mockBody = getMockHttpResponse($logoUrl);
        if ($mockBody !== null && strlen($mockBody) >= 100) {
            $data = $mockBody;
            $httpCode = 200;
        }
    }

    if ($data === null) {
        $ch = curl_init();
        $resolveOpts = [];
        foreach ($verifiedIps as $vip) {
            $resolveOpts[] = $host . ':' . $port . ':' . $vip;
        }
        curl_setopt_array($ch, [
            CURLOPT_URL => $logoUrl,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => CURL_TIMEOUT,
            CURLOPT_CONNECTTIMEOUT => CURL_CONNECT_TIMEOUT,
            // Don't follow redirects automatically; validate each target manually
            CURLOPT_FOLLOWLOCATION => false,
            // Restrict protocol to HTTP/HTTPS for both initial and redirect requests
            CURLOPT_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
            CURLOPT_REDIR_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
            CURLOPT_USERAGENT => 'AviationWX Partner Logo Bot',
            CURLOPT_MAXFILESIZE => getCacheFileMaxSizeBytes(),
            // Pin the verified IPs to prevent DNS rebinding between check and fetch
            CURLOPT_RESOLVE => $resolveOpts,
        ]);

        $data = curl_exec($ch);
        $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        $redirectUrl = curl_getinfo($ch, CURLINFO_REDIRECT_URL);
        curl_close($ch);

        // Handle manual redirect following with SSRF protection
        $redirectsFollowed = 0;
        while ($httpCode >= 300 && $httpCode < 400 && $redirectUrl !== '' && $redirectUrl !== false && $redirectsFollowed < 3) {
            // Resolve relative redirects against the original URL
            if (parse_url($redirectUrl, PHP_URL_HOST) === null) {
                $origScheme = parse_url($logoUrl, PHP_URL_SCHEME) ?: 'https';
                $origHost = parse_url($logoUrl, PHP_URL_HOST) ?: '';
                $origPort = isset($origPortParts['port']) ? (int)$origPortParts['port'] : ($origScheme === 'https' ? 443 : 80);
                $redirectUrl = $origScheme . '://' . $origHost .
                    (parse_url($redirectUrl, PHP_URL_PORT) !== null ? ':' . parse_url($redirectUrl, PHP_URL_PORT) : '') .
                    $redirectUrl;
            }

            $redirectIps = resolveLogoUrlHost($redirectUrl);
            if ($redirectIps === null) {
                aviationwx_log('warning', 'partner logo download blocked: unsafe redirect target', [
                    'original_url' => $logoUrl,
                    'redirect_url' => $redirectUrl,
                ], 'app');
                return false;
            }

            $redirectHost = strtolower(parse_url($redirectUrl, PHP_URL_HOST) ?: '');
            $redirectPort = parse_url($redirectUrl, PHP_URL_PORT);
            if ($redirectPort === null) {
                $redirectPort = (strtolower(parse_url($redirectUrl, PHP_URL_SCHEME) ?: '') === 'https') ? 443 : 80;
            }
            $redirectResolveOpts = [];
            foreach ($redirectIps as $rip) {
                $redirectResolveOpts[] = $redirectHost . ':' . $redirectPort . ':' . $rip;
            }

            $ch = curl_init();
            curl_setopt_array($ch, [
                CURLOPT_URL => $redirectUrl,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT => CURL_TIMEOUT,
                CURLOPT_CONNECTTIMEOUT => CURL_CONNECT_TIMEOUT,
                CURLOPT_FOLLOWLOCATION => false,
                CURLOPT_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
                CURLOPT_REDIR_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
                CURLOPT_USERAGENT => 'AviationWX Partner Logo Bot',
                CURLOPT_MAXFILESIZE => getCacheFileMaxSizeBytes(),
                CURLOPT_RESOLVE => $redirectResolveOpts,
            ]);

            $data = curl_exec($ch);
            $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $error = curl_error($ch);
            $redirectUrl = curl_getinfo($ch, CURLINFO_REDIRECT_URL);
            curl_close($ch);
            $redirectsFollowed++;
        }
    }

    if ($error !== '') {
        aviationwx_log('warning', 'partner logo download failed', [
            'url' => $logoUrl,
            'error' => $error
        ], 'app');
        return false;
    }

    if ($httpCode !== 200 || !$data || strlen($data) < 100) {
        aviationwx_log('warning', 'partner logo download invalid response', [
            'url' => $logoUrl,
            'http_code' => $httpCode,
            'size' => strlen($data ?? '')
        ], 'app');
        return false;
    }

    // Validate and process image
    $tmpFile = getUniqueTmpFile($cacheFile);
    
    // Check if JPEG
    if (strpos($data, "\xff\xd8") === 0) {
        // JPEG - write directly
        if (@file_put_contents($tmpFile, $data) !== false) {
            if (@rename($tmpFile, $cacheFile)) {
                aviationwx_log('info', 'partner logo cached', [
                    'url' => $logoUrl,
                    'cache_file' => basename($cacheFile)
                ], 'app');
                return true;
            } else {
                @unlink($tmpFile);
            }
        }
    } elseif (strpos($data, "\x89PNG") === 0) {
        // PNG - convert to JPEG
        if (function_exists('imagecreatefromstring') && function_exists('imagejpeg')) {
            $img = @imagecreatefromstring($data);
            if ($img) {
                if (@imagejpeg($img, $tmpFile, 85)) {
                    if (@rename($tmpFile, $cacheFile)) {
                        aviationwx_log('info', 'partner logo cached (PNG converted)', [
                            'url' => $logoUrl,
                            'cache_file' => basename($cacheFile)
                        ], 'app');
                        return true;
                    } else {
                        @unlink($tmpFile);
                    }
                } else {
                }
            }
        }
    } else {
        aviationwx_log('warning', 'partner logo unsupported format', [
            'url' => $logoUrl,
            'header' => substr($data, 0, 4)
        ], 'app');
    }
    
    return false;
}

/**
 * Get unique temporary file path
 * 
 * @param string $targetFile Target file path
 * @return string Temporary file path
 */
function getUniqueTmpFile(string $targetFile): string {
    $dir = dirname($targetFile);
    $base = basename($targetFile);
    return $dir . '/.' . $base . '.' . uniqid('tmp_', true);
}

/**
 * Get cached logo path (downloads if needed)
 * 
 * Returns path to cached logo file. If cache is stale or missing,
 * triggers background download (non-blocking).
 * 
 * @param string $logoUrl Logo URL
 * @return string|null Cache file path if available, null otherwise
 */
function getPartnerLogoCachePath(string $logoUrl): ?string {
    $cacheFile = getPartnerLogoCacheFile($logoUrl);
    
    // If cache is fresh, return it
    if (isPartnerLogoCacheFresh($cacheFile) && file_exists($cacheFile)) {
        return $cacheFile;
    }
    
    // Try to download (blocking for first request, but fast)
    // In production, this could be done in background
    if (downloadPartnerLogo($logoUrl)) {
        return $cacheFile;
    }
    
    // If download failed but we have stale cache, return it
    if (file_exists($cacheFile)) {
        return $cacheFile;
    }
    
    return null;
}

