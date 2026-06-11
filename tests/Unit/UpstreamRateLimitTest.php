<?php

declare(strict_types=1);

namespace AviationWX\Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Unit tests for lib/upstream-rate-limit.php (fingerprinting and token bucket).
 */
final class UpstreamRateLimitTest extends TestCase
{
    private ?string $testRoot = null;

    protected function setUp(): void
    {
        parent::setUp();
        $this->testRoot = sys_get_temp_dir() . '/upstream-rate-limit-test-' . bin2hex(random_bytes(4));
        mkdir($this->testRoot, 0755, true);
        $GLOBALS['upstreamRateLimitTestRoot'] = $this->testRoot;
    }

    protected function tearDown(): void
    {
        unset(
            $GLOBALS['upstreamRateLimitTestRoot'],
            $GLOBALS['upstreamRateLimitTestForcePersistFailure']
        );
        if ($this->testRoot !== null && is_dir($this->testRoot)) {
            $files = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($this->testRoot, \FilesystemIterator::SKIP_DOTS),
                \RecursiveIteratorIterator::CHILD_FIRST
            );
            foreach ($files as $file) {
                if ($file->isDir()) {
                    rmdir($file->getPathname());
                } else {
                    unlink($file->getPathname());
                }
            }
            rmdir($this->testRoot);
        }
        parent::tearDown();
    }

    public function testFingerprint_SameCredentials_ReturnsStableHash(): void
    {
        require_once __DIR__ . '/../../lib/upstream-rate-limit.php';

        $source = ['api_key' => 'secret-a', 'station_id' => '12345'];
        $a = upstreamRateFingerprint('tempest', $source);
        $b = upstreamRateFingerprint('tempest', $source);
        $this->assertSame($a, $b);
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $a);
    }

    public function testFingerprint_DifferentApiKey_ReturnsDifferentHash(): void
    {
        require_once __DIR__ . '/../../lib/upstream-rate-limit.php';

        $a = upstreamRateFingerprint('tempest', ['api_key' => 'key-one', 'station_id' => '1']);
        $b = upstreamRateFingerprint('tempest', ['api_key' => 'key-two', 'station_id' => '1']);
        $this->assertNotSame($a, $b);
    }

    public function testFingerprint_Tempest_IgnoresStationId(): void
    {
        require_once __DIR__ . '/../../lib/upstream-rate-limit.php';

        $a = upstreamRateFingerprint('tempest', ['api_key' => 'shared', 'station_id' => '111']);
        $b = upstreamRateFingerprint('tempest', ['api_key' => 'shared', 'station_id' => '222']);
        $this->assertSame($a, $b);
    }

    public function testFingerprint_Pwsweather_UsesClientIdAndSecret(): void
    {
        require_once __DIR__ . '/../../lib/upstream-rate-limit.php';

        $a = upstreamRateFingerprint('pwsweather', [
            'client_id' => 'cid',
            'client_secret' => 'sec',
            'station_id' => 'ST1',
        ]);
        $b = upstreamRateFingerprint('pwsweather', [
            'client_id' => 'cid',
            'client_secret' => 'sec',
            'station_id' => 'ST2',
        ]);
        $this->assertSame($a, $b);
    }

    public function testTokenBucketCompute_AllowsBurstThenDenies(): void
    {
        require_once __DIR__ . '/../../lib/upstream-rate-limit.php';

        $tokens = 3.0;
        $last = 1000.0;
        $now = 1000.0;
        $allowed = [];
        for ($i = 0; $i < 5; $i++) {
            $result = upstreamRateTokenBucketComputeTake($tokens, $last, 60, 3, $now);
            $allowed[] = $result['allowed'];
            $tokens = $result['tokens'];
            $last = $result['last_refill'];
        }
        $this->assertSame([true, true, true, false, false], $allowed);
    }

    public function testTokenBucketCompute_RefillsOverTime(): void
    {
        require_once __DIR__ . '/../../lib/upstream-rate-limit.php';

        $t0 = 1_700_000_000.0;
        $empty = upstreamRateTokenBucketComputeTake(0.0, $t0, 60, 3, $t0);
        $this->assertFalse($empty['allowed']);

        // 60 rpm => 1 token per second; after 2s we regain 2 tokens (capped at burst 3)
        $refilled = upstreamRateTokenBucketComputeTake(0.0, $t0, 60, 3, $t0 + 2.0);
        $this->assertTrue($refilled['allowed']);
        $this->assertGreaterThanOrEqual(0.9, $refilled['tokens']);
    }

    public function testTryTake_ExhaustsThenRecoversAfterElapsedTime(): void
    {
        require_once __DIR__ . '/../../lib/upstream-rate-limit.php';

        $fingerprint = 'test-fingerprint-' . bin2hex(random_bytes(8));
        $rpm = 60;
        $burst = 2;
        $t0 = 1_700_000_000.0;

        $this->assertTrue(upstreamRateTryTake($fingerprint, $rpm, $burst, $t0));
        $this->assertTrue(upstreamRateTryTake($fingerprint, $rpm, $burst, $t0));
        $this->assertFalse(upstreamRateTryTake($fingerprint, $rpm, $burst, $t0));

        // 2 seconds later at 60 rpm we regain 2 tokens
        $this->assertTrue(upstreamRateTryTake($fingerprint, $rpm, $burst, $t0 + 2.0));
    }

    public function testTryTake_CorruptStateFile_DoesNotGrantFullBurst(): void
    {
        require_once __DIR__ . '/../../lib/upstream-rate-limit.php';

        $fingerprint = hash('sha256', 'corrupt-state-test');
        $stateFile = upstreamRateLimitStateFilePath($fingerprint);
        $dir = dirname($stateFile);
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }
        file_put_contents($stateFile, 'not-json');

        $t0 = 1_700_000_000.0;
        $this->assertFalse(upstreamRateTryTake($fingerprint, 60, 3, $t0));
    }

    public function testTryTake_PersistFailure_FailsOpenWithoutConsumedFlag(): void
    {
        require_once __DIR__ . '/../../lib/upstream-rate-limit.php';

        $fingerprint = hash('sha256', 'persist-failure-test');
        $t0 = 1_700_000_100.0;
        $consumed = false;

        $GLOBALS['upstreamRateLimitTestForcePersistFailure'] = true;
        $this->assertTrue(upstreamRateTryTake($fingerprint, 60, 1, $t0, $consumed));
        $this->assertFalse($consumed);

        unset($GLOBALS['upstreamRateLimitTestForcePersistFailure']);
        $consumed = false;
        $this->assertTrue(upstreamRateTryTake($fingerprint, 60, 1, $t0, $consumed));
        $this->assertTrue($consumed);
    }

    public function testFingerprint_Metar_DifferentStationIds_ReturnsDifferentHash(): void
    {
        require_once __DIR__ . '/../../lib/upstream-rate-limit.php';

        $a = upstreamRateFingerprint('metar', ['station_id' => 'KAAA']);
        $b = upstreamRateFingerprint('metar', ['station_id' => 'KBBB']);
        $this->assertNotSame($a, $b);
    }

    public function testFingerprint_Awosnet_DifferentStationIds_ReturnsDifferentHash(): void
    {
        require_once __DIR__ . '/../../lib/upstream-rate-limit.php';

        $a = upstreamRateFingerprint('awosnet', ['station_id' => 'ks40']);
        $b = upstreamRateFingerprint('awosnet', ['station_id' => 'ks41']);
        $this->assertNotSame($a, $b);
    }

    public function testConsumeForSource_MockMode_ReturnsAllowed(): void
    {
        require_once __DIR__ . '/../../lib/config.php';
        require_once __DIR__ . '/../../lib/upstream-rate-limit.php';

        $source = ['type' => 'tempest', 'api_key' => 'k', 'station_id' => '1'];
        $result = upstreamRateLimitConsumeForSource($source);
        $this->assertTrue($result['allowed']);
        $this->assertNull($result['fingerprint_prefix']);
    }

    public function testPolicyForProvider_Tempest_ReturnsConfiguredLimits(): void
    {
        require_once __DIR__ . '/../../lib/constants.php';
        require_once __DIR__ . '/../../lib/upstream-rate-limit.php';

        $policy = upstreamRateLimitPolicyForProvider('tempest');
        $this->assertSame(UPSTREAM_RATE_LIMIT_TEMPEST_RPM, $policy['rpm']);
        $this->assertSame(UPSTREAM_RATE_LIMIT_TEMPEST_BURST, $policy['burst']);
    }

    public function testFingerprint_Ambient_ApplicationKeyScope_IgnoresApiKey(): void
    {
        require_once __DIR__ . '/../../lib/upstream-rate-limit.php';

        $sharedApp = 'app-key-shared';
        $a = upstreamRateFingerprintForScope('ambient', 'application_key', [
            'api_key' => 'user-key-a',
            'application_key' => $sharedApp,
        ], ['application_key']);
        $b = upstreamRateFingerprintForScope('ambient', 'application_key', [
            'api_key' => 'user-key-b',
            'application_key' => $sharedApp,
        ], ['application_key']);
        $this->assertSame($a, $b);
    }

    public function testFingerprint_Ambient_ApiKeyScope_IgnoresApplicationKey(): void
    {
        require_once __DIR__ . '/../../lib/upstream-rate-limit.php';

        $sharedUser = 'user-key-shared';
        $a = upstreamRateFingerprintForScope('ambient', 'api_key', [
            'api_key' => $sharedUser,
            'application_key' => 'app-a',
        ], ['api_key']);
        $b = upstreamRateFingerprintForScope('ambient', 'api_key', [
            'api_key' => $sharedUser,
            'application_key' => 'app-b',
        ], ['api_key']);
        $this->assertSame($a, $b);
    }

    public function testScopesForSource_Ambient_ReturnsApiKeyAndApplicationKeyBuckets(): void
    {
        require_once __DIR__ . '/../../lib/constants.php';
        require_once __DIR__ . '/../../lib/upstream-rate-limit.php';

        $scopes = upstreamRateLimitScopesForSource([
            'type' => 'ambient',
            'api_key' => 'user-1',
            'application_key' => 'app-1',
        ]);

        $this->assertCount(2, $scopes);
        $this->assertSame('api_key', $scopes[0]['scope']);
        $this->assertSame(UPSTREAM_RATE_LIMIT_AMBIENT_API_KEY_RPM, $scopes[0]['rpm']);
        $this->assertSame(UPSTREAM_RATE_LIMIT_AMBIENT_API_KEY_BURST, $scopes[0]['burst']);
        $this->assertSame('application_key', $scopes[1]['scope']);
        $this->assertSame(UPSTREAM_RATE_LIMIT_AMBIENT_APPLICATION_KEY_RPM, $scopes[1]['rpm']);
        $this->assertSame(UPSTREAM_RATE_LIMIT_AMBIENT_APPLICATION_KEY_BURST, $scopes[1]['burst']);
    }

    public function testConsumeForSource_Ambient_ApplicationKeyScopeLimitsSharedDeveloperKey(): void
    {
        require_once __DIR__ . '/../../lib/upstream-rate-limit.php';

        upstreamRateLimitTestForceEnforcement();
        $t0 = 1_700_000_000.0;
        $sharedApp = 'shared-developer-key';
        $stations = [
            ['type' => 'ambient', 'api_key' => 'user-a', 'application_key' => $sharedApp],
            ['type' => 'ambient', 'api_key' => 'user-b', 'application_key' => $sharedApp],
            ['type' => 'ambient', 'api_key' => 'user-c', 'application_key' => $sharedApp],
        ];

        $GLOBALS['upstreamRateLimitTestNow'] = $t0;
        foreach ($stations as $source) {
            $this->assertTrue(upstreamRateLimitConsumeForSource($source)['allowed']);
        }

        $GLOBALS['upstreamRateLimitTestNow'] = $t0;
        $fourth = upstreamRateLimitConsumeForSource([
            'type' => 'ambient',
            'api_key' => 'user-d',
            'application_key' => $sharedApp,
        ]);
        $this->assertFalse($fourth['allowed']);

        upstreamRateLimitTestClearForceEnforcement();
        unset($GLOBALS['upstreamRateLimitTestNow']);
    }

    public function testConsumeForSource_Ambient_PartialDenyRefundsEarlierScopeTokens(): void
    {
        require_once __DIR__ . '/../../lib/upstream-rate-limit.php';

        upstreamRateLimitTestForceEnforcement();
        $t0 = 1_700_000_000.0;
        $sharedApp = 'shared-developer-key';
        $deniedSource = [
            'type' => 'ambient',
            'api_key' => 'user-d',
            'application_key' => $sharedApp,
        ];

        $GLOBALS['upstreamRateLimitTestNow'] = $t0;
        foreach (
            [
                ['type' => 'ambient', 'api_key' => 'user-a', 'application_key' => $sharedApp],
                ['type' => 'ambient', 'api_key' => 'user-b', 'application_key' => $sharedApp],
                ['type' => 'ambient', 'api_key' => 'user-c', 'application_key' => $sharedApp],
            ] as $source
        ) {
            $this->assertTrue(upstreamRateLimitConsumeForSource($source)['allowed']);
        }

        $GLOBALS['upstreamRateLimitTestNow'] = $t0;
        $this->assertFalse(upstreamRateLimitConsumeForSource($deniedSource)['allowed']);

        $apiFingerprint = upstreamRateFingerprintForScope(
            'ambient',
            'api_key',
            $deniedSource,
            ['api_key']
        );
        $GLOBALS['upstreamRateLimitTestNow'] = $t0;
        $this->assertTrue(
            upstreamRateTryTake(
                $apiFingerprint,
                UPSTREAM_RATE_LIMIT_AMBIENT_API_KEY_RPM,
                UPSTREAM_RATE_LIMIT_AMBIENT_API_KEY_BURST,
                $t0
            ),
            'api_key token should be refunded when application_key scope denies the take'
        );

        upstreamRateLimitTestClearForceEnforcement();
        unset($GLOBALS['upstreamRateLimitTestNow']);
    }

    public function testTokenBucketComputeRefund_CapsAtBurst(): void
    {
        require_once __DIR__ . '/../../lib/upstream-rate-limit.php';

        $result = upstreamRateTokenBucketComputeRefund(2.0, 100.0, 3);
        $this->assertSame(3.0, $result['tokens']);
        $this->assertSame(100.0, $result['last_refill']);
    }

    public function testConsumeForSource_Ambient_ApiKeyScopeAllowsOnePerSecond(): void
    {
        require_once __DIR__ . '/../../lib/upstream-rate-limit.php';

        upstreamRateLimitTestForceEnforcement();
        $source = [
            'type' => 'ambient',
            'api_key' => 'solo-user',
            'application_key' => 'solo-app',
        ];
        $t0 = 1_700_000_000.0;

        $GLOBALS['upstreamRateLimitTestNow'] = $t0;
        $this->assertTrue(upstreamRateLimitConsumeForSource($source)['allowed']);

        $GLOBALS['upstreamRateLimitTestNow'] = $t0;
        $this->assertFalse(upstreamRateLimitConsumeForSource($source)['allowed']);

        $GLOBALS['upstreamRateLimitTestNow'] = $t0 + 1.0;
        $this->assertTrue(upstreamRateLimitConsumeForSource($source)['allowed']);

        upstreamRateLimitTestClearForceEnforcement();
        unset($GLOBALS['upstreamRateLimitTestNow']);
    }

    public function testGlobalCredentialFingerprint_Ambient_UsesApplicationKeyScope(): void
    {
        require_once __DIR__ . '/../../lib/upstream-rate-limit.php';

        $app = 'developer-app-key';
        $a = upstreamRateGlobalCredentialFingerprint('ambient', [
            'api_key' => 'user-a',
            'application_key' => $app,
        ]);
        $b = upstreamRateGlobalCredentialFingerprint('ambient', [
            'api_key' => 'user-b',
            'application_key' => $app,
        ]);
        $this->assertSame($a, $b);
        $this->assertNotSame(
            $a,
            upstreamRateGlobalCredentialFingerprint('ambient', [
                'api_key' => 'user-a',
                'application_key' => 'other-app',
            ])
        );
    }

    public function testPolicyForProvider_WeatherLink_BurstMatchesPerSecondUpstreamCap(): void
    {
        require_once __DIR__ . '/../../lib/constants.php';
        require_once __DIR__ . '/../../lib/upstream-rate-limit.php';

        $policy = upstreamRateLimitPolicyForProvider('weatherlink_v2');
        $this->assertSame(UPSTREAM_RATE_LIMIT_WEATHERLINK_RPM, $policy['rpm']);
        $this->assertSame(UPSTREAM_RATE_LIMIT_WEATHERLINK_BURST, $policy['burst']);
        $this->assertLessThanOrEqual(3, $policy['burst']);
    }

    public function testPolicyForProvider_Nws_BurstIsConservativeForUndisclosedLimits(): void
    {
        require_once __DIR__ . '/../../lib/constants.php';
        require_once __DIR__ . '/../../lib/upstream-rate-limit.php';

        $policy = upstreamRateLimitPolicyForProvider('nws');
        $this->assertLessThanOrEqual(2, $policy['burst']);
    }
}
