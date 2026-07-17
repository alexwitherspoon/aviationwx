<?php

/**
 * Runway Geometry Loading and Processing
 *
 * Loads runway data from FAA (US) and OurAirports (worldwide), preferring FAA when both exist.
 * Transforms lat/lon endpoints to normalized segments for wind visualization.
 * Active runways only; combines both sources in APCu cache.
 */

require_once __DIR__ . '/airport-ourairports.php';
require_once __DIR__ . '/constants.php';
require_once __DIR__ . '/cache-paths.php';
require_once __DIR__ . '/heading-conversion.php';

/** APCu key prefix for runway segments per airport */
const RUNWAYS_APCU_PREFIX = 'aviationwx_runways_';

/** APCu key for runways cache file mtime (invalidates per-airport cache when file updates) */
const RUNWAYS_APCU_MTIME_KEY = 'aviationwx_runways_file_mtime';

/**
 * Check if runway segments are in true north (geographic) vs magnetic north
 *
 * Lat/lon runways (manual or FAA/OurAirports) use geographic bearing = true north.
 * Heading-based runways (heading_1/heading_2) use runway numbers = magnetic north.
 * Declination is only applied to convert true → magnetic for display.
 *
 * @param array $airport Airport config with runways (optional)
 * @return bool True if segments are in true north (need declination conversion)
 */
function runwaysSegmentsAreInTrueNorth(array $airport): bool {
    if (empty($airport['runways']) || !is_array($airport['runways'])) {
        return true; // Programmatic/FAA/OurAirports from lat/lon = true north
    }
    return isManualRunwaysLatLonFormat($airport['runways']);
}

/**
 * Rotate runway segments from true north to magnetic north for display
 *
 * @param array $segments Segments with start/end [x,y]
 * @param float $declination Declination (positive=East)
 * @return array Rotated segments
 */
function rotateRunwaySegmentsToMagnetic(array $segments, float $declination): array {
    if ($declination === 0.0) {
        return $segments;
    }
    $rotated = [];
    foreach ($segments as $seg) {
        $s = $seg['start'] ?? [0, 0];
        $e = $seg['end'] ?? [0, 0];
        $rs = rotatePointTrueToMagnetic((float) $s[0], (float) $s[1], $declination);
        $re = rotatePointTrueToMagnetic((float) $e[0], (float) $e[1], $declination);
        $rotated[] = array_merge($seg, [
            'start' => [$rs['x'], $rs['y']],
            'end' => [$re['x'], $re['y']],
        ]);
    }
    return $rotated;
}

/**
 * Get runway segments for an airport (unified format for frontend)
 *
 * Returns array of segments: [{ start: [x,y], end: [x,y], le_ident, he_ident, source }, ...]
 * Coordinates are normalized -1..1, centered on airport, North = +y.
 * Segments in true north are rotated to magnetic for display (compass alignment).
 *
 * @param string $airportId Airport identifier (e.g. kspb, cyav)
 * @param array $airport Airport config with lat, lon, runways (optional manual override)
 * @return array<string, mixed>|null Segments array or null if none available
 */
function getRunwaySegmentsForAirport(string $airportId, array $airport): ?array {
    $airportId = strtolower($airportId);

    // Manual override: use config runways, convert to segments
    if (!empty($airport['runways']) && is_array($airport['runways'])) {
        if (isManualRunwaysLatLonFormat($airport['runways'])) {
            $segments = manualRunwaysLatLonToSegments($airport['runways'], $airport);
        } else {
            $segments = manualRunwaysToSegments($airport['runways']);
        }
        return rotateSegmentsIfTrueNorth($segments, $airport);
    }

    // Programmatic: check APCu, then file cache (invalidate APCu when file is newer)
    $apcuKey = RUNWAYS_APCU_PREFIX . $airportId;
    $cachePath = CACHE_RUNWAYS_DATA_FILE;
    $fileMtime = file_exists($cachePath) ? filemtime($cachePath) : 0;

    if (function_exists('apcu_fetch')) {
        $storedMtime = @apcu_fetch(RUNWAYS_APCU_MTIME_KEY, $mtimeSuccess);
        if ($mtimeSuccess && $fileMtime > 0 && (int) $storedMtime === (int) $fileMtime) {
            $cached = @apcu_fetch($apcuKey, $success);
            if ($success && $cached !== false && is_array($cached)) {
                return rotateSegmentsIfTrueNorth($cached, $airport);
            }
        }
    }

    $segments = loadRunwaySegmentsFromFileCache($airportId, $airport);
    if ($segments !== null && function_exists('apcu_store')) {
        @apcu_store($apcuKey, $segments, RUNWAYS_APCU_TTL);
        if ($fileMtime > 0) {
            @apcu_store(RUNWAYS_APCU_MTIME_KEY, $fileMtime, RUNWAYS_APCU_TTL);
        }
        return rotateSegmentsIfTrueNorth($segments, $airport);
    }

    return null;
}

/**
 * Rotate segments to magnetic if they are in true north
 *
 * @param array $segments Segments array
 * @param array $airport Airport config for declination
 * @return array Segments (rotated when in true north)
 */
function rotateSegmentsIfTrueNorth(array $segments, array $airport): array {
    if (!runwaysSegmentsAreInTrueNorth($airport)) {
        return $segments;
    }
    require_once __DIR__ . '/config.php';
    $declination = getMagneticDeclination($airport);
    return rotateRunwaySegmentsToMagnetic($segments, $declination);
}

/**
 * Detect if runways use lat/lon schema (ident-keyed endpoints) vs heading schema
 *
 * @param array $runways Runways array
 * @return bool True if lat/lon format
 */
function isManualRunwaysLatLonFormat(array $runways): bool {
    $first = $runways[0] ?? [];
    if (isset($first['heading_1'], $first['heading_2'])) {
        return false;
    }
    foreach ($first as $key => $val) {
        if ($key === 'name') {
            continue;
        }
        if (is_array($val) && isset($val['lat'], $val['lon'])) {
            return true;
        }
    }
    return false;
}

/**
 * Extract magnetic heading (degrees) from runway ident
 *
 * Runway 35 → 350°, 28L → 280°. Strips L/C/R suffix.
 *
 * @param string $ident Runway end ident (e.g. "35", "28L", "10R")
 * @return int Heading 1-360
 */
function parseIdentHeading(string $ident): int {
    $num = (int) preg_replace('/[LCR]$/i', '', trim($ident));
    if ($num < 1 || $num > 36) {
        return 360;
    }
    return (int) ($num * 10);
}

/**
 * Compute bearing from center to point (degrees 0-360, North=0)
 *
 * @param array $center { lat, lon }
 * @param array $point { lat, lon }
 * @return float Bearing in degrees
 */
function computeBearing(array $center, array $point): float {
    $lat1 = deg2rad((float) ($center['lat'] ?? 0));
    $lon1 = deg2rad((float) ($center['lon'] ?? 0));
    $lat2 = deg2rad((float) ($point['lat'] ?? 0));
    $lon2 = deg2rad((float) ($point['lon'] ?? 0));
    $dLon = $lon2 - $lon1;
    $y = sin($dLon) * cos($lat2);
    $x = cos($lat1) * sin($lat2) - sin($lat1) * cos($lat2) * cos($dLon);
    $bearing = rad2deg(atan2($y, $x));
    $normalized = fmod($bearing + 360, 360);
    return $normalized < 0 ? $normalized + 360 : $normalized;
}

/**
 * Convert manual runway config (lat/lon schema) to segments
 *
 * Uses bearing-based label assignment: each ident placed on the endpoint
 * whose bearing from airport center is closest to that ident's heading.
 * Ensures 35 is always on north end, 17 on south end regardless of schema order.
 *
 * @param array $runways Array of { name, "ident": { lat, lon }, ... }
 * @param array $airport { lat, lon } airport center
 * @return array Array of segments
 */
function manualRunwaysLatLonToSegments(array $runways, array $airport): array {
    $center = [
        'lat' => (float) ($airport['lat'] ?? 0),
        'lon' => (float) ($airport['lon'] ?? 0),
    ];
    $segments = [];
    foreach ($runways as $rw) {
        $endpoints = [];
        foreach ($rw as $key => $val) {
            if ($key === 'name' || !is_array($val)) {
                continue;
            }
            $lat = $val['lat'] ?? null;
            $lon = $val['lon'] ?? null;
            if ($lat !== null && $lon !== null) {
                $endpoints[$key] = ['lat' => (float) $lat, 'lon' => (float) $lon];
            }
        }
        if (count($endpoints) !== 2) {
            continue;
        }
        $idents = array_keys($endpoints);
        $points = array_values($endpoints);
        $bearing0 = computeBearing($center, $points[0]);
        $bearing1 = computeBearing($center, $points[1]);
        $heading0 = parseIdentHeading($idents[0]);
        $heading1 = parseIdentHeading($idents[1]);
        $diff0 = min(abs($bearing0 - $heading0), 360 - abs($bearing0 - $heading0));
        $diff1 = min(abs($bearing0 - $heading1), 360 - abs($bearing0 - $heading1));
        if ($diff0 < $diff1) {
            $identAt0 = $idents[0];
            $identAt1 = $idents[1];
        } else {
            $identAt0 = $idents[1];
            $identAt1 = $idents[0];
        }
        $p0 = $identAt0 === $idents[0] ? $points[0] : $points[1];
        $p1 = $identAt0 === $idents[0] ? $points[1] : $points[0];
        $seg = normalizeLatLonToSegment($center, $p0, $p1, $identAt0, $identAt1);
        $segments[] = $seg;
    }
    return $segments;
}

/**
 * Normalize lat/lon endpoints to -1..1 segment
 *
 * @param array $center Airport center
 * @param array $p0 First endpoint
 * @param array $p1 Second endpoint
 * @param string $ident0 Ident at p0
 * @param string $ident1 Ident at p1
 * @return array Segment { start, end, le_ident, he_ident, source }
 */
function normalizeLatLonToSegment(array $center, array $p0, array $p1, string $ident0, string $ident1): array {
    $latC = (float) ($center['lat'] ?? 0);
    $lonC = (float) ($center['lon'] ?? 0);
    $x0 = (float) ($p0['lon'] ?? 0) - $lonC;
    $y0 = (float) ($p0['lat'] ?? 0) - $latC;
    $x1 = (float) ($p1['lon'] ?? 0) - $lonC;
    $y1 = (float) ($p1['lat'] ?? 0) - $latC;
    // Use Euclidean distance so diagonal runways get same buffer as N-S/E-W
    $d0 = sqrt($x0 * $x0 + $y0 * $y0);
    $d1 = sqrt($x1 * $x1 + $y1 * $y1);
    $max = max($d0, $d1, 0.001);
    $scale = 0.9 / $max;
    return [
        'start' => [$x0 * $scale, $y0 * $scale],
        'end' => [$x1 * $scale, $y1 * $scale],
        'le_ident' => $ident0,
        'he_ident' => $ident1,
        'source' => 'manual',
    ];
}

/**
 * Convert manual runway config (heading-based) to segment format
 *
 * @param array $runways Array of { name, heading_1, heading_2 }
 * @return array Array of segments (lines through center)
 */
function manualRunwaysToSegments(array $runways): array {
    $segments = [];
    foreach ($runways as $rw) {
        $h1 = $rw['heading_1'] ?? null;
        $h2 = $rw['heading_2'] ?? null;
        $name = $rw['name'] ?? '';

        if ($h1 === null || $h2 === null) {
            continue;
        }

        $h1 = (int) $h1;
        $h2 = (int) $h2;

        $parts = explode('/', $name);
        $leIdent = $parts[0] ?? '';
        $heIdent = $parts[1] ?? '';

        $angle = ($h1 * M_PI) / 180;
        $length = 0.9;
        // Aviation: 0°=N (+y), 90°=E (+x). start = heading_1 end (e.g. 80°=east), end = heading_2 end (260°=west).
        // Runway numbers are at approach end: 8 at west (end), 26 at east (start).
        $segments[] = [
            'start' => [sin($angle) * $length, cos($angle) * $length],
            'end' => [-sin($angle) * $length, -cos($angle) * $length],
            'le_ident' => trim($leIdent),
            'he_ident' => trim($heIdent),
            'ident_at_start' => trim($heIdent),
            'ident_at_end' => trim($leIdent),
            'source' => 'manual',
        ];
    }
    return $segments;
}

/**
 * Extract runway segments for an airport from parsed cache data
 *
 * @param array $data Parsed runways cache (must have 'airports' key)
 * @param string $airportId Airport identifier
 * @param array $airport Airport config with icao, faa (optional)
 * @return array|null Segments or null if not found
 */
function getRunwaySegmentsFromParsedCache(array $data, string $airportId, array $airport): ?array {
    if (!isset($data['airports']) || !is_array($data['airports'])) {
        return null;
    }
    $airports = $data['airports'];
    $identsToTry = array_filter([
        strtoupper($airportId),
        isset($airport['icao']) ? strtoupper((string) $airport['icao']) : null,
        isset($airport['faa']) ? strtoupper((string) $airport['faa']) : null,
    ]);
    foreach ($identsToTry as $ident) {
        if (isset($airports[$ident]) && !empty($airports[$ident]['segments'])) {
            return $airports[$ident]['segments'];
        }
    }
    return null;
}

/**
 * Load parsed runway cache JSON from disk (live cache or test fixture).
 *
 * @return array<string, mixed>|null
 */
function loadRunwaysCacheDataFromDisk(): ?array
{
    static $memoized = null;
    static $resolved = false;

    if ($resolved) {
        return $memoized;
    }

    $resolved = true;
    $cachePath = CACHE_RUNWAYS_DATA_FILE;
    $fixturePath = dirname(__DIR__) . '/tests/Fixtures/runways_data.json';

    if ((getenv('APP_ENV') === 'testing' || (defined('APP_ENV') && APP_ENV === 'testing'))
        && file_exists($fixturePath)) {
        $cachePath = $fixturePath;
    } elseif (!file_exists($cachePath)) {
        return null;
    }

    $content = @file_get_contents($cachePath);
    if ($content === false) {
        return null;
    }

    $data = json_decode($content, true);
    if (!is_array($data) || !isset($data['airports'])) {
        return null;
    }

    $memoized = $data;

    return $memoized;
}

/**
 * Load runway segments from file cache for an airport
 *
 * @param string $airportId Airport identifier
 * @param array $airport Airport config with lat, lon
 * @return array|null Segments or null if not found
 */
function loadRunwaySegmentsFromFileCache(string $airportId, array $airport): ?array {
    $data = loadRunwaysCacheDataFromDisk();
    if ($data === null) {
        return null;
    }

    return getRunwaySegmentsFromParsedCache($data, $airportId, $airport);
}

/**
 * Warm APCu cache for configured airports
 *
 * Loads the runway cache file once and parses once to avoid memory exhaustion
 * when many airports are configured.
 *
 * @param array $airports Config airports array
 * @return int Number of airports warmed
 */
function warmRunwaysApcuCache(array $airports): int {
    $data = loadRunwaysCacheDataFromDisk();
    if ($data === null) {
        return 0;
    }

    $warmed = 0;
    foreach ($airports as $airportId => $airport) {
        if (!is_array($airport)) {
            continue;
        }
        if (!empty($airport['runways'])) {
            continue;
        }
        $segments = getRunwaySegmentsFromParsedCache($data, strtolower($airportId), $airport);
        if ($segments !== null && function_exists('apcu_store')) {
            $key = RUNWAYS_APCU_PREFIX . strtolower($airportId);
            @apcu_store($key, $segments, RUNWAYS_APCU_TTL);
            $warmed++;
        }
    }
    return $warmed;
}

/**
 * Check if runway cache needs refresh (missing or stale)
 *
 * @return bool True if fetch should run
 */
function runwaysCacheNeedsRefresh(): bool {
    $path = CACHE_RUNWAYS_DATA_FILE;
    if (!file_exists($path)) {
        return true;
    }
    $age = time() - filemtime($path);
    return $age >= RUNWAYS_CACHE_MAX_AGE;
}

/**
 * Whether an OurAirports surface code represents water (excluded from DA runway selection).
 */
function ourAirportsIsWaterSurface(string $surface): bool
{
    return str_contains(strtoupper(trim($surface)), 'WATER');
}

/**
 * Whether an OurAirports surface code is non-paved for POH grass correction.
 */
function ourAirportsIsNonPavedSurface(string $surface): bool
{
    $surface = strtoupper(trim($surface));
    if ($surface === '') {
        return false;
    }

    foreach (['TURF', 'GRS', 'GRE', 'GVL', 'GRASS', 'GRAVEL', 'GRVL', 'DIRT', 'SOD', 'CLAY', 'EARTH'] as $code) {
        if (str_contains($surface, $code)) {
            return true;
        }
    }

    return false;
}

/**
 * Normalize OurAirports surface text to NASR-style codes for POH lookup.
 */
function ourAirportsNormalizeSurfaceCode(string $surface): string
{
    $surface = strtoupper(trim($surface));
    if ($surface === '' || ourAirportsIsWaterSurface($surface)) {
        return $surface;
    }
    if (ourAirportsIsNonPavedSurface($surface)) {
        return 'TURF';
    }

    return 'ASPH';
}

/**
 * Build a runway id from OurAirports low/high end idents.
 */
function ourAirportsRunwayIdFromIdents(string $leIdent, string $heIdent): string
{
    $leIdent = trim($leIdent);
    $heIdent = trim($heIdent);
    if ($leIdent !== '' && $heIdent !== '') {
        return $leIdent . '/' . $heIdent;
    }
    if ($leIdent !== '') {
        return $leIdent;
    }
    if ($heIdent !== '') {
        return $heIdent;
    }

    return 'unknown';
}

/**
 * Whether an OurAirports runway row is eligible for DA performance scoring.
 *
 * Closed rows are omitted when `runways.csv` is ingested; water surfaces excluded here.
 *
 * @param array $runway Parsed runway with length_ft and surface
 */
function ourAirportsRunwayIsSelectable(array $runway): bool
{
    $lengthFt = (int) ($runway['length_ft'] ?? 0);
    if ($lengthFt <= 0) {
        return false;
    }

    $surface = (string) ($runway['surface'] ?? '');
    if ($surface !== '' && ourAirportsIsWaterSurface($surface)) {
        return false;
    }

    return true;
}

/**
 * Resolve OurAirports parsed runway rows for a cache airport ident.
 *
 * Tries the ident directly, then a mapped ICAO when FAA LID aliases are known.
 *
 * @param array<string, list<array>> $ourairports Runways keyed by airport ident
 * @param array<string, string> $faaToIcao FAA LID to ICAO mapping
 * @return list<array>|null Parsed OurAirports rows or null when unavailable
 */
function resolveOurAirportsRunwaysForCacheIdent(string $ident, array $ourairports, array $faaToIcao = []): ?array
{
    $candidates = [strtoupper($ident)];
    $icao = $faaToIcao[$candidates[0]] ?? null;
    if (is_string($icao) && $icao !== '' && strtoupper($icao) !== $candidates[0]) {
        $candidates[] = strtoupper($icao);
    }

    foreach ($candidates as $candidate) {
        if (isset($ourairports[$candidate]) && !empty($ourairports[$candidate])) {
            return $ourairports[$candidate];
        }
    }

    return null;
}

/**
 * Build NASR-shaped performance runway records from OurAirports parse rows.
 *
 * @param list<array> $runways Parsed OurAirports runway rows
 * @return list<array>
 */
function buildOurAirportsPerformanceRunways(array $runways): array
{
    $performance = [];
    foreach ($runways as $runway) {
        if (!is_array($runway) || !ourAirportsRunwayIsSelectable($runway)) {
            continue;
        }

        $performance[] = [
            'rwy_id' => ourAirportsRunwayIdFromIdents(
                (string) ($runway['le_ident'] ?? ''),
                (string) ($runway['he_ident'] ?? '')
            ),
            'length_ft' => (int) $runway['length_ft'],
            'surface' => ourAirportsNormalizeSurfaceCode((string) ($runway['surface'] ?? '')),
            'ends' => [],
            'le_displaced_threshold_ft' => isset($runway['le_displaced_threshold_ft'])
                ? (int) $runway['le_displaced_threshold_ft']
                : 0,
            'he_displaced_threshold_ft' => isset($runway['he_displaced_threshold_ft'])
                ? (int) $runway['he_displaced_threshold_ft']
                : 0,
        ];
    }

    return $performance;
}

/**
 * Select the longest selectable OurAirports performance runway.
 *
 * @param list<array> $runways Performance runway records
 * @return array|null Selected runway or null when none qualify
 */
function ourAirportsSelectLongestActiveLandRunway(array $runways): ?array
{
    $selected = ourAirportsSelectActiveLandRunwaysForPerformance($runways);
    if ($selected === []) {
        return null;
    }

    return $selected[0];
}

/**
 * Active OurAirports performance runways for DA scoring (longest first).
 *
 * @param list<array> $runways Performance runway records
 * @return list<array> Runways sorted by length descending
 */
function ourAirportsSelectActiveLandRunwaysForPerformance(array $runways): array
{
    $selectable = [];
    foreach ($runways as $runway) {
        if (!is_array($runway) || !ourAirportsRunwayIsSelectable($runway)) {
            continue;
        }
        $selectable[] = $runway;
    }

    if ($selectable === []) {
        return [];
    }

    usort(
        $selectable,
        static fn (array $a, array $b): int => (int) ($b['length_ft'] ?? 0) <=> (int) ($a['length_ft'] ?? 0)
    );

    return $selectable;
}

/**
 * Extract performance runways for an airport from parsed runway cache data.
 *
 * @param array $data Parsed runways cache (must have 'airports' key)
 * @param string $airportId Airport identifier
 * @param array $airport Airport config with optional ourairports_ident, icao, faa
 * @return list<array>|null Performance runways or null when not found
 */
function getOurAirportsPerformanceRunwaysFromParsedCache(array $data, string $airportId, array $airport): ?array
{
    if (!isset($data['airports']) || !is_array($data['airports'])) {
        return null;
    }
    $airports = $data['airports'];
    $identsToTry = ourAirportsCacheLookupIdentsForAirport($airportId, $airport);
    foreach ($identsToTry as $ident) {
        if (!isset($airports[$ident]) || !is_array($airports[$ident])) {
            continue;
        }
        $runways = $airports[$ident]['performance_runways'] ?? null;
        if (is_array($runways) && $runways !== []) {
            return $runways;
        }
    }

    return null;
}

/**
 * Load OurAirports performance runways from file cache for an airport.
 *
 * @param string $airportId Airport identifier
 * @param array $airport Airport config
 * @return list<array>|null Performance runways or null when not found
 */
function loadOurAirportsPerformanceRunwaysFromFileCache(string $airportId, array $airport): ?array
{
    $data = loadRunwaysCacheDataFromDisk();
    if ($data === null) {
        return null;
    }

    return getOurAirportsPerformanceRunwaysFromParsedCache($data, $airportId, $airport);
}

/**
 * Active OurAirports performance runways for density altitude scoring.
 *
 * @param string $airportId Airport identifier (config key or ICAO)
 * @param array $airport Airport configuration
 * @return list<array> Runways with per-end displaced thresholds, longest first
 */
function getOurAirportsPerformanceRunwaysForAirport(string $airportId, array $airport): array
{
    $runways = loadOurAirportsPerformanceRunwaysFromFileCache($airportId, $airport);
    if ($runways === null) {
        return [];
    }

    $selected = ourAirportsSelectActiveLandRunwaysForPerformance($runways);
    if ($selected === []) {
        return [];
    }

    return array_map(
        static fn (array $runway): array => ourAirportsPerformanceRunwayForEvaluation($runway),
        $selected
    );
}

/**
 * Build a runway record for POH evaluation from a cached OurAirports performance row.
 *
 * @param array $selected Performance runway from cache
 * @return array Runway with ends for displaced-threshold roll limits
 */
function ourAirportsPerformanceRunwayForEvaluation(array $selected): array
{
    $rwyId = (string) ($selected['rwy_id'] ?? 'ourairports');
    $idents = explode('/', $rwyId, 2);
    $leIdent = $idents[0] ?? '';
    $heIdent = $idents[1] ?? $leIdent;
    $leDisplaced = isset($selected['le_displaced_threshold_ft'])
        ? (int) $selected['le_displaced_threshold_ft']
        : 0;
    $heDisplaced = isset($selected['he_displaced_threshold_ft'])
        ? (int) $selected['he_displaced_threshold_ft']
        : 0;

    $ends = [];
    if ($leIdent !== '') {
        $ends[] = [
            'end_id' => $leIdent,
            'displaced_thr_len' => $leDisplaced,
            'obstruction' => [],
        ];
    }
    if ($heIdent !== '' && $heIdent !== $leIdent) {
        $ends[] = [
            'end_id' => $heIdent,
            'displaced_thr_len' => $heDisplaced,
            'obstruction' => [],
        ];
    }

    return [
        'rwy_id' => $rwyId,
        'length_ft' => (int) ($selected['length_ft'] ?? 0),
        'surface' => (string) ($selected['surface'] ?? 'ASPH'),
        'ends' => $ends,
    ];
}
