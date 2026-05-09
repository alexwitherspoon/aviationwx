<?php
/**
 * Pure validation for spill JSON payloads (no filesystem).
 */

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../lib/constants.php';
require_once __DIR__ . '/../../lib/metrics-spill-payload.php';

class MetricsSpillPayloadParseTest extends TestCase
{
    public function testParse_ValidPayload_ReturnsCounters(): void
    {
        $hourId = '2026-07-01-12';
        $data = [
            'schema_version' => METRICS_SPILL_FILE_SCHEMA_VERSION,
            'hour_id' => $hourId,
            'counters' => ['global_page_views' => 3, 'global_weather_requests' => 2],
        ];

        $out = metrics_parse_spill_payload_for_merge($data, $hourId);
        $this->assertIsArray($out);
        $this->assertSame(3, $out['counters']['global_page_views']);
        $this->assertSame(2, $out['counters']['global_weather_requests']);
    }

    public function testParse_WrongSchema_ReturnsNull(): void
    {
        $hourId = '2026-07-01-12';
        $data = [
            'schema_version' => 99999,
            'hour_id' => $hourId,
            'counters' => ['global_page_views' => 1],
        ];

        $this->assertNull(metrics_parse_spill_payload_for_merge($data, $hourId));
    }

    public function testParse_HourIdMismatch_ReturnsNull(): void
    {
        $data = [
            'schema_version' => METRICS_SPILL_FILE_SCHEMA_VERSION,
            'hour_id' => '2026-07-01-11',
            'counters' => ['global_page_views' => 1],
        ];

        $this->assertNull(metrics_parse_spill_payload_for_merge($data, '2026-07-01-12'));
    }

    public function testParse_MissingCounters_ReturnsNull(): void
    {
        $hourId = '2026-07-01-12';
        $data = [
            'schema_version' => METRICS_SPILL_FILE_SCHEMA_VERSION,
            'hour_id' => $hourId,
        ];

        $this->assertNull(metrics_parse_spill_payload_for_merge($data, $hourId));
    }
}
