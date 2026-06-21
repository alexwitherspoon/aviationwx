<?php
/**
 * Aggregated TFR GeoJSON for the airports directory map (internal API only).
 *
 * Builds and styles TFR map geometry and tooltip copy. Disk cache orchestration
 * (aggregate JSON, flock rebuild, serve entry) lives in {@see map-layer-cache.php}.
 * Status fields are revalidated at serve time via
 * {@see notamTfrMapLayerRevalidatePayload()} in map-layer-cache.php.
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

