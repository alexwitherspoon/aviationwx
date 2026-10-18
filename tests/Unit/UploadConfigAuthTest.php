<?php
/**
 * Unit tests for wifilogger weather_sources config validation and upload token auth.
 */

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../lib/upload/keys.php';
require_once __DIR__ . '/../../lib/upload/config.php';
require_once __DIR__ . '/../../lib/config.php';

class UploadConfigAuthTest extends TestCase
{
    private const FIXTURE_TOKEN = 'awxu_000000000000000000000000000000000000000000000001';

    private function validSource(): array
    {
        return [
            'type' => 'wifilogger',
            'upload_token' => self::FIXTURE_TOKEN,
            'wid' => 'station-davis-01',
            'mac' => 'aa:bb:cc:dd:ee:ff',
        ];
    }

    private function airportWithSources(array $sources, string $airportId = 'kspb'): array
    {
        return [
            'airports' => [
                $airportId => [
                    'name' => 'Scappoose',
                    'icao' => 'KSPB',
                    'weather_sources' => $sources,
                ],
            ],
        ];
    }

    public function testValidateUploadConfig_AcceptsValidWifiloggerRow(): void
    {
        $config = $this->airportWithSources([$this->validSource()]);
        $result = validateUploadConfig($config);
        $this->assertSame([], $result['errors'], implode('; ', $result['errors']));
    }

    public function testValidateUploadConfig_IgnoresNonWifiloggerRows(): void
    {
        $config = $this->airportWithSources([
            ['type' => 'metar', 'station_id' => 'KSPB'],
        ]);
        $result = validateUploadConfig($config);
        $this->assertSame([], $result['errors'], implode('; ', $result['errors']));
    }

    public function testValidateUploadConfig_RejectsMissingFields(): void
    {
        $config = $this->airportWithSources([['type' => 'wifilogger']]);
        $result = validateUploadConfig($config);
        $this->assertNotEmpty($result['errors']);
        $this->assertTrue(
            (bool) array_filter($result['errors'], static fn ($e) => str_contains($e, 'upload_token')),
            implode('; ', $result['errors'])
        );
        $this->assertTrue(
            (bool) array_filter($result['errors'], static fn ($e) => str_contains($e, 'wid')),
            implode('; ', $result['errors'])
        );
        $this->assertTrue(
            (bool) array_filter($result['errors'], static fn ($e) => str_contains($e, 'mac')),
            implode('; ', $result['errors'])
        );
    }

    public function testValidateUploadConfig_RejectsBadTokenShape(): void
    {
        $source = $this->validSource();
        $source['upload_token'] = 'awxb_' . str_repeat('0', 48);
        $config = $this->airportWithSources([$source]);
        $result = validateUploadConfig($config);
        $this->assertTrue(
            (bool) array_filter($result['errors'], static fn ($e) => str_contains($e, 'upload_token')),
            implode('; ', $result['errors'])
        );
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('invalidMacProvider')]
    public function testValidateUploadConfig_RejectsBadMac(string $mac): void
    {
        $source = $this->validSource();
        $source['mac'] = $mac;
        $config = $this->airportWithSources([$source]);
        $result = validateUploadConfig($config);
        $this->assertTrue(
            (bool) array_filter($result['errors'], static fn ($e) => str_contains($e, 'mac')),
            implode('; ', $result['errors'])
        );
    }

    public static function invalidMacProvider(): array
    {
        return [
            'empty' => [''],
            'five_octets' => ['aa:bb:cc:dd:ee'],
            'non_hex' => ['aa:bb:cc:dd:ee:gg'],
            'three_digits' => ['aaa:bb:cc:dd:ee:ff'],
        ];
    }

    public function testValidateUploadConfig_RejectsBridgeFieldsOnWifiloggerRow(): void
    {
        $source = $this->validSource();
        $source['bridge_id'] = 'bridge-spb-1';
        $source['bridge_source_id'] = 'station-davis';
        $config = $this->airportWithSources([$source]);
        $result = validateUploadConfig($config);
        $this->assertTrue(
            (bool) array_filter($result['errors'], static fn ($e) => str_contains($e, 'bridge_id')),
            implode('; ', $result['errors'])
        );
    }

    public function testValidateUploadConfig_RejectsWindReferenceOnWifiloggerRow(): void
    {
        $source = $this->validSource();
        $source['wind_reference'] = 'magnetic';
        $config = $this->airportWithSources([$source]);
        $result = validateUploadConfig($config);
        $this->assertTrue(
            (bool) array_filter($result['errors'], static fn ($e) => str_contains($e, 'wind_reference')),
            implode('; ', $result['errors'])
        );
    }

    public function testValidateUploadConfig_RejectsDuplicateTokensAcrossAirports(): void
    {
        $source = $this->validSource();
        $config = [
            'airports' => [
                'kspb' => ['name' => 'Scappoose', 'icao' => 'KSPB', 'weather_sources' => [$source]],
                'kczk' => ['name' => 'Cascade', 'icao' => 'KCZK', 'weather_sources' => [$source]],
            ],
        ];
        $result = validateUploadConfig($config);
        $this->assertTrue(
            (bool) array_filter($result['errors'], static fn ($e) => str_contains($e, 'duplicated')),
            implode('; ', $result['errors'])
        );
    }

    public function testResolveUploadApiKey_FindsBinding(): void
    {
        $source = $this->validSource();
        $config = $this->airportWithSources([$source]);
        $binding = resolveUploadApiKey(self::FIXTURE_TOKEN, $config);
        $this->assertNotNull($binding);
        $this->assertSame('kspb', $binding['airport_id']);
        $this->assertSame('station-davis-01', $binding['source_id']);
        $this->assertSame('station-davis-01', $binding['wid']);
        $this->assertSame('AA:BB:CC:DD:EE:FF', $binding['mac']);
    }

    public function testResolveUploadApiKey_RejectsUnknown(): void
    {
        $this->assertNull(resolveUploadApiKey(self::FIXTURE_TOKEN, ['airports' => []]));
        $this->assertNull(resolveUploadApiKey('ak_live_abcdefghijklmnop', ['airports' => []]));
    }
}