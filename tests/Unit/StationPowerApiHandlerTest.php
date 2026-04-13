<?php
/**
 * Unit tests for station power JSON API handler (no HTTP; no rate limit).
 */

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../lib/config.php';
require_once __DIR__ . '/../../lib/cache-paths.php';
require_once __DIR__ . '/../../lib/station-power/station-power-api-handler.php';

final class StationPowerApiHandlerTest extends TestCase
{
    private ?string $cacheFile = null;

    protected function setUp(): void
    {
        parent::setUp();
        if (function_exists('apcu_clear_cache')) {
            @apcu_clear_cache();
        }
        clearConfigCache();
        loadConfig(false);
    }

    protected function tearDown(): void
    {
        if ($this->cacheFile !== null && is_file($this->cacheFile)) {
            @unlink($this->cacheFile);
            $this->cacheFile = null;
        }
        parent::tearDown();
    }

    public function testBuildResponse_MissingAirport_Returns400(): void
    {
        [$code, $body] = stationPowerApiBuildResponse('');
        $this->assertSame(400, $code);
        $this->assertFalse($body['success']);
    }

    public function testBuildResponse_Kspb_NotConfigured_Returns404(): void
    {
        [$code, $body] = stationPowerApiBuildResponse('kspb');
        $this->assertSame(404, $code);
        $this->assertFalse($body['success']);
    }

    public function testBuildResponse_Spfx_WithDisplayableCache_Returns200(): void
    {
        if (!is_dir(CACHE_STATION_POWER_DIR)) {
            @mkdir(CACHE_STATION_POWER_DIR, 0755, true);
        }
        $this->cacheFile = getStationPowerCachePath('spfx');
        $now = time();
        $payload = [
            'provider' => 'vrm',
            'fetched_at' => $now,
            'sample_time_ms' => $now * 1000,
            'battery_soc_percent' => 50.0,
            'load_watts' => 100,
            'solar_total_watts' => 200,
        ];
        $this->assertNotFalse(file_put_contents($this->cacheFile, json_encode($payload)));

        [$code, $body] = stationPowerApiBuildResponse('spfx');
        $this->assertSame(200, $code);
        $this->assertTrue($body['success']);
        $this->assertTrue($body['displayable']);
        $this->assertIsArray($body['station_power']);
        $this->assertSame(200, $body['station_power']['solar_total_watts'] ?? null);
        $this->assertArrayHasKey('poll_interval_seconds', $body);
        $this->assertGreaterThanOrEqual(60, (int) $body['poll_interval_seconds']);
    }

    public function testBuildResponse_Spfx_NoCache_DisplayableFalse(): void
    {
        $path = getStationPowerCachePath('spfx');
        if (is_file($path)) {
            @unlink($path);
        }

        [$code, $body] = stationPowerApiBuildResponse('spfx');
        $this->assertSame(200, $code);
        $this->assertTrue($body['success']);
        $this->assertFalse($body['displayable']);
        $this->assertNull($body['station_power']);
    }

    public function testEncodeJson_RoundTrip(): void
    {
        $json = stationPowerApiEncodeJson(['success' => true, 'x' => 1]);
        $this->assertIsString($json);
        $this->assertStringContainsString('"success":true', $json);
    }
}
