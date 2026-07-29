<?php
/**
 * Map-ready TFR display projection.
 *
 * Builds a draw-oriented GeoJSON feature list from per-NOTAM map features.
 * Does not read or write the unified airspace store. Overlapping footprints that
 * share restriction kind, map style bucket, and vertical limits collapse into one
 * convex-hull outline so the directory map signals "research TFRs here" without
 * stacking near-duplicate polygons.
 */

declare(strict_types=1);

/** BBox IoU at or above this value may join a display cluster. */
const NOTAM_TFR_MAP_DISPLAY_IOU_THRESHOLD = 0.5;

/** Diagnostics version for the display projection step. */
const NOTAM_TFR_MAP_DISPLAY_PROJECTION_VERSION = 1;

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

    $passthrough = [];
    /** @var array<string, list<array<string, mixed>>> $buckets */
    $buckets = [];

    foreach ($features as $feature) {
        if (!is_array($feature)) {
            continue;
        }
        if (!notamTfrMapLayerDisplayFeatureIsMergeablePolygon($feature)) {
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
 * Normalized vertical band key such as `SFC-9000`, or null when unknown.
 *
 * @param array<string, mixed> $feature
 */
function notamTfrMapLayerDisplayVerticalKey(array $feature): ?string
{
    $props = is_array($feature['properties'] ?? null) ? $feature['properties'] : [];
    $headline = (string) ($props['banner_headline'] ?? '');
    if (preg_match('/\b(SFC|\d+)\s*-\s*(\d+)\s*ft\b/i', $headline, $m) === 1) {
        $lo = strtoupper($m[1]);

        return $lo . '-' . $m[2];
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
            if (notamTfrMapLayerDisplayBBoxIou($boxes[$i], $boxes[$j]) >= NOTAM_TFR_MAP_DISPLAY_IOU_THRESHOLD) {
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
 * @param list<array<string, mixed>> $cluster
 * @return array<string, mixed>|null
 */
function notamTfrMapLayerDisplayMergeCluster(array $cluster): ?array
{
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

    $primary = $cluster[0];
    foreach ($cluster as $feature) {
        $primary = notamTfrMapLayerPreferFeature($feature, $primary);
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
    $props['geometry_kind'] = 'polygon';
    $props['display_projection_version'] = NOTAM_TFR_MAP_DISPLAY_PROJECTION_VERSION;

    $headline = trim((string) ($props['banner_headline'] ?? ''));
    if ($headline !== '' && count($memberList) > 1) {
        $props['banner_headline'] = $headline . ' (' . count($memberList) . ' overlapping NOTAMs)';
    }

    $featurePrefix = (($props['restriction_kind'] ?? 'tfr') === 'tfr') ? 'tfr-display-' : 'airspace-display-';

    return [
        'type' => 'Feature',
        'id' => $featurePrefix . preg_replace('/[^A-Za-z0-9_-]+/', '-', $primaryId),
        'geometry' => [
            'type' => 'Polygon',
            'coordinates' => [$hull],
        ],
        'properties' => $props,
    ];
}

/**
 * @param array<string, mixed> $feature
 * @return array{0: float, 1: float, 2: float, 3: float}|null minLon,minLat,maxLon,maxLat
 */
function notamTfrMapLayerDisplayFeatureBBox(array $feature): ?array
{
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
    $ix1 = max($a[0], $b[0]);
    $iy1 = max($a[1], $b[1]);
    $ix2 = min($a[2], $b[2]);
    $iy2 = min($a[3], $b[3]);
    if ($ix2 <= $ix1 || $iy2 <= $iy1) {
        return 0.0;
    }
    $inter = ($ix2 - $ix1) * ($iy2 - $iy1);
    $areaA = max(1e-18, ($a[2] - $a[0]) * ($a[3] - $a[1]));
    $areaB = max(1e-18, ($b[2] - $b[0]) * ($b[3] - $b[1]));

    return $inter / ($areaA + $areaB - $inter);
}

/**
 * Exterior-ring vertices as [lon, lat] pairs (closing vertex omitted when duplicate).
 *
 * @param array<string, mixed> $feature
 * @return list<array{0: float, 1: float}>
 */
function notamTfrMapLayerDisplayFeatureVertices(array $feature): array
{
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
