<?php

/**
 * Runway Geometry Fetcher
 *
 * Downloads FAA (US) and OurAirports (worldwide) runway data, merges with FAA preferred,
 * transforms to normalized segments, writes file cache, warms APCu.
 *
 * Lock file prevents concurrent fetches. Runs weekly; fetches only when missing or >30 days old.
 *
 * Usage: php scripts/fetch-runways.php
 */

require_once __DIR__ . '/../lib/config.php';
require_once __DIR__ . '/../lib/logger.php';
require_once __DIR__ . '/../lib/cache-paths.php';
require_once __DIR__ . '/../lib/runways.php';

// Large runway cache + APCu warm needs extra memory for json_decode
// @ suppresses ini_set failure in restricted environments (e.g. disable_functions)
@ini_set('memory_limit', '256M');

$OURAIRPORTS_RUNWAYS_URL = 'https://davidmegginson.github.io/ourairports-data/runways.csv';
$FAA_RUNWAYS_URL = 'https://ngda-transportation-geoplatform.hub.arcgis.com/api/download/v1/items/110af7b8a9424a59a3fb1d8fc69a2172/csv?layers=0';

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
 * Download URL to string
 *
 * @param string $url URL to fetch
 * @return string|null Content or null on failure
 */
function downloadUrl(string $url): ?string {
    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL => $url,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 120,
        CURLOPT_CONNECTTIMEOUT => 15,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_USERAGENT => 'AviationWX Runway Fetcher/1.0',
    ]);
    $content = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($content === false || $httpCode !== 200) {
        return null;
    }
    return $content;
}

/**
 * Parse OurAirports runways CSV
 *
 * @param string $csv Raw CSV content
 * @return array<string, array> Runways by airport_ident
 */
function parseOurAirportsRunways(string $csv): array {
    $lines = str_getcsv($csv, "\n", '"', '\\');
    if (count($lines) < 2) {
        return [];
    }

    $header = str_getcsv(array_shift($lines), ',', '"', '\\');
    $idx = array_flip(array_map('trim', $header));

    $byAirport = [];
    foreach ($lines as $line) {
        $row = str_getcsv($line, ',', '"', '\\');
        if (count($row) < 5) {
            continue;
        }

        $closed = $idx['closed'] ?? null;
        if ($closed !== null && isset($row[$closed]) && (int) $row[$closed] === 1) {
            continue;
        }

        $ident = $idx['airport_ident'] ?? null;
        $leLat = $idx['le_latitude_deg'] ?? null;
        $leLon = $idx['le_longitude_deg'] ?? null;
        $heLat = $idx['he_latitude_deg'] ?? null;
        $heLon = $idx['he_longitude_deg'] ?? null;
        $leIdent = $idx['le_ident'] ?? null;
        $heIdent = $idx['he_ident'] ?? null;

        if ($ident === null) {
            continue;
        }

        $airportId = strtoupper(trim($row[$ident] ?? ''));
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

        if (!isset($byAirport[$airportId])) {
            $byAirport[$airportId] = [];
        }
        $byAirport[$airportId][] = [
            'lat1' => $lat1,
            'lon1' => $lon1,
            'lat2' => $lat2,
            'lon2' => $lon2,
            'le_ident' => $leIdent !== null ? trim($row[$leIdent] ?? '') : '',
            'he_ident' => $heIdent !== null ? trim($row[$heIdent] ?? '') : '',
            'source' => 'ourairports',
        ];
    }
    return $byAirport;
}

/**
 * Parse FAA runways CSV (ArcGIS export format)
 *
 * @param string $csv Raw CSV content
 * @return array<string, array> Runways by ARPT_ID
 */
function parseFaaRunways(string $csv): array {
    $lines = str_getcsv($csv, "\n", '"', '\\');
    if (count($lines) < 2) {
        return [];
    }

    $header = str_getcsv(array_shift($lines), ',', '"', '\\');
    $idx = array_flip(array_map('trim', $header));

    $lat1Key = 'LAT1_DECIMAL';
    $lon1Key = 'LONG1_DECIMAL';
    $lat2Key = 'LAT2_DECIMAL';
    $lon2Key = 'LONG2_DECIMAL';
    $arptKey = 'ARPT_ID';
    $rwyKey = 'RWY_ID';

    if (!isset($idx[$arptKey])) {
        return [];
    }

    $byAirport = [];
    foreach ($lines as $line) {
        $row = str_getcsv($line, ',', '"', '\\');
        if (count($row) < 5) {
            continue;
        }

        $airportId = strtoupper(trim($row[$idx[$arptKey]] ?? ''));
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

        $rwyId = isset($idx[$rwyKey], $row[$idx[$rwyKey]]) ? trim($row[$idx[$rwyKey]]) : '';
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
 * Merge FAA and OurAirports; FAA-only for FAA airports, OurAirports for the rest
 *
 * For airports in the FAA dataset: use FAA data only, output under both FAA ID and ICAO
 * (when mapped via OurAirports airports.csv). For airports not in FAA: use OurAirports.
 * Never mix both sources for the same airport.
 *
 * @param array $faa Runways by airport from FAA (keyed by ARPT_ID e.g. HIO)
 * @param array $ourairports Runways by airport from OurAirports (keyed by airport_ident e.g. KHIO)
 * @param array $airportCenters Lat/lon per airport (from OurAirports airports.csv or centroid)
 * @param array $faaToIcao FAA LID -> ICAO mapping for US airports (from OurAirports airports.csv)
 * @return array Merged airports with segments
 */
function mergeRunwaySources(array $faa, array $ourairports, array $airportCenters, array $faaToIcao = []): array {
    $result = [];
    $coveredByIdent = [];

    // 1. Process FAA airports: use FAA data only, output under FAA ID and ICAO when mapped
    foreach ($faa as $faaId => $runways) {
        if (empty($runways)) {
            continue;
        }
        $center = $airportCenters[$faaId] ?? $airportCenters[$faaToIcao[$faaId] ?? ''] ?? null;
        if ($center === null) {
            $lats = array_merge(array_column($runways, 'lat1'), array_column($runways, 'lat2'));
            $lons = array_merge(array_column($runways, 'lon1'), array_column($runways, 'lon2'));
            $center = [
                'lat' => array_sum($lats) / count($lats),
                'lon' => array_sum($lons) / count($lons),
            ];
        }
        $segments = runwaysToSegments($runways, (float) $center['lat'], (float) $center['lon']);
        $entry = [
            'segments' => $segments,
            'center_lat' => $center['lat'],
            'center_lon' => $center['lon'],
        ];
        $result[$faaId] = $entry;
        $coveredByIdent[$faaId] = true;
        if (isset($faaToIcao[$faaId])) {
            $result[$faaToIcao[$faaId]] = $entry;
            $coveredByIdent[$faaToIcao[$faaId]] = true;
        }
    }

    // 2. Process OurAirports airports: use only when not already covered by FAA
    foreach ($ourairports as $oaId => $runways) {
        if (isset($coveredByIdent[$oaId]) || empty($runways)) {
            continue;
        }
        $center = $airportCenters[$oaId] ?? null;
        if ($center === null) {
            $lats = array_merge(array_column($runways, 'lat1'), array_column($runways, 'lat2'));
            $lons = array_merge(array_column($runways, 'lon1'), array_column($runways, 'lon2'));
            $center = [
                'lat' => array_sum($lats) / count($lats),
                'lon' => array_sum($lons) / count($lons),
            ];
        }
        $segments = runwaysToSegments($runways, (float) $center['lat'], (float) $center['lon']);
        $result[$oaId] = [
            'segments' => $segments,
            'center_lat' => $center['lat'],
            'center_lon' => $center['lon'],
        ];
    }
    return $result;
}

// CLI entry
if (php_sapi_name() !== 'cli') {
    exit(1);
}

$fp = acquireRunwaysFetchLock();
if ($fp === false) {
    aviationwx_log('info', 'runways fetch: another instance running, skipping', [], 'app');
    exit(0);
}

if (!runwaysCacheNeedsRefresh()) {
    flock($fp, LOCK_UN);
    fclose($fp);
    aviationwx_log('info', 'runways fetch: cache fresh, skipping', [], 'app');
    exit(0);
}

aviationwx_log('info', 'runways fetch: starting download', [], 'app');

$ourairportsCsv = downloadUrl($OURAIRPORTS_RUNWAYS_URL);
$faaCsv = downloadUrl($FAA_RUNWAYS_URL);

if ($ourairportsCsv === null && $faaCsv === null) {
    aviationwx_log('error', 'runways fetch: both downloads failed', [], 'app', true);
    flock($fp, LOCK_UN);
    fclose($fp);
    exit(1);
}

$ourairports = $ourairportsCsv !== null ? parseOurAirportsRunways($ourairportsCsv) : [];
$faa = $faaCsv !== null ? parseFaaRunways($faaCsv) : [];

$airportCenters = [];
$faaToIcao = [];
if ($ourairportsCsv !== null) {
    $airportsCsv = downloadUrl('https://davidmegginson.github.io/ourairports-data/airports.csv');
    if ($airportsCsv !== null) {
        $lines = str_getcsv($airportsCsv, "\n", '"', '\\');
        $header = str_getcsv(array_shift($lines), ',', '"', '\\');
        $idx = array_flip(array_map('trim', $header));
        $identIdx = $idx['ident'] ?? null;
        $latIdx = $idx['latitude_deg'] ?? null;
        $lonIdx = $idx['longitude_deg'] ?? null;
        $gpsIdx = $idx['gps_code'] ?? null;
        $icaoIdx = $idx['icao_code'] ?? null;
        $iataIdx = $idx['iata_code'] ?? null;
        $localIdx = $idx['local_code'] ?? null;
        $countryIdx = $idx['iso_country'] ?? null;
        if ($identIdx !== null && $latIdx !== null && $lonIdx !== null) {
            foreach ($lines as $line) {
                $row = str_getcsv($line, ',', '"', '\\');
                if (count($row) <= max($identIdx, $latIdx, $lonIdx)) {
                    continue;
                }
                $id = strtoupper(trim($row[$identIdx] ?? ''));
                $lat = $row[$latIdx] ?? '';
                $lon = $row[$lonIdx] ?? '';
                if ($id !== '' && $lat !== '' && $lon !== '') {
                    $airportCenters[$id] = ['lat' => (float) $lat, 'lon' => (float) $lon];
                }
                if ($icaoIdx === null || $countryIdx === null || trim($row[$countryIdx] ?? '') !== 'US') {
                    continue;
                }
                $icao = strtoupper(trim($row[$icaoIdx] ?? ''));
                if ($icao === '') {
                    continue;
                }
                foreach ([$gpsIdx, $iataIdx, $localIdx] as $altIdx) {
                    if ($altIdx === null) {
                        continue;
                    }
                    $alt = strtoupper(trim($row[$altIdx] ?? ''));
                    if ($alt !== '' && $alt !== $icao) {
                        $faaToIcao[$alt] = $icao;
                    }
                }
            }
        }
    }
}

$merged = mergeRunwaySources($faa, $ourairports, $airportCenters, $faaToIcao);

$output = [
    'fetched_at' => time(),
    'airports' => $merged,
];

ensureCacheDir(CACHE_RUNWAYS_DIR);
$tmpPath = CACHE_RUNWAYS_DATA_FILE . '.tmp.' . getmypid();
$written = @file_put_contents($tmpPath, json_encode($output, JSON_UNESCAPED_SLASHES));
if ($written === false || !@rename($tmpPath, CACHE_RUNWAYS_DATA_FILE)) {
    @unlink($tmpPath);
    aviationwx_log('error', 'runways fetch: failed to write cache', [], 'app', true);
    flock($fp, LOCK_UN);
    fclose($fp);
    exit(1);
}

$config = loadConfig(false);
$airports = $config['airports'] ?? [];
$warmed = warmRunwaysApcuCache($airports);

flock($fp, LOCK_UN);
fclose($fp);
@unlink(getRunwaysFetchLockPath());

aviationwx_log('info', 'runways fetch: complete', [
    'airports' => count($merged),
    'apcu_warmed' => $warmed,
], 'app');

exit(0);
