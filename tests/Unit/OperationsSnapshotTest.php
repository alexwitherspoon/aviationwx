<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * @covers operations_snapshot_redact_scalar_string
 * @covers operations_snapshot_scrub_context
 * @covers operations_snapshot_aggregate_log_fingerprints
 * @covers operations_snapshot_summarize_airport_health
 * @covers operations_snapshot_weather_circuit_by_source
 * @covers operations_snapshot_verbose_detail_warranted
 * @covers operations_snapshot_apply_verbose_gate
 * @covers operations_snapshot_build
 * @covers operations_snapshot_write_envelope
 * @covers operations_snapshot_read_log_tail
 * @covers operations_snapshot_get_api_payload
 */
class OperationsSnapshotTest extends TestCase
{
    public function testRedactScalarString_stripsQueryFromUrl(): void
    {
        $in = 'failed https://example.com/api?token=secret&x=1 end';
        $out = operations_snapshot_redact_scalar_string($in);
        $this->assertStringNotContainsString('token=secret', $out);
        $this->assertStringContainsString('[redacted]', $out);
    }

    /**
     * Regression: fseek returns 0 on success; a negated check treated success as failure and returned ''.
     */
    public function testReadLogTail_returnsTailWhenFileExceedsMaxBytes(): void
    {
        $path = sys_get_temp_dir() . '/awx_log_tail_' . bin2hex(random_bytes(4)) . '.log';
        $suffix = 'TAIL_MARKER_UNIQUE_XYZ';
        $content = str_repeat('P', 150) . "\n" . $suffix;
        $this->assertNotFalse(file_put_contents($path, $content));
        try {
            $tail = operations_snapshot_read_log_tail($path, 40);
            $this->assertStringContainsString($suffix, $tail);
        } finally {
            @unlink($path);
        }
    }

    public function testScrubContext_redactsSensitiveKeys(): void
    {
        $ctx = [
            'source' => 'web',
            'api_key' => 'should-redact',
            'nested' => ['password' => 'x'],
        ];
        $out = operations_snapshot_scrub_context($ctx);
        $this->assertSame('web', $out['source']);
        $this->assertSame('[redacted]', $out['api_key']);
        $this->assertArrayNotHasKey('nested', $out);
    }

    public function testAggregateLogFingerprints_groupsByMessage(): void
    {
        $now = strtotime('2026-05-14T12:00:00Z');
        $t1 = gmdate('c', $now - 120);
        $t2 = gmdate('c', $now - 60);
        $lines = implode("\n", [
            json_encode(['ts' => $t1, 'level' => 'warning', 'message' => 'same msg', 'context' => ['source' => 'cli']]),
            json_encode(['ts' => $t2, 'level' => 'warning', 'message' => 'same msg', 'context' => ['source' => 'cli']]),
            json_encode(['ts' => $t2, 'level' => 'info', 'message' => 'ignored', 'context' => []]),
        ]);
        $agg = operations_snapshot_aggregate_log_fingerprints($lines, $now - 3600, 10);
        $this->assertCount(1, $agg);
        $this->assertSame(2, $agg[0]['count']);
        $this->assertSame('warning|same msg', $agg[0]['fingerprint']);
    }

    public function testSummarizeAirportHealth_countsAndWorst(): void
    {
        $airports = [
            [
                'id' => 'KAAA',
                'status' => 'operational',
                'components' => [
                    ['name' => 'Weather', 'status' => 'operational', 'message' => 'OK'],
                ],
            ],
            [
                'id' => 'KBBB',
                'status' => 'degraded',
                'components' => [
                    ['name' => 'Weather', 'status' => 'degraded', 'message' => 'Stale'],
                ],
            ],
        ];
        $sum = operations_snapshot_summarize_airport_health($airports, 5);
        $this->assertSame(1, $sum['counts']['operational']);
        $this->assertSame(1, $sum['counts']['degraded']);
        $this->assertCount(1, $sum['worst']);
        $this->assertSame('KBBB', $sum['worst'][0]['id']);
        $this->assertStringContainsString('Stale', $sum['worst'][0]['message']);
    }

    public function testWeatherCircuitBySource_sumsRecentBuckets(): void
    {
        $h = gmdate('Y-m-d-H');
        $prev = gmdate('Y-m-d-H', time() - 3600);
        $data = [
            'hourly_buckets' => [
                $prev => ['circuit_open_tempest' => 2, 'circuit_open_events' => 2],
                $h => ['circuit_open_tempest' => 3, 'circuit_open_ambient' => 1, 'circuit_open_events' => 4],
            ],
        ];
        $by = operations_snapshot_weather_circuit_by_source($data);
        $this->assertSame(5, $by['tempest']);
        $this->assertSame(1, $by['ambient']);
    }

    public function testVerboseDetailWarranted_whenWeatherDegraded(): void
    {
        $data = [
            'uptime_layer' => ['system' => ['status' => 'operational'], 'public_api' => ['status' => 'operational']],
            'data_plane' => [
                'airport_summary' => ['counts' => ['degraded' => 0, 'down' => 0]],
                'weather' => ['status' => 'degraded', 'message' => 'x', 'metrics' => []],
                'variant' => ['status' => 'operational'],
            ],
            'details' => ['log_fingerprints' => []],
        ];
        $this->assertTrue(operations_snapshot_verbose_detail_warranted($data));
    }

    public function testVerboseDetailWarranted_falseWhenAllOperational(): void
    {
        $data = [
            'uptime_layer' => ['system' => ['status' => 'operational'], 'public_api' => ['status' => 'operational']],
            'data_plane' => [
                'airport_summary' => ['counts' => ['degraded' => 0, 'down' => 0]],
                'weather' => ['status' => 'operational', 'metrics' => ['circuit_open_events_last_hour' => 0]],
                'variant' => ['status' => 'operational'],
            ],
            'details' => ['log_fingerprints' => []],
        ];
        $this->assertFalse(operations_snapshot_verbose_detail_warranted($data));
    }

    public function testVerboseDetailWarranted_emptyDataDoesNotError(): void
    {
        $this->assertFalse(operations_snapshot_verbose_detail_warranted([]));
    }

    public function testApplyVerboseGate_removesDetailsWhenNotVerbose(): void
    {
        $data = [
            'details' => [
                'log_fingerprints' => [['fingerprint' => 'a']],
                'airport_worst' => [['id' => 'KZZZ']],
                'weather_circuit_by_source' => ['tempest' => 1],
            ],
        ];
        $out = operations_snapshot_apply_verbose_gate($data, false);
        $this->assertArrayNotHasKey('log_fingerprints', $out['details']);
        $this->assertArrayNotHasKey('airport_worst', $out['details']);
        $this->assertArrayNotHasKey('weather_circuit_by_source', $out['details']);
    }

    public function testBuildAndWriteEnvelope_roundTrip(): void
    {
        $tmp = sys_get_temp_dir() . '/awx_ops_test_' . bin2hex(random_bytes(4));
        $this->assertTrue(@mkdir($tmp, 0755, true));

        try {
            $sys = json_encode([
                'cached_at' => time(),
                'ttl' => 600,
                'key' => 'x',
                'data' => ['status' => 'operational', 'components' => []],
            ], JSON_THROW_ON_ERROR);
            file_put_contents($tmp . '/status_system_health.json', $sys);
            file_put_contents($tmp . '/public_api_health.json', json_encode([
                'cached_at' => time(),
                'ttl' => 600,
                'key' => 'x',
                'data' => ['status' => 'operational', 'endpoints' => []],
            ], JSON_THROW_ON_ERROR));
            file_put_contents($tmp . '/status_airport_health.json', json_encode([
                'cached_at' => time(),
                'ttl' => 600,
                'key' => 'x',
                'data' => [],
            ], JSON_THROW_ON_ERROR));
            file_put_contents($tmp . '/weather_health.json', json_encode([
                'health' => [
                    'name' => 'Weather Data Fetching',
                    'status' => 'operational',
                    'message' => 'OK',
                    'lastChanged' => time(),
                    'metrics' => ['circuit_open_events_last_hour' => 0],
                ],
                'hourly_buckets' => [],
            ], JSON_THROW_ON_ERROR));
            file_put_contents($tmp . '/variant_health.json', json_encode([
                'health' => [
                    'name' => 'Webcam Variant Generation',
                    'status' => 'operational',
                    'message' => 'OK',
                    'lastChanged' => time(),
                    'metrics' => [],
                ],
            ], JSON_THROW_ON_ERROR));

            $built = operations_snapshot_build($tmp, ['now' => time(), 'log_path' => '']);
            $this->assertSame(OPERATIONS_SNAPSHOT_SCHEMA_VERSION, $built['snapshot_meta']['schema_version']);
            $this->assertArrayHasKey('uptime_layer', $built);

            $snap = $tmp . '/operations_snapshot.json';
            $this->assertTrue(operations_snapshot_write_envelope($snap, $built, OPERATIONS_SNAPSHOT_MAX_AGE_SECONDS));
            $env = operations_snapshot_read_envelope($snap);
            $this->assertIsArray($env);
            $this->assertArrayHasKey('data', $env);
        } finally {
            foreach (glob($tmp . '/*') ?: [] as $f) {
                @unlink($f);
            }
            @rmdir($tmp);
        }
    }

    public function testGetApiPayload_staleWhenOlderThanMaxAge(): void
    {
        $snap = sys_get_temp_dir() . '/awx_ops_api_' . bin2hex(random_bytes(4)) . '.json';
        $inner = [
            'snapshot_meta' => [
                'schema_version' => OPERATIONS_SNAPSHOT_SCHEMA_VERSION,
                'generated_at' => gmdate('c', time() - 4000),
                'generated_at_unix' => time() - 4000,
            ],
            'uptime_layer' => ['system' => ['status' => 'operational'], 'public_api' => null],
            'data_plane' => [
                'airport_summary' => ['total' => 0, 'counts' => ['operational' => 0, 'degraded' => 0, 'down' => 0, 'maintenance' => 0, 'other' => 0]],
                'weather' => ['status' => 'operational', 'message' => 'x', 'metrics' => []],
                'variant' => ['status' => 'operational'],
            ],
            'capacity_layer' => [],
            'edge_layer' => [],
            'pipeline_meta' => [],
            'details' => ['log_fingerprints' => [], 'airport_worst' => [], 'weather_circuit_by_source' => []],
        ];
        $payload = [
            'cached_at' => time() - 4000,
            'ttl' => OPERATIONS_SNAPSHOT_MAX_AGE_SECONDS,
            'key' => 'operations_snapshot',
            'data' => $inner,
        ];
        file_put_contents($snap, json_encode($payload, JSON_THROW_ON_ERROR));

        $api = operations_snapshot_get_api_payload($snap);
        $this->assertSame('stale', $api['operations']['snapshot_meta']['freshness']);
        @unlink($snap);
    }

    public function testGetApiPayload_missingReturnsFreshnessMissing(): void
    {
        $api = operations_snapshot_get_api_payload(sys_get_temp_dir() . '/nonexistent_ops_snapshot.json');
        $this->assertSame('missing', $api['operations']['snapshot_meta']['freshness']);
    }
}
