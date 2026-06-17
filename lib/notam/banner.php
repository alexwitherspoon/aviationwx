<?php

declare(strict_types=1);

/**
 * Dashboard NOTAM banner taxonomy, headlines, deduplication, and display ordering.
 *
 * Full NOTAM text is always retained for safety; headlines are visual cues only.
 */

require_once __DIR__ . '/filter.php';
require_once __DIR__ . '/schedule.php';
require_once __DIR__ . '/map-layer.php';

/** @var array<string, int> Lower sort value = higher banner priority */
const NOTAM_BANNER_STATUS_PRIORITY = [
    'active' => 0,
    'inactive_scheduled' => 1,
    'upcoming_today' => 2,
    'upcoming_future' => 3,
];

/** @var array<string, int> Scope priority when status ties */
const NOTAM_BANNER_SCOPE_PRIORITY = [
    'aerodrome' => 0,
    'runway' => 1,
    'airspace' => 2,
];

/**
 * Classify banner scope for a filtered NOTAM row.
 *
 * @param array<string, mixed> $notam Parsed NOTAM with notam_type from {@see filterRelevantNotams()}
 * @param array<string, mixed> $airport Airport configuration
 * @return string|null aerodrome, runway, airspace, or null when not banner-eligible
 */
function notamBannerClassifyScope(array $notam, array $airport): ?string
{
    $notamType = (string) ($notam['notam_type'] ?? '');
    if ($notamType === 'tfr') {
        return 'airspace';
    }
    if ($notamType === 'aerodrome_closure' && isAerodromeClosure($notam, $airport)) {
        return notamBannerClosureScopeFromNotam($notam);
    }

    return null;
}

/**
 * Aerodrome-level vs runway-level closure scope.
 *
 * Aerodrome wins when both airport-wide and runway phrases appear.
 *
 * @param array<string, mixed> $notam Parsed NOTAM row
 * @return string aerodrome or runway
 */
function notamBannerClosureScopeFromNotam(array $notam): string
{
    $code = strtoupper((string) ($notam['code'] ?? ''));
    if (str_starts_with($code, 'QFA')) {
        return 'aerodrome';
    }

    $upper = strtoupper((string) ($notam['text'] ?? ''));
    if (preg_match('/\bAD\s+AP\b.*\b(CLSD|CLOSED)\b/', $upper) === 1) {
        return 'aerodrome';
    }
    if (preg_match('/\b(ARPT|AIRPORT)\b.*\b(CLSD|CLOSED)\b/', $upper) === 1) {
        return 'aerodrome';
    }

    return 'runway';
}

/**
 * Classify restriction category within a banner scope.
 *
 * @param string $scope From {@see notamBannerClassifyScope()}
 * @param array<string, mixed> $notam Parsed NOTAM row
 * @return string Category slug (full_closure, partial_restriction, fire, general, ...)
 */
function notamBannerClassifyCategory(string $scope, array $notam): string
{
    if ($scope === 'airspace') {
        return notamBannerClassifyAirspaceCategory((string) ($notam['text'] ?? ''));
    }

    if ($scope === 'runway' && notamBannerTextIndicatesPartialRunwayRestriction((string) ($notam['text'] ?? ''))) {
        return 'partial_restriction';
    }

    return 'full_closure';
}

/**
 * Whether runway closure text includes partial aircraft limits.
 *
 * @param string $text NOTAM body
 */
function notamBannerTextIndicatesPartialRunwayRestriction(string $text): bool
{
    if ($text === '') {
        return false;
    }
    $upper = strtoupper($text);
    if (str_contains($upper, 'WINGSPAN')) {
        return true;
    }
    if (preg_match('/\bCLSD\s+TO\b/', $upper) === 1) {
        return true;
    }
    if (preg_match('/\bTO\s+ACFT\b/', $upper) === 1 && str_contains($upper, 'CLSD')) {
        return true;
    }

    return false;
}

/**
 * Classify airspace TFR category from NOTAM prose (fail open to general).
 *
 * @param string $text NOTAM body
 * @return string Category slug
 */
function notamBannerClassifyAirspaceCategory(string $text): string
{
    if ($text === '') {
        return 'general';
    }
    $upper = strtoupper($text);

    if (preg_match('/\b91\.137\s*\(\s*A\s*\)\s*\(\s*2\s*\)/', $upper) === 1
        || str_contains($upper, 'FIRE FIGHTING')
        || str_contains($upper, 'FIRE FIGHT')
    ) {
        return 'fire';
    }
    if (preg_match('/\b91\.137\s*\(\s*A\s*\)\s*\(\s*1\s*\)/', $upper) === 1) {
        return 'hazard_surface';
    }
    if (preg_match('/\b91\.137\s*\(\s*A\s*\)\s*\(\s*3\s*\)/', $upper) === 1
        || preg_match('/\bVIP\b/', $upper) === 1
        || str_contains($upper, 'DISASTER')
    ) {
        return 'vip_disaster';
    }
    if (preg_match('/\b(ROCKET|SPACE\s+LAUNCH|LAUNCH\s+OPS)\b/', $upper) === 1
        || str_contains($upper, 'GROUND BASED ROCKET')
    ) {
        return 'space_launch';
    }
    if (preg_match('/\b(UAS|DRONE)\b/', $upper) === 1) {
        return 'uas';
    }
    if (preg_match('/\b(SPORTING|STADIUM)\b/', $upper) === 1) {
        return 'sporting';
    }

    return 'general';
}

/**
 * Extract a runway designator phrase for headlines and fingerprints.
 *
 * @param string $text NOTAM body
 * @return string|null e.g. RWY 15/33 or RWY 13L/31R
 */
function notamBannerExtractRunwayDesignator(string $text): ?string
{
    if ($text === '') {
        return null;
    }
    if (preg_match(
        '/\bRWY\s+(\d{1,2}[LRC]?\s*\/\s*\d{1,2}[LRC]?|\d{1,2}[LRC]?)\b/i',
        $text,
        $matches
    ) !== 1) {
        return null;
    }

    $designator = strtoupper(preg_replace('/\s+/', '', $matches[1]) ?? $matches[1]);
    if ($designator === '') {
        return null;
    }

    if (str_contains($designator, '/')) {
        return 'RWY ' . $designator;
    }

    return 'RWY ' . $designator;
}

/**
 * Stable fingerprint for banner deduplication (paired A/numeric NOTAMs, same event).
 *
 * @param array<string, mixed> $notam Parsed NOTAM row with banner_scope and banner_category
 * @param array<string, mixed> $airport Airport configuration
 */
function notamBannerEventFingerprint(array $notam, array $airport): string
{
    $scope = (string) ($notam['banner_scope'] ?? '');
    $category = (string) ($notam['banner_category'] ?? '');
    $text = (string) ($notam['text'] ?? '');

    $airportKey = strtolower((string) ($airport['icao'] ?? $airport['faa'] ?? $airport['iata'] ?? ''));

    if ($scope === 'aerodrome') {
        return 'aerodrome|' . $airportKey . '|' . $category;
    }

    if ($scope === 'runway') {
        $rwy = notamBannerExtractRunwayDesignator($text) ?? 'unknown';
        $partial = $category === 'partial_restriction'
            ? substr(sha1(strtoupper($text)), 0, 12)
            : 'full';

        return 'runway|' . $airportKey . '|' . $rwy . '|' . $partial;
    }

    if ($scope === 'airspace') {
        $coords = parseTfrCoordinates($text);
        $radius = parseTfrRadiusNm($text);
        $vertical = parseTfrVerticalLimitsSummary($text) ?? '';
        $centerKey = 'none';
        if ($coords !== null) {
            $centerKey = sprintf('%.4f,%.4f', $coords['lat'], $coords['lon']);
        }
        $radiusKey = $radius !== null ? (string) round($radius, 2) : 'poly';
        $dailyKey = preg_match('/\bDLY\b/', strtoupper($text)) === 1 ? 'daily' : 'single';
        $startKey = trim((string) ($notam['start_time_utc'] ?? ''));
        if ($dailyKey === 'daily') {
            $startKey = 'series';
        }

        return 'airspace|' . $category . '|' . $centerKey . '|' . $radiusKey . '|' . $vertical
            . '|' . $dailyKey . '|' . $startKey;
    }

    return 'unknown|' . trim((string) ($notam['id'] ?? '')) . '|' . substr(sha1($text), 0, 16);
}

/**
 * Build a short dashboard headline (visual cue; full text remains authoritative).
 *
 * @param array<string, mixed> $notam Parsed NOTAM row with banner_scope and banner_category
 */
function notamBannerBuildHeadline(array $notam): string
{
    $scope = (string) ($notam['banner_scope'] ?? '');
    $category = (string) ($notam['banner_category'] ?? '');
    $text = (string) ($notam['text'] ?? '');

    if ($scope === 'aerodrome') {
        return 'Airport closed';
    }

    if ($scope === 'runway') {
        $rwy = notamBannerExtractRunwayDesignator($text) ?? 'Runway';
        if ($category === 'partial_restriction') {
            $detail = notamBannerPartialRunwayRestrictionPhrase($text);

            return $detail !== null ? $rwy . ' restricted - ' . $detail : $rwy . ' restricted';
        }

        return $rwy . ' closed';
    }

    if ($scope === 'airspace') {
        return notamBannerBuildAirspaceHeadline($category, $text);
    }

    return 'NOTAM restriction';
}

/**
 * Partial runway restriction phrase for headlines.
 *
 * @param string $text NOTAM body
 */
function notamBannerPartialRunwayRestrictionPhrase(string $text): ?string
{
    $upper = strtoupper($text);
    if (preg_match('/\bWINGSPAN\s+MORE\s+THAN\s+(\d+)\s*FT\b/', $upper, $m) === 1) {
        return 'wingspan over ' . $m[1] . ' ft';
    }
    if (preg_match('/\bWEIGHT\s+MORE\s+THAN\s+(\d+)\s*(LB|LBS)\b/', $upper, $m) === 1) {
        return 'weight over ' . $m[1] . ' lb';
    }

    return null;
}

/**
 * Airspace TFR headline with geometry hints when parseable.
 *
 * @param string $category From {@see notamBannerClassifyAirspaceCategory()}
 * @param string $text NOTAM body
 */
function notamBannerBuildAirspaceHeadline(string $category, string $text): string
{
    $upper = strtoupper($text);
    $daily = preg_match('/\bDLY\b/', $upper) === 1;

    $label = match ($category) {
        'fire' => $daily ? 'Daily fire TFR' : 'Fire TFR',
        'hazard_surface' => 'Hazard TFR',
        'vip_disaster' => 'VIP TFR',
        'space_launch' => 'Rocket test TFR',
        'uas' => 'UAS TFR',
        'sporting' => 'Sporting event TFR',
        default => 'TFR',
    };

    $parts = [$label];
    $radius = parseTfrRadiusNm($text);
    if ($radius !== null) {
        $nm = rtrim(rtrim(number_format($radius, 1, '.', ''), '0'), '.');
        $parts[] = $nm . ' NM radius';
    } elseif (count(parseTfrPolygonVertices($text)) >= 3) {
        $parts[] = 'polygon area';
    }

    $vertical = parseTfrVerticalLimitsSummary($text);
    if ($vertical !== null && $vertical !== '') {
        $parts[] = $vertical;
    }

    return implode(' - ', $parts);
}

/**
 * Human-readable schedule line for the banner headline block.
 *
 * @param array<string, mixed> $notam Parsed NOTAM row
 * @param string $status Display status from {@see revalidateNotamStatus()}
 * @param string $timezone Airport IANA timezone
 * @param int $nowUnix Current Unix time
 */
function notamBannerBuildScheduleLine(array &$notam, string $status, string $timezone, int $nowUnix): string
{
    if ($status === 'inactive_scheduled') {
        $next = notamNextRestrictionStartUtc($notam, $nowUnix);
        if ($next !== null && $next !== '') {
            $ts = strtotime($next);
            if ($ts !== false && $ts > $nowUnix) {
                return 'Not active now - next window '
                    . notamTfrMapLayerFormatLocalDateTimeForTooltip($ts, $timezone);
            }
        }

        return 'Not active now - see NOTAM for schedule';
    }

    $fromMap = notamTfrMapLayerTooltipStatusLine($notam, $status, $timezone, $nowUnix);
    if ($fromMap !== null && $fromMap !== '') {
        return $fromMap;
    }

    if ($status === 'active') {
        $endUtc = notamCurrentRestrictionEndUtc($notam, $nowUnix);
        if ($endUtc !== null && $endUtc !== '') {
            $ts = strtotime($endUtc);
            if ($ts !== false && $ts > $nowUnix) {
                return 'Active until ' . notamTfrMapLayerFormatLocalDateTimeForTooltip($ts, $timezone);
            }
        }
        $envEnd = trim((string) ($notam['end_time_utc'] ?? ''));
        if ($envEnd !== '' && strtoupper($envEnd) !== 'PERM') {
            $ts = strtotime($envEnd);
            if ($ts !== false && $ts > $nowUnix) {
                return 'Active until ' . notamTfrMapLayerFormatLocalDateTimeForTooltip($ts, $timezone);
            }
        }

        return 'Active now';
    }

    $start = notamFirstRestrictionStartUnix($notam);
    if ($start !== null && $start > $nowUnix) {
        return 'Upcoming from ' . notamTfrMapLayerFormatLocalDateTimeForTooltip($start, $timezone);
    }

    return 'See NOTAM for schedule';
}

/**
 * Attach banner taxonomy and headline fields to a filtered NOTAM row.
 *
 * @param array<string, mixed> $notam Mutated in place
 * @param array<string, mixed> $airport Airport configuration
 * @param string $status Current display status
 * @param string $timezone Airport IANA timezone
 * @param int $nowUnix Current Unix time
 */
function notamEnrichBannerFields(
    array &$notam,
    array $airport,
    string $status,
    string $timezone,
    int $nowUnix
): void {
    $scope = notamBannerClassifyScope($notam, $airport);
    if ($scope === null) {
        return;
    }

    $category = notamBannerClassifyCategory($scope, $notam);
    $notam['banner_scope'] = $scope;
    $notam['banner_category'] = $category;
    $notam['banner_headline'] = notamBannerBuildHeadline($notam);
    $notam['banner_schedule_line'] = notamBannerBuildScheduleLine($notam, $status, $timezone, $nowUnix);
    $notam['banner_event_fingerprint'] = notamBannerEventFingerprint($notam, $airport);
}

/**
 * Prefer ICAO A-series ids when merging duplicate banner events.
 *
 * @param array<string, mixed> $a Candidate NOTAM row
 * @param array<string, mixed> $b Candidate NOTAM row
 * @return int Negative if $a wins, positive if $b wins
 */
function notamBannerComparePreferredDuplicate(array $a, array $b): int
{
    $idA = trim((string) ($a['id'] ?? ''));
    $idB = trim((string) ($b['id'] ?? ''));
    $aSeries = preg_match('/^A\d+\//', $idA) === 1;
    $bSeries = preg_match('/^A\d+\//', $idB) === 1;
    if ($aSeries !== $bSeries) {
        return $bSeries <=> $aSeries;
    }

    $lenA = strlen((string) ($a['text'] ?? ''));
    $lenB = strlen((string) ($b['text'] ?? ''));

    return $lenB <=> $lenA;
}

/**
 * Deduplicate banner NOTAM rows by event fingerprint.
 *
 * @param array<int, array<string, mixed>> $notams Rows with banner_event_fingerprint set
 * @return array<int, array<string, mixed>>
 */
function deduplicateBannerNotams(array $notams): array
{
    $byFingerprint = [];
    $withoutFingerprint = [];
    foreach ($notams as $notam) {
        $fp = (string) ($notam['banner_event_fingerprint'] ?? '');
        if ($fp === '') {
            $withoutFingerprint[] = $notam;
            continue;
        }
        if (!isset($byFingerprint[$fp])) {
            $byFingerprint[$fp] = $notam;
            continue;
        }
        if (notamBannerComparePreferredDuplicate($notam, $byFingerprint[$fp]) < 0) {
            $byFingerprint[$fp] = $notam;
        }
    }

    return array_merge(array_values($byFingerprint), $withoutFingerprint);
}

/**
 * Restriction start used for banner display ordering at $nowUnix.
 *
 * Uses the next EFFECTIVE window for scheduled gaps; otherwise the earliest future start.
 *
 * @param array<string, mixed> $notam Parsed NOTAM row
 * @param int $nowUnix Current Unix time
 */
function notamBannerSortableRestrictionStartUnix(array &$notam, int $nowUnix): int
{
    $status = (string) ($notam['status'] ?? '');
    if ($status === 'inactive_scheduled') {
        $nextUtc = notamNextRestrictionStartUtc($notam, $nowUnix);
        if ($nextUtc !== null && $nextUtc !== '') {
            $nextTs = strtotime($nextUtc);
            if ($nextTs !== false && $nextTs > 0) {
                return $nextTs;
            }
        }
    }

    $firstStart = notamFirstRestrictionStartUnix($notam);
    if ($firstStart !== null && $firstStart > $nowUnix) {
        return $firstStart;
    }

    if ($status === 'active') {
        return $nowUnix;
    }

    $nextUtc = notamNextRestrictionStartUtc($notam, $nowUnix);
    if ($nextUtc !== null && $nextUtc !== '') {
        $nextTs = strtotime($nextUtc);
        if ($nextTs !== false && $nextTs > 0) {
            return $nextTs;
        }
    }

    return $firstStart ?? $nowUnix;
}

/**
 * Sort key for banner selection (lower = higher priority).
 *
 * @param array<string, mixed> $notam Enriched NOTAM row
 * @param int $nowUnix Current Unix time
 * @return array{0: int, 1: int, 2: int} Tuple for spaceship operator
 */
function notamBannerSelectionSortKey(array $notam, int $nowUnix): array
{
    $status = (string) ($notam['status'] ?? 'upcoming_future');
    $scope = (string) ($notam['banner_scope'] ?? 'airspace');
    $statusPri = NOTAM_BANNER_STATUS_PRIORITY[$status] ?? 99;
    $scopePri = NOTAM_BANNER_SCOPE_PRIORITY[$scope] ?? 99;

    $nextStart = notamBannerSortableRestrictionStartUnix($notam, $nowUnix);

    return [$statusPri, $scopePri, $nextStart];
}

/**
 * Sort banner NOTAM rows for stable dashboard display (all deduplicated rows returned).
 *
 * Order: status priority, then scope priority, then nearest upcoming restriction start.
 *
 * @param array<int, array<string, mixed>> $notams Deduplicated rows with banner fields
 * @param int $nowUnix Current Unix time
 * @return array<int, array<string, mixed>>
 */
function sortBannerNotamsForDisplay(array $notams, int $nowUnix): array
{
    if ($notams === []) {
        return [];
    }

    usort($notams, static function (array $a, array $b) use ($nowUnix): int {
        $ka = notamBannerSelectionSortKey($a, $nowUnix);
        $kb = notamBannerSelectionSortKey($b, $nowUnix);

        return $ka <=> $kb;
    });

    return $notams;
}

/**
 * Enrich, deduplicate, and sort dashboard banner NOTAMs.
 *
 * Returns every banner-eligible row after event deduplication (no row cap).
 *
 * @param array<int, array<string, mixed>> $notams Filtered cache rows (notam_type set)
 * @param array<string, mixed> $airport Airport configuration
 * @param string $timezone Airport IANA timezone
 * @param int $nowUnix Current Unix time
 * @return array<int, array<string, mixed>> Deduped rows for the banner API
 */
function notamPrepareDashboardBannerRows(
    array $notams,
    array $airport,
    string $timezone,
    int $nowUnix
): array {
    $enriched = [];
    foreach ($notams as $notam) {
        if (!is_array($notam)) {
            continue;
        }
        $status = (string) ($notam['status'] ?? revalidateNotamStatus($notam, $timezone));
        if (!notamIsBannerRelevantStatus($status, $notam, $nowUnix)) {
            continue;
        }
        $row = $notam;
        notamEnrichBannerFields($row, $airport, $status, $timezone, $nowUnix);
        if (!isset($row['banner_event_fingerprint'])) {
            continue;
        }
        $row['status'] = $status;
        $enriched[] = $row;
    }

    $deduped = deduplicateBannerNotams($enriched);

    return sortBannerNotamsForDisplay($deduped, $nowUnix);
}
