<?php
/**
 * Unit tests: crawler admission refresh worker (off-path Google fetch + Bing/Yandex queue drain).
 */

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

if (!defined('CACHE_CRAWLER_ADMISSION_DIR')) {
    define('CACHE_CRAWLER_ADMISSION_DIR', sys_get_temp_dir() . '/crawler_admission_test_' . getmypid());
}

require_once __DIR__ . '/../../lib/crawler-admission.php';

final class CrawlerAdmissionRefreshTest extends TestCase
{
    protected function setUp(): void
    {
        foreach ([
            getCrawlerGoogleAllowlistPath(),
            getCrawlerVerifiedIpAllowlistPath(),
            getCrawlerPendingIpsPath(),
        ] as $p) {
            if (file_exists($p)) {
                @unlink($p);
            }
            if (file_exists($p . '.lock')) {
                @unlink($p . '.lock');
            }
        }
        if (function_exists('apcu_delete')) {
            @apcu_delete('crawler_admission_google');
            @apcu_delete('crawler_admission_verified');
        }
    }

    protected function tearDown(): void
    {
        unset(
            $GLOBALS['crawlerAdmissionDnsResolver'],
            $GLOBALS['crawlerAdmissionTestHttpGet'],
            $GLOBALS['crawlerAdmissionTestFixture']
        );
        foreach ([
            getCrawlerGoogleAllowlistPath(),
            getCrawlerVerifiedIpAllowlistPath(),
            getCrawlerPendingIpsPath(),
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

    public function testCrawlerAdmissionVerifyDnsChain_BingSuffixAndForwardMatch_Accepts(): void
    {
        $GLOBALS['crawlerAdmissionDnsResolver'] = function (string $q, bool $forward): ?string {
            return $forward ? '207.46.13.84' : 'msnbot-207-46-13-84.search.msn.com';
        };
        $this->assertTrue(crawlerAdmissionVerifyDnsChain('207.46.13.84', ['.search.msn.com']));
    }

    public function testCrawlerAdmissionVerifyDnsChain_WrongSuffix_Rejects(): void
    {
        $GLOBALS['crawlerAdmissionDnsResolver'] = function (string $q, bool $forward): ?string {
            return $forward ? '207.46.13.84' : 'admin.example.org';
        };
        $this->assertFalse(crawlerAdmissionVerifyDnsChain('207.46.13.84', ['.search.msn.com']));
    }

    public function testCrawlerAdmissionVerifyDnsChain_ForwardMismatch_Rejects(): void
    {
        $GLOBALS['crawlerAdmissionDnsResolver'] = function (string $q, bool $forward): ?string {
            return $forward ? '9.9.9.9' : 'msnbot-x.search.msn.com';
        };
        $this->assertFalse(crawlerAdmissionVerifyDnsChain('207.46.13.84', ['.search.msn.com']));
    }

    public function testCrawlerAdmissionVerifyDnsChain_V6ExpandedForward_Accepts(): void
    {
        $GLOBALS['crawlerAdmissionDnsResolver'] = function (string $q, bool $forward): ?string {
            return $forward ? '2001:4860:4801:0000:0000:0000:0000:0010' : 'msnbot-v6.search.msn.com';
        };
        // Compressed request address, expanded forward record: packed compare must accept.
        $this->assertTrue(crawlerAdmissionVerifyDnsChain('2001:4860:4801::10', ['.search.msn.com']));
    }

    public function testCrawlerAdmissionVerifyDnsChain_YandexSuffix_Accepts(): void
    {
        $GLOBALS['crawlerAdmissionDnsResolver'] = function (string $q, bool $forward): ?string {
            return $forward ? '77.88.8.1' : 'scan-77-88-8-1.yandex.net';
        };
        $this->assertTrue(crawlerAdmissionVerifyDnsChain('77.88.8.1', ['.yandex.ru', '.yandex.net', '.yandex.com']));
    }

    public function testCrawlerAdmissionEnqueueAndDrain_PublishesVerifiedIp(): void
    {
        $this->assertTrue(crawlerAdmissionEnqueuePendingIp('207.46.13.84'));
        $this->assertSame(['207.46.13.84'], array_keys(crawlerAdmissionPendingIps()));

        $GLOBALS['crawlerAdmissionDnsResolver'] = function (string $q, bool $forward): ?string {
            return $forward ? '207.46.13.84' : 'msnbot-207-46-13-84.search.msn.com';
        };
        $this->assertTrue(crawlerAdmissionDrainPendingQueue(time()));
        $verified = crawlerAdmissionVerifiedIps();
        $this->assertArrayHasKey('207.46.13.84', $verified);
        $this->assertSame([], crawlerAdmissionPendingIps(), 'queue must clear after drain');
    }

    public function testCrawlerAdmissionDrain_UnverifiedIp_NotPublishedAndQueueCleared(): void
    {
        crawlerAdmissionWriteJson(getCrawlerPendingIpsPath(), ['198.51.100.9' => time()]);
        $GLOBALS['crawlerAdmissionDnsResolver'] = function (string $q, bool $forward): ?string {
            return $forward ? '198.51.100.9' : 'admin.example.org';
        };
        crawlerAdmissionDrainPendingQueue(time());
        $this->assertSame([], crawlerAdmissionVerifiedIps());
        $this->assertSame([], crawlerAdmissionPendingIps());
    }

    public function testCrawlerAdmissionRefresh_FetchesGoogleListAndDrainsQueue(): void
    {
        $GLOBALS['crawlerAdmissionTestFixture'] = file_get_contents(__DIR__ . '/../Fixtures/google-crawlers-sample.json');
        $GLOBALS['crawlerAdmissionTestHttpGet'] = function (string $url): array {
            return ['body' => $GLOBALS['crawlerAdmissionTestFixture'], 'http_code' => 200];
        };
        crawlerAdmissionWriteJson(getCrawlerPendingIpsPath(), ['207.46.13.84' => time()]);
        $GLOBALS['crawlerAdmissionDnsResolver'] = function (string $q, bool $forward): ?string {
            return $forward ? '207.46.13.84' : 'msnbot-207-46-13-84.search.msn.com';
        };

        $summary = crawlerAdmissionRefresh();
        $this->assertSame('fetched', $summary['google_status']);
        $this->assertSame(2, $summary['google_prefixes']);
        $this->assertSame(1, $summary['queue_drained']);
        $this->assertSame(1, $summary['verified_ips']);
        $this->assertTrue($summary['drain_published']);
        $this->assertFileExists(getCrawlerGoogleAllowlistPath());
    }

    public function testCrawlerAdmissionRefresh_FetchFailure_RetainsPriorGoogleFile(): void
    {
        crawlerAdmissionWriteJson(getCrawlerGoogleAllowlistPath(), ['prefixes' => [['ipv4Prefix' => '66.249.64.0/19']]]);
        $GLOBALS['crawlerAdmissionTestHttpGet'] = function (string $url): array {
            return ['body' => false, 'http_code' => 0];
        };
        $summary = crawlerAdmissionRefresh();
        $this->assertSame('fetch_failed', $summary['google_status']);
        $this->assertSame(1, count(crawlerAdmissionGooglePrefixes()));
    }

    public function testCrawlerAdmissionRefresh_MalformedGoogleBody_ReportedMalformed(): void
    {
        crawlerAdmissionWriteJson(getCrawlerGoogleAllowlistPath(), ['prefixes' => [['ipv4Prefix' => '66.249.64.0/19']]]);
        $GLOBALS['crawlerAdmissionTestHttpGet'] = function (string $url): array {
            return ['body' => '{"creationTime":"now","prefixes":[]}', 'http_code' => 200];
        };
        $summary = crawlerAdmissionRefresh();
        $this->assertSame('malformed', $summary['google_status']);
        $this->assertSame(1, count(crawlerAdmissionGooglePrefixes()));
    }
}
