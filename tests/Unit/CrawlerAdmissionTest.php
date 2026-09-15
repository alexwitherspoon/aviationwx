<?php
/**
 * Unit tests: crawler admission allowlist (pure lookups, no DNS, no worker).
 */

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

// Sandbox the allowlist cache dir to a disposable path before cache-paths loads.
if (!defined('CACHE_CRAWLER_ADMISSION_DIR')) {
    define('CACHE_CRAWLER_ADMISSION_DIR', sys_get_temp_dir() . '/crawler_admission_test_' . getmypid());
}

require_once __DIR__ . '/../../lib/crawler-admission.php';

final class CrawlerAdmissionTest extends TestCase
{
    protected function setUp(): void
    {
        @unlink(getCrawlerGoogleAllowlistPath());
        @unlink(getCrawlerVerifiedIpAllowlistPath());
        @unlink(getCrawlerPendingIpsPath());
        if (function_exists('apcu_delete')) {
            @apcu_delete('crawler_admission_google');
            @apcu_delete('crawler_admission_verified');
        }
    }

    public function testCrawlerAdmissionCidrMatch_V4InRange_ReturnsTrue(): void
    {
        $prefixes = [['ipv4Prefix' => '66.249.64.0/19']];
        $this->assertTrue(crawlerAdmissionCidrMatch('66.249.95.255', $prefixes));
        $this->assertFalse(crawlerAdmissionCidrMatch('66.249.96.1', $prefixes));
    }

    public function testCrawlerAdmissionCidrMatch_V6InRange_ReturnsTrue(): void
    {
        $prefixes = [['ipv6Prefix' => '2001:4860:4801:10::/64']];
        $this->assertTrue(crawlerAdmissionCidrMatch('2001:4860:4801:10::5', $prefixes));
        $this->assertFalse(crawlerAdmissionCidrMatch('2001:4860:4801:20::1', $prefixes));
    }

    public function testCrawlerAdmissionPackV6_ShortWithoutCompression_ReturnsNull(): void
    {
        $this->assertNull(crawlerAdmissionPackV6('2001:db8:1'));
        $this->assertNull(crawlerAdmissionPackV6('::'));
        $this->assertSame(16, strlen(crawlerAdmissionPackV6('2001:db8::1')));
        $this->assertSame(16, strlen(crawlerAdmissionPackV6('::1')));
    }

    public function testCrawlerAdmissionPackV6_JunkColonForms_ReturnsNull(): void
    {
        $this->assertNull(crawlerAdmissionPackV6(':::'));
        $this->assertNull(crawlerAdmissionPackV6('1:::'));
        $this->assertNull(crawlerAdmissionPackV6('1:::2'));
        $this->assertNull(crawlerAdmissionPackV6('1:2:3:4:5:6:7:'));
    }

    public function testCrawlerAdmissionCidrMatch_NonDecimalPrefixLength_Ignored(): void
    {
        $prefixes = [['ipv4Prefix' => '66.249.64.0/abc'], ['ipv4Prefix' => '8.8.8.0/24']];
        $this->assertFalse(crawlerAdmissionCidrMatch('66.249.80.1', $prefixes));
        $this->assertTrue(crawlerAdmissionCidrMatch('8.8.8.9', $prefixes));
    }

    public function testCrawlerAdmissionHasWellFormedPrefix_JunkRejected(): void
    {
        $this->assertFalse(crawlerAdmissionHasWellFormedPrefix([]));
        $this->assertFalse(crawlerAdmissionHasWellFormedPrefix([['ipv4Prefix' => 'not-an-ip/24']]));
        $this->assertTrue(crawlerAdmissionHasWellFormedPrefix([['ipv4Prefix' => '66.249.64.0/19']]));
    }

    public function testCrawlerAdmissionWriteJson_AtomicallyWritesAndReads(): void
    {
        $path = getCrawlerGoogleAllowlistPath();
        $this->assertTrue(crawlerAdmissionWriteJson($path, ['prefixes' => [['ipv4Prefix' => '66.249.64.0/19']]]));
        $this->assertSame(1, count(crawlerAdmissionGooglePrefixes()));
        $this->assertFileDoesNotExist($path . '.tmp.' . getmypid());
    }

    public function testCrawlerAdmissionGooglePrefixes_FailsClosedWhenStale(): void
    {
        crawlerAdmissionWriteJson(getCrawlerGoogleAllowlistPath(), ['prefixes' => [['ipv4Prefix' => '66.249.64.0/19']]]);
        touch(getCrawlerGoogleAllowlistPath(), time() - CRAWLER_ALLOWLIST_MAX_AGE - 1);
        clearstatcache();
        if (function_exists('apcu_clear_cache')) {
            @apcu_clear_cache();
        }
        $this->assertNull(crawlerAdmissionGooglePrefixes());
    }

    public function testCrawlerAdmissionIsKnownCrawler_GoogleCidrAndVerifiedIp(): void
    {
        crawlerAdmissionWriteJson(getCrawlerGoogleAllowlistPath(), ['prefixes' => [['ipv4Prefix' => '66.249.64.0/19']]]);
        $this->assertTrue(isKnownCrawler('66.249.80.1'));
        $this->assertFalse(isKnownCrawler('9.9.9.9'));

        crawlerAdmissionWriteJson(getCrawlerVerifiedIpAllowlistPath(), ['207.46.13.77' => time() + 3600]);
        $this->assertTrue(isKnownCrawler('207.46.13.77'));
    }

    public function testCrawlerAdmissionIsKnownCrawler_IgnoredExpiredVerifiedIp(): void
    {
        crawlerAdmissionWriteJson(getCrawlerVerifiedIpAllowlistPath(), ['207.46.13.78' => time() - 1]);
        $this->assertFalse(isKnownCrawler('207.46.13.78'));
    }

    public function testCrawlerAdmissionIsKnownCrawler_MalformedIp_ReturnsFalse(): void
    {
        $this->assertFalse(isKnownCrawler('not-an-ip'));
        $this->assertFalse(isKnownCrawler(''));
    }

    public function testCrawlerAdmissionMaybeEnqueue_BingUaUnknownIp_QueuesForVerify(): void
    {
        crawlerAdmissionWriteJson(getCrawlerGoogleAllowlistPath(), ['prefixes' => [['ipv4Prefix' => '66.249.64.0/19']]]);
        $this->assertTrue(crawlerAdmissionMaybeEnqueue('207.46.13.99', 'Mozilla/5.0 (compatible; bingbot/2.0)'));
        $this->assertSame(['207.46.13.99'], array_keys(crawlerAdmissionPendingIps()));
    }

    public function testCrawlerAdmissionMaybeEnqueue_BrowserUa_NotQueued(): void
    {
        $this->assertFalse(crawlerAdmissionMaybeEnqueue('207.46.13.99', 'Mozilla/5.0 (Macintosh; Chrome/122)'));
        $this->assertSame([], crawlerAdmissionPendingIps());
    }

    public function testCrawlerAdmissionMaybeEnqueue_GoogleCoveredIp_NotQueued(): void
    {
        crawlerAdmissionWriteJson(getCrawlerGoogleAllowlistPath(), ['prefixes' => [['ipv4Prefix' => '66.249.64.0/19']]]);
        // A Google-IP claiming a Bing UA must not crowd the queue; it is already known.
        $this->assertFalse(crawlerAdmissionMaybeEnqueue('66.249.80.1', 'bingbot/2.0'));
        $this->assertSame([], crawlerAdmissionPendingIps());
    }

    public function testCrawlerAdmissionMaybeEnqueue_AlreadyVerified_NotRequeued(): void
    {
        crawlerAdmissionWriteJson(getCrawlerVerifiedIpAllowlistPath(), ['207.46.13.77' => time() + 3600]);
        $this->assertFalse(crawlerAdmissionMaybeEnqueue('207.46.13.77', 'bingbot/2.0'));
        $this->assertSame([], crawlerAdmissionPendingIps());
    }

    public function testCrawlerAdmissionMaybeEnqueue_MalformedIp_NotQueued(): void
    {
        $this->assertFalse(crawlerAdmissionMaybeEnqueue('not-an-ip', 'bingbot/2.0'));
    }
}
