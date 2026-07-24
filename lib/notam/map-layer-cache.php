<?php
/**
 * Serve orchestration for the NOTAM TFR map layer (GeoJSON projection).
 *
 * Reads normalized AirspaceRecord rows from map-airspace.json (NMS side-channel
 * upserts during per-airport fetch). Projects to GeoJSON at serve time with
 * status revalidation from embedded NOTAM rows.
 */

require_once __DIR__ . '/map-aggregate-cache.php';

/**
 * Return map GeoJSON from the national airspace record store.
 *
 * Fail-closed when the store is missing, invalid, stale, or on a build-token
 * mismatch. Status and geometry dedup run on every request via
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
        return notamTfrMapLayerEmptyPayload($now, $ttl, true);
    }

    if (notamMapAirspaceAggregateIsStale($ttl, $now)) {
        return notamTfrMapLayerEmptyPayload($now, $ttl, true);
    }

    if (!notamMapAirspaceAggregateBuildTokenMatches($envelope)) {
        return notamTfrMapLayerEmptyPayload($now, $ttl, true);
    }

    return notamTfrMapLayerBuildPayloadFromAirspaceStore($envelope, $now);
}
