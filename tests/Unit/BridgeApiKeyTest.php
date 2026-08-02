<?php
/**
 * Unit tests for bridge API key generation and shape validation.
 */

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../lib/bridge/keys.php';

class BridgeApiKeyTest extends TestCase
{
    public function testGenerateBridgeApiKey_MatchesShape(): void
    {
        $key = generateBridgeApiKey();
        $this->assertTrue(isValidBridgeApiKeyShape($key));
        $this->assertStringStartsWith(BRIDGE_API_KEY_PREFIX, $key);
        $this->assertSame(
            strlen(BRIDGE_API_KEY_PREFIX) + BRIDGE_API_KEY_SECRET_LENGTH,
            strlen($key)
        );
    }

    public function testGenerateBridgeApiKey_Unique(): void
    {
        $a = generateBridgeApiKey();
        $b = generateBridgeApiKey();
        $this->assertNotSame($a, $b);
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('invalidKeyProvider')]
    public function testIsValidBridgeApiKeyShape_RejectsInvalid(string $key): void
    {
        $this->assertFalse(isValidBridgeApiKeyShape($key));
    }

    public static function invalidKeyProvider(): array
    {
        return [
            'empty' => [''],
            'partner_style' => ['ak_live_abcdefghijklmnop'],
            'short_secret' => ['awxb_abc'],
            'wrong_prefix' => ['awx_' . str_repeat('a', 48)],
            'symbols' => ['awxb_' . str_repeat('!', 48)],
            'too_long' => ['awxb_' . str_repeat('a', 49)],
        ];
    }
}
