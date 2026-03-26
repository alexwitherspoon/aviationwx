<?php
/**
 * Align METAR ICAO completeness flags with the snapshots that actually contributed
 * visibility/ceiling to the aggregated result (UnifiedFetcher).
 *
 * @package AviationWX\Weather
 */

declare(strict_types=1);

/**
 * Set metar_visibility_reported / metar_ceiling_reported from the METAR snapshot that
 * matches each field's _field_station_map entry when the field source is metar.
 * Leaves flags null when the aggregated field did not come from METAR (or cannot be matched).
 *
 * @param array<string, mixed> $result Aggregated weather (must include _field_source_map)
 * @param array<\AviationWX\Weather\Data\WeatherSnapshot> $snapshots Parsed snapshots from the fetch
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

        $mc = $candidates[0]->metarFieldCompleteness;
        if (is_array($mc) && array_key_exists($completenessKey, $mc)) {
            $result[$outKey] = $mc[$completenessKey];
        }
    }
}
