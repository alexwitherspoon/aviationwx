<?php
/**
 * Unit tests: VRM provider normalization to canonical station-power JSON.
 */

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../lib/constants.php';
require_once __DIR__ . '/../../lib/station-power/provider/VrmStationPowerProvider.php';

final class StationPowerVrmNormalizeTest extends TestCase
{
    public function testNormalizeFromStatsMapsLastRowTupleIndexOne(): void
    {
        $raw = json_decode(
            file_get_contents(__DIR__ . '/../Fixtures/station-power-vrm-stats-sample.json'),
            true
        );
        $this->assertIsArray($raw);

        $canonical = VrmStationPowerProvider::normalizeFromStatsResponse(
            $raw,
            null,
            1_700_000_100
        );

        $this->assertSame('vrm', $canonical['provider']);
        $this->assertSame(1_700_000_100, $canonical['fetched_at']);
        $this->assertSame(1700000000000, $canonical['sample_time_ms']);
        $this->assertSame(85.5, $canonical['battery_soc_percent']);
        $this->assertSame(120.0, $canonical['load_watts']);
        $this->assertSame(0.45, $canonical['solar_pc_watts']);
        $this->assertSame(0.12, $canonical['solar_pb_watts']);
        $this->assertNull($canonical['battery_volts']);
        $this->assertNull($canonical['time_to_go_display']);
        $this->assertSame('15mins', $canonical['stats_interval']);
    }

    public function testNormalizeWithBatterySummaryWidgetExtractsVoltsAndTtg(): void
    {
        $stats = ['success' => true, 'records' => ['bs' => [[1700000000000, 90.0, 90.0, 90.0]]]];
        $widget = [
            'success' => true,
            'records' => [
                'data' => [
                    '47' => ['value' => 52.1, 'formattedValue' => '52.10 V'],
                    '52' => ['value' => 3600, 'formattedValue' => '1h'],
                ],
            ],
        ];

        $canonical = VrmStationPowerProvider::normalizeFromStatsResponse($stats, $widget, 1000);

        $this->assertSame(52.1, $canonical['battery_volts']);
        $this->assertSame('1h', $canonical['time_to_go_display']);
    }
}
