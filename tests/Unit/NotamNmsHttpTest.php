<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * NMS HTTP execution with global backoff, header capture, and one 429 retry.
 *
 * @covers ::notamExecuteNmsQuery
 * @covers ::notamPerformNmsHttpGet
 * @covers ::notamCompute429RetryWaitSeconds
 * @covers ::notamLogInvalidNmsPayload
 */
final class NotamNmsHttpTest extends TestCase
{
    private ?string $testRoot = null;

    protected function setUp(): void
    {
        parent::setUp();
        $this->testRoot = sys_get_temp_dir() . '/notam-http-' . bin2hex(random_bytes(4));
        mkdir($this->testRoot, 0755, true);
        $GLOBALS['upstreamRateLimitTestRoot'] = $this->testRoot;
        $GLOBALS['notamRateLimitTestClientId'] = 'client-http';
        $GLOBALS['notamRateLimitTestClientSecret'] = 'secret-http';
        $GLOBALS['notamRateLimitTestBaseUrl'] = 'https://example.test/nms';
        $GLOBALS['notamTestSkipSleep'] = true;
        $GLOBALS['notamTestSleepAccumulatedSeconds'] = 0;
        $GLOBALS['notamTestBearerToken'] = 'test-token';

        require_once dirname(__DIR__, 2) . '/lib/notam/http.php';
        require_once dirname(__DIR__, 2) . '/lib/notam/circuit-breaker.php';
        require_once dirname(__DIR__, 2) . '/lib/notam/fetcher.php';

        notamRateLimitTestForceEnforcement();
        clearNotamGlobalBackoff();
    }

    protected function tearDown(): void
    {
        clearNotamGlobalBackoff();
        unset(
            $GLOBALS['upstreamRateLimitTestRoot'],
            $GLOBALS['notamRateLimitTestClientId'],
            $GLOBALS['notamRateLimitTestClientSecret'],
            $GLOBALS['notamRateLimitTestBaseUrl'],
            $GLOBALS['notamRateLimitTestForceEnforcement'],
            $GLOBALS['notamTestSkipSleep'],
            $GLOBALS['notamTestSleepAccumulatedSeconds'],
            $GLOBALS['notamTestNmsHttpHandler'],
            $GLOBALS['notamTestBearerToken']
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

    public function testExecuteNmsQuery_DeferredWhenGlobalBackoffActive(): void
    {
        recordNotamGlobalRateLimitFailure(429, null, time());

        $lastRequestTime = 0.0;
        $result = notamExecuteNmsQuery(
            'https://example.test/nmsapi/v1/notams?location=OR81',
            'location',
            $lastRequestTime,
            'test-token',
        );

        $this->assertFalse($result['ok']);
        $this->assertTrue($result['deferred']);
        $this->assertNull($result['http_code']);
    }

    public function testExecuteNmsQuery_429Then200OnRetrySucceeds(): void
    {
        $calls = 0;
        $GLOBALS['notamTestNmsHttpHandler'] = static function (string $url, string $bearerToken) use (&$calls): array {
            $calls++;

            return $calls === 1
                ? ['body' => 'busy', 'http_code' => 429, 'headers' => ['retry-after' => '2'], 'error' => '']
                : ['body' => '{"status":"Success","data":{}}', 'http_code' => 200, 'headers' => [], 'error' => ''];
        };

        $lastRequestTime = 0.0;
        $result = notamExecuteNmsQuery(
            'https://example.test/nmsapi/v1/notams?location=OR81',
            'location',
            $lastRequestTime,
            'test-token',
        );

        $this->assertTrue($result['ok']);
        $this->assertSame(200, $result['http_code']);
        $this->assertSame(2, $calls);
        $this->assertSame(2, $GLOBALS['notamTestSleepAccumulatedSeconds']);
        $this->assertFalse(checkNotamGlobalBackoff()['skip']);
    }

    public function testExecuteNmsQuery_Double429RecordsGlobalBackoff(): void
    {
        $GLOBALS['notamTestNmsHttpHandler'] = static function (string $url, string $bearerToken): array {
            return ['body' => 'busy', 'http_code' => 429, 'headers' => ['retry-after' => '1'], 'error' => ''];
        };

        $lastRequestTime = 0.0;
        $result = notamExecuteNmsQuery(
            'https://example.test/nmsapi/v1/notams?location=OR81',
            'location',
            $lastRequestTime,
            'test-token',
        );

        $this->assertFalse($result['ok']);
        $this->assertSame(429, $result['http_code']);
        $this->assertTrue(checkNotamGlobalBackoff()['skip']);
    }

    public function testExecuteNmsQuery_SuccessClearsGlobalBackoff(): void
    {
        recordNotamGlobalRateLimitFailure(429, ['retry-after' => '1'], time() - 120);

        $GLOBALS['notamTestNmsHttpHandler'] = static function (string $url, string $bearerToken): array {
            return [
                'body' => '{"status":"Success","data":{"aixm":[]}}',
                'http_code' => 200,
                'headers' => [],
                'error' => '',
            ];
        };

        $lastRequestTime = 0.0;
        $result = notamExecuteNmsQuery(
            'https://example.test/nmsapi/v1/notams?location=OR81',
            'location',
            $lastRequestTime,
            'test-token',
        );

        $this->assertTrue($result['ok']);
        $this->assertFalse(checkNotamGlobalBackoff()['skip']);
    }

    public function testCompute429RetryWaitSeconds_ClampsToConfiguredMax(): void
    {
        $wait = notamCompute429RetryWaitSeconds(['retry-after' => '120']);

        $this->assertSame(NOTAM_429_RETRY_MAX_WAIT_SECONDS, $wait);
    }

    public function testQueryNotamsByLocation_UsesEmptyDataObjectAsSuccess(): void
    {
        require_once dirname(__DIR__, 2) . '/lib/notam/fetcher.php';

        $GLOBALS['notamTestBearerToken'] = 'token-test';
        $GLOBALS['notamTestNmsHttpHandler'] = static function (string $url, string $bearerToken): array {
            return [
                'body' => '{"status":"Success","data":{}}',
                'http_code' => 200,
                'headers' => [],
                'error' => '',
            ];
        };

        $lastRequestTime = 0.0;
        $querySucceeded = false;
        $rows = queryNotamsByLocation('OR81', $lastRequestTime, [], $querySucceeded);

        $this->assertSame([], $rows);
        $this->assertTrue($querySucceeded);
    }

    public function testQueryNotamsByLocation_DeferredSetsQuerySucceededNull(): void
    {
        recordNotamGlobalRateLimitFailure(429, null, time());

        $lastRequestTime = 0.0;
        $querySucceeded = false;
        $rows = queryNotamsByLocation('OR81', $lastRequestTime, [], $querySucceeded);

        $this->assertSame([], $rows);
        $this->assertNull($querySucceeded);
    }

    public function testFetchNotamsForAirport_AllDeferredWhenGlobalBackoffBlocksQueries(): void
    {
        recordNotamGlobalRateLimitFailure(429, null, time());

        $config = loadConfig();
        $this->assertIsArray($config);
        $airport = $config['airports']['kspb'] ?? null;
        $this->assertIsArray($airport);

        $fetchSucceeded = true;
        $fetchAllDeferred = false;
        fetchNotamsForAirport('kspb', $airport, $fetchSucceeded, $fetchAllDeferred);

        $this->assertFalse($fetchSucceeded);
        $this->assertTrue($fetchAllDeferred);
    }

    public function testQueryNotamsByLocation_FailsWhenHttp200HasNonSuccessPayload(): void
    {
        clearNotamGlobalBackoff();

        $GLOBALS['notamTestNmsHttpHandler'] = static function (string $url, string $bearerToken): array {
            return [
                'body' => '{"status":"Error","data":{}}',
                'http_code' => 200,
                'headers' => [],
                'error' => '',
            ];
        };

        $lastRequestTime = 0.0;
        $querySucceeded = true;
        $rows = queryNotamsByLocation('OR81', $lastRequestTime, [], $querySucceeded);

        $this->assertSame([], $rows);
        $this->assertFalse($querySucceeded);
    }
}
