<?php
/**
 * Unit/integration-style tests for bridge bootstrap/health handlers (no HTTP server).
 */

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../lib/bridge/keys.php';
require_once __DIR__ . '/../../lib/bridge/middleware.php';
require_once __DIR__ . '/../../lib/bridge/store.php';
require_once __DIR__ . '/../../lib/config.php';
require_once __DIR__ . '/../../api/v1/bridge-bootstrap.php';
require_once __DIR__ . '/../../api/v1/bridge-health.php';

class BridgeApiHandlersTest extends TestCase
{
    private string $tmpRoot;
    private string $originalConfigPath;

    private const FIXTURE_KEY = 'awxb_000000000000000000000000000000000000000000000001';

    protected function setUp(): void
    {
        parent::setUp();
        $this->originalConfigPath = getenv('CONFIG_PATH') ?: '';
        putenv('CONFIG_PATH=' . __DIR__ . '/../Fixtures/airports.json.test');
        $_ENV['CONFIG_PATH'] = __DIR__ . '/../Fixtures/airports.json.test';
        clearConfigCache();

        $this->tmpRoot = sys_get_temp_dir() . '/aviationwx_bridge_handlers_' . uniqid('', true);
        mkdir($this->tmpRoot, 0755, true);
        $GLOBALS['bridgeTestCacheRoot'] = $this->tmpRoot;
    }

    protected function tearDown(): void
    {
        unset($GLOBALS['bridgeTestCacheRoot']);
        if ($this->originalConfigPath !== '') {
            putenv('CONFIG_PATH=' . $this->originalConfigPath);
            $_ENV['CONFIG_PATH'] = $this->originalConfigPath;
        } else {
            putenv('CONFIG_PATH');
            unset($_ENV['CONFIG_PATH']);
        }
        clearConfigCache();
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

    private function bindingContext(): array
    {
        $binding = resolveBridgeApiKey(self::FIXTURE_KEY);
        $this->assertNotNull($binding);
        return [
            'airport_id' => $binding['airport_id'],
            'bridge_id' => $binding['bridge_id'],
            'label' => $binding['label'],
            'bridge' => $binding['bridge'],
            'api_key' => self::FIXTURE_KEY,
            'rate_limit' => [],
            'ip' => '127.0.0.1',
        ];
    }

    public function testBootstrapReturnsAirportAndDeclination(): void
    {
        $_SERVER['REQUEST_METHOD'] = 'GET';
        ob_start();
        handleBridgeBootstrap([], $this->bindingContext());
        $out = ob_get_clean();
        $json = json_decode($out, true);
        $this->assertIsArray($json);
        $this->assertTrue($json['success'] ?? false);
        $this->assertSame('KSPB', $json['data']['airport']['id'] ?? null);
        $this->assertSame('bridge-spb-test-1', $json['data']['bridge_id'] ?? null);
        $this->assertArrayHasKey('declination_deg', $json['data']);
        $this->assertArrayHasKey('declination_source', $json['data']);
        $this->assertSame(60, $json['data']['heartbeat_interval_seconds'] ?? null);
        $this->assertIsArray($json['data']['enabled_sources'] ?? null);
    }

    public function testHealthPersistsInventory(): void
    {
        $_SERVER['REQUEST_METHOD'] = 'POST';
        $body = json_encode([
            'observed_at' => gmdate('c'),
            'bridge_id' => 'wrong-id-should-warn',
            'host' => [
                'status' => 'operational',
                'ntp_ok' => true,
                'ntp_failure_seconds' => 0,
                'build' => ['version' => '1.0.0', 'commit' => 'deadbee'],
            ],
            'inventory' => [
                'stations' => [
                    [
                        'id' => 'station-scappoose-davis',
                        'name' => 'Davis',
                        'type' => 'davis_weatherlink_live',
                        'enabled_on_bridge' => true,
                    ],
                ],
                'cameras' => [],
            ],
            'errors' => [],
        ]);

        // Simulate php://input via a stream wrapper is hard; call normalize+store path used by handler
        $parsed = json_decode($body, true);
        $this->assertIsArray($parsed);
        warnIfBridgeBodyIdMismatch($this->bindingContext(), $parsed['bridge_id']);
        $normalized = bridgeNormalizeHealthPayload($parsed);
        $this->assertTrue($normalized['ok']);
        $ctx = $this->bindingContext();
        $this->assertTrue(bridgeStoreHealth($ctx['airport_id'], $ctx['bridge_id'], $normalized['health']));

        $stored = bridgeLoadHealth($ctx['airport_id'], $ctx['bridge_id']);
        $this->assertNotNull($stored);
        $this->assertSame('station-scappoose-davis', $stored['inventory']['stations'][0]['id'] ?? null);
        $this->assertSame('bridge-spb-test-1', $stored['bridge_id'] ?? null);
    }

    public function testResolveFixtureKey(): void
    {
        $binding = resolveBridgeApiKey(self::FIXTURE_KEY);
        $this->assertNotNull($binding);
        $this->assertSame('kspb', $binding['airport_id']);
        $this->assertSame('bridge-spb-test-1', $binding['bridge_id']);
    }
}
