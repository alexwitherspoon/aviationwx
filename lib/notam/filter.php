<?php
/**
 * NOTAM Filter
 * 
 * Filters NOTAMs for aerodrome closures and TFRs relevant to an airport.
 * Safety-critical: Ensures only geographically relevant NOTAMs are shown to pilots.
 */

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../constants.php';
require_once __DIR__ . '/../units.php';
require_once __DIR__ . '/../airport-identifiers.php';
require_once __DIR__ . '/../weather/utils.php';
require_once __DIR__ . '/schedule.php';
require_once __DIR__ . '/tfr-indicators.php';

/** Epsilon for comparing decoded TFR vertices (degrees) */
const TFR_VERTEX_EQUAL_EPSILON_DEG = 1.0e-6;

/**
 * Minimum absolute signed double-area (NM^2) for a polygon ring to be treated as
 * a non-degenerate closed shape. Below this, relevance fails closed.
 */
const TFR_POLYGON_MIN_ABS_DOUBLE_AREA_NM2 = 1e-8;

/**
 * PCRE pattern for one FAA coordinate pair (DDMMSSN then DDDMMSSW/E).
 * Capture groups 1-8: lat deg,min,sec,hem; lon deg,min,sec,hem.
 *
 * @return string Regex pattern with delimiters
 */
function tfrCoordinatePairRegex(): string {
    return '/(\d{2})(\d{2})(\d{2})([NS])\s*(\d{2,3})(\d{2})(\d{2})([EW])/i';
}

/**
 * Decode one DDMMSSN / DDDMMSSW match from {@see tfrCoordinatePairRegex()}.
 *
 * Rejects components outside FAA-style ranges (minutes and seconds 0--59,
 * latitude degrees 0--90, longitude degrees 0--180) so corrupted or
 * non-conforming text does not shift decoded positions silently.
 *
 * @param array<int|string,string> $m Match array: full at [0], groups 1-8 for lat/lon fields
 * @return array{lat: float, lon: float}|null Invalid groups or out-of-range coordinates
 */
function tfrDecodeCoordinateGroups(array $m): ?array {
    if (!isset($m[8])) {
        return null;
    }
    $latDeg = (int)$m[1];
    $latMin = (int)$m[2];
    $latSec = (int)$m[3];
    $latDir = strtoupper((string)$m[4]);

    $lonDeg = (int)$m[5];
    $lonMin = (int)$m[6];
    $lonSec = (int)$m[7];
    $lonDir = strtoupper((string)$m[8]);

    if (
        $latDeg < 0 || $latDeg > 90
        || $lonDeg < 0 || $lonDeg > 180
        || $latMin < 0 || $latMin > 59
        || $latSec < 0 || $latSec > 59
        || $lonMin < 0 || $lonMin > 59
        || $lonSec < 0 || $lonSec > 59
        || ($latDir !== 'N' && $latDir !== 'S')
        || ($lonDir !== 'E' && $lonDir !== 'W')
    ) {
        return null;
    }

    if (($latDeg === 90 && ($latMin > 0 || $latSec > 0))
        || ($lonDeg === 180 && ($lonMin > 0 || $lonSec > 0))) {
        return null;
    }

    $lat = $latDeg + ($latMin / 60) + ($latSec / 3600);
    $lon = $lonDeg + ($lonMin / 60) + ($lonSec / 3600);

    if ($latDir === 'S') {
        $lat = -$lat;
    }
    if ($lonDir === 'W') {
        $lon = -$lon;
    }

    if ($lat >= -90 && $lat <= 90 && $lon >= -180 && $lon <= 180) {
        return ['lat' => $lat, 'lon' => $lon];
    }

    return null;
}

/**
 * Parse coordinates from TFR NOTAM text
 *
 * Returns the first coordinate pair in standard aviation format. For polygon
 * definitions, use {@see parseTfrPolygonVertices()}, {@see parseTfrPolygonVerticesMeta()},
 * or {@see parseTfrGeographicRelevanceReference()}.
 *
 * Supported formats:
 * - DDMMSSN/DDDMMSSW (e.g., 413900N1122300W)
 * - With optional whitespace between lat/lon hemispheres and longitude digits
 *
 * @param string $text NOTAM text to parse (empty string returns null)
 * @return array{lat: float, lon: float}|null Decimal degrees or null if no valid coordinates found
 */
function parseTfrCoordinates(string $text): ?array {
    if (preg_match(tfrCoordinatePairRegex(), $text, $matches)) {
        return tfrDecodeCoordinateGroups($matches);
    }

    return null;
}

/**
 * Detect FAA phrasing that closes a polygon without repeating the first coordinate.
 *
 * @param string $text NOTAM body (case-insensitive match)
 * @return bool True when POINT OF ORIGIN appears as a whole phrase
 */
function tfrPolygonHasPointOfOriginClosurePhrase(string $text): bool {
    return preg_match('/\bPOINT\s+OF\s+ORIGIN\b/i', $text) === 1;
}

/**
 * Parse all DDMMSSN/DDDMMSSW coordinate pairs from TFR text in document order.
 *
 * Collapses consecutive duplicates and removes a closing vertex equal to the first
 * when present. {@see parseTfrPolygonVerticesMeta()} also records whether the ring
 * is watertight (explicit closure or POINT OF ORIGIN).
 *
 * @param string $text NOTAM text
 * @return array<int, array{lat: float, lon: float}> Ordered vertices (may be empty)
 */
function parseTfrPolygonVertices(string $text): array {
    return parseTfrPolygonVerticesMeta($text)['vertices'];
}

/**
 * Parse polygon vertices and whether the NOTAM defines a closed ring.
 *
 * ring_closed is true when the first and last decoded coordinates coincide (after
 * deduping consecutive repeats), or when the text contains "POINT OF ORIGIN" so
 * the last edge returns to the first vertex. Otherwise fail closed for polygon TFRs.
 *
 * @param string $text NOTAM text
 * @return array{vertices: array<int, array{lat: float, lon: float}>, ring_closed: bool}
 */
function parseTfrPolygonVerticesMeta(string $text): array {
    if (!preg_match_all(tfrCoordinatePairRegex(), $text, $matches, PREG_SET_ORDER)) {
        return ['vertices' => [], 'ring_closed' => false];
    }

    $vertices = [];
    foreach ($matches as $row) {
        $pt = tfrDecodeCoordinateGroups($row);
        if ($pt === null) {
            continue;
        }
        $last = end($vertices);
        if ($last !== false
            && abs($last['lat'] - $pt['lat']) < TFR_VERTEX_EQUAL_EPSILON_DEG
            && abs($last['lon'] - $pt['lon']) < TFR_VERTEX_EQUAL_EPSILON_DEG) {
            continue;
        }
        $vertices[] = $pt;
    }

    $ringClosed = false;
    $n = count($vertices);
    if ($n >= 2) {
        $first = $vertices[0];
        $last = $vertices[$n - 1];
        if (abs($first['lat'] - $last['lat']) < TFR_VERTEX_EQUAL_EPSILON_DEG
            && abs($first['lon'] - $last['lon']) < TFR_VERTEX_EQUAL_EPSILON_DEG) {
            $ringClosed = true;
            array_pop($vertices);
        }
    }

    if (!$ringClosed && tfrPolygonHasPointOfOriginClosurePhrase($text) && count($vertices) >= 3) {
        $ringClosed = true;
    }

    return ['vertices' => $vertices, 'ring_closed' => $ringClosed];
}

/**
 * Project geodetic vertices to local east/north plane (NM) for small polygons.
 *
 * @param array<int, array{lat: float, lon: float}> $vertices Vertex list (non-empty)
 * @return array{ref_lat: float, ref_lon: float, xs: float[], ys: float[], cos_ref: float}|null Null if empty or near-pole (unreliable plane)
 */
function tfrPolygonProjectVerticesToLocalPlaneNm(array $vertices): ?array {
    $n = count($vertices);
    if ($n < 1) {
        return null;
    }
    $refLat = 0.0;
    $refLon = 0.0;
    foreach ($vertices as $v) {
        $refLat += $v['lat'];
        $refLon += $v['lon'];
    }
    $refLat /= $n;
    $refLon /= $n;

    $refLatRad = deg2rad($refLat);
    $cosRef = cos($refLatRad);
    if (abs($cosRef) < 1e-8) {
        return null;
    }

    $rNm = EARTH_RADIUS_NAUTICAL_MILES;
    $xs = [];
    $ys = [];
    foreach ($vertices as $v) {
        $xs[] = $rNm * $cosRef * deg2rad($v['lon'] - $refLon);
        $ys[] = $rNm * deg2rad($v['lat'] - $refLat);
    }

    return [
        'ref_lat' => $refLat,
        'ref_lon' => $refLon,
        'xs' => $xs,
        'ys' => $ys,
        'cos_ref' => $cosRef,
    ];
}

/**
 * Signed double area (NM^2) of a simple polygon in the local plane (shoelace).
 *
 * @param float[] $xs Easting (NM) per vertex
 * @param float[] $ys Northing (NM) per vertex
 * @return float Signed double area in square NM; zero if fewer than three vertices or length mismatch
 */
function tfrPolygonSignedDoubleAreaNm2(array $xs, array $ys): float {
    $n = count($xs);
    if ($n < 3 || $n !== count($ys)) {
        return 0.0;
    }
    $twice = 0.0;
    for ($i = 0; $i < $n; $i++) {
        $j = ($i + 1) % $n;
        $twice += $xs[$i] * $ys[$j] - $xs[$j] * $ys[$i];
    }

    return $twice;
}

/**
 * Ray casting point-in-polygon test in local NM plane.
 *
 * @param float $px Airport easting (NM) in the same frame as $xs/$ys
 * @param float $py Airport northing (NM)
 * @param float[] $xs Polygon eastings (NM)
 * @param float[] $ys Polygon northings (NM)
 * @return bool True if the test point lies inside the ring (boundary excluded by ray parity)
 */
function pointInPolygonRayCastLocalNm(float $px, float $py, array $xs, array $ys): bool {
    $n = count($xs);
    if ($n < 3 || $n !== count($ys)) {
        return false;
    }
    $inside = false;
    for ($i = 0, $j = $n - 1; $i < $n; $j = $i++) {
        $xi = $xs[$i];
        $yi = $ys[$i];
        $xj = $xs[$j];
        $yj = $ys[$j];
        $crosses = ($yi > $py) !== ($yj > $py);
        if ($crosses) {
            $denom = $yj - $yi;
            if (abs($denom) < 1e-18) {
                continue;
            }
            $xInt = $xi + ($xj - $xi) * ($py - $yi) / $denom;
            if ($px < $xInt) {
                $inside = !$inside;
            }
        }
    }

    return $inside;
}

/**
 * Shortest distance from a point to a closed polygon edge (NM) in the local plane.
 *
 * @param float $px Airport easting (NM)
 * @param float $py Airport northing (NM)
 * @param float[] $xs Polygon eastings (NM)
 * @param float[] $ys Polygon northings (NM)
 * @return float Shortest distance to any edge (NM), or PHP_FLOAT_MAX if fewer than two vertices
 */
function minDistancePointToPolygonRingNm(float $px, float $py, array $xs, array $ys): float {
    $n = count($xs);
    if ($n < 2 || $n !== count($ys)) {
        return PHP_FLOAT_MAX;
    }
    $minSq = PHP_FLOAT_MAX;
    for ($i = 0; $i < $n; $i++) {
        $j = ($i + 1) % $n;
        $x1 = $xs[$i];
        $y1 = $ys[$i];
        $x2 = $xs[$j];
        $y2 = $ys[$j];
        $dx = $x2 - $x1;
        $dy = $y2 - $y1;
        $lenSq = $dx * $dx + $dy * $dy;
        if ($lenSq < 1e-24) {
            $t = 0.0;
        } else {
            $t = (($px - $x1) * $dx + ($py - $y1) * $dy) / $lenSq;
            $t = max(0.0, min(1.0, $t));
        }
        $qx = $x1 + $t * $dx;
        $qy = $y1 + $t * $dy;
        $dsq = ($px - $qx) * ($px - $qx) + ($py - $qy) * ($py - $qy);
        if ($dsq < $minSq) {
            $minSq = $dsq;
        }
    }

    return sqrt(max(0.0, $minSq));
}

/**
 * True when the airport lies inside the closed polygon or within the relevance buffer
 * outside its boundary (NM, local plane).
 *
 * @param array<int, array{lat: float, lon: float}> $vertices At least three corners (ring not repeated)
 * @return bool False when polygon is degenerate in the projected plane
 */
function isAirportInsideOrNearTfrPolygonRing(array $vertices, float $airportLat, float $airportLon): bool {
    $n = count($vertices);
    if ($n < 3) {
        return false;
    }

    $plane = tfrPolygonProjectVerticesToLocalPlaneNm($vertices);
    if ($plane === null) {
        return false;
    }

    $refLat = $plane['ref_lat'];
    $refLon = $plane['ref_lon'];
    $cosRef = $plane['cos_ref'];
    $xs = $plane['xs'];
    $ys = $plane['ys'];
    $rNm = EARTH_RADIUS_NAUTICAL_MILES;

    $signedDouble = tfrPolygonSignedDoubleAreaNm2($xs, $ys);
    if (abs($signedDouble) < TFR_POLYGON_MIN_ABS_DOUBLE_AREA_NM2) {
        return false;
    }

    $px = $rNm * $cosRef * deg2rad($airportLon - $refLon);
    $py = $rNm * deg2rad($airportLat - $refLat);

    if (pointInPolygonRayCastLocalNm($px, $py, $xs, $ys)) {
        return true;
    }

    $distEdge = minDistancePointToPolygonRingNm($px, $py, $xs, $ys);

    return $distEdge <= TFR_RELEVANCE_BUFFER_NM;
}

/**
 * Planar polygon centroid in lat/lon using a local equirectangular tangent plane (NM).
 *
 * Suitable for small TFR polygons (CONUS incident airspace). Returns null when
 * fewer than three vertices or the polygon is degenerate (near-zero area), so
 * callers can fail closed.
 *
 * @param array<int, array{lat: float, lon: float}> $vertices Closed ring not required; algorithm treats edge N-1 to 0 as closing
 * @return array{lat: float, lon: float}|null Centroid or null if not computable
 */
function polygonCentroidLatLonFromVertices(array $vertices): ?array {
    $n = count($vertices);
    if ($n < 3) {
        return null;
    }

    $plane = tfrPolygonProjectVerticesToLocalPlaneNm($vertices);
    if ($plane === null) {
        return null;
    }

    $refLat = $plane['ref_lat'];
    $refLon = $plane['ref_lon'];
    $cosRef = $plane['cos_ref'];
    $xs = $plane['xs'];
    $ys = $plane['ys'];
    $rNm = EARTH_RADIUS_NAUTICAL_MILES;

    $doubleArea = 0.0;
    $sumCx = 0.0;
    $sumCy = 0.0;
    for ($i = 0; $i < $n; $i++) {
        $j = ($i + 1) % $n;
        $cross = $xs[$i] * $ys[$j] - $xs[$j] * $ys[$i];
        $doubleArea += $cross;
        $sumCx += ($xs[$i] + $xs[$j]) * $cross;
        $sumCy += ($ys[$i] + $ys[$j]) * $cross;
    }

    if (abs($doubleArea) < TFR_POLYGON_MIN_ABS_DOUBLE_AREA_NM2) {
        return null;
    }

    $centroidX = $sumCx / (3.0 * $doubleArea);
    $centroidY = $sumCy / (3.0 * $doubleArea);

    $centLat = $refLat + rad2deg($centroidY / $rNm);
    $centLon = $refLon + rad2deg($centroidX / ($rNm * $cosRef));

    if ($centLat >= -90 && $centLat <= 90 && $centLon >= -180 && $centLon <= 180) {
        return ['lat' => $centLat, 'lon' => $centLon];
    }

    return null;
}

/**
 * Reference point and inner radius (NM) for circle or legacy point TFRs only.
 *
 * Circle TFRs (explicit NM radius in text): first coordinate is center, parsed radius used.
 * Polygon TFRs (no radius, three or more vertices) use point-in-polygon in
 * {@see isTfrRelevantToAirport()}; this function returns null for that case.
 * Otherwise: first coordinate with {@see TFR_DEFAULT_RADIUS_NM} (legacy single-point behavior).
 *
 * For disk geometry returned here, {@see isTfrRelevantToAirport()} requires haversine distance
 * from the airport to the center to be <= the returned radius (NM), with no added
 * {@see TFR_RELEVANCE_BUFFER_NM} (that buffer is for polygon edges only).
 *
 * @param string $text NOTAM body
 * @param array<int, array{lat: float, lon: float}>|null $vertices Pre-parsed vertices to avoid a second scan of $text; null parses from $text
 * @param float|null $parsedRadiusNm When set, skips re-parsing radius from $text (must match {@see parseTfrRadiusNm()} for the same body when applicable)
 * @return array{lat: float, lon: float, radius_nm: float}|null Unusable or polygon path (null when polygon applies to PiP instead)
 */
function parseTfrGeographicRelevanceReference(string $text, ?array $vertices = null, ?float $parsedRadiusNm = null): ?array {
    $parsedRadius = $parsedRadiusNm ?? parseTfrRadiusNm($text);
    if ($vertices === null) {
        $vertices = parseTfrPolygonVertices($text);
    }
    if ($vertices === []) {
        return null;
    }

    if ($parsedRadius !== null) {
        $center = $vertices[0];

        return [
            'lat' => $center['lat'],
            'lon' => $center['lon'],
            'radius_nm' => $parsedRadius,
        ];
    }

    if (count($vertices) >= 3) {
        return null;
    }

    $center = $vertices[0];

    return [
        'lat' => $center['lat'],
        'lon' => $center['lon'],
        'radius_nm' => TFR_DEFAULT_RADIUS_NM,
    ];
}

/**
 * Parse TFR radius from NOTAM text
 * 
 * FAA NOTAMs specify TFR radii in nautical miles (NM).
 * Values outside TFR_RADIUS_MIN_NM to TFR_RADIUS_MAX_NM are rejected as parsing errors.
 * 
 * Supported formats:
 * - "5NM RADIUS" or "5 NM RADIUS"
 * - "5 NAUTICAL MILE RADIUS"
 * - "RADIUS OF 5NM"
 * - "WITHIN 5NM"
 * 
 * @param string $text NOTAM text to parse (empty string returns null)
 * @return float|null Radius in nautical miles, or null if not found/invalid
 */
function parseTfrRadiusNm(string $text): ?float {
    // Pattern: number followed by NM/NAUTICAL MILE(S) and RADIUS
    // Or RADIUS followed by number and NM
    $patterns = [
        '/(\d+(?:\.\d+)?)\s*NM\s+RADIUS/i',
        '/(\d+(?:\.\d+)?)\s*NAUTICAL\s+MILES?\s+RADIUS/i',
        '/RADIUS\s+(?:OF\s+)?(\d+(?:\.\d+)?)\s*NM/i',
        '/(\d+(?:\.\d+)?)\s*NM\s+AREA/i',
        '/WITHIN\s+(\d+(?:\.\d+)?)\s*NM/i',
    ];
    
    foreach ($patterns as $pattern) {
        if (preg_match($pattern, $text, $matches)) {
            $radiusNm = (float)$matches[1];
            // Sanity check using constants
            if ($radiusNm >= TFR_RADIUS_MIN_NM && $radiusNm <= TFR_RADIUS_MAX_NM) {
                return $radiusNm;
            }
        }
    }
    
    return null;
}

/**
 * Extract a short vertical limit summary from TFR NOTAM body text (visual cue only).
 *
 * Matches common FAA phrasing. Output uses pilot-style wording (SFC, FL, ft, AGL, MSL).
 * Not for navigation; official limits remain on the government NOTAM.
 *
 * @param string $text Full NOTAM text
 * @return string|null Null when no supported altitude pattern is found
 */
function parseTfrVerticalLimitsSummary(string $text): ?string {
    if ($text === '') {
        return null;
    }
    $u = strtoupper($text);

    $feetOk = static function (int $ft): bool {
        return $ft >= 50 && $ft <= 80000;
    };
    $flOk = static function (int $fl): bool {
        return $fl >= 10 && $fl <= 600;
    };

    // Prose: FROM THE SURFACE TO ... FEET (MEAN SEA LEVEL / MSL / AGL)
    if (preg_match(
        '/\bFROM\s+THE\s+SURFACE\s+TO\s+(?:AND\s+INCLUDING\s+)?(\d+)\s+FEET\s+'
        . '(?:MEAN\s+SEA\s+LEVEL|MSL)\b/',
        $u,
        $m
    )) {
        $ft = (int)$m[1];
        if ($feetOk($ft)) {
            return 'SFC - ' . $ft . ' ft MSL';
        }
    }
    if (preg_match(
        '/\bFROM\s+THE\s+SURFACE\s+TO\s+(?:AND\s+INCLUDING\s+)?(\d+)\s+FEET\s+'
        . '(?:ABOVE\s+GROUND\s+LEVEL|AGL)\b/',
        $u,
        $m
    )) {
        $ft = (int)$m[1];
        if ($feetOk($ft)) {
            return 'SFC - ' . $ft . ' ft AGL';
        }
    }
    if (preg_match(
        '/\bFROM\s+THE\s+SURFACE\s+TO\s+(?:AND\s+INCLUDING\s+)?(\d+)\s+FEET\b/',
        $u,
        $m
    )) {
        $ft = (int)$m[1];
        if ($feetOk($ft)) {
            return 'SFC - ' . $ft . ' ft';
        }
    }

    // SFC TO FL180 / FROM THE SURFACE TO FL 180
    if (preg_match(
        '/\b(?:FROM\s+THE\s+SURFACE|SFC)\s+TO\s+(?:AND\s+INCLUDING\s+)?FL\s*(\d{2,3})\b/',
        $u,
        $m
    )) {
        $fl = (int)$m[1];
        if ($flOk($fl)) {
            return 'SFC - FL' . $fl;
        }
    }

    if (preg_match('/\bSFC\s*-\s*FL\s*(\d{2,3})\b/', $u, $m)) {
        $fl = (int)$m[1];
        if ($flOk($fl)) {
            return 'SFC - FL' . $fl;
        }
    }

    if (preg_match('/\bFL\s*(\d{2,3})\s+(?:-|TO)\s+FL\s*(\d{2,3})\b/', $u, $m)) {
        $a = (int)$m[1];
        $b = (int)$m[2];
        if ($flOk($a) && $flOk($b)) {
            return 'FL' . $a . ' - FL' . $b;
        }
    }

    // SFC-5000FT / SFC - 5000 FEET (FAA area line; feet assumed unless AGL/MSL present nearby)
    if (preg_match('/\bSFC\s*-\s*(\d+)\s*(?:FT|FEET)\b/', $u, $m)) {
        $ft = (int)$m[1];
        if ($feetOk($ft)) {
            return 'SFC - ' . $ft . ' ft';
        }
    }

    if (preg_match('/\bSFC\s+TO\s+(\d+)\s+FEET\s+(AGL|MSL)\b/', $u, $m)) {
        $ft = (int)$m[1];
        if ($feetOk($ft)) {
            return 'SFC - ' . $ft . ' ft ' . $m[2];
        }
    }

    return null;
}

/**
 * Calculate haversine distance between two points in nautical miles
 * 
 * Uses the standard haversine formula with Earth radius constant from units.php
 * 
 * @param float $lat1 Latitude of point 1 (decimal degrees)
 * @param float $lon1 Longitude of point 1 (decimal degrees)
 * @param float $lat2 Latitude of point 2 (decimal degrees)
 * @param float $lon2 Longitude of point 2 (decimal degrees)
 * @return float Distance in nautical miles
 */
function calculateDistanceNm(float $lat1, float $lon1, float $lat2, float $lon2): float {
    // Convert to radians
    $lat1Rad = deg2rad($lat1);
    $lat2Rad = deg2rad($lat2);
    $deltaLat = deg2rad($lat2 - $lat1);
    $deltaLon = deg2rad($lon2 - $lon1);
    
    // Haversine formula
    $a = sin($deltaLat / 2) * sin($deltaLat / 2) +
         cos($lat1Rad) * cos($lat2Rad) *
         sin($deltaLon / 2) * sin($deltaLon / 2);
    $c = 2 * atan2(sqrt($a), sqrt(1 - $a));
    
    return EARTH_RADIUS_NAUTICAL_MILES * $c;
}

/**
 * Get all airport identifiers (current and historical)
 * 
 * @param array $airport Airport configuration
 * @return array Array of uppercase identifier strings
 */
function getAirportIdentifiers(array $airport): array {
    $identifiers = [];
    
    // Current identifiers
    if (isset($airport['icao'])) {
        $identifiers[] = strtoupper($airport['icao']);
    }
    if (isset($airport['iata'])) {
        $identifiers[] = strtoupper($airport['iata']);
    }
    if (isset($airport['faa'])) {
        $identifiers[] = strtoupper($airport['faa']);
    }
    
    // Historical identifiers
    if (isset($airport['formerly']) && is_array($airport['formerly'])) {
        foreach ($airport['formerly'] as $former) {
            $identifiers[] = strtoupper($former);
        }
    }
    
    return array_unique($identifiers);
}

/**
 * Check if needle exists in haystack as a whole word (word boundary match)
 * 
 * Prevents false positives like "FIELD" matching "SPRINGFIELD".
 * Uses word boundary regex to ensure the match is not part of a larger word.
 * 
 * @param string $haystack Text to search in (should be uppercase)
 * @param string $needle Word to find (should be uppercase)
 * @return bool True if needle found as a whole word
 */
function isWordMatch(string $haystack, string $needle): bool {
    if (empty($needle) || empty($haystack)) {
        return false;
    }
    // Use word boundary \b to match whole words only
    $pattern = '/\b' . preg_quote($needle, '/') . '\b/';
    return preg_match($pattern, $haystack) === 1;
}

/**
 * Check if NOTAM is a cancellation (type C)
 * 
 * NOTAM types:
 * - N = New
 * - R = Replace
 * - C = Cancel (cancels a previous NOTAM)
 * 
 * Cancel NOTAMs indicate a restriction has been lifted and should not be
 * displayed as warnings (they're "good news" - e.g., "runway closure CANCELED").
 * 
 * @param array $notam Parsed NOTAM data with 'type' and 'text' fields
 * @return bool True if this is a cancellation NOTAM
 */
function isNotamCancellation(array $notam): bool {
    // Check type field from parser (N=New, R=Replace, C=Cancel)
    $type = strtoupper($notam['type'] ?? '');
    if ($type === 'C') {
        return true;
    }
    
    // Also check for NOTAMC in text (backup detection)
    $text = strtoupper($notam['text'] ?? '');
    if (strpos($text, 'NOTAMC') !== false) {
        return true;
    }
    
    // Check for "CANCELED" or "CANCELLED" at end of text (indicates cancellation)
    if (preg_match('/\bCANCEL+ED\s*$/', $text)) {
        return true;
    }
    
    return false;
}

/**
 * Whether NOTAM prose indicates a runway or aerodrome closure when Q-code is absent.
 *
 * @param string $text NOTAM body (any case)
 * @return bool True for RWY/AD AP/ARPT closure phrases; false for taxiway-only or hazard-only text
 */
function notamTextIndicatesRunwayOrAerodromeClosure(string $text): bool {
    $upper = strtoupper($text);
    if ($upper === '') {
        return false;
    }

    if (!str_contains($upper, 'CLSD') && !str_contains($upper, 'CLOSED')) {
        return false;
    }

    if (preg_match('/\bRWY\b.*\b(CLSD|CLOSED)\b/', $upper) === 1) {
        return true;
    }
    if (preg_match('/\bAD\s+AP\b.*\b(CLSD|CLOSED)\b/', $upper) === 1) {
        return true;
    }
    if (preg_match('/\b(ARPT|AIRPORT)\b.*\b(CLSD|CLOSED)\b/', $upper) === 1) {
        return true;
    }

    return false;
}

/**
 * Whether a parsed NOTAM is runway- or aerodrome-level (not taxiway/apron).
 *
 * Accepts QMR/QFA Q-codes, FAA scenario 86 / AIXM runway members, or conservative text fallback.
 *
 * @param array<string, mixed> $notam Parsed NOTAM row
 * @return bool True when scope is runway or aerodrome
 */
function notamRestrictionScopeIsRunwayOrAerodrome(array $notam): bool {
    $code = strtoupper((string) ($notam['code'] ?? ''));
    if (str_starts_with($code, 'QMX') || str_starts_with($code, 'QMA') || str_starts_with($code, 'QMP')) {
        return false;
    }
    if (str_starts_with($code, 'QMR') || str_starts_with($code, 'QFA')) {
        return true;
    }

    $scenario = (string) ($notam['scenario'] ?? '');
    if ($scenario === NOTAM_FAA_SCENARIO_RUNWAY_CLOSURE) {
        return true;
    }
    if (!empty($notam['aixm_runway_event'])) {
        return true;
    }

    if ($code === '') {
        return notamTextIndicatesRunwayOrAerodromeClosure((string) ($notam['text'] ?? ''));
    }

    return false;
}

/**
 * Check if NOTAM is a runway or aerodrome closure.
 *
 * Only matches runway-level and above closures (not taxiway/apron, not hazard-only text):
 * - QMR* = Runway closed
 * - QFA* = Aerodrome/airport closed
 * - FAA scenario 86 / AIXM runway events (DOM format without Q-code)
 * - Text fallback when Q-code is empty (RWY/AD AP/ARPT CLSD/CLOSED phrases)
 *
 * Excludes:
 * - QMX* = Taxiway closures (less critical)
 * - QMA* = Apron/ramp closures (less critical)
 * - QMP* = Parking area closures (less critical)
 * - Type C (Cancel) NOTAMs (restriction lifted, not a warning)
 *
 * @param array<string, mixed> $notam Parsed NOTAM data
 * @param array<string, mixed> $airport Airport configuration
 * @return bool True if runway/aerodrome closure (and not a cancellation)
 */
function isAerodromeClosure(array $notam, array $airport): bool {
    if (isNotamCancellation($notam)) {
        return false;
    }

    if (!notamRestrictionScopeIsRunwayOrAerodrome($notam)) {
        return false;
    }

    $text = strtoupper((string) ($notam['text'] ?? ''));
    if (!str_contains($text, 'CLSD') && !str_contains($text, 'CLOSED')) {
        return false;
    }

    // Location match (current or historical identifiers)
    $identifiers = getAirportIdentifiers($airport);
    $notamLocation = strtoupper($notam['location'] ?? '');
    
    if (!in_array($notamLocation, $identifiers)) {
        // Also check airport name matching for geospatial queries (word boundary match)
        $airportName = strtoupper($airport['name'] ?? '');
        $notamAirportName = strtoupper($notam['airport_name'] ?? '');
        
        if (empty($airportName) || empty($notamAirportName)) {
            return false;
        }
        // Use word boundary matching to avoid "FIELD" matching "SPRINGFIELD"
        if (!isWordMatch($notamAirportName, $airportName) && !isWordMatch($airportName, $notamAirportName)) {
            return false;
        }
    }
    
    return true;
}

/**
 * Check if NOTAM is a TFR (Temporary Flight Restriction)
 * 
 * Excludes cancellation NOTAMs (type C) since they indicate the TFR is lifted.
 * 
 * @param array $notam Parsed NOTAM data with 'text' and 'type' fields
 * @return bool True if TFR indicators found and not a cancellation
 */
function isTfr(array $notam): bool {
    // Exclude cancellation NOTAMs - they indicate restriction is lifted
    if (isNotamCancellation($notam)) {
        return false;
    }
    
    return notamTextMayIndicateTfr((string) ($notam['text'] ?? ''));
}

/**
 * Check if TFR is relevant to airport based on geographic proximity
 * 
 * A TFR is relevant if any of these conditions are met:
 * 1. The NOTAM location field matches an airport identifier
 * 2. The NOTAM airport_name field matches the airport name (word boundary match)
 * 3. The TFR text explicitly mentions the airport's name or identifier
 * 4. The airport is inside a closed polygon TFR (explicit ring closure or POINT OF
 *    ORIGIN), or within {@see TFR_RELEVANCE_BUFFER_NM} of its boundary; otherwise parsed
 *    circle radius or legacy default-radius disk uses haversine distance with no extra
 *    buffer beyond the applicable NM radius (parsed circle or {@see TFR_DEFAULT_RADIUS_NM}
 *    for legacy when no radius is stated)
 * 
 * All distance calculations use nautical miles (standard aviation unit).
 * Conservative approach: excludes TFR when coordinates cannot be parsed.
 * 
 * @param array $tfr Parsed TFR NOTAM data with 'text', 'location', 'airport_name' fields
 * @param array $airport Airport config with 'name', 'lat', 'lon', and identifier fields
 * @return bool True if TFR is relevant to this airport
 */
function isTfrRelevantToAirport(array $tfr, array $airport): bool {
    $text = $tfr['text'] ?? '';
    $textUpper = strtoupper($text);
    $airportName = strtoupper($airport['name'] ?? '');
    $identifiers = getAirportIdentifiers($airport);
    
    // Check if NOTAM location field matches an airport identifier
    $notamLocation = strtoupper($tfr['location'] ?? '');
    if (!empty($notamLocation) && in_array($notamLocation, $identifiers)) {
        return true;
    }
    
    // Check if NOTAM airport_name matches (word boundary to avoid "FIELD" matching "SPRINGFIELD")
    $notamAirportName = strtoupper($tfr['airport_name'] ?? '');
    if (!empty($notamAirportName) && !empty($airportName)) {
        if (isWordMatch($notamAirportName, $airportName) || isWordMatch($airportName, $notamAirportName)) {
            return true;
        }
    }
    
    // Check if TFR text mentions airport name (word boundary match)
    if (!empty($airportName) && isWordMatch($textUpper, $airportName)) {
        return true;
    }
    
    // Check if TFR text mentions any airport identifier (word boundary match)
    foreach ($identifiers as $identifier) {
        if (!empty($identifier) && isWordMatch($textUpper, $identifier)) {
            return true;
        }
    }
    
    // Geographic relevance: polygon uses PiP + boundary buffer; circle/legacy use great-circle
    // distance to center with no buffer beyond the stated (or default) radius NM
    if (!isset($airport['lat']) || !isset($airport['lon'])) {
        // No airport coordinates - be conservative and exclude
        return false;
    }
    
    $airportLat = (float)$airport['lat'];
    $airportLon = (float)$airport['lon'];

    $parsedRadius = parseTfrRadiusNm($text);
    $polygonMeta = parseTfrPolygonVerticesMeta($text);
    $vertices = $polygonMeta['vertices'];

    if ($vertices === []) {
        return false;
    }

    // Polygon TFR (no NM radius in text): require watertight ring, then point-in-polygon
    if ($parsedRadius === null && count($vertices) >= 3) {
        if (!$polygonMeta['ring_closed']) {
            return false;
        }

        return isAirportInsideOrNearTfrPolygonRing($vertices, $airportLat, $airportLon);
    }

    $geoRef = parseTfrGeographicRelevanceReference($text, $vertices, $parsedRadius);
    if ($geoRef === null) {
        return false;
    }

    $distanceNm = calculateDistanceNm(
        $airportLat,
        $airportLon,
        $geoRef['lat'],
        $geoRef['lon']
    );

    return $distanceNm <= $geoRef['radius_nm'];
}

/**
 * Classify NOTAM display status using effective time windows when available.
 *
 * Uses {@see notamEnsureEffectiveSegments()} so older cache rows still classify correctly.
 * When {@see parseFaaEffectiveUtcSegmentsFromText()} finds slices, "active" means inside a slice,
 * not merely inside the wider GML envelope used by NMS for some TFRs.
 *
 * @param array<string, mixed> $notam Parsed NOTAM row (mutated: segments ensured via {@see notamEnsureEffectiveSegments()})
 * @param string $timezone IANA timezone for "today" boundaries
 * @param int $nowUnix Current Unix timestamp (injectable for tests)
 * @return string active|inactive_scheduled|upcoming_today|upcoming_future|expired|unknown
 */
function classifyNotamDisplayStatusAt(array &$notam, string $timezone, int $nowUnix): string {
    notamEnsureEffectiveSegments($notam);
    $segments = $notam['effective_segments'] ?? [];
    if ($segments !== []) {
        return classifyNotamStatusFromSegmentsAt($notam, $timezone, $nowUnix, $segments);
    }
    return classifyNotamEnvelopeOnlyStatusAt($notam, $timezone, $nowUnix);
}

/**
 * Segment-based classification (FAA EFFECTIVE windows or envelope-as-single-segment).
 *
 * @param array<string, mixed> $notam Parsed NOTAM (used only if segment timestamps are invalid)
 * @param string $timezone IANA timezone
 * @param int $nowUnix Current time
 * @param array<int, array{start_time_utc: string, end_time_utc: string}> $segments Effective windows
 * @return string Status code
 */
function classifyNotamStatusFromSegmentsAt(
    array $notam,
    string $timezone,
    int $nowUnix,
    array $segments
): string {
    $firstStart = PHP_INT_MAX;
    $lastEnd = 0;
    foreach ($segments as $seg) {
        $s = strtotime($seg['start_time_utc'] ?? '');
        $e = strtotime($seg['end_time_utc'] ?? '');
        if ($s === false || $e === false || $s <= 0 || $e <= 0) {
            continue;
        }
        $firstStart = min($firstStart, $s);
        $lastEnd = max($lastEnd, $e);
    }
    if ($firstStart === PHP_INT_MAX || $lastEnd <= 0) {
        return classifyNotamEnvelopeOnlyStatusAt($notam, $timezone, $nowUnix);
    }
    if ($nowUnix > $lastEnd) {
        return 'expired';
    }
    foreach ($segments as $seg) {
        $s = strtotime($seg['start_time_utc'] ?? '');
        $e = strtotime($seg['end_time_utc'] ?? '');
        if ($s === false || $e === false || $s <= 0 || $e <= 0) {
            continue;
        }
        if ($nowUnix >= $s && $nowUnix <= $e) {
            return 'active';
        }
    }
    if ($nowUnix < $firstStart) {
        return notamUpcomingBucketForStartAt($firstStart, $timezone, $nowUnix);
    }
    return 'inactive_scheduled';
}

/**
 * Envelope-only classification (legacy NOTAMs, PERM, or prose without EFFECTIVE pairs).
 *
 * @param array<string, mixed> $notam Parsed NOTAM (start_time_utc, end_time_utc)
 * @param string $timezone IANA timezone
 * @param int $nowUnix Current time
 * @return string Status code
 */
function classifyNotamEnvelopeOnlyStatusAt(array $notam, string $timezone, int $nowUnix): string {
    $startTimeRaw = !empty($notam['start_time_utc']) ? strtotime($notam['start_time_utc']) : false;
    if ($startTimeRaw === false || $startTimeRaw === 0) {
        return 'unknown';
    }
    $startTime = $startTimeRaw;
    $endTimeRaw = !empty($notam['end_time_utc']) ? strtotime($notam['end_time_utc']) : false;
    $endTime = ($endTimeRaw !== false && $endTimeRaw > 0) ? $endTimeRaw : null;
    if ($endTime !== null && $nowUnix > $endTime) {
        return 'expired';
    }
    if ($nowUnix >= $startTime) {
        if ($endTime === null) {
            return 'active';
        }
        return $nowUnix <= $endTime ? 'active' : 'expired';
    }
    return notamUpcomingBucketForStartAt($startTime, $timezone, $nowUnix);
}

/**
 * Map a future start timestamp to upcoming_today vs upcoming_future using local midnight.
 *
 * @param int $startUnix Segment or envelope start (UTC)
 * @param string $timezone IANA timezone
 * @param int $nowUnix Current time
 * @return string upcoming_today|upcoming_future
 */
function notamUpcomingBucketForStartAt(int $startUnix, string $timezone, int $nowUnix): string {
    try {
        $tz = new DateTimeZone($timezone);
        $nowDt = new DateTimeImmutable('@' . $nowUnix);
        $nowDt = $nowDt->setTimezone($tz);
        $todayStart = (clone $nowDt)->setTime(0, 0, 0)->getTimestamp();
        $todayEnd = (clone $nowDt)->setTime(23, 59, 59)->getTimestamp();
    } catch (Exception $e) {
        $todayStart = strtotime('today', $nowUnix);
        $todayEnd = strtotime('tomorrow', $nowUnix) - 1;
    }
    if ($startUnix >= $todayStart && $startUnix <= $todayEnd) {
        return 'upcoming_today';
    }
    return 'upcoming_future';
}

/**
 * Re-validate NOTAM status at serve time
 * 
 * NOTAMs may expire between cache time and serve time. This function
 * re-checks the status against current time to ensure expired NOTAMs
 * are not displayed to pilots. Uses airport local timezone for "today"
 * calculation to maintain consistency with determineNotamStatus().
 * 
 * @param array<string, mixed> $notam Cached NOTAM row: start_time_utc, optional effective_segments, optional status
 * @param string $timezone Airport timezone (e.g., 'America/Denver'), defaults to UTC
 * @return string When start_time_utc is valid: 'active', 'inactive_scheduled', 'upcoming_today', 'upcoming_future', or 'expired'. When start is missing or invalid: cached status or 'unknown'
 */
function revalidateNotamStatus(array $notam, string $timezone = 'UTC'): string {
    $now = time();
    $startTimeRaw = !empty($notam['start_time_utc']) ? strtotime($notam['start_time_utc']) : false;
    $startTime = ($startTimeRaw !== false && $startTimeRaw > 0) ? $startTimeRaw : null;
    if ($startTime === null) {
        return $notam['status'] ?? 'unknown';
    }
    return classifyNotamDisplayStatusAt($notam, $timezone, $now);
}

/**
 * Determine NOTAM status (active, inactive_scheduled, upcoming_today, upcoming_future, expired, unknown)
 *
 * Uses airport's local timezone to determine "today" for proper classification
 * of upcoming NOTAMs. Without airport context, falls back to UTC.
 * 
 * @param array<string, mixed> $notam Parsed NOTAM: start_time_utc, end_time_utc, text; optional effective_segments after enrichment
 * @param array<string, mixed>|null $airport Airport config for timezone (optional, defaults to UTC)
 * @return string One of: 'active', 'inactive_scheduled', 'upcoming_today', 'upcoming_future', 'expired', 'unknown'
 */
function determineNotamStatus(array $notam, ?array $airport = null): string {
    $timezone = $airport !== null ? getAirportTimezone($airport) : 'UTC';
    $startTimeRaw = !empty($notam['start_time_utc']) ? strtotime($notam['start_time_utc']) : false;
    if ($startTimeRaw === false || $startTimeRaw === 0) {
        return 'unknown';
    }
    return classifyNotamDisplayStatusAt($notam, $timezone, time());
}

/**
 * Filter NOTAMs for relevant closures and TFRs
 *
 * Returns only NOTAMs that are:
 * - Relevant to this airport (location match or geographic proximity)
 * - Banner-worthy status: active, inactive_scheduled (gap between EFFECTIVE windows),
 *   upcoming_today, or upcoming_future within {@see NOTAM_BANNER_UPCOMING_FUTURE_HORIZON_SECONDS}
 *   of the first restriction window
 *
 * @param array<int, array<string, mixed>> $notams Parsed NOTAM rows from {@see parseNotamXmlArray()}
 * @param array<string, mixed> $airport Airport configuration with timezone, coordinates, and identifiers
 * @return array<int, array<string, mixed>> Filtered NOTAMs with 'notam_type' and 'status' fields added
 */
function filterRelevantNotams(array $notams, array $airport): array {
    $relevant = [];
    
    foreach ($notams as $notam) {
        $isClosureNotam = isAerodromeClosure($notam, $airport);
        $isTfrNotam = isTfr($notam);
        
        if ($isClosureNotam) {
            $status = determineNotamStatus($notam, $airport);
            if (notamIsBannerRelevantStatus($status, $notam)) {
                $notam['notam_type'] = 'aerodrome_closure';
                $notam['status'] = $status;
                $relevant[] = $notam;
            }
        } elseif ($isTfrNotam) {
            if (isTfrRelevantToAirport($notam, $airport)) {
                $status = determineNotamStatus($notam, $airport);
                if (notamIsBannerRelevantStatus($status, $notam)) {
                    $notam['notam_type'] = 'tfr';
                    $notam['status'] = $status;
                    $relevant[] = $notam;
                }
            }
        }
    }
    
    return $relevant;
}

/**
 * Whether a NOTAM status should be cached for the airport banner pipeline.
 *
 * Includes near-future {@see NOTAM_BANNER_UPCOMING_FUTURE_HORIZON_SECONDS} upcoming_future rows
 * so pilots see advance warning before the first restriction window.
 *
 * @param string $status Status from {@see determineNotamStatus()}
 * @param array<string, mixed> $notam Parsed NOTAM; upcoming_future horizon uses {@see notamFirstRestrictionStartUnix()}
 * @param int|null $nowUnix Reference time for horizon checks; null uses {@see time()}
 * @return bool True when the NOTAM should be retained in cache for this airport
 */
function notamIsBannerRelevantStatus(string $status, array $notam, ?int $nowUnix = null): bool {
    $now = $nowUnix ?? time();
    if (in_array($status, ['active', 'upcoming_today', 'inactive_scheduled'], true)) {
        return true;
    }
    if ($status !== 'upcoming_future') {
        return false;
    }
    $firstStart = notamFirstRestrictionStartUnix($notam);
    if ($firstStart === null || $firstStart <= $now) {
        return false;
    }
    return ($firstStart - $now) <= NOTAM_BANNER_UPCOMING_FUTURE_HORIZON_SECONDS;
}
