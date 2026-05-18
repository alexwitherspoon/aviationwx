<?php

declare(strict_types=1);

namespace AviationWX\Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Unit tests for `lib/metar-bulk.php` (CSV mapping, gzip ingest, slice age gate).
 */
final class MetarBulkTest extends TestCase
{
    /**
     * @return array<int, string>
     */
    private function csvHeaderColumns(): array
    {
        return [
            'raw_text',
            'station_id',
            'observation_time',
            'latitude',
            'longitude',
            'temp_c',
            'dewpoint_c',
            'wind_dir_degrees',
            'wind_speed_kt',
            'wind_gust_kt',
            'visibility_statute_mi',
            'altim_in_hg',
            'sea_level_pressure_mb',
            'corrected',
            'auto',
            'auto_station',
            'maintenance_indicator_on',
            'no_signal',
            'lightning_sensor_off',
            'freezing_rain_sensor_off',
            'present_weather_sensor_off',
            'wx_string',
            'sky_cover',
            'cloud_base_ft_agl',
            'sky_cover',
            'cloud_base_ft_agl',
            'sky_cover',
            'cloud_base_ft_agl',
            'sky_cover',
            'cloud_base_ft_agl',
            'flight_category',
            'three_hr_pressure_tendency_mb',
            'maxT_c',
            'minT_c',
            'maxT24hr_c',
            'minT24hr_c',
            'precip_in',
            'pcp3hr_in',
            'pcp6hr_in',
            'pcp24hr_in',
            'snow_in',
            'vert_vis_ft',
            'metar_type',
            'elevation_m',
        ];
    }

    /**
     * @param array<int, string> $values
     */
    private function csvLineFromValues(array $values): string
    {
        $fh = fopen('php://memory', 'r+b');
        if ($fh === false) {
            $this->fail('fopen php://memory failed');
        }
        fputcsv($fh, $values, ',', '"', '\\');
        rewind($fh);
        $s = stream_get_contents($fh);
        fclose($fh);

        return rtrim((string) $s, "\r\n");
    }

    public function testRefreshMetarBulkScriptExists(): void
    {
        $this->assertFileExists(__DIR__ . '/../../scripts/refresh-metar-bulk.php');
    }

    public function testMetarBulkSanitizeIcaoForFilename(): void
    {
        require_once __DIR__ . '/../../lib/metar-bulk.php';
        $this->assertSame('KPDX', metarBulkSanitizeIcaoForFilename('kpdx'));
        $this->assertNull(metarBulkSanitizeIcaoForFilename('../etc'));
        $this->assertNull(metarBulkSanitizeIcaoForFilename('AB'));
    }

    public function testMetarBulkParseObservationTime(): void
    {
        require_once __DIR__ . '/../../lib/metar-bulk.php';
        $this->assertSame(
            strtotime('2026-05-18T15:00:00.000Z'),
            metarBulkParseObservationTime('2026-05-18T15:00:00.000Z')
        );
        $this->assertSame(PHP_INT_MIN, metarBulkParseObservationTime(''));
    }

    public function testCsvRowToJsonEnvelopeParsesThroughParseMetarResponse(): void
    {
        require_once __DIR__ . '/../../lib/metar-bulk.php';
        require_once __DIR__ . '/../../lib/weather/adapter/metar-v1.php';

        $row = array_fill(0, 44, '');
        $row[0] = 'METAR KZZZ 181448Z 16011G17KT 10SM SCT060 BKN070 25/25 A2993';
        $row[1] = 'KZZZ';
        $row[2] = '2026-05-18T14:48:00.000Z';
        $row[5] = '25';
        $row[6] = '25';
        $row[7] = '160';
        $row[8] = '11';
        $row[9] = '17';
        $row[10] = '10+';
        $row[11] = '29.93';
        $row[22] = 'SCT';
        $row[23] = '6000';
        $row[24] = 'BKN';
        $row[25] = '7000';

        $json = metarBulkCsvRowToJsonEnvelope($row);
        $this->assertNotNull($json);
        $airport = ['icao' => 'KZZZ'];
        $parsed = parseMETARResponse((string) $json, $airport);
        $this->assertNotNull($parsed);
        $this->assertEqualsWithDelta(25.0, (float) $parsed['temperature'], 0.001);
        $this->assertSame(11, $parsed['wind_speed']);
        $this->assertSame(17, $parsed['gust_speed']);
        $this->assertStringContainsString('KZZZ', (string) $parsed['raw_ob']);
    }

    public function testIngestKeepsLatestObservationPerStation(): void
    {
        require_once __DIR__ . '/../../lib/cache-paths.php';
        require_once __DIR__ . '/../../lib/metar-bulk.php';

        metarBulkEnsureDirectories();
        $stationsDir = getMetarBulkStationsDir();
        $oldFile = $stationsDir . '/KZZZ.json';
        if (is_file($oldFile)) {
            @unlink($oldFile);
        }

        $header = $this->csvLineFromValues($this->csvHeaderColumns());

        $older = array_fill(0, 44, '');
        $older[0] = 'METAR KZZZ 181200Z AUTO 09007KT 10SM CLR 05/03 A3000';
        $older[1] = 'KZZZ';
        $older[2] = '2026-05-18T12:00:00.000Z';
        $older[5] = '5';
        $older[6] = '3';
        $older[7] = '90';
        $older[8] = '7';
        $older[10] = '10';
        $older[11] = '30.00';

        $newer = array_fill(0, 44, '');
        $newer[0] = 'METAR KZZZ 181500Z AUTO 18015KT 10SM CLR 15/10 A2990';
        $newer[1] = 'KZZZ';
        $newer[2] = '2026-05-18T15:00:00.000Z';
        $newer[5] = '15';
        $newer[6] = '10';
        $newer[7] = '180';
        $newer[8] = '15';
        $newer[10] = '10';
        $newer[11] = '29.90';

        $csvBody = $header . "\n"
            . $this->csvLineFromValues($older) . "\n"
            . $this->csvLineFromValues($newer) . "\n";
        $gzPath = sys_get_temp_dir() . '/metar_bulk_test_' . uniqid('', true) . '.gz';
        $gzData = gzencode($csvBody, 9);
        $this->assertNotFalse($gzData);
        file_put_contents($gzPath, $gzData);

        $stats = metarBulkIngestGzipToStationFiles($gzPath, ['KZZZ' => true]);
        @unlink($gzPath);

        $this->assertArrayNotHasKey('error', $stats);
        $this->assertSame(1, $stats['written']);
        $this->assertSame(2, $stats['scanned']);

        $written = (string) file_get_contents($oldFile);
        $this->assertStringContainsString('181500Z', $written);
        $this->assertStringNotContainsString('181200Z', $written);
        @unlink($oldFile);
    }

    public function testTryReadJsonResponseForStationRejectsStaleFile(): void
    {
        require_once __DIR__ . '/../../lib/cache-paths.php';
        require_once __DIR__ . '/../../lib/constants.php';
        require_once __DIR__ . '/../../lib/metar-bulk.php';

        metarBulkEnsureDirectories();
        $path = getMetarBulkStationJsonPath('KZZZ');
        if (is_file($path)) {
            @unlink($path);
        }
        file_put_contents($path, '[]');
        touch($path, time() - METAR_BULK_STATION_FILE_MAX_AGE_SECONDS - 60);

        $this->assertNull(metarBulkTryReadJsonResponseForStation('KZZZ'));

        touch($path, time());
        $body = metarBulkTryReadJsonResponseForStation('KZZZ');
        $this->assertSame('[]', $body);
        @unlink($path);
    }
}
