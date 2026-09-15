<?php
/**
 * Offline unit tests for the crawler identity allowlist (no network DNS or HTTP).
 *
 * Google CIDR matching uses a trimmed fixture. Bing/Yandex memos use a scripted DNS resolver
 * via the $GLOBALS test hook, matching the repo's testhook convention.
 */

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

// Cache paths are sandboxed by tests/bootstrap.php before cache-paths loads: a disposable
// CACHE_CRAWLER_IDENTITY_DIR avoids touching the project's real allowlist files.

require_once __DIR__ . '/../../lib/crawler-identity.php';

final class CrawlerIdentityTest extends TestCase
{
    protected function setUp(): void
    {
        // Always start clean.
        foreach ([
            getCrawlerIdentityGooglePath(),
            getCrawlerIdentityBingPath(),
            getCrawlerIdentityYandexPath(),
            getCrawlerIdentityNegativePath(),
        ] as $p) {
            if (file_exists($p)) {
                @unlink($p);
            }
            if (file_exists($p . '.lock')) {
                @unlink($p . '.lock');
            }
        }
        if (function_exists('apcu_delete')) {
            @apcu_delete('crawler_identity_google');
        }
    }

    protected function tearDown(): void
    {
        unset(
            $GLOBALS['crawlerIdentityDnsResolver'],
            $GLOBALS['crawlerIdentityTestHttpGet'],
            $GLOBALS['crawlerDnsCallCount'],
            $GLOBALS['crawlerIdentityTestFixture'],
            $GLOBALS['crawlerIdentityTestForwardRecords']
        );
        foreach ([
            getCrawlerIdentityGooglePath(),
            getCrawlerIdentityBingPath(),
            getCrawlerIdentityYandexPath(),
            getCrawlerIdentityNegativePath(),
        ] as $p) {
            if (file_exists($p)) {
                @unlink($p);
            }
            if (file_exists($p . '.lock')) {
                @unlink($p . '.lock');
            }
        }
        parent::tearDown();
    }

    public function testCrawlerIdentityCidrMatch_V4InRange_ReturnsTrue(): void
    {
        $prefixes = [
            ['ipv4Prefix' => '66.249.64.0/19'],
            ['ipv4Prefix' => '93.184.216.0/24'],
        ];
        $this->assertTrue(crawlerIdentityCidrMatch('66.249.80.1', $prefixes));
        $this->assertTrue(crawlerIdentityCidrMatch('66.249.95.255', $prefixes));
        $this->assertTrue(crawlerIdentityCidrMatch('93.184.216.42', $prefixes));
    }

    public function testCrawlerIdentityCidrMatch_V4OutsideRange_ReturnsFalse(): void
    {
        $prefixes = [
            ['ipv4Prefix' => '66.249.64.0/19'],
            ['ipv4Prefix' => '93.184.216.0/24'],
        ];
        $this->assertFalse(crawlerIdentityCidrMatch('8.8.8.8', $prefixes));
        $this->assertFalse(crawlerIdentityCidrMatch('66.249.128.1', $prefixes));
        $this->assertFalse(crawlerIdentityCidrMatch('93.184.217.1', $prefixes));
    }

    public function testCrawlerIdentityCidrMatch_V6InRange_ReturnsTrue(): void
    {
        $prefixes = [
            ['ipv6Prefix' => '2001:4860:4801:10::/64'],
        ];
        $this->assertTrue(crawlerIdentityCidrMatch('2001:4860:4801:10::5', $prefixes));
        $this->assertTrue(crawlerIdentityCidrMatch('2001:4860:4801:10:ffff:ffff:ffff:ffff', $prefixes));
    }

    public function testCrawlerIdentityCidrMatch_V6OutsideRange_ReturnsFalse(): void
    {
        $prefixes = [
            ['ipv6Prefix' => '2001:4860:4801:10::/64'],
        ];
        $this->assertFalse(crawlerIdentityCidrMatch('2001:4860:4801:20::1', $prefixes));
        // IPv4 against a v6-only list must not match
        $this->assertFalse(crawlerIdentityCidrMatch('66.249.80.1', $prefixes));
    }

    public function testCrawlerIdentityCidrMatch_CrossFamilyPrefixes_IgnoresMismatchedFamily(): void
    {
        $mixed = [
            ['ipv4Prefix' => '66.249.64.0/19'],
            ['ipv6Prefix' => '2001:4860:4801:10::/64'],
        ];
        $this->assertTrue(crawlerIdentityCidrMatch('66.249.80.1', $mixed));
        $this->assertTrue(crawlerIdentityCidrMatch('2001:4860:4801:10::9', $mixed));
        $this->assertFalse(crawlerIdentityCidrMatch('8.8.8.8', $mixed));
    }

    public function testCrawlerIdentityNormalizeHost_TrimsDotAndCase_ReturnsCleanHost(): void
    {
        $this->assertSame('crawl-1-2-3-4.googlebot.com', crawlerIdentityNormalizeHost('crawl-1-2-3-4.GoogleBot.COM.'));
        $this->assertSame(null, crawlerIdentityNormalizeHost(''));
        $this->assertSame(null, crawlerIdentityNormalizeHost(null));
    }

    public function testCrawlerIdentityCidrMatch_NonDecimalPrefixLength_Ignored(): void
    {
        // (int) 'abc' is 0 in PHP, which would widen a malformed prefix to the whole family.
        $prefixes = [
            ['ipv4Prefix' => '66.249.64.0/abc'],
            ['ipv4Prefix' => '8.8.8.0/24'],
        ];
        $this->assertFalse(crawlerIdentityCidrMatch('66.249.80.1', $prefixes));
        $this->assertTrue(crawlerIdentityCidrMatch('8.8.8.9', $prefixes));
    }

    public function testCrawlerIdentityCidrMatch_OutOfRangePrefixLength_Ignored(): void
    {
        $prefixes = [
            ['ipv4Prefix' => '66.249.64.0/64'],
            ['ipv6Prefix' => '2001:4860:4801:10::/200'],
        ];
        $this->assertFalse(crawlerIdentityCidrMatch('66.249.80.1', $prefixes));
        $this->assertFalse(crawlerIdentityCidrMatch('2001:4860:4801:10::5', $prefixes));
    }

    public function testCrawlerIdentityVerifyDnsChain_BingSuffixAndForwardMatch_Accepts(): void
    {
        $GLOBALS['crawlerIdentityDnsResolver'] = function (string $q, bool $forward): ?string {
            if (!$forward) {
                return 'msnbot-123.search.msn.com';
            }
            // host -> ip must echo back the original
            return $q === 'msnbot-123.search.msn.com' ? '207.46.13.42' : null;
        };
        $this->assertTrue(crawlerIdentityVerifyDnsChain('207.46.13.42', ['.search.msn.com']));
    }

    public function testCrawlerIdentityVerifyDnsChain_WrongSuffix_Rejects(): void
    {
        $GLOBALS['crawlerIdentityDnsResolver'] = function (string $q, bool $forward): ?string {
            return $forward ? '207.46.13.43' : 'evil.example.org';
        };
        $this->assertFalse(crawlerIdentityVerifyDnsChain('207.46.13.43', ['.search.msn.com']));
    }

    public function testCrawlerIdentityVerifyDnsChain_ForwardMismatch_Rejects(): void
    {
        $GLOBALS['crawlerIdentityDnsResolver'] = function (string $q, bool $forward): ?string {
            return $forward ? '9.9.9.9' : 'msnbot-1.search.msn.com';
        };
        $this->assertFalse(crawlerIdentityVerifyDnsChain('207.46.13.44', ['.search.msn.com']));
    }

    public function testCrawlerIdentityMemoCheck_VerifiedIp_NoSecondDnsCallInWindow(): void
    {
        $GLOBALS['crawlerDnsCallCount'] = 0;
        $GLOBALS['crawlerIdentityDnsResolver'] = function (string $q, bool $forward): ?string {
            $GLOBALS['crawlerDnsCallCount'] = (int) ($GLOBALS['crawlerDnsCallCount'] ?? 0) + 1;
            return $forward ? '207.46.13.45' : 'msnbot-2.search.msn.com';
        };
        $memoPath = getCrawlerIdentityBingPath();

        // First call verifies via DNS and writes memo
        $this->assertTrue(crawlerIdentityMemoCheck('207.46.13.45', $memoPath, ['.search.msn.com']));
        $firstCount = (int) $GLOBALS['crawlerDnsCallCount'];
        $this->assertTrue($firstCount >= 2);

        // Second call within the memo window must not hit the resolver at all
        $this->assertTrue(crawlerIdentityMemoCheck('207.46.13.45', $memoPath, ['.search.msn.com']));
        $this->assertSame($firstCount, (int) $GLOBALS['crawlerDnsCallCount']);
    }

    public function testCrawlerIdentityMemoCheck_FailedVerify_UsesShortNegativeMemo(): void
    {
        $GLOBALS['crawlerDnsCallCount'] = 0;
        $GLOBALS['crawlerIdentityDnsResolver'] = function (string $q, bool $forward): ?string {
            $GLOBALS['crawlerDnsCallCount'] = (int) ($GLOBALS['crawlerDnsCallCount'] ?? 0) + 1;
            return $forward ? '9.9.9.9' : 'msnbot-2.search.msn.com';
        };
        $memoPath = getCrawlerIdentityBingPath();

        // Failed verify records a negative memo entry.
        $this->assertFalse(crawlerIdentityMemoCheck('207.46.13.50', $memoPath, ['.search.msn.com']));
        $firstCount = (int) $GLOBALS['crawlerDnsCallCount'];
        $this->assertTrue($firstCount >= 2);

        // Repeat within the negative memo window skips DNS.
        $this->assertFalse(crawlerIdentityMemoCheck('207.46.13.50', $memoPath, ['.search.msn.com']));
        $this->assertSame($firstCount, (int) $GLOBALS['crawlerDnsCallCount']);
    }

    public function testCrawlerIdentityMemoCheck_FailedBingVerify_DoesNotSuppressYandex(): void
    {
        // The negative memo is namespaced per engine: a failed Bing lookup for an IP must not
        // short-circuit a Yandex verification of that same address.
        $GLOBALS['crawlerDnsCallCount'] = 0;
        $GLOBALS['crawlerIdentityDnsResolver'] = function (string $q, bool $forward): ?string {
            $GLOBALS['crawlerDnsCallCount'] = (int) ($GLOBALS['crawlerDnsCallCount'] ?? 0) + 1;
            return $forward ? '9.9.9.9' : 'msnbot-2.search.msn.com';
        };
        $bingPath = getCrawlerIdentityBingPath();

        $this->assertFalse(crawlerIdentityMemoCheck('207.46.13.51', $bingPath, ['.search.msn.com']));
        $firstCount = (int) $GLOBALS['crawlerDnsCallCount'];

        // The bing failure is memoized, but a yandex check for the same IP still runs DNS.
        $yandexPath = getCrawlerIdentityYandexPath();
        $this->assertFalse(crawlerIdentityMemoCheck('207.46.13.51', $yandexPath, ['.yandex.ru', '.yandex.net', '.yandex.com']));
        $this->assertTrue((int) $GLOBALS['crawlerDnsCallCount'] > $firstCount);
    }

    public function testCrawlerIdentityRefresh_FetchesGoogleListAndPrunesMemo_Succeeds(): void
    {
        $GLOBALS['crawlerIdentityTestFixture'] = file_get_contents(__DIR__ . '/../Fixtures/google-crawlers-sample.json');
        $GLOBALS['crawlerIdentityTestHttpGet'] = function (string $url, int $timeout): array {
            return ['body' => $GLOBALS['crawlerIdentityTestFixture'], 'http_code' => 200];
        };
        // Seed an expired memo entry to prove pruning cleans it.
        crawlerIdentityWriteMemo(getCrawlerIdentityBingPath(), ['207.46.13.9' => time() - 1]);

        $summary = crawlerIdentityRefresh();

        $this->assertSame('fetched', $summary['google_status']);
        $this->assertSame(4, $summary['google_prefixes']);
        $this->assertTrue(file_exists(getCrawlerIdentityGooglePath()));
        $this->assertSame(0, $summary['bing_memo_entries']);
    }

    public function testCrawlerIdentityRefresh_FetchFailure_RetainsPriorFile(): void
    {
        // Existing valid file
        crawlerIdentityWriteGoogleList(
            getCrawlerIdentityGooglePath(),
            ['creationTime' => 'x', 'prefixes' => [['ipv4Prefix' => '66.249.64.0/19']]]
        );
        $GLOBALS['crawlerIdentityTestHttpGet'] = function (string $url, int $timeout): array {
            return ['body' => false, 'http_code' => 0];
        };
        $summary = crawlerIdentityRefresh();
        $this->assertSame('fetch_failed', $summary['google_status']);
        // Prior file retained and still parse-read-desirable
        $this->assertSame(1, count(crawlerIdentityGooglePrefixes()));
    }

    public function testCrawlerIdentityRefresh_EmptyPrefixList_ReportedMalformed(): void
    {
        // A 200 with no usable prefixes must not replace a valid allowlist with an empty one.
        crawlerIdentityWriteGoogleList(
            getCrawlerIdentityGooglePath(),
            ['creationTime' => 'x', 'prefixes' => [['ipv4Prefix' => '66.249.64.0/19']]]
        );
        $GLOBALS['crawlerIdentityTestHttpGet'] = function (string $url, int $timeout): array {
            return ['body' => '{"creationTime":"now","prefixes":[]}', 'http_code' => 200];
        };
        $summary = crawlerIdentityRefresh();
        $this->assertSame('malformed', $summary['google_status']);
        $this->assertSame(1, count(crawlerIdentityGooglePrefixes()));
    }

    public function testCrawlerIdentityRefresh_JunkNetworkPrefix_ReportedMalformed(): void
    {
        // not-an-ip/24 must not qualify as a usable prefix.
        crawlerIdentityWriteGoogleList(
            getCrawlerIdentityGooglePath(),
            ['creationTime' => 'x', 'prefixes' => [['ipv4Prefix' => '66.249.64.0/19']]]
        );
        $GLOBALS['crawlerIdentityTestHttpGet'] = function (string $url, int $timeout): array {
            return ['body' => '{"creationTime":"now","prefixes":[{"ipv4Prefix":"not-an-ip/24"}]}', 'http_code' => 200];
        };
        $summary = crawlerIdentityRefresh();
        $this->assertSame('malformed', $summary['google_status']);
        $this->assertSame(1, count(crawlerIdentityGooglePrefixes()));
    }

    public function testCrawlerIdentityAdmission_MalformedIp_ReturnsFalseWithoutDns(): void
    {
        // A junk CF-Connecting-IP must not reach DNS (gethostbyaddr throws on malformed input).
        $GLOBALS['crawlerDnsCallCount'] = 0;
        $GLOBALS['crawlerIdentityDnsResolver'] = function (string $q, bool $forward): ?string {
            $GLOBALS['crawlerDnsCallCount'] = (int) ($GLOBALS['crawlerDnsCallCount'] ?? 0) + 1;
            return 'x';
        };
        $this->assertFalse(isKnownSearchEngineCrawler('not-an-ip', ['HTTP_USER_AGENT' => 'yandex']));
        $this->assertSame(0, (int) $GLOBALS['crawlerDnsCallCount']);
    }

    public function testCrawlerIdentityAdmission_ForgedCfVerifiedBotHeader_NotAdmitted(): void
    {
        // The cf-verified-bot header is not honored: nginx does not strip/validate a client-sent
        // value, so trusting it would be a forgeable bypass. A browser UA with that header must
        // not be admitted (no Google CIDR match, no Bing/Yandex UA gate).
        $this->assertFalse(isKnownSearchEngineCrawler(
            '9.9.9.9',
            ['HTTP_CF_VERIFIED_BOT' => 'true', 'HTTP_USER_AGENT' => 'Mozilla/5.0']
        ));
    }

    public function testCrawlerIdentityAdmissionForRequest_FirstParty_ForwardsIpAndUa(): void
    {
        // Seed a Google CIDR so the forwarded hardened IP is verifiable as a crawler.
        crawlerIdentityWriteGoogleList(
            getCrawlerIdentityGooglePath(),
            ['creationTime' => 'x', 'prefixes' => [['ipv4Prefix' => '66.249.64.0/19']]]
        );
        [$ip, $env] = crawlerIdentityAdmissionForRequest(
            true,
            '66.249.80.1',
            [
                'HTTP_X_FORWARDED_ADMISSION_IP' => '66.249.80.1',
                'HTTP_X_FORWARDED_CLIENT_UA' => 'Mozilla/5.0 (compatible; bingbot/2.0)',
            ]
        );
        $this->assertSame('66.249.80.1', $ip);
        $this->assertSame('mozilla/5.0 (compatible; bingbot/2.0)', strtolower($env['HTTP_USER_AGENT']));
        // The forwarded identity must actually admit the crawler.
        $this->assertTrue(isKnownSearchEngineCrawler($ip, $env));
    }

    public function testCrawlerIdentityAdmissionForRequest_FirstPartyWithoutHardenedIp_UsesTrustedPeer(): void
    {
        // No hardened admission IP: the spoofable bucketing header must NOT mint the exemption.
        // The peer (REMOTE_ADDR) wins so a direct-origin client can't claim a Google identity.
        [$ip, $env] = crawlerIdentityAdmissionForRequest(
            true,
            '66.249.80.1',
            ['REMOTE_ADDR' => '9.9.9.9']
        );
        $this->assertSame('9.9.9.9', $ip);
    }

    public function testCrawlerIdentityAdmissionForRequest_External_UsesTrustedPeerNotForwardedHeader(): void
    {
        // A non-Cloudflare peer with a forged CF-Connecting-IP must fall back to REMOTE_ADDR so
        // a direct-origin client cannot mint a Google identity.
        [$ip, $env] = crawlerIdentityAdmissionForRequest(
            false,
            null,
            ['REMOTE_ADDR' => '9.9.9.9', 'HTTP_CF_CONNECTING_IP' => '66.249.80.1']
        );
        $this->assertSame('9.9.9.9', $ip);
        $this->assertNull($env);
    }

    public function testCrawlerIdentityAdmission_BingUa_VerifiedOnly(): void
    {
        $GLOBALS['crawlerDnsCallCount'] = 0;
        $GLOBALS['crawlerIdentityDnsResolver'] = function (string $q, bool $forward): ?string {
            $GLOBALS['crawlerDnsCallCount'] = (int) ($GLOBALS['crawlerDnsCallCount'] ?? 0) + 1;
            return $forward ? '207.46.13.99' : 'msnbot-5.search.msn.com';
        };
        // Bing UA + matching IP -> verified
        $this->assertTrue(isKnownSearchEngineCrawler(
            '207.46.13.99',
            ['HTTP_USER_AGENT' => 'Mozilla/5.0 (compatible; bingbot/2.0)']
        ));
        // Same IP but a browser UA -> no memo hit and no DNS for bing (UA-gated)
        $before = (int) $GLOBALS['crawlerDnsCallCount'];
        // DNS resolver still answers, but the call must not happen for a browser UA.
        $this->assertFalse(isKnownSearchEngineCrawler(
            '207.46.13.99',
            ['HTTP_USER_AGENT' => 'Mozilla/5.0 (Macintosh; Chrome/122)']
        ));
        $this->assertSame($before, (int) $GLOBALS['crawlerDnsCallCount']);
    }

    public function testCrawlerIdentityMemoCheck_MissLock_SerializesConcurrentWriters(): void
    {
        // A miss must go through the lock path (proven by the lock files existing afterwards)
        // and the verified entry must be reused on the next call without a second DNS round.
        $GLOBALS['crawlerDnsCallCount'] = 0;
        $GLOBALS['crawlerIdentityDnsResolver'] = function (string $q, bool $forward): ?string {
            $GLOBALS['crawlerDnsCallCount'] = (int) ($GLOBALS['crawlerDnsCallCount'] ?? 0) + 1;
            return $forward ? '207.46.13.77' : 'msnbot-lock.search.msn.com';
        };
        $memoPath = getCrawlerIdentityBingPath();
        $this->assertTrue(crawlerIdentityMemoCheck('207.46.13.77', $memoPath, ['.search.msn.com']));
        $this->assertTrue(file_exists($memoPath . '.lock'), 'miss path must create the memo lock');
        $afterFirst = (int) $GLOBALS['crawlerDnsCallCount'];
        $this->assertTrue($afterFirst >= 2);
        $this->assertTrue(crawlerIdentityMemoCheck('207.46.13.77', $memoPath, ['.search.msn.com']));
        $this->assertSame($afterFirst, (int) $GLOBALS['crawlerDnsCallCount']);
    }

    public function testCrawlerIdentityVerifyDnsChain_AnyForwardAddress_AcceptsNotJustFirst(): void
    {
        // PTR host resolves forward to two A records; the verified IP is the second one.
        $GLOBALS['crawlerIdentityDnsResolver'] = function (string $q, bool $forward): ?string {
            return $forward ? '207.46.13.10' : 'msnbot-multi.search.msn.com';
        };
        $GLOBALS['crawlerIdentityTestForwardRecords'] = [
            ['ip' => '207.46.13.10'],
            ['ip' => '207.46.13.11'],
        ];
        $this->assertTrue(crawlerIdentityVerifyDnsChain('207.46.13.11', ['.search.msn.com']));
        $this->assertFalse(crawlerIdentityVerifyDnsChain('207.46.13.12', ['.search.msn.com']));
    }

    public function testCrawlerIdentityVerifyDnsChain_V6ExpandedForwardRecord_Accepts(): void
    {
        // Request uses a compressed v6 spelling; DNS returns the same address fully expanded.
        // Text equality would reject it, packed comparison must accept it.
        $GLOBALS['crawlerIdentityDnsResolver'] = function (string $q, bool $forward): ?string {
            return $forward ? '2001:4860:4801:0010:0000:0000:0000:0005' : 'msnbot-v6.search.msn.com';
        };
        $GLOBALS['crawlerIdentityTestForwardRecords'] = [
            ['ipv6' => '2001:4860:4801:0010:0000:0000:0000:0005'],
        ];
        $this->assertTrue(crawlerIdentityVerifyDnsChain('2001:4860:4801:10::5', ['.search.msn.com']));
    }

    public function testCrawlerIdentityTrustedClientIp_IgnoresSpoofableForwardedFor_FallsBackToRemoteAddr(): void
    {
        // Client sets X-Forwarded-For to a Google range; without a validated Cloudflare peer,
        // the trusted identity must fall back to REMOTE_ADDR, never any forwarded value.
        $oldServer = $_SERVER;
        try {
            $_SERVER = [
                'HTTP_X_FORWARDED_FOR' => '66.249.80.1',
                'REMOTE_ADDR' => '9.9.9.9',
            ];
            $this->assertSame('9.9.9.9', crawlerIdentityTrustedClientIp());

            // CF-Connecting-IP from a NON-Cloudflare peer must be ignored (forgeable origin-direct).
            $_SERVER = [
                'HTTP_CF_CONNECTING_IP' => '66.249.80.1',
                'HTTP_X_FORWARDED_FOR' => '6.6.6.6',
                'REMOTE_ADDR' => '9.9.9.9',
            ];
            $this->assertSame('9.9.9.9', crawlerIdentityTrustedClientIp());

            // Only a real Cloudflare peer may assert CF-Connecting-IP.
            $_SERVER = [
                'HTTP_CF_CONNECTING_IP' => '66.249.80.1',
                'REMOTE_ADDR' => '104.16.1.1',
            ];
            $this->assertSame('66.249.80.1', crawlerIdentityTrustedClientIp());
        } finally {
            $_SERVER = $oldServer;
        }
    }

    public function testCrawlerIdentityRefresh_FailureWithoutPriorCache_ReportsNoUsableAllowlist(): void
    {
        // No prior google file, and the fetch fails.
        $this->assertFalse(file_exists(getCrawlerIdentityGooglePath()));
        $GLOBALS['crawlerIdentityTestHttpGet'] = function (string $url, int $timeout): array {
            return ['body' => false, 'http_code' => 0];
        };
        $summary = crawlerIdentityRefresh();
        $this->assertSame('fetch_failed', $summary['google_status']);
        $this->assertNull($summary['google_cache_age_seconds']);
        // Worker treats null cache age as no usable allowlist: exit 1. Mirror that condition here
        // to lock the shape without invoking the worker script.
        $age = $summary['google_cache_age_seconds'];
        $noUsableCache = $age === null || (is_numeric($age) && (int) $age >= SEO_CRAWLER_STALE_AFTER_SECONDS);
        $this->assertTrue($noUsableCache);
    }

    public function testCrawlerIdentityPruneNegativeMap_ExpiredAndOverflow_Evicted(): void
    {
        $now = time();
        $expired = [];
        for ($i = 0; $i < 20; $i++) {
            $expired['e' . $i] = $now - 1;
        }
        $this->assertSame([], crawlerIdentityPruneNegativeMap($expired, $now));

        $fresh = [];
        for ($i = 0; $i < SEO_CRAWLER_NEGATIVE_MEMO_MAX_ENTRIES + 10; $i++) {
            $fresh['k' . $i] = $now + (10 + $i); // keep newest; evict most-expired
        }
        $kept = crawlerIdentityPruneNegativeMap($fresh, $now);
        $this->assertLessThanOrEqual(SEO_CRAWLER_NEGATIVE_MEMO_MAX_ENTRIES, count($kept));
        $this->assertArrayNotHasKey('k0', $kept, 'oldest entries must be evicted first');
        $this->assertArrayHasKey('k' . (SEO_CRAWLER_NEGATIVE_MEMO_MAX_ENTRIES + 9), $kept);
    }
}