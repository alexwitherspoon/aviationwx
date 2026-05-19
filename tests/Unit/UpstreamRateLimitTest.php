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
        $GLOBALS['upstream_rate_limit_test_root'] = $this->testRoot;
    }

    protected function tearDown(): void
    {
        unset($GLOBALS['upstream_rate_limit_test_root']);
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
        $a = upstream_rate_fingerprint('tempest', $source);
        $b = upstream_rate_fingerprint('tempest', $source);
        $this->assertSame($a, $b);
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $a);
    }

    public function testFingerprint_DifferentApiKey_ReturnsDifferentHash(): void
    {
        require_once __DIR__ . '/../../lib/upstream-rate-limit.php';

        $a = upstream_rate_fingerprint('tempest', ['api_key' => 'key-one', 'station_id' => '1']);
        $b = upstream_rate_fingerprint('tempest', ['api_key' => 'key-two', 'station_id' => '1']);
        $this->assertNotSame($a, $b);
    }

    public function testFingerprint_Tempest_IgnoresStationId(): void
    {
        require_once __DIR__ . '/../../lib/upstream-rate-limit.php';

        $a = upstream_rate_fingerprint('tempest', ['api_key' => 'shared', 'station_id' => '111']);
        $b = upstream_rate_fingerprint('tempest', ['api_key' => 'shared', 'station_id' => '222']);
        $this->assertSame($a, $b);
    }

    public function testFingerprint_Pwsweather_UsesClientIdAndSecret(): void
    {
        require_once __DIR__ . '/../../lib/upstream-rate-limit.php';

        $a = upstream_rate_fingerprint('pwsweather', [
            'client_id' => 'cid',
            'client_secret' => 'sec',
            'station_id' => 'ST1',
        ]);
        $b = upstream_rate_fingerprint('pwsweather', [
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
            $result = upstream_rate_token_bucket_compute_take($tokens, $last, 60, 3, $now);
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
        $empty = upstream_rate_token_bucket_compute_take(0.0, $t0, 60, 3, $t0);
        $this->assertFalse($empty['allowed']);

        // 60 rpm => 1 token per second; after 2s we regain 2 tokens (capped at burst 3)
        $refilled = upstream_rate_token_bucket_compute_take(0.0, $t0, 60, 3, $t0 + 2.0);
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

        $this->assertTrue(upstream_rate_try_take($fingerprint, $rpm, $burst, $t0));
        $this->assertTrue(upstream_rate_try_take($fingerprint, $rpm, $burst, $t0));
        $this->assertFalse(upstream_rate_try_take($fingerprint, $rpm, $burst, $t0));

        // 2 seconds later at 60 rpm we regain 2 tokens
        $this->assertTrue(upstream_rate_try_take($fingerprint, $rpm, $burst, $t0 + 2.0));
    }

    public function testTryTake_CorruptStateFile_DoesNotGrantFullBurst(): void
    {
        require_once __DIR__ . '/../../lib/upstream-rate-limit.php';

        $fingerprint = hash('sha256', 'corrupt-state-test');
        $stateFile = upstream_rate_limit_state_file_path($fingerprint);
        $dir = dirname($stateFile);
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }
        file_put_contents($stateFile, 'not-json');

        $t0 = 1_700_000_000.0;
        $this->assertFalse(upstream_rate_try_take($fingerprint, 60, 3, $t0));
    }

    public function testFingerprint_Metar_DifferentStationIds_ReturnsDifferentHash(): void
    {
        require_once __DIR__ . '/../../lib/upstream-rate-limit.php';

        $a = upstream_rate_fingerprint('metar', ['station_id' => 'KAAA']);
        $b = upstream_rate_fingerprint('metar', ['station_id' => 'KBBB']);
        $this->assertNotSame($a, $b);
    }

    public function testFingerprint_Awosnet_DifferentStationIds_ReturnsDifferentHash(): void
    {
        require_once __DIR__ . '/../../lib/upstream-rate-limit.php';

        $a = upstream_rate_fingerprint('awosnet', ['station_id' => 'ks40']);
        $b = upstream_rate_fingerprint('awosnet', ['station_id' => 'ks41']);
        $this->assertNotSame($a, $b);
    }

    public function testConsumeForSource_MockMode_ReturnsAllowed(): void
    {
        require_once __DIR__ . '/../../lib/config.php';
        require_once __DIR__ . '/../../lib/upstream-rate-limit.php';

        $source = ['type' => 'tempest', 'api_key' => 'k', 'station_id' => '1'];
        $result = upstream_rate_limit_consume_for_source($source);
        $this->assertTrue($result['allowed']);
        $this->assertNull($result['fingerprint_prefix']);
    }

    public function testPolicyForProvider_Tempest_ReturnsConfiguredLimits(): void
    {
        require_once __DIR__ . '/../../lib/constants.php';
        require_once __DIR__ . '/../../lib/upstream-rate-limit.php';

        $policy = upstream_rate_limit_policy_for_provider('tempest');
        $this->assertSame(UPSTREAM_RATE_LIMIT_TEMPEST_RPM, $policy['rpm']);
        $this->assertSame(UPSTREAM_RATE_LIMIT_TEMPEST_BURST, $policy['burst']);
    }
}
