<?php

declare(strict_types=1);

namespace AviationWX\Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * UnifiedFetcher MADIS path uses mock/bulk resolution instead of curl_multi,
 * mirroring the METAR source.
 */
final class FetchAllSourcesMadisTest extends TestCase
{
    private ?string $madisCacheRoot = null;

    protected function setUp(): void
    {
        parent::setUp();
        require_once __DIR__ . '/../../lib/test-mocks.php';
        require_once __DIR__ . '/../../lib/weather/adapter/madis-hfmetar-v1.php';
        require_once __DIR__ . '/../../lib/weather/UnifiedFetcher.php';
        madisHfmetarTestSetSkipMock(false);
    }

    protected function tearDown(): void
    {
        madisHfmetarTestSetSkipMock(false);
        unset($GLOBALS['madisHfmetarTestCacheRoot']);
        if ($this->madisCacheRoot !== null && is_dir($this->madisCacheRoot)) {
            $files = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($this->madisCacheRoot, \FilesystemIterator::SKIP_DOTS),
                \RecursiveIteratorIterator::CHILD_FIRST
            );
            foreach ($files as $file) {
                if ($file->isDir()) {
                    rmdir($file->getPathname());
                } else {
                    unlink($file->getPathname());
                }
            }
            rmdir($this->madisCacheRoot);
        }
        parent::tearDown();
    }

    /**
     * In test mode the MADIS mock supplies the sample file without curl
     */
    public function testFetchAllSources_MadisSource_UsesMockWithoutCurl(): void
    {
        $sources = [
            'source_0' => [
                'type' => 'madis_hfmetar',
                'station_id' => 'KSPB',
            ],
        ];
        $result = fetchAllSources($sources, 'kspb');
        $body = $result['responses']['source_0'] ?? null;
        $this->assertIsString($body);
        // The mock body is gzipped netCDF, not a JSON slice.
        $this->assertNotSame('{', $body[0] ?? '');
    }

    /**
     * parseSourceResponse turns the netCDF fixture into a valid snapshot
     */
    public function testParseSourceResponse_MadisNetcdf_ReturnsSnapshot(): void
    {
        $fixture = (string) file_get_contents(__DIR__ . '/../Fixtures/madis/hfmetar-sample.gz');
        $snapshot = parseSourceResponse(['type' => 'madis_hfmetar', 'station_id' => 'kspb'], $fixture, []);
        $this->assertNotNull($snapshot);
        $this->assertTrue($snapshot->isValid);
        $this->assertSame('madis_hfmetar', $snapshot->source);
        $this->assertSame('KSPB', $snapshot->stationId);
    }

    /**
     * parseSourceResponse accepts a bulk JSON slice body
     */
    public function testParseSourceResponse_MadisJsonSlice_ReturnsSnapshot(): void
    {
        $slice = [
            'station_id' => 'KSPB',
            'file_hour' => '20261120_23',
            'updated_at' => time(),
            'observations' => [
                [
                    'obs_time' => gmmktime(23, 0, 0, 11, 20, 2026),
                    'temperature_c' => 7.0,
                    'dewpoint_c' => 3.0,
                    'altimeter_inhg' => 30.11,
                    'wind_direction_deg' => 0,
                    'wind_speed_kts' => 0.0,
                    'gust_speed_kts' => null,
                    'visibility_sm' => 10.0,
                    'ceiling_ft' => 2200,
                    'cloud_cover' => 'OVC',
                ],
            ],
        ];
        $snapshot = parseSourceResponse(['type' => 'madis_hfmetar', 'station_id' => 'kspb'], json_encode($slice), []);
        $this->assertNotNull($snapshot);
        $this->assertTrue($snapshot->isValid);
        $this->assertEqualsWithDelta(2200.0, $snapshot->ceiling->value, 0.01);
        $this->assertSame('OVC', $snapshot->cloudCover->value);
    }

    /**
     * A fresh bulk slice is used instead of the HTTP mock when the mock is skipped
     */
    public function testFetchAllSources_MadisSource_PrefersBulkSliceOverHttp(): void
    {
        require_once __DIR__ . '/../../lib/cache-paths.php';
        require_once __DIR__ . '/../../lib/madis-hfmetar/bulk.php';

        madisHfmetarTestSetSkipMock(true);
        $this->madisCacheRoot = sys_get_temp_dir() . '/madis_fetch_test_' . uniqid('', true);
        $GLOBALS['madisHfmetarTestCacheRoot'] = $this->madisCacheRoot;
        madisHfmetarBulkEnsureDirectories();

        $slice = [
            'station_id' => 'KSPB',
            'file_hour' => '20261120_23',
            'updated_at' => time(),
            'observations' => [
                [
                    'obs_time' => gmmktime(23, 55, 0, 11, 20, 2026),
                    'temperature_c' => 7.0,
                    'dewpoint_c' => 3.0,
                    'altimeter_inhg' => 30.12,
                    'wind_direction_deg' => 0,
                    'wind_speed_kts' => 0.0,
                    'gust_speed_kts' => null,
                    'visibility_sm' => 10.0,
                    'ceiling_ft' => 2300,
                    'cloud_cover' => 'OVC',
                ],
            ],
        ];
        file_put_contents(getMadisHfmetarStationSlicePath('KSPB'), json_encode($slice));

        $sources = [
            'source_0' => [
                'type' => 'madis_hfmetar',
                'station_id' => 'KSPB',
            ],
        ];
        $result = fetchAllSources($sources, 'kspb');
        $body = $result['responses']['source_0'] ?? null;
        $this->assertIsString($body);
        // The slice JSON is used as the response body.
        $this->assertStringContainsString('"observations"', $body);
    }
}
