<?php
/**
 * Unit tests for bridge health normalize/store and host status evaluation.
 */

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../lib/bridge/keys.php';
require_once __DIR__ . '/../../lib/bridge/health.php';
require_once __DIR__ . '/../../lib/bridge/store.php';
require_once __DIR__ . '/../../lib/bridge/status.php';
require_once __DIR__ . '/../../lib/cache-paths.php';

class BridgeHealthStatusTest extends TestCase
{
    private string $tmpRoot;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tmpRoot = sys_get_temp_dir() . '/aviationwx_bridge_health_' . uniqid('', true);
        mkdir($this->tmpRoot, 0755, true);
        $GLOBALS['bridgeTestCacheRoot'] = $this->tmpRoot;
    }

    protected function tearDown(): void
    {
        unset($GLOBALS['bridgeTestCacheRoot']);
        $this->removeTree($this->tmpRoot);
        parent::tearDown();
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

    public function testNormalizeHealth_RequiresHostStatus(): void
    {
        $result = bridgeNormalizeHealthPayload([
            'observed_at' => '2026-07-29T23:00:00Z',
        ]);
        $this->assertFalse($result['ok']);
    }

    public function testStoreAndEvaluate_Operational(): void
    {
        $normalized = bridgeNormalizeHealthPayload([
            'observed_at' => gmdate('c'),
            'bridge_id' => 'bridge-spb-1',
            'host' => [
                'status' => 'operational',
                'ntp_ok' => true,
                'ntp_failure_seconds' => 0,
                'build' => ['version' => '2.0.0', 'commit' => 'abc1234'],
            ],
            'inventory' => [
                'stations' => [
                    [
                        'id' => 'station-scappoose-davis',
                        'name' => 'Davis WLL',
                        'type' => 'davis_weatherlink_live',
                        'enabled_on_bridge' => true,
                    ],
                ],
                'cameras' => [
                    ['id' => 'cam-west', 'name' => 'West', 'enabled_on_bridge' => true],
                ],
            ],
            'errors' => [],
        ]);
        $this->assertTrue($normalized['ok'], $normalized['error'] ?? '');
        $this->assertTrue(bridgeStoreHealth('kspb', 'bridge-spb-1', $normalized['health']));

        $eval = evaluateBridgeHostHealth('kspb', [
            'id' => 'bridge-spb-1',
            'label' => 'Scappoose Pi',
        ], [
            'weather_sources' => [],
        ]);
        $this->assertSame('operational', $eval['status']);
        $this->assertStringContainsString('not-enabled', $eval['message']);
        $this->assertFileExists(getBridgeHealthCachePath('kspb', 'bridge-spb-1'));
        $this->assertFileExists(getBridgeHealthHistoryCachePath('kspb', 'bridge-spb-1'));
    }

    public function testEvaluate_BriefNtpFailureIsDegraded(): void
    {
        $normalized = bridgeNormalizeHealthPayload([
            'observed_at' => gmdate('c'),
            'host' => [
                'status' => 'operational',
                'ntp_ok' => false,
                'ntp_failure_seconds' => 30,
            ],
            'inventory' => ['stations' => [], 'cameras' => []],
        ]);
        $this->assertTrue($normalized['ok']);
        bridgeStoreHealth('kspb', 'bridge-spb-1', $normalized['health']);

        $eval = evaluateBridgeHostHealth('kspb', ['id' => 'bridge-spb-1', 'label' => 'Pi'], null);
        $this->assertSame('degraded', $eval['status']);
    }

    public function testEvaluate_LongNtpFailureIsDown(): void
    {
        $normalized = bridgeNormalizeHealthPayload([
            'observed_at' => gmdate('c'),
            'host' => [
                'status' => 'operational',
                'ntp_ok' => false,
                'ntp_failure_seconds' => DEFAULT_STALE_ERROR_SECONDS,
            ],
            'inventory' => ['stations' => [], 'cameras' => []],
        ]);
        $this->assertTrue($normalized['ok']);
        bridgeStoreHealth('kspb', 'bridge-spb-1', $normalized['health']);

        $eval = evaluateBridgeHostHealth('kspb', ['id' => 'bridge-spb-1'], null);
        $this->assertSame('down', $eval['status']);
    }

    public function testEvaluate_MissingHeartbeatIsDown(): void
    {
        $eval = evaluateBridgeHostHealth('kspb', ['id' => 'bridge-missing'], null);
        $this->assertSame('down', $eval['status']);
        $this->assertStringContainsString('No heartbeat', $eval['message']);
    }

    public function testScrubSecretsInErrors(): void
    {
        $normalized = bridgeNormalizeHealthPayload([
            'observed_at' => gmdate('c'),
            'host' => ['status' => 'degraded', 'ntp_ok' => true],
            'errors' => [
                [
                    'fingerprint' => 'upload:sftp_auth',
                    'count' => 2,
                    'last_message' => 'password=supersecret token=abc',
                    'subsystem' => 'upload',
                ],
            ],
        ]);
        $this->assertTrue($normalized['ok']);
        $msg = $normalized['health']['errors'][0]['last_message'] ?? '';
        $this->assertStringNotContainsString('supersecret', $msg);
        $this->assertStringContainsString('[redacted]', $msg);
    }
}
