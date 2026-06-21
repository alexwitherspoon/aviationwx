<?php
/**
 * Aggregated TFR GeoJSON for the airports directory map (internal API only).
 *
 * Geometry is cached on disk and rebuilt when per-airport NOTAM sources change,
 * aggregate age exceeds {@see getNotamCacheTtlSeconds()}, or the cache is missing.
 * Status, style, and tooltip lines are revalidated at serve time from per-airport
 * caches using {@see revalidateNotamStatus()} so restriction colors track wall-clock
 * windows without rebuilding geometry. Geometry build uses the same helper.
 */

require_once __DIR__ . '/../logger.php';
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../constants.php';
require_once __DIR__ . '/../cache-paths.php';
require_once __DIR__ . '/../units.php';
require_once __DIR__ . '/../weather/utils.php';
require_once __DIR__ . '/cache.php';
require_once __DIR__ . '/filter.php';
require_once __DIR__ . '/schedule.php';

/** Segments on approximate circle rings (visual only, not for navigation). */
const NOTAM_TFR_MAP_CIRCLE_SEGMENTS = 64;

/** @var array<string, int> Lower value = higher priority when geometry overlaps */
const NOTAM_TFR_MAP_STATUS_PRIORITY = [
    'active' => 0,
    'inactive_scheduled' => 1,
    'upcoming_today' => 2,
    'upcoming_future' => 3,
];

/**
 * Bump when map build, dedup, or serve-time revalidation logic changes.
 * Always included in {@see notamTfrMapLayerCurrentBuildToken()} alongside deploy SHA.
 */
const NOTAM_TFR_MAP_LAYER_LOGIC_VERSION = 1;

/**
 * Build one closed GeoJSON outer ring [lon, lat] from decoded TFR vertices.
 *
 * @param array<int, array{lat: float, lon: float}> $vertices Ring vertices without repeated closing point
 * @param bool $ringClosed True when the NOTAM defines a closed polygon
 * @return array<int, array{0: float, 1: float}>|null Null when not drawable
 */
function notamTfrMapLayerGeoJsonRingFromVertices(array $vertices, bool $ringClosed): ?array {
    if (!$ringClosed || count($vertices) < 3) {
        return null;
    }
    $plane = tfrPolygonProjectVerticesToLocalPlaneNm($vertices);
    if ($plane === null) {
        return null;
    }
    $signed = tfrPolygonSignedDoubleAreaNm2($plane['xs'], $plane['ys']);
    if (abs($signed) < TFR_POLYGON_MIN_ABS_DOUBLE_AREA_NM2) {
        return null;
    }
    $ring = [];
    foreach ($vertices as $v) {
        $ring[] = [(float)$v['lon'], (float)$v['lat']];
    }
    $first = $ring[0];
    $last = $ring[count($ring) - 1];
    if (abs($first[0] - $last[0]) > 1e-9 || abs($first[1] - $last[1]) > 1e-9) {
        $ring[] = [$first[0], $first[1]];
    }
    return $ring;
}

/**
 * Approximate a NM circle as a closed GeoJSON ring (visual only).
 *
 * @param float $centerLat Center latitude (degrees)
 * @param float $centerLon Center longitude (degrees)
 * @param float $radiusNm Radius (NM)
 * @return array<int, array{0: float, 1: float}>
 */
function notamTfrMapLayerGeoJsonRingFromCircle(float $centerLat, float $centerLon, float $radiusNm): array {
    $n = NOTAM_TFR_MAP_CIRCLE_SEGMENTS;
    $latScale = $radiusNm / 60.0;
    $cosLat = cos(deg2rad($centerLat));
    $lonScale = $latScale / max($cosLat, 0.02);
    $ring = [];
    for ($i = 0; $i < $n; $i++) {
        $theta = (2 * M_PI * $i) / $n;
        $ring[] = [
            $centerLon + $lonScale * sin($theta),
            $centerLat + $latScale * cos($theta),
        ];
    }
    $ring[] = $ring[0];
    return $ring;
}

/**
 * Map segment-aware status to a simple EFB-style stroke bucket for the directory map.
 *
 * @param string $status From {@see classifyNotamDisplayStatusAt()}
 * @return string 'active' or 'upcoming'
 */
function notamTfrMapLayerStyleBucket(string $status): string {
    return $status === 'active' ? 'active' : 'upcoming';
}

/**
 * Sortable priority for overlapping map features (lower = wins).
 *
 * Aligns with dashboard banner status ordering; kept local so the map layer
 * does not depend on banner headline/dedup code.
 *
 * @param string $status From {@see classifyNotamDisplayStatusAt()}
 * @return int Priority rank (lower = higher priority)
 */
function notamTfrMapLayerStatusPriority(string $status): int {
    return NOTAM_TFR_MAP_STATUS_PRIORITY[$status] ?? 99;
}

/**
 * Stable geometry key for deduplicating overlapping TFR map features.
 *
 * @param array<string, mixed> $feature GeoJSON Feature with geometry and properties
 * @return string|null Null when geometry cannot be keyed
 */
function notamTfrMapLayerFeatureGeometryKey(array $feature): ?string {
    $geometry = $feature['geometry'] ?? null;
    if (!is_array($geometry)) {
        return null;
    }
    $type = (string) ($geometry['type'] ?? '');
    $props = is_array($feature['properties'] ?? null) ? $feature['properties'] : [];

    if ($type === 'Point' && ($props['geometry_kind'] ?? '') === 'circle') {
        $coords = $geometry['coordinates'] ?? null;
        if (!is_array($coords) || count($coords) < 2) {
            return null;
        }
        $radiusNm = isset($props['radius_nm']) ? (float) $props['radius_nm'] : 0.0;
        if ($radiusNm <= 0) {
            return null;
        }

        return sprintf(
            'circle|%.4f|%.4f|%.2f',
            round((float) $coords[1], 4),
            round((float) $coords[0], 4),
            round($radiusNm, 2)
        );
    }

    if ($type === 'Polygon') {
        $rings = $geometry['coordinates'] ?? null;
        if (!is_array($rings) || !isset($rings[0]) || !is_array($rings[0])) {
            return null;
        }
        $parts = [];
        $ring = $rings[0];
        $count = count($ring);
        for ($i = 0; $i < $count; $i++) {
            if ($i === $count - 1 && $count > 1) {
                $first = $ring[0];
                $last = $ring[$i];
                if (is_array($first) && is_array($last)
                    && abs((float) $first[0] - (float) $last[0]) < 1e-9
                    && abs((float) $first[1] - (float) $last[1]) < 1e-9
                ) {
                    continue;
                }
            }
            if (!is_array($ring[$i]) || count($ring[$i]) < 2) {
                continue;
            }
            $parts[] = sprintf('%.4f,%.4f', round((float) $ring[$i][0], 4), round((float) $ring[$i][1], 4));
        }
        if ($parts === []) {
            return null;
        }

        return 'poly|' . implode(';', $parts);
    }

    return null;
}

/**
 * Minimal GeoJSON feature for geometry-key checks and build (drawable TFR text only).
 *
 * @param array<string, mixed> $notam Parsed NOTAM row
 * @return array<string, mixed>|null Feature with geometry and geometry_kind only
 */
function notamTfrMapLayerMinimalFeatureForGeometryKey(array $notam): ?array {
    $text = (string)($notam['text'] ?? '');
    $meta = parseTfrPolygonVerticesMeta($text);
    $vertices = $meta['vertices'];
    $ringClosed = $meta['ring_closed'];
    $parsedRadius = parseTfrRadiusNm($text);
    $polygonRing = null;
    if ($ringClosed && count($vertices) >= 3) {
        $polygonRing = notamTfrMapLayerGeoJsonRingFromVertices($vertices, true);
    }

    if ($polygonRing !== null) {
        return [
            'type' => 'Feature',
            'geometry' => [
                'type' => 'Polygon',
                'coordinates' => [$polygonRing],
            ],
            'properties' => [
                'geometry_kind' => 'polygon',
            ],
        ];
    }

    $geo = parseTfrGeographicRelevanceReference($text, $vertices, $parsedRadius);
    if ($geo === null || $geo['radius_nm'] <= 0) {
        return null;
    }

    return [
        'type' => 'Feature',
        'geometry' => [
            'type' => 'Point',
            'coordinates' => [(float)$geo['lon'], (float)$geo['lat']],
        ],
        'properties' => [
            'geometry_kind' => 'circle',
            'radius_nm' => (float)$geo['radius_nm'],
        ],
    ];
}

/**
 * Collect geometry dedup keys present in a cached aggregate FeatureCollection.
 *
 * @param array<string, mixed> $cachedPayload Decoded aggregate cache
 * @return array<string, true> Keys from {@see notamTfrMapLayerFeatureGeometryKey()}
 */
function notamTfrMapLayerAggregateGeometryKeys(array $cachedPayload): array {
    $keys = [];
    $features = $cachedPayload['features'] ?? [];
    if (!is_array($features)) {
        return $keys;
    }
    foreach ($features as $feature) {
        if (!is_array($feature)) {
            continue;
        }
        $key = notamTfrMapLayerFeatureGeometryKey($feature);
        if ($key !== null && $key !== '') {
            $keys[$key] = true;
        }
    }

    return $keys;
}

/**
 * True when listed sources contain drawable TFR geometry absent from the aggregate.
 *
 * Uses the same geometry keys as {@see notamTfrMapLayerDeduplicateFeaturesByGeometry()}
 * so duplicate NOTAM ids for one shape do not force endless rebuilds.
 *
 * @param array<string, mixed> $cachedPayload Decoded aggregate cache
 * @param array{
 *     newest_mtime: int,
 *     by_id: array<string, array{notam: array<string, mixed>, timezone: string, airport_id: string}>,
 *     airports: array<string, array{airport: array<string, mixed>, timezone: string, notams: array<int, mixed>, cache_mtime: int}>
 * } $listedCaches Preloaded per-airport caches
 * @param int $nowUnix Current Unix time (UTC instants)
 * @return bool
 */
function notamTfrMapLayerAggregateMissingDrawableGeometry(
    array $cachedPayload,
    array $listedCaches,
    int $nowUnix
): bool {
    $aggregateKeys = notamTfrMapLayerAggregateGeometryKeys($cachedPayload);
    $sourceKeys = [];
    $seenIds = [];

    foreach ($listedCaches['airports'] as $entry) {
        $airport = $entry['airport'];
        if (!is_array($airport) || !isset($airport['lat'], $airport['lon'])) {
            continue;
        }
        $timezone = $entry['timezone'];
        foreach ($entry['notams'] as $notam) {
            if (!is_array($notam)) {
                continue;
            }
            if (!isTfr($notam)) {
                continue;
            }
            $id = trim((string)($notam['id'] ?? ''));
            if ($id === '' || isset($seenIds[$id])) {
                continue;
            }
            notamEnsureEffectiveSegments($notam);
            $status = revalidateNotamStatus($notam, $timezone, $nowUnix);
            if ($status === 'expired' || $status === 'unknown') {
                continue;
            }
            $minimal = notamTfrMapLayerMinimalFeatureForGeometryKey($notam);
            if ($minimal === null) {
                continue;
            }
            $seenIds[$id] = true;
            $key = notamTfrMapLayerFeatureGeometryKey($minimal);
            if ($key !== null && $key !== '') {
                $sourceKeys[$key] = true;
            }
        }
    }

    foreach ($sourceKeys as $key => $_) {
        if (!isset($aggregateKeys[$key])) {
            return true;
        }
    }

    return false;
}

/**
 * Pick the higher-priority feature when two share the same geometry key.
 *
 * @param array<string, mixed> $candidate New feature
 * @param array<string, mixed> $incumbent Existing feature for the geometry key
 * @return array<string, mixed>
 */
function notamTfrMapLayerPreferFeature(array $candidate, array $incumbent): array {
    $candidateStatus = (string) (($candidate['properties'] ?? [])['status'] ?? '');
    $incumbentStatus = (string) (($incumbent['properties'] ?? [])['status'] ?? '');
    $candidatePri = notamTfrMapLayerStatusPriority($candidateStatus);
    $incumbentPri = notamTfrMapLayerStatusPriority($incumbentStatus);
    if ($candidatePri < $incumbentPri) {
        return $candidate;
    }
    if ($candidatePri > $incumbentPri) {
        return $incumbent;
    }

    $candidateId = (string) (($candidate['properties'] ?? [])['notam_id'] ?? '');
    $incumbentId = (string) (($incumbent['properties'] ?? [])['notam_id'] ?? '');

    return strcmp($candidateId, $incumbentId) < 0 ? $candidate : $incumbent;
}

/**
 * Collapse overlapping TFR features so the highest-priority status wins.
 *
 * Map-only dedup: distinct drawable geometry (not dashboard event fingerprints).
 * Active restrictions must not be masked by overlapping upcoming NOTAMs.
 *
 * @param array<int, array<string, mixed>> $features GeoJSON features
 * @return array<int, array<string, mixed>>
 */
function notamTfrMapLayerDeduplicateFeaturesByGeometry(array $features): array {
    if ($features === []) {
        return [];
    }

    $byGeometry = [];
    $ungrouped = [];
    foreach ($features as $feature) {
        if (!is_array($feature)) {
            continue;
        }
        $key = notamTfrMapLayerFeatureGeometryKey($feature);
        if ($key === null || $key === '') {
            $ungrouped[] = $feature;
            continue;
        }
        if (!isset($byGeometry[$key])) {
            $byGeometry[$key] = $feature;
            continue;
        }
        $byGeometry[$key] = notamTfrMapLayerPreferFeature($feature, $byGeometry[$key]);
    }

    return array_merge(array_values($byGeometry), $ungrouped);
}

/**
 * Format one UTC instant for map tooltip copy (airport local clock, matches dashboard NOTAM phrasing).
 *
 * @param int $unixUtc Unix timestamp (UTC)
 * @param string $timezone IANA timezone (e.g. America/Los_Angeles)
 * @return string e.g. "6:00 PM PDT, May 15, 2026"
 */
function notamTfrMapLayerFormatLocalDateTimeForTooltip(int $unixUtc, string $timezone): string {
    try {
        $tz = new DateTimeZone($timezone);
    } catch (Exception $e) {
        $tz = new DateTimeZone('UTC');
    }
    $dt = (new DateTimeImmutable('@' . $unixUtc))->setTimezone($tz);

    return $dt->format('g:i A T') . ', ' . $dt->format('M j, Y');
}

/**
 * Resolve EFFECTIVE segments (or a single envelope window) for tooltip wording.
 *
 * @param array<string, mixed> $notam Parsed NOTAM row (mutated by {@see notamEnsureEffectiveSegments()})
 * @return array<int, array{start_time_utc: string, end_time_utc: string}>
 */
function notamTfrMapLayerResolveSegmentsForTooltip(array &$notam): array {
    notamEnsureEffectiveSegments($notam);
    $segs = $notam['effective_segments'] ?? null;
    if (is_array($segs) && $segs !== []) {
        return $segs;
    }
    $start = trim((string)($notam['start_time_utc'] ?? ''));
    $endRaw = $notam['end_time_utc'] ?? null;
    $end = is_string($endRaw) ? trim($endRaw) : '';
    if ($start === '' || $end === '' || strtoupper($end) === 'PERM') {
        return [];
    }
    $sTs = strtotime($start);
    $eTs = strtotime($end);
    if ($sTs === false || $eTs === false || $sTs <= 0 || $eTs <= 0 || $eTs < $sTs) {
        return [];
    }
    $sIso = notamTimestampToIsoUtc($sTs);
    $eIso = notamTimestampToIsoUtc($eTs);
    if ($sIso === null || $eIso === null) {
        return [];
    }

    return [['start_time_utc' => $sIso, 'end_time_utc' => $eIso]];
}

/**
 * Human-readable schedule line for directory map tooltip and popup (not for navigation).
 *
 * @param array<string, mixed> $notam Parsed NOTAM row
 * @param string $status From {@see classifyNotamDisplayStatusAt()}
 * @param string $timezone Airport IANA timezone
 * @param int $nowUnix Current Unix time (UTC-based instants)
 * @return string|null Null when schedule does not support the requested phrasing
 */
function notamTfrMapLayerTooltipStatusLine(array &$notam, string $status, string $timezone, int $nowUnix): ?string {
    $segments = notamTfrMapLayerResolveSegmentsForTooltip($notam);
    if ($segments === []) {
        if ($status !== 'active') {
            return null;
        }
        $endRaw = $notam['end_time_utc'] ?? '';
        $endStr = is_string($endRaw) ? trim($endRaw) : '';
        if ($endStr === '' || strtoupper($endStr) === 'PERM') {
            return null;
        }
        $eTs = strtotime($endStr);
        if ($eTs === false || $eTs <= 0 || $nowUnix > $eTs) {
            return null;
        }

        return 'Active now until ' . notamTfrMapLayerFormatLocalDateTimeForTooltip($eTs, $timezone);
    }

    if ($status === 'active') {
        foreach ($segments as $seg) {
            $s = strtotime($seg['start_time_utc'] ?? '');
            $e = strtotime($seg['end_time_utc'] ?? '');
            if ($s === false || $e === false || $s <= 0 || $e <= 0) {
                continue;
            }
            if ($nowUnix >= $s && $nowUnix <= $e) {
                return 'Active now until ' . notamTfrMapLayerFormatLocalDateTimeForTooltip($e, $timezone);
            }
        }

        return null;
    }

    if ($status === 'inactive_scheduled' || $status === 'upcoming_today' || $status === 'upcoming_future') {
        foreach ($segments as $seg) {
            $s = strtotime($seg['start_time_utc'] ?? '');
            $e = strtotime($seg['end_time_utc'] ?? '');
            if ($s === false || $e === false || $s <= 0 || $e <= 0) {
                continue;
            }
            if ($nowUnix < $s) {
                return 'Upcoming from ' . notamTfrMapLayerFormatLocalDateTimeForTooltip($s, $timezone)
                    . ' to ' . notamTfrMapLayerFormatLocalDateTimeForTooltip($e, $timezone);
            }
        }

        return null;
    }

    return null;
}

/**
 * Build token stored in aggregate cache so code deploys invalidate stale geometry.
 *
 * Combines deploy SHA ({@see getGitSha()}) and {@see NOTAM_TFR_MAP_LAYER_LOGIC_VERSION}
 * so map-layer logic changes invalidate the aggregate even when GIT_SHA is unchanged.
 *
 * @return string Token such as `abc1234-v1`, or `logic-v1` when SHA is unavailable
 */
function notamTfrMapLayerCurrentBuildToken(): string {
    $versionSuffix = '-v' . NOTAM_TFR_MAP_LAYER_LOGIC_VERSION;
    $sha = getGitSha();
    if ($sha !== '') {
        return $sha . $versionSuffix;
    }

    return 'logic' . $versionSuffix;
}

/**
 * True when decoded aggregate JSON has the expected FeatureCollection shape.
 *
 * @param array<string, mixed>|null $decoded Decoded aggregate cache payload
 * @return bool
 */
function notamTfrMapLayerAggregateFileIsValid(?array $decoded): bool {
    return is_array($decoded)
        && ($decoded['type'] ?? '') === 'FeatureCollection'
        && isset($decoded['features'])
        && is_array($decoded['features']);
}

/**
 * True when cached aggregate was built by the current deploy or logic version.
 *
 * @param array<string, mixed>|null $cachedPayload Decoded aggregate cache
 * @return bool
 */
function notamTfrMapLayerAggregateBuildTokenMatches(?array $cachedPayload): bool {
    if ($cachedPayload === null) {
        return false;
    }
    $stored = trim((string)($cachedPayload['map_layer_build_token'] ?? ''));
    if ($stored === '') {
        return false;
    }

    return $stored === notamTfrMapLayerCurrentBuildToken();
}

/**
 * Aggregate map layer file mtime after clearing PHP's per-request stat cache.
 *
 * @return int Unix mtime, or 0 when the file is missing
 */
function notamTfrMapLayerAggregateCacheMtime(): int {
    $path = getNotamTfrMapLayerCachePath();
    clearstatcache(true, $path);

    return is_file($path) ? (int)@filemtime($path) : 0;
}

/**
 * Load listed airport NOTAM caches in one pass for build and serve-time revalidation.
 *
 * @param array<string, mixed> $config Full config from {@see loadConfig()}
 * @return array{
 *     newest_mtime: int,
 *     by_id: array<string, array{notam: array<string, mixed>, timezone: string, airport_id: string}>,
 *     airports: array<string, array{airport: array<string, mixed>, timezone: string, notams: array<int, mixed>, cache_mtime: int}>
 * }
 */
function notamTfrMapLayerLoadListedAirportCaches(array $config): array {
    $result = [
        'newest_mtime' => 0,
        'by_id' => [],
        'airports' => [],
    ];
    if (!isset($config['airports']) || !is_array($config['airports'])) {
        return $result;
    }

    $listed = getListedAirports($config);
    foreach ($listed as $airportId => $airport) {
        if (!is_array($airport) || !isAirportEnabled($airport)) {
            continue;
        }
        $cachePath = notamCacheFilePath((string)$airportId);
        $cache = notamReadCachePayload($cachePath);
        $cacheMtime = is_readable($cachePath) ? (int)@filemtime($cachePath) : 0;
        if ($cacheMtime > $result['newest_mtime']) {
            $result['newest_mtime'] = $cacheMtime;
        }
        $notams = [];
        if ($cache !== null && isset($cache['notams']) && is_array($cache['notams'])) {
            $notams = $cache['notams'];
        }
        $timezone = getAirportTimezone($airport);
        $result['airports'][(string)$airportId] = [
            'airport' => $airport,
            'timezone' => $timezone,
            'notams' => $notams,
            'cache_mtime' => $cacheMtime,
        ];
        foreach ($notams as $notam) {
            if (!is_array($notam)) {
                continue;
            }
            $id = trim((string)($notam['id'] ?? ''));
            if ($id === '') {
                continue;
            }
            if (isset($result['by_id'][$id])) {
                continue;
            }
            $result['by_id'][$id] = [
                'notam' => $notam,
                'timezone' => $timezone,
                'airport_id' => (string)$airportId,
            ];
        }
    }

    return $result;
}

/**
 * Build GeoJSON FeatureCollection payload from listed airport NOTAM caches.
 *
 * @param array<string, mixed> $config Full config from {@see loadConfig()}
 * @param array{
 *     newest_mtime: int,
 *     by_id: array<string, array{notam: array<string, mixed>, timezone: string, airport_id: string}>,
 *     airports: array<string, array{airport: array<string, mixed>, timezone: string, notams: array<int, mixed>, cache_mtime: int}>
 * }|null $listedCaches Optional preload from {@see notamTfrMapLayerLoadListedAirportCaches()}
 * @return array{type: string, features: array<int, array<string, mixed>>, generated_at: int, cache_ttl_seconds: int, map_layer_build_token: string}
 */
function notamTfrMapLayerBuildPayload(array $config, ?array $listedCaches = null): array {
    $ttl = getNotamCacheTtlSeconds();
    $features = [];
    $seenIds = [];

    if ($listedCaches === null) {
        $listedCaches = notamTfrMapLayerLoadListedAirportCaches($config);
    }

    foreach ($listedCaches['airports'] as $airportId => $entry) {
        $airport = $entry['airport'];
        if (!is_array($airport) || !isset($airport['lat'], $airport['lon'])) {
            continue;
        }
        $timezone = $entry['timezone'];
        $now = time();

        foreach ($entry['notams'] as $notam) {
            if (!is_array($notam)) {
                continue;
            }
            if (!isTfr($notam)) {
                continue;
            }
            $id = trim((string)($notam['id'] ?? ''));
            if ($id === '') {
                continue;
            }
            if (isset($seenIds[$id])) {
                continue;
            }

            notamEnsureEffectiveSegments($notam);
            $status = revalidateNotamStatus($notam, $timezone, $now);
            if ($status === 'expired' || $status === 'unknown') {
                continue;
            }

            $minimal = notamTfrMapLayerMinimalFeatureForGeometryKey($notam);
            if ($minimal === null) {
                continue;
            }

            $seenIds[$id] = true;
            $bucket = notamTfrMapLayerStyleBucket($status);
            $official = 'https://notams.aim.faa.gov/notamSearch/search?notamNumber=' . rawurlencode($id);

            $baseProps = [
                'notam_id' => $id,
                'airport_id' => (string)$airportId,
                'status' => $status,
                'map_layer_style' => $bucket,
                'official_link' => $official,
            ];
            $statusLine = notamTfrMapLayerTooltipStatusLine($notam, $status, $timezone, $now);
            if ($statusLine !== null && $statusLine !== '') {
                $baseProps['status_line'] = $statusLine;
            }
            $text = (string)($notam['text'] ?? '');
            $verticalLimits = parseTfrVerticalLimitsSummary($text);
            if ($verticalLimits !== null && $verticalLimits !== '') {
                $baseProps['vertical_limits'] = $verticalLimits;
            }

            $geometryKind = (string)($minimal['properties']['geometry_kind'] ?? '');
            if ($geometryKind === 'polygon') {
                $baseProps['geometry_kind'] = 'polygon';
                $features[] = [
                    'type' => 'Feature',
                    'id' => 'tfr-' . preg_replace('/[^A-Za-z0-9_-]+/', '-', $id),
                    'geometry' => $minimal['geometry'],
                    'properties' => $baseProps,
                ];
                continue;
            }

            $baseProps['geometry_kind'] = 'circle';
            $radiusNm = (float)($minimal['properties']['radius_nm'] ?? 0);
            $baseProps['radius_nm'] = $radiusNm;
            $baseProps['radius_m'] = $radiusNm * METERS_PER_NAUTICAL_MILE;
            $features[] = [
                'type' => 'Feature',
                'id' => 'tfr-' . preg_replace('/[^A-Za-z0-9_-]+/', '-', $id),
                'geometry' => $minimal['geometry'],
                'properties' => $baseProps,
            ];
        }
    }

    $features = notamTfrMapLayerDeduplicateFeaturesByGeometry($features);

    return [
        'type' => 'FeatureCollection',
        'features' => $features,
        'generated_at' => time(),
        'cache_ttl_seconds' => $ttl,
        'map_layer_build_token' => notamTfrMapLayerCurrentBuildToken(),
    ];
}

/**
 * Latest modification time among listed airport NOTAM cache files.
 *
 * @param array<string, mixed> $config Full config from {@see loadConfig()}
 * @return int Unix mtime, or 0 when no readable per-airport cache exists
 */
function notamTfrMapLayerNewestPerAirportCacheMtime(array $config): int {
    return notamTfrMapLayerLoadListedAirportCaches($config)['newest_mtime'];
}

/**
 * Whether the on-disk aggregate map layer should be rebuilt from source caches.
 *
 * Pure predicate: pass a decoded aggregate payload when already loaded to avoid
 * extra disk reads. Does not read the aggregate file itself.
 *
 * @param int $mapMtime {@see filemtime()} of aggregate cache, or 0 when missing
 * @param array<string, mixed> $config Full config from {@see loadConfig()}
 * @param int $ttl Aggregate max age from {@see getNotamCacheTtlSeconds()}
 * @param array<string, mixed>|null $cachedPayload Decoded aggregate JSON, if available
 * @param int|null $newestSourceMtime Optional preload from {@see notamTfrMapLayerLoadListedAirportCaches()}
 * @param array{
 *     newest_mtime: int,
 *     by_id: array<string, array{notam: array<string, mixed>, timezone: string, airport_id: string}>,
 *     airports: array<string, array{airport: array<string, mixed>, timezone: string, notams: array<int, mixed>, cache_mtime: int}>
 * }|null $listedCaches Optional preload from {@see notamTfrMapLayerLoadListedAirportCaches()}
 * @param int|null $nowUnix Optional fixed clock for tests; defaults to {@see time()}
 * @return bool True when geometry rebuild is required
 */
function notamTfrMapLayerAggregateNeedsRebuild(
    int $mapMtime,
    array $config,
    int $ttl,
    ?array $cachedPayload = null,
    ?int $newestSourceMtime = null,
    ?array $listedCaches = null,
    ?int $nowUnix = null
): bool {
    $nowUnix = $nowUnix ?? time();
    if ($mapMtime <= 0) {
        return true;
    }

    $age = $nowUnix - $mapMtime;
    if ($age < 0 || $age >= $ttl) {
        return true;
    }

    $sourceMtime = $newestSourceMtime ?? notamTfrMapLayerNewestPerAirportCacheMtime($config);
    if ($sourceMtime > $mapMtime) {
        return true;
    }

    if ($cachedPayload === null || !notamTfrMapLayerAggregateFileIsValid($cachedPayload)) {
        return true;
    }

    if (!notamTfrMapLayerAggregateBuildTokenMatches($cachedPayload)) {
        return true;
    }

    $listedCaches = $listedCaches ?? notamTfrMapLayerLoadListedAirportCaches($config);

    return notamTfrMapLayerAggregateMissingDrawableGeometry($cachedPayload, $listedCaches, $nowUnix);
}

/**
 * Read decoded aggregate map layer JSON from disk.
 *
 * @return array<string, mixed>|null FeatureCollection payload or null when missing/invalid
 */
function notamTfrMapLayerReadAggregateCache(): ?array {
    $path = getNotamTfrMapLayerCachePath();
    clearstatcache(true, $path);
    if (!is_readable($path)) {
        return null;
    }

    $raw = @file_get_contents($path);
    if ($raw === false || $raw === '') {
        aviationwx_log('warning', 'notam map layer: unreadable aggregate cache', [
            'path' => $path,
        ], 'app');

        return null;
    }

    try {
        $decoded = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
    } catch (JsonException $e) {
        aviationwx_log('warning', 'notam map layer: invalid aggregate cache JSON', [
            'path' => $path,
            'error' => $e->getMessage(),
        ], 'app');

        return null;
    }

    if (!notamTfrMapLayerAggregateFileIsValid(is_array($decoded) ? $decoded : null)) {
        aviationwx_log('warning', 'notam map layer: aggregate cache has unexpected shape', [
            'path' => $path,
        ], 'app');

        return null;
    }

    return $decoded;
}

/**
 * Recompute status, map style, and tooltip lines from per-airport NOTAM caches.
 *
 * Cached geometry is retained; deduplication runs again so active restrictions
 * win when wall-clock status changes without a geometry rebuild. Uses the same
 * TFR filter and status helper as {@see api/notam.php}.
 *
 * @param array<string, mixed> $payload GeoJSON FeatureCollection from disk or build
 * @param array<string, mixed> $config Full config from {@see loadConfig()}
 * @param int|null $nowUnix Current Unix time (UTC instants); defaults to {@see time()}
 * @param array{
 *     newest_mtime: int,
 *     by_id: array<string, array{notam: array<string, mixed>, timezone: string, airport_id: string}>,
 *     airports: array<string, array{airport: array<string, mixed>, timezone: string, notams: array<int, mixed>, cache_mtime: int}>
 * }|null $listedCaches Optional preload from {@see notamTfrMapLayerLoadListedAirportCaches()}
 * @return array<string, mixed> Payload with fresh status fields and {@see time()} as generated_at
 */
function notamTfrMapLayerRevalidatePayload(
    array $payload,
    array $config,
    ?int $nowUnix = null,
    ?array $listedCaches = null
): array {
    $nowUnix = $nowUnix ?? time();
    $ttl = getNotamCacheTtlSeconds();
    $features = $payload['features'] ?? [];
    if (!is_array($features) || $features === []) {
        return [
            'type' => 'FeatureCollection',
            'features' => [],
            'generated_at' => $nowUnix,
            'cache_ttl_seconds' => $ttl,
        ];
    }

    if ($listedCaches === null) {
        $listedCaches = notamTfrMapLayerLoadListedAirportCaches($config);
    }
    $lookup = $listedCaches['by_id'];
    $revalidated = [];
    foreach ($features as $feature) {
        if (!is_array($feature)) {
            continue;
        }
        $props = is_array($feature['properties'] ?? null) ? $feature['properties'] : [];
        $notamId = trim((string)($props['notam_id'] ?? ''));
        if ($notamId === '' || !isset($lookup[$notamId])) {
            continue;
        }

        $entry = $lookup[$notamId];
        $notam = $entry['notam'];
        if (!isTfr($notam)) {
            continue;
        }
        notamEnsureEffectiveSegments($notam);
        $status = revalidateNotamStatus($notam, $entry['timezone'], $nowUnix);
        if ($status === 'expired' || $status === 'unknown') {
            continue;
        }

        $props['status'] = $status;
        $props['map_layer_style'] = notamTfrMapLayerStyleBucket($status);
        $props['airport_id'] = $entry['airport_id'];
        $statusLine = notamTfrMapLayerTooltipStatusLine($notam, $status, $entry['timezone'], $nowUnix);
        if ($statusLine !== null && $statusLine !== '') {
            $props['status_line'] = $statusLine;
        } else {
            unset($props['status_line']);
        }
        $feature['properties'] = $props;
        $revalidated[] = $feature;
    }

    $revalidated = notamTfrMapLayerDeduplicateFeaturesByGeometry($revalidated);

    return [
        'type' => 'FeatureCollection',
        'features' => $revalidated,
        'generated_at' => $nowUnix,
        'cache_ttl_seconds' => $ttl,
    ];
}

/**
 * Rebuild aggregate geometry under an exclusive flock (single-flight, non-blocking).
 *
 * @param array<string, mixed> $config Full config from {@see loadConfig()}
 * @param int $mapMtime Known aggregate mtime before lock attempt
 * @param int $ttl Aggregate max age from {@see getNotamCacheTtlSeconds()}
 * @param array{
 *     newest_mtime: int,
 *     by_id: array<string, array{notam: array<string, mixed>, timezone: string, airport_id: string}>,
 *     airports: array<string, array{airport: array<string, mixed>, timezone: string, notams: array<int, mixed>, cache_mtime: int}>
 * } $listedCaches Preloaded per-airport caches
 * @param array<string, mixed>|null $cachedPayload Decoded aggregate JSON, if already read
 * @return array<string, mixed>|null Fresh or existing payload; null when a waiter should retry read
 */
function notamTfrMapLayerRebuildAggregateLocked(
    array $config,
    int $mapMtime,
    int $ttl,
    array $listedCaches,
    ?array $cachedPayload = null
): ?array {
    $lockPath = getNotamTfrMapLayerRebuildLockPath();
    $lockDir = dirname($lockPath);
    if (!is_dir($lockDir)) {
        if (!@mkdir($lockDir, 0755, true) && !is_dir($lockDir)) {
            return notamTfrMapLayerBuildPayload($config, $listedCaches);
        }
    }

    $fp = @fopen($lockPath, 'c+');
    if ($fp === false) {
        return notamTfrMapLayerBuildPayload($config, $listedCaches);
    }

    if (!@flock($fp, LOCK_EX | LOCK_NB)) {
        fclose($fp);

        return null;
    }

    try {
        $path = getNotamTfrMapLayerCachePath();
        $currentMtime = notamTfrMapLayerAggregateCacheMtime();
        $currentCached = $currentMtime !== $mapMtime
            ? notamTfrMapLayerReadAggregateCache()
            : $cachedPayload;

        if (!notamTfrMapLayerAggregateNeedsRebuild(
            $currentMtime,
            $config,
            $ttl,
            $currentCached,
            $listedCaches['newest_mtime'],
            $listedCaches
        )) {
            return $currentCached ?? notamTfrMapLayerReadAggregateCache();
        }

        $payload = notamTfrMapLayerBuildPayload($config, $listedCaches);
        if (!notamTfrMapLayerWriteCache($payload)) {
            aviationwx_log('warning', 'notam map layer: failed to write cache', [
                'path' => $path,
                'feature_count' => count($payload['features'] ?? []),
            ], 'app');
        } else {
            aviationwx_log('info', 'notam map layer: cache rebuilt', [
                'feature_count' => count($payload['features'] ?? []),
                'ttl_seconds' => $ttl,
            ], 'app');
        }

        return $payload;
    } finally {
        @flock($fp, LOCK_UN);
        fclose($fp);
    }
}

/**
 * Rebuild aggregate geometry under a blocking flock (cold start).
 *
 * @param array<string, mixed> $config Full config from {@see loadConfig()}
 * @param int $ttl Aggregate max age from {@see getNotamCacheTtlSeconds()}
 * @param array{
 *     newest_mtime: int,
 *     by_id: array<string, array{notam: array<string, mixed>, timezone: string, airport_id: string}>,
 *     airports: array<string, array{airport: array<string, mixed>, timezone: string, notams: array<int, mixed>, cache_mtime: int}>
 * } $listedCaches Preloaded per-airport caches
 * @return array<string, mixed>|null Fresh or existing payload
 */
function notamTfrMapLayerRebuildAggregateBlocking(array $config, int $ttl, array $listedCaches): ?array {
    $lockPath = getNotamTfrMapLayerRebuildLockPath();
    $lockDir = dirname($lockPath);
    if (!is_dir($lockDir)) {
        if (!@mkdir($lockDir, 0755, true) && !is_dir($lockDir)) {
            return notamTfrMapLayerBuildPayload($config, $listedCaches);
        }
    }

    $fp = @fopen($lockPath, 'c+');
    if ($fp === false) {
        return notamTfrMapLayerBuildPayload($config, $listedCaches);
    }

    if (!@flock($fp, LOCK_EX)) {
        fclose($fp);

        return notamTfrMapLayerReadAggregateCache();
    }

    try {
        $path = getNotamTfrMapLayerCachePath();
        $currentMtime = notamTfrMapLayerAggregateCacheMtime();
        $currentCached = notamTfrMapLayerReadAggregateCache();
        if (!notamTfrMapLayerAggregateNeedsRebuild(
            $currentMtime,
            $config,
            $ttl,
            $currentCached,
            $listedCaches['newest_mtime'],
            $listedCaches
        )) {
            return $currentCached;
        }

        $payload = notamTfrMapLayerBuildPayload($config, $listedCaches);
        if (!notamTfrMapLayerWriteCache($payload)) {
            aviationwx_log('warning', 'notam map layer: failed to write cache', [
                'path' => $path,
                'feature_count' => count($payload['features'] ?? []),
            ], 'app');
        }

        return $payload;
    } finally {
        @flock($fp, LOCK_UN);
        fclose($fp);
    }
}

/**
 * Load or rebuild cached geometry (disk aggregate) for the map layer.
 *
 * Serve path cases:
 * 1. Fresh aggregate on disk - return as-is.
 * 2. Stale aggregate - non-blocking flock rebuild.
 * 3. Lock held - return existing aggregate if another worker rebuilt.
 * 4. Cold start - blocking flock rebuild.
 * 5. Lock unavailable - build in-process without persisting coordination.
 *
 * @param array<string, mixed> $config Full config from {@see loadConfig()}
 * @param int $ttl Aggregate max age from {@see getNotamCacheTtlSeconds()}
 * @param array{
 *     newest_mtime: int,
 *     by_id: array<string, array{notam: array<string, mixed>, timezone: string, airport_id: string}>,
 *     airports: array<string, array{airport: array<string, mixed>, timezone: string, notams: array<int, mixed>, cache_mtime: int}>
 * } $listedCaches Preloaded per-airport caches
 * @return array<string, mixed> GeoJSON FeatureCollection with geometry metadata
 */
function notamTfrMapLayerResolveCachedGeometry(array $config, int $ttl, array $listedCaches): array {
    $path = getNotamTfrMapLayerCachePath();
    $mapMtime = notamTfrMapLayerAggregateCacheMtime();
    $cached = notamTfrMapLayerReadAggregateCache();

    if (!notamTfrMapLayerAggregateNeedsRebuild(
        $mapMtime,
        $config,
        $ttl,
        $cached,
        $listedCaches['newest_mtime'],
        $listedCaches
    )) {
        return $cached ?? [
            'type' => 'FeatureCollection',
            'features' => [],
            'generated_at' => time(),
            'cache_ttl_seconds' => $ttl,
            'map_layer_build_token' => notamTfrMapLayerCurrentBuildToken(),
        ];
    }

    $rebuilt = notamTfrMapLayerRebuildAggregateLocked($config, $mapMtime, $ttl, $listedCaches, $cached);
    if ($rebuilt !== null) {
        return $rebuilt;
    }

    $cached = notamTfrMapLayerReadAggregateCache();
    if ($cached !== null) {
        return $cached;
    }

    $rebuilt = notamTfrMapLayerRebuildAggregateBlocking($config, $ttl, $listedCaches);
    if ($rebuilt !== null) {
        return $rebuilt;
    }

    $payload = notamTfrMapLayerBuildPayload($config, $listedCaches);
    if (!notamTfrMapLayerWriteCache($payload)) {
        aviationwx_log('warning', 'notam map layer: failed to write cache after lock miss', [
            'path' => $path,
            'feature_count' => count($payload['features'] ?? []),
        ], 'app');
    }

    return $payload;
}

/**
 * Write aggregated map layer JSON to disk (atomic replace when possible).
 *
 * @param array<string, mixed> $payload GeoJSON payload plus metadata
 * @return bool True when the file was written
 */
function notamTfrMapLayerWriteCache(array $payload): bool {
    $path = getNotamTfrMapLayerCachePath();
    $dir = dirname($path);
    if (!is_dir($dir)) {
        if (!@mkdir($dir, 0755, true) && !is_dir($dir)) {
            return false;
        }
    }
    $json = json_encode($payload, JSON_UNESCAPED_SLASHES);
    if ($json === false) {
        return false;
    }
    $tmp = $path . '.tmp.' . bin2hex(random_bytes(4));
    if (@file_put_contents($tmp, $json, LOCK_EX) === false) {
        return false;
    }
    if (!@rename($tmp, $path)) {
        $ok = @file_put_contents($path, $json, LOCK_EX) !== false;
        @unlink($tmp);
        return $ok;
    }
    return true;
}

/**
 * Return map GeoJSON: shared disk cache for geometry, serve-time status refresh.
 *
 * Three TTL concepts apply to this endpoint:
 * - {@see getNotamCacheTtlSeconds()} on disk aggregate and in JSON cache_ttl_seconds
 *   (client poll interval on airports.php).
 * - {@see NOTAM_API_CACHE_TTL_SECONDS} on HTTP Cache-Control (browser/CDN sharing).
 * - Serve-time revalidation uses wall clock on every origin request.
 *
 * @return array<string, mixed> GeoJSON FeatureCollection plus generated_at and cache_ttl_seconds
 */
function notamTfrMapLayerServeOrRebuild(): array {
    $ttl = getNotamCacheTtlSeconds();
    $now = time();
    $config = loadConfig();
    $emptyConfig = ['airports' => []];

    if ($config === null || !isset($config['airports'])) {
        $empty = [
            'type' => 'FeatureCollection',
            'features' => [],
            'generated_at' => $now,
            'cache_ttl_seconds' => $ttl,
        ];
        $listedCaches = notamTfrMapLayerLoadListedAirportCaches($emptyConfig);

        return notamTfrMapLayerRevalidatePayload($empty, $emptyConfig, $now, $listedCaches);
    }

    $listedCaches = notamTfrMapLayerLoadListedAirportCaches($config);
    $geometry = notamTfrMapLayerResolveCachedGeometry($config, $ttl, $listedCaches);

    return notamTfrMapLayerRevalidatePayload($geometry, $config, $now, $listedCaches);
}
