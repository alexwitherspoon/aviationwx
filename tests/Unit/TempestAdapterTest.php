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
    public function testParseTempestResponse_FederatedMockJson_HasTemperaturePressureDewpoint(): void
    {
        $response = getMockTempestResponse();
        $result = parseTempestResponse($response);
        $this->assertIsArray($result);
        $this->assertArrayHasKey('temperature', $result);
        $this->assertArrayHasKey('pressure', $result);
        $this->assertNotNull($result['dewpoint']);
    }

    public function testParseTempestResponse_DeviceObsStTwoRows_UsesLastRowNullDewpoint(): void
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

    public function testParseTempestResponse_ObsStEmptyObs_ReturnsNull(): void
    {
        $response = json_encode([
            'type' => 'obs_st',
            'obs' => [],
        ], JSON_THROW_ON_ERROR);
        $this->assertNull(parseTempestResponse($response));
    }

    public function testTempestObsStRowToStationObservationAssoc_MissingEpoch_ReturnsNull(): void
    {
        $row = array_fill(0, 22, 0);
        $row[7] = 20.0;
        unset($row[0]);
        $this->assertNull(tempestObsStRowToStationObservationAssoc($row));
    }

    public function testTempestExtractStDeviceIdFromStationsJson_HubAndSensor_ReturnsSensorDeviceId(): void
    {
        $json = getMockTempestStationsMetadataResponse();
        $this->assertSame(900002, tempestExtractStDeviceIdFromStationsJson($json));
    }

    public function testTempestExtractStDeviceIdFromStationsJson_TwoSensorDevices_ReturnsFirstSensor(): void
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

    public function testTempestExtractStDeviceIdFromStationsJson_OnlyHub_ReturnsNull(): void
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

    public function testTempestExtractStDeviceIdFromStationsJson_InvalidJson_ReturnsNull(): void
    {
        $this->assertNull(tempestExtractStDeviceIdFromStationsJson('not-json'));
    }

    public function testParseTempestResponse_FederatedObsListShapedFirstElement_ReturnsNull(): void
    {
        $response = json_encode([
            'status' => ['status_code' => 0],
            'obs' => [[1, 2, 3, 4]],
        ], JSON_THROW_ON_ERROR);
        $this->assertNull(parseTempestResponse($response));
    }

    public function testTempestApplyDeviceFallbackIfNeeded_EmptyFederatedObs_ReturnsDeviceObservationPayload(): void
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

    public function testTempestApplyDeviceFallbackIfNeeded_TimestampOnlySkeleton_ReturnsDeviceObservationPayload(): void
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

    public function testTempestApplyDeviceFallbackIfNeeded_UsableFederatedObs_ReturnsOriginalBody(): void
    {
        $station = getMockTempestResponse();
        $source = [
            'type' => 'tempest',
            'station_id' => '12345',
            'api_key' => 'x',
        ];
        $this->assertSame($station, tempestApplyDeviceFallbackIfNeeded($station, $source, 'kxxx'));
    }

    public function testTempestApplyDeviceFallbackIfNeeded_MissingApiKey_ReturnsOriginalBody(): void
    {
        $station = json_encode(['status' => ['status_code' => 0], 'obs' => []], JSON_THROW_ON_ERROR);
        $source = [
            'type' => 'tempest',
            'station_id' => '12345',
        ];
        $this->assertSame($station, tempestApplyDeviceFallbackIfNeeded($station, $source, 'kxxx'));
    }

    public function testTempestAdapter_ParseToSnapshot_DeviceObsSt_ReturnsValidSnapshot(): void
    {
        $snap = TempestAdapter::parseToSnapshot(getMockTempestDeviceObsStResponse(), []);
        $this->assertNotNull($snap);
        $this->assertTrue($snap->isValid);
        $this->assertTrue($snap->temperature->hasValue());
        $this->assertFalse($snap->dewpoint->hasValue());
    }

    public function testTempestParsedObservationHasUsableSensorFields_WindOrPrecipOrTemp_ReturnsTrue(): void
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

    public function testTempestParsedObservationHasUsableSensorFields_TimestampOnly_ReturnsFalse(): void
    {
        $skeleton = json_encode([
            'status' => ['status_code' => 0],
            'obs' => [['timestamp' => 1700000000]],
        ], JSON_THROW_ON_ERROR);
        $parsed = parseTempestResponse($skeleton);
        $this->assertIsArray($parsed);
        $this->assertFalse(tempestParsedObservationHasUsableSensorFields($parsed));
    }

    public function testTempestAdapter_BuildUrlAndStationsUrl_EncodeReservedCharactersInToken(): void
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

    public function testTempestHttpTimeoutWithinDeadline_Expired_ReturnsNull(): void
    {
        $this->assertNull(tempestHttpTimeoutWithinDeadline(8, microtime(true) - 1.0));
    }

    public function testTempestHttpTimeoutWithinDeadline_AmpleTime_ReturnsPreferred(): void
    {
        $this->assertSame(8, tempestHttpTimeoutWithinDeadline(8, microtime(true) + 60.0));
    }

    public function testTempestDeviceFallbackSequenceDeadline_RespectsCurlMultiOverallCap(): void
    {
        $this->assertLessThanOrEqual(
            15.0,
            tempestDeviceFallbackSequenceDeadline(8) - microtime(true),
            'deadline span should not exceed CURL_MULTI_OVERALL_TIMEOUT when defined'
        );
    }

    public function testTempestFallbackPerHopTimeoutSeconds_LteCurlTimeoutAndFallbackCap(): void
    {
        $this->assertTrue(defined('CURL_TIMEOUT'));
        $t = tempestFallbackPerHopTimeoutSeconds();
        $this->assertGreaterThanOrEqual(1, $t);
        $this->assertLessThanOrEqual((int) CURL_TIMEOUT, $t);
        $this->assertLessThanOrEqual(TempestAdapter::FALLBACK_HTTP_TIMEOUT_SECONDS, $t);
    }
}
