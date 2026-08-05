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

    public function testNormalize_RejectsInventoryStationWithoutId(): void
    {
        $result = bridgeNormalizeHealthPayload([
            'observed_at' => gmdate('c'),
            'host' => ['status' => 'operational'],
            'inventory' => [
                'stations' => [
                    ['name' => 'missing id'],
                ],
            ],
        ]);
        $this->assertFalse($result['ok']);
        $this->assertStringContainsString('id is required', $result['error'] ?? '');
    }

    public function testNormalize_RejectsBadSubsystemStatus(): void
    {
        $result = bridgeNormalizeHealthPayload([
            'observed_at' => gmdate('c'),
            'host' => ['status' => 'operational'],
            'subsystems' => [
                'weather' => ['status' => 'ok'],
            ],
        ]);
        $this->assertFalse($result['ok']);
        $this->assertStringContainsString('subsystems.weather.status', $result['error'] ?? '');
    }

    public function testNormalize_RejectsErrorsWithoutFingerprint(): void
    {
        $result = bridgeNormalizeHealthPayload([
            'observed_at' => gmdate('c'),
            'host' => ['status' => 'operational'],
            'errors' => [
                ['count' => 1, 'last_message' => 'oops'],
            ],
        ]);
        $this->assertFalse($result['ok']);
        $this->assertStringContainsString('fingerprint', $result['error'] ?? '');
    }

    public function testNormalize_RejectsInventoryOverMax(): void
    {
        $stations = [];
        for ($i = 0; $i < BRIDGE_HEALTH_INVENTORY_MAX + 1; $i++) {
            $stations[] = ['id' => 'station-' . $i];
        }
        $result = bridgeNormalizeHealthPayload([
            'observed_at' => gmdate('c'),
            'host' => ['status' => 'operational'],
            'inventory' => ['stations' => $stations],
        ]);
        $this->assertFalse($result['ok']);
        $this->assertStringContainsString('exceeds max', $result['error'] ?? '');
    }

    public function testHistoryAppend_RetainsLinesUnderLock(): void
    {
        $path = $this->tmpRoot . '/health_history.jsonl';
        $this->assertTrue(bridgeAppendJsonlRing($path, ['n' => 1], 10));
        $this->assertTrue(bridgeAppendJsonlRing($path, ['n' => 2], 10));
        $this->assertTrue(bridgeAppendJsonlRing($path, ['n' => 3], 2));
        $raw = file_get_contents($path);
        $this->assertNotFalse($raw);
        $lines = array_values(array_filter(explode("\n", trim($raw))));
        $this->assertCount(2, $lines);
        $this->assertSame(2, json_decode($lines[0], true)['n'] ?? null);
        $this->assertSame(3, json_decode($lines[1], true)['n'] ?? null);
    }

    public function testHostStatusWorse_DegradedBeatsMaintenanceEitherOrder(): void
    {
        $this->assertSame('degraded', bridgeHostStatusWorse('maintenance', 'degraded'));
        $this->assertSame('degraded', bridgeHostStatusWorse('degraded', 'maintenance'));
        $this->assertSame('down', bridgeHostStatusWorse('degraded', 'down'));
        $this->assertSame('down', bridgeHostStatusWorse('maintenance', 'down'));
        $this->assertSame('maintenance', bridgeHostStatusWorse('operational', 'maintenance'));
    }

    public function testBuildAirportBridgeHostsComponent_DegradedWinsOverMaintenance(): void
    {
        $maint = bridgeNormalizeHealthPayload([
            'observed_at' => gmdate('c'),
            'host' => ['status' => 'maintenance', 'ntp_ok' => true, 'ntp_failure_seconds' => 0],
            'inventory' => ['stations' => [], 'cameras' => []],
        ]);
        $this->assertTrue($maint['ok']);
        $this->assertTrue(bridgeStoreHealth('kspb', 'bridge-maint', $maint['health']));

        $degraded = bridgeNormalizeHealthPayload([
            'observed_at' => gmdate('c'),
            'host' => ['status' => 'degraded', 'ntp_ok' => true, 'ntp_failure_seconds' => 0],
            'inventory' => ['stations' => [], 'cameras' => []],
        ]);
        $this->assertTrue($degraded['ok']);
        $this->assertTrue(bridgeStoreHealth('kspb', 'bridge-degraded', $degraded['health']));

        $airport = [
            'bridges' => [
                ['id' => 'bridge-maint', 'label' => 'Maint'],
                ['id' => 'bridge-degraded', 'label' => 'Degraded'],
            ],
        ];
        $component = buildAirportBridgeHostsComponent('kspb', $airport);
        $this->assertNotNull($component);
        $this->assertSame('degraded', $component['status']);

        // Order of bridges[] must not change the rollup
        $airportReversed = [
            'bridges' => [
                ['id' => 'bridge-degraded', 'label' => 'Degraded'],
                ['id' => 'bridge-maint', 'label' => 'Maint'],
            ],
        ];
        $componentReversed = buildAirportBridgeHostsComponent('kspb', $airportReversed);
        $this->assertNotNull($componentReversed);
        $this->assertSame('degraded', $componentReversed['status']);
    }

    /**
     * Airport rollup treats host maintenance as degraded (not operational)
     */
    public function testCheckAirportHealth_BridgeHostsMaintenanceOnly_IsDegraded(): void
    {
        require_once __DIR__ . '/../../lib/status-checks.php';

        $maint = bridgeNormalizeHealthPayload([
            'observed_at' => gmdate('c'),
            'host' => ['status' => 'maintenance', 'ntp_ok' => true, 'ntp_failure_seconds' => 0],
            'inventory' => ['stations' => [], 'cameras' => []],
        ]);
        $this->assertTrue($maint['ok']);
        $this->assertTrue(bridgeStoreHealth('kspb', 'bridge-maint-only', $maint['health']));

        $airport = [
            'weather_sources' => [],
            'webcams' => [],
            'bridges' => [
                ['id' => 'bridge-maint-only', 'label' => 'Maint'],
            ],
        ];
        $health = checkAirportHealth('kspb', $airport);

        $this->assertArrayHasKey('bridge_hosts', $health['components']);
        $this->assertSame('maintenance', $health['components']['bridge_hosts']['status']);
        $this->assertSame('degraded', $health['status']);
    }

    /**
     * Config airport maintenance still overrides component-derived status
     */
    public function testCheckAirportHealth_ConfigMaintenanceOverridesBridgeDegraded(): void
    {
        require_once __DIR__ . '/../../lib/status-checks.php';

        $maint = bridgeNormalizeHealthPayload([
            'observed_at' => gmdate('c'),
            'host' => ['status' => 'maintenance', 'ntp_ok' => true, 'ntp_failure_seconds' => 0],
            'inventory' => ['stations' => [], 'cameras' => []],
        ]);
        $this->assertTrue($maint['ok']);
        $this->assertTrue(bridgeStoreHealth('kspb', 'bridge-maint-cfg', $maint['health']));

        $airport = [
            'maintenance' => true,
            'weather_sources' => [],
            'webcams' => [],
            'bridges' => [
                ['id' => 'bridge-maint-cfg', 'label' => 'Maint'],
            ],
        ];
        $health = checkAirportHealth('kspb', $airport);

        $this->assertSame('maintenance', $health['status']);
    }
}
