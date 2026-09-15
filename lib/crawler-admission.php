<?php
/**
 * Crawler admission allowlist.
 *
 * Verification happens off the request path (the refresh worker builds these files); the request
 * path only reads data. Two sources: Google's official CIDR list, and a verified-IP file the
 * worker publishes for Bing/Yandex after reverse-DNS verification. isKnownCrawler() is a pure
 * lookup, never DNS, with no locks.
 */

require_once __DIR__ . '/constants.php';
require_once __DIR__ . '/cache-paths.php';

/**
 * Pack an IPv4 address into a 4-byte string, or null for malformed input.
 *
 * @internal
 */
function crawlerAdmissionPackV4(string $ip): ?string
{
    $parts = explode('.', $ip);
    if (count($parts) !== 4) {
        return null;
    }
    $bytes = '';
    foreach ($parts as $part) {
        if (!preg_match('/^\d{1,3}$/', $part)) {
            return null;
        }
        $octet = (int) $part;
        if ($octet > 255) {
            return null;
        }
        $bytes .= chr($octet);
    }
    return $bytes;
}

/**
 * Pack an IPv6 address into a 16-byte string, or null for malformed input.
 *
 * Requires exactly 8 hextets when there is no :: compression and rejects a bare "::", so a
 * short value like 2001:db8:1 is invalid rather than silently padded into a usable address.
 *
 * @internal
 */
function crawlerAdmissionPackV6(string $ip): ?string
{
    $sides = explode('::', $ip);
    $left = [];
    $right = [];
    if (count($sides) === 2) {
        $left = $sides[0] !== '' ? explode(':', $sides[0]) : [];
        $right = $sides[1] !== '' ? explode(':', $sides[1]) : [];
    } elseif (count($sides) === 1) {
        $right = explode(':', $sides[0]);
    } else {
        return null; // more than one :: run
    }
    if ($left === [] && $right === []) {
        return null; // bare "::" is not an address
    }

    // Empty hextets only arise from invalid input like ":::", "1:::", or a trailing ":": valid
    // IPv6 never has an empty token within a group, and :: is the compression that handles zero
    // fill. Reject them so junk cannot map onto a usable address.
    foreach (array_merge($left, $right) as $h) {
        if ($h === '') {
            return null;
        }
    }

    if (count($sides) === 1 && count($right) !== 8) {
        return null; // no compression, so exactly 8 hextets required
    }
    if ($left !== [] && $right !== [] && (count($left) + count($right)) > 8) {
        return null;
    }

    $hextets = [];
    foreach ($left as $h) {
        $hextets[] = $h;
    }
    $gap = 8 - (count($left) + count($right));
    if ($gap < 0) {
        return null;
    }
    for ($i = 0; $i < $gap; $i++) {
        $hextets[] = '0';
    }
    foreach ($right as $h) {
        $hextets[] = $h;
    }
    if (count($hextets) !== 8) {
        return null;
    }

    $bytes = '';
    foreach ($hextets as $hextet) {
        if ($hextet === '') {
            $hextet = '0';
        }
        if (strlen($hextet) > 4 || !preg_match('/^[0-9a-fA-F]+$/', $hextet)) {
            return null;
        }
        $bytes .= hex2bin(sprintf('%04x', intval($hextet, 16)));
    }
    return strlen($bytes) === 16 ? $bytes : null;
}

/**
 * Whether an IP is inside any given CIDR prefix (IPv4 or IPv6), using a byte compare.
 *
 * @param array<int,array<string,mixed>|string> $prefixes [ ['ipv4Prefix'|'ipv6Prefix' => 'a.b.c.d/24'], ... ]
 * @internal
 */
function crawlerAdmissionCidrMatch(string $ip, array $prefixes): bool
{
    $is6 = str_contains($ip, ':');
    $packed = $is6 ? crawlerAdmissionPackV6($ip) : crawlerAdmissionPackV4($ip);
    if ($packed === null) {
        return false;
    }
    $addrBits = $is6 ? 128 : 32;
    $byteLen = $addrBits / 8;

    foreach ($prefixes as $prefix) {
        $cidr = is_array($prefix)
            ? (string) ($prefix['ipv6Prefix'] ?? $prefix['ipv4Prefix'] ?? '')
            : (string) $prefix;
        if ($cidr === '') {
            continue;
        }
        $slash = strpos($cidr, '/');
        $network = $slash !== false ? substr($cidr, 0, $slash) : $cidr;
        $len = $addrBits;
        if ($slash !== false) {
            // (int) 'abc' is 0 in PHP, which would widen a malformed prefix to the whole family.
            $lenRaw = substr($cidr, $slash + 1);
            if (!preg_match('/^\d+$/', $lenRaw)) {
                continue;
            }
            $len = (int) $lenRaw;
            if ($len < 0 || $len > $addrBits) {
                continue;
            }
        }

        $nIs6 = str_contains($network, ':');
        if ($nIs6 !== $is6) {
            continue;
        }
        $npacked = $nIs6 ? crawlerAdmissionPackV6($network) : crawlerAdmissionPackV4($network);
        if ($npacked === null) {
            continue;
        }

        $fullBytes = (int) floor($len / 8);
        $remainBits = $len - ($fullBytes * 8);
        $match = true;
        for ($i = 0; $i < $fullBytes; $i++) {
            if (ord(substr($packed, $i, 1)) !== ord(substr($npacked, $i, 1))) {
                $match = false;
                break;
            }
        }
        if ($match && $remainBits > 0 && $fullBytes < $byteLen) {
            $mask = 0xFF << (8 - $remainBits) & 0xFF;
            if ((ord(substr($packed, $fullBytes, 1)) & $mask) !== (ord(substr($npacked, $fullBytes, 1)) & $mask)) {
                $match = false;
            }
        }
        if ($match) {
            return true;
        }
    }
    return false;
}

/**
 * Whether a prefix list contains at least one usable entry.
 *
 * Rejects an empty or malformed list so a bad upstream response cannot replace a valid allowlist
 * with one that admits nothing.
 *
 * @param array<int,array<string,mixed>|string> $prefixes
 * @internal
 */
function crawlerAdmissionHasWellFormedPrefix(array $prefixes): bool
{
    foreach ($prefixes as $prefix) {
        $cidr = is_array($prefix)
            ? (string) ($prefix['ipv6Prefix'] ?? $prefix['ipv4Prefix'] ?? '')
            : (string) $prefix;
        if ($cidr === '') {
            continue;
        }
        $slash = strpos($cidr, '/');
        $network = $slash !== false ? substr($cidr, 0, $slash) : $cidr;
        if (str_contains($network, ':')) {
            if (crawlerAdmissionPackV6($network) === null) {
                continue;
            }
        } else {
            if (crawlerAdmissionPackV4($network) === null) {
                continue;
            }
        }
        if ($slash === false) {
            return true;
        }
        $lenRaw = substr($cidr, $slash + 1);
        if (!preg_match('/^\d+$/', $lenRaw)) {
            continue;
        }
        $len = (int) $lenRaw;
        if ($len >= 0 && $len <= (str_contains($network, ':') ? 128 : 32)) {
            return true;
        }
    }
    return false;
}

/**
 * Atomically write a JSON file in the crawler-admission cache dir.
 *
 * @internal
 */
function crawlerAdmissionWriteJson(string $path, array $data): bool
{
    $dir = dirname($path);
    if (!is_dir($dir)) {
        if (!@mkdir($dir, 0755, true) && !is_dir($dir)) {
            return false;
        }
    }
    $tmp = $path . '.tmp.' . getmypid();
    $encoded = json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    if ($encoded === false || file_put_contents($tmp, $encoded, LOCK_EX) === false) {
        return false;
    }
    if (!@rename($tmp, $path)) {
        @unlink($tmp);
        return false;
    }
    return true;
}

/**
 * Read the Google CIDR allowlist, APCu-keyed on file mtime so a worker rewrite is visible
 * immediately (the worker runs in a separate process and cannot clear web-worker APCu).
 *
 * @return array<int,array<string,mixed>>|null Prefix list, or null when unavailable or stale
 * @internal
 */
function crawlerAdmissionGooglePrefixes(): ?array
{
    $path = getCrawlerGoogleAllowlistPath();
    $mtime = file_exists($path) ? (int) filemtime($path) : null;
    if ($mtime === null || (time() - $mtime) >= CRAWLER_ALLOWLIST_MAX_AGE) {
        // Missing or past the stale window: fail closed rather than keep granting from old ranges.
        if (function_exists('apcu_delete')) {
            @apcu_delete('crawler_admission_google');
        }
        return null;
    }
    if (function_exists('apcu_fetch')) {
        $cached = @apcu_fetch('crawler_admission_google');
        if (is_array($cached) && (int) ($cached['mtime'] ?? -1) === $mtime && is_array($cached['prefixes'])) {
            return $cached['prefixes'];
        }
    }
    $content = @file_get_contents($path);
    if ($content === false) {
        return null;
    }
    $json = @json_decode($content, true);
    if (!is_array($json) || !isset($json['prefixes']) || !is_array($json['prefixes'])) {
        return null;
    }
    $prefixes = $json['prefixes'];
    if (function_exists('apcu_store')) {
        @apcu_store('crawler_admission_google', ['mtime' => $mtime, 'prefixes' => $prefixes], CRAWLER_ALLOWLIST_REFRESH_INTERVAL);
    }
    return $prefixes;
}

/**
 * Read the verified Bing/Yandex IP allowlist map (ip -> expires_at unix seconds).
 *
 * @return array<string,int>
 * @internal
 */
function crawlerAdmissionVerifiedIps(): array
{
    $path = getCrawlerVerifiedIpAllowlistPath();
    $content = @file_get_contents($path);
    if ($content === false) {
        return [];
    }
    $json = @json_decode($content, true);
    return is_array($json) ? $json : [];
}

/**
 * Whether an IP is a known search-engine crawler.
 *
 * Pure lookup over the Google CIDR allowlist and the verified-IP map. No DNS, no locks, so a
 * slow upstream cannot block the request path.
 *
 * @param string $ip Client IP
 * @return bool
 */
function isKnownCrawler(string $ip): bool
{
    if ($ip === '' || filter_var($ip, FILTER_VALIDATE_IP) === false) {
        return false;
    }

    $google = crawlerAdmissionGooglePrefixes();
    if (is_array($google) && crawlerAdmissionCidrMatch($ip, $google)) {
        return true;
    }

    $verified = crawlerAdmissionVerifiedIps();
    $now = time();
    if (isset($verified[$ip]) && is_numeric($verified[$ip]) && (int) $verified[$ip] > $now) {
        return true;
    }

    return false;
}
