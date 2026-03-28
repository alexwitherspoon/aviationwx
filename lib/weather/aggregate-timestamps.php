<?php
/**
 * Normalize aggregate `last_updated` / `last_updated_iso` for weather pipeline output.
 *
 * Policy: max of positive candidates so aggregate `last_updated` reflects the freshest metadata
 * in the payload (field obs times, primary/metar fetch times, obs times). This is intentional:
 * APIs and staleness checks need a single "newest timestamp" in the blob, and that may be a
 * fetch time when observation metadata is older or missing.
 *
 * Do not assume aggregate `last_updated` is safe to show as "when conditions were observed".
 * The airport dashboard uses `pickObservationUnixTimestamp` in `weather-timestamp-utils.js`
 * for that (observation max, else fetch). See docs/DATA_FLOW.md#airport-last-updated-observation-vs-fetch-time.
 * Digit strings are accepted in the browser helper when JSON stores them as strings.
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
 * aggregator "now" or time() in addCalculatedFields). The file-level docblock explains why this
 * max-of-all stamp must not be confused with the airport UI "Last updated" label.
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
