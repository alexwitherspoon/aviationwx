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
require_once __DIR__ . '/logger.php';

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
 * APCu-keyed on file mtime like the Google list, since the worker rewrites this file from a
 * separate process and the request path reads it for every client, not just crawler-looking ones.
 *
 * @return array<string,int>
 * @internal
 */
function crawlerAdmissionVerifiedIps(): array
{
    $path = getCrawlerVerifiedIpAllowlistPath();
    $mtime = file_exists($path) ? (int) filemtime($path) : -1;
    if (function_exists('apcu_fetch')) {
        $cached = @apcu_fetch('crawler_admission_verified');
        if (is_array($cached) && (int) ($cached['mtime'] ?? -1) === $mtime && is_array($cached['ips'])) {
            return $cached['ips'];
        }
    }
    $content = @file_get_contents($path);
    $json = @json_decode($content, true);
    $ips = is_array($json) ? $json : [];
    if (function_exists('apcu_store')) {
        @apcu_store('crawler_admission_verified', ['mtime' => $mtime, 'ips' => $ips], CRAWLER_ALLOWLIST_REFRESH_INTERVAL);
    }
    return $ips;
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

/**
 * Resolve a hostname or IP for crawler verification.
 *
 * Test hook: set `$GLOBALS['crawlerAdmissionDnsResolver']` to a callable
 * `function(string $q, bool $forward): ?string` to script DNS in unit tests.
 *
 * @param string $q Hostname (forward) or IP (reverse)
 * @param bool $forward True for host -> IP, false for IP -> host (PTR)
 * @return string|null Resolved address or hostname, null on failure
 * @internal
 */
function crawlerAdmissionResolveDns(string $q, bool $forward): ?string
{
    if (isset($GLOBALS['crawlerAdmissionDnsResolver']) && is_callable($GLOBALS['crawlerAdmissionDnsResolver'])) {
        $resolved = ($GLOBALS['crawlerAdmissionDnsResolver'])($q, $forward);
        return is_string($resolved) && $resolved !== '' ? $resolved : null;
    }
    if ($forward) {
        $recs = @dns_get_record($q, DNS_A);
        foreach (($recs ?? []) as $rec) {
            if (is_array($rec) && is_string($rec['ip'] ?? null)) {
                return (string) $rec['ip'];
            }
        }
        $recs6 = @dns_get_record($q, DNS_AAAA);
        foreach (($recs6 ?? []) as $rec) {
            if (is_array($rec) && is_string($rec['ipv6'] ?? null)) {
                return (string) $rec['ipv6'];
            }
        }
        return null;
    }
    $name = @gethostbyaddr($q);
    return is_string($name) && $name !== '' ? $name : null;
}

/**
 * Reverse-DNS + forward-DNS verification for a crawler IP.
 *
 * The PTR host must end with an allowed suffix, and the forward lookup of that host must resolve
 * back to the same IP. Mixed addressing is compared packed for v6 so an expanded DNS spelling
 * still matches a compressed request address.
 *
 * @param string $ip Address to verify
 * @param array<int,string> $allowedSuffixes e.g. ['.search.msn.com'] for Bing
 * @return bool True when verified
 * @internal
 */
function crawlerAdmissionVerifyDnsChain(string $ip, array $allowedSuffixes): bool
{
    $host = crawlerAdmissionResolveDns($ip, false);
    if ($host === null) {
        return false;
    }
    $host = strtolower(trim($host));
    if (substr($host, -1) === '.') {
        $host = substr($host, 0, -1);
    }
    $suffixMatch = false;
    foreach ($allowedSuffixes as $suffix) {
        if ($suffix === '' || substr($host, -strlen($suffix)) === $suffix) {
            $suffixMatch = true;
            break;
        }
    }
    if (!$suffixMatch) {
        return false;
    }
    $forward = crawlerAdmissionResolveDns($host, true);
    if ($forward !== null && $forward === $ip) {
        return true;
    }
    // v6 can come back expanded: when the forward string differs, compare every A/AAAA record
    // packed. Route through the resolver so the offline test hook drives this branch too.
    if (isset($GLOBALS['crawlerAdmissionDnsResolver']) && is_callable($GLOBALS['crawlerAdmissionDnsResolver'])) {
        $candidate = crawlerAdmissionResolveDns($host, true);
        if (!str_contains($ip, ':') || $candidate === null) {
            return false;
        }
        $packedIp = crawlerAdmissionPackV6($ip);
        $packedCandidate = $packedIp !== null ? crawlerAdmissionPackV6($candidate) : null;
        return $packedCandidate !== null && $packedCandidate === $packedIp;
    }
    $recs = @dns_get_record($host, DNS_A | DNS_AAAA);
    foreach (($recs ?? []) as $rec) {
        $addr = (string) ($rec['ipv6'] ?? '');
        if ($addr !== '') {
            $packedRec = crawlerAdmissionPackV6($addr);
            if ($packedRec !== null && $packedRec === $packedIp) {
                return true;
            }
        }
    }
    return false;
}

/**
 * Read the pending Bing/Yandex IP queue (ip -> first_seen unix seconds).
 *
 * @return array<string,int>
 * @internal
 */
function crawlerAdmissionPendingIps(): array
{
    $content = @file_get_contents(getCrawlerPendingIpsPath());
    if ($content === false) {
        return [];
    }
    $json = @json_decode($content, true);
    return is_array($json) ? $json : [];
}

/**
 * Add an IP to the pending verification queue, under a lock to stay atomic with the worker drain.
 *
 * @return bool True when the IP was accepted onto the queue
 * @internal
 */
function crawlerAdmissionEnqueuePendingIp(string $ip): bool
{
    $path = getCrawlerPendingIpsPath();
    $lockPath = $path . '.lock';
    $dir = dirname($path);
    if (!is_dir($dir)) {
        if (!@mkdir($dir, 0755, true) && !is_dir($dir)) {
            return false;
        }
    }
    $fp = @fopen($lockPath, 'c+');
    if ($fp === false) {
        return false;
    }
    @flock($fp, LOCK_EX);
    $queue = crawlerAdmissionPendingIps();
    $changed = false;
    if (!isset($queue[$ip])) {
        if (count($queue) >= CRAWLER_PENDING_MAX_IPS) {
            @flock($fp, LOCK_UN);
            @fclose($fp);
            return false;
        }
        $queue[$ip] = time();
        $changed = true;
    }
    // Only write when the map changed: a pending-but-unverified IP requests repeatedly, and a
    // rewrite per request is an O(N) encode plus rename on a hot path when the queue is large.
    $ok = $changed ? crawlerAdmissionWriteJson($path, $queue) : true;
    @flock($fp, LOCK_UN);
    @fclose($fp);
    return $ok;
}

/**
 * Drain the pending queue through DNS verification and publish the verified map.
 *
 * Runs off the request path. PTR + forward-verify each pending IP against the Bing or Yandex
 * suffix list; verified IPs land in the allowlist. The queue is cleared only after the verified
 * map is written, so a failed publish keeps the pending IPs for the next run. Expired verified
 * entries are pruned on every run.
 *
 * @param int $now Unix time
 * @return bool True when the verified map was published, false when the write failed
 * @internal
 */
function crawlerAdmissionDrainPendingQueue(int $now): bool
{
    $path = getCrawlerPendingIpsPath();
    $lockPath = $path . '.lock';

    // Snapshot the queue under the lock, then release it for the DNS loop. Holding the lock
    // across every reverse lookup would make request-path enqueues block for the whole drain.
    $fp = @fopen($lockPath, 'c+');
    if ($fp === false) {
        return false;
    }
    @flock($fp, LOCK_EX);
    $queue = crawlerAdmissionPendingIps();
    @flock($fp, LOCK_UN);
    @fclose($fp);

    $verified = crawlerAdmissionVerifiedIps();
    foreach ($queue as $ip => $unused) {
        if (!is_string($ip) || filter_var($ip, FILTER_VALIDATE_IP) === false) {
            continue;
        }
        if (crawlerAdmissionVerifyDnsChain($ip, ['.search.msn.com'])) {
            $verified[$ip] = $now + CRAWLER_VERIFIED_IP_TTL;
        } elseif (crawlerAdmissionVerifyDnsChain($ip, ['.yandex.ru', '.yandex.net', '.yandex.com'])) {
            $verified[$ip] = $now + CRAWLER_VERIFIED_IP_TTL;
        }
    }
    foreach ($verified as $ip => $expires) {
        if (!is_numeric($expires) || (int) $expires <= $now) {
            unset($verified[$ip]);
        }
    }

    // Re-lock for the publish and queue clear so an enqueue during DNS is not lost or double-cleared.
    // Only the IPs this run processed are removed; anything enqueued during the DNS loop stays for
    // the next run instead of being dropped.
    $fp = @fopen($lockPath, 'c+');
    if ($fp === false) {
        return false;
    }
    @flock($fp, LOCK_EX);
    $published = crawlerAdmissionWriteJson(getCrawlerVerifiedIpAllowlistPath(), $verified);
    if ($published) {
        $remaining = crawlerAdmissionPendingIps();
        foreach ($queue as $ip => $unused) {
            unset($remaining[$ip]);
        }
        crawlerAdmissionWriteJson($path, $remaining);
    }
    @flock($fp, LOCK_UN);
    @fclose($fp);
    return $published;
}

/**
 * Fetch Google's official crawler CIDR JSON.
 *
 * @return array{0: string|false, 1: int} body, http code
 * @internal
 */
function crawlerAdmissionFetchGoogleList(): array
{
    if (isset($GLOBALS['crawlerAdmissionTestHttpGet']) && is_callable($GLOBALS['crawlerAdmissionTestHttpGet'])) {
        $result = ($GLOBALS['crawlerAdmissionTestHttpGet'])(
            'https://developers.google.com/static/crawling/ipranges/common-crawlers.json'
        );
        if (is_array($result)) {
            return [
                is_string($result['body'] ?? null) ? (string) $result['body'] : false,
                is_numeric($result['http_code'] ?? null) ? (int) $result['http_code'] : 0,
            ];
        }
        return [false, 0];
    }
    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL => 'https://developers.google.com/static/crawling/ipranges/common-crawlers.json',
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => 5,
        CURLOPT_TIMEOUT => 15,
        CURLOPT_HTTPHEADER => ['Accept: application/json'],
    ]);
    $body = curl_exec($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return [$body, $code];
}

/**
 * Refresh the Google CIDR allowlist and drain the Bing/Yandex verification queue.
 *
 * Worker entry point (scripts/refresh-crawler-admission.php). Atomic writes keep the request
 * path consistent. A failed Google fetch with a prior file retains it; only a stale or missing
 * result with no usable cache escalates.
 *
 * @return array<string, mixed>
 */
function crawlerAdmissionRefresh(): array
{
    $now = time();
    $summary = [
        'google_status' => 'unchanged',
        'google_prefixes' => 0,
        'queue_drained' => 0,
        'verified_ips' => 0,
    ];

    // Google CIDR list
    $googlePath = getCrawlerGoogleAllowlistPath();
    [$body, $code] = crawlerAdmissionFetchGoogleList();
    if ($body !== false && $code === 200 && $body !== '') {
        $json = @json_decode($body, true);
        if (is_array($json) && isset($json['prefixes']) && is_array($json['prefixes'])
            && crawlerAdmissionHasWellFormedPrefix($json['prefixes'])
        ) {
            if (crawlerAdmissionWriteJson($googlePath, $json)) {
                $summary['google_status'] = 'fetched';
                $summary['google_prefixes'] = count($json['prefixes']);
            } else {
                $summary['google_status'] = 'write_failed';
            }
        } else {
            $summary['google_status'] = 'malformed';
        }
    } else {
        $summary['google_status'] = 'fetch_failed';
    }

    // Bing/Yandex queue drain
    $pending = crawlerAdmissionPendingIps();
    $summary['queue_drained'] = count($pending);
    $published = crawlerAdmissionDrainPendingQueue($now);
    $summary['verified_ips'] = count(crawlerAdmissionVerifiedIps());
    $summary['drain_published'] = $published;
    if (!$published) {
        aviationwx_log('warning', 'crawler_admission: verified-ip publish failed; pending IPs retained', [], 'app');
    }

    return $summary;
}

/**
 * Maybe enqueue an IP for off-path crawler verification.
 *
 * Only addresses that claim a Bing or Yandex user agent are queued, so a flood of spoofed
 * UA/IP pairs cannot grow the pending file without bound without also looking like search
 * traffic. Already-verified and Google-covered IPs never reach the queue.
 *
 * @param string $ip Client IP
 * @param string $userAgent Raw request user agent (matched case-insensitively)
 * @return bool True when the IP was accepted onto the queue
 */
function crawlerAdmissionMaybeEnqueue(string $ip, string $userAgent): bool
{
    if ($ip === '' || filter_var($ip, FILTER_VALIDATE_IP) === false) {
        return false;
    }
    $ua = strtolower($userAgent);
    if (!str_contains($ua, 'bingbot') && !str_contains($ua, 'bingpreview') && !str_contains($ua, 'yandex')) {
        return false;
    }
    $google = crawlerAdmissionGooglePrefixes();
    if (is_array($google) && crawlerAdmissionCidrMatch($ip, $google)) {
        return false;
    }
    $verified = crawlerAdmissionVerifiedIps();
    $now = time();
    if (isset($verified[$ip]) && is_numeric($verified[$ip]) && (int) $verified[$ip] > $now) {
        // Still verified. If it is within one drain interval of expiry, renew it so an active
        // crawler does not lose its exemption between worker runs. Writes at most once per
        // interval per IP, not on every request.
        if ((int) $verified[$ip] < ($now + CRAWLER_ALLOWLIST_REFRESH_INTERVAL)) {
            $verified[$ip] = $now + CRAWLER_VERIFIED_IP_TTL;
            crawlerAdmissionWriteJson(getCrawlerVerifiedIpAllowlistPath(), $verified);
        }
        return false;
    }
    return crawlerAdmissionEnqueuePendingIp($ip);
}
