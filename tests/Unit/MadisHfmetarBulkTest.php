<?php
/**
 * Unit Tests for the MADIS HFMETAR bulk refresh worker
 *
 * Uses the committed one-hour sample file (tests/Fixtures/madis/hfmetar-sample.gz)
 * as the "downloaded" netCDF input and a disposable cache root so no real
 * cache or network is touched.
 *
 * @package AviationWX\Tests\Unit
 */

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../lib/cache-paths.php';
require_once __DIR__ . '/../../lib/madis-hfmetar/bulk.php';

class MadisHfmetarBulkTest extends TestCase
{
    private string $cacheRoot;

    private string $fixturePath;

    protected function setUp(): void
    {
        parent::setUp();
        $this->cacheRoot = sys_get_temp_dir() . '/madis_hfmetar_test_' . uniqid('', true);
        $GLOBALS['madisHfmetarTestCacheRoot'] = $this->cacheRoot;
        $this->fixturePath = __DIR__ . '/../Fixtures/madis/hfmetar-sample.gz';
        $this->assertFileExists($this->fixturePath);
    }

    protected function tearDown(): void
    {
        unset($GLOBALS['madisHfmetarTestCacheRoot']);
        $this->removeTree($this->cacheRoot);
        parent::tearDown();
    }

    private function removeTree(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        foreach (scandir($dir) as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $path = $dir . '/' . $entry;
            if (is_dir($path)) {
                $this->removeTree($path);
            } else {
                @unlink($path);
            }
        }
        @rmdir($dir);
    }

    /**
     * Current-hour file URL matches the MADIS naming pattern
     */
    public function testMadisHfmetarBulk_CurrentHourFileUrl_Format(): void
    {
        $url = madisHfmetarBulkCurrentHourFileUrl(gmmktime(23, 0, 0, 11, 20, 2026));
        $this->assertStringContainsString('LDAD/hfmetar/netCDF/20261120_2300.gz', $url);
    }

    /**
     * Ingesting the sample file writes one slice per configured station
     */
    public function testMadisHfmetarBulk_IngestSampleFile_WritesSlices(): void
    {
        $result = madisHfmetarBulkIngestFileToStationSlices($this->fixturePath, ['KSPB' => true, 'KMMV' => true], '20261120_23');
        $this->assertArrayNotHasKey('error', $result);
        $this->assertSame(2, $result['written']);

        $kspb = json_decode((string) file_get_contents(getMadisHfmetarStationSlicePath('KSPB')), true);
        $this->assertIsArray($kspb);
        $this->assertSame('KSPB', $kspb['station_id']);
        $this->assertSame('20261120_23', $kspb['file_hour']);
        $this->assertCount(12, $kspb['observations']);
        $this->assertSame(2200, $kspb['observations'][0]['ceiling_ft']);
    }

    /**
     * A fresh station slice is returned as JSON
     */
    public function testMadisHfmetarBulk_TryReadStationSlice_Fresh_ReturnsJson(): void
    {
        madisHfmetarBulkIngestFileToStationSlices($this->fixturePath, ['KSPB' => true], '20261120_23');
        $json = madisHfmetarTryReadStationSlice('kspb');
        $this->assertNotNull($json);
        $decoded = json_decode($json, true);
        $this->assertSame('KSPB', $decoded['station_id']);
    }

    /**
     * A stale station slice is ignored so HTTP fallback can serve
     */
    public function testMadisHfmetarBulk_TryReadStationSlice_Stale_ReturnsNull(): void
    {
        madisHfmetarBulkIngestFileToStationSlices($this->fixturePath, ['KSPB' => true], '20261120_23');
        $path = getMadisHfmetarStationSlicePath('KSPB');
        // Older than MADIS_HFMETAR_STATION_FILE_MAX_AGE_SECONDS
        $this->assertTrue(@touch($path, time() - 1000));
        $this->assertNull(madisHfmetarTryReadStationSlice('KSPB'));
    }

    /**
     * A missing station slice returns null
     */
    public function testMadisHfmetarBulk_TryReadStationSlice_Missing_ReturnsNull(): void
    {
        $this->assertNull(madisHfmetarTryReadStationSlice('KZZZ'));
    }

    /**
     * Collect only configured madis_hfmetar station ids from enabled airports
     */
    public function testMadisHfmetarBulk_CollectConfiguredStationIds_ReturnsMadisSources(): void
    {
        $config = [
            'airports' => [
                'kspb' => [
                    'id' => 'kspb',
                    'icao' => 'KSPB',
                    'enabled' => true,
                    'weather_sources' => [
                        ['type' => 'madis_hfmetar', 'station_id' => 'KSPB'],
                        ['type' => 'metar', 'station_id' => 'KSPB'],
                    ],
                ],
                'disabled' => [
                    'id' => 'disabled',
                    'icao' => 'KZZZ',
                    'enabled' => false,
                    'weather_sources' => [
                        ['type' => 'madis_hfmetar', 'station_id' => 'KZZZ'],
                    ],
                ],
            ],
        ];
        $ids = madisHfmetarBulkCollectConfiguredStationIds($config);
        $this->assertSame(['KSPB' => true], $ids);
    }

    /**
     * Orphan slices for unconfigured stations are pruned
     */
    public function testMadisHfmetarBulk_PruneOrphan_RemovesUnconfiguredStations(): void
    {
        madisHfmetarBulkIngestFileToStationSlices($this->fixturePath, ['KSPB' => true, 'KMMV' => true], '20261120_23');
        $this->assertFileExists(getMadisHfmetarStationSlicePath('KMMV'));
        $pruned = madisHfmetarBulkPruneOrphanStationSlices(['KSPB' => true]);
        $this->assertSame(1, $pruned);
        $this->assertFileDoesNotExist(getMadisHfmetarStationSlicePath('KMMV'));
        $this->assertFileExists(getMadisHfmetarStationSlicePath('KSPB'));
    }

    /**
     * Refresh run skips in mock/test mode without touching the network
     */
    public function testMadisHfmetarBulk_RefreshRun_MockMode_Skips(): void
    {
        $result = madisHfmetarBulkRefreshRun();
        $this->assertTrue($result['ok']);
        $this->assertSame('skipped_mock_or_test_mode', $result['note']);
    }
}
