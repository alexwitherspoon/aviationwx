<?php
/**
 * Dashboard display strings for canonical station power rows (PHP + tests).
 * Keeps server-rendered values aligned with documented cache shape.
 */

declare(strict_types=1);

/**
 * Format all station power cells for the airport dashboard (matches dashboard rules).
 *
 * @param array<string,mixed> $sp Canonical station power cache row; empty array yields all "---"
 * @return array{
 *   solar_now: string,
 *   solar_today: string,
 *   solar_today_title: string,
 *   dc_load_now: string,
 *   load_today: string,
 *   load_today_title: string,
 *   battery_volts: string,
 *   ttg: string,
 *   soc_text: string,
 *   soc_meter_class: string,
 *   soc_meter_value: string,
 *   soc_meter_inner_text: string,
 *   soc_show_meter: bool
 * }
 */
function stationPowerDashboardFormatCells(array $sp): array
{
    $fmtW = static function ($v): string {
        if ($v === null || !is_numeric($v)) {
            return '---';
        }

        return htmlspecialchars((string) round((float) $v, 0), ENT_QUOTES, 'UTF-8') . ' W';
    };
    $fmtWh = static function ($v): string {
        if ($v === null || !is_numeric($v)) {
            return '---';
        }

        return htmlspecialchars((string) round((float) $v, 0), ENT_QUOTES, 'UTF-8') . ' Wh';
    };
    $fmtBatteryVolts = static function ($v): string {
        if ($v === null || !is_numeric($v)) {
            return '---';
        }

        return htmlspecialchars((string) round((float) $v, 1), ENT_QUOTES, 'UTF-8') . 'v';
    };
    $fmtSoc = static function ($v): string {
        if ($v === null || !is_numeric($v)) {
            return '---';
        }

        return htmlspecialchars((string) round((float) $v, 1), ENT_QUOTES, 'UTF-8') . '%';
    };
    $fmtTtg = static function (?string $s): string {
        if ($s === null) {
            return '---';
        }
        $t = trim($s);
        if ($t === '') {
            return '---';
        }
        if (ctype_digit($t)) {
            $n = (int) $t;
            $unit = $n === 1 ? 'hr' : 'hrs';

            return htmlspecialchars((string) $n, ENT_QUOTES, 'UTF-8') . ' ' . $unit;
        }
        if (preg_match('/^(\d+)\s*h$/i', $t, $m)) {
            $n = (int) $m[1];
            $unit = $n === 1 ? 'hr' : 'hrs';

            return htmlspecialchars((string) $n, ENT_QUOTES, 'UTF-8') . ' ' . $unit;
        }

        return htmlspecialchars($t, ENT_QUOTES, 'UTF-8');
    };

    $socVal = isset($sp['battery_soc_percent']) && is_numeric($sp['battery_soc_percent'])
        ? max(0.0, min(100.0, (float) $sp['battery_soc_percent']))
        : null;
    $socMeterClass = 'station-power-meter';
    if ($socVal !== null) {
        if ($socVal < 40.0) {
            $socMeterClass .= ' station-power-meter--low';
        } elseif ($socVal < 60.0) {
            $socMeterClass .= ' station-power-meter--medium';
        }
    }

    $solarTotalWatts = null;
    if (isset($sp['solar_total_watts']) && is_numeric($sp['solar_total_watts'])) {
        $solarTotalWatts = (float) $sp['solar_total_watts'];
    } else {
        $pcW = isset($sp['solar_pc_watts']) && is_numeric($sp['solar_pc_watts']) ? (float) $sp['solar_pc_watts'] : null;
        $pbW = isset($sp['solar_pb_watts']) && is_numeric($sp['solar_pb_watts']) ? (float) $sp['solar_pb_watts'] : null;
        if ($pcW !== null || $pbW !== null) {
            $solarTotalWatts = (float) ($pcW ?? 0.0) + (float) ($pbW ?? 0.0);
        }
    }

    $solarTodayWh = isset($sp['solar_daily_wh_local']) && is_numeric($sp['solar_daily_wh_local'])
        ? (float) $sp['solar_daily_wh_local']
        : null;
    $solarTodayTitle = 'Cumulative solar energy from local midnight to now (hourly buckets, airport timezone).';
    if ($solarTodayWh === null && isset($sp['solar_daily_kwh_utc']) && is_numeric($sp['solar_daily_kwh_utc'])) {
        $solarTodayWh = (float) $sp['solar_daily_kwh_utc'] * 1000.0;
        $solarTodayTitle = 'Cumulative solar energy for the UTC calendar day (local-day hourly data unavailable).';
    }

    $loadTodayWh = isset($sp['load_daily_wh_local']) && is_numeric($sp['load_daily_wh_local'])
        ? (float) $sp['load_daily_wh_local']
        : null;
    $loadTodayTitle = 'Cumulative DC energy from local midnight to now (hourly buckets, airport timezone).';
    if ($loadTodayWh === null && isset($sp['load_daily_kwh_utc']) && is_numeric($sp['load_daily_kwh_utc'])) {
        $loadTodayWh = (float) $sp['load_daily_kwh_utc'] * 1000.0;
        $loadTodayTitle = 'Cumulative DC energy for the UTC calendar day (local-day hourly data unavailable).';
    }

    $ttgDisplay = isset($sp['time_to_go_display']) && is_string($sp['time_to_go_display']) && $sp['time_to_go_display'] !== ''
        ? $sp['time_to_go_display']
        : null;

    $socShowMeter = $socVal !== null;

    return [
        'solar_now' => $fmtW($solarTotalWatts),
        'solar_today' => $fmtWh($solarTodayWh),
        'solar_today_title' => $solarTodayTitle,
        'dc_load_now' => $fmtW($sp['load_watts'] ?? null),
        'load_today' => $fmtWh($loadTodayWh),
        'load_today_title' => $loadTodayTitle,
        'battery_volts' => $fmtBatteryVolts($sp['battery_volts'] ?? null),
        'ttg' => $fmtTtg($ttgDisplay),
        'soc_text' => $socVal !== null ? $fmtSoc($socVal) : '---',
        'soc_meter_class' => $socMeterClass,
        'soc_meter_value' => $socVal !== null ? htmlspecialchars((string) $socVal, ENT_QUOTES, 'UTF-8') : '0',
        'soc_meter_inner_text' => $socVal !== null
            ? htmlspecialchars((string) round($socVal, 1), ENT_QUOTES, 'UTF-8') . ' percent'
            : '0 percent',
        'soc_show_meter' => $socShowMeter,
    ];
}
