<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class NotamFetchReliabilityTest extends TestCase
{
    private string $cacheDir;

    protected function setUp(): void
    {
        $this->cacheDir = sys_get_temp_dir() . '/aviationwx-notam-fetch-' . bin2hex(random_bytes(4));
        mkdir($this->cacheDir, 0755, true);
        putenv('APP_ENV=testing');
        putenv('CONFIG_PATH=' . dirname(__DIR__, 2) . '/tests/Fixtures/airports.json.test');
    }

    protected function tearDown(): void
    {
        $this->removeTree($this->cacheDir);
    }

    public function testFetchNotamsForAirport_SetsFetchSucceededFalseWhenCredentialsMissing(): void
    {
        require_once dirname(__DIR__, 2) . '/lib/notam/fetcher.php';

        $config = loadConfig();
        self::assertIsArray($config);
        $airport = $config['airports']['kspb'] ?? null;
        self::assertIsArray($airport);

        $fetchSucceeded = true;
        $notams = fetchNotamsForAirport('kspb', $airport, $fetchSucceeded);

        self::assertFalse($fetchSucceeded);
        self::assertSame([], $notams);
    }

    public function testFetchNotamsForAirport_SetsFetchSucceededWhenOutParamStartsNull(): void
    {
        require_once dirname(__DIR__, 2) . '/lib/notam/fetcher.php';

        $config = loadConfig();
        self::assertIsArray($config);
        $airport = $config['airports']['kspb'] ?? null;
        self::assertIsArray($airport);

        $fetchSucceeded = null;
        fetchNotamsForAirport('kspb', $airport, $fetchSucceeded);

        self::assertFalse($fetchSucceeded);
    }

    public function testProcessAirportNotam_RecordsFetchAttemptWhenFetchFailsWithNoCache(): void
    {
        $cacheFile = $this->cacheDir . '/kspb.json';
        self::assertFileDoesNotExist($cacheFile);

        $result = $this->runProcessAirportNotamSubprocess($cacheFile);

        self::assertFalse($result['success']);
        self::assertFileDoesNotExist($cacheFile);
        self::assertFileExists(dirname($cacheFile) . '/kspb.fetch-attempt');
    }

    public function testProcessAirportNotam_PreservesExistingCacheWhenFetchFails(): void
    {
        $cacheFile = $this->cacheDir . '/kspb.json';
        $original = [
            'fetched_at' => 1700000000,
            'airport' => 'kspb',
            'notams' => [['id' => 'keep-me', 'text' => 'RWY CLSD']],
            'status' => 'success',
        ];
        file_put_contents($cacheFile, json_encode($original, JSON_THROW_ON_ERROR));

        $result = $this->runProcessAirportNotamSubprocess($cacheFile);

        self::assertFalse($result['success']);
        $after = json_decode((string) file_get_contents($cacheFile), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame('keep-me', $after['notams'][0]['id']);
        self::assertSame(1700000000, $after['fetched_at']);
        self::assertFileExists(dirname($cacheFile) . '/kspb.fetch-attempt');
    }

    public function testProcessAirportNotam_RecordsFetchAttemptOnWriteFailure(): void
    {
        $cacheFile = $this->cacheDir . '/kspb.json';
        $original = [
            'fetched_at' => 1700000000,
            'airport' => 'kspb',
            'notams' => [['id' => 'keep-me', 'text' => 'RWY CLSD']],
            'status' => 'success',
        ];
        file_put_contents($cacheFile, json_encode($original, JSON_THROW_ON_ERROR));

        $result = $this->runProcessAirportNotamSubprocess($cacheFile, [
            'fetch_succeeded' => true,
            'force_write_fail' => true,
        ]);

        self::assertFalse($result['success']);
        $after = json_decode((string) file_get_contents($cacheFile), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame('keep-me', $after['notams'][0]['id']);
        self::assertSame(1700000000, $after['fetched_at']);
        self::assertFileExists(dirname($cacheFile) . '/kspb.fetch-attempt');
    }

    public function testProcessAirportNotam_RecordsFetchAttemptOnException(): void
    {
        $cacheFile = $this->cacheDir . '/kspb.json';
        file_put_contents($cacheFile, json_encode([
            'fetched_at' => 1700000000,
            'airport' => 'kspb',
            'notams' => [['id' => 'keep-me']],
            'status' => 'success',
        ], JSON_THROW_ON_ERROR));

        $result = $this->runProcessAirportNotamSubprocess($cacheFile, [
            'fetch_throw' => 'simulated worker failure',
        ]);

        self::assertFalse($result['success']);
        self::assertFileExists(dirname($cacheFile) . '/kspb.fetch-attempt');
    }

    public function testProcessAirportNotam_SkipsFetchAttemptWhenAllQueriesDeferred(): void
    {
        $cacheFile = $this->cacheDir . '/kspb.json';
        $original = [
            'fetched_at' => 1700000000,
            'airport' => 'kspb',
            'notams' => [['id' => 'keep-me', 'text' => 'RWY CLSD']],
            'status' => 'success',
        ];
        file_put_contents($cacheFile, json_encode($original, JSON_THROW_ON_ERROR));

        $backoffRoot = sys_get_temp_dir() . '/aviationwx-notam-backoff-' . bin2hex(random_bytes(4));
        mkdir($backoffRoot, 0755, true);

        try {
            $result = $this->runProcessAirportNotamSubprocess($cacheFile, [
                'global_backoff' => true,
                'backoff_root' => $backoffRoot,
            ]);

            self::assertTrue($result['success']);
            self::assertFileDoesNotExist(dirname($cacheFile) . '/kspb.fetch-attempt');
            self::assertFileExists($backoffRoot . '/backoff.json');
            $after = json_decode((string) file_get_contents($cacheFile), true, 512, JSON_THROW_ON_ERROR);
            self::assertSame('keep-me', $after['notams'][0]['id']);
            self::assertSame(1700000000, $after['fetched_at']);
        } finally {
            $this->removeTree($backoffRoot);
        }
    }

    /**
     * @param array{fetch_succeeded?: bool, force_write_fail?: bool, fetch_throw?: string, global_backoff?: bool, backoff_root?: string} $options
     * @return array{success: bool, output: string}
     */
    private function runProcessAirportNotamSubprocess(string $cacheFile, array $options = []): array
    {
        $root = dirname(__DIR__, 2);
        $tmp = sys_get_temp_dir() . '/aviationwx_notam_proc_' . bin2hex(random_bytes(8)) . '.php';
        $script = <<<'PHP'
<?php
putenv('APP_ENV=testing');
putenv('CONFIG_PATH=' . getenv('NOTAM_TEST_CONFIG_PATH'));
$_ENV['APP_ENV'] = 'testing';
$_ENV['CONFIG_PATH'] = getenv('NOTAM_TEST_CONFIG_PATH');
if (!defined('AVIATIONWX_LOG_DIR')) {
    define('AVIATIONWX_LOG_DIR', sys_get_temp_dir() . '/aviationwx_notam_proc_logs');
}
@mkdir(AVIATIONWX_LOG_DIR, 0755, true);
@touch(AVIATIONWX_LOG_DIR . '/app.log');
define('AVIATIONWX_NOTAM_CACHE_DIR', getenv('NOTAM_TEST_CACHE_DIR'));
define('AVIATIONWX_FETCH_NOTAM_LOAD_ONLY', true);
if (getenv('NOTAM_TEST_GLOBAL_BACKOFF') === '1') {
    define('CACHE_BASE_DIR', getenv('NOTAM_TEST_BACKOFF_ROOT'));
}
if (($throw = getenv('NOTAM_TEST_FETCH_THROW')) !== false && $throw !== '') {
    define('AVIATIONWX_NOTAM_TEST_FETCH_THROW', $throw);
}
if (($succeeded = getenv('NOTAM_TEST_FETCH_SUCCEEDED')) !== false && $succeeded !== '') {
    define('AVIATIONWX_NOTAM_TEST_FETCH_SUCCEEDED', $succeeded === '1');
    define('AVIATIONWX_NOTAM_TEST_FETCH_NOTAMS', [['id' => 'test-write', 'text' => 'RWY CLSD']]);
}
if (getenv('NOTAM_TEST_FORCE_WRITE_FAIL') === '1') {
    define('AVIATIONWX_NOTAM_TEST_FORCE_WRITE_FAIL', true);
}
require getenv('NOTAM_TEST_ROOT') . '/scripts/fetch-notam.php';
if (getenv('NOTAM_TEST_GLOBAL_BACKOFF') === '1') {
    $GLOBALS['notamRateLimitTestClientId'] = 'client-http';
    $GLOBALS['notamRateLimitTestClientSecret'] = 'secret-http';
    $GLOBALS['notamRateLimitTestBaseUrl'] = 'https://example.test/nms';
    $GLOBALS['notamTestBearerToken'] = 'test-token';
    $GLOBALS['notamTestSkipSleep'] = true;
    notamRateLimitTestForceEnforcement();
    recordNotamGlobalRateLimitFailure(429, null, time());
}
$ok = processAirportNotam('kspb', 'test-invocation', false);
echo json_encode(['success' => $ok], JSON_THROW_ON_ERROR);

PHP;
        file_put_contents($tmp, $script);

        $env = [
            'NOTAM_TEST_ROOT' => $root,
            'NOTAM_TEST_CONFIG_PATH' => $root . '/tests/Fixtures/airports.json.test',
            'NOTAM_TEST_CACHE_DIR' => dirname($cacheFile),
        ];
        if (isset($options['fetch_throw'])) {
            $env['NOTAM_TEST_FETCH_THROW'] = (string) $options['fetch_throw'];
        }
        if (array_key_exists('fetch_succeeded', $options)) {
            $env['NOTAM_TEST_FETCH_SUCCEEDED'] = $options['fetch_succeeded'] ? '1' : '0';
        }
        if (!empty($options['force_write_fail'])) {
            $env['NOTAM_TEST_FORCE_WRITE_FAIL'] = '1';
        }
        if (!empty($options['global_backoff'])) {
            $env['NOTAM_TEST_GLOBAL_BACKOFF'] = '1';
            $env['NOTAM_TEST_BACKOFF_ROOT'] = (string) ($options['backoff_root'] ?? '');
        }
        $proc = proc_open([PHP_BINARY, $tmp], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, null, $env);
        self::assertIsResource($proc);
        $output = stream_get_contents($pipes[1]) . stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        proc_close($proc);
        @unlink($tmp);

        $decoded = json_decode(trim($output), true);
        self::assertIsArray($decoded, 'subprocess output: ' . $output);

        return [
            'success' => (bool) ($decoded['success'] ?? false),
            'output' => $output,
        ];
    }

    private function removeTree(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }

        foreach (scandir($dir) ?: [] as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            $path = $dir . '/' . $item;
            is_dir($path) ? $this->removeTree($path) : @unlink($path);
        }
        @rmdir($dir);
    }
}
