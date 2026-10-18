<?php
/**
 * Unit tests for WiFiLogger payload validation and normalization (upload ingest).
 */

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../lib/upload/ingest.php';

class UploadIngestTest extends TestCase
{
    private const NOW = 1531754005;

    private function binding(): array
    {
        return [
            'airport_id' => 'kspb',
            'source_id' => 'station-davis-01',
            'wid' => 'station-davis-01',
            'mac' => 'AA:BB:CC:DD:EE:FF',
        ];
    }

    private function rtdBody(int $tutc = self::NOW): array
    {
        return [
            'type' => 'rtd',
            'wid' => 'station-davis-01',
            'tutc' => $tutc,
            'tempout' => 67,
            'humout' => 55,
            'windspd' => 4,
            'winddir' => 210,
        ];
    }

    public function testPrepareRtd_BuildsStoreRecord(): void
    {
        $prepared = uploadPrepareWifiLoggerIngest($this->rtdBody(), $this->binding(), 'aa:bb:cc:dd:ee:ff', self::NOW);
        $this->assertTrue($prepared['ok']);
        $this->assertSame('rtd', $prepared['kind']);
        $record = $prepared['record'];
        $this->assertSame('wifilogger', $record['provider']);
        $this->assertSame('station-davis-01', $record['source_id']);
        $this->assertSame(self::NOW, $record['observed_unix']);
        $this->assertSame(gmdate('c', self::NOW), $record['observed_at']);
        $this->assertSame('rtd', $record['provider_meta']['raw']['type']);
        $this->assertSame(67, $record['provider_meta']['raw']['tempout']);
        $this->assertSame('AA:BB:CC:DD:EE:FF', $record['provider_meta']['mac']);
    }

    public function testPrepareRtd_AcceptsDashAndBareMac(): void
    {
        $this->assertTrue(uploadPrepareWifiLoggerIngest($this->rtdBody(), $this->binding(), 'aa-bb-cc-dd-ee-ff', self::NOW)['ok']);
        $this->assertTrue(uploadPrepareWifiLoggerIngest($this->rtdBody(), $this->binding(), 'AABBCCDDEEFF', self::NOW)['ok']);
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('rejectProvider')]
    public function testPrepareRtd_Rejects(string $field, array $body, ?string $mac, int $now, string $expectedCode): void
    {
        $prepared = uploadPrepareWifiLoggerIngest($body, $this->binding(), $mac, $now);
        $this->assertFalse($prepared['ok']);
        $this->assertSame($expectedCode, $prepared['code']);
    }

    public static function rejectProvider(): array
    {
        $now = self::NOW;
        return [
            'bad_type' => [
                'type', ['type' => 'other', 'wid' => 'station-davis-01', 'tutc' => $now],
                null, $now, UPLOAD_INGEST_ERROR_INVALID_REQUEST,
            ],
            'missing_wid' => [
                'wid', ['type' => 'rtd', 'tutc' => $now],
                'aa:bb:cc:dd:ee:ff', $now, UPLOAD_INGEST_ERROR_INVALID_REQUEST,
            ],
            'wid_mismatch' => [
                'wid', ['type' => 'rtd', 'wid' => 'other-station', 'tutc' => $now],
                'aa:bb:cc:dd:ee:ff', $now, UPLOAD_INGEST_ERROR_WID_MISMATCH,
            ],
            'missing_mac_header' => [
                'mac', ['type' => 'rtd', 'wid' => 'station-davis-01', 'tutc' => $now],
                null, $now, UPLOAD_INGEST_ERROR_MAC_MISMATCH,
            ],
            'mac_mismatch' => [
                'mac', ['type' => 'rtd', 'wid' => 'station-davis-01', 'tutc' => $now],
                '11:22:33:44:55:66', $now, UPLOAD_INGEST_ERROR_MAC_MISMATCH,
            ],
            'missing_tutc' => [
                'tutc', ['type' => 'rtd', 'wid' => 'station-davis-01'],
                'aa:bb:cc:dd:ee:ff', $now, UPLOAD_INGEST_ERROR_MISSING_STATION_TIME,
            ],
            'zero_tutc' => [
                'tutc', ['type' => 'rtd', 'wid' => 'station-davis-01', 'tutc' => 0],
                'aa:bb:cc:dd:ee:ff', $now, UPLOAD_INGEST_ERROR_MISSING_STATION_TIME,
            ],
            'future_tutc' => [
                'tutc', ['type' => 'rtd', 'wid' => 'station-davis-01', 'tutc' => $now + 120],
                'aa:bb:cc:dd:ee:ff', $now, UPLOAD_INGEST_ERROR_FUTURE_STATION_TIME,
            ],
        ];
    }

    public function testPrepareRegistration_BuildsRegistrationRecord(): void
    {
        $body = [
            'type' => 'registration',
            'wid' => 'station-davis-01',
            'mac' => 'aa:bb:cc:dd:ee:ff',
            'model' => 'VANTAGE-VUE',
            'firmware' => '2.63',
        ];
        $prepared = uploadPrepareWifiLoggerIngest($body, $this->binding(), 'aa:bb:cc:dd:ee:ff', self::NOW);
        $this->assertTrue($prepared['ok']);
        $this->assertSame('registration', $prepared['kind']);
        $this->assertSame('AA:BB:CC:DD:EE:FF', $prepared['registration']['mac']);
        $this->assertSame('VANTAGE-VUE', $prepared['registration']['payload']['model']);
    }

    public function testUploadEnvelope_Shape(): void
    {
        $this->assertSame(
            ['error' => 0, 'status' => 'rtd_ok', 'content' => null, 'results' => 1],
            uploadEnvelope(0, 'rtd_ok', null, 1)
        );
    }
}