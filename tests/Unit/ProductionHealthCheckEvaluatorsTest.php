<?php

declare(strict_types=1);

require_once __DIR__ . '/../../lib/production-health-check-evaluators.php';

use PHPUnit\Framework\TestCase;

/**
 * @coversNothing
 */
final class ProductionHealthCheckEvaluatorsTest extends TestCase
{
    public function testProductionHealthCheckEvaluateApiV1StatusJson_ValidEnvelope_ReturnsOk(): void
    {
        $j = [
            'success' => true,
            'status' => [
                'status' => 'operational',
                'checks' => ['configuration' => ['status' => 'operational']],
            ],
        ];
        $r = productionHealthCheckEvaluateApiV1StatusJson($j);
        $this->assertTrue($r['ok']);
    }

    public function testProductionHealthCheckEvaluateApiV1StatusJson_MissingChecks_ReturnsNotOk(): void
    {
        $j = ['success' => true, 'status' => ['status' => 'operational']];
        $r = productionHealthCheckEvaluateApiV1StatusJson($j);
        $this->assertFalse($r['ok']);
    }

    public function testProductionHealthCheckEvaluateApiV1StatusJson_StringSuccess_ReturnsNotOk(): void
    {
        $j = [
            'success' => 'true',
            'status' => [
                'status' => 'operational',
                'checks' => ['configuration' => ['status' => 'operational']],
            ],
        ];
        $this->assertFalse(productionHealthCheckEvaluateApiV1StatusJson($j)['ok']);
    }

    public function testProductionHealthCheckEvaluateApiV1VersionJson_ValidEnvelope_ReturnsOk(): void
    {
        $j = ['hash' => 'abc', 'timestamp' => 123, 'deploy_date' => '2026-01-01T00:00:00Z'];
        $this->assertTrue(productionHealthCheckEvaluateApiV1VersionJson($j)['ok']);
    }

    public function testProductionHealthCheckEvaluateApiV1VersionJson_HashAndTimestamp_ReturnsOk(): void
    {
        $j = ['hash' => 'x', 'timestamp' => 1];
        $this->assertTrue(productionHealthCheckEvaluateApiV1VersionJson($j)['ok']);
    }

    public function testProductionHealthCheckEvaluateApiV1VersionJson_FloatTimestamp_ReturnsOk(): void
    {
        $j = ['hash' => 'x', 'timestamp' => 1.25];
        $this->assertTrue(productionHealthCheckEvaluateApiV1VersionJson($j)['ok']);
    }

    public function testProductionHealthCheckEvaluateApiV1VersionJson_MissingHash_ReturnsNotOk(): void
    {
        $this->assertFalse(productionHealthCheckEvaluateApiV1VersionJson(['timestamp' => 1])['ok']);
    }

    public function testProductionHealthCheckEvaluateOpenapiJson_ValidOpenApi3_ReturnsOk(): void
    {
        $j = ['openapi' => '3.0.3', 'info' => ['title' => 'T'], 'paths' => []];
        $this->assertTrue(productionHealthCheckEvaluateOpenapiJson($j)['ok']);
    }

    public function testProductionHealthCheckEvaluateOpenapiJson_MissingPaths_ReturnsNotOk(): void
    {
        $j = ['openapi' => '3.0.3', 'info' => ['title' => 'T']];
        $this->assertFalse(productionHealthCheckEvaluateOpenapiJson($j)['ok']);
    }

    public function testProductionHealthCheckEvaluateOpenapiJson_WrongMajorVersion_ReturnsNotOk(): void
    {
        $j = ['openapi' => '2.0', 'info' => []];
        $this->assertFalse(productionHealthCheckEvaluateOpenapiJson($j)['ok']);
    }

    public function testProductionHealthCheckEvaluateApiV1OperationsJson_ValidEnvelope_ReturnsOk(): void
    {
        $j = ['success' => true, 'operations' => ['snapshot_meta' => ['schema_version' => 1]]];
        $this->assertTrue(productionHealthCheckEvaluateApiV1OperationsJson($j)['ok']);
    }

    public function testProductionHealthCheckEvaluateApiV1OperationsJson_MissingSchemaVersion_ReturnsNotOk(): void
    {
        $j = ['success' => true, 'operations' => ['snapshot_meta' => []]];
        $this->assertFalse(productionHealthCheckEvaluateApiV1OperationsJson($j)['ok']);
    }

    public function testProductionHealthCheckEvaluateHealthLiveJson_ValidEnvelope_ReturnsOk(): void
    {
        $j = ['ok' => true, 'time' => time(), 'php_version' => '8.4'];
        $this->assertTrue(productionHealthCheckEvaluateHealthLiveJson($j)['ok']);
    }

    public function testProductionHealthCheckEvaluateHealthLiveJson_NumericStringTime_ReturnsOk(): void
    {
        $j = ['ok' => true, 'time' => '1730000000', 'php_version' => '8.4'];
        $this->assertTrue(productionHealthCheckEvaluateHealthLiveJson($j)['ok']);
    }

    public function testProductionHealthCheckEvaluateHealthReadyJson_ValidEnvelope_ReturnsOk(): void
    {
        $j = ['ok' => true, 'errors' => []];
        $this->assertTrue(productionHealthCheckEvaluateHealthReadyJson($j)['ok']);
    }

    public function testProductionHealthCheckEvaluateHealthReadyJson_NonBooleanOk_ReturnsNotOk(): void
    {
        $j = ['ok' => 'yes', 'errors' => []];
        $this->assertFalse(productionHealthCheckEvaluateHealthReadyJson($j)['ok']);
    }

    public function testProductionHealthCheckEvaluateOutageStatusJson_ValidEnvelope_ReturnsOk(): void
    {
        $j = [
            'success' => true,
            'maintenance' => false,
            'in_outage' => false,
            'limited_availability' => false,
            'newest_timestamp' => 0,
            'sources' => ['primary' => ['timestamp' => 1, 'stale' => false, 'age' => 0]],
        ];
        $this->assertTrue(productionHealthCheckEvaluateOutageStatusJson($j)['ok']);
    }

    public function testProductionHealthCheckEvaluateOutageStatusJson_FloatNewestTimestamp_ReturnsOk(): void
    {
        $j = [
            'success' => true,
            'maintenance' => false,
            'in_outage' => false,
            'limited_availability' => false,
            'newest_timestamp' => 1.5,
            'sources' => [],
        ];
        $this->assertTrue(productionHealthCheckEvaluateOutageStatusJson($j)['ok']);
    }

    public function testProductionHealthCheckEvaluateApiV1EmbedJson_ValidEnvelope_ReturnsOk(): void
    {
        $j = ['success' => true, 'data' => ['embed' => ['topics' => []]]];
        $this->assertTrue(productionHealthCheckEvaluateApiV1EmbedJson($j)['ok']);
    }

    public function testProductionHealthCheckEvaluateApiV1EmbedJson_EmptyEmbedObject_ReturnsNotOk(): void
    {
        $j = ['success' => true, 'data' => ['embed' => []]];
        $this->assertFalse(productionHealthCheckEvaluateApiV1EmbedJson($j)['ok']);
    }

    public function testProductionHealthCheckEvaluateApiV1EmbedJson_ListEmbedPayload_ReturnsNotOk(): void
    {
        $j = ['success' => true, 'data' => ['embed' => [1, 2]]];
        $this->assertFalse(productionHealthCheckEvaluateApiV1EmbedJson($j)['ok']);
    }

    public function testProductionHealthCheckEvaluateApiV1WeatherJson_ValidEnvelope_ReturnsOk(): void
    {
        $j = ['success' => true, 'weather' => ['icao' => 'kspb']];
        $this->assertTrue(productionHealthCheckEvaluateApiV1WeatherJson($j)['ok']);
    }

    public function testProductionHealthCheckEvaluateApiV1WeatherJson_MissingWeather_ReturnsNotOk(): void
    {
        $j = ['success' => true];
        $this->assertFalse(productionHealthCheckEvaluateApiV1WeatherJson($j)['ok']);
    }

    public function testProductionHealthCheckEvaluateApiV1WebcamsJson_ValidEnvelope_ReturnsOk(): void
    {
        $j = ['success' => true, 'webcams' => []];
        $this->assertTrue(productionHealthCheckEvaluateApiV1WebcamsJson($j)['ok']);
    }

    public function testProductionHealthCheckEvaluateApiV1WebcamsJson_MissingWebcamsKey_ReturnsNotOk(): void
    {
        $j = ['success' => true];
        $this->assertFalse(productionHealthCheckEvaluateApiV1WebcamsJson($j)['ok']);
    }

    public function testProductionHealthCheckEvaluateApiV1WebcamsJson_AssociativeWebcams_ReturnsNotOk(): void
    {
        $j = ['success' => true, 'webcams' => ['not' => 'a list']];
        $this->assertFalse(productionHealthCheckEvaluateApiV1WebcamsJson($j)['ok']);
    }

    public function testProductionHealthCheckJsonDecodeAssoc_ValidObject_ReturnsAssocArray(): void
    {
        $this->assertSame(['a' => 1], productionHealthCheckJsonDecodeAssoc('{"a":1}'));
    }

    public function testProductionHealthCheckJsonDecodeAssoc_InvalidJson_ReturnsNull(): void
    {
        $this->assertNull(productionHealthCheckJsonDecodeAssoc('{'));
    }

    public function testProductionHealthCheckJsonDecodeAssoc_TopLevelString_ReturnsNull(): void
    {
        $this->assertNull(productionHealthCheckJsonDecodeAssoc('"x"'));
    }

    public function testProductionHealthCheckJsonDecodeAssoc_TopLevelArray_ReturnsNull(): void
    {
        $this->assertNull(productionHealthCheckJsonDecodeAssoc('[]'));
        $this->assertNull(productionHealthCheckJsonDecodeAssoc('[1,2]'));
    }

    public function testProductionHealthCheckJsonDecodeAssoc_EmptyTopLevelObject_ReturnsEmptyArray(): void
    {
        $this->assertSame([], productionHealthCheckJsonDecodeAssoc('{}'));
    }

    public function testProductionHealthCheckJsonDecodeAssoc_ExceedsMaxDepth_ReturnsNull(): void
    {
        $inner = '{}';
        for ($i = 0; $i < 80; $i++) {
            $inner = '{"k":' . $inner . '}';
        }
        $this->assertNull(productionHealthCheckJsonDecodeAssoc($inner, 64));
    }
}
