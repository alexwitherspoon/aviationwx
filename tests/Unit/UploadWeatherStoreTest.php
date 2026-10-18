<?php
/**
 * Unit tests for WiFiLogger upload weather storage (latest + observations + registration).
 */

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../lib/upload/store.php';
require_once __DIR__ . '/../../lib/cache-paths.php';

class UploadWeatherStoreTest extends TestCase
{
    private string $tmpRoot;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tmpRoot = sys_get_temp_dir() . '/aviationwx_upload_wx_' . uniqid('', true);
        mkdir($this->tmpRoot, 0755, true);
        $GLOBALS['uploadTestCacheRoot'] = $this->tmpRoot;
    }

    protected function tearDown(): void
    {
        unset($GLOBALS['uploadTestCacheRoot']);
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

    private function rawObservation(string $wid = 'station-davis-01', int $ts = 1531754005): array
    {
        return [
            'observed_at' => gmdate('c', $ts),
            'observed_unix' => $ts,
            'source_id' => $wid,
            'provider' => 'wifilogger',
            'provider_meta' => [
                'raw' => [
                    'type' => 'rtd',
                    'wid' => $wid,
                    'tutc' => $ts,
                    'tempout' => 67.8,
                ],
            ],
        ];
    }

    public function testCachePaths_ReturnExpectedSuffixes(): void
    {
        $latest = getUploadWeatherLatestCachePath('kspb', 'station-davis-01');
        $samples = getUploadWeatherSamplesCachePath('kspb', 'station-davis-01');
        $registration = getUploadRegistrationCachePath('kspb', 'station-davis-01');
        $this->assertStringEndsWith('/kspb/station-davis-01/latest.json', $latest);
        $this->assertStringEndsWith('/kspb/station-davis-01/samples.jsonl', $samples);
        $this->assertStringEndsWith('/kspb/station-davis-01/registration.json', $registration);
    }

    public function testCachePaths_RejectsUnsafeSegments(): void
    {
        $this->assertSame('', getUploadWeatherLatestCachePath('../etc', 'station-davis-01'));
        $this->assertSame('', getUploadWeatherLatestCachePath('kspb', 'a/../b'));
    }

    public function testStoreWeatherObservation_WritesLatestAndAppendsRing(): void
    {
        $record = $this->rawObservation();
        $this->assertTrue(uploadStoreWeatherObservation('kspb', 'station-davis-01', $record, 1531754006));

        $latest = uploadLoadWeatherLatest('kspb', 'station-davis-01');
        $this->assertNotNull($latest);
        $this->assertSame('wifilogger', $latest['provider']);
        $this->assertSame('kspb', $latest['airport_id']);
        $this->assertSame(1531754005, $latest['observed_unix']);

        $samplesPath = getUploadWeatherSamplesCachePath('kspb', 'station-davis-01');
        $lines = file($samplesPath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [];
        $this->assertCount(1, $lines);
    }

    public function testStoreWeatherObservation_FirstWinsAtEqualObservedUnix(): void
    {
        $this->assertTrue(uploadStoreWeatherObservation('kspb', 'station-davis-01', $this->rawObservation('station-davis-01', 1531754005)));

        $older = $this->rawObservation('station-davis-01', 1531754000);
        $older['provider_meta']['raw']['tempout'] = 1.0;
        $this->assertTrue(uploadStoreWeatherObservation('kspb', 'station-davis-01', $older, 1531754006));

        $latest = uploadLoadWeatherLatest('kspb', 'station-davis-01');
        $this->assertSame(1531754005, $latest['observed_unix']);
        $this->assertSame(67.8, $latest['provider_meta']['raw']['tempout']);
    }

    public function testStoreRegistration_WriteAndReload(): void
    {
        $registration = [
            'type' => 'registration',
            'wid' => 'station-davis-01',
            'mac' => 'aa:bb:cc:dd:ee:ff',
            'model' => 'VANTAGE-VUE',
            'firmware' => '2.63',
        ];
        $this->assertTrue(uploadStoreRegistration('kspb', 'station-davis-01', $registration));

        $loaded = uploadLoadRegistration('kspb', 'station-davis-01');
        $this->assertSame('aa:bb:cc:dd:ee:ff', $loaded['mac']);
        $this->assertSame('VANTAGE-VUE', $loaded['model']);
    }
}