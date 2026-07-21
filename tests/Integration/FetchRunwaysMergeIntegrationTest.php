<?php

/**
 * End-to-end validation that fetch-runways.php runs merge when policy says it should,
 * including after acquiring the exclusive fetch lock (production lock-order bug).
 */

use PHPUnit\Framework\TestCase;

class FetchRunwaysMergeIntegrationTest extends TestCase
{
    private string $cacheDir = '';

    private string $logDir = '';

    protected function setUp(): void
    {
        parent::setUp();

        if (!function_exists('proc_open')) {
            $this->markTestSkipped('proc_open not available');
        }

        $suffix = bin2hex(random_bytes(8));
        $this->cacheDir = sys_get_temp_dir() . '/aviationwx_fetch_runways_' . $suffix;
        $this->logDir = sys_get_temp_dir() . '/aviationwx_fetch_runways_logs_' . $suffix;
        @mkdir($this->cacheDir, 0755, true);
        @mkdir($this->logDir, 0755, true);
        @touch($this->logDir . '/app.log');
        @touch($this->logDir . '/user.log');
    }

    protected function tearDown(): void
    {
        $this->removeDir($this->cacheDir);
        $this->removeDir($this->logDir);
        parent::tearDown();
    }

    public function testFetchRunwaysCli_PolicyReadyWithLockPresent_StartsMerge(): void
    {
        $root = dirname(__DIR__, 2);
        $fixtureConfig = $root . '/tests/Fixtures/airports.json.test';
        $this->seedProductionLikeCacheState($root);

        $beforeMerge = time();
        $result = $this->runFetchRunwaysScript($root, $fixtureConfig);

        $this->assertSame(0, $result['exit'], $result['output']);

        $log = (string) file_get_contents($this->logDir . '/app.log');
        $this->assertStringContainsString('runways fetch: starting merge', $log);
        $this->assertStringContainsString('runways fetch: complete', $log);
        $this->assertStringNotContainsString('runways fetch: skipped after lock', $log);
        $this->assertStringNotContainsString('not due or waiting for OurAirports bulk CSVs', $log);

        $cachePath = $this->cacheDir . '/runways/runways_data.json';
        $this->assertFileExists($cachePath);
        $decoded = json_decode((string) file_get_contents($cachePath), true);
        $this->assertIsArray($decoded);
        $this->assertGreaterThanOrEqual($beforeMerge, (int) ($decoded['fetched_at'] ?? 0));
        $this->assertNotEmpty($decoded['airports'] ?? []);
    }

    /**
     * @return array{exit: int, output: string}
     */
    private function runFetchRunwaysScript(string $root, string $configPath): array
    {
        $prepend = sys_get_temp_dir() . '/aviationwx_fetch_runways_prepend_' . bin2hex(random_bytes(8)) . '.php';
        // APP_ENV=development avoids loadRunwaysCacheDataFromDisk() using the test fixture path.
        $prependBody = '<?php' . PHP_EOL
            . 'define(\'CACHE_BASE_DIR\', ' . var_export($this->cacheDir, true) . ');' . PHP_EOL
            . 'define(\'AVIATIONWX_LOG_DIR\', ' . var_export($this->logDir, true) . ');' . PHP_EOL
            . 'putenv(\'APP_ENV=development\');' . PHP_EOL
            . 'putenv(\'CONFIG_PATH=\' . ' . var_export($configPath, true) . ');' . PHP_EOL;
        file_put_contents($prepend, $prependBody);

        $script = $root . '/scripts/fetch-runways.php';
        $cmd = escapeshellarg(PHP_BINARY)
            . ' -d auto_prepend_file=' . escapeshellarg($prepend)
            . ' ' . escapeshellarg($script)
            . ' 2>&1';
        $descriptor = [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
        $env = [
            'APP_ENV' => 'development',
            'CONFIG_PATH' => $configPath,
        ];
        $proc = proc_open($cmd, $descriptor, $pipes, $root, $env);
        $this->assertIsResource($proc);
        fclose($pipes[0]);
        $out = stream_get_contents($pipes[1]);
        $err = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $exitCode = proc_close($proc);
        @unlink($prepend);

        return ['exit' => $exitCode, 'output' => $out . $err];
    }

    private function seedProductionLikeCacheState(string $root): void
    {
        $ourairportsDir = $this->cacheDir . '/ourairports';
        $runwaysDir = $this->cacheDir . '/runways';
        @mkdir($ourairportsDir, 0755, true);
        @mkdir($runwaysDir, 0755, true);

        copy(
            $root . '/tests/Fixtures/ourairports/airports.csv',
            $ourairportsDir . '/airports.csv'
        );
        copy(
            $root . '/tests/Fixtures/ourairports/runways.csv',
            $ourairportsDir . '/runways.csv'
        );

        $now = time();
        touch($ourairportsDir . '/airports.csv', $now);
        touch($ourairportsDir . '/runways.csv', $now);

        file_put_contents(
            $runwaysDir . '/runways_data.json',
            json_encode(['fetched_at' => $now - 86400, 'airports' => []], JSON_UNESCAPED_SLASHES),
            LOCK_EX
        );
        touch($runwaysDir . '/runways_data.json', $now - 86400);

        $meta = [
            'files' => [
                'airports' => [
                    'last_probe_result' => 'unchanged',
                    'last_fetch_at' => $now,
                ],
                'runways' => [
                    'last_probe_result' => 'unchanged',
                    'last_fetch_at' => $now,
                ],
            ],
            'faa_ngda' => (object) [],
        ];
        file_put_contents(
            $ourairportsDir . '/ourairports_meta.json',
            json_encode($meta, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES),
            LOCK_EX
        );

        file_put_contents(
            $runwaysDir . '/faa_ngda_runways.csv',
            "ARPT_ID,RWY_ID,LAT1_DECIMAL,LONG1_DECIMAL,LAT2_DECIMAL,LONG2_DECIMAL\n"
            . "PDX,10/28,45.589722,-122.605000,45.588722,-122.590000\n",
            LOCK_EX
        );
        touch($runwaysDir . '/faa_ngda_runways.csv', $now - 7200);

        file_put_contents($runwaysDir . '/.fetch.lock', '', LOCK_EX);
    }

    private function removeDir(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }

        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($iterator as $item) {
            if ($item->isDir()) {
                @rmdir($item->getPathname());
            } else {
                @unlink($item->getPathname());
            }
        }
        @rmdir($dir);
    }
}
