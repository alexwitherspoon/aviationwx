<?php
/**
 * Map-ready TFR display projection.
 *
 * Builds a draw-oriented GeoJSON feature list from per-NOTAM map features.
 * Does not read or write the unified airspace store. Overlapping footprints that
 * share restriction kind, map style bucket, and vertical limits collapse into one
 * outline so the directory map signals "research TFRs here" without stacking
 * near-duplicate circles or polygons.
 *
 * Clustering: polygons use bbox IoU / containment / coverage. Circles only
 * nest with circles that fully contain them (lateral circle peers stay separate).
 * Circle-dominated merges keep a covering circle; hull polygons are used only
 * when a polygon sticks outside that cover.
 *
 * Source "N NM radius" footprints sometimes arrive as polygon rings; the display
 * path rewrites those to true circle features so the map keeps a smooth outline.
 */

declare(strict_types=1);

/** BBox IoU at or above this value may join a display cluster (polygon paths). */
const NOTAM_TFR_MAP_DISPLAY_IOU_THRESHOLD = 0.5;

/**
 * Intersection / min(area) at or above this value may join a display cluster.
 * Catches nested footprints where IoU stays low.
 */
const NOTAM_TFR_MAP_DISPLAY_COVERAGE_THRESHOLD = 0.5;

/** Sample count when approximating a circle as vertices for hull merges. */
const NOTAM_TFR_MAP_DISPLAY_CIRCLE_SEGMENTS = 32;

/** Diagnostics version for the display projection step. */
const NOTAM_TFR_MAP_DISPLAY_PROJECTION_VERSION = 7;

/**
 * Max coefficient of variation of vertex radii for geometric circle rewrite.
 * Disney-style standing TFRs are near-perfect rings (CV ≪ 0.01).
 */
const NOTAM_TFR_MAP_DISPLAY_CIRCLE_RADIUS_CV_MAX = 0.05;

/**
 * Looser CV for dense WFS rings that approximate FAA "N NM ARC CENTERED ON" areas
 * (Mayport-style security volumes often land near 0.15–0.20).
 */
const NOTAM_TFR_MAP_DISPLAY_CIRCLE_RADIUS_CV_MAX_DENSE = 0.20;

/** Minimum vertices to use the dense-ring CV allowance. */
const NOTAM_TFR_MAP_DISPLAY_CIRCLE_DENSE_MIN_VERTICES = 24;

/**
 * Project per-NOTAM map features into a map-ready display Feature list.
 *
 * @param list<array<string, mixed>> $features GeoJSON features after exact geometry dedup
 * @return list<array<string, mixed>>
 */
function notamTfrMapLayerProjectDisplayFeatures(array $features): array
{
    if ($features === []) {
        return [];
    }

    $normalized = [];
    foreach ($features as $feature) {
        if (!is_array($feature)) {
            continue;
        }
        foreach (notamTfrMapLayerDisplayExplodeMultiPolygon($feature) as $part) {
            $normalized[] = notamTfrMapLayerDisplayNormalizeRadiusPolygonToCircle($part);
        }
    }

    $passthrough = [];
    /** @var array<string, list<array<string, mixed>>> $buckets */
    $buckets = [];

    foreach ($normalized as $feature) {
        if (!notamTfrMapLayerDisplayFeatureIsMergeable($feature)) {
            $passthrough[] = $feature;
            continue;
        }
        // Keep exploded MultiPolygon volumes separate (Mayport arc + corridor + ocean).
        $props = is_array($feature['properties'] ?? null) ? $feature['properties'] : [];
        if (array_key_exists('display_part_index', $props)) {
            $passthrough[] = $feature;
            continue;
        }
        $bucketKey = notamTfrMapLayerDisplayBucketKey($feature);
        if ($bucketKey === null) {
            $passthrough[] = $feature;
            continue;
        }
        $buckets[$bucketKey][] = $feature;
    }

    $projected = [];
    foreach ($buckets as $members) {
        foreach (notamTfrMapLayerDisplayClusterByOverlap($members) as $cluster) {
            if (count($cluster) === 1) {
                $projected[] = $cluster[0];
                continue;
            }
            $merged = notamTfrMapLayerDisplayMergeCluster($cluster);
            $projected[] = $merged ?? $cluster[0];
            if ($merged === null) {
                for ($i = 1, $n = count($cluster); $i < $n; $i++) {
                    $projected[] = $cluster[$i];
                }
            }
        }
    }

    return array_merge($projected, $passthrough);
}

/**
 * Explode MultiPolygon features into per-part Polygon features for cleaner Leaflet draw.
 *
 * Overlapping WFS volumes (Mayport arc + ocean + corridor) keep separate outlines instead
 * of one multipolygon path that visually webs through the circle.
 *
 * @param array<string, mixed> $feature
 * @return list<array<string, mixed>>
 */
function notamTfrMapLayerDisplayExplodeMultiPolygon(array $feature): array
{
    $geometry = $feature['geometry'] ?? null;
    if (!is_array($geometry) || (($geometry['type'] ?? null) !== 'MultiPolygon')) {
        return [$feature];
    }
    $coordinates = $geometry['coordinates'] ?? null;
    if (!is_array($coordinates) || $coordinates === []) {
        return [$feature];
    }

    $props = is_array($feature['properties'] ?? null) ? $feature['properties'] : [];
    $baseId = (string) ($feature['id'] ?? ('tfr-' . ($props['notam_id'] ?? 'unknown')));
    $parts = [];
    foreach ($coordinates as $i => $poly) {
        if (!is_array($poly) || $poly === []) {
            continue;
        }
        $partProps = $props;
        $partProps['geometry_kind'] = 'polygon';
        $partProps['display_part_index'] = (int) $i;
        $parts[] = [
            'type' => 'Feature',
            'id' => $baseId . '-p' . (int) $i,
            'geometry' => [
                'type' => 'Polygon',
                'coordinates' => $poly,
            ],
            'properties' => $partProps,
        ];
    }

    return $parts === [] ? [$feature] : $parts;
}

/**
 * Rewrite "N NM radius" polygon rings to Point + circle for smooth Leaflet draw.
 *
 * Store geometry is unchanged; this is display-only.
 *
 * @param array<string, mixed> $feature
 * @return array<string, mixed>
 */
function notamTfrMapLayerDisplayNormalizeRadiusPolygonToCircle(array $feature): array
{
    if (notamTfrMapLayerDisplayFeatureIsMergeableCircle($feature)) {
        return $feature;
    }
    if (!notamTfrMapLayerDisplayFeatureIsMergeablePolygon($feature)) {
        return $feature;
    }

    $inferred = notamTfrMapLayerDisplayInferCircleFromRadiusPolygon($feature);
    if ($inferred === null) {
        return $feature;
    }

    $props = is_array($feature['properties'] ?? null) ? $feature['properties'] : [];
    $props['geometry_kind'] = 'circle';
    $props['radius_nm'] = $inferred['radius_nm'];
    $props['radius_m'] = $inferred['radius_nm'] * 1852.0;

    return [
        'type' => 'Feature',
        'id' => $feature['id'] ?? ('tfr-display-circle-' . ($props['notam_id'] ?? 'unknown')),
        'geometry' => [
            'type' => 'Point',
            'coordinates' => [$inferred['lon'], $inferred['lat']],
        ],
        'properties' => $props,
    ];
}

/**
 * Infer a display circle from a polygon ring.
 *
 * Prefers headline "N NM radius" when present and consistent with the footprint.
 * Otherwise fits a circle geometrically when the ring is nearly circular
 * (standing security TFRs like Disney often lack an NM radius in the map title).
 *
 * MultiPolygon: uses the largest exterior ring only so extra WFS lobes do not
 * skew the center or invent internal chords.
 *
 * @param array<string, mixed> $feature
 * @return array{lon: float, lat: float, radius_nm: float}|null
 */
function notamTfrMapLayerDisplayInferCircleFromRadiusPolygon(array $feature): ?array
{
    $pts = notamTfrMapLayerDisplayPrimaryRingVertices($feature);
    if (count($pts) < 8) {
        return null;
    }

    $sumLon = 0.0;
    $sumLat = 0.0;
    foreach ($pts as $pt) {
        $sumLon += $pt[0];
        $sumLat += $pt[1];
    }
    $lon = $sumLon / count($pts);
    $lat = $sumLat / count($pts);

    $cosLat = max(0.2, cos(deg2rad($lat)));
    $radiiNm = [];
    foreach ($pts as $pt) {
        $dxNm = ($pt[0] - $lon) * 60.0 * $cosLat;
        $dyNm = ($pt[1] - $lat) * 60.0;
        $radiiNm[] = hypot($dxNm, $dyNm);
    }
    $meanRadiusNm = array_sum($radiiNm) / count($radiiNm);
    if ($meanRadiusNm <= 0.0) {
        return null;
    }
    sort($radiiNm);
    $mid = intdiv(count($radiiNm), 2);
    $medianRadiusNm = (count($radiiNm) % 2 === 1)
        ? $radiiNm[$mid]
        : (($radiiNm[$mid - 1] + $radiiNm[$mid]) / 2.0);
    $variance = 0.0;
    foreach ($radiiNm as $r) {
        $d = $r - $meanRadiusNm;
        $variance += $d * $d;
    }
    $variance /= count($radiiNm);
    $cv = sqrt($variance) / $meanRadiusNm;

    $props = is_array($feature['properties'] ?? null) ? $feature['properties'] : [];
    $arcHints = is_array($props['arc_hints'] ?? null) ? $props['arc_hints'] : [];
    foreach ($arcHints as $hint) {
        if (!is_array($hint)) {
            continue;
        }
        $hintLon = isset($hint['lon']) && is_numeric($hint['lon']) ? (float) $hint['lon'] : null;
        $hintLat = isset($hint['lat']) && is_numeric($hint['lat']) ? (float) $hint['lat'] : null;
        $hintR = isset($hint['radius_nm']) && is_numeric($hint['radius_nm']) ? (float) $hint['radius_nm'] : null;
        if ($hintLon === null || $hintLat === null || $hintR === null || $hintR <= 0.0) {
            continue;
        }
        $dxNm = ($lon - $hintLon) * 60.0 * $cosLat;
        $dyNm = ($lat - $hintLat) * 60.0;
        $centerDistNm = hypot($dxNm, $dyNm);
        if ($centerDistNm > max(1.5, $hintR * 0.35)) {
            continue;
        }
        if ($medianRadiusNm < ($hintR * 0.65) || $medianRadiusNm > ($hintR * 1.4)) {
            continue;
        }

        return [
            'lon' => $hintLon,
            'lat' => $hintLat,
            'radius_nm' => $hintR,
        ];
    }

    $minLon = $pts[0][0];
    $minLat = $pts[0][1];
    $maxLon = $pts[0][0];
    $maxLat = $pts[0][1];
    foreach ($pts as $pt) {
        $minLon = min($minLon, $pt[0]);
        $minLat = min($minLat, $pt[1]);
        $maxLon = max($maxLon, $pt[0]);
        $maxLat = max($maxLat, $pt[1]);
    }
    $widthNm = ($maxLon - $minLon) * 60.0 * $cosLat;
    $heightNm = ($maxLat - $minLat) * 60.0;
    if ($widthNm <= 0.0 || $heightNm <= 0.0) {
        return null;
    }
    $aspect = $widthNm / $heightNm;
    if ($aspect < 0.7 || $aspect > 1.43) {
        return null;
    }

    $headline = (string) ($props['banner_headline'] ?? '');
    $headlineRadiusNm = null;
    if (preg_match('/\b(\d+(?:\.\d+)?)\s*NM\s+radius\b/i', $headline, $m) === 1) {
        $candidate = (float) $m[1];
        if ($candidate > 0.0) {
            $headlineRadiusNm = $candidate;
        }
    }

    $bboxRadiusNm = max($widthNm, $heightNm) / 2.0;
    if ($headlineRadiusNm !== null
        && $bboxRadiusNm >= ($headlineRadiusNm * 0.7)
        && $bboxRadiusNm <= ($headlineRadiusNm * 1.35)
    ) {
        // Coarse WFS rings still declare an NM radius in the map title.
        return [
            'lon' => $lon,
            'lat' => $lat,
            'radius_nm' => $headlineRadiusNm,
        ];
    }

    // No usable headline radius: only rewrite when the ring is geometrically circular
    // (standing security TFRs like Disney often omit "NM radius" from the title).
    $cvMax = count($pts) >= NOTAM_TFR_MAP_DISPLAY_CIRCLE_DENSE_MIN_VERTICES
        ? NOTAM_TFR_MAP_DISPLAY_CIRCLE_RADIUS_CV_MAX_DENSE
        : NOTAM_TFR_MAP_DISPLAY_CIRCLE_RADIUS_CV_MAX;
    if ($cv > $cvMax) {
        return null;
    }

    return [
        'lon' => $lon,
        'lat' => $lat,
        'radius_nm' => $medianRadiusNm,
    ];
}

/**
 * Vertices of the largest exterior ring (MultiPolygon-safe), excluding the closing duplicate.
 *
 * @param array<string, mixed> $feature
 * @return list<array{0: float, 1: float}>
 */
function notamTfrMapLayerDisplayPrimaryRingVertices(array $feature): array
{
    $geometry = $feature['geometry'] ?? null;
    if (!is_array($geometry)) {
        return [];
    }
    $type = (string) ($geometry['type'] ?? '');
    $coordinates = $geometry['coordinates'] ?? null;
    if (!is_array($coordinates)) {
        return [];
    }

    $rings = [];
    if ($type === 'Polygon') {
        $rings[] = $coordinates[0] ?? null;
    } elseif ($type === 'MultiPolygon') {
        foreach ($coordinates as $polygon) {
            if (!is_array($polygon)) {
                continue;
            }
            $rings[] = $polygon[0] ?? null;
        }
    }

    $best = [];
    foreach ($rings as $ring) {
        if (!is_array($ring) || count($ring) < 3) {
            continue;
        }
        $pts = [];
        foreach ($ring as $coord) {
            if (!is_array($coord) || count($coord) < 2) {
                continue;
            }
            if (!is_numeric($coord[0]) || !is_numeric($coord[1])) {
                continue;
            }
            $pts[] = [(float) $coord[0], (float) $coord[1]];
        }
        if (count($pts) >= 2) {
            $first = $pts[0];
            $last = $pts[count($pts) - 1];
            if ($first[0] === $last[0] && $first[1] === $last[1]) {
                array_pop($pts);
            }
        }
        if (count($pts) > count($best)) {
            $best = $pts;
        }
    }

    return $best;
}

/**
 * Whether a feature can participate in display merging.
 *
 * @param array<string, mixed> $feature
 */
function notamTfrMapLayerDisplayFeatureIsMergeable(array $feature): bool
{
    return notamTfrMapLayerDisplayFeatureIsMergeablePolygon($feature)
        || notamTfrMapLayerDisplayFeatureIsMergeableCircle($feature);
}

/**
 * Whether a feature can participate in polygon display merging.
 *
 * @param array<string, mixed> $feature
 */
function notamTfrMapLayerDisplayFeatureIsMergeablePolygon(array $feature): bool
{
    $geometry = $feature['geometry'] ?? null;
    if (!is_array($geometry)) {
        return false;
    }
    $type = strtolower((string) ($geometry['type'] ?? ''));
    if ($type !== 'polygon' && $type !== 'multipolygon') {
        return false;
    }
    $props = is_array($feature['properties'] ?? null) ? $feature['properties'] : [];
    $kind = strtolower((string) ($props['geometry_kind'] ?? $type));

    return $kind === 'polygon' || $kind === 'multipolygon';
}

/**
 * Whether a feature is a drawable circle that can display-merge.
 *
 * @param array<string, mixed> $feature
 */
function notamTfrMapLayerDisplayFeatureIsMergeableCircle(array $feature): bool
{
    $geometry = $feature['geometry'] ?? null;
    if (!is_array($geometry)) {
        return false;
    }
    if (strtolower((string) ($geometry['type'] ?? '')) !== 'point') {
        return false;
    }
    $props = is_array($feature['properties'] ?? null) ? $feature['properties'] : [];
    if (strtolower((string) ($props['geometry_kind'] ?? '')) !== 'circle') {
        return false;
    }
    $coords = $geometry['coordinates'] ?? null;
    if (!is_array($coords) || count($coords) < 2) {
        return false;
    }

    return notamTfrMapLayerDisplayFeatureRadiusNm($feature) > 0.0;
}

/**
 * Circle radius in nautical miles, or 0 when unknown.
 *
 * @param array<string, mixed> $feature
 */
function notamTfrMapLayerDisplayFeatureRadiusNm(array $feature): float
{
    $props = is_array($feature['properties'] ?? null) ? $feature['properties'] : [];
    if (isset($props['radius_nm']) && is_numeric($props['radius_nm'])) {
        return max(0.0, (float) $props['radius_nm']);
    }
    if (isset($props['radius_m']) && is_numeric($props['radius_m'])) {
        return max(0.0, (float) $props['radius_m'] / 1852.0);
    }

    return 0.0;
}

/**
 * Bucket key: restriction kind + vertical limits + style bucket.
 *
 * Features without a parseable vertical band are not display-merged.
 *
 * @param array<string, mixed> $feature
 */
function notamTfrMapLayerDisplayBucketKey(array $feature): ?string
{
    $props = is_array($feature['properties'] ?? null) ? $feature['properties'] : [];
    $vertical = notamTfrMapLayerDisplayVerticalKey($feature);
    if ($vertical === null || $vertical === '') {
        return null;
    }
    $restriction = strtolower((string) ($props['restriction_kind'] ?? 'tfr'));
    $style = strtolower((string) ($props['map_layer_style'] ?? $props['status'] ?? 'active'));

    return $restriction . '|' . $vertical . '|' . $style;
}

/**
 * Normalized vertical band key such as `SFC-9000` or `SFC-5000-AGL`, or null when unknown.
 *
 * Includes AGL/MSL (and FL/UNL when present) so distinct datums never share a merge bucket.
 *
 * @param array<string, mixed> $feature
 */
function notamTfrMapLayerDisplayVerticalKey(array $feature): ?string
{
    $props = is_array($feature['properties'] ?? null) ? $feature['properties'] : [];
    $headline = (string) ($props['banner_headline'] ?? '');
    if (preg_match('/\b(SFC|\d+)\s*-\s*(\d+)\s*ft(?:\s+(AGL|MSL|FL|UNL))?\b/i', $headline, $m) === 1) {
        $lo = strtoupper($m[1]);
        $key = $lo . '-' . $m[2];
        if (isset($m[3]) && $m[3] !== '') {
            $key .= '-' . strtoupper($m[3]);
        }

        return $key;
    }

    return null;
}

/**
 * @param list<array<string, mixed>> $features Same display bucket
 * @return list<list<array<string, mixed>>>
 */
function notamTfrMapLayerDisplayClusterByOverlap(array $features): array
{
    $n = count($features);
    if ($n <= 1) {
        return $features === [] ? [] : [$features];
    }

    $parent = range(0, $n - 1);
    $find = static function (int $x) use (&$parent, &$find): int {
        if ($parent[$x] !== $x) {
            $parent[$x] = $find($parent[$x]);
        }

        return $parent[$x];
    };
    $union = static function (int $a, int $b) use (&$parent, $find): void {
        $ra = $find($a);
        $rb = $find($b);
        if ($ra !== $rb) {
            $parent[$rb] = $ra;
        }
    };

    $boxes = [];
    foreach ($features as $i => $feature) {
        $boxes[$i] = notamTfrMapLayerDisplayFeatureBBox($feature);
    }

    for ($i = 0; $i < $n; $i++) {
        if ($boxes[$i] === null) {
            continue;
        }
        for ($j = $i + 1; $j < $n; $j++) {
            if ($boxes[$j] === null) {
                continue;
            }
            if (notamTfrMapLayerDisplayFeaturesShouldCluster($features[$i], $features[$j], $boxes[$i], $boxes[$j])) {
                $union($i, $j);
            }
        }
    }

    /** @var array<int, list<array<string, mixed>>> $groups */
    $groups = [];
    for ($i = 0; $i < $n; $i++) {
        $root = $find($i);
        $groups[$root][] = $features[$i];
    }

    return array_values($groups);
}

/**
 * Whether two features should join the same display cluster.
 *
 * Circle peers merge only when one circle fully contains the other. Lateral
 * overlaps that merely touch stay as separate circles.
 *
 * @param array<string, mixed> $featureA
 * @param array<string, mixed> $featureB
 * @param array{0: float, 1: float, 2: float, 3: float} $boxA
 * @param array{0: float, 1: float, 2: float, 3: float} $boxB
 */
function notamTfrMapLayerDisplayFeaturesShouldCluster(
    array $featureA,
    array $featureB,
    array $boxA,
    array $boxB
): bool {
    $aCircle = notamTfrMapLayerDisplayFeatureIsMergeableCircle($featureA);
    $bCircle = notamTfrMapLayerDisplayFeatureIsMergeableCircle($featureB);
    if ($aCircle && $bCircle) {
        return notamTfrMapLayerDisplayCircleContainsCircle($featureA, $featureB)
            || notamTfrMapLayerDisplayCircleContainsCircle($featureB, $featureA);
    }

    return notamTfrMapLayerDisplayBBoxesShouldCluster($boxA, $boxB);
}

/**
 * Whether two bboxes should join a display cluster (polygon / circle-polygon).
 *
 * @param array{0: float, 1: float, 2: float, 3: float} $a
 * @param array{0: float, 1: float, 2: float, 3: float} $b
 */
function notamTfrMapLayerDisplayBBoxesShouldCluster(array $a, array $b): bool
{
    if (notamTfrMapLayerDisplayBBoxIou($a, $b) >= NOTAM_TFR_MAP_DISPLAY_IOU_THRESHOLD) {
        return true;
    }
    if (notamTfrMapLayerDisplayBBoxContains($a, $b) || notamTfrMapLayerDisplayBBoxContains($b, $a)) {
        return true;
    }

    return notamTfrMapLayerDisplayBBoxCoverage($a, $b) >= NOTAM_TFR_MAP_DISPLAY_COVERAGE_THRESHOLD;
}

/**
 * True when $inner lies fully inside $outer (centers + radii in NM).
 *
 * @param array<string, mixed> $outer
 * @param array<string, mixed> $inner
 */
function notamTfrMapLayerDisplayCircleContainsCircle(array $outer, array $inner): bool
{
    $outerCenter = notamTfrMapLayerDisplayFeatureCenter($outer);
    $innerCenter = notamTfrMapLayerDisplayFeatureCenter($inner);
    $outerR = notamTfrMapLayerDisplayFeatureRadiusNm($outer);
    $innerR = notamTfrMapLayerDisplayFeatureRadiusNm($inner);
    if ($outerCenter === null || $innerCenter === null || $outerR <= 0.0 || $innerR <= 0.0) {
        return false;
    }
    if ($innerR > $outerR + 1e-6) {
        return false;
    }
    $dist = notamTfrMapLayerDisplayDistanceNm(
        $outerCenter[0],
        $outerCenter[1],
        $innerCenter[0],
        $innerCenter[1]
    );

    // Small slack for float / center rounding in source geometry.
    return ($dist + $innerR) <= ($outerR + 0.05);
}

/**
 * @param list<array<string, mixed>> $cluster
 * @return array<string, mixed>|null
 */
function notamTfrMapLayerDisplayMergeCluster(array $cluster): ?array
{
    $allCircles = true;
    foreach ($cluster as $feature) {
        if (!notamTfrMapLayerDisplayFeatureIsMergeableCircle($feature)) {
            $allCircles = false;
            break;
        }
    }

    $primary = $cluster[0];
    if ($allCircles) {
        $bestRadius = -1.0;
        foreach ($cluster as $feature) {
            $radius = notamTfrMapLayerDisplayFeatureRadiusNm($feature);
            if ($radius > $bestRadius) {
                $bestRadius = $radius;
                $primary = $feature;
            } elseif ($radius === $bestRadius) {
                $primary = notamTfrMapLayerPreferFeature($feature, $primary);
            }
        }
    } else {
        foreach ($cluster as $feature) {
            $primary = notamTfrMapLayerPreferFeature($feature, $primary);
        }
    }

    $memberIds = [];
    foreach ($cluster as $feature) {
        $props = is_array($feature['properties'] ?? null) ? $feature['properties'] : [];
        $id = trim((string) ($props['notam_id'] ?? ''));
        if ($id !== '') {
            $memberIds[$id] = true;
        }
    }
    $memberList = array_keys($memberIds);
    usort($memberList, static function (string $a, string $b): int {
        return notamTfrMapLayerDisplayNotamIdSortKey($b) <=> notamTfrMapLayerDisplayNotamIdSortKey($a)
            ?: strcmp($a, $b);
    });

    $props = is_array($primary['properties'] ?? null) ? $primary['properties'] : [];
    $primaryId = $memberList[0] ?? (string) ($props['notam_id'] ?? '');
    $props['notam_id'] = $primaryId;
    $props['member_notam_ids'] = $memberList;
    $props['member_count'] = count($memberList);
    $props['display_merged'] = true;
    $props['display_projection_version'] = NOTAM_TFR_MAP_DISPLAY_PROJECTION_VERSION;

    $headline = trim((string) ($props['banner_headline'] ?? ''));
    if ($headline !== '' && count($memberList) > 1) {
        $props['banner_headline'] = $headline . ' (' . count($memberList) . ' overlapping NOTAMs)';
    }

    $featurePrefix = (($props['restriction_kind'] ?? 'tfr') === 'tfr') ? 'tfr-display-' : 'airspace-display-';
    $featureId = $featurePrefix . preg_replace('/[^A-Za-z0-9_-]+/', '-', $primaryId);

    $circleMembers = [];
    foreach ($cluster as $feature) {
        if (notamTfrMapLayerDisplayFeatureIsMergeableCircle($feature)) {
            $circleMembers[] = $feature;
        }
    }

    // Prefer a covering circle whenever circle members can cover the cluster.
    // Nested circle-in-circle and circle-with-contained-polygon stay circular.
    if ($circleMembers !== []) {
        $circle = notamTfrMapLayerDisplayMergeCircleCluster($circleMembers);
        if ($circle !== null && notamTfrMapLayerDisplayClusterCoveredByCircle($cluster, $circle)) {
            // Headline / style from the largest circle footprint.
            $primary = $circleMembers[0];
            $bestRadius = -1.0;
            foreach ($circleMembers as $feature) {
                $radius = notamTfrMapLayerDisplayFeatureRadiusNm($feature);
                if ($radius > $bestRadius) {
                    $bestRadius = $radius;
                    $primary = $feature;
                } elseif ($radius === $bestRadius) {
                    $primary = notamTfrMapLayerPreferFeature($feature, $primary);
                }
            }
            $props = is_array($primary['properties'] ?? null) ? $primary['properties'] : [];
            $props['notam_id'] = $primaryId;
            $props['member_notam_ids'] = $memberList;
            $props['member_count'] = count($memberList);
            $props['display_merged'] = true;
            $props['display_projection_version'] = NOTAM_TFR_MAP_DISPLAY_PROJECTION_VERSION;
            $headline = trim((string) ($props['banner_headline'] ?? ''));
            if ($headline !== '' && count($memberList) > 1) {
                $props['banner_headline'] = $headline . ' (' . count($memberList) . ' overlapping NOTAMs)';
            }
            $props['geometry_kind'] = 'circle';
            $props['radius_nm'] = $circle['radius_nm'];
            $props['radius_m'] = $circle['radius_nm'] * 1852.0;

            return [
                'type' => 'Feature',
                'id' => $featureId,
                'geometry' => [
                    'type' => 'Point',
                    'coordinates' => [$circle['lon'], $circle['lat']],
                ],
                'properties' => $props,
            ];
        }
    }

    $points = [];
    foreach ($cluster as $feature) {
        foreach (notamTfrMapLayerDisplayFeatureVertices($feature) as $pt) {
            $points[] = $pt;
        }
    }
    $hull = notamTfrMapLayerDisplayConvexHull($points);
    if ($hull === null || count($hull) < 4) {
        return null;
    }

    $props['geometry_kind'] = 'polygon';
    unset($props['radius_nm'], $props['radius_m']);

    return [
        'type' => 'Feature',
        'id' => $featureId,
        'geometry' => [
            'type' => 'Polygon',
            'coordinates' => [$hull],
        ],
        'properties' => $props,
    ];
}

/**
 * Whether every cluster member lies inside the covering circle.
 *
 * @param list<array<string, mixed>> $cluster
 * @param array{lon: float, lat: float, radius_nm: float} $circle
 */
function notamTfrMapLayerDisplayClusterCoveredByCircle(array $cluster, array $circle): bool
{
    foreach ($cluster as $feature) {
        if (notamTfrMapLayerDisplayFeatureIsMergeableCircle($feature)) {
            $center = notamTfrMapLayerDisplayFeatureCenter($feature);
            $radius = notamTfrMapLayerDisplayFeatureRadiusNm($feature);
            if ($center === null || $radius <= 0.0) {
                return false;
            }
            $dist = notamTfrMapLayerDisplayDistanceNm(
                $circle['lon'],
                $circle['lat'],
                $center[0],
                $center[1]
            );
            if (($dist + $radius) > ($circle['radius_nm'] + 0.05)) {
                return false;
            }
            continue;
        }
        foreach (notamTfrMapLayerDisplayFeatureVertices($feature) as $pt) {
            $dist = notamTfrMapLayerDisplayDistanceNm(
                $circle['lon'],
                $circle['lat'],
                $pt[0],
                $pt[1]
            );
            if ($dist > ($circle['radius_nm'] + 0.05)) {
                return false;
            }
        }
    }

    return true;
}

/**
 * Covering circle for a circle-only cluster (largest footprint, centers may differ slightly).
 *
 * @param list<array<string, mixed>> $cluster
 * @return array{lon: float, lat: float, radius_nm: float}|null
 */
function notamTfrMapLayerDisplayMergeCircleCluster(array $cluster): ?array
{
    $bestLon = null;
    $bestLat = null;
    $bestRadius = 0.0;
    foreach ($cluster as $feature) {
        $center = notamTfrMapLayerDisplayFeatureCenter($feature);
        $radius = notamTfrMapLayerDisplayFeatureRadiusNm($feature);
        if ($center === null || $radius <= 0.0) {
            continue;
        }
        if ($radius > $bestRadius) {
            $bestRadius = $radius;
            $bestLon = $center[0];
            $bestLat = $center[1];
        }
    }
    if ($bestLon === null || $bestLat === null || $bestRadius <= 0.0) {
        return null;
    }

    $coverNm = $bestRadius;
    foreach ($cluster as $feature) {
        $center = notamTfrMapLayerDisplayFeatureCenter($feature);
        $radius = notamTfrMapLayerDisplayFeatureRadiusNm($feature);
        if ($center === null || $radius <= 0.0) {
            continue;
        }
        $dist = notamTfrMapLayerDisplayDistanceNm($bestLon, $bestLat, $center[0], $center[1]);
        $coverNm = max($coverNm, $dist + $radius);
    }

    return [
        'lon' => $bestLon,
        'lat' => $bestLat,
        'radius_nm' => $coverNm,
    ];
}

/**
 * @param array<string, mixed> $feature
 * @return array{0: float, 1: float}|null lon, lat
 */
function notamTfrMapLayerDisplayFeatureCenter(array $feature): ?array
{
    $geometry = $feature['geometry'] ?? null;
    if (!is_array($geometry)) {
        return null;
    }
    if (strtolower((string) ($geometry['type'] ?? '')) !== 'point') {
        return null;
    }
    $coords = $geometry['coordinates'] ?? null;
    if (!is_array($coords) || count($coords) < 2) {
        return null;
    }

    return [(float) $coords[0], (float) $coords[1]];
}

/**
 * Approximate great-circle distance in nautical miles (equirectangular).
 */
function notamTfrMapLayerDisplayDistanceNm(float $lon1, float $lat1, float $lon2, float $lat2): float
{
    $meanLat = deg2rad(($lat1 + $lat2) / 2.0);
    $dLatNm = ($lat2 - $lat1) * 60.0;
    $dLonNm = ($lon2 - $lon1) * 60.0 * max(0.2, cos($meanLat));

    return sqrt(($dLatNm * $dLatNm) + ($dLonNm * $dLonNm));
}

/**
 * @param array<string, mixed> $feature
 * @return array{0: float, 1: float, 2: float, 3: float}|null minLon,minLat,maxLon,maxLat
 */
function notamTfrMapLayerDisplayFeatureBBox(array $feature): ?array
{
    if (notamTfrMapLayerDisplayFeatureIsMergeableCircle($feature)) {
        $center = notamTfrMapLayerDisplayFeatureCenter($feature);
        $radiusNm = notamTfrMapLayerDisplayFeatureRadiusNm($feature);
        if ($center === null || $radiusNm <= 0.0) {
            return null;
        }
        $dLat = $radiusNm / 60.0;
        $dLon = $radiusNm / (60.0 * max(0.2, cos(deg2rad($center[1]))));

        return [
            $center[0] - $dLon,
            $center[1] - $dLat,
            $center[0] + $dLon,
            $center[1] + $dLat,
        ];
    }

    $pts = notamTfrMapLayerDisplayFeatureVertices($feature);
    if ($pts === []) {
        return null;
    }
    $minLon = $maxLon = $pts[0][0];
    $minLat = $maxLat = $pts[0][1];
    foreach ($pts as $pt) {
        $minLon = min($minLon, $pt[0]);
        $maxLon = max($maxLon, $pt[0]);
        $minLat = min($minLat, $pt[1]);
        $maxLat = max($maxLat, $pt[1]);
    }

    return [$minLon, $minLat, $maxLon, $maxLat];
}

/**
 * @param array{0: float, 1: float, 2: float, 3: float} $a
 * @param array{0: float, 1: float, 2: float, 3: float} $b
 */
function notamTfrMapLayerDisplayBBoxIou(array $a, array $b): float
{
    $inter = notamTfrMapLayerDisplayBBoxIntersectionArea($a, $b);
    if ($inter <= 0.0) {
        return 0.0;
    }
    $areaA = max(1e-18, ($a[2] - $a[0]) * ($a[3] - $a[1]));
    $areaB = max(1e-18, ($b[2] - $b[0]) * ($b[3] - $b[1]));

    return $inter / ($areaA + $areaB - $inter);
}

/**
 * Intersection area divided by the smaller bbox area (1.0 when one contains the other).
 *
 * @param array{0: float, 1: float, 2: float, 3: float} $a
 * @param array{0: float, 1: float, 2: float, 3: float} $b
 */
function notamTfrMapLayerDisplayBBoxCoverage(array $a, array $b): float
{
    $inter = notamTfrMapLayerDisplayBBoxIntersectionArea($a, $b);
    if ($inter <= 0.0) {
        return 0.0;
    }
    $areaA = max(1e-18, ($a[2] - $a[0]) * ($a[3] - $a[1]));
    $areaB = max(1e-18, ($b[2] - $b[0]) * ($b[3] - $b[1]));

    return $inter / min($areaA, $areaB);
}

/**
 * @param array{0: float, 1: float, 2: float, 3: float} $outer
 * @param array{0: float, 1: float, 2: float, 3: float} $inner
 */
function notamTfrMapLayerDisplayBBoxContains(array $outer, array $inner): bool
{
    $eps = 1e-9;

    return ($outer[0] - $eps) <= $inner[0]
        && ($outer[1] - $eps) <= $inner[1]
        && ($outer[2] + $eps) >= $inner[2]
        && ($outer[3] + $eps) >= $inner[3];
}

/**
 * @param array{0: float, 1: float, 2: float, 3: float} $a
 * @param array{0: float, 1: float, 2: float, 3: float} $b
 */
function notamTfrMapLayerDisplayBBoxIntersectionArea(array $a, array $b): float
{
    $ix1 = max($a[0], $b[0]);
    $iy1 = max($a[1], $b[1]);
    $ix2 = min($a[2], $b[2]);
    $iy2 = min($a[3], $b[3]);
    if ($ix2 <= $ix1 || $iy2 <= $iy1) {
        return 0.0;
    }

    return ($ix2 - $ix1) * ($iy2 - $iy1);
}

/**
 * Exterior-ring vertices as [lon, lat] pairs (closing vertex omitted when duplicate).
 * Circles are sampled into a closed ring approximation for hull merges.
 *
 * @param array<string, mixed> $feature
 * @return list<array{0: float, 1: float}>
 */
function notamTfrMapLayerDisplayFeatureVertices(array $feature): array
{
    if (notamTfrMapLayerDisplayFeatureIsMergeableCircle($feature)) {
        $center = notamTfrMapLayerDisplayFeatureCenter($feature);
        $radiusNm = notamTfrMapLayerDisplayFeatureRadiusNm($feature);
        if ($center === null || $radiusNm <= 0.0) {
            return [];
        }

        return notamTfrMapLayerDisplayCircleSamplePoints($center[0], $center[1], $radiusNm);
    }

    $geometry = $feature['geometry'] ?? null;
    if (!is_array($geometry)) {
        return [];
    }
    $type = strtolower((string) ($geometry['type'] ?? ''));
    $coords = $geometry['coordinates'] ?? null;
    if (!is_array($coords)) {
        return [];
    }

    $rings = [];
    if ($type === 'polygon') {
        if (isset($coords[0]) && is_array($coords[0])) {
            $rings[] = $coords[0];
        }
    } elseif ($type === 'multipolygon') {
        foreach ($coords as $poly) {
            if (is_array($poly) && isset($poly[0]) && is_array($poly[0])) {
                $rings[] = $poly[0];
            }
        }
    }

    $out = [];
    foreach ($rings as $ring) {
        if (!is_array($ring)) {
            continue;
        }
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
            $pt = $ring[$i];
            if (!is_array($pt) || count($pt) < 2) {
                continue;
            }
            $out[] = [(float) $pt[0], (float) $pt[1]];
        }
    }

    return $out;
}

/**
 * @return list<array{0: float, 1: float}>
 */
function notamTfrMapLayerDisplayCircleSamplePoints(float $lon, float $lat, float $radiusNm): array
{
    $dLat = $radiusNm / 60.0;
    $dLon = $radiusNm / (60.0 * max(0.2, cos(deg2rad($lat))));
    $out = [];
    $segments = NOTAM_TFR_MAP_DISPLAY_CIRCLE_SEGMENTS;
    for ($i = 0; $i < $segments; $i++) {
        $theta = (2.0 * M_PI * $i) / $segments;
        $out[] = [
            $lon + ($dLon * cos($theta)),
            $lat + ($dLat * sin($theta)),
        ];
    }

    return $out;
}

/**
 * Monotone-chain convex hull; returns a closed GeoJSON ring or null.
 *
 * @param list<array{0: float, 1: float}> $points
 * @return list<array{0: float, 1: float}>|null
 */
function notamTfrMapLayerDisplayConvexHull(array $points): ?array
{
    if (count($points) < 3) {
        return null;
    }

    $uniq = [];
    foreach ($points as $pt) {
        $key = sprintf('%.6f,%.6f', $pt[0], $pt[1]);
        $uniq[$key] = $pt;
    }
    $pts = array_values($uniq);
    if (count($pts) < 3) {
        return null;
    }

    usort($pts, static function (array $a, array $b): int {
        return $a[0] <=> $b[0] ?: $a[1] <=> $b[1];
    });

    $cross = static function (array $o, array $a, array $b): float {
        return ($a[0] - $o[0]) * ($b[1] - $o[1]) - ($a[1] - $o[1]) * ($b[0] - $o[0]);
    };

    $lower = [];
    foreach ($pts as $p) {
        while (count($lower) >= 2 && $cross($lower[count($lower) - 2], $lower[count($lower) - 1], $p) <= 0) {
            array_pop($lower);
        }
        $lower[] = $p;
    }

    $upper = [];
    for ($i = count($pts) - 1; $i >= 0; $i--) {
        $p = $pts[$i];
        while (count($upper) >= 2 && $cross($upper[count($upper) - 2], $upper[count($upper) - 1], $p) <= 0) {
            array_pop($upper);
        }
        $upper[] = $p;
    }

    array_pop($lower);
    array_pop($upper);
    $hull = array_merge($lower, $upper);
    if (count($hull) < 3) {
        return null;
    }
    $hull[] = $hull[0];

    return $hull;
}

/**
 * Sort key favoring higher numeric NOTAM series (newer FDC numbers tend larger).
 */
function notamTfrMapLayerDisplayNotamIdSortKey(string $notamId): int
{
    if (preg_match('/(\d+)\s*\/\s*(\d+)/', $notamId, $m) === 1) {
        return ((int) $m[2]) * 1000000 + (int) $m[1];
    }
    if (preg_match('/(\d+)/', $notamId, $m) === 1) {
        return (int) $m[1];
    }

    return 0;
}
