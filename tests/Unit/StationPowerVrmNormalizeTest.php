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
    public function testNormalizeFromStatsMapsLastRowAndKwToWatts(): void
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
        // Last column is avg in [ts, min, max, avg] rows
        $this->assertSame(85.6, $canonical['battery_soc_percent']);
        // consumption and solar_yield are watts; Pc/Pb are kW converted to watts
        $this->assertSame(120.0, $canonical['load_watts']);
        $this->assertSame(570.0, $canonical['solar_total_watts']);
        $this->assertSame(450.0, $canonical['solar_pc_watts']);
        $this->assertSame(120.0, $canonical['solar_pb_watts']);
        $this->assertNull($canonical['battery_volts']);
        $this->assertNull($canonical['time_to_go_display']);
        $this->assertSame('15mins', $canonical['stats_interval']);
        $this->assertNull($canonical['solar_daily_kwh_utc']);
        $this->assertNull($canonical['load_daily_kwh_utc']);
        $this->assertNull($canonical['solar_daily_wh_local']);
        $this->assertNull($canonical['load_daily_wh_local']);
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

    public function testNormalizePrefersSolarYieldOverPcPlusPb(): void
    {
        $stats = [
            'success' => true,
            'records' => [
                'bs' => [[1700000000000, 50.0, 50.0, 50.0]],
                'consumption' => [[1700000000000, 44.0, 45.0, 44.731]],
                'solar_yield' => [[1700000000000, 55.0, 58.0, 57.0]],
                'Pc' => [[1700000000000, 0.01, 0.02, 0.01]],
                'Pb' => [[1700000000000, 0.0, 0.0, 0.0]],
            ],
        ];

        $canonical = VrmStationPowerProvider::normalizeFromStatsResponse($stats, null, 1);

        $this->assertSame(57.0, $canonical['solar_total_watts']);
        $this->assertSame(10.0, $canonical['solar_pc_watts']);
        $this->assertSame(0.0, $canonical['solar_pb_watts']);
    }

    public function testNormalizeSolarYieldIsWattsNotKilowatts(): void
    {
        $stats = [
            'success' => true,
            'records' => [
                'bs' => [[1700000000000, 50.0, 50.0, 50.0]],
                'consumption' => [[1700000000000, 10.0, 10.0, 10.0]],
                'solar_yield' => [[1700000000000, 48.0, 52.0, 49.972]],
                'Pc' => [[1700000000000, 0.02, 0.02, 0.02]],
                'Pb' => [[1700000000000, 0.0, 0.0, 0.0]],
            ],
        ];

        $canonical = VrmStationPowerProvider::normalizeFromStatsResponse($stats, null, 1);

        $this->assertSame(49.972, $canonical['solar_total_watts']);
        $this->assertSame(20.0, $canonical['solar_pc_watts']);
    }

    public function testNormalizeConsumptionIsWattsNotKilowatts(): void
    {
        $stats = [
            'success' => true,
            'records' => [
                'bs' => [[1700000000000, 50.0, 50.0, 50.0]],
                'consumption' => [[1700000000000, 40.0, 50.0, 44.731]],
                'Pc' => [[1700000000000, 0.01, 0.02, 0.01]],
                'Pb' => [[1700000000000, 0.0, 0.0, 0.0]],
            ],
        ];

        $canonical = VrmStationPowerProvider::normalizeFromStatsResponse($stats, null, 1);

        $this->assertSame(44.731, $canonical['load_watts']);
        $this->assertSame(10.0, $canonical['solar_total_watts']);
        $this->assertSame(10.0, $canonical['solar_pc_watts']);
        $this->assertSame(0.0, $canonical['solar_pb_watts']);
    }

    public function testNormalizeWithBatterySummaryWidgetFallbackMetaIds(): void
    {
        $stats = ['success' => true, 'records' => ['bs' => [[1700000000000, 90.0, 90.0, 90.0]]]];
        $widget = [
            'success' => true,
            'records' => [
                'data' => [
                    '99' => [
                        'description' => 'Battery voltage',
                        'value' => 52.1,
                        'formattedValue' => '52.10 V',
                        'unit' => 'V',
                    ],
                    '100' => [
                        'description' => 'Time to go',
                        'value' => 3600,
                        'formattedValue' => '1h',
                    ],
                ],
            ],
        ];

        $canonical = VrmStationPowerProvider::normalizeFromStatsResponse($stats, $widget, 1000);

        $this->assertSame(52.1, $canonical['battery_volts']);
        $this->assertSame('1h', $canonical['time_to_go_display']);
    }

    public function testMergeOverallstatsUsesSolarYieldAndConsumption(): void
    {
        $base = VrmStationPowerProvider::normalizeFromStatsResponse(
            ['success' => true, 'records' => ['bs' => [[1700000000000, 90.0, 90.0, 90.0]]]],
            null,
            1000
        );
        $overall = [
            'today' => [
                'totals' => [
                    'solar_yield' => 12.5,
                    'consumption' => 8.25,
                ],
            ],
        ];

        $merged = VrmStationPowerProvider::mergeOverallstatsIntoCanonical($base, $overall);

        $this->assertSame(12.5, $merged['solar_daily_kwh_utc']);
        $this->assertSame(8.25, $merged['load_daily_kwh_utc']);
    }

    public function testMergeOverallstatsFallbackPcPlusPbWhenNoSolarYield(): void
    {
        $base = VrmStationPowerProvider::normalizeFromStatsResponse(
            ['success' => true, 'records' => ['bs' => [[1700000000000, 90.0, 90.0, 90.0]]]],
            null,
            1000
        );
        $overall = [
            'records' => [
                'today' => [
                    'totals' => [
                        'Pc' => 6.33,
                        'Pb' => 7.77,
                        'consumption' => 4.1,
                    ],
                ],
            ],
        ];

        $merged = VrmStationPowerProvider::mergeOverallstatsIntoCanonical($base, $overall);

        $this->assertSame(14.1, $merged['solar_daily_kwh_utc']);
        $this->assertSame(4.1, $merged['load_daily_kwh_utc']);
    }

    public function testMergeLocalDailyWhFromHourlyStatsUsesLocalMidnightToNow(): void
    {
        $base = VrmStationPowerProvider::normalizeFromStatsResponse(
            ['success' => true, 'records' => ['bs' => [[1700000000000, 90.0, 90.0, 90.0]]]],
            null,
            1000
        );
        $tsMs = strtotime('2024-01-15 12:00:00 UTC') * 1000;
        $hourly = [
            'success' => true,
            'records' => [
                'solar_yield' => [[$tsMs, 100.0, 100.0, 100.0]],
                'consumption' => [[$tsMs, 50.0, 50.0, 50.0]],
            ],
        ];

        $merged = VrmStationPowerProvider::mergeLocalDailyWhFromStats(
            $base,
            $hourly,
            'UTC',
            strtotime('2024-01-15 14:00:00 UTC')
        );

        $this->assertSame(100.0, $merged['solar_daily_wh_local']);
        $this->assertSame(50.0, $merged['load_daily_wh_local']);
    }

    public function testMergeLocalDailyWhFromHourlyStatsPcPbFallbackKilowatts(): void
    {
        $base = VrmStationPowerProvider::normalizeFromStatsResponse(
            ['success' => true, 'records' => ['bs' => [[1700000000000, 90.0, 90.0, 90.0]]]],
            null,
            1000
        );
        $tsMs = strtotime('2024-01-15 12:00:00 UTC') * 1000;
        $hourly = [
            'success' => true,
            'records' => [
                'Pc' => [[$tsMs, 0.1, 0.1, 0.1]],
                'Pb' => [[$tsMs, 0.02, 0.02, 0.02]],
                'consumption' => [[$tsMs, 30.0, 30.0, 30.0]],
            ],
        ];

        $merged = VrmStationPowerProvider::mergeLocalDailyWhFromStats(
            $base,
            $hourly,
            'UTC',
            strtotime('2024-01-15 14:00:00 UTC')
        );

        $this->assertSame(120.0, $merged['solar_daily_wh_local']);
        $this->assertSame(30.0, $merged['load_daily_wh_local']);
    }

    public function testMergeDiagnosticsFillsNullBatteryVoltsAndTtg(): void
    {
        $base = VrmStationPowerProvider::normalizeFromStatsResponse(
            ['success' => true, 'records' => ['bs' => [[1700000000000, 90.0, 90.0, 90.0]]]],
            null,
            1000
        );
        $this->assertNull($base['battery_volts']);
        $this->assertNull($base['time_to_go_display']);

        $diag = json_decode(
            file_get_contents(__DIR__ . '/../Fixtures/station-power-vrm-diagnostics-sample.json'),
            true
        );
        $this->assertIsArray($diag);

        $merged = VrmStationPowerProvider::mergeDiagnosticsBatteryFields($base, $diag);

        $this->assertSame(26.29, $merged['battery_volts']);
        $this->assertSame('240h', $merged['time_to_go_display']);
    }

    public function testNormalizeTimeToGoDisplayRoundsHoursAndStripsDecimals(): void
    {
        $this->assertSame('240', VrmStationPowerProvider::normalizeTimeToGoDisplay('240.00 hrs'));
        $this->assertSame('240', VrmStationPowerProvider::normalizeTimeToGoDisplay('240h'));
        $this->assertSame('2', VrmStationPowerProvider::normalizeTimeToGoDisplay('1.5 hours'));
        $this->assertSame('1', VrmStationPowerProvider::normalizeTimeToGoDisplay('1h'));
        $this->assertNull(VrmStationPowerProvider::normalizeTimeToGoDisplay('0.00 hrs'));
        $this->assertNull(VrmStationPowerProvider::normalizeTimeToGoDisplay(null));
        $this->assertSame('about 2 days', VrmStationPowerProvider::normalizeTimeToGoDisplay('about 2 days'));
    }

    public function testNormalizeStripsUselessZeroTtgFromWidget(): void
    {
        $stats = ['success' => true, 'records' => ['bs' => [[1700000000000, 90.0, 90.0, 90.0]]]];
        $widget = [
            'success' => true,
            'records' => [
                'data' => [
                    '47' => ['value' => 52.1, 'formattedValue' => '52.10 V'],
                    '52' => ['value' => 0, 'formattedValue' => '0.00 hrs'],
                ],
            ],
        ];

        $canonical = VrmStationPowerProvider::normalizeFromStatsResponse($stats, $widget, 1000);

        $this->assertSame(52.1, $canonical['battery_volts']);
        $this->assertNull($canonical['time_to_go_display']);
    }

    public function testMergeDiagnosticsFillsTtgWhenWidgetOnlySentZeroHours(): void
    {
        $stats = ['success' => true, 'records' => ['bs' => [[1700000000000, 90.0, 90.0, 90.0]]]];
        $widget = [
            'success' => true,
            'records' => [
                'data' => [
                    '47' => ['value' => 52.1, 'formattedValue' => '52.10 V'],
                    '52' => ['value' => 0, 'formattedValue' => '0.00 hrs'],
                ],
            ],
        ];
        $base = VrmStationPowerProvider::normalizeFromStatsResponse($stats, $widget, 1000);
        $this->assertNull($base['time_to_go_display']);

        $diag = json_decode(
            file_get_contents(__DIR__ . '/../Fixtures/station-power-vrm-diagnostics-sample.json'),
            true
        );
        $this->assertIsArray($diag);

        $merged = VrmStationPowerProvider::mergeDiagnosticsBatteryFields($base, $diag);

        $this->assertSame('240h', $merged['time_to_go_display']);
    }

    public function testDiagnosticsSkipsFirstUselessTtgRowWhenLaterRowIsValid(): void
    {
        $diag = [
            'success' => true,
            'records' => [
                [
                    'code' => 'ttg',
                    'name' => 'Time to go',
                    'formattedValue' => '0.00 hrs',
                ],
                [
                    'code' => 'ttg',
                    'name' => 'Time to go',
                    'formattedValue' => '240h',
                ],
            ],
        ];
        $base = VrmStationPowerProvider::normalizeFromStatsResponse(
            ['success' => true, 'records' => ['bs' => [[1700000000000, 90.0, 90.0, 90.0]]]],
            null,
            1000
        );

        $merged = VrmStationPowerProvider::mergeDiagnosticsBatteryFields($base, $diag);

        $this->assertSame('240h', $merged['time_to_go_display']);
    }

    public function testMergeDiagnosticsDoesNotOverwriteExistingWidgetValues(): void
    {
        $widget = [
            'success' => true,
            'records' => [
                'data' => [
                    '47' => ['value' => 52.1, 'formattedValue' => '52.10 V'],
                    '52' => ['value' => 3600, 'formattedValue' => '1h'],
                ],
            ],
        ];
        $base = VrmStationPowerProvider::normalizeFromStatsResponse(
            ['success' => true, 'records' => ['bs' => [[1700000000000, 90.0, 90.0, 90.0]]]],
            $widget,
            1000
        );
        $diag = json_decode(
            file_get_contents(__DIR__ . '/../Fixtures/station-power-vrm-diagnostics-sample.json'),
            true
        );
        $merged = VrmStationPowerProvider::mergeDiagnosticsBatteryFields($base, is_array($diag) ? $diag : null);

        $this->assertSame(52.1, $merged['battery_volts']);
        $this->assertSame('1h', $merged['time_to_go_display']);
    }

    public function testWidgetBatteryVoltageUsesMaxWhenCellAndBankPresent(): void
    {
        $widget = [
            'success' => true,
            'records' => [
                'data' => [
                    '47' => [
                        'description' => 'Cell voltage',
                        'unit' => 'V',
                        'value' => 4.2,
                        'formattedValue' => '4.20 V',
                    ],
                    '99' => [
                        'description' => 'Battery voltage',
                        'unit' => 'V',
                        'value' => 26.35,
                        'formattedValue' => '26.35 V',
                    ],
                    '52' => ['value' => 3600, 'formattedValue' => '240h'],
                ],
            ],
        ];

        $canonical = VrmStationPowerProvider::normalizeFromStatsResponse(
            ['success' => true, 'records' => ['bs' => [[1700000000000, 90.0, 90.0, 90.0]]]],
            $widget,
            1000
        );

        $this->assertSame(26.35, $canonical['battery_volts']);
        $this->assertSame('240h', $canonical['time_to_go_display']);
    }

    public function testMergeDiagnosticsReplacesSuspectLowVoltageWithBankReading(): void
    {
        $base = [
            'battery_volts' => 4.2,
            'time_to_go_display' => '240h',
        ];
        $diag = json_decode(
            file_get_contents(__DIR__ . '/../Fixtures/station-power-vrm-diagnostics-sample.json'),
            true
        );
        $this->assertIsArray($diag);
        $merged = VrmStationPowerProvider::mergeDiagnosticsBatteryFields($base, $diag);

        $this->assertSame(26.29, $merged['battery_volts']);
    }
}
