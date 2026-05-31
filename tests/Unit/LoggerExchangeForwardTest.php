<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class LoggerExchangeForwardTest extends TestCase
{
    private string $exchangeRoot;

    protected function setUp(): void
    {
        $this->exchangeRoot = sys_get_temp_dir() . '/aviationwx-logger-exchange-' . bin2hex(random_bytes(4));
        mkdir($this->exchangeRoot, 0750, true);
    }

    protected function tearDown(): void
    {
        putenv('EXCHANGE_PATH');
        $this->removeTree($this->exchangeRoot);
    }

    public function testForward_WorksAfterConfigLoadsFollowingLoggerFirst(): void
    {
        $configPath = $this->writeContributionsEnabledConfig();
        $result = $this->runLoggerSubprocess(<<<'PHP'
require $root . '/lib/logger.php';
aviationwx_log('info', 'before config', ['api_key' => 'secret-key'], 'app');
require $root . '/lib/config.php';
aviationwx_log('info', 'after config', ['api_key' => 'secret-key'], 'app');
PHP, $configPath);

        self::assertSame(0, $result['exit'], $result['output']);
        $day = gmdate('Y-m-d');
        $path = $this->exchangeRoot . '/in/structured-logs/' . $day . '.jsonl';
        self::assertFileExists($path);

        $lines = array_values(array_filter(array_map('trim', file($path, FILE_IGNORE_NEW_LINES) ?: [])));
        self::assertCount(1, $lines);
        $line = json_decode($lines[0], true, 512, JSON_THROW_ON_ERROR);
        self::assertSame('after config', $line['message']);
        self::assertSame('[redacted]', $line['context']['api_key']);
        self::assertStringNotContainsString('secret-key', $lines[0]);
    }

    public function testScrubExchangeLogContext_RedactsQueryParameterInUrl(): void
    {
        putenv('APP_ENV=testing');
        putenv('CONFIG_PATH=' . dirname(__DIR__, 2) . '/tests/Fixtures/airports.json.test');
        require_once dirname(__DIR__, 2) . '/lib/logger.php';

        $scrubbed = aviationwx_scrub_exchange_log_context([
            'endpoint' => '/api/weather.php?airport=kspb&api_key=supersecret',
        ]);

        self::assertStringContainsString('api_key=[redacted]', $scrubbed['endpoint']);
        self::assertStringNotContainsString('supersecret', $scrubbed['endpoint']);
    }

    /**
     * @return array{exit: int, output: string}
     */
    private function runLoggerSubprocess(string $body, string $configPath): array
    {
        $root = dirname(__DIR__, 2);
        $tmp = sys_get_temp_dir() . '/aviationwx_logger_sub_' . bin2hex(random_bytes(8)) . '.php';
        $script = <<<'PHP'
<?php
putenv('APP_ENV=testing');
putenv('CONFIG_PATH=' . getenv('LOGGER_TEST_CONFIG_PATH'));
putenv('EXCHANGE_PATH=' . getenv('LOGGER_TEST_EXCHANGE_PATH'));
$_ENV['APP_ENV'] = 'testing';
$_ENV['CONFIG_PATH'] = getenv('LOGGER_TEST_CONFIG_PATH');
if (!defined('AVIATIONWX_LOG_DIR')) {
    define('AVIATIONWX_LOG_DIR', sys_get_temp_dir() . '/aviationwx_logger_sub_logs');
}
@mkdir(AVIATIONWX_LOG_DIR, 0755, true);
@touch(AVIATIONWX_LOG_DIR . '/app.log');
$root = getenv('LOGGER_TEST_ROOT');

PHP;
        $script .= $body;
        file_put_contents($tmp, $script);

        $env = [
            'LOGGER_TEST_ROOT' => $root,
            'LOGGER_TEST_CONFIG_PATH' => $configPath,
            'LOGGER_TEST_EXCHANGE_PATH' => $this->exchangeRoot,
        ];
        $cmd = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($tmp) . ' 2>&1';
        $proc = proc_open($cmd, [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, null, $env);
        self::assertIsResource($proc);
        fclose($pipes[0]);
        $out = stream_get_contents($pipes[1]) . stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $exit = proc_close($proc);
        @unlink($tmp);

        return ['exit' => $exit, 'output' => $out];
    }

    private function writeContributionsEnabledConfig(): string
    {
        $fixture = dirname(__DIR__, 2) . '/tests/Fixtures/airports.json.test';
        $config = json_decode((string) file_get_contents($fixture), true, 512, JSON_THROW_ON_ERROR);
        $config['config']['contributions'] = ['enabled' => true];
        $path = sys_get_temp_dir() . '/aviationwx_contrib_config_' . bin2hex(random_bytes(4)) . '.json';
        file_put_contents($path, json_encode($config, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT));

        return $path;
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
