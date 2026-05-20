<?php

declare(strict_types=1);

namespace AviationWX\Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Global weather circuit breaker keys shared across airports for one credential.
 */
final class GlobalWeatherCircuitBreakerTest extends TestCase
{
    private string $backoffFile;

    private const AIRPORT_A = 'global_breaker_test_a';

    private const AIRPORT_B = 'global_breaker_test_b';

    protected function setUp(): void
    {
        parent::setUp();
        require_once __DIR__ . '/../../lib/cache-paths.php';
        require_once __DIR__ . '/../../lib/constants.php';
        require_once __DIR__ . '/../../lib/upstream-rate-limit.php';
        require_once __DIR__ . '/../../lib/circuit-breaker.php';

        $this->backoffFile = CACHE_BACKOFF_FILE;
        ensureCacheDir(dirname($this->backoffFile));
        $this->cleanupKeys();
    }

    protected function tearDown(): void
    {
        $this->cleanupKeys();
        parent::tearDown();
    }

    public function testGlobalKey_SameTempestApiKey_ReturnsSameKey(): void
    {
        $sourceA = ['type' => 'tempest', 'api_key' => 'shared-key', 'station_id' => '1'];
        $sourceB = ['type' => 'tempest', 'api_key' => 'shared-key', 'station_id' => '2'];

        $this->assertSame(
            weatherGlobalCircuitBreakerKey('tempest', $sourceA),
            weatherGlobalCircuitBreakerKey('tempest', $sourceB)
        );
    }

    public function testCheckWeatherCircuitBreaker_GlobalOpenDifferentAirport_Skips(): void
    {
        $source = ['type' => 'tempest', 'api_key' => 'global-test-key', 'station_id' => '99'];

        for ($i = 0; $i < CIRCUIT_BREAKER_FAILURE_THRESHOLD; $i++) {
            recordWeatherFailure(self::AIRPORT_A, 'tempest', 'transient', 429, null, $source);
        }

        $result = checkWeatherCircuitBreaker(self::AIRPORT_B, 'tempest', $source);
        $this->assertTrue($result['skip']);
        $this->assertSame('global_circuit_open', $result['reason']);
    }

    public function testCheckWeatherCircuitBreaker_GlobalNotOpen_ReturnsPerAirportStats(): void
    {
        $source = ['type' => 'tempest', 'api_key' => 'stats-key', 'station_id' => '1'];

        recordWeatherFailure(self::AIRPORT_B, 'tempest', 'transient', HTTP_STATUS_SERVICE_UNAVAILABLE, null, $source);

        $result = checkWeatherCircuitBreaker(self::AIRPORT_B, 'tempest', $source);
        $this->assertFalse($result['skip']);
        $this->assertSame(1, $result['failures']);
    }

    public function testRecordWeatherSuccess_GlobalKey_ClearsSharedBackoff(): void
    {
        $source = ['type' => 'tempest', 'api_key' => 'clear-global-key', 'station_id' => '1'];

        for ($i = 0; $i < CIRCUIT_BREAKER_FAILURE_THRESHOLD; $i++) {
            recordWeatherFailure(self::AIRPORT_A, 'tempest', 'transient', 429, null, $source);
        }

        $blocked = checkWeatherCircuitBreaker(self::AIRPORT_B, 'tempest', $source);
        $this->assertTrue($blocked['skip']);

        recordWeatherSuccess(self::AIRPORT_A, 'tempest', $source);

        $allowed = checkWeatherCircuitBreaker(self::AIRPORT_B, 'tempest', $source);
        $this->assertFalse($allowed['skip']);
    }

    public function testRecordWeatherFailure_NonCoordinatingHttpCode_DoesNotOpenGlobalOnly(): void
    {
        $source = ['type' => 'tempest', 'api_key' => '404-only-key', 'station_id' => '1'];

        for ($i = 0; $i < CIRCUIT_BREAKER_FAILURE_THRESHOLD; $i++) {
            recordWeatherFailure(self::AIRPORT_A, 'tempest', 'transient', 404, null, $source);
        }

        $globalKey = weatherGlobalCircuitBreakerKey('tempest', $source);
        $data = $this->readBackoffData();
        $this->assertArrayNotHasKey($globalKey, $data);

        $perAirport = checkWeatherCircuitBreaker(self::AIRPORT_B, 'tempest', $source);
        $this->assertFalse($perAirport['skip']);
    }

    public function testRecordWeatherFailure_Http503_RecordsGlobalKey(): void
    {
        $source = ['type' => 'tempest', 'api_key' => '503-key', 'station_id' => '1'];

        recordWeatherFailure(self::AIRPORT_A, 'tempest', 'transient', HTTP_STATUS_SERVICE_UNAVAILABLE, null, $source);

        $globalKey = weatherGlobalCircuitBreakerKey('tempest', $source);
        $data = $this->readBackoffData();
        $this->assertArrayHasKey($globalKey, $data);
    }

    private function readBackoffData(): array
    {
        if (!is_file($this->backoffFile)) {
            return [];
        }

        return json_decode((string) file_get_contents($this->backoffFile), true) ?: [];
    }

    private function cleanupKeys(): void
    {
        if (!is_file($this->backoffFile)) {
            return;
        }

        $data = $this->readBackoffData();
        $prefixes = [
            self::AIRPORT_A . '_weather_',
            self::AIRPORT_B . '_weather_',
            'global_weather_tempest_',
        ];
        $changed = false;
        foreach (array_keys($data) as $key) {
            foreach ($prefixes as $prefix) {
                if (str_starts_with($key, $prefix)) {
                    unset($data[$key]);
                    $changed = true;
                    break;
                }
            }
        }
        if ($changed) {
            file_put_contents($this->backoffFile, json_encode($data, JSON_PRETTY_PRINT), LOCK_EX);
        }
    }
}
