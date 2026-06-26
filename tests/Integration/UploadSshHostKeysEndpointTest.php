<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Integration tests for upload SFTP SSH host key roster endpoint.
 */
class UploadSshHostKeysEndpointTest extends TestCase
{
    private static string $baseUrl;

    private static bool $serverAvailable = false;

    public static function setUpBeforeClass(): void
    {
        self::$baseUrl = getenv('TEST_API_URL') ?: 'http://localhost:8080';

        $ch = curl_init(self::$baseUrl);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 5);
        curl_setopt($ch, CURLOPT_NOBODY, true);
        curl_exec($ch);
        self::$serverAvailable = curl_getinfo($ch, CURLINFO_HTTP_CODE) > 0;
        curl_close($ch);
    }

    private function skipIfServerUnavailable(): void
    {
        if (!self::$serverAvailable) {
            $this->markTestSkipped('Test server not running at ' . self::$baseUrl);
        }
    }

    /**
     * @return array{code: int, headers: array<string, string>, body: string, json: ?array}
     */
    private function fetchRoster(string $path): array
    {
        $url = self::$baseUrl . $path;

        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 10);
        curl_setopt($ch, CURLOPT_HTTPHEADER, ['Accept: application/json']);
        curl_setopt($ch, CURLOPT_HEADER, true);

        $response = curl_exec($ch);
        $headerSize = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
        $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        $headerStr = substr((string) $response, 0, $headerSize);
        $body = substr((string) $response, $headerSize);

        $headers = [];
        foreach (explode("\r\n", $headerStr) as $line) {
            if (strpos($line, ':') !== false) {
                [$key, $value] = explode(':', $line, 2);
                $headers[strtolower(trim($key))] = trim($value);
            }
        }

        return [
            'code' => $httpCode,
            'headers' => $headers,
            'body' => $body,
            'json' => json_decode($body, true),
        ];
    }

    public function testWellKnownPath_ReturnsValidJsonWhenKeysAvailable(): void
    {
        $this->skipIfServerUnavailable();

        $response = $this->fetchRoster('/.well-known/aviationwx-upload-ssh-host-keys.json');
        if ($response['code'] === 503) {
            $this->markTestSkipped('Container has no readable ssh host keys in /etc/ssh');
        }

        $this->assertSame(200, $response['code']);
        $this->assertIsArray($response['json']);
        $this->assertSame(1, $response['json']['version']);
        $this->assertSame(getUploadHostname(), $response['json']['hostname']);
        $this->assertSame(getSftpPort(), $response['json']['port']);
        $this->assertNotEmpty($response['json']['sha256']);
        foreach ($response['json']['sha256'] as $fp) {
            $this->assertMatchesRegularExpression('/^SHA256:[A-Za-z0-9+/]+$/', $fp);
        }
    }

    public function testWellKnownPath_SendsAggressiveNoStoreCacheHeaders(): void
    {
        $this->skipIfServerUnavailable();

        $response = $this->fetchRoster('/.well-known/aviationwx-upload-ssh-host-keys.json');
        if ($response['code'] === 503) {
            $this->markTestSkipped('Container has no readable ssh host keys in /etc/ssh');
        }

        $cacheControl = $response['headers']['cache-control'] ?? '';
        $this->assertStringContainsString('no-store', $cacheControl);
        $this->assertStringContainsString('no-cache', $cacheControl);
        $this->assertStringContainsString('max-age=0', $cacheControl);
        $this->assertStringContainsString('s-maxage=0', $cacheControl);
        $this->assertSame('no-cache', $response['headers']['pragma'] ?? '');
    }
}
