<?php
/**
 * DyaconLive upstream skip and UnifiedFetcher integration tests.
 */

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../lib/config.php';
require_once __DIR__ . '/../../lib/weather/UnifiedFetcher.php';
require_once __DIR__ . '/../../lib/weather/dyaconlive-state.php';
require_once __DIR__ . '/../../lib/weather/adapter/dyaconlive-v1.php';
require_once __DIR__ . '/../mock-weather-responses.php';

class DyaconLiveFetchSkipTest extends TestCase
{
    private ?string $stateDir = null;

    protected function setUp(): void
    {
        $this->stateDir = sys_get_temp_dir() . '/dyaconlive-fetch-' . getmypid();
        mkdir($this->stateDir, 0755, true);
        $GLOBALS['dyaconliveTestStateDir'] = $this->stateDir;
        $GLOBALS['dyaconliveTestBearerToken'] = 'test_dyaconlive_bearer_token';
    }

    protected function tearDown(): void
    {
        unset($GLOBALS['dyaconliveTestStateDir'], $GLOBALS['dyaconliveTestBearerToken'], $GLOBALS['dyaconliveTestNowUnix']);
        if ($this->stateDir !== null && is_dir($this->stateDir)) {
            foreach (glob($this->stateDir . '/*') ?: [] as $file) {
                @unlink($file);
            }
            @rmdir($this->stateDir);
        }
    }

    public function testFetchAllSources_WithCurrentBucketState_SkipsHttpResponse(): void
    {
        $airport = [
            'icao' => 'KAOC',
            'timezone' => 'America/Boise',
            'weather_sources' => [
                [
                    'type' => 'dyaconlive',
                    'station_id' => 130114,
                    'username' => 'test@example.com',
                    'password' => 'secret',
                ],
            ],
        ];
        $source = $airport['weather_sources'][0];
        $response = getMockDyaconLiveDataResponse();
        $snapshot = DyaconLiveAdapter::parseToSnapshot($response, [
            'station_id' => 130114,
            'timezone' => 'America/Boise',
        ]);
        $this->assertNotNull($snapshot);
        $lastIso = DyaconLiveAdapter::extractLastBucketIso($response, [
            'timezone' => 'America/Boise',
        ]);
        $this->assertNotNull($lastIso);
        $bucketUnix = dyaconliveParseBucketIsoToUnix($lastIso, 'America/Boise');
        $this->assertNotNull($bucketUnix);

        dyaconliveWriteSourceState(
            getDyaconLiveSourceStatePath('kaoc', 0),
            $bucketUnix,
            $lastIso,
            $snapshot
        );

        $GLOBALS['dyaconliveTestNowUnix'] = dyaconliveParseBucketIsoToUnix('2026-07-07T09:47:00', 'America/Boise');

        $sources = ['source_0' => $source];
        $result = fetchAllSources($sources, 'kaoc', $airport);
        $this->assertArrayHasKey('source_0', $result['skipped_snapshots']);
        $this->assertArrayNotHasKey('source_0', $result['responses']);
        $this->assertTrue($result['skipped_snapshots']['source_0']->isValid);
    }

    public function testFetchAllSources_WithoutState_FetchesMockResponse(): void
    {
        $airport = [
            'icao' => 'KAOC',
            'timezone' => 'America/Boise',
        ];
        $source = [
            'type' => 'dyaconlive',
            'station_id' => 130114,
            'username' => 'test@example.com',
            'password' => 'secret',
        ];
        $sources = ['source_0' => $source];
        $result = fetchAllSources($sources, 'kaoc', $airport);
        $this->assertArrayHasKey('source_0', $result['responses']);
        $this->assertIsString($result['responses']['source_0']);
        $this->assertStringContainsString('air_temp', $result['responses']['source_0']);
    }

    public function testFetchWeatherUnified_DyaconSource_ProducesTemperature(): void
    {
        $airport = [
            'icao' => 'KAOC',
            'timezone' => 'America/Boise',
            'elevation_ft' => 5335,
            'lat' => 43.6035278,
            'lon' => -113.33425,
            'weather_sources' => [
                [
                    'type' => 'dyaconlive',
                    'station_id' => 130114,
                    'username' => 'test@example.com',
                    'password' => 'secret',
                ],
            ],
        ];
        $result = fetchWeatherUnified($airport, 'kaoc');
        $this->assertNotNull($result['temperature'] ?? null);
        $this->assertGreaterThanOrEqual(1, $result['_sources_succeeded'] ?? 0);
    }
}
