<?php

/**
 * Exclude closed runways from density altitude performance scoring.
 *
 * NASR COND=FAILED/CLOSED and OurAirports closed=1 are filtered at ingest.
 * Active full aerodrome/runway NOTAMs apply when the airport NOTAM cache is not failclosed.
 */

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../runway-end-ident.php';
require_once __DIR__ . '/../notam/cache.php';
require_once __DIR__ . '/../notam/filter.php';
require_once __DIR__ . '/../notam/schedule.php';
require_once __DIR__ . '/../notam/banner.php';

/**
 * Normalize a runway pair designator for order-insensitive comparison.
 */
function densityAltitudePerformanceNormalizeRunwayPairKey(string $designator): string
{
    $normalized = strtoupper(str_replace(' ', '', trim($designator)));
    if ($normalized === '' || !str_contains($normalized, '/')) {
        $canonical = canonicalizeRunwayEndIdent($normalized);

        return $canonical ?? $normalized;
    }

    [$endA, $endB] = explode('/', $normalized, 2);
    $canonicalA = canonicalizeRunwayEndIdent($endA) ?? strtoupper($endA);
    $canonicalB = canonicalizeRunwayEndIdent($endB) ?? strtoupper($endB);
    $ends = [$canonicalA, $canonicalB];
    sort($ends, SORT_STRING);

    return implode('/', $ends);
}

/**
 * Whether a performance runway id matches a NOTAM pair designator (either end order).
 */
function densityAltitudePerformanceRunwayPairMatchesDesignator(string $rwyId, string $designator): bool
{
    $designatorKey = densityAltitudePerformanceNormalizeRunwayPairKey($designator);
    if (!str_contains($designatorKey, '/')) {
        return false;
    }

    return densityAltitudePerformanceNormalizeRunwayPairKey($rwyId) === $designatorKey;
}

/**
 * Load NOTAM rows when cache age is within the NOTAM failclosed threshold.
 *
 * Returns null when cache is missing or too stale. Callers skip NOTAM-based
 * runway exclusions and leave the ingest-filtered runway list unchanged.
 *
 * @return list<array<string, mixed>>|null
 */
function loadNotamRowsForDensityAltitudePerformance(string $airportId, ?int $nowUnix = null): ?array
{
    $cacheFile = notamCacheFilePath($airportId);
    if (!is_file($cacheFile)) {
        return null;
    }

    $now = $nowUnix ?? time();
    if ($now - (int) filemtime($cacheFile) > getNotamStaleFailclosedSeconds()) {
        return null;
    }

    $payload = notamReadCachePayload($cacheFile);
    if ($payload === null || !isset($payload['notams']) || !is_array($payload['notams'])) {
        return null;
    }

    $rows = [];
    foreach ($payload['notams'] as $notam) {
        if (is_array($notam)) {
            $rows[] = $notam;
        }
    }

    return $rows;
}

/**
 * Active full-closure NOTAMs that remove runways or departure ends from DA scoring.
 *
 * Partial restrictions (wingspan, CLSD TO) are ignored. Only `active` status applies
 * so upcoming closures do not suppress performance on open runways.
 *
 * @param list<array<string, mixed>> $notams Parsed NOTAM rows
 * @param array<string, mixed> $airport Airport configuration
 * @param int|null $nowUnix Reference time for tests
 * @return array{
 *     aerodrome_closed: bool,
 *     closed_pair_designators: list<string>,
 *     closed_end_idents: list<string>
 * }
 */
function notamResolveActiveDensityAltitudeRunwayClosures(
    array $notams,
    array $airport,
    ?int $nowUnix = null
): array {
    $timezone = getAirportTimezone($airport);
    $now = $nowUnix ?? time();
    $closures = [
        'aerodrome_closed' => false,
        'closed_pair_designators' => [],
        'closed_end_idents' => [],
    ];

    foreach ($notams as $notam) {
        if (!is_array($notam) || !isAerodromeClosure($notam, $airport)) {
            continue;
        }

        notamEnsureEffectiveSegments($notam);
        if (revalidateNotamStatus($notam, $timezone, $now) !== 'active') {
            continue;
        }

        if (notamBannerClosureScopeFromNotam($notam) === 'aerodrome') {
            $closures['aerodrome_closed'] = true;

            continue;
        }

        if (notamBannerTextIndicatesPartialRunwayRestriction((string) ($notam['text'] ?? ''))) {
            continue;
        }

        $raw = notamBannerExtractRunwayDesignatorRaw((string) ($notam['text'] ?? ''));
        if ($raw === null) {
            continue;
        }

        if (str_contains($raw, '/')) {
            $closures['closed_pair_designators'][] = $raw;
            continue;
        }

        $canonical = canonicalizeRunwayEndIdent($raw);
        if ($canonical !== null) {
            $closures['closed_end_idents'][] = $canonical;
        }
    }

    $closures['closed_pair_designators'] = array_values(array_unique($closures['closed_pair_designators']));
    $closures['closed_end_idents'] = array_values(array_unique($closures['closed_end_idents']));

    return $closures;
}

/**
 * Remove runways and departure ends that are fully closed by active NOTAMs.
 *
 * @param list<array> $runways Performance runway rows
 * @param array<string, mixed> $closures From {@see notamResolveActiveDensityAltitudeRunwayClosures()}
 * @return list<array>
 */
function applyNotamClosuresToPerformanceRunways(array $runways, array $closures): array
{
    if ($closures['aerodrome_closed'] ?? false) {
        return [];
    }

    $filtered = [];
    foreach ($runways as $runway) {
        if (!is_array($runway)) {
            continue;
        }

        $rwyId = (string) ($runway['rwy_id'] ?? '');
        foreach ($closures['closed_pair_designators'] as $pairDesignator) {
            if (densityAltitudePerformanceRunwayPairMatchesDesignator($rwyId, $pairDesignator)) {
                continue 2;
            }
        }

        $closedEndIdents = $closures['closed_end_idents'] ?? [];
        if (($runway['ends'] ?? []) !== [] && $closedEndIdents !== []) {
            $openEnds = [];
            foreach ($runway['ends'] as $end) {
                if (!is_array($end)) {
                    continue;
                }
                $endIdent = canonicalizeRunwayEndIdent((string) ($end['end_id'] ?? ''));
                if ($endIdent !== null && in_array($endIdent, $closedEndIdents, true)) {
                    continue;
                }
                $openEnds[] = $end;
            }
            if ($openEnds === []) {
                continue;
            }
            $runway['ends'] = $openEnds;
        }

        $filtered[] = $runway;
    }

    return $filtered;
}

/**
 * Apply NOTAM closure filtering when a trustworthy airport cache exists.
 *
 * @param list<array> $runways Performance runway rows
 * @param array<string, mixed> $airport Airport configuration
 * @param string|null $airportId Config airport key
 * @return list<array>
 */
function filterPerformanceRunwaysForActiveNotamClosures(
    array $runways,
    array $airport,
    ?string $airportId
): array {
    if ($airportId === null || trim($airportId) === '') {
        return $runways;
    }

    $notams = loadNotamRowsForDensityAltitudePerformance($airportId);
    if ($notams === null) {
        return $runways;
    }

    return applyNotamClosuresToPerformanceRunways(
        $runways,
        notamResolveActiveDensityAltitudeRunwayClosures($notams, $airport)
    );
}
