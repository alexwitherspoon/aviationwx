<?php
/**
 * Crawler identity allowlist: verify that an IP belongs to a known search-engine crawler.
 *
 * Identity sources, cheapest first:
 * - Google: official CIDR list (developers.google.com/static/crawling/ipranges/common-crawlers.json),
 *   refreshed by the scheduler worker. Pure in-memory match after load.
 * - Bing / Yandex: reverse-DNS memo. No single authoritative IP list exists for either; both
 *   document reverse-DNS verification (PTR host under their domain, then forward DNS must
 *   resolve back to the same IP). Verified IPs are memoized to a cache file for a bounded
 *   window (SEO_CRAWLER_MEMO_MAX_AGE) so the DNS round-trip happens at most once per IP.
 *
 * Nothing here ever blocks a request. The only effect is that verified crawlers bypass the
 * per-IP rate limits they currently trip during multi-resource renders. Human and unknown-bot
 * limits are untouched.
 */

require_once __DIR__ . '/constants.php';
require_once __DIR__ . '/logger.php';
require_once __DIR__ . '/cache-paths.php';

/**
 * Resolve a hostname or IP.
 *
 * Test hook: set `$GLOBALS['crawlerIdentityDnsResolver']` to a callable
 * `function(string $q, bool $forward): ?string` to script DNS in unit tests.
 *
 * @param string $q Hostname (forward) or IP (reverse)
 * @param bool $forward True for host -> IP lookups, false for IP -> host (PTR)
 * @return string|null Resolved address or hostname, null on failure
 */
function crawlerIdentityResolveDns(string $q, bool $forward): ?string
{
    if (isset($GLOBALS['crawlerIdentityDnsResolver']) && is_callable($GLOBALS['crawlerIdentityDnsResolver'])) {
        $resolved = ($GLOBALS['crawlerIdentityDnsResolver'])($q, $forward);
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
 * Normalize a PTR result to a lowercase hostname without the trailing dot.
 *
 * @internal
 */
function crawlerIdentityNormalizeHost(?string $host): ?string
{
    if ($host === null) {
        return null;
    }
    $h = trim(strtolower($host));
    if ($h === '') {
        return null;
    }
    if (substr($h, -1) === '.') {
        $h = substr($h, 0, -1);
    }
    return $h !== '' ? $h : null;
}

/**
 * Verify that an IP passes the reverse-DNS + forward-DNS check for a host-suffix list.
 *
 * @param string $ip Address to verify
 * @param array<int,string> $allowedSuffixes e.g. ['.search.msn.com'] for Bing
 * @return bool True when PTR host ends with an allowed suffix AND forward DNS resolves back to $ip
 * @internal
 */
function crawlerIdentityVerifyDnsChain(string $ip, array $allowedSuffixes): bool
{
    $host = crawlerIdentityNormalizeHost(crawlerIdentityResolveDns($ip, false));
    if ($host === null) {
        return false;
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
    // Forward lookup must resolve back to the original address. Check every A/AAAA record so
    // a dual-stack host with multiple addresses is accepted regardless of which one we probed.
    $forward = crawlerIdentityResolveDns($host, true);
    if ($forward !== null && $forward === $ip) {
        return true;
    }
    $recs = crawlerIdentityForwardRecords($host);
    // v6 is compared packed: DNS can return an expanded form of the same address the request
    // compressed, and text equality would wrongly reject a valid crawler.
    $packedIp = str_contains($ip, ':') ? crawlerIdentityPackV6($ip) : null;
    foreach ($recs as $rec) {
        $addr = (string) ($rec['ip'] ?? $rec['ipv6'] ?? '');
        if ($addr === $ip) {
            return true;
        }
        if ($packedIp !== null && str_contains($addr, ':')) {
            $packedRec = crawlerIdentityPackV6($addr);
            if ($packedRec !== null && $packedRec === $packedIp) {
                return true;
            }
        }
    }
    return false;
}

/**
 * A/AAAA records for a hostname.
 *
 * Test hook: set `$GLOBALS['crawlerIdentityTestForwardRecords']` to the record list.
 *
 * @return array<int,array<string,mixed>>
 * @internal
 */
function crawlerIdentityForwardRecords(string $host): array
{
    if (isset($GLOBALS['crawlerIdentityTestForwardRecords']) && is_array($GLOBALS['crawlerIdentityTestForwardRecords'])) {
        return $GLOBALS['crawlerIdentityTestForwardRecords'];
    }
    $recs = @dns_get_record($host, DNS_A | DNS_AAAA);
    return is_array($recs) ? $recs : [];
}

/**
 * Pack an IPv4 address into a 4-byte string.
 *
 * @internal
 */
function crawlerIdentityPackV4(string $ip): ?string
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
 * Pack an IPv6 address into a 16-byte string.
 *
 * Handles the :: compression (one run) and leading zeros per hextet.
 *
 * @internal
 */
function crawlerIdentityPackV6(string $ip): ?string
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
 * Whether an IP is inside one of the given CIDR prefixes (IPv4 or IPv6).
 *
 * Prefixes come as [ {'ipv6Prefix'|'ipv4Prefix': 'a.b.c.d/24'} ] from the Google JSON. Handles
 * both address families. Byte compare with a final partial-byte mask for non-octet prefix
 * lengths.
 *
 * @param string $ip Address to test
 * @param array<int,array<string,mixed>> $prefixes CIDR list
 * @return bool
 */
function crawlerIdentityCidrMatch(string $ip, array $prefixes): bool
{
    $is6 = str_contains($ip, ':');
    $packed = $is6 ? crawlerIdentityPackV6($ip) : crawlerIdentityPackV4($ip);
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
            $lenRaw = substr($cidr, $slash + 1);
            // (int) 'abc' is 0 in PHP, which would widen a malformed prefix to the whole address
            // family; an oversized length walks past the packed bytes and crashes on ord('').
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
        $npacked = $nIs6 ? crawlerIdentityPackV6($network) : crawlerIdentityPackV4($network);
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
 * Load the Google CIDR prefix list memoized in APCu, or from the cache file on first call.
 *
 * @return array<int,array<string,mixed>>|null Prefixes, null when unavailable
 * @internal
 */
function crawlerIdentityGooglePrefixes(): ?array
{
    $path = getCrawlerIdentityGooglePath();
    $cacheAgeSeconds = file_exists($path) ? (time() - (int) filemtime($path)) : null;
    if ($cacheAgeSeconds === null || $cacheAgeSeconds >= SEO_CRAWLER_STALE_AFTER_SECONDS) {
        // Missing or past the stale window: no usable allowlist. Fail closed so stale ranges
        // never keep granting a bypass after refreshes stop succeeding.
        if (function_exists('apcu_delete')) {
            @apcu_delete('crawler_identity_google');
        }
        return null;
    }
    if (function_exists('apcu_fetch')) {
        $cached = @apcu_fetch('crawler_identity_google');
        if (is_array($cached)) {
            $currentMtime = (int) filemtime($path);
            if ((int) ($cached['mtime'] ?? -1) === $currentMtime && is_array($cached['prefixes'])) {
                return $cached['prefixes'];
            }
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
        // Cache keyed on file mtime: the refresh worker runs as a separate CLI process whose own
        // apcu_delete cannot invalidate web workers, so gate the value on the file identity that
        // all processes share. A rewritten file reloads immediately instead of lingering a TTL.
        @apcu_store(
            'crawler_identity_google',
            ['mtime' => (int) filemtime($path), 'prefixes' => $prefixes],
            SEO_CRAWLER_IDENTITY_REFRESH_INTERVAL + RATE_LIMIT_APCU_TTL_BUFFER
        );
    }
    return $prefixes;
}

/**
 * Read a reverse-DNS memo file: ip -> expires_at map.
 *
 * @param string $path Cache file path
 * @return array<string,int>
 * @internal
 */
function crawlerIdentityReadMemo(string $path): array
{
    if (!file_exists($path)) {
        return [];
    }
    $content = @file_get_contents($path);
    if ($content === false) {
        return [];
    }
    $json = @json_decode($content, true);
    return is_array($json) ? $json : [];
}

/**
 * Atomically write a memo file.
 *
 * @internal
 */
function crawlerIdentityWriteMemo(string $path, array $memo): bool
{
    $dir = dirname($path);
    if (!is_dir($dir)) {
        if (!@mkdir($dir, 0755, true) && !is_dir($dir)) {
            return false;
        }
    }
    $tmp = $path . '.tmp.' . getmypid();
    if (file_put_contents($tmp, json_encode($memo), LOCK_EX) === false) {
        return false;
    }
    if (!@rename($tmp, $path)) {
        @unlink($tmp);
        return false;
    }
    return true;
}

/**
 * Verify an IP against a reverse-DNS memo (memoized DNS chain).
 *
 * A failed check is recorded in a short-lived negative memo so a spoofed crawl user-agent on
 * the same IP cannot force a blocking DNS lookup on every request.
 *
 * @param string $ip Address to verify
 * @param string $path Memo cache file path
 * @param array<int,string> $allowedSuffixes Allowed PTR host suffixes
 * @return bool True when the memo or a fresh DNS chain confirms the IP
 * @internal
 */
function crawlerIdentityMemoCheck(string $ip, string $path, array $allowedSuffixes): bool
{
    $now = time();

    // Serialize the miss path so parallel subresource requests for a new crawler IP do not all
    // run the DNS chain or overwrite each other's memo entries. Re-check after acquiring.
    $lockPath = $path . '.lock';
    $fp = @fopen($lockPath, 'c+');
    if ($fp === false) {
        // No lock available: fall back to the unlocked path rather than fail admission.
        return crawlerIdentityMemoCheckUnlocked($ip, $path, $allowedSuffixes, $now);
    }
    @flock($fp, LOCK_EX);
    $result = crawlerIdentityMemoCheckUnlocked($ip, $path, $allowedSuffixes, $now);
    @flock($fp, LOCK_UN);
    @fclose($fp);
    return $result;
}

/**
 * Memo verification without a shared lock.
 *
 * @internal
 */
function crawlerIdentityMemoCheckUnlocked(string $ip, string $path, array $allowedSuffixes, int $now): bool
{
    $memo = crawlerIdentityReadMemo($path);

    if (isset($memo[$ip]) && is_numeric($memo[$ip]) && (int) $memo[$ip] > $now) {
        return true;
    }

    $neg = crawlerIdentityReadMemo(getCrawlerIdentityNegativePath());
    if (isset($neg[$ip]) && is_numeric($neg[$ip]) && (int) $neg[$ip] > $now) {
        return false;
    }

    if (!crawlerIdentityVerifyDnsChain($ip, $allowedSuffixes)) {
        $neg[$ip] = $now + SEO_CRAWLER_NEGATIVE_MEMO_TTL;
        crawlerIdentityWriteMemo(getCrawlerIdentityNegativePath(), $neg);
        return false;
    }

    $memo[$ip] = $now + SEO_CRAWLER_MEMO_MAX_AGE;
    crawlerIdentityWriteMemo($path, $memo);
    return true;
}

/**
 * Client IP for the crawler admission check.
 *
 * Differs from the rate-limit bucketing identity on purpose: bucketing can tolerate a spoofed
 * forwarded header (an attacker rate-limits themselves), but an exemption cannot. So for the
 * identity decision we trust only CF-Connecting-IP (Cloudflare overwrites it on every proxied
 * request) or the TCP peer REMOTE_ADDR, never the first X-Forwarded-For entry a client can set.
 *
 * @return string Client IP, or 'unknown' when none is available
 * @internal
 */
function crawlerIdentityTrustedClientIp(): string
{
    $cfIp = trim($_SERVER['HTTP_CF_CONNECTING_IP'] ?? '');
    if ($cfIp !== '') {
        return $cfIp;
    }
    $remote = trim($_SERVER['REMOTE_ADDR'] ?? '');
    return $remote !== '' ? $remote : 'unknown';
}

/**
 * Whether an IP is a known search-engine crawler.
 *
 * Order: Google CIDR, then Bing/Yandex memo gated on the request user-agent. Google matching is
 * pure CIDR with no DNS. Bing and Yandex have no published range lists, so they are verified by
 * reverse DNS only when the request advertises that engine's user-agent; the memo makes that a
 * once-per-IP-per-window lookup. A human with a browser user-agent never triggers a DNS call
 * here.
 *
 * The Cloudflare cf-verified-bot header is deliberately not honored: the free tier never emits
 * it, and nginx does not strip or validate a client-sent value, so trusting it would only open a
 * forgeable bypass.
 *
 * @param string $ip Client IP address
 * @param array<string,mixed> $serverEnv Request environment (defaults to $_SERVER)
 * @return bool
 */
function isKnownSearchEngineCrawler(string $ip, array $serverEnv = null): bool
{
    if ($ip === '' || $ip === null) {
        return false;
    }
    // Only valid addresses reach DNS or CIDR: gethostbyaddr() throws on malformed input under
    // PHP 8, and a junk CF-Connecting-IP must not turn a rate-limit call into a 500.
    if (filter_var($ip, FILTER_VALIDATE_IP) === false) {
        return false;
    }
    if ($serverEnv === null) {
        $serverEnv = $_SERVER;
    }
    $ua = strtolower((string) ($serverEnv['HTTP_USER_AGENT'] ?? ''));

    $google = crawlerIdentityGooglePrefixes();
    if (is_array($google) && crawlerIdentityCidrMatch($ip, $google)) {
        return true;
    }

    // Bing and Yandex: only resolve when the request claims to be that bot. Prevents a
    // reverse-DNS lookup on ordinary browser traffic.
    if (str_contains($ua, 'bingbot') || str_contains($ua, 'bingpreview')) {
        if (crawlerIdentityMemoCheck($ip, getCrawlerIdentityBingPath(), ['.search.msn.com'])) {
            return true;
        }
    }
    if (str_contains($ua, 'yandex')) {
        if (crawlerIdentityMemoCheck($ip, getCrawlerIdentityYandexPath(), ['.yandex.ru', '.yandex.net', '.yandex.com'])) {
            return true;
        }
    }

    return false;
}

/**
 * Fetch the Google crawler CIDR JSON.
 *
 * Test hook: set `$GLOBALS['crawlerIdentityTestHttpGet']` to a callable
 * `function(string $url, int $timeout): array{body: string|false, http_code: int}`.
 *
 * @return array{0: string|false, 1: int} body, http code
 * @internal
 */
function crawlerIdentityFetchGoogleList(): array
{
    if (isset($GLOBALS['crawlerIdentityTestHttpGet']) && is_callable($GLOBALS['crawlerIdentityTestHttpGet'])) {
        $result = ($GLOBALS['crawlerIdentityTestHttpGet'])(
            'https://developers.google.com/static/crawling/ipranges/common-crawlers.json',
            SEO_CRAWLER_HTTP_TIMEOUT
        );
        if (is_array($result)) {
            $body = is_string($result['body'] ?? null) ? (string) $result['body'] : false;
            $code = is_numeric($result['http_code'] ?? null) ? (int) $result['http_code'] : 0;
            return [$body, $code];
        }
        return [false, 0];
    }

    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL => 'https://developers.google.com/static/crawling/ipranges/common-crawlers.json',
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => CURL_CONNECT_TIMEOUT,
        CURLOPT_TIMEOUT => SEO_CRAWLER_HTTP_TIMEOUT,
        CURLOPT_HTTPHEADER => ['Accept: application/json'],
    ]);
    $body = curl_exec($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return [$body, $code];
}

/**
 * Refresh the Google CIDR list and prune expired memo entries.
 *
 * Worker entry point (scripts/refresh-seo-crawlers.php). Fetches Google's official list,
 * validates the shape, and retains the previous file on any failure. A failure older than
 * SEO_CRAWLER_STALE_AFTER_SECONDS escalates to error-level logging.
 *
 * @return array<string, mixed>
 */
function crawlerIdentityRefresh(): array
{
    $summary = [
        'google_status' => 'unchanged',
        'google_prefixes' => 0,
        'bing_memo_entries' => 0,
        'yandex_memo_entries' => 0,
        'negative_memo_entries' => 0,
        'google_cache_age_seconds' => null,
    ];

    // --- Google CIDR list ---
    $path = getCrawlerIdentityGooglePath();
    $now = time();
    $priorAge = file_exists($path) ? ($now - (int) filemtime($path)) : PHP_INT_MAX;

    [$body, $code] = crawlerIdentityFetchGoogleList();

    if ($body !== false && $code === HTTP_STATUS_OK && $body !== '') {
        $json = @json_decode($body, true);
        if (is_array($json) && isset($json['prefixes']) && is_array($json['prefixes'])) {
            [$written, $prefixCount] = crawlerIdentityWriteGoogleList($path, $json);
            if ($written) {
                $summary['google_status'] = 'fetched';
                $summary['google_prefixes'] = $prefixCount;
                if (function_exists('apcu_delete')) {
                    @apcu_delete('crawler_identity_google');
                }
                aviationwx_log('info', 'crawler_identity: google CIDR list refreshed', [
                    'prefixes' => $prefixCount,
                ], 'app');
            } else {
                $summary['google_status'] = 'write_failed';
                aviationwx_log('warning', 'crawler_identity: google CIDR list fetch ok but write failed', [
                    'path' => $path,
                ], 'app');
            }
        } else {
            $summary['google_status'] = 'malformed';
            aviationwx_log('warning', 'crawler_identity: google CIDR list malformed', [], 'app');
        }
    } else {
        $summary['google_status'] = 'fetch_failed';
        if ($priorAge >= SEO_CRAWLER_STALE_AFTER_SECONDS) {
            aviationwx_log('error', 'crawler_identity: google CIDR list stale and refresh failing', [
                'http_code' => $code,
                'cache_age_seconds' => $priorAge,
            ], 'app');
        } else {
            aviationwx_log('warning', 'crawler_identity: google CIDR list refresh failed, retaining prior file', [
                'http_code' => $code,
            ], 'app');
        }
    }

    // --- Prune memo files ---
    $pruneSpecs = [
        [getCrawlerIdentityBingPath(), 'bing'],
        [getCrawlerIdentityYandexPath(), 'yandex'],
        [getCrawlerIdentityNegativePath(), 'negative'],
    ];
    foreach ($pruneSpecs as $entry) {
        $memoPath = (string) $entry[0];
        $label = (string) $entry[1];
        $memo = crawlerIdentityReadMemo($memoPath);
        $keep = [];
        foreach ($memo as $ip => $expires) {
            if (is_numeric($expires) && (int) $expires > $now) {
                $keep[$ip] = $expires;
            }
        }
        if (count($keep) !== count($memo)) {
            crawlerIdentityWriteMemo($memoPath, $keep);
        }
        $summary[$label . '_memo_entries'] = count($keep);
    }

    $summary['google_cache_age_seconds'] = file_exists($path) ? ($now - (int) filemtime($path)) : null;
    return $summary;
}

/**
 * Atomically write the Google CIDR list, preserving the official JSON shape.
 *
 * @return array{0: bool, 1: int} written, prefix count
 * @internal
 */
function crawlerIdentityWriteGoogleList(string $path, array $json): array
{
    $dir = dirname($path);
    if (!is_dir($dir)) {
        if (!@mkdir($dir, 0755, true) && !is_dir($dir)) {
            return [false, 0];
        }
    }
    $tmp = $path . '.tmp.' . getmypid();
    $encoded = json_encode($json, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    if ($encoded === false || file_put_contents($tmp, $encoded, LOCK_EX) === false) {
        return [false, 0];
    }
    if (!@rename($tmp, $path)) {
        @unlink($tmp);
        return [false, 0];
    }
    return [true, count($json['prefixes'])];
}