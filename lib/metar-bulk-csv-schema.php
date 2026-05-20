<?php
/**
 * AWC `metars.cache.csv.gz` CSV header contract. Ingest rejects rows unless the file header matches exactly.
 *
 * Source: https://aviationweather.gov/data/cache/metars.cache.csv.gz (first line). Update this module and
 * `tests/Fixtures/metar-bulk-csv-header-line.txt` together when AWC changes the public schema.
 */

/**
 * Canonical column names in order (44 columns, including repeated sky_cover / cloud_base_ft_agl pairs).
 *
 * @return list<string>
 */
function metarBulkCsvExpectedHeaderColumns(): array
{
    return [
        'raw_text',
        'station_id',
        'observation_time',
        'latitude',
        'longitude',
        'temp_c',
        'dewpoint_c',
        'wind_dir_degrees',
        'wind_speed_kt',
        'wind_gust_kt',
        'visibility_statute_mi',
        'altim_in_hg',
        'sea_level_pressure_mb',
        'corrected',
        'auto',
        'auto_station',
        'maintenance_indicator_on',
        'no_signal',
        'lightning_sensor_off',
        'freezing_rain_sensor_off',
        'present_weather_sensor_off',
        'wx_string',
        'sky_cover',
        'cloud_base_ft_agl',
        'sky_cover',
        'cloud_base_ft_agl',
        'sky_cover',
        'cloud_base_ft_agl',
        'sky_cover',
        'cloud_base_ft_agl',
        'flight_category',
        'three_hr_pressure_tendency_mb',
        'maxT_c',
        'minT_c',
        'maxT24hr_c',
        'minT24hr_c',
        'precip_in',
        'pcp3hr_in',
        'pcp6hr_in',
        'pcp24hr_in',
        'snow_in',
        'vert_vis_ft',
        'metar_type',
        'elevation_m',
    ];
}

/**
 * Expected column count for AWC METAR bulk CSV rows (including header row).
 */
function metarBulkCsvExpectedColumnCount(): int
{
    return count(metarBulkCsvExpectedHeaderColumns());
}

/**
 * Strip UTF-8 BOM from the first header cell when present (AWC may change encoding).
 *
 * @param array<int, string|null> $headerRow
 * @return array<int, string|null>
 */
function metarBulkCsvNormalizeHeaderRow(array $headerRow): array
{
    if (isset($headerRow[0]) && is_string($headerRow[0])) {
        $headerRow[0] = preg_replace('/^\xEF\xBB\xBF/', '', $headerRow[0]) ?? $headerRow[0];
    }

    return $headerRow;
}

/**
 * True when the first CSV row from AWC matches the canonical header (strict, length and per-cell equality).
 *
 * @param array<int, string|null> $headerRow
 */
function metarBulkCsvHeaderMatchesExpected(array $headerRow): bool
{
    $headerRow = metarBulkCsvNormalizeHeaderRow($headerRow);
    $expected = metarBulkCsvExpectedHeaderColumns();
    if (count($headerRow) !== count($expected)) {
        return false;
    }
    foreach ($expected as $i => $name) {
        if (($headerRow[$i] ?? null) !== $name) {
            return false;
        }
    }

    return true;
}

/**
 * Short summary when `metarBulkCsvHeaderMatchesExpected()` is false (for ops logs).
 *
 * @param array<int, string|null> $headerRow
 */
function metarBulkCsvDescribeHeaderMismatch(array $headerRow): string
{
    $headerRow = metarBulkCsvNormalizeHeaderRow($headerRow);
    $expected = metarBulkCsvExpectedHeaderColumns();
    $gotCount = count($headerRow);
    $expectedCount = count($expected);
    if ($gotCount !== $expectedCount) {
        return sprintf('column_count got=%d expected=%d', $gotCount, $expectedCount);
    }
    $parts = [];
    foreach ($expected as $i => $name) {
        if (($headerRow[$i] ?? null) !== $name) {
            $got = $headerRow[$i] ?? '(null)';
            $parts[] = sprintf('col%d:%s!=%s', $i, $got, $name);
            if (count($parts) >= 5) {
                $parts[] = '...';

                break;
            }
        }
    }

    return $parts === [] ? 'unknown_mismatch' : implode('; ', $parts);
}

/**
 * Map each column name to zero-based indices (handles duplicate header names).
 *
 * @param list<string> $headerColumns
 * @return array<string, list<int>>
 */
function metarBulkCsvBuildColumnIndexLists(array $headerColumns): array
{
    $lists = [];
    foreach ($headerColumns as $i => $name) {
        $key = (string) $name;
        if (!isset($lists[$key])) {
            $lists[$key] = [];
        }
        $lists[$key][] = (int) $i;
    }

    return $lists;
}
