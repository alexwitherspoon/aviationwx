<?php
/**
 * Tempest WeatherFlow adapter tests (safety-critical path).
 *
 * Covers: federated parsing; `obs_st` normalization (last row wins); first ST from `/stations` JSON;
 * device fallback when federated `obs` is empty or federated row is timestamp-only; URL builders;
 * rejection of list-shaped `obs[0]` (avoids mis-reading numeric arrays as keyed fields). Requires APP_ENV=testing
 * and URL mocks in lib/test-mocks.php.
 */

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../lib/config.php';
require_once __DIR__ . '/../../lib/weather/adapter/tempest-v1.php';
require_once __DIR__ . '/../mock-weather-responses.php';

class TempestAdapterTest extends TestCase
{
    public function test_givenFederatedStationJson_whenParsed_thenFieldsMatchStationContract(): void
    {
        $response = getMockTempestResponse();
        $result = parseTempestResponse($response);
        $this->assertIsArray($result);
        $this->assertArrayHasKey('temperature', $result);
        $this->assertArrayHasKey('pressure', $result);
        $this->assertNotNull($result['dewpoint']);
    }

    public function test_givenDeviceObsStJson_whenParsed_thenUsesLastRowAndNullDewpoint(): void
    {
        $early = array_fill(0, 22, 0);
        $early[0] = 100;
        $early[7] = 1.0;
        $late = array_fill(0, 22, 0);
        $late[0] = 200;
        $late[7] = 22.5;
        $late[8] = 50.0;
        $response = json_encode([
            'status' => ['status_code' => 0],
            'device_id' => 1,
            'type' => 'obs_st',
            'obs' => [$early, $late],
        ], JSON_THROW_ON_ERROR);
        $result = parseTempestResponse($response);
        $this->assertIsArray($result);
        $this->assertEqualsWithDelta(22.5, $result['temperature'], 0.0001);
        $this->assertSame(200, $result['obs_time']);
        $this->assertNull($result['dewpoint']);
    }

    public function test_givenObsStWithEmptyObs_whenParsed_thenReturnsNull(): void
    {
        $response = json_encode([
            'type' => 'obs_st',
            'obs' => [],
        ], JSON_THROW_ON_ERROR);
        $this->assertNull(parseTempestResponse($response));
    }

    public function test_givenObsStRowMissingEpoch_whenMappedToAssoc_thenReturnsNull(): void
    {
        $row = array_fill(0, 22, 0);
        $row[7] = 20.0;
        unset($row[0]);
        $this->assertNull(tempestObsStRowToStationObservationAssoc($row));
    }

    public function test_givenStationsMetadataWithHbAndSt_whenExtractSt_thenReturnsStDeviceId(): void
    {
        $json = getMockTempestStationsMetadataResponse();
        $this->assertSame(900002, tempestExtractStDeviceIdFromStationsJson($json));
    }

    public function test_givenStationsMetadataWithTwoStDevices_whenExtractSt_thenReturnsFirstSt(): void
    {
        $json = json_encode([
            'stations' => [[
                'devices' => [
                    ['device_id' => 111, 'device_type' => 'ST'],
                    ['device_id' => 222, 'device_type' => 'ST'],
                ],
            ]],
        ], JSON_THROW_ON_ERROR);
        $this->assertSame(111, tempestExtractStDeviceIdFromStationsJson($json));
    }

    public function test_givenStationsMetadataWithoutSt_whenExtractSt_thenReturnsNull(): void
    {
        $json = json_encode([
            'stations' => [[
                'devices' => [
                    ['device_id' => 1, 'device_type' => 'HB'],
                ],
            ]],
        ], JSON_THROW_ON_ERROR);
        $this->assertNull(tempestExtractStDeviceIdFromStationsJson($json));
    }

    public function test_givenInvalidStationsJson_whenExtractSt_thenReturnsNull(): void
    {
        $this->assertNull(tempestExtractStDeviceIdFromStationsJson('not-json'));
    }

    public function test_givenFederatedObsFirstElementIsList_whenParsed_thenReturnsNull(): void
    {
        $response = json_encode([
            'status' => ['status_code' => 0],
            'obs' => [[1, 2, 3, 4]],
        ], JSON_THROW_ON_ERROR);
        $this->assertNull(parseTempestResponse($response));
    }

    public function test_givenEmptyStationObsAndValidSource_whenApplyDeviceFallback_thenReturnsParsableDeviceBody(): void
    {
        $station = json_encode([
            'status' => ['status_code' => 0],
            'obs' => [],
        ], JSON_THROW_ON_ERROR);
        $source = [
            'type' => 'tempest',
            'station_id' => '12345',
            'api_key' => 'test-token-for-mock',
        ];
        $out = tempestApplyDeviceFallbackIfNeeded($station, $source, 'kxxx');
        $parsed = parseTempestResponse($out);
        $this->assertNotNull($parsed);
        $this->assertEqualsWithDelta(5.6, $parsed['temperature'], 0.0001);
    }

    public function test_givenFederatedSkeletonObsOnlyTimestamp_whenApplyDeviceFallback_thenReturnsParsableDeviceBody(): void
    {
        $station = json_encode([
            'status' => ['status_code' => 0],
            'obs' => [[
                'timestamp' => 1700000000,
            ]],
        ], JSON_THROW_ON_ERROR);
        $parsedSkeleton = parseTempestResponse($station);
        $this->assertIsArray($parsedSkeleton, 'skeleton row must parse to an array');
        $this->assertFalse(tempestParsedObservationHasUsableSensorFields($parsedSkeleton));
        $source = [
            'type' => 'tempest',
            'station_id' => '12345',
            'api_key' => 'test-token-for-mock',
        ];
        $out = tempestApplyDeviceFallbackIfNeeded($station, $source, 'kxxx');
        $parsed = parseTempestResponse($out);
        $this->assertNotNull($parsed);
        $this->assertEqualsWithDelta(5.6, $parsed['temperature'], 0.0001);
    }

    public function test_givenParsableStationBody_whenApplyDeviceFallback_thenReturnsOriginalBody(): void
    {
        $station = getMockTempestResponse();
        $source = [
            'type' => 'tempest',
            'station_id' => '12345',
            'api_key' => 'x',
        ];
        $this->assertSame($station, tempestApplyDeviceFallbackIfNeeded($station, $source, 'kxxx'));
    }

    public function test_givenEmptyStationObsAndMissingApiKey_whenApplyDeviceFallback_thenReturnsOriginalBody(): void
    {
        $station = json_encode(['status' => ['status_code' => 0], 'obs' => []], JSON_THROW_ON_ERROR);
        $source = [
            'type' => 'tempest',
            'station_id' => '12345',
        ];
        $this->assertSame($station, tempestApplyDeviceFallbackIfNeeded($station, $source, 'kxxx'));
    }

    public function test_givenDeviceObsStBody_whenParseToSnapshot_thenIsValid(): void
    {
        $snap = TempestAdapter::parseToSnapshot(getMockTempestDeviceObsStResponse(), []);
        $this->assertNotNull($snap);
        $this->assertTrue($snap->isValid);
        $this->assertTrue($snap->temperature->hasValue());
        $this->assertFalse($snap->dewpoint->hasValue());
    }

    public function test_tempestParsedObservationHasUsableSensorFields_trueForWindOrPrecipOrTemp(): void
    {
        $this->assertTrue(tempestParsedObservationHasUsableSensorFields([
            'temperature' => null,
            'humidity' => null,
            'pressure' => null,
            'dewpoint' => null,
            'wind_speed' => 5,
            'wind_direction' => null,
            'gust_speed' => null,
            'precip_accum' => 0.0,
        ]));
        $this->assertTrue(tempestParsedObservationHasUsableSensorFields([
            'temperature' => null,
            'humidity' => null,
            'pressure' => null,
            'dewpoint' => null,
            'wind_speed' => null,
            'wind_direction' => null,
            'gust_speed' => null,
            'precip_accum' => 0.01,
        ]));
        $this->assertTrue(tempestParsedObservationHasUsableSensorFields([
            'temperature' => 1.0,
            'humidity' => null,
            'pressure' => null,
            'dewpoint' => null,
            'wind_speed' => null,
            'wind_direction' => null,
            'gust_speed' => null,
            'precip_accum' => 0.0,
        ]));
    }

    public function test_tempestParsedObservationHasUsableSensorFields_falseWhenOnlyTimestampLikeParsedOutput(): void
    {
        $skeleton = json_encode([
            'status' => ['status_code' => 0],
            'obs' => [['timestamp' => 1700000000]],
        ], JSON_THROW_ON_ERROR);
        $parsed = parseTempestResponse($skeleton);
        $this->assertIsArray($parsed);
        $this->assertFalse(tempestParsedObservationHasUsableSensorFields($parsed));
    }

    public function test_givenTempestConfig_whenBuildUrls_thenPathsAndEncodingAreCorrect(): void
    {
        $cfg = ['station_id' => '214348', 'api_key' => 'a+b/c'];
        $stationObs = TempestAdapter::buildUrl($cfg);
        $this->assertStringContainsString('/observations/station/214348', $stationObs);
        $this->assertStringContainsString('token=a%2Bb%2Fc', $stationObs);
        $meta = TempestAdapter::buildStationsMetadataUrl($cfg);
        $this->assertStringContainsString('/rest/stations/214348', $meta);
        $this->assertStringContainsString('token=a%2Bb%2Fc', $meta);
        $dev = TempestAdapter::buildDeviceObservationsUrl(1215470, 'tok/1');
        $this->assertStringContainsString('/observations/device/1215470', $dev);
        $this->assertStringContainsString('token=tok%2F1', $dev);
    }
}
