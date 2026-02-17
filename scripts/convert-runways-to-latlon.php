<?php

/**
 * Convert runway data from FAA/OurAirports to lat/lon schema for airports.json
 *
 * Downloads FAA (US) and OurAirports runways, outputs the new format for specified
 * airports. Use output to replace "runways" in airports.json.
 *
 * Usage:
 *   php scripts/convert-runways-to-latlon.php kpfc kspb khio kboi 56s
 *   php scripts/convert-runways-to-latlon.php --output runways.json
 */
error_reporting(E_ALL & ~E_DEPRECATED);

$OURAIRPORTS_RUNWAYS_URL = 'https://davidmegginson.github.io/ourairports-data/runways.csv';
$FAA_RUNWAYS_URL = 'https://ngda-transportation-geoplatform.hub.arcgis.com/api/download/v1/items/110af7b8a9424a59a3fb1d8fc69a2172/csv?layers=0';

function downloadUrl(string $url): ?string {
    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL => $url,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 120,
        CURLOPT_CONNECTTIMEOUT => 15,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_USERAGENT => 'AviationWX Runway Converter/1.0',
    ]);
    $content = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    return ($content !== false && $httpCode === 200) ? $content : null;
}

function parseOurAirportsRunways(string $csv): array {
    $lines = str_getcsv($csv, "\n");
    if (count($lines) < 2) return [];
    $header = str_getcsv(array_shift($lines), ',');
    $idx = array_flip(array_map('trim', $header));
    $byAirport = [];
    foreach ($lines as $line) {
        $row = str_getcsv($line, ',');
        if (count($row) < 5) continue;
        $closed = $idx['closed'] ?? null;
        if ($closed !== null && isset($row[$closed]) && (int) $row[$closed] === 1) continue;
        $ident = $idx['airport_ident'] ?? null;
        if ($ident === null) continue;
        $airportId = strtoupper(trim($row[$ident] ?? ''));
        if ($airportId === '') continue;
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
        if ($lat1 === null || $lon1 === null || $lat2 === null || $lon2 === null) continue;
        if (!isset($byAirport[$airportId])) $byAirport[$airportId] = [];
        $byAirport[$airportId][] = [
            'lat1' => $lat1, 'lon1' => $lon1, 'lat2' => $lat2, 'lon2' => $lon2,
            'le_ident' => $leIdent !== null ? trim($row[$leIdent] ?? '') : '',
            'he_ident' => $heIdent !== null ? trim($row[$heIdent] ?? '') : '',
            'source' => 'ourairports',
        ];
    }
    return $byAirport;
}

function parseFaaRunways(string $csv): array {
    $lines = str_getcsv($csv, "\n");
    if (count($lines) < 2) return [];
    $header = str_getcsv(array_shift($lines), ',');
    $idx = array_flip(array_map('trim', $header));
    $arptKey = 'ARPT_ID';
    if (!isset($idx[$arptKey])) return [];
    $lat1Key = 'LAT1_DECIMAL';
    $lon1Key = 'LONG1_DECIMAL';
    $lat2Key = 'LAT2_DECIMAL';
    $lon2Key = 'LONG2_DECIMAL';
    $rwyKey = 'RWY_ID';
    $byAirport = [];
    foreach ($lines as $line) {
        $row = str_getcsv($line, ',');
        if (count($row) < 5) continue;
        $airportId = strtoupper(trim($row[$idx[$arptKey]] ?? ''));
        if ($airportId === '') continue;
        $lat1 = isset($idx[$lat1Key], $row[$idx[$lat1Key]]) && $row[$idx[$lat1Key]] !== '' ? (float) $row[$idx[$lat1Key]] : null;
        $lon1 = isset($idx[$lon1Key], $row[$idx[$lon1Key]]) && $row[$idx[$lon1Key]] !== '' ? (float) $row[$idx[$lon1Key]] : null;
        $lat2 = isset($idx[$lat2Key], $row[$idx[$lat2Key]]) && $row[$idx[$lat2Key]] !== '' ? (float) $row[$idx[$lat2Key]] : null;
        $lon2 = isset($idx[$lon2Key], $row[$idx[$lon2Key]]) && $row[$idx[$lon2Key]] !== '' ? (float) $row[$idx[$lon2Key]] : null;
        if ($lat1 === null || $lon1 === null || $lat2 === null || $lon2 === null) continue;
        $rwyId = isset($idx[$rwyKey], $row[$idx[$rwyKey]]) ? trim($row[$idx[$rwyKey]]) : '';
        $parts = preg_split('#\s*/\s*#', $rwyId, 2);
        $leIdent = trim($parts[0] ?? '');
        $heIdent = trim($parts[1] ?? $parts[0] ?? '');
        if (!isset($byAirport[$airportId])) $byAirport[$airportId] = [];
        $byAirport[$airportId][] = [
            'lat1' => $lat1, 'lon1' => $lon1, 'lat2' => $lat2, 'lon2' => $lon2,
            'le_ident' => $leIdent,
            'he_ident' => $heIdent,
            'source' => 'faa',
        ];
    }
    return $byAirport;
}

function rawRunwaysToLatLonSchema(array $runways): array {
    $out = [];
    foreach ($runways as $rw) {
        $le = $rw['le_ident'] ?? '';
        $he = $rw['he_ident'] ?? '';
        if ($le === '' || $he === '') continue;
        $out[] = [
            'name' => $le . '/' . $he,
            $le => ['lat' => round($rw['lat1'], 6), 'lon' => round($rw['lon1'], 6)],
            $he => ['lat' => round($rw['lat2'], 6), 'lon' => round($rw['lon2'], 6)],
        ];
    }
    return $out;
}

$args = array_slice($argv, 1);
$airportIds = [];
$skipNext = false;
foreach ($args as $i => $arg) {
    if ($skipNext) {
        $skipNext = false;
        continue;
    }
    if ($arg === '--airports' && isset($args[$i + 1])) {
        $airportIds = array_merge($airportIds, array_map('trim', explode(',', $args[$i + 1])));
        $skipNext = true;
        continue;
    }
    if ($arg === '--output' && isset($args[$i + 1])) {
        $skipNext = true;
        continue;
    }
    if ($arg !== '' && $arg[0] !== '-' && strpos($arg, '.') === false) {
        $airportIds[] = $arg;
    }
}
if (empty($airportIds)) {
    $airportIds = ['KPFC', 'KSPB', 'KHIO', 'KBOI', '56S', 'CYAV'];
    echo "No airports specified. Using defaults: " . implode(', ', $airportIds) . "\n\n";
}

echo "Downloading runway data...\n";
$ourairportsCsv = downloadUrl($OURAIRPORTS_RUNWAYS_URL);
$faaCsv = downloadUrl($FAA_RUNWAYS_URL);

if ($ourairportsCsv === null && $faaCsv === null) {
    fwrite(STDERR, "Error: Both FAA and OurAirports downloads failed.\n");
    exit(1);
}

$ourairports = $ourairportsCsv !== null ? parseOurAirportsRunways($ourairportsCsv) : [];
$faa = $faaCsv !== null ? parseFaaRunways($faaCsv) : [];

$identsMap = [
    'kpfc' => ['KPFC', 'PFC'],
    'kspb' => ['KSPB', 'SPB'],
    'khio' => ['HIO', 'KHIO'], // FAA uses HIO; try first for complete runway set (3 runways)
    'kboi' => ['KBOI', 'BOI'],
    '56s' => ['56S'],
    'cyav' => ['CYAV'],
    'kczk' => ['KCZK', 'CZK'],
    'kvkx' => ['KVKX', 'VKX'],
];

$output = [];
foreach ($airportIds as $id) {
    $idLower = strtolower($id);
    $identsToTry = $identsMap[$idLower] ?? [strtoupper($id)];
    $runways = null;
    foreach ($identsToTry as $ident) {
        $runways = !empty($faa[$ident]) ? $faa[$ident] : ($ourairports[$ident] ?? null);
        if ($runways !== null && !empty($runways)) break;
    }
    if (empty($runways)) {
        echo "  $id: No runway data found (tried " . implode(', ', $identsToTry) . ")\n";
        continue;
    }
    $schema = rawRunwaysToLatLonSchema($runways);
    if (empty($schema)) {
        echo "  $id: No valid runways (missing idents)\n";
        continue;
    }
    $output[$id] = $schema;
    echo "  $id: " . count($schema) . " runway(s)\n";
}

$out = "--- Paste into airports.json (replace \"runways\" for each airport) ---\n\n";
$out .= "Full output (all airports):\n";
$out .= json_encode($output, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n\n";
$out .= "--- Per-airport (copy each block into the matching airport) ---\n\n";
foreach ($output as $airportKey => $runways) {
    $out .= "// $airportKey\n\"runways\": " . json_encode($runways, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . ",\n\n";
}

$outFile = null;
foreach ($argv as $i => $arg) {
    if ($arg === '--output' && isset($argv[$i + 1])) {
        $outFile = $argv[$i + 1];
        break;
    }
}
if ($outFile) {
    file_put_contents($outFile, $out);
    echo "Output written to $outFile\n";
} else {
    echo $out;
}
