<?php
/**
 * Offline unit tests for the crawler identity allowlist (no network DNS or HTTP).
 *
 * Google CIDR matching uses a trimmed fixture. Bing/Yandex memos use a scripted DNS resolver
 * via the $GLOBALS test hook, matching the repo's testhook convention.
 */

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

// Steer the crawler identity cache dir to a disposable path before cache-paths loads.
if (!defined('CACHE_CRAWLER_IDENTITY_DIR')) {
    define('CACHE_CRAWLER_IDENTITY_DIR', sys_get_temp_dir() . '/crawler_identity_test_' . getmypid());
}

if (!defined('CACHE_BASE_DIR')) {
    define('CACHE_BASE_DIR', sys_get_temp_dir() . '/crawler_identity_test_base_' . getmypid());
}

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
        ] as $p) {
            if (file_exists($p)) {
                @unlink($p);
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
            $GLOBALS['crawlerIdentityTestFixture']
        );
        foreach ([
            getCrawlerIdentityGooglePath(),
            getCrawlerIdentityBingPath(),
            getCrawlerIdentityYandexPath(),
        ] as $p) {
            if (file_exists($p)) {
                @unlink($p);
            }
        }
        parent::tearDown();
    }

    public function testCidrMatchInsideV4Range(): void
    {
        $prefixes = [
            ['ipv4Prefix' => '66.249.64.0/19'],
            ['ipv4Prefix' => '93.184.216.0/24'],
        ];
        $this->assertTrue(crawlerIdentityCidrMatch('66.249.80.1', $prefixes));
        $this->assertTrue(crawlerIdentityCidrMatch('66.249.95.255', $prefixes));
        $this->assertTrue(crawlerIdentityCidrMatch('93.184.216.42', $prefixes));
    }

    public function testCidrNoMatchV4(): void
    {
        $prefixes = [
            ['ipv4Prefix' => '66.249.64.0/19'],
            ['ipv4Prefix' => '93.184.216.0/24'],
        ];
        $this->assertFalse(crawlerIdentityCidrMatch('8.8.8.8', $prefixes));
        $this->assertFalse(crawlerIdentityCidrMatch('66.249.128.1', $prefixes));
        $this->assertFalse(crawlerIdentityCidrMatch('93.184.217.1', $prefixes));
    }

    public function testCidrMatchInsideV6Range(): void
    {
        $prefixes = [
            ['ipv6Prefix' => '2001:4860:4801:10::/64'],
        ];
        $this->assertTrue(crawlerIdentityCidrMatch('2001:4860:4801:10::5', $prefixes));
        $this->assertTrue(crawlerIdentityCidrMatch('2001:4860:4801:10:ffff:ffff:ffff:ffff', $prefixes));
    }

    public function testCidrNoMatchV6(): void
    {
        $prefixes = [
            ['ipv6Prefix' => '2001:4860:4801:10::/64'],
        ];
        $this->assertFalse(crawlerIdentityCidrMatch('2001:4860:4801:20::1', $prefixes));
        // IPv4 against a v6-only list must not match
        $this->assertFalse(crawlerIdentityCidrMatch('66.249.80.1', $prefixes));
    }

    public function testCidrIgnoresCrossFamilyPrefixes(): void
    {
        $mixed = [
            ['ipv4Prefix' => '66.249.64.0/19'],
            ['ipv6Prefix' => '2001:4860:4801:10::/64'],
        ];
        $this->assertTrue(crawlerIdentityCidrMatch('66.249.80.1', $mixed));
        $this->assertTrue(crawlerIdentityCidrMatch('2001:4860:4801:10::9', $mixed));
        $this->assertFalse(crawlerIdentityCidrMatch('8.8.8.8', $mixed));
    }

    public function testNormalizeHostTrimsDotAndCase(): void
    {
        $this->assertSame('crawl-1-2-3-4.googlebot.com', crawlerIdentityNormalizeHost('crawl-1-2-3-4.GoogleBot.COM.'));
        $this->assertSame(null, crawlerIdentityNormalizeHost(''));
        $this->assertSame(null, crawlerIdentityNormalizeHost(null));
    }

    public function testDnsChainAcceptsBingSuffixAndForwardMatch(): void
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

    public function testDnsChainRejectsWrongSuffix(): void
    {
        $GLOBALS['crawlerIdentityDnsResolver'] = function (string $q, bool $forward): ?string {
            return $forward ? '207.46.13.43' : 'evil.example.org';
        };
        $this->assertFalse(crawlerIdentityVerifyDnsChain('207.46.13.43', ['.search.msn.com']));
    }

    public function testDnsChainRejectsWhenForwardMismatch(): void
    {
        $GLOBALS['crawlerIdentityDnsResolver'] = function (string $q, bool $forward): ?string {
            return $forward ? '9.9.9.9' : 'msnbot-1.search.msn.com';
        };
        $this->assertFalse(crawlerIdentityVerifyDnsChain('207.46.13.44', ['.search.msn.com']));
    }

    public function testMemoCheckCachesVerifiedIpWithNoSecondDnsCall(): void
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

    public function testRefreshFetchesGoogleListAndPrunesMemo(): void
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

    public function testRefreshRetainsPriorFileOnFailure(): void
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

    public function testCfVerifiedBotHeaderOnlyWhenTrue(): void
    {
        $this->assertTrue(crawlerIdentityCfVerifiedBot(['HTTP_CF_VERIFIED_BOT' => 'true']));
        $this->assertTrue(crawlerIdentityCfVerifiedBot(['HTTP_CF_VERIFIED_BOT' => 'True']));
        $this->assertFalse(crawlerIdentityCfVerifiedBot(['HTTP_CF_VERIFIED_BOT' => 'false']));
        $this->assertFalse(crawlerIdentityCfVerifiedBot([]));
        $this->assertFalse(crawlerIdentityCfVerifiedBot(['HTTP_CF_VERIFIED_BOT' => '1']));
    }

    public function testBingVerifiedOnlyWhenUaClaimsBing(): void
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
}