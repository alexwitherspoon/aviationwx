<?php
/**
 * Normalize aggregate `last_updated` / `last_updated_iso` for weather pipeline output.
 *
 * Policy matches `public/js/weather-timestamp-utils.js` (pickWeatherUnixTimestamp): use the
 * maximum of all positive, finite candidate timestamps so the overall label reflects the
 * freshest observation metadata present (field obs times, primary/metar fetch times, obs times).
 *
 * @package AviationWX\Weather
 */

declare(strict_types=1);

require_once __DIR__ . '/metar-completeness-aggregate.php';

/**
 * Extract a positive Unix second from a scalar map/API value, or null if unusable.
 *
 * Reuses normalizeAggregateFieldObsTime coercion rules, then rejects non-positive values.
 *
 * @param mixed $value Raw timestamp from aggregate data
 * @return int|null Unix seconds strictly greater than zero, or null
 */
function weather_positive_aggregate_timestamp(mixed $value): ?int
{
    $n = normalizeAggregateFieldObsTime($value);
    if ($n === null || $n <= 0) {
        return null;
    }

    return $n;
}

/**
 * Set last_updated and last_updated_iso from all valid timestamp fields in aggregate data.
 *
 * Modifies $data in place. When no positive candidate exists, uses $fallbackNow (typically
 * aggregator "now" or time() in addCalculatedFields).
 *
 * @param array<string, mixed> $data Weather aggregate (modified in place)
 * @param int $fallbackNow Unix seconds when no valid candidate is found
 * @return void
 */
function normalizeAggregateLastUpdatedTimes(array &$data, int $fallbackNow): void
{
    $candidates = [];
    $keys = [
        'last_updated',
        'last_updated_primary',
        'last_updated_metar',
        'obs_time_primary',
        'obs_time_metar',
    ];

    foreach ($keys as $key) {
        if (!array_key_exists($key, $data)) {
            continue;
        }
        $p = weather_positive_aggregate_timestamp($data[$key]);
        if ($p !== null) {
            $candidates[] = $p;
        }
    }

    $map = $data['_field_obs_time_map'] ?? null;
    if (is_array($map)) {
        foreach ($map as $v) {
            $p = weather_positive_aggregate_timestamp($v);
            if ($p !== null) {
                $candidates[] = $p;
            }
        }
    }

    if ($candidates === []) {
        $data['last_updated'] = $fallbackNow;
    } else {
        $data['last_updated'] = max($candidates);
    }

    $data['last_updated_iso'] = date('c', $data['last_updated']);
}
