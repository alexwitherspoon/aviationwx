#!/usr/bin/env php
<?php

/**
 * Compare manual runway definitions (airports.json) with FAA/OurAirports cache sources
 *
 * Fetches runway data from FAA and OurAirports, then compares lat/lon coordinates
 * with manual definitions in config. Reports matches, differences, and missing runways.
 *
 * Usage:
 *   CONFIG_PATH=/path/to/airports.json php scripts/compare-runways.php
 *   php scripts/compare-runways.php [--config path] [--tolerance 0.0001]
 *
 * Options:
 *   --config path    Config file (default: CONFIG_PATH or config/airports.json)
 *   --tolerance deg  Max diff to consider "match" in degrees (default: 0.0001 ≈ 11m)
 */

error_reporting(E_ALL & ~E_DEPRECATED);

$OURAIRPORTS_RUNWAYS_URL = 'https://davidmegginson.github.io/ourairports-data/runways.csv';
$FAA_RUNWAYS_URL = 'https://ngda-transportation-geoplatform.hub.arcgis.com/api/download/v1/items/110af7b8a9424a59a3fb1d8fc69a2172/csv?layers=0';

$options = getopt('', ['config:', 'tolerance:', 'help']);
$configPath = $options['config'] ?? getenv('CONFIG_PATH') ?: __DIR__ . '/../config/airports.json';
$tolerance = isset($options['tolerance']) ? (float) $options['tolerance'] : 0.0001;

if (isset($options['help'])) {
    echo "Compare manual runways (airports.json) with FAA/OurAirports\n\n";
    echo "Usage: php scripts/compare-runways.php [--config path] [--tolerance 0.0001]\n\n";
    echo "  --config path    Config file path\n";
    echo "  --tolerance deg Max lat/lon diff to consider match (default 0.0001 ≈ 11m)\n";
    exit(0);
}

function downloadUrl(string $url): ?string
{
    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL => $url,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 120,
        CURLOPT_CONNECTTIMEOUT => 15,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_USERAGENT => 'AviationWX Runway Compare/1.0',
    ]);
    $content = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return ($content !== false && $httpCode === 200) ? $content : null;
}

function parseOurAirportsRunways(string $csv): array
{
    $lines = str_getcsv($csv, "\n");
    if (count($lines) < 2) {
        return [];
    }
    $header = str_getcsv(array_shift($lines), ',');
    $idx = array_flip(array_map('trim', $header));
    $byAirport = [];
    foreach ($lines as $line) {
        $row = str_getcsv($line, ',');
        if (count($row) < 5) {
            continue;
        }
        $closed = $idx['closed'] ?? null;
        if ($closed !== null && isset($row[$closed]) && (int) $row[$closed] === 1) {
            continue;
        }
        $ident = $idx['airport_ident'] ?? null;
        if ($ident === null) {
            continue;
        }
        $airportId = strtoupper(trim($row[$ident] ?? ''));
        if ($airportId === '') {
            continue;
        }
        $leLat = $idx['le_latitude_deg'] ?? null;
        $leLon = $idx['le_longitude_deg'] ?? null;
        $heLat = $idx['he_latitude_deg'] ?? null;
        $heLon = $idx['he_longitude_deg'] ?? null;
        $leIdent = $idx['le_ident'] ?? null;
        $heIdent = $idx['he_ident'] ?? null;
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
            'lat1' => $lat1, 'lon1' => $lon1, 'lat2' => $lat2, 'lon2' => $lon2,
            'le_ident' => $leIdent !== null ? trim($row[$leIdent] ?? '') : '',
            'he_ident' => $heIdent !== null ? trim($row[$heIdent] ?? '') : '',
            'source' => 'ourairports',
        ];
    }
    return $byAirport;
}

function parseFaaRunways(string $csv): array
{
    $lines = str_getcsv($csv, "\n");
    if (count($lines) < 2) {
        return [];
    }
    $header = str_getcsv(array_shift($lines), ',');
    $idx = array_flip(array_map('trim', $header));
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
    foreach ($lines as $line) {
        $row = str_getcsv($line, ',');
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
        $parts = preg_split('#\s*/\s*#', $rwyId, 2);
        $leIdent = trim($parts[0] ?? '');
        $heIdent = trim($parts[1] ?? $parts[0] ?? '');
        if (!isset($byAirport[$airportId])) {
            $byAirport[$airportId] = [];
        }
        $byAirport[$airportId][] = [
            'lat1' => $lat1, 'lon1' => $lon1, 'lat2' => $lat2, 'lon2' => $lon2,
            'le_ident' => $leIdent,
            'he_ident' => $heIdent,
            'source' => 'faa',
        ];
    }
    return $byAirport;
}

function runwaysToIdentMap(array $runways): array
{
    $map = [];
    foreach ($runways as $rw) {
        $le = $rw['le_ident'] ?? '';
        $he = $rw['he_ident'] ?? '';
        if ($le !== '') {
            $map[$le] = ['lat' => $rw['lat1'], 'lon' => $rw['lon1']];
        }
        if ($he !== '') {
            $map[$he] = ['lat' => $rw['lat2'], 'lon' => $rw['lon2']];
        }
    }
    return $map;
}

function manualRunwaysToIdentMap(array $runways): array
{
    $map = [];
    foreach ($runways as $rw) {
        foreach ($rw as $key => $val) {
            if ($key === 'name' || !is_array($val)) {
                continue;
            }
            $lat = $val['lat'] ?? null;
            $lon = $val['lon'] ?? null;
            if ($lat !== null && $lon !== null) {
                $map[$key] = ['lat' => (float) $lat, 'lon' => (float) $lon];
            }
        }
    }
    return $map;
}

function isLatLonFormat(array $runways): bool
{
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

$identsMap = [
    'kpfc' => ['KPFC', 'PFC'],
    'kspb' => ['KSPB', 'SPB'],
    'khio' => ['HIO', 'KHIO'],
    'kboi' => ['KBOI', 'BOI'],
    '56s' => ['56S'],
    'cyav' => ['CYAV'],
    'kczk' => ['KCZK', 'CZK'],
    'kvkx' => ['KVKX', 'VKX'],
    '7s5' => ['7S5'],
    '2id3' => ['2ID3'],
    'or81' => ['OR81'],
    'id76' => ['ID76'],
    'keul' => ['KEUL', 'EUL'],
    'kman' => ['KMAN', 'MAN'],
    's40' => ['S40'],
    '42b' => ['42B'],
    '69v' => ['69V'],
];

if (!file_exists($configPath)) {
    fwrite(STDERR, "Config not found: $configPath\n");
    exit(1);
}

$config = json_decode(file_get_contents($configPath), true);
if (!is_array($config) || !isset($config['airports'])) {
    fwrite(STDERR, "Invalid config\n");
    exit(1);
}

echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n";
echo "Runway Comparison: Manual (airports.json) vs FAA/OurAirports\n";
echo "Config: $configPath | Tolerance: {$tolerance}° (~" . round($tolerance * 111000) . "m)\n";
echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n\n";

echo "Downloading FAA/OurAirports runway data...\n";
$faaCsv = downloadUrl($FAA_RUNWAYS_URL);
$oaCsv = downloadUrl($OURAIRPORTS_RUNWAYS_URL);
$faa = $faaCsv !== null ? parseFaaRunways($faaCsv) : [];
$ourairports = $oaCsv !== null ? parseOurAirportsRunways($oaCsv) : [];
echo "  FAA: " . count($faa) . " airports | OurAirports: " . count($ourairports) . " airports\n\n";

$airportsWithLatLon = [];
foreach ($config['airports'] as $airportId => $airport) {
    if (!isset($airport['runways']) || !is_array($airport['runways'])) {
        continue;
    }
    if (!isLatLonFormat($airport['runways'])) {
        continue;
    }
    $airportsWithLatLon[$airportId] = $airport;
}

if (empty($airportsWithLatLon)) {
    echo "No airports with lat/lon runway format in config.\n";
    exit(0);
}

$identsToTry = [];
foreach (array_keys($airportsWithLatLon) as $id) {
    $identsToTry[$id] = $identsMap[strtolower($id)] ?? [strtoupper($id)];
}

foreach ($airportsWithLatLon as $airportId => $airport) {
    $manualMap = manualRunwaysToIdentMap($airport['runways']);
    $cacheRunways = null;
    $cacheSource = null;

    foreach ($identsToTry[$airportId] ?? [strtoupper($airportId)] as $ident) {
        $cacheRunways = !empty($faa[$ident]) ? $faa[$ident] : ($ourairports[$ident] ?? null);
        $cacheSource = !empty($faa[$ident]) ? 'FAA' : (!empty($ourairports[$ident]) ? 'OurAirports' : null);
        if ($cacheRunways !== null && !empty($cacheRunways)) {
            break;
        }
    }

    echo "─── $airportId ({$airport['name']}) ───\n";

    if ($cacheRunways === null || empty($cacheRunways)) {
        echo "  Cache: No data (tried " . implode(', ', $identsToTry[$airportId] ?? []) . ")\n";
        echo "  Manual: " . count($manualMap) . " runway ends\n\n";
        continue;
    }

    $cacheMap = runwaysToIdentMap($cacheRunways);
    echo "  Source: $cacheSource | Manual: " . count($manualMap) . " ends | Cache: " . count($cacheMap) . " ends\n";

    $onlyManual = array_diff_key($manualMap, $cacheMap);
    $onlyCache = array_diff_key($cacheMap, $manualMap);

    if (!empty($onlyCache)) {
        echo "  ⚠️  In cache but not manual: " . implode(', ', array_keys($onlyCache)) . "\n";
    }
    if (!empty($onlyManual)) {
        echo "  ⚠️  In manual but not cache: " . implode(', ', array_keys($onlyManual)) . "\n";
    }

    $diffs = [];
    foreach ($manualMap as $ident => $manual) {
        if (!isset($cacheMap[$ident])) {
            continue;
        }
        $cache = $cacheMap[$ident];
        $dLat = abs($manual['lat'] - $cache['lat']);
        $dLon = abs($manual['lon'] - $cache['lon']);
        if ($dLat > $tolerance || $dLon > $tolerance) {
            $approxM = round(sqrt($dLat * $dLat + $dLon * $dLon) * 111000);
            $diffs[] = sprintf(
                "%s: Δlat=%.6f Δlon=%.6f (~%dm) [manual %.6f,%.6f vs cache %.6f,%.6f]",
                $ident,
                $manual['lat'] - $cache['lat'],
                $manual['lon'] - $cache['lon'],
                $approxM,
                $manual['lat'],
                $manual['lon'],
                $cache['lat'],
                $cache['lon']
            );
        }
    }

    if (!empty($diffs)) {
        echo "  📐 Differences > {$tolerance}°:\n";
        foreach ($diffs as $d) {
            echo "     $d\n";
        }
    } else {
        $common = array_intersect_key($manualMap, $cacheMap);
        echo "  ✓ Match: " . count($common) . " runway ends within tolerance\n";
    }

    echo "\n";
}

echo "Done.\n";
