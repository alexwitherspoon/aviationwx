<?php

declare(strict_types=1);

require_once __DIR__ . '/../../lib/production-health-check-evaluators.php';

use PHPUnit\Framework\TestCase;

/**
 * @coversNothing
 */
final class ProductionHealthCheckEvaluatorsTest extends TestCase
{
    public function testStatusV1Valid(): void
    {
        $j = [
            'success' => true,
            'status' => [
                'status' => 'operational',
                'checks' => ['configuration' => ['status' => 'operational']],
            ],
        ];
        $r = production_health_check_evaluate_api_v1_status_json($j);
        $this->assertTrue($r['ok']);
    }

    public function testStatusV1MissingChecks(): void
    {
        $j = ['success' => true, 'status' => ['status' => 'operational']];
        $r = production_health_check_evaluate_api_v1_status_json($j);
        $this->assertFalse($r['ok']);
    }

    public function testStatusV1StringSuccessFails(): void
    {
        $j = [
            'success' => 'true',
            'status' => [
                'status' => 'operational',
                'checks' => ['configuration' => ['status' => 'operational']],
            ],
        ];
        $this->assertFalse(production_health_check_evaluate_api_v1_status_json($j)['ok']);
    }

    public function testVersionV1Valid(): void
    {
        $j = ['hash' => 'abc', 'timestamp' => 123, 'deploy_date' => '2026-01-01T00:00:00Z'];
        $this->assertTrue(production_health_check_evaluate_api_v1_version_json($j)['ok']);
    }

    public function testVersionV1HashOnlyWithTimestamp(): void
    {
        $j = ['hash' => 'x', 'timestamp' => 1];
        $this->assertTrue(production_health_check_evaluate_api_v1_version_json($j)['ok']);
    }

    public function testVersionV1FloatTimestampAccepted(): void
    {
        $j = ['hash' => 'x', 'timestamp' => 1.25];
        $this->assertTrue(production_health_check_evaluate_api_v1_version_json($j)['ok']);
    }

    public function testVersionV1MissingHash(): void
    {
        $this->assertFalse(production_health_check_evaluate_api_v1_version_json(['timestamp' => 1])['ok']);
    }

    public function testOpenapiValid(): void
    {
        $j = ['openapi' => '3.0.3', 'info' => ['title' => 'T'], 'paths' => []];
        $this->assertTrue(production_health_check_evaluate_openapi_json($j)['ok']);
    }

    public function testOpenapiMissingPathsFails(): void
    {
        $j = ['openapi' => '3.0.3', 'info' => ['title' => 'T']];
        $this->assertFalse(production_health_check_evaluate_openapi_json($j)['ok']);
    }

    public function testOpenapiWrongMajor(): void
    {
        $j = ['openapi' => '2.0', 'info' => []];
        $this->assertFalse(production_health_check_evaluate_openapi_json($j)['ok']);
    }

    public function testOperationsValid(): void
    {
        $j = ['success' => true, 'operations' => ['snapshot_meta' => ['schema_version' => 1]]];
        $this->assertTrue(production_health_check_evaluate_api_v1_operations_json($j)['ok']);
    }

    public function testOperationsMissingSchemaVersionFails(): void
    {
        $j = ['success' => true, 'operations' => ['snapshot_meta' => []]];
        $this->assertFalse(production_health_check_evaluate_api_v1_operations_json($j)['ok']);
    }

    public function testHealthLiveValid(): void
    {
        $j = ['ok' => true, 'time' => time(), 'php_version' => '8.4'];
        $this->assertTrue(production_health_check_evaluate_health_live_json($j)['ok']);
    }

    public function testHealthLiveNumericStringTimeAccepted(): void
    {
        $j = ['ok' => true, 'time' => '1730000000', 'php_version' => '8.4'];
        $this->assertTrue(production_health_check_evaluate_health_live_json($j)['ok']);
    }

    public function testHealthReadyValid(): void
    {
        $j = ['ok' => true, 'errors' => []];
        $this->assertTrue(production_health_check_evaluate_health_ready_json($j)['ok']);
    }

    public function testHealthReadyNotBooleanOk(): void
    {
        $j = ['ok' => 'yes', 'errors' => []];
        $this->assertFalse(production_health_check_evaluate_health_ready_json($j)['ok']);
    }

    public function testOutageStatusValid(): void
    {
        $j = [
            'success' => true,
            'maintenance' => false,
            'in_outage' => false,
            'limited_availability' => false,
            'newest_timestamp' => 0,
            'sources' => ['primary' => ['timestamp' => 1, 'stale' => false, 'age' => 0]],
        ];
        $this->assertTrue(production_health_check_evaluate_outage_status_json($j)['ok']);
    }

    public function testOutageStatusFloatNewestTimestampAccepted(): void
    {
        $j = [
            'success' => true,
            'maintenance' => false,
            'in_outage' => false,
            'limited_availability' => false,
            'newest_timestamp' => 1.5,
            'sources' => [],
        ];
        $this->assertTrue(production_health_check_evaluate_outage_status_json($j)['ok']);
    }

    public function testEmbedValid(): void
    {
        $j = ['success' => true, 'data' => ['embed' => ['topics' => []]]];
        $this->assertTrue(production_health_check_evaluate_api_v1_embed_json($j)['ok']);
    }

    public function testEmbedEmptyObjectFails(): void
    {
        $j = ['success' => true, 'data' => ['embed' => []]];
        $this->assertFalse(production_health_check_evaluate_api_v1_embed_json($j)['ok']);
    }

    public function testEmbedListPayloadFails(): void
    {
        $j = ['success' => true, 'data' => ['embed' => [1, 2]]];
        $this->assertFalse(production_health_check_evaluate_api_v1_embed_json($j)['ok']);
    }

    public function testWeatherV1Valid(): void
    {
        $j = ['success' => true, 'weather' => ['icao' => 'kspb']];
        $this->assertTrue(production_health_check_evaluate_api_v1_weather_json($j)['ok']);
    }

    public function testWeatherV1MissingWeatherFails(): void
    {
        $j = ['success' => true];
        $this->assertFalse(production_health_check_evaluate_api_v1_weather_json($j)['ok']);
    }

    public function testWebcamsV1Valid(): void
    {
        $j = ['success' => true, 'webcams' => []];
        $this->assertTrue(production_health_check_evaluate_api_v1_webcams_json($j)['ok']);
    }

    public function testWebcamsV1MissingKeyFails(): void
    {
        $j = ['success' => true];
        $this->assertFalse(production_health_check_evaluate_api_v1_webcams_json($j)['ok']);
    }

    public function testWebcamsV1AssociativePayloadFails(): void
    {
        $j = ['success' => true, 'webcams' => ['not' => 'a list']];
        $this->assertFalse(production_health_check_evaluate_api_v1_webcams_json($j)['ok']);
    }

    public function testJsonDecodeAssocValidObject(): void
    {
        $this->assertSame(['a' => 1], production_health_check_json_decode_assoc('{"a":1}'));
    }

    public function testJsonDecodeAssocRejectsInvalid(): void
    {
        $this->assertNull(production_health_check_json_decode_assoc('{'));
    }

    public function testJsonDecodeAssocRejectsNonObjectTopLevel(): void
    {
        $this->assertNull(production_health_check_json_decode_assoc('"x"'));
    }

    public function testJsonDecodeAssocRejectsTopLevelArray(): void
    {
        $this->assertNull(production_health_check_json_decode_assoc('[]'));
        $this->assertNull(production_health_check_json_decode_assoc('[1,2]'));
    }

    public function testJsonDecodeAssocEmptyTopLevelObjectAccepted(): void
    {
        $this->assertSame([], production_health_check_json_decode_assoc('{}'));
    }

    public function testJsonDecodeAssocRejectsExcessiveDepth(): void
    {
        $inner = '{}';
        for ($i = 0; $i < 80; $i++) {
            $inner = '{"k":' . $inner . '}';
        }
        $this->assertNull(production_health_check_json_decode_assoc($inner, 64));
    }
}
