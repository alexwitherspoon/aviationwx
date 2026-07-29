<?php
/**
 * Unified airspace store consumers (banner + runway closure gates).
 *
 * Capability flags are data sufficiency only. Airport relevance still applies.
 * When the national store is missing or stale, callers keep using per-airport
 * NOTAM caches alone (no additional fail-closed on the dashboard API).
 */

declare(strict_types=1);

require_once __DIR__ . '/../map-aggregate-cache.php';
require_once __DIR__ . '/../filter.php';
require_once __DIR__ . '/../fetcher.php';

/**
 * Whether the national store is usable for consumer reads.
 */
function notamAirspaceStoreIsReadableForConsumers(?int $nowUnix = null): bool
{
    $envelope = notamMapAirspaceAggregateRead();
    if ($envelope === null) {
        return false;
    }
    if (!notamMapAirspaceAggregateMergeLogicMatches($envelope)) {
        return false;
    }

    $ttl = getNotamCacheTtlSeconds();

    return !notamMapAirspaceAggregateIsStale($ttl, $nowUnix);
}

/**
 * Embedded NOTAM rows from the unified store for one capability + airport relevance.
 *
 * @param array<string, mixed> $airport Airport configuration
 * @param 'banner'|'runway_closure' $capability
 * @return list<array<string, mixed>>
 */
function notamAirspaceStoreRelevantNotamsForAirport(
    array $airport,
    string $capability,
    ?int $nowUnix = null
): array {
    if ($capability !== 'banner' && $capability !== 'runway_closure') {
        return [];
    }

    if (!notamAirspaceStoreIsReadableForConsumers($nowUnix)) {
        return [];
    }

    $envelope = notamMapAirspaceAggregateRead();
    if ($envelope === null) {
        return [];
    }

    $records = $envelope['records'] ?? [];
    if (!is_array($records)) {
        return [];
    }

    $out = [];
    $seen = [];

    foreach ($records as $record) {
        if (!is_array($record)) {
            continue;
        }
        if (($record['capabilities'][$capability] ?? false) !== true) {
            continue;
        }

        $notam = $record['notam'] ?? null;
        if (!is_array($notam)) {
            continue;
        }

        $filtered = filterRelevantNotams([$notam], $airport);
        if ($filtered === []) {
            continue;
        }

        $row = $filtered[0];
        $key = notamCanonicalDedupKey($row);
        if (isset($seen[$key])) {
            continue;
        }
        $seen[$key] = true;
        $out[] = $row;
    }

    return $out;
}

/**
 * Merge per-airport NOTAM rows with capability-gated unified-store rows.
 *
 * @param list<array<string, mixed>> $airportNotams
 * @param list<array<string, mixed>> $storeNotams
 * @return list<array<string, mixed>>
 */
function notamMergeAirportAndStoreNotamRows(array $airportNotams, array $storeNotams): array
{
    $byKey = [];
    foreach (array_merge($airportNotams, $storeNotams) as $notam) {
        if (!is_array($notam)) {
            continue;
        }
        $key = notamCanonicalDedupKey($notam);
        if (!isset($byKey[$key])) {
            $byKey[$key] = $notam;
            continue;
        }
        if (function_exists('mergeParsedNotamDuplicates')) {
            $byKey[$key] = mergeParsedNotamDuplicates($byKey[$key], $notam);
        }
    }

    return array_values($byKey);
}
