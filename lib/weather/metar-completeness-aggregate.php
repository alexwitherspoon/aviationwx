<?php
/**
 * Align METAR ICAO completeness flags with the snapshots that actually contributed
 * visibility/ceiling to the aggregated result (UnifiedFetcher).
 *
 * @package AviationWX\Weather
 */

declare(strict_types=1);

use AviationWX\Weather\Data\WeatherReading;
use AviationWX\Weather\Data\WeatherSnapshot;

/**
 * Resolve the visibility or ceiling reading used for METAR completeness attribution.
 *
 * @param WeatherSnapshot $snapshot METAR snapshot
 * @param string $fieldName Field key: `visibility` or `ceiling`
 * @return WeatherReading Reading for that field
 * @throws \InvalidArgumentException If $fieldName is not visibility or ceiling
 */
function metarCompletenessFieldReading(WeatherSnapshot $snapshot, string $fieldName): WeatherReading
{
    return match ($fieldName) {
        'visibility' => $snapshot->visibility,
        'ceiling' => $snapshot->ceiling,
        default => throw new \InvalidArgumentException(
            'METAR completeness field must be visibility or ceiling, got: ' . $fieldName
        ),
    };
}

/**
 * Normalize observation time from aggregated `_field_obs_time_map` values.
 *
 * @param mixed $value Map entry (typically int; also accepts whole float or numeric string)
 * @return int|null Unix observation time in seconds, or null if not coercible
 */
function normalizeAggregateFieldObsTime(mixed $value): ?int
{
    if (is_int($value)) {
        return $value;
    }
    if (is_float($value) && floor($value) === $value) {
        return (int) $value;
    }
    if (is_string($value) && $value !== '') {
        $v = filter_var($value, FILTER_VALIDATE_INT, FILTER_NULL_ON_FAILURE);

        return $v;
    }

    return null;
}

/**
 * When multiple METAR snapshots share a station, pick the one whose field reading matches
 * _field_obs_time_map, else the freshest field observation time (ties broken by fetchTime).
 *
 * @param array<WeatherSnapshot> $candidates Non-empty METAR snapshots for the station
 * @param string $fieldName Field key: visibility or ceiling
 * @param int|null $wantObsTime Winning field observation time from aggregation, if known
 * @return WeatherSnapshot Snapshot whose completeness flags apply to this field
 */
function pickMetarSnapshotForFieldCompleteness(array $candidates, string $fieldName, ?int $wantObsTime): WeatherSnapshot
{
    if ($fieldName !== 'visibility' && $fieldName !== 'ceiling') {
        throw new \InvalidArgumentException(
            'METAR completeness field must be visibility or ceiling, got: ' . $fieldName
        );
    }
    if (count($candidates) === 1) {
        return $candidates[0];
    }
    if ($wantObsTime !== null) {
        // First matching snapshot in candidate order aligns with WeatherAggregator when obs times tie (first wins).
        foreach ($candidates as $snapshot) {
            $reading = metarCompletenessFieldReading($snapshot, $fieldName);
            if ($reading->observationTime === $wantObsTime) {
                return $snapshot;
            }
        }
    }
    usort(
        $candidates,
        static function (WeatherSnapshot $a, WeatherSnapshot $b) use ($fieldName): int {
            $ra = metarCompletenessFieldReading($a, $fieldName);
            $rb = metarCompletenessFieldReading($b, $fieldName);
            $ta = $ra->observationTime ?? 0;
            $tb = $rb->observationTime ?? 0;
            $cmp = $tb <=> $ta;
            if ($cmp !== 0) {
                return $cmp;
            }

            return $b->fetchTime <=> $a->fetchTime;
        }
    );

    return $candidates[0];
}

/**
 * Set metar_visibility_reported / metar_ceiling_reported from the METAR snapshot that
 * matches each field's _field_station_map entry when the field source is metar.
 * Leaves flags null when the aggregated field did not come from METAR (or cannot be matched).
 *
 * @param array<string, mixed> $result Aggregated weather (must include _field_source_map)
 * @param array<WeatherSnapshot> $snapshots Parsed snapshots from the fetch
 */
function applyMetarCompletenessFlagsFromAggregation(array &$result, array $snapshots): void
{
    $result['metar_visibility_reported'] = null;
    $result['metar_ceiling_reported'] = null;

    $sourceMap = $result['_field_source_map'] ?? [];
    if (!is_array($sourceMap)) {
        return;
    }

    $stationMap = $result['_field_station_map'] ?? [];
    if (!is_array($stationMap)) {
        $stationMap = [];
    }

    $obsTimeMap = $result['_field_obs_time_map'] ?? [];
    if (!is_array($obsTimeMap)) {
        $obsTimeMap = [];
    }

    $map = [
        'visibility' => 'visibility_reported',
        'ceiling' => 'ceiling_reported',
    ];
    $outKeys = [
        'visibility' => 'metar_visibility_reported',
        'ceiling' => 'metar_ceiling_reported',
    ];

    foreach ($map as $fieldName => $completenessKey) {
        $outKey = $outKeys[$fieldName];
        if (!isset($sourceMap[$fieldName]) || $sourceMap[$fieldName] !== 'metar') {
            continue;
        }

        $wantStation = isset($stationMap[$fieldName]) && is_string($stationMap[$fieldName])
            ? trim($stationMap[$fieldName]) : null;
        if ($wantStation === '') {
            $wantStation = null;
        }

        $candidates = [];
        foreach ($snapshots as $snapshot) {
            if ($snapshot->source !== 'metar' || $snapshot->metarFieldCompleteness === null) {
                continue;
            }
            if ($wantStation !== null) {
                if ($snapshot->metarStationId === null) {
                    continue;
                }
                if (strcasecmp($snapshot->metarStationId, $wantStation) !== 0) {
                    continue;
                }
            }
            $candidates[] = $snapshot;
        }

        if (count($candidates) === 0) {
            continue;
        }
        if (count($candidates) > 1 && $wantStation === null) {
            continue;
        }

        $wantObsTime = null;
        if (array_key_exists($fieldName, $obsTimeMap)) {
            $wantObsTime = normalizeAggregateFieldObsTime($obsTimeMap[$fieldName]);
        }

        $picked = pickMetarSnapshotForFieldCompleteness($candidates, $fieldName, $wantObsTime);
        $mc = $picked->metarFieldCompleteness;
        if (is_array($mc) && array_key_exists($completenessKey, $mc)) {
            $result[$outKey] = $mc[$completenessKey];
        }
    }
}
