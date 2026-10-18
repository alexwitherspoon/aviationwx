<?php
/**
 * Unit tests for WiFiLogger upload token generation and shape validation.
 */

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../lib/upload/keys.php';

class UploadApiKeyTest extends TestCase
{
    public function testGenerateUploadApiKey_MatchesShape(): void
    {
        $key = generateUploadApiKey();
        $this->assertTrue(isValidUploadApiKeyShape($key));
        $this->assertStringStartsWith(UPLOAD_API_KEY_PREFIX, $key);
        $this->assertSame(
            strlen(UPLOAD_API_KEY_PREFIX) + UPLOAD_API_KEY_SECRET_LENGTH,
            strlen($key)
        );
    }

    public function testGenerateUploadApiKey_Unique(): void
    {
        $a = generateUploadApiKey();
        $b = generateUploadApiKey();
        $this->assertNotSame($a, $b);
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('invalidKeyProvider')]
    public function testIsValidUploadApiKeyShape_RejectsInvalid(string $key): void
    {
        $this->assertFalse(isValidUploadApiKeyShape($key));
    }

    public static function invalidKeyProvider(): array
    {
        return [
            'empty' => [''],
            'bridge_prefix' => ['awxb_' . str_repeat('0', 48)],
            'partner_style' => ['ak_live_abcdefghijklmnop'],
            'short_secret' => ['awxu_abc'],
            'wrong_prefix' => ['awx_' . str_repeat('a', 48)],
            'symbols' => ['awxu_' . str_repeat('!', 48)],
            'too_long' => ['awxu_' . str_repeat('a', 49)],
        ];
    }
}