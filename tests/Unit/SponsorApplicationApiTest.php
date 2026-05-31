<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class SponsorApplicationApiTest extends TestCase
{
    private string $exchangeRoot;

    protected function setUp(): void
    {
        $this->exchangeRoot = sys_get_temp_dir() . '/aviationwx-sponsor-api-' . bin2hex(random_bytes(4));
        mkdir($this->exchangeRoot . '/in/sponsor-applications', 0750, true);
    }

    protected function tearDown(): void
    {
        $this->removeTree($this->exchangeRoot);
    }

    public function testEndpoint_Returns404WhenContributionsDisabled(): void
    {
        $result = $this->runEndpoint([
            'REQUEST_METHOD' => 'POST',
            'body' => $this->validBody(),
        ], dirname(__DIR__, 2) . '/tests/Fixtures/airports.json.test');

        self::assertSame(404, $result['status']);
    }

    public function testEndpoint_GetReturns405(): void
    {
        $result = $this->runEndpoint([
            'REQUEST_METHOD' => 'GET',
        ], $this->contributionsConfigPath());

        self::assertSame(405, $result['status']);
        self::assertStringContainsString('Method not allowed', $result['body']);
    }

    public function testEndpoint_GetReturnsAllowHeaderWhenHttpAvailable(): void
    {
        if (!function_exists('curl_init')) {
            self::markTestSkipped('cURL not available');
        }

        $baseUrl = getenv('TEST_API_URL') ?: getenv('TEST_BASE_URL') ?: 'http://localhost:8080';
        $url = rtrim($baseUrl, '/') . '/api/sponsor-application';
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_CUSTOMREQUEST => 'GET',
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HEADER => true,
            CURLOPT_TIMEOUT => 10,
        ]);
        $raw = curl_exec($ch);
        if ($raw === false) {
            self::markTestSkipped('Sponsor application endpoint not reachable');
        }

        self::assertIsString($raw);
        self::assertStringContainsString('HTTP/1.1 405', $raw);
        self::assertMatchesRegularExpression('/\r\nAllow:\s*POST\r\n/i', $raw);
    }

    public function testEndpoint_HoneypotReturns400(): void
    {
        $body = $this->validBody();
        $body['website'] = 'https://spam.test';
        $result = $this->runEndpoint([
            'REQUEST_METHOD' => 'POST',
            'body' => $body,
        ], $this->contributionsConfigPath());

        self::assertSame(400, $result['status']);
    }

    public function testEndpoint_ValidationFailureReturns400(): void
    {
        $body = $this->validBody();
        unset($body['org_name']);
        $result = $this->runEndpoint([
            'REQUEST_METHOD' => 'POST',
            'body' => $body,
        ], $this->contributionsConfigPath());

        self::assertSame(400, $result['status']);
        self::assertStringContainsString('Validation failed', $result['body']);
    }

    public function testEndpoint_ValidPostReturns202AndWritesSpool(): void
    {
        $result = $this->runEndpoint([
            'REQUEST_METHOD' => 'POST',
            'body' => $this->validBody(),
        ], $this->contributionsConfigPath());

        self::assertSame(202, $result['status']);
        self::assertStringContainsString('"ok":true', $result['body']);
        self::assertNotEmpty(glob($this->exchangeRoot . '/in/sponsor-applications/*.json'));
    }

    /**
     * @param array{REQUEST_METHOD?: string, body?: array<string, mixed>} $options
     *
     * @return array{status: int, body: string, headers: array<string, string>}
     */
    private function runEndpoint(array $options, string $configPath): array
    {
        return $this->runEndpointViaStdin($options, $configPath, dirname(__DIR__, 2));
    }

    /**
     * @param array{REQUEST_METHOD?: string, body?: array<string, mixed>} $options
     *
     * @return array{status: int, body: string, headers: array<string, string>}
     */
    private function runEndpointViaStdin(array $options, string $configPath, string $root): array
    {
        $method = $options['REQUEST_METHOD'] ?? 'POST';
        $payload = isset($options['body']) ? json_encode($options['body'], JSON_THROW_ON_ERROR) : '';
        $tmp = sys_get_temp_dir() . '/aviationwx_sponsor_api_' . bin2hex(random_bytes(8)) . '.php';
        $script = <<<'PHP'
<?php
putenv('APP_ENV=testing');
putenv('CONFIG_PATH=' . getenv('SPONSOR_API_CONFIG_PATH'));
putenv('EXCHANGE_PATH=' . getenv('SPONSOR_API_EXCHANGE_PATH'));
$_ENV['APP_ENV'] = 'testing';
$_ENV['CONFIG_PATH'] = getenv('SPONSOR_API_CONFIG_PATH');
if (!defined('AVIATIONWX_LOG_DIR')) {
    define('AVIATIONWX_LOG_DIR', sys_get_temp_dir() . '/aviationwx_sponsor_api_logs');
}
@mkdir(AVIATIONWX_LOG_DIR, 0755, true);
@touch(AVIATIONWX_LOG_DIR . '/app.log');
define('AVIATIONWX_SPONSOR_APPLICATION_TEST_MODE', true);
$_SERVER['REQUEST_METHOD'] = getenv('SPONSOR_API_METHOD');
ob_start();
try {
    require getenv('SPONSOR_API_ROOT') . '/api/sponsor-application.php';
} catch (SponsorApplicationHandlerStopped) {
}
$out = ob_get_clean();
$headers = [];
foreach (headers_list() as $header) {
    $parts = explode(':', $header, 2);
    if (count($parts) === 2) {
        $headers[strtolower(trim($parts[0]))] = trim($parts[1]);
    }
}
echo json_encode([
    'status' => http_response_code(),
    'headers' => $headers,
    'body' => $out,
], JSON_THROW_ON_ERROR);

PHP;
        file_put_contents($tmp, $script);

        $env = [
            'SPONSOR_API_ROOT' => $root,
            'SPONSOR_API_CONFIG_PATH' => $configPath,
            'SPONSOR_API_EXCHANGE_PATH' => $this->exchangeRoot,
            'SPONSOR_API_METHOD' => $method,
            'SPONSOR_APPLICATION_TEST_INPUT' => $payload,
        ];
        $descriptor = [1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
        $proc = proc_open([PHP_BINARY, $tmp], $descriptor, $pipes, null, $env);
        self::assertIsResource($proc);
        $output = stream_get_contents($pipes[1]) . stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        proc_close($proc);
        @unlink($tmp);

        $decoded = json_decode(trim($output), true);
        self::assertIsArray($decoded, 'subprocess output: ' . $output);

        return [
            'status' => (int) ($decoded['status'] ?? 0),
            'body' => (string) ($decoded['body'] ?? ''),
            'headers' => is_array($decoded['headers'] ?? null) ? $decoded['headers'] : [],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function validBody(): array
    {
        return [
            'airport_id' => 'kspb',
            'org_name' => 'Test FBO',
            'contact_name' => 'Jane Doe',
            'contact_email' => 'sponsor@example.com',
            'org_type' => 'on_airport_business',
        ];
    }

    private function contributionsConfigPath(): string
    {
        $fixture = dirname(__DIR__, 2) . '/tests/Fixtures/airports.json.test';
        $config = json_decode((string) file_get_contents($fixture), true, 512, JSON_THROW_ON_ERROR);
        $config['config']['contributions'] = ['enabled' => true];
        $path = sys_get_temp_dir() . '/aviationwx_sponsor_cfg_' . bin2hex(random_bytes(4)) . '.json';
        file_put_contents($path, json_encode($config, JSON_THROW_ON_ERROR));

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
