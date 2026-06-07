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

    /**
     * @return array{success: bool, output: string}
     */
    private function runProcessAirportNotamSubprocess(string $cacheFile): array
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
require getenv('NOTAM_TEST_ROOT') . '/scripts/fetch-notam.php';
$ok = processAirportNotam('kspb', 'test-invocation', false);
echo json_encode(['success' => $ok], JSON_THROW_ON_ERROR);

PHP;
        file_put_contents($tmp, $script);

        $env = [
            'NOTAM_TEST_ROOT' => $root,
            'NOTAM_TEST_CONFIG_PATH' => $root . '/tests/Fixtures/airports.json.test',
            'NOTAM_TEST_CACHE_DIR' => dirname($cacheFile),
        ];
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
