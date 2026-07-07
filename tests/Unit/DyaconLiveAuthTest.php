<?php
/**
 * DyaconLive bearer token lifecycle tests.
 */

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../lib/weather/dyaconlive-auth.php';

class DyaconLiveAuthTest extends TestCase
{
    protected function tearDown(): void
    {
        unset($GLOBALS['dyaconliveTestBearerToken']);
        parent::tearDown();
    }

    public function testTokenExpiresInSeconds_UsesExpiresInWhenPresent(): void
    {
        $ttl = dyaconliveTokenExpiresInSeconds('token', ['expires_in' => 900]);
        $this->assertSame(900, $ttl);
    }

    public function testTokenExpiresInSeconds_ParsesJwtExp(): void
    {
        $exp = time() + 1800;
        $payload = base64_encode(json_encode(['exp' => $exp], JSON_THROW_ON_ERROR));
        $token = 'hdr.' . rtrim(strtr($payload, '+/', '-_'), '=') . '.sig';
        $ttl = dyaconliveTokenExpiresInSeconds($token, []);
        $this->assertGreaterThan(1700, $ttl);
        $this->assertLessThanOrEqual(1800, $ttl);
    }

    public function testTokenExpiresInSeconds_FallsBackToDefault(): void
    {
        $ttl = dyaconliveTokenExpiresInSeconds('not-a-jwt', []);
        $this->assertSame(DYACONLIVE_TOKEN_DEFAULT_TTL_SECONDS, $ttl);
    }

    public function testGetBearerToken_TestOverride_ReturnsInjectedToken(): void
    {
        $GLOBALS['dyaconliveTestBearerToken'] = 'injected-test-token';
        $this->assertSame('injected-test-token', dyaconliveGetBearerToken('user@example.com', 'secret'));
    }

    public function testGetBearerToken_MissingCredentials_ReturnsNull(): void
    {
        $this->assertNull(dyaconliveGetBearerToken('', 'secret'));
        $this->assertNull(dyaconliveGetBearerToken('user@example.com', ''));
    }

    public function testGetBearerToken_TestMode_UsesMockTokenEndpoint(): void
    {
        unset($GLOBALS['dyaconliveTestBearerToken']);
        $this->assertSame('test_dyaconlive_bearer_token', dyaconliveGetBearerToken('user@example.com', 'secret'));
    }

    public function testBearerTokenCacheKey_IsStablePerUsername(): void
    {
        $this->assertSame(
            dyaconliveBearerTokenCacheKey('user@example.com'),
            dyaconliveBearerTokenCacheKey('user@example.com')
        );
        $this->assertNotSame(
            dyaconliveBearerTokenCacheKey('a@example.com'),
            dyaconliveBearerTokenCacheKey('b@example.com')
        );
    }
}
