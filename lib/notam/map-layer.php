<?php
/**
 * Aggregated TFR GeoJSON for the airports directory map (internal API only).
 *
 * Rebuilds from per-airport NOTAM cache files using the same TTL as
 * {@see getNotamCacheTtlSeconds()} so map geometry tracks NOTAM fetch cadence.
 */

require_once __DIR__ . '/../logger.php';
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../constants.php';
require_once __DIR__ . '/../cache-paths.php';
require_once __DIR__ . '/../units.php';
require_once __DIR__ . '/../weather/utils.php';
require_once __DIR__ . '/filter.php';
require_once __DIR__ . '/schedule.php';

/** Segments on approximate circle rings (visual only, not for navigation). */
const NOTAM_TFR_MAP_CIRCLE_SEGMENTS = 64;

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
 * @return array{type: string, features: array<int, array<string, mixed>>, generated_at: int, cache_ttl_seconds: int}
 */
function notamTfrMapLayerBuildPayload(array $config): array {
    $ttl = getNotamCacheTtlSeconds();
    $features = [];
    $seenIds = [];

    $listed = getListedAirports($config);
    foreach ($listed as $airportId => $airport) {
        if (!is_array($airport) || !isAirportEnabled($airport)) {
            continue;
        }
        if (!isset($airport['lat'], $airport['lon'])) {
            continue;
        }
        $cachePath = getNotamCachePath((string)$airportId);
        if (!is_readable($cachePath)) {
            continue;
        }
        $raw = @file_get_contents($cachePath);
        if ($raw === false || $raw === '') {
            continue;
        }
        $cache = json_decode($raw, true);
        if (!is_array($cache) || !isset($cache['notams']) || !is_array($cache['notams'])) {
            continue;
        }
        $timezone = getAirportTimezone($airport);
        $now = time();

        foreach ($cache['notams'] as $notam) {
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
            $status = classifyNotamDisplayStatusAt($notam, $timezone, $now);
            if ($status === 'expired' || $status === 'unknown') {
                continue;
            }

            $text = (string)($notam['text'] ?? '');
            $meta = parseTfrPolygonVerticesMeta($text);
            $vertices = $meta['vertices'];
            $ringClosed = $meta['ring_closed'];
            $parsedRadius = parseTfrRadiusNm($text);
            $polygonRing = null;
            if ($ringClosed && count($vertices) >= 3) {
                $polygonRing = notamTfrMapLayerGeoJsonRingFromVertices($vertices, true);
            }

            $geo = null;
            if ($polygonRing === null) {
                $geo = parseTfrGeographicRelevanceReference($text, $vertices, $parsedRadius);
                if ($geo === null || $geo['radius_nm'] <= 0) {
                    continue;
                }
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
            $verticalLimits = parseTfrVerticalLimitsSummary($text);
            if ($verticalLimits !== null && $verticalLimits !== '') {
                $baseProps['vertical_limits'] = $verticalLimits;
            }

            if ($polygonRing !== null) {
                $baseProps['geometry_kind'] = 'polygon';
                $features[] = [
                    'type' => 'Feature',
                    'id' => 'tfr-' . preg_replace('/[^A-Za-z0-9_-]+/', '-', $id),
                    'geometry' => [
                        'type' => 'Polygon',
                        'coordinates' => [$polygonRing],
                    ],
                    'properties' => $baseProps,
                ];
                continue;
            }

            // Circle TFRs: Point + radius so the client can use L.circle (true circle in map projection).
            $baseProps['geometry_kind'] = 'circle';
            $baseProps['radius_nm'] = (float)$geo['radius_nm'];
            $baseProps['radius_m'] = (float)$geo['radius_nm'] * METERS_PER_NAUTICAL_MILE;
            $features[] = [
                'type' => 'Feature',
                'id' => 'tfr-' . preg_replace('/[^A-Za-z0-9_-]+/', '-', $id),
                'geometry' => [
                    'type' => 'Point',
                    'coordinates' => [(float)$geo['lon'], (float)$geo['lat']],
                ],
                'properties' => $baseProps,
            ];
        }
    }

    return [
        'type' => 'FeatureCollection',
        'features' => $features,
        'generated_at' => time(),
        'cache_ttl_seconds' => $ttl,
    ];
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
 * Return cached GeoJSON if fresh; otherwise rebuild from NOTAM caches and write.
 *
 * @return array<string, mixed> GeoJSON FeatureCollection plus generated_at and cache_ttl_seconds
 */
function notamTfrMapLayerServeOrRebuild(): array {
    $path = getNotamTfrMapLayerCachePath();
    $ttl = getNotamCacheTtlSeconds();
    if (is_readable($path)) {
        $age = time() - (int)@filemtime($path);
        if ($age >= 0 && $age < $ttl) {
            $decoded = json_decode((string)@file_get_contents($path), true);
            if (is_array($decoded) && ($decoded['type'] ?? '') === 'FeatureCollection'
                && isset($decoded['features']) && is_array($decoded['features'])) {
                return $decoded;
            }
        }
    }

    $config = loadConfig();
    if ($config === null || !isset($config['airports'])) {
        $empty = [
            'type' => 'FeatureCollection',
            'features' => [],
            'generated_at' => time(),
            'cache_ttl_seconds' => $ttl,
        ];
        notamTfrMapLayerWriteCache($empty);
        return $empty;
    }

    $payload = notamTfrMapLayerBuildPayload($config);
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
}
