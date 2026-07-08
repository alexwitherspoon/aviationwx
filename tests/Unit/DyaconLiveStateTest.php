<?php
/**
 * DyaconLive per-source state and upstream skip reuse tests.
 */

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../lib/config.php';
require_once __DIR__ . '/../../lib/weather/dyaconlive-state.php';
require_once __DIR__ . '/../../lib/weather/adapter/dyaconlive-v1.php';
require_once __DIR__ . '/../mock-weather-responses.php';

class DyaconLiveStateTest extends TestCase
{
    private string $stateDir;

    protected function setUp(): void
    {
        $this->stateDir = sys_get_temp_dir() . '/dyaconlive-state-test-' . getmypid();
        mkdir($this->stateDir, 0755, true);
    }

    protected function tearDown(): void
    {
        $files = glob($this->stateDir . '/*') ?: [];
        foreach ($files as $file) {
            @unlink($file);
        }
        @rmdir($this->stateDir);
    }

    public function testStateRoundTrip_PersistsBucketAndSnapshot(): void
    {
        $path = $this->stateDir . '/kaoc_0.json';
        $response = getMockDyaconLiveDataResponse();
        $snapshot = DyaconLiveAdapter::parseToSnapshot($response, [
            'timezone' => 'America/Boise',
            'elevation_ft' => 5335,
        ]);
        $this->assertNotNull($snapshot);

        dyaconliveWriteSourceState($path, 1720364400, '2026-07-07T09:40:00', $snapshot);
        $loaded = dyaconliveReadSourceState($path);
        $this->assertIsArray($loaded);
        $this->assertSame(1720364400, $loaded['last_bucket_unix']);
        $this->assertSame('2026-07-07T09:40:00', $loaded['last_bucket_iso']);
        $this->assertIsArray($loaded['snapshot']);
        $rebuilt = dyaconliveSnapshotFromStateArray($loaded['snapshot']);
        $this->assertNotNull($rebuilt);
        $this->assertTrue($rebuilt->isValid);
        $this->assertEqualsWithDelta(
            $snapshot->temperature->value,
            $rebuilt->temperature->value,
            0.001
        );
    }

    public function testReadSourceState_MissingFile_ReturnsNull(): void
    {
        $this->assertNull(dyaconliveReadSourceState($this->stateDir . '/missing.json'));
    }

    public function testSnapshotFromStateArray_InvalidWindDirection_UsesEmptyWind(): void
    {
        $response = getMockDyaconLiveDataResponse();
        $snapshot = DyaconLiveAdapter::parseToSnapshot($response, [
            'timezone' => 'America/Boise',
            'elevation_ft' => 5335,
        ]);
        $this->assertNotNull($snapshot);
        $state = dyaconliveSnapshotToStateArray($snapshot);
        $state['wind_direction'] = 'not-a-number';

        $rebuilt = dyaconliveSnapshotFromStateArray($state);
        $this->assertNotNull($rebuilt);
        $this->assertFalse($rebuilt->wind->speed->hasValue());
        $this->assertFalse($rebuilt->wind->direction->hasValue());
    }

    public function testReadSourceState_CorruptJson_ReturnsNull(): void
    {
        $path = $this->stateDir . '/bad.json';
        file_put_contents($path, '{not json');
        $this->assertNull(dyaconliveReadSourceState($path));
    }
}
