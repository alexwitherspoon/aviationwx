<?php

/**
 * Runway Geometry Fetcher
 *
 * Merges FAA (US) and OurAirports runway data into normalized segments, streams a single
 * runways_data.json (chunked write, atomic rename after retention check), warms APCu.
 * Scheduler invokes in background only.
 *
 * Usage: php scripts/fetch-runways.php
 */

require_once __DIR__ . '/../lib/config.php';
require_once __DIR__ . '/../lib/logger.php';
require_once __DIR__ . '/../lib/cache-paths.php';
require_once __DIR__ . '/../lib/worker-timeout.php';
require_once __DIR__ . '/../lib/runways.php';
require_once __DIR__ . '/../lib/ourairports/ingest-airports.php';
require_once __DIR__ . '/../lib/ourairports/http.php';
require_once __DIR__ . '/../lib/ourairports/refresh.php';
require_once __DIR__ . '/../lib/ourairports/urls.php';

// Worldwide merge peak exceeds default PHP-FPM/CLI limits; match NASR APT worker headroom.
// @ suppresses ini_set failure in restricted environments (e.g. disable_functions)
@ini_set('memory_limit', '1024M');

/**
 * Read a local CSV file or return null.
 */
function readLocalCsvFile(string $path): ?string
{
    if (!is_readable($path)) {
        return null;
    }

    $content = @file_get_contents($path);

    return ($content === false || $content === '') ? null : $content;
}

/**
 * Download FAA NGDA runway CSV when refresh policy requires it.
 */
function downloadFaaNgdaRunwaysIfNeeded(): ?string
{
    if (!faaNgdaRunwayCsvNeedsRefresh() && is_readable(CACHE_FAA_NGDA_RUNWAYS_CSV)) {
        return readLocalCsvFile(CACHE_FAA_NGDA_RUNWAYS_CSV);
    }

    if (faaNgdaRunwayCsvNeedsRefresh() && !faaNgdaOverdueRefreshShouldTriggerMerge() && is_readable(CACHE_FAA_NGDA_RUNWAYS_CSV)) {
        return readLocalCsvFile(CACHE_FAA_NGDA_RUNWAYS_CSV);
    }

    $response = ourAirportsHttpGet(FAA_NGDA_RUNWAYS_CSV_URL);
    if (!$response['ok'] || $response['body'] === null) {
        faaNgdaRecordFetchAttempt(false);
        return readLocalCsvFile(CACHE_FAA_NGDA_RUNWAYS_CSV);
    }

    if (!faaNgdaRunwayCsvBodyIsValid($response['body'])) {
        aviationwx_log('error', 'runways fetch: rejected invalid FAA NGDA CSV body', [
            'http_code' => $response['http_code'],
            'body_bytes' => strlen($response['body']),
        ], 'app');
        faaNgdaRecordFetchAttempt(false);
        return readLocalCsvFile(CACHE_FAA_NGDA_RUNWAYS_CSV);
    }

    ensureCacheDir(CACHE_RUNWAYS_DIR);
    $tmp = CACHE_FAA_NGDA_RUNWAYS_CSV . '.tmp.' . getmypid();
    if (@file_put_contents($tmp, $response['body'], LOCK_EX) === false || !@rename($tmp, CACHE_FAA_NGDA_RUNWAYS_CSV)) {
        @unlink($tmp);
        faaNgdaRecordFetchAttempt(false);
        return readLocalCsvFile(CACHE_FAA_NGDA_RUNWAYS_CSV);
    }

    faaNgdaRecordFetchAttempt(true);

    return $response['body'];
}

/**
 * Acquire fetch lock; return handle or false
 *
 * @return resource|false File handle or false if lock held by another process
 */
function acquireRunwaysFetchLock() {
    $lockPath = getRunwaysFetchLockPath();
    $dir = dirname($lockPath);
    if (!is_dir($dir)) {
        @mkdir($dir, 0755, true);
    }

    if (file_exists($lockPath)) {
        $age = time() - filemtime($lockPath);
        if ($age > FILE_LOCK_STALE_SECONDS) {
            @unlink($lockPath);
        }
    }

    $fp = @fopen($lockPath, 'c+');
    if (!$fp) {
        return false;
    }
    if (!@flock($fp, LOCK_EX | LOCK_NB)) {
        fclose($fp);
        return false;
    }
    return $fp;
}

/**
 * Parse OurAirports runways CSV from an open handle (streaming; no full-file string).
 *
 * @param resource $handle Readable CSV stream positioned at start
 * @return array<string, array<int, array<string, mixed>>> Runways by airport_ident
 */
function parseOurAirportsRunwaysFromHandle($handle): array
{
    $header = fgetcsv($handle, 0, ',', '"', '\\');
    if (!is_array($header) || $header === []) {
        return [];
    }
    $idx = array_flip(array_map(static fn($h) => trim((string) $h), $header));

    $ident = $idx['airport_ident'] ?? null;
    if ($ident === null) {
        return [];
    }

    $closedIdx = $idx['closed'] ?? null;
    $leLat = $idx['le_latitude_deg'] ?? null;
    $leLon = $idx['le_longitude_deg'] ?? null;
    $heLat = $idx['he_latitude_deg'] ?? null;
    $heLon = $idx['he_longitude_deg'] ?? null;
    $leIdent = $idx['le_ident'] ?? null;
    $heIdent = $idx['he_ident'] ?? null;
    $lengthIdx = $idx['length_ft'] ?? null;
    $surfaceIdx = $idx['surface'] ?? null;
    $widthIdx = $idx['width_ft'] ?? null;
    $lightedIdx = $idx['lighted'] ?? null;
    $leHeadingIdx = $idx['le_heading_degT'] ?? null;
    $heHeadingIdx = $idx['he_heading_degT'] ?? null;
    $leDisplacedIdx = $idx['le_displaced_threshold_ft'] ?? null;
    $heDisplacedIdx = $idx['he_displaced_threshold_ft'] ?? null;

    $byAirport = [];
    while (($row = fgetcsv($handle, 0, ',', '"', '\\')) !== false) {
        if (!is_array($row) || count($row) < 5) {
            continue;
        }

        $airportId = strtoupper(trim((string) ($row[$ident] ?? '')));
        if ($airportId === '') {
            continue;
        }

        $lat1 = $leLat !== null && isset($row[$leLat]) && $row[$leLat] !== '' ? (float) $row[$leLat] : null;
        $lon1 = $leLon !== null && isset($row[$leLon]) && $row[$leLon] !== '' ? (float) $row[$leLon] : null;
        $lat2 = $heLat !== null && isset($row[$heLat]) && $row[$heLat] !== '' ? (float) $row[$heLat] : null;
        $lon2 = $heLon !== null && isset($row[$heLon]) && $row[$heLon] !== '' ? (float) $row[$heLon] : null;
        if ($lat1 === null || $lon1 === null || $lat2 === null || $lon2 === null) {
            continue;
        }

        $isClosed = $closedIdx !== null && isset($row[$closedIdx]) && (int) $row[$closedIdx] === 1;
        $lengthFt = $lengthIdx !== null && isset($row[$lengthIdx]) && is_numeric($row[$lengthIdx])
            ? (int) round((float) $row[$lengthIdx])
            : 0;
        $widthFt = $widthIdx !== null && isset($row[$widthIdx]) && is_numeric($row[$widthIdx])
            ? (int) round((float) $row[$widthIdx])
            : null;

        if (!isset($byAirport[$airportId])) {
            $byAirport[$airportId] = [];
        }
        $byAirport[$airportId][] = [
            'lat1' => $lat1,
            'lon1' => $lon1,
            'lat2' => $lat2,
            'lon2' => $lon2,
            'le_ident' => $leIdent !== null ? trim((string) ($row[$leIdent] ?? '')) : '',
            'he_ident' => $heIdent !== null ? trim((string) ($row[$heIdent] ?? '')) : '',
            'length_ft' => $lengthFt,
            'width_ft' => $widthFt,
            'surface' => $surfaceIdx !== null ? trim((string) ($row[$surfaceIdx] ?? '')) : '',
            'lighted' => $lightedIdx !== null && isset($row[$lightedIdx]) && (int) $row[$lightedIdx] === 1,
            'closed' => $isClosed,
            'le_heading_degT' => $leHeadingIdx !== null && isset($row[$leHeadingIdx]) && is_numeric($row[$leHeadingIdx])
                ? (int) round((float) $row[$leHeadingIdx])
                : null,
            'he_heading_degT' => $heHeadingIdx !== null && isset($row[$heHeadingIdx]) && is_numeric($row[$heHeadingIdx])
                ? (int) round((float) $row[$heHeadingIdx])
                : null,
            'le_displaced_threshold_ft' => $leDisplacedIdx !== null && isset($row[$leDisplacedIdx]) && is_numeric($row[$leDisplacedIdx])
                ? (int) round((float) $row[$leDisplacedIdx])
                : 0,
            'he_displaced_threshold_ft' => $heDisplacedIdx !== null && isset($row[$heDisplacedIdx]) && is_numeric($row[$heDisplacedIdx])
                ? (int) round((float) $row[$heDisplacedIdx])
                : 0,
            'source' => 'ourairports',
        ];
    }

    return $byAirport;
}

/**
 * @return array<string, array<int, array<string, mixed>>>
 */
function parseOurAirportsRunwaysFromPath(string $path): array
{
    if (!is_readable($path)) {
        return [];
    }
    $handle = @fopen($path, 'rb');
    if ($handle === false) {
        return [];
    }
    try {
        return parseOurAirportsRunwaysFromHandle($handle);
    } finally {
        fclose($handle);
    }
}

/**
 * Parse OurAirports runways CSV
 *
 * @param string $csv Raw CSV content
 * @return array<string, array> Runways by airport_ident
 */
function parseOurAirportsRunways(string $csv): array
{
    $handle = fopen('php://temp', 'r+b');
    if ($handle === false) {
        return [];
    }
    fwrite($handle, $csv);
    rewind($handle);
    try {
        return parseOurAirportsRunwaysFromHandle($handle);
    } finally {
        fclose($handle);
    }
}

/**
 * Parse FAA runways CSV from an open handle (streaming; no full-file string).
 *
 * @param resource $handle Readable CSV stream positioned at start
 * @return array<string, array<int, array<string, mixed>>> Runways by ARPT_ID
 */
function parseFaaRunwaysFromHandle($handle): array
{
    $header = fgetcsv($handle, 0, ',', '"', '\\');
    if (!is_array($header) || $header === []) {
        return [];
    }
    $idx = array_flip(array_map(static fn($h) => trim((string) $h), $header));

    $arptKey = 'ARPT_ID';
    if (!isset($idx[$arptKey])) {
        return [];
    }

    $lat1Key = 'LAT1_DECIMAL';
    $lon1Key = 'LONG1_DECIMAL';
    $lat2Key = 'LAT2_DECIMAL';
    $lon2Key = 'LONG2_DECIMAL';
    $rwyKey = 'RWY_ID';

    $byAirport = [];
    while (($row = fgetcsv($handle, 0, ',', '"', '\\')) !== false) {
        if (!is_array($row) || count($row) < 5) {
            continue;
        }

        $airportId = strtoupper(trim((string) ($row[$idx[$arptKey]] ?? '')));
        if ($airportId === '') {
            continue;
        }

        $lat1 = isset($idx[$lat1Key], $row[$idx[$lat1Key]]) && $row[$idx[$lat1Key]] !== '' ? (float) $row[$idx[$lat1Key]] : null;
        $lon1 = isset($idx[$lon1Key], $row[$idx[$lon1Key]]) && $row[$idx[$lon1Key]] !== '' ? (float) $row[$idx[$lon1Key]] : null;
        $lat2 = isset($idx[$lat2Key], $row[$idx[$lat2Key]]) && $row[$idx[$lat2Key]] !== '' ? (float) $row[$idx[$lat2Key]] : null;
        $lon2 = isset($idx[$lon2Key], $row[$idx[$lon2Key]]) && $row[$idx[$lon2Key]] !== '' ? (float) $row[$idx[$lon2Key]] : null;
        if ($lat1 === null || $lon1 === null || $lat2 === null || $lon2 === null) {
            continue;
        }

        $rwyId = isset($idx[$rwyKey], $row[$idx[$rwyKey]]) ? trim((string) $row[$idx[$rwyKey]]) : '';
        $parts = explode('/', $rwyId);
        $leIdent = count($parts) === 2 ? trim($parts[0]) : $rwyId;
        $heIdent = count($parts) === 2 ? trim($parts[1]) : $rwyId;

        if (!isset($byAirport[$airportId])) {
            $byAirport[$airportId] = [];
        }
        $byAirport[$airportId][] = [
            'lat1' => $lat1,
            'lon1' => $lon1,
            'lat2' => $lat2,
            'lon2' => $lon2,
            'le_ident' => $leIdent,
            'he_ident' => $heIdent,
            'source' => 'faa',
        ];
    }

    return $byAirport;
}

/**
 * @return array<string, array<int, array<string, mixed>>>
 */
function parseFaaRunwaysFromPath(string $path): array
{
    if (!is_readable($path)) {
        return [];
    }
    $handle = @fopen($path, 'rb');
    if ($handle === false) {
        return [];
    }
    try {
        return parseFaaRunwaysFromHandle($handle);
    } finally {
        fclose($handle);
    }
}

/**
 * Parse FAA runways CSV (ArcGIS export format)
 *
 * @param string $csv Raw CSV content
 * @return array<string, array> Runways by ARPT_ID
 */
function parseFaaRunways(string $csv): array
{
    $handle = fopen('php://temp', 'r+b');
    if ($handle === false) {
        return [];
    }
    fwrite($handle, $csv);
    rewind($handle);
    try {
        return parseFaaRunwaysFromHandle($handle);
    } finally {
        fclose($handle);
    }
}

/**
 * Bearing from center (0,0) to point (x,y) in normalized coords (x=East, y=North)
 *
 * @param float $x East component
 * @param float $y North component
 * @return float Bearing in degrees 0-360
 */
function bearingFromNormalized(float $x, float $y): float {
    $rad = atan2($x, $y);
    $deg = rad2deg($rad);
    return $deg < 0 ? $deg + 360 : $deg;
}

/**
 * Transform raw runway endpoints to normalized segments
 *
 * @param array $runways Raw runways with lat1,lon1,lat2,lon2
 * @param float $centerLat Airport center latitude
 * @param float $centerLon Airport center longitude
 * @return array Segments in normalized -1..1 space
 */
function runwaysToSegments(array $runways, float $centerLat, float $centerLon): array {
    $scaleLon = 111320 * cos($centerLat * M_PI / 180) / 1000;
    $scaleLat = 110540 / 1000;

    $points = [];
    foreach ($runways as $rw) {
        $x1 = ($rw['lon1'] - $centerLon) * $scaleLon;
        $y1 = ($rw['lat1'] - $centerLat) * $scaleLat;
        $x2 = ($rw['lon2'] - $centerLon) * $scaleLon;
        $y2 = ($rw['lat2'] - $centerLat) * $scaleLat;
        $points[] = ['x' => $x1, 'y' => $y1];
        $points[] = ['x' => $x2, 'y' => $y2];
    }

    // Use Euclidean distance so diagonal runways get same buffer as N-S/E-W
    $maxExtent = 0;
    foreach ($points as $p) {
        $d = sqrt($p['x'] * $p['x'] + $p['y'] * $p['y']);
        if ($d > $maxExtent) {
            $maxExtent = $d;
        }
    }
    if ($maxExtent < 0.001) {
        $maxExtent = 1;
    }
    $scale = 0.9 / $maxExtent;

    $segments = [];
    foreach ($runways as $rw) {
        $x1 = ($rw['lon1'] - $centerLon) * $scaleLon * $scale;
        $y1 = ($rw['lat1'] - $centerLat) * $scaleLat * $scale;
        $x2 = ($rw['lon2'] - $centerLon) * $scaleLon * $scale;
        $y2 = ($rw['lat2'] - $centerLat) * $scaleLat * $scale;

        $leIdent = $rw['le_ident'] ?? '';
        $heIdent = $rw['he_ident'] ?? '';
        // Assign idents by bearing: runway numbers are at approach end (opposite of bearing).
        // Point at 80° (east) gets 26; point at 260° (west) gets 8.
        $bearing1 = bearingFromNormalized($x1, $y1);
        $bearing2 = bearingFromNormalized($x2, $y2);
        $hLe = parseIdentHeading($leIdent);
        $hHe = parseIdentHeading($heIdent);
        $diff1Le = min(abs($bearing1 - $hLe), 360 - abs($bearing1 - $hLe));
        $diff1He = min(abs($bearing1 - $hHe), 360 - abs($bearing1 - $hHe));
        if ($diff1Le < $diff1He) {
            $identAt1 = $heIdent;
            $identAt2 = $leIdent;
        } else {
            $identAt1 = $leIdent;
            $identAt2 = $heIdent;
        }

        $segments[] = [
            'start' => [$x1, $y1],
            'end' => [$x2, $y2],
            'le_ident' => $identAt1,
            'he_ident' => $identAt2,
            'ident_at_start' => $identAt1,
            'ident_at_end' => $identAt2,
            'source' => $rw['source'] ?? 'programmatic',
        ];
    }
    return $segments;
}

/**
 * Prefer configured airport center; otherwise use runway endpoint centroid.
 *
 * @param array<int, array<string, mixed>> $runways
 * @param array<string, array{lat: float, lon: float}> $airportCenters
 * @return array{lat: float, lon: float}
 */
function resolveRunwayEntryCenter(array $runways, array $airportCenters, string $primaryId, ?string $altId = null): array
{
    $center = $airportCenters[$primaryId] ?? ($altId !== null ? ($airportCenters[$altId] ?? null) : null);
    if ($center !== null) {
        return $center;
    }
    $lats = array_merge(array_column($runways, 'lat1'), array_column($runways, 'lat2'));
    $lons = array_merge(array_column($runways, 'lon1'), array_column($runways, 'lon2'));

    return [
        'lat' => array_sum($lats) / count($lats),
        'lon' => array_sum($lons) / count($lons),
    ];
}

/**
 * Build one FAA-authoritative cache entry (geometry from FAA; OA performance/display when mapped).
 *
 * @param array<int, array<string, mixed>> $runways
 * @param array<string, array{lat: float, lon: float}> $airportCenters
 * @param array<string, array<int, array<string, mixed>>> $ourairports
 * @param array<string, string> $faaToIcao
 * @return array<string, mixed>
 */
function buildFaaMergedRunwayEntry(
    string $faaId,
    array $runways,
    array $airportCenters,
    array $ourairports,
    array $faaToIcao
): array {
    $icao = $faaToIcao[$faaId] ?? null;
    $center = resolveRunwayEntryCenter($runways, $airportCenters, $faaId, $icao);
    $entry = [
        'segments' => runwaysToSegments($runways, (float) $center['lat'], (float) $center['lon']),
        'center_lat' => $center['lat'],
        'center_lon' => $center['lon'],
    ];
    $oaRunways = resolveOurAirportsRunwaysForCacheIdent($faaId, $ourairports, $faaToIcao);
    if ($oaRunways !== null) {
        $entry['performance_runways'] = buildOurAirportsPerformanceRunways($oaRunways);
        $entry['display_runways'] = buildOurAirportsDisplayRunways($oaRunways);
    }

    return $entry;
}

/**
 * Build one OurAirports-only cache entry (geometry + performance/display from OA rows).
 *
 * @param array<int, array<string, mixed>> $runways
 * @param array<string, array{lat: float, lon: float}> $airportCenters
 * @return array<string, mixed>
 */
function buildOurAirportsMergedRunwayEntry(string $oaId, array $runways, array $airportCenters): array
{
    $center = resolveRunwayEntryCenter($runways, $airportCenters, $oaId);

    return [
        'segments' => runwaysToSegments($runways, (float) $center['lat'], (float) $center['lon']),
        'center_lat' => $center['lat'],
        'center_lon' => $center['lon'],
        'performance_runways' => buildOurAirportsPerformanceRunways($runways),
        'display_runways' => buildOurAirportsDisplayRunways($runways),
    ];
}

/**
 * Previous cache airport count for retention (meta preferred to avoid decoding ~13MB JSON).
 */
function readPreviousRunwaysCacheAirportCount(): ?int
{
    if (is_readable(CACHE_RUNWAYS_META_FILE)) {
        $raw = @file_get_contents(CACHE_RUNWAYS_META_FILE);
        $meta = is_string($raw) ? json_decode($raw, true) : null;
        if (is_array($meta) && isset($meta['airport_count']) && is_numeric($meta['airport_count'])) {
            return (int) $meta['airport_count'];
        }
    }

    if (!is_readable(CACHE_RUNWAYS_DATA_FILE)) {
        return null;
    }

    // Pre-meta caches: decode once so retention still fail-closed on shrink.
    $raw = @file_get_contents(CACHE_RUNWAYS_DATA_FILE);
    $data = is_string($raw) ? json_decode($raw, true) : null;
    unset($raw);
    if (!is_array($data) || !isset($data['airports']) || !is_array($data['airports'])) {
        return null;
    }

    return count($data['airports']);
}

/**
 * True only when the write reported every expected byte (rejects short writes).
 */
function runwaysCacheBytesFullyWritten(int|false $written, string $payload): bool
{
    return is_int($written) && $written === strlen($payload);
}

/**
 * @param resource $handle
 */
function fwriteExactRunwaysCache($handle, string $data): bool
{
    return runwaysCacheBytesFullyWritten(@fwrite($handle, $data), $data);
}

/**
 * Write runways_meta.json used by retention checks on the next merge.
 */
function writeRunwaysCacheMeta(int $airportCount, int $fetchedAt, int $memoryPeakBytes): bool
{
    ensureCacheDir(CACHE_RUNWAYS_DIR);
    $payload = [
        'airport_count' => $airportCount,
        'fetched_at' => $fetchedAt,
        'memory_peak_bytes' => $memoryPeakBytes,
        'written_at' => time(),
    ];
    $tmp = CACHE_RUNWAYS_META_FILE . '.tmp.' . getmypid();
    $json = json_encode($payload, JSON_UNESCAPED_SLASHES);
    // Reject short writes so truncated meta cannot poison retention next cycle.
    $written = $json === false ? false : @file_put_contents($tmp, $json, LOCK_EX);
    if ($json === false || !runwaysCacheBytesFullyWritten($written, $json) || !@rename($tmp, CACHE_RUNWAYS_META_FILE)) {
        @unlink($tmp);

        return false;
    }

    return true;
}

/**
 * @param resource $handle
 * @return array{ok: false, count: int, tmp_path: null, error: string}
 */
function abortMergedRunwaysStreamWrite($handle, string $tmpPath, int $written, string $error): array
{
    if (is_resource($handle)) {
        fclose($handle);
    }
    @unlink($tmpPath);

    return ['ok' => false, 'count' => $written, 'tmp_path' => null, 'error' => $error];
}

/**
 * Stream-merge into a temp JSON file. Does not replace the live cache (caller publishes).
 *
 * @param array<string, array<int, array<string, mixed>>> $faa
 * @param array<string, array<int, array<string, mixed>>> $ourairports
 * @param array<string, array{lat: float, lon: float}> $airportCenters
 * @param array<string, string> $faaToIcao
 * @return array{ok: bool, count: int, tmp_path: ?string, error: ?string}
 */
function writeMergedRunwaysCacheStreaming(
    array $faa,
    array $ourairports,
    array $airportCenters,
    array $faaToIcao,
    int $fetchedAt
): array {
    ensureCacheDir(CACHE_RUNWAYS_DIR);
    $tmpPath = CACHE_RUNWAYS_DATA_FILE . '.tmp.' . getmypid();
    $handle = @fopen($tmpPath, 'wb');
    if ($handle === false) {
        return ['ok' => false, 'count' => 0, 'tmp_path' => null, 'error' => 'failed to open temp cache for write'];
    }

    $chunkSize = max(1, (int) RUNWAYS_MERGE_WRITE_CHUNK_SIZE);
    $written = 0;
    $first = true;
    $coveredByIdent = [];

    $writeEntry = static function (string $ident, array $entry) use ($handle, &$written, &$first): ?string {
        $keyJson = json_encode($ident, JSON_UNESCAPED_SLASHES);
        $entryJson = json_encode($entry, JSON_UNESCAPED_SLASHES);
        if ($keyJson === false || $entryJson === false) {
            return 'json_encode failed for airport ' . $ident;
        }
        // Manual object members: commas between keys; full-file json_encode would spike RAM.
        $prefix = $first ? '' : ',';
        $chunk = $prefix . $keyJson . ':' . $entryJson;
        if (!fwriteExactRunwaysCache($handle, $chunk)) {
            return 'fwrite failed for airport ' . $ident;
        }
        $first = false;
        $written++;

        return null;
    };

    if (!fwriteExactRunwaysCache($handle, '{"fetched_at":' . (int) $fetchedAt . ',"airports":{')) {
        return abortMergedRunwaysStreamWrite($handle, $tmpPath, 0, 'failed to write cache header');
    }

    foreach (array_chunk(array_keys($faa), $chunkSize) as $chunkIds) {
        foreach ($chunkIds as $faaId) {
            $runways = $faa[$faaId] ?? [];
            if ($runways === []) {
                continue;
            }
            $entry = buildFaaMergedRunwayEntry((string) $faaId, $runways, $airportCenters, $ourairports, $faaToIcao);
            $err = $writeEntry((string) $faaId, $entry);
            if ($err !== null) {
                return abortMergedRunwaysStreamWrite($handle, $tmpPath, $written, $err);
            }
            $coveredByIdent[(string) $faaId] = true;
            if (isset($faaToIcao[$faaId])) {
                $icao = (string) $faaToIcao[$faaId];
                $err = $writeEntry($icao, $entry);
                if ($err !== null) {
                    return abortMergedRunwaysStreamWrite($handle, $tmpPath, $written, $err);
                }
                $coveredByIdent[$icao] = true;
            }
        }
        updateWorkerHeartbeat();
        gc_collect_cycles();
    }

    foreach (array_chunk(array_keys($ourairports), $chunkSize) as $chunkIds) {
        foreach ($chunkIds as $oaId) {
            if (isset($coveredByIdent[$oaId])) {
                continue;
            }
            $runways = $ourairports[$oaId] ?? [];
            if ($runways === []) {
                continue;
            }
            $entry = buildOurAirportsMergedRunwayEntry((string) $oaId, $runways, $airportCenters);
            $err = $writeEntry((string) $oaId, $entry);
            if ($err !== null) {
                return abortMergedRunwaysStreamWrite($handle, $tmpPath, $written, $err);
            }
        }
        updateWorkerHeartbeat();
        gc_collect_cycles();
    }

    if (!fwriteExactRunwaysCache($handle, '}}')) {
        return abortMergedRunwaysStreamWrite($handle, $tmpPath, $written, 'failed to write cache footer');
    }
    if (!fclose($handle)) {
        @unlink($tmpPath);

        return ['ok' => false, 'count' => $written, 'tmp_path' => null, 'error' => 'failed to close temp cache'];
    }

    if ($written === 0) {
        @unlink($tmpPath);

        return ['ok' => false, 'count' => 0, 'tmp_path' => null, 'error' => 'merged airport count is zero'];
    }

    return ['ok' => true, 'count' => $written, 'tmp_path' => $tmpPath, 'error' => null];
}

/**
 * Retention-check a streamed temp cache, then atomically replace the live file.
 *
 * Fail-closed: on reject or rename failure the previous live cache is left untouched.
 *
 * @param array<string, array{lat: float, lon: float}> $airportCenters
 * @return array{ok: bool, count: int, error: ?string, retained_previous: bool}
 */
function publishMergedRunwaysCacheAfterRetentionCheck(
    string $tmpPath,
    int $newCount,
    ?int $previousCount,
    array $airportCenters,
    bool $airportsCsvExpected,
    int $fetchedAt
): array {
    $rejectReason = runwaysMergeRejectReasonFromCounts(
        $newCount,
        $previousCount,
        $airportCenters,
        $airportsCsvExpected
    );
    if ($rejectReason !== null) {
        @unlink($tmpPath);

        return [
            'ok' => false,
            'count' => $newCount,
            'error' => $rejectReason,
            'retained_previous' => true,
        ];
    }

    if (!@rename($tmpPath, CACHE_RUNWAYS_DATA_FILE)) {
        @unlink($tmpPath);

        return [
            'ok' => false,
            'count' => $newCount,
            'error' => 'failed to publish cache via rename',
            'retained_previous' => true,
        ];
    }

    if (!writeRunwaysCacheMeta($newCount, $fetchedAt, memory_get_peak_usage(true))) {
        aviationwx_log('warning', 'runways fetch: failed to write runways meta cache', [
            'airport_count' => $newCount,
            'meta_path' => CACHE_RUNWAYS_META_FILE,
        ], 'app');
    }

    return [
        'ok' => true,
        'count' => $newCount,
        'error' => null,
        'retained_previous' => false,
    ];
}

/**
 * Merge FAA and OurAirports; FAA segments for FAA airports, OurAirports for the rest
 *
 * For airports in the FAA dataset: segment geometry comes from FAA only, output under both
 * FAA ID and ICAO (when mapped via OurAirports airports.csv). When OurAirports has matching
 * runway rows, attach them as performance_runways for DA fallback without replacing FAA
 * segments. For airports not in FAA: use OurAirports for both segments and performance_runways.
 *
 * @param array $faa Runways by airport from FAA (keyed by ARPT_ID e.g. HIO)
 * @param array $ourairports Runways by airport from OurAirports (keyed by airport_ident e.g. KHIO)
 * @param array $airportCenters Lat/lon per airport (from OurAirports airports.csv or centroid)
 * @param array $faaToIcao FAA LID -> ICAO mapping for US airports (from OurAirports airports.csv)
 * @return array Merged airports with segments
 */
function mergeRunwaySources(array $faa, array $ourairports, array $airportCenters, array $faaToIcao = []): array
{
    $result = [];
    $coveredByIdent = [];

    foreach ($faa as $faaId => $runways) {
        if ($runways === []) {
            continue;
        }
        $entry = buildFaaMergedRunwayEntry((string) $faaId, $runways, $airportCenters, $ourairports, $faaToIcao);
        $result[$faaId] = $entry;
        $coveredByIdent[$faaId] = true;
        if (isset($faaToIcao[$faaId])) {
            $result[$faaToIcao[$faaId]] = $entry;
            $coveredByIdent[$faaToIcao[$faaId]] = true;
        }
    }

    foreach ($ourairports as $oaId => $runways) {
        if (isset($coveredByIdent[$oaId]) || $runways === []) {
            continue;
        }
        $result[$oaId] = buildOurAirportsMergedRunwayEntry((string) $oaId, $runways, $airportCenters);
    }

    return $result;
}

// CLI entry
if (php_sapi_name() === 'cli') {
    $scriptName = $_SERVER['PHP_SELF'] ?? $_SERVER['SCRIPT_NAME'] ?? '';
    if (basename($scriptName) !== basename(__FILE__) && $scriptName !== __FILE__) {
        return;
    }

    initWorkerTimeout(RUNWAYS_MERGE_WORKER_TIMEOUT, 'runways_merge');

    // Scheduler redirects worker stdout/stderr to /dev/null; surface fatals in app.log.
    register_shutdown_function(static function (): void {
        $err = error_get_last();
        if ($err === null) {
            return;
        }
        $fatalTypes = [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR];
        if (!in_array($err['type'] ?? 0, $fatalTypes, true)) {
            return;
        }
        aviationwx_log('error', 'runways fetch: fatal error', [
            'type' => $err['type'],
            'message' => $err['message'] ?? '',
            'file' => $err['file'] ?? '',
            'line' => $err['line'] ?? 0,
            'memory_limit' => ini_get('memory_limit'),
            'memory_peak_bytes' => memory_get_peak_usage(true),
        ], 'app', true);
    });

    if (!runwaysMergeWorkerShouldRun()) {
        $reason = runwaysMergeWaitingReason();
        aviationwx_log('info', 'runways fetch: skipped', [
            'reason' => $reason ?? 'not due',
        ], 'app');
        exit(0);
    }

    $fp = acquireRunwaysFetchLock();
    if ($fp === false) {
        aviationwx_log('info', 'runways fetch: another instance running, skipping', [], 'app');
        exit(0);
    }

    if (!runwaysMergeWorkerShouldRun(true)) {
        flock($fp, LOCK_UN);
        fclose($fp);
        $reason = runwaysMergeWaitingReason();
        aviationwx_log('info', 'runways fetch: skipped after lock', [
            'reason' => $reason ?? 'not due',
        ], 'app');
        exit(0);
    }

    $oaPath = ourAirportsCsvPath('runways');
    $faaPath = CACHE_FAA_NGDA_RUNWAYS_CSV;
    aviationwx_log('info', 'runways fetch: starting merge', [
        'mode' => 'chunked_stream_write',
        'chunk_size' => RUNWAYS_MERGE_WRITE_CHUNK_SIZE,
        'memory_limit' => ini_get('memory_limit'),
        'oa_runways_bytes' => is_readable($oaPath) ? (int) filesize($oaPath) : 0,
        'faa_ngda_bytes' => is_readable($faaPath) ? (int) filesize($faaPath) : 0,
        'previous_cache_bytes' => is_readable(CACHE_RUNWAYS_DATA_FILE) ? (int) filesize(CACHE_RUNWAYS_DATA_FILE) : 0,
    ], 'app');

    if (!is_readable($faaPath) || (faaNgdaRunwayCsvNeedsRefresh() && faaNgdaOverdueRefreshShouldTriggerMerge())) {
        downloadFaaNgdaRunwaysIfNeeded();
    }
    $oaReadable = is_readable($oaPath);
    $faaReadable = is_readable($faaPath);
    if (!$oaReadable && !$faaReadable) {
        aviationwx_log('error', 'runways fetch: no runway source data available on disk', [], 'app', true);
        flock($fp, LOCK_UN);
        fclose($fp);
        exit(1);
    }

    $ourairports = $oaReadable ? parseOurAirportsRunwaysFromPath($oaPath) : [];
    $faa = $faaReadable ? parseFaaRunwaysFromPath($faaPath) : [];
    updateWorkerHeartbeat();

    $mapping = ourAirportsLoadAirportCentersAndFaaMappingFromDisk();
    $airportCenters = $mapping['centers'];
    $faaToIcao = $mapping['faa_to_icao'];

    $airportsCsvPath = ourAirportsCsvPath('airports');
    $airportsCsvSize = is_readable($airportsCsvPath) ? @filesize($airportsCsvPath) : false;
    $airportsCsvExpected = is_int($airportsCsvSize) && $airportsCsvSize > 200;
    $previousCount = readPreviousRunwaysCacheAirportCount();

    if ($airportsCsvExpected && $airportCenters === []) {
        aviationwx_log('error', 'runways fetch: retaining previous cache', [
            'reason' => 'airports.csv present but center mapping is empty',
            'merged_airports' => 0,
            'previous_airports' => $previousCount,
        ], 'app', true);
        flock($fp, LOCK_UN);
        fclose($fp);
        exit(is_readable(CACHE_RUNWAYS_DATA_FILE) ? 0 : 1);
    }

    $fetchedAt = time();
    $writeResult = writeMergedRunwaysCacheStreaming(
        $faa,
        $ourairports,
        $airportCenters,
        $faaToIcao,
        $fetchedAt
    );
    unset($faa, $ourairports, $faaToIcao, $mapping);

    $tmpPath = $writeResult['tmp_path'] ?? null;
    if (!$writeResult['ok'] || !is_string($tmpPath) || $tmpPath === '') {
        aviationwx_log('error', 'runways fetch: retaining previous cache', [
            'reason' => $writeResult['error'] ?? 'stream write failed',
            'merged_airports' => $writeResult['count'],
            'previous_airports' => $previousCount,
            'memory_peak_bytes' => memory_get_peak_usage(true),
        ], 'app', true);
        flock($fp, LOCK_UN);
        fclose($fp);
        exit(is_readable(CACHE_RUNWAYS_DATA_FILE) ? 0 : 1);
    }

    $publish = publishMergedRunwaysCacheAfterRetentionCheck(
        $tmpPath,
        (int) $writeResult['count'],
        $previousCount,
        $airportCenters,
        $airportsCsvExpected,
        $fetchedAt
    );
    unset($airportCenters);

    if (!$publish['ok']) {
        aviationwx_log('error', 'runways fetch: retaining previous cache', [
            'reason' => $publish['error'] ?? 'publish failed',
            'merged_airports' => $publish['count'],
            'previous_airports' => $previousCount,
            'memory_peak_bytes' => memory_get_peak_usage(true),
            'retained_previous' => $publish['retained_previous'],
        ], 'app', true);
        flock($fp, LOCK_UN);
        fclose($fp);
        exit(is_readable(CACHE_RUNWAYS_DATA_FILE) ? 0 : 1);
    }

    $config = loadConfig(false);
    $airports = $config['airports'] ?? [];
    $warmed = warmRunwaysApcuCache($airports);

    flock($fp, LOCK_UN);
    fclose($fp);
    @unlink(getRunwaysFetchLockPath());

    aviationwx_log('info', 'runways fetch: complete', [
        'airports' => $publish['count'],
        'apcu_warmed' => $warmed,
        'memory_peak_bytes' => memory_get_peak_usage(true),
        'mode' => 'chunked_stream_write',
    ], 'app');

    exit(0);
}
