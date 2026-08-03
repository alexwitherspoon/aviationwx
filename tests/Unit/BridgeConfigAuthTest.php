<?php
/**
 * Unit tests for bridges[] / aviationwx_bridge config validation and auth resolution.
 */

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../lib/bridge/keys.php';
require_once __DIR__ . '/../../lib/bridge/config.php';
require_once __DIR__ . '/../../lib/bridge/auth.php';
require_once __DIR__ . '/../../lib/config.php';

class BridgeConfigAuthTest extends TestCase
{
    private function validKey(): string
    {
        return generateBridgeApiKey();
    }

    private function baseAirport(string $key, string $bridgeId = 'bridge-spb-1'): array
    {
        return [
            'name' => 'Scappoose',
            'icao' => 'KSPB',
            'bridges' => [
                [
                    'id' => $bridgeId,
                    'api_key' => $key,
                    'label' => 'Scappoose Pi',
                ],
            ],
        ];
    }

    public function testValidateBridgeConfig_AcceptsValidBridges(): void
    {
        $key = $this->validKey();
        $config = [
            'airports' => [
                'kspb' => $this->baseAirport($key),
            ],
        ];
        $result = validateBridgeConfig($config);
        $this->assertSame([], $result['errors'], implode('; ', $result['errors']));
    }

    public function testValidateBridgeConfig_RejectsEnabledWeatherSourcesOnBridgeRow(): void
    {
        $key = $this->validKey();
        $airport = $this->baseAirport($key);
        $airport['bridges'][0]['enabled_weather_sources'] = [
            ['bridge_source_id' => 'station-1', 'station_id' => 'wx-1'],
        ];
        $result = validateBridgeConfig(['airports' => ['kspb' => $airport]]);
        $this->assertNotEmpty($result['errors']);
        $this->assertStringContainsString('enabled_weather_sources', $result['errors'][0]);
        $this->assertStringContainsString('weather_sources', $result['errors'][0]);
    }

    public function testValidateBridgeConfig_RejectsBadKeyShape(): void
    {
        $airport = $this->baseAirport('awxb_tooshort');
        $result = validateBridgeConfig(['airports' => ['kspb' => $airport]]);
        $this->assertNotEmpty($result['errors']);
        $this->assertTrue(
            (bool) array_filter($result['errors'], static fn ($e) => str_contains($e, 'api_key must match shape')),
            implode('; ', $result['errors'])
        );
    }

    public function testValidateBridgeConfig_RejectsDuplicateKeysAcrossAirports(): void
    {
        $key = $this->validKey();
        $config = [
            'airports' => [
                'kspb' => $this->baseAirport($key, 'bridge-spb-1'),
                'kczk' => $this->baseAirport($key, 'bridge-czk-1'),
            ],
        ];
        $result = validateBridgeConfig($config);
        $this->assertNotEmpty($result['errors']);
        $this->assertTrue(
            (bool) array_filter($result['errors'], static fn ($e) => str_contains($e, 'duplicated')),
            implode('; ', $result['errors'])
        );
    }

    public function testValidateBridgeConfig_AviationwxBridgeRequiresMatchingBridgeId(): void
    {
        $key = $this->validKey();
        $airport = $this->baseAirport($key);
        $airport['weather_sources'] = [
            [
                'type' => 'aviationwx_bridge',
                'bridge_id' => 'bridge-missing',
                'bridge_source_id' => 'station-scappoose-davis',
            ],
        ];
        $result = validateBridgeConfig(['airports' => ['kspb' => $airport]]);
        $this->assertNotEmpty($result['errors']);
        $this->assertTrue(
            (bool) array_filter($result['errors'], static fn ($e) => str_contains($e, 'does not match')),
            implode('; ', $result['errors'])
        );
    }

    public function testValidateBridgeConfig_AviationwxBridgeAcceptsWhenBound(): void
    {
        $key = $this->validKey();
        $airport = $this->baseAirport($key);
        $airport['weather_sources'] = [
            [
                'type' => 'aviationwx_bridge',
                'bridge_id' => 'bridge-spb-1',
                'bridge_source_id' => 'station-scappoose-davis',
                'station_id' => 'wx-spb-bridge-davis',
            ],
        ];
        $result = validateBridgeConfig(['airports' => ['kspb' => $airport]]);
        $this->assertSame([], $result['errors'], implode('; ', $result['errors']));
    }

    public function testIsBridgeWeatherSourceEnabled_OptionB(): void
    {
        $key = $this->validKey();
        $airport = $this->baseAirport($key);
        $this->assertFalse(isBridgeWeatherSourceEnabled($airport, 'bridge-spb-1', 'station-scappoose-davis'));

        $airport['weather_sources'] = [
            [
                'type' => 'aviationwx_bridge',
                'bridge_id' => 'bridge-spb-1',
                'bridge_source_id' => 'station-scappoose-davis',
            ],
        ];
        $this->assertTrue(isBridgeWeatherSourceEnabled($airport, 'bridge-spb-1', 'station-scappoose-davis'));
        $this->assertFalse(isBridgeWeatherSourceEnabled($airport, 'bridge-spb-1', 'other-station'));
    }

    public function testValidateBridgeConfig_DavisWeatherlinkLiveAcceptsWhenBound(): void
    {
        $key = $this->validKey();
        $airport = $this->baseAirport($key);
        $airport['weather_sources'] = [
            [
                'type' => 'davis_weatherlink_live',
                'bridge_id' => 'bridge-spb-1',
                'bridge_source_id' => 'station-scappoose-davis',
                'txid' => 1,
            ],
        ];
        $result = validateBridgeConfig(['airports' => ['kspb' => $airport]]);
        $this->assertSame([], $result['errors'], implode('; ', $result['errors']));
    }

    public function testIsBridgeWeatherSourceEnabled_DavisType(): void
    {
        $key = $this->validKey();
        $airport = $this->baseAirport($key);
        $airport['weather_sources'] = [
            [
                'type' => 'davis_weatherlink_live',
                'bridge_id' => 'bridge-spb-1',
                'bridge_source_id' => 'station-scappoose-davis',
            ],
        ];
        $this->assertTrue(isBridgeWeatherSourceEnabled($airport, 'bridge-spb-1', 'station-scappoose-davis'));
    }

    public function testValidateBridgeConfig_RejectsWindReferenceOnEnableRow(): void
    {
        $key = $this->validKey();
        $airport = $this->baseAirport($key);
        $airport['weather_sources'] = [
            [
                'type' => 'davis_weatherlink_live',
                'bridge_id' => 'bridge-spb-1',
                'bridge_source_id' => 'station-scappoose-davis',
                'wind_reference' => 'magnetic',
            ],
        ];
        $result = validateBridgeConfig(['airports' => ['kspb' => $airport]]);
        $this->assertNotEmpty($result['errors']);
        $this->assertTrue(
            (bool) array_filter($result['errors'], static fn ($e) => str_contains($e, 'wind_reference')),
            implode('; ', $result['errors'])
        );
    }

    public function testResolveBridgeApiKey_FindsBinding(): void
    {
        $key = $this->validKey();
        $config = [
            'airports' => [
                'kspb' => $this->baseAirport($key),
            ],
        ];
        $binding = resolveBridgeApiKey($key, $config);
        $this->assertNotNull($binding);
        $this->assertSame('kspb', $binding['airport_id']);
        $this->assertSame('bridge-spb-1', $binding['bridge_id']);
        $this->assertSame('Scappoose Pi', $binding['label']);
    }

    public function testResolveBridgeApiKey_RejectsUnknown(): void
    {
        $this->assertNull(resolveBridgeApiKey($this->validKey(), ['airports' => []]));
    }

    public function testValidateRuntimeConfigSchema_IncludesBridgeValidation(): void
    {
        $key = $this->validKey();
        $config = [
            'config' => [],
            'airports' => [
                'kspb' => array_merge($this->baseAirport($key), [
                    'webcams' => [],
                ]),
            ],
        ];
        // Invalid: enabled_weather_sources on bridge
        $config['airports']['kspb']['bridges'][0]['enabled_weather_sources'] = [];
        $result = validateRuntimeConfigSchema($config);
        $this->assertFalse($result['valid']);
    }

    public function testValidateAirportsJsonStructure_AcceptsAviationwxBridgeType(): void
    {
        $key = $this->validKey();
        $config = [
            'airports' => [
                'kspb' => [
                    'name' => 'Scappoose',
                    'icao' => 'KSPB',
                    'lat' => 45.771,
                    'lon' => -122.862,
                    'access_type' => 'public',
                    'tower_status' => 'non_towered',
                    'bridges' => [
                        ['id' => 'bridge-spb-1', 'api_key' => $key],
                    ],
                    'weather_sources' => [
                        [
                            'type' => 'aviationwx_bridge',
                            'bridge_id' => 'bridge-spb-1',
                            'bridge_source_id' => 'station-scappoose-davis',
                        ],
                    ],
                ],
            ],
        ];
        $result = validateAirportsJsonStructure($config);
        $this->assertTrue($result['valid'], implode('; ', $result['errors']));
    }
}
