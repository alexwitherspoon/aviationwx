<?php

/**
 * Ingest OurAirports airports.csv into identity JSON cache.
 */

require_once __DIR__ . '/../cache-paths.php';
require_once __DIR__ . '/../logger.php';
require_once __DIR__ . '/urls.php';

/**
 * Parse airports.csv content into ICAO/IATA/FAA code lists.
 *
 * @return array{icao: list<string>, iata: list<string>, faa: list<string>}|null
 */
function ourAirportsParseAirportsCsvIdentity(string $csvContent): ?array
{
    $icaoCodes = [];
    $iataCodes = [];
    $faaCodes = [];

    $handle = fopen('php://memory', 'rb+');
    if ($handle === false) {
        return null;
    }

    fwrite($handle, $csvContent);
    rewind($handle);

    $header = fgetcsv($handle, 0, ',', '"', '\\');
    if ($header === false) {
        fclose($handle);
        return null;
    }

    $header = array_map(static fn ($col) => trim((string) $col), $header);
    $icaoIdx = array_search('icao_code', $header, true);
    $iataIdx = array_search('iata_code', $header, true);
    $gpsIdx = array_search('gps_code', $header, true);

    while (($row = fgetcsv($handle, 0, ',', '"', '\\')) !== false) {
        if ($row === [null]) {
            continue;
        }

        if ($icaoIdx !== false && isset($row[$icaoIdx]) && $row[$icaoIdx] !== '') {
            $icao = strtoupper(trim((string) $row[$icaoIdx]));
            if (preg_match('/^[A-Z0-9]{3,4}$/', $icao)) {
                $icaoCodes[$icao] = true;
            }
        }

        if ($iataIdx !== false && isset($row[$iataIdx]) && $row[$iataIdx] !== '') {
            $iata = strtoupper(trim((string) $row[$iataIdx]));
            if (preg_match('/^[A-Z]{3}$/', $iata)) {
                $iataCodes[$iata] = true;
            }
        }

        if ($gpsIdx !== false && isset($row[$gpsIdx]) && $row[$gpsIdx] !== '') {
            $faa = strtoupper(trim((string) $row[$gpsIdx]));
            if (preg_match('/^[A-Z0-9]{3,4}$/', $faa)) {
                $faaCodes[$faa] = true;
            }
        }
    }

    fclose($handle);

    return [
        'icao' => array_keys($icaoCodes),
        'iata' => array_keys($iataCodes),
        'faa' => array_keys($faaCodes),
    ];
}

/**
 * Build identity JSON from raw airports.csv on disk.
 */
function ingestOurAirportsIdentityFromDisk(): bool
{
    $path = ourAirportsCsvPath('airports');
    if (!is_readable($path)) {
        return false;
    }

    $csvContent = @file_get_contents($path);
    if ($csvContent === false || $csvContent === '') {
        return false;
    }

    $result = ourAirportsParseAirportsCsvIdentity($csvContent);
    if ($result === null) {
        return false;
    }

    ensureCacheDir(CACHE_BASE_DIR);
    $tmp = CACHE_OURAIRPORTS_FILE . '.tmp.' . getmypid();
    $json = json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    if ($json === false) {
        return false;
    }

    if (@file_put_contents($tmp, $json, LOCK_EX) === false || !@rename($tmp, CACHE_OURAIRPORTS_FILE)) {
        @unlink($tmp);
        return false;
    }

    aviationwx_log('info', 'OurAirports identity cache ingested from disk', [
        'icao_count' => count($result['icao']),
        'iata_count' => count($result['iata']),
        'faa_count' => count($result['faa']),
    ], 'app');

    return true;
}

/**
 * Build OurAirports frequencies JSON from raw airport-frequencies.csv on disk.
 */
function ingestOurAirportsFrequenciesFromDisk(): bool
{
    require_once __DIR__ . '/../airport-frequencies.php';

    $path = ourAirportsCsvPath('airport_frequencies');
    if (!is_readable($path)) {
        return false;
    }

    $csvContent = @file_get_contents($path);
    if ($csvContent === false || $csvContent === '') {
        return false;
    }

    $freqAirports = parseOurAirportsFrequenciesCsv($csvContent);
    $payload = [
        'schema_version' => 1,
        'fetched_at' => gmdate('c'),
        'airports' => $freqAirports,
    ];

    ensureCacheDir(CACHE_BASE_DIR);
    $tmp = CACHE_OURAIRPORTS_FREQUENCIES_FILE . '.tmp.' . getmypid();
    $json = json_encode($payload, JSON_UNESCAPED_SLASHES);
    if ($json === false) {
        return false;
    }

    if (@file_put_contents($tmp, $json, LOCK_EX) === false || !@rename($tmp, CACHE_OURAIRPORTS_FREQUENCIES_FILE)) {
        @unlink($tmp);
        return false;
    }

    resetOurAirportsFrequenciesCacheMemo();

    aviationwx_log('info', 'OurAirports frequencies cache ingested from disk', [
        'airports' => count($freqAirports),
    ], 'app');

    return true;
}

/**
 * True when identity JSON is older than airports.csv on disk.
 */
function ourAirportsIdentityCacheIsStale(): bool
{
    if (!is_readable(CACHE_OURAIRPORTS_AIRPORTS_CSV)) {
        return false;
    }

    if (!is_readable(CACHE_OURAIRPORTS_FILE)) {
        return true;
    }

    return (int) filemtime(CACHE_OURAIRPORTS_AIRPORTS_CSV) > (int) filemtime(CACHE_OURAIRPORTS_FILE);
}

/**
 * True when frequencies JSON is older than airport-frequencies.csv on disk.
 */
function ourAirportsFrequenciesCacheIsStale(): bool
{
    if (!is_readable(CACHE_OURAIRPORTS_FREQUENCIES_CSV)) {
        return false;
    }

    if (!is_readable(CACHE_OURAIRPORTS_FREQUENCIES_FILE)) {
        return true;
    }

    return (int) filemtime(CACHE_OURAIRPORTS_FREQUENCIES_CSV) > (int) filemtime(CACHE_OURAIRPORTS_FREQUENCIES_FILE);
}

/**
 * Parse airports.csv centers and FAA->ICAO mapping from disk.
 *
 * @return array{centers: array<string, array{lat: float, lon: float}>, faa_to_icao: array<string, string>}
 */
function ourAirportsLoadAirportCentersAndFaaMappingFromDisk(): array
{
    $path = ourAirportsCsvPath('airports');
    $centers = [];
    $faaToIcao = [];

    if (!is_readable($path)) {
        return ['centers' => $centers, 'faa_to_icao' => $faaToIcao];
    }

    $csv = @file_get_contents($path);
    if ($csv === false || $csv === '') {
        return ['centers' => $centers, 'faa_to_icao' => $faaToIcao];
    }

    $lines = str_getcsv($csv, "\n", '"', '\\');
    if ($lines === [] || count($lines) < 2) {
        return ['centers' => $centers, 'faa_to_icao' => $faaToIcao];
    }

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

    if ($identIdx === null || $latIdx === null || $lonIdx === null) {
        return ['centers' => $centers, 'faa_to_icao' => $faaToIcao];
    }

    foreach ($lines as $line) {
        $row = str_getcsv($line, ',', '"', '\\');
        if (count($row) <= max($identIdx, $latIdx, $lonIdx)) {
            continue;
        }
        $id = strtoupper(trim($row[$identIdx] ?? ''));
        $lat = $row[$latIdx] ?? '';
        $lon = $row[$lonIdx] ?? '';
        if ($id !== '' && $lat !== '' && $lon !== '') {
            $centers[$id] = ['lat' => (float) $lat, 'lon' => (float) $lon];
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

    return ['centers' => $centers, 'faa_to_icao' => $faaToIcao];
}
