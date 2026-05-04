<?php
/**
 * Country resolution from coordinates using vendored administrative polygons (GeoJSON).
 *
 * Point-in-polygon is implemented natively (no GEOS). GeoJSON parsing is json_decode only.
 * Polygon loader is isolated so the on-disk format can change without altering callers.
 *
 * @package AviationWX
 */

/**
 * Absolute path to the bundled Admin-0 countries GeoJSON (Natural Earth scale, repo-vendored).
 */
function countryResolutionGetBundledAdmin0GeoJsonPath(): string
{
    return dirname(__DIR__) . '/data/geo/ne_110m_admin_0_countries.geojson';
}

/**
 * Whether a string is a valid ISO 3166-1 alpha-2 code (uses bundled allowlist).
 *
 * @param string $code Candidate code (any case)
 * @return bool True if valid and not a reserved/placeholder Natural Earth sentinel
 */
function countryResolutionIsValidIso3166Alpha2(string $code): bool
{
    $code = strtoupper(trim($code));
    if (strlen($code) !== 2 || !ctype_alpha($code)) {
        return false;
    }
    static $allowed = null;
    if ($allowed === null) {
        $path = __DIR__ . '/data/iso3166-alpha2-codes.txt';
        $allowed = [];
        if (is_readable($path)) {
            foreach (file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
                $line = strtoupper(trim($line));
                if (strlen($line) === 2 && ctype_alpha($line)) {
                    $allowed[$line] = true;
                }
            }
        }
    }
    return isset($allowed[$code]);
}

/**
 * Parse Natural Earth-style Admin-0 GeoJSON into a normalized feature list for hit testing.
 *
 * Each returned element: `iso_a2` (string), `parts` (list of array{exterior: ring, holes: list<ring>}),
 * where each ring is a list of [lon, lat] vertices (closed or unclosed).
 *
 * Skips features without a usable ISO_A2 (e.g. Natural Earth "-99" sentinels).
 *
 * @param string $path Absolute path to GeoJSON FeatureCollection file
 * @return list<array{iso_a2: string, parts: list<array{exterior: list<list{0: float, 1: float}>, holes: list<list<list{0: float, 1: float}>}>}>
 */
function countryResolutionLoadAdmin0FeaturesFromGeoJson(string $path): array
{
    if (!is_readable($path)) {
        return [];
    }
    $raw = file_get_contents($path);
    if ($raw === false) {
        return [];
    }
    $data = json_decode($raw, true);
    if (!is_array($data) || ($data['type'] ?? '') !== 'FeatureCollection' || !isset($data['features']) || !is_array($data['features'])) {
        return [];
    }
    $out = [];
    foreach ($data['features'] as $feature) {
        if (!is_array($feature) || ($feature['type'] ?? '') !== 'Feature') {
            continue;
        }
        $props = $feature['properties'] ?? [];
        if (!is_array($props)) {
            continue;
        }
        $iso = isset($props['ISO_A2']) && is_string($props['ISO_A2']) ? strtoupper(trim($props['ISO_A2'])) : '';
        if ($iso === '' || $iso === '-99' || strlen($iso) !== 2 || !ctype_alpha($iso)) {
            continue;
        }
        $geom = $feature['geometry'] ?? null;
        if (!is_array($geom)) {
            continue;
        }
        $parts = countryResolutionNormalizeGeometryToParts($geom);
        if ($parts === []) {
            continue;
        }
        $out[] = ['iso_a2' => $iso, 'parts' => $parts];
    }
    return $out;
}

/**
 * @param array<string, mixed> $geometry GeoJSON geometry object
 * @return list<array{exterior: list<list{0: float, 1: float}>, holes: list<list<list{0: float, 1: float}>}>}>
 */
function countryResolutionNormalizeGeometryToParts(array $geometry): array
{
    $type = $geometry['type'] ?? '';
    $coords = $geometry['coordinates'] ?? null;
    if (!is_array($coords)) {
        return [];
    }
    if ($type === 'Polygon') {
        $exterior = countryResolutionNormalizeRing($coords[0] ?? []);
        if ($exterior === []) {
            return [];
        }
        $holes = [];
        for ($i = 1, $n = count($coords); $i < $n; $i++) {
            $h = countryResolutionNormalizeRing($coords[$i]);
            if ($h !== []) {
                $holes[] = $h;
            }
        }
        return [['exterior' => $exterior, 'holes' => $holes]];
    }
    if ($type === 'MultiPolygon') {
        $parts = [];
        foreach ($coords as $poly) {
            if (!is_array($poly) || $poly === []) {
                continue;
            }
            $exterior = countryResolutionNormalizeRing($poly[0] ?? []);
            if ($exterior === []) {
                continue;
            }
            $holes = [];
            for ($i = 1, $n = count($poly); $i < $n; $i++) {
                $h = countryResolutionNormalizeRing($poly[$i] ?? []);
                if ($h !== []) {
                    $holes[] = $h;
                }
            }
            $parts[] = ['exterior' => $exterior, 'holes' => $holes];
        }
        return $parts;
    }
    return [];
}

/**
 * @param mixed $ring GeoJSON linear ring
 * @return list<array{0: float, 1: float}>
 */
function countryResolutionNormalizeRing($ring): array
{
    if (!is_array($ring)) {
        return [];
    }
    $norm = [];
    foreach ($ring as $pt) {
        if (!is_array($pt) || count($pt) < 2) {
            continue;
        }
        if (!is_numeric($pt[0]) || !is_numeric($pt[1])) {
            continue;
        }
        $norm[] = [(float) $pt[0], (float) $pt[1]];
    }
    if (count($norm) < 3) {
        return [];
    }
    $first = $norm[0];
    $last = $norm[count($norm) - 1];
    if ($first[0] === $last[0] && $first[1] === $last[1]) {
        array_pop($norm);
    }
    return $norm;
}

/**
 * Ray-cast point-in-ring test (ring in lon/lat space).
 *
 * @param list<array{0: float, 1: float}> $ring Closed or open ring
 */
function countryResolutionPointInRing(float $lon, float $lat, array $ring): bool
{
    $n = count($ring);
    if ($n < 3) {
        return false;
    }
    $inside = false;
    for ($i = 0, $j = $n - 1; $i < $n; $j = $i++) {
        $xi = $ring[$i][0];
        $yi = $ring[$i][1];
        $xj = $ring[$j][0];
        $yj = $ring[$j][1];
        $intersect = (($yi > $lat) !== ($yj > $lat))
            && ($lon < ($xj - $xi) * ($lat - $yi) / ($yj - $yi + 1e-18) + $xi);
        if ($intersect) {
            $inside = !$inside;
        }
    }
    return $inside;
}

/**
 * @param list<array{0: float, 1: float}> $ring
 */
function countryResolutionRingBBox(array $ring): array
{
    $minLon = INF;
    $maxLon = -INF;
    $minLat = INF;
    $maxLat = -INF;
    foreach ($ring as $pt) {
        $minLon = min($minLon, $pt[0]);
        $maxLon = max($maxLon, $pt[0]);
        $minLat = min($minLat, $pt[1]);
        $maxLat = max($maxLat, $pt[1]);
    }
    return ['minLon' => $minLon, 'maxLon' => $maxLon, 'minLat' => $minLat, 'maxLat' => $maxLat];
}

/**
 * @param array{exterior: list<array{0: float, 1: float}>, holes: list<list<array{0: float, 1: float}>>} $part
 */
function countryResolutionPartBBox(array $part): array
{
    $bb = countryResolutionRingBBox($part['exterior']);
    return $bb;
}

/**
 * @param array{minLon: float, maxLon: float, minLat: float, maxLat: float} $bb
 */
function countryResolutionBboxContainsPoint(array $bb, float $lon, float $lat): bool
{
    return $lon >= $bb['minLon'] && $lon <= $bb['maxLon'] && $lat >= $bb['minLat'] && $lat <= $bb['maxLat'];
}

/**
 * @param array{exterior: list<array{0: float, 1: float}>, holes: list<list<array{0: float, 1: float}>>} $part
 */
function countryResolutionPointInPart(float $lon, float $lat, array $part): bool
{
    if (!countryResolutionPointInRing($lon, $lat, $part['exterior'])) {
        return false;
    }
    foreach ($part['holes'] as $hole) {
        if ($hole !== [] && countryResolutionPointInRing($lon, $lat, $hole)) {
            return false;
        }
    }
    return true;
}

/**
 * Resolve ISO 3166-1 alpha-2 country at a coordinate using normalized Admin-0 features.
 *
 * Returns the first matching country when polygons overlap (data should not overlap in practice).
 *
 * @param float $lat WGS84 latitude
 * @param float $lon WGS84 longitude
 * @param list<array{iso_a2: string, parts: list<array{exterior: list<array{0: float, 1: float}>, holes: list<list<array{0: float, 1: float}>>}>}> $features From countryResolutionLoadAdmin0FeaturesFromGeoJson()
 * @return string|null Uppercase alpha-2 or null if not inside any feature
 */
function countryResolutionFindIsoAlpha2AtLatLon(float $lat, float $lon, array $features): ?string
{
    foreach ($features as $feature) {
        $iso = $feature['iso_a2'] ?? '';
        if (!is_string($iso) || $iso === '') {
            continue;
        }
        foreach ($feature['parts'] as $part) {
            $bb = countryResolutionPartBBox($part);
            if (!countryResolutionBboxContainsPoint($bb, $lon, $lat)) {
                continue;
            }
            if (countryResolutionPointInPart($lon, $lat, $part)) {
                return strtoupper($iso);
            }
        }
    }
    return null;
}
