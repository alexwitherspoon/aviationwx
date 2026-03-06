<?php
/**
 * Public API Weather Formatting
 *
 * Shared formatting for weather responses across Weather, Bulk, and Embed endpoints.
 * Ensures explicit values for safety-critical data in all API responses.
 */

/** Sector labels for 16-point wind rose (N, NNE, NE, ... NNW) */
const WIND_ROSE_SECTOR_LABELS = ['N', 'NNE', 'NE', 'ENE', 'E', 'ESE', 'SE', 'SSE', 'S', 'SSW', 'SW', 'WSW', 'W', 'WNW', 'NW', 'NNW'];

/**
 * Format last_hour_wind as object with sectors, reference, and unit
 *
 * @param array|null $petals Raw 16-sector array or null
 * @return array|null { sectors, sector_labels, reference, unit } or null
 */
function formatLastHourWindForApi(?array $petals): ?array
{
    if ($petals === null || !is_array($petals) || count($petals) !== 16) {
        return null;
    }
    return [
        'sectors' => array_values($petals),
        'sector_labels' => WIND_ROSE_SECTOR_LABELS,
        'reference' => 'magnetic_north',
        'unit' => 'knots',
    ];
}

/**
 * Format wind_direction as object for API response
 *
 * @param array $weather Raw weather data from cache
 * @return array { true_north, magnetic_north, variable }
 */
function formatWindDirectionForApi(array $weather): array
{
    $wdRaw = $weather['wind_direction'] ?? null;
    $isVRB = ($weather['wind_direction_text'] ?? '') === 'VRB'
        || (is_string($wdRaw) && strtoupper($wdRaw) === 'VRB');

    $trueNorth = null;
    $magneticNorth = null;
    if (!$isVRB) {
        $trueNorth = is_numeric($weather['wind_direction'] ?? null)
            ? (int) round((float) $weather['wind_direction'])
            : null;
        $magneticNorth = $weather['wind_direction_magnetic'] ?? null;
    }

    return [
        'true_north' => $trueNorth,
        'magnetic_north' => $magneticNorth,
        'variable' => $isVRB,
    ];
}

/**
 * Format weather data for API response
 *
 * Wind direction: true_north (degrees 0-360, internal storage), magnetic_north
 * (degrees 0-360, pilot-facing display), variable (true when METAR reports VRB).
 *
 * @param array $weather Raw weather data from cache
 * @param array $airport Airport configuration
 * @return array Formatted weather data
 */
function formatWeatherResponse(array $weather, array $airport): array
{
    return [
        'flight_category' => $weather['flight_category'] ?? null,
        'temperature' => $weather['temperature'] ?? null,
        'temperature_f' => $weather['temperature_f'] ?? null,
        'dewpoint' => $weather['dewpoint'] ?? null,
        'dewpoint_f' => $weather['dewpoint_f'] ?? null,
        'dewpoint_spread' => $weather['dewpoint_spread'] ?? null,
        'humidity' => $weather['humidity'] ?? null,
        'wind_speed' => $weather['wind_speed'] ?? null,
        'wind_direction' => formatWindDirectionForApi($weather),
        'gust_speed' => $weather['gust_speed'] ?? null,
        'gust_factor' => $weather['gust_factor'] ?? null,
        'pressure' => $weather['pressure'] ?? null,
        'visibility' => $weather['visibility'] ?? null,
        'visibility_greater_than' => $weather['visibility_greater_than'] ?? false,
        'ceiling' => $weather['ceiling'] ?? null,
        'cloud_cover' => $weather['cloud_cover'] ?? null,
        'precip_accum' => $weather['precip_accum'] ?? null,
        'density_altitude' => $weather['density_altitude'] ?? null,
        'pressure_altitude' => $weather['pressure_altitude'] ?? null,
        'sunrise' => $weather['sunrise'] ?? null,
        'sunset' => $weather['sunset'] ?? null,
        'daily' => [
            'temp_high' => $weather['temp_high_today'] ?? null,
            'temp_high_time' => isset($weather['temp_high_ts'])
                ? gmdate('c', $weather['temp_high_ts'])
                : null,
            'temp_low' => $weather['temp_low_today'] ?? null,
            'temp_low_time' => isset($weather['temp_low_ts'])
                ? gmdate('c', $weather['temp_low_ts'])
                : null,
            'peak_gust' => $weather['peak_gust_today'] ?? null,
            'peak_gust_time' => isset($weather['peak_gust_time'])
                ? gmdate('c', $weather['peak_gust_time'])
                : null,
        ],
        'observation_time' => isset($weather['obs_time_primary'])
            ? gmdate('c', $weather['obs_time_primary'])
            : null,
        'last_updated' => isset($weather['last_updated'])
            ? gmdate('c', $weather['last_updated'])
            : null,
        'last_hour_wind' => formatLastHourWindForApi($weather['last_hour_wind'] ?? null),
        '_field_obs_time_map' => $weather['_field_obs_time_map'] ?? [],
    ];
}
