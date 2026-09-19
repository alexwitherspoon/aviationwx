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
        if ($first === 0) {
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
    $parsed = parse_url($logoUrl);
    if ($parsed === false || !isset($parsed['host']) || $parsed['host'] === '') {
        return false;
    }

    // Only allow http and https schemes
    $scheme = strtolower($parsed['scheme'] ?? '');
    if (!in_array($scheme, ['http', 'https'], true)) {
        return false;
    }

    $host = strtolower($parsed['host']);

    if (filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4 | FILTER_FLAG_IPV6) !== false) {
        return !isPrivateIp($host);
    }

    // Check both A and AAAA records — getthostbynamel() only returns A records
    $resolved = @gethostbynamel($host);
    if ($resolved === false || $resolved === []) {
        return false;
    }

    foreach ($resolved as $ip) {
        if (isPrivateIp($ip)) {
            return false;
        }
    }

    // Also check AAAA records (gethostbynamel skips IPv6)
    $records = @dns_get_record($host, DNS_A | DNS_AAAA);
    if (is_array($records)) {
        foreach ($records as $rec) {
            if (isset($rec['ipv6'])) {
                if (isPrivateIp($rec['ipv6'])) {
                    return false;
                }
            }
        }
    }

    return true;
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
    if (!isLogoUrlSafe($logoUrl)) {
        aviationwx_log('warning', 'partner logo download blocked: unsafe URL', [
            'url' => $logoUrl,
        ], 'app');
        return false;
    }

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
        $redirectAbort = false;
        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => $logoUrl,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => CURL_TIMEOUT,
            CURLOPT_CONNECTTIMEOUT => CURL_CONNECT_TIMEOUT,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS => 3,
            // Restrict protocol to HTTP/HTTPS; blocks file:// and other schemes via redirects
            CURLOPT_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
            CURLOPT_REDIR_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
            CURLOPT_USERAGENT => 'AviationWX Partner Logo Bot',
            CURLOPT_MAXFILESIZE => getCacheFileMaxSizeBytes(),
            // Validate each redirect Location header against SSRF filters
            CURLOPT_HEADERFUNCTION => function ($curl, $header) use (&$redirectAbort, $logoUrl) {
                if (str_starts_with(trim($header), 'Location:')) {
                    $location = trim(substr($header, 9));
                    if ($location !== '' && !isLogoUrlSafe($location)) {
                        $redirectAbort = true;
                        return 0;
                    }
                }
                return strlen($header);
            },
        ]);

        $data = curl_exec($ch);
        $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);

        if ($redirectAbort) {
            aviationwx_log('warning', 'partner logo download blocked: unsafe redirect target', [
                'url' => $logoUrl,
            ], 'app');
            return false;
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

