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
 * Read the national store when it is usable for consumer merges.
 *
 * Single disk read: merge-logic and staleness are evaluated from the same envelope.
 *
 * @return array<string, mixed>|null Null when missing, mismatched, or stale
 */
function notamAirspaceStoreReadForConsumers(?int $nowUnix = null): ?array
{
    $envelope = notamMapAirspaceAggregateRead();
    if ($envelope === null) {
        return null;
    }
    if (!notamMapAirspaceAggregateMergeLogicMatches($envelope)) {
        return null;
    }

    $ttl = getNotamCacheTtlSeconds();
    if (notamMapAirspaceAggregateEnvelopeIsStale($envelope, $ttl, $nowUnix)) {
        return null;
    }

    return $envelope;
}

/**
 * Whether the national store is usable for consumer reads.
 */
function notamAirspaceStoreIsReadableForConsumers(?int $nowUnix = null): bool
{
    return notamAirspaceStoreReadForConsumers($nowUnix) !== null;
}

/**
 * Whether a store NOTAM matches the airport for a consumer capability.
 *
 * Applies capability-appropriate airport relevance only. Status / time windows
 * remain the caller's responsibility (banner API revalidates; DA/runway paths
 * apply their own active-closure filters).
 *
 * @param array<string, mixed> $notam Parsed NOTAM row
 * @param array<string, mixed> $airport Airport configuration
 * @param 'banner'|'runway_closure' $capability
 */
function notamAirspaceStoreNotamMatchesAirport(
    array $notam,
    array $airport,
    string $capability
): bool {
    if ($capability === 'runway_closure') {
        return isAerodromeClosure($notam, $airport)
            || isRunwayAffectingRestrictionNotam($notam, $airport);
    }

    if (isTfr($notam)) {
        return isTfrRelevantToAirport($notam, $airport);
    }

    return isAerodromeClosure($notam, $airport)
        || isRunwayAffectingRestrictionNotam($notam, $airport);
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

    $envelope = notamAirspaceStoreReadForConsumers($nowUnix);
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

        if (!notamAirspaceStoreNotamMatchesAirport($notam, $airport, $capability)) {
            continue;
        }

        $key = notamCanonicalDedupKey($notam);
        if (isset($seen[$key])) {
            continue;
        }
        $seen[$key] = true;
        $out[] = $notam;
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
