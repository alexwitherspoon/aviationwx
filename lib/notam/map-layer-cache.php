<?php
/**
 * Serve orchestration for the NOTAM TFR map layer (GeoJSON projection).
 *
 * Reads normalized AirspaceRecord rows from map-airspace.json (unified store).
 * Serve eligibility uses schema/merge versions and envelope data_updated_at -
 * never deploy SHA.
 */

require_once __DIR__ . '/cache.php';
require_once __DIR__ . '/map-layer.php';
require_once __DIR__ . '/map-aggregate-cache.php';

/**
 * Return map GeoJSON from the national airspace record store.
 *
 * Fail-closed when the store is missing, invalid, stale, or on a merge-logic
 * version mismatch. Status and geometry dedup run on every request via
 * {@see notamTfrMapLayerBuildPayloadFromAirspaceStore()}.
 *
 * @return array<string, mixed> GeoJSON FeatureCollection plus metadata
 */
function notamTfrMapLayerServeOrRebuild(): array
{
    $ttl = getNotamCacheTtlSeconds();
    $now = time();

    $envelope = notamMapAirspaceAggregateRead();
    if ($envelope === null) {
        return notamTfrMapLayerEmptyPayload($now, $ttl, true, 'store_missing');
    }

    if (!notamMapAirspaceAggregateMergeLogicMatches($envelope)) {
        return notamTfrMapLayerEmptyPayload($now, $ttl, true, 'merge_logic_mismatch');
    }

    if (notamMapAirspaceAggregateIsStale($ttl, $now)) {
        return notamTfrMapLayerEmptyPayload($now, $ttl, true, 'store_stale');
    }

    // Legacy v1 envelopes: project after in-memory normalize (do not rewrite on serve).
    if ((int) ($envelope['schema_version'] ?? 0) === 1) {
        $envelope = notamMapAirspaceAggregateNormalizeEnvelope($envelope);
    }

    return notamTfrMapLayerBuildPayloadFromAirspaceStore($envelope, $now);
}
