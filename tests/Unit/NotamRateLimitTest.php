<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Cross-process NMS NOTAM API rate limiting (1 req/s).
 */
final class NotamRateLimitTest extends TestCase
{
    private ?string $testRoot = null;

    protected function setUp(): void
    {
        parent::setUp();
        $this->testRoot = sys_get_temp_dir() . '/notam-rate-limit-test-' . bin2hex(random_bytes(4));
        mkdir($this->testRoot, 0755, true);
        $GLOBALS['upstreamRateLimitTestRoot'] = $this->testRoot;
        putenv('APP_ENV=testing');
        $_ENV['APP_ENV'] = 'testing';
    }

    protected function tearDown(): void
    {
        unset(
            $GLOBALS['upstreamRateLimitTestRoot'],
            $GLOBALS['notamRateLimitTestForceEnforcement'],
            $GLOBALS['notamRateLimitTestSkipSleep'],
            $GLOBALS['upstreamRateLimitTestNow'],
            $GLOBALS['notamRateLimitTestPollMicroseconds'],
            $GLOBALS['notamRateLimitTestClientId'],
            $GLOBALS['notamRateLimitTestClientSecret'],
            $GLOBALS['notamRateLimitTestBaseUrl']
        );
        if ($this->testRoot !== null && is_dir($this->testRoot)) {
            $files = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($this->testRoot, FilesystemIterator::SKIP_DOTS),
                RecursiveIteratorIterator::CHILD_FIRST
            );
            foreach ($files as $file) {
                if ($file->isDir()) {
                    rmdir($file->getPathname());
                } else {
                    unlink($file->getPathname());
                }
            }
            rmdir($this->testRoot);
        }
        parent::tearDown();
    }

    public function testNotamRateLimitFingerprint_SameCredential_ReturnsStableHash(): void
    {
        require_once dirname(__DIR__, 2) . '/lib/notam/rate-limit.php';

        $GLOBALS['notamRateLimitTestClientId'] = 'client-a';
        $GLOBALS['notamRateLimitTestClientSecret'] = 'secret-a';
        $GLOBALS['notamRateLimitTestBaseUrl'] = 'https://example.test/nms';

        $this->assertSame(
            notamRateLimitFingerprint(),
            notamRateLimitFingerprint()
        );
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', notamRateLimitFingerprint());
    }

    public function testNotamRateLimitAcquire_SecondRequestWithinOneSecondWaits(): void
    {
        require_once dirname(__DIR__, 2) . '/lib/notam/rate-limit.php';

        notamRateLimitTestForceEnforcement();
        $GLOBALS['notamRateLimitTestSkipSleep'] = true;
        $GLOBALS['notamRateLimitTestPollMicroseconds'] = 50_000;
        $GLOBALS['notamRateLimitTestClientId'] = 'client-a';
        $GLOBALS['notamRateLimitTestClientSecret'] = 'secret-a';
        $GLOBALS['notamRateLimitTestBaseUrl'] = 'https://example.test/nms';

        $t0 = 1_700_000_000.0;
        $GLOBALS['upstreamRateLimitTestNow'] = $t0;
        notamRateLimitAcquire();

        $GLOBALS['upstreamRateLimitTestNow'] = $t0 + 0.2;
        notamRateLimitAcquire();

        $this->assertGreaterThanOrEqual($t0 + 1.0, (float) $GLOBALS['upstreamRateLimitTestNow']);
    }

    public function testRateLimitWait_UsesGlobalAcquireNotOnlyLocalClock(): void
    {
        require_once dirname(__DIR__, 2) . '/lib/notam/fetcher.php';

        notamRateLimitTestForceEnforcement();
        $GLOBALS['notamRateLimitTestSkipSleep'] = true;
        $GLOBALS['notamRateLimitTestPollMicroseconds'] = 100_000;
        $GLOBALS['notamRateLimitTestClientId'] = 'client-b';
        $GLOBALS['notamRateLimitTestClientSecret'] = 'secret-b';
        $GLOBALS['notamRateLimitTestBaseUrl'] = 'https://example.test/nms';

        $t0 = 1_700_000_100.0;
        $GLOBALS['upstreamRateLimitTestNow'] = $t0;
        $lastRequestTime = 0.0;
        rateLimitWait($lastRequestTime);

        $GLOBALS['upstreamRateLimitTestNow'] = $t0 + 0.1;
        rateLimitWait($lastRequestTime);

        $this->assertGreaterThanOrEqual($t0 + 1.0, (float) $GLOBALS['upstreamRateLimitTestNow']);
    }

    public function testNotamRateLimitAcquire_MissingCredentials_SkipsBucket(): void
    {
        require_once dirname(__DIR__, 2) . '/lib/notam/rate-limit.php';

        notamRateLimitTestForceEnforcement();
        $GLOBALS['notamRateLimitTestClientId'] = '';
        $GLOBALS['notamRateLimitTestClientSecret'] = '';
        $GLOBALS['notamRateLimitTestBaseUrl'] = 'https://example.test/nms';

        notamRateLimitAcquire();

        $this->assertSame(0, count(glob($this->testRoot . '/*/*.json') ?: []));
    }

    public function testNotamRateLimitAcquire_TestModeBypassWithoutForce(): void
    {
        require_once dirname(__DIR__, 2) . '/lib/notam/rate-limit.php';

        $GLOBALS['notamRateLimitTestClientId'] = 'client-c';
        $GLOBALS['notamRateLimitTestClientSecret'] = 'secret-c';
        $GLOBALS['notamRateLimitTestBaseUrl'] = 'https://example.test/nms';

        $t0 = 1_700_000_200.0;
        $GLOBALS['upstreamRateLimitTestNow'] = $t0;
        notamRateLimitAcquire();
        notamRateLimitAcquire();

        $this->assertSame($t0, (float) $GLOBALS['upstreamRateLimitTestNow']);
        $this->assertSame(0, count(glob($this->testRoot . '/*/*.json') ?: []));
    }
}
