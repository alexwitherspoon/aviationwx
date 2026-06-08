<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class ExchangeSpoolTest extends TestCase
{
    private string $exchangeRoot;

    protected function setUp(): void
    {
        $this->exchangeRoot = sys_get_temp_dir() . '/aviationwx-oss-exchange-' . bin2hex(random_bytes(4));
        putenv('EXCHANGE_PATH=' . $this->exchangeRoot);
        putenv('APP_ENV=testing');
        putenv('CONFIG_PATH=' . dirname(__DIR__, 2) . '/tests/Fixtures/airports.json.test');

        require_once dirname(__DIR__, 2) . '/lib/config.php';
        require_once dirname(__DIR__, 2) . '/lib/exchange-spool.php';
    }

    protected function tearDown(): void
    {
        putenv('EXCHANGE_PATH');
        $this->removeTree($this->exchangeRoot);
    }

    public function testExchangeRoot_PreservesSingleSlashMount(): void
    {
        putenv('EXCHANGE_PATH=/');
        self::assertSame('/', aviationwx_exchange_root());
        putenv('EXCHANGE_PATH=' . $this->exchangeRoot);
    }

    public function testWriteSponsorApplication_CreatesAtomicJsonFile(): void
    {
        $applicationId = '770e8400-e29b-41d4-a716-446655440002';
        $path = aviationwx_exchange_write_sponsor_application([
            'application_id' => $applicationId,
            'airport_id' => 'kspb',
            'org_name' => 'Test FBO',
            'contact_name' => 'Jane',
            'contact_email' => 'test@example.com',
            'org_type' => 'on_airport_business',
        ]);

        self::assertFileExists($path);
        self::assertFileDoesNotExist($path . '.tmp');

        $payload = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame('1.0.0', $payload['schema_version']);
        self::assertSame($applicationId, $payload['application_id']);
        self::assertSame('test@example.com', $payload['contact_email']);
        self::assertSame(0640, fileperms($path) & 0777);
    }

    public function testWriteSponsorApplication_RejectsInvalidApplicationId(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        aviationwx_exchange_write_sponsor_application([
            'application_id' => '../evil',
            'airport_id' => 'kspb',
            'org_name' => 'Test FBO',
            'contact_name' => 'Jane',
            'contact_email' => 'test@example.com',
            'org_type' => 'on_airport_business',
        ]);
    }

    public function testWriteSponsorApplication_PreservesMessageZero(): void
    {
        $applicationId = '880e8400-e29b-41d4-a716-446655440003';
        $path = aviationwx_exchange_write_sponsor_application([
            'application_id' => $applicationId,
            'airport_id' => 'kspb',
            'org_name' => 'Test FBO',
            'contact_name' => 'Jane',
            'contact_email' => 'zero@example.com',
            'org_type' => 'on_airport_business',
            'message' => '0',
        ]);

        $payload = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame('0', $payload['message']);
    }

    public function testAppendStructuredLog_WritesJsonl(): void
    {
        aviationwx_exchange_append_structured_log('info', 'exchange test', ['source' => 'test']);

        $logDir = $this->exchangeRoot . '/in/structured-logs';
        self::assertDirectoryExists($logDir);
        $files = glob($logDir . '/*.jsonl') ?: [];
        self::assertNotEmpty($files);

        $lines = [];
        foreach ($files as $file) {
            foreach (file($file, FILE_IGNORE_NEW_LINES) ?: [] as $line) {
                $trimmed = trim($line);
                if ($trimmed !== '') {
                    $lines[] = $trimmed;
                }
            }
        }

        self::assertNotEmpty($lines);
        $line = json_decode($lines[count($lines) - 1], true, 512, JSON_THROW_ON_ERROR);
        self::assertSame('1.0.0', $line['schema_version']);
        self::assertSame('exchange test', $line['message']);
        self::assertSame(0640, fileperms($files[0]) & 0777);
    }

    private function removeTree(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }

        $items = scandir($dir) ?: [];
        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            $path = $dir . '/' . $item;
            is_dir($path) ? $this->removeTree($path) : @unlink($path);
        }
        @rmdir($dir);
    }
}
