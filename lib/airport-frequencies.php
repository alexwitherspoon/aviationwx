<?php
/**
 * Airport radio frequency merge and deduplication.
 *
 * Precedence: config -> NASR -> OurAirports per role field.
 *
 * CTAF/UNICOM deduplication follows AIM 4-1-9 / AC 90-66C: CTAF is the traffic-advisory
 * role and may be implemented on a UNICOM or MULTICOM frequency. When both roles share
 * the same MHz value, only CTAF is shown so pilots see one traffic frequency.
 */

require_once __DIR__ . '/airport-ourairports.php';
require_once __DIR__ . '/cache-paths.php';
require_once __DIR__ . '/nasr/frequencies-cache.php';

/** @var array|null In-request memo for OurAirports frequencies cache */
$GLOBALS['_ourairports_frequencies_cache_memo'] = null;

/**
 * Pilot-facing frequency roles in dashboard display order.
 *
 * @return list<string>
 */
function airportFrequencyRoleDisplayOrder(): array
{
    return [
        'tower',
        'ground',
        'ctaf',
        'unicom',
        'atis',
        'approach',
        'departure',
        'clearance',
        'asos',
        'awos',
    ];
}

/**
 * Normalize a MHz string for comparison and display.
 *
 * @param mixed $mhz
 */
function normalizeAviationFrequencyMhz($mhz): ?string
{
    if ($mhz === null) {
        return null;
    }

    if (is_string($mhz)) {
        $mhz = trim($mhz);
        if ($mhz === '') {
            return null;
        }
    }

    if (!is_numeric($mhz)) {
        return null;
    }

    $value = round((float) $mhz, 3);
    if ($value < 118.0 || $value > 136.975) {
        return null;
    }

    $formatted = rtrim(rtrim(number_format($value, 3, '.', ''), '0'), '.');

    return $formatted !== '' ? $formatted : null;
}

/**
 * Map OurAirports airport-frequencies.csv type to platform role keys.
 *
 * @param string $type OurAirports type column (CTAF, UNIC, TWR, ...)
 */
function mapOurAirportsFrequencyTypeToRole(string $type): ?string
{
    $type = strtoupper(trim($type));

    return match ($type) {
        'CTAF' => 'ctaf',
        'UNIC' => 'unicom',
        'TWR' => 'tower',
        'GND' => 'ground',
        'ATIS' => 'atis',
        'APP' => 'approach',
        'DEP' => 'departure',
        'CLD' => 'clearance',
        'ASOS' => 'asos',
        'AWOS' => 'awos',
        default => null,
    };
}

/**
 * Collapse duplicate roles that share the same MHz value.
 *
 * Rules (AIM 4-1-9 / AC 90-66C):
 * - CTAF may be a UNICOM frequency: when equal, keep CTAF only.
 * - Towered airports publish the same MHz as CTAF when the tower is closed: when equal, keep tower.
 *
 * @param array<string, string> $frequencies Role => MHz
 * @return array<string, string>
 */
function collapseDuplicateAirportFrequencyRoles(array $frequencies): array
{
    $normalized = [];
    foreach ($frequencies as $role => $mhz) {
        if (!is_string($role) || $role === '') {
            continue;
        }
        $value = normalizeAviationFrequencyMhz($mhz);
        if ($value === null) {
            continue;
        }
        $normalized[$role] = $value;
    }

    if (isset($normalized['ctaf'], $normalized['unicom'])
        && $normalized['ctaf'] === $normalized['unicom']) {
        unset($normalized['unicom']);
    }

    if (isset($normalized['ctaf'], $normalized['tower'])
        && $normalized['ctaf'] === $normalized['tower']) {
        unset($normalized['ctaf']);
    }

    return $normalized;
}

/**
 * Merge frequency maps with per-field precedence (first source wins per role).
 *
 * @param list<array<string, string>> $sources Ordered sources (highest precedence first)
 * @return array<string, string>
 */
function mergeAirportFrequencySources(array $sources): array
{
    $merged = [];

    foreach ($sources as $source) {
        if (!is_array($source)) {
            continue;
        }
        foreach ($source as $role => $mhz) {
            if (!is_string($role) || $role === '' || isset($merged[$role])) {
                continue;
            }
            $value = normalizeAviationFrequencyMhz($mhz);
            if ($value !== null) {
                $merged[$role] = $value;
            }
        }
    }

    $merged = collapseDuplicateAirportFrequencyRoles($merged);

    return sortAirportFrequencyRoles($merged);
}

/**
 * Sort frequency roles for stable dashboard/API output.
 *
 * @param array<string, string> $frequencies
 * @return array<string, string>
 */
function sortAirportFrequencyRoles(array $frequencies): array
{
    $order = airportFrequencyRoleDisplayOrder();
    $rank = array_flip($order);

    uksort($frequencies, static function (string $a, string $b) use ($rank): int {
        $ra = $rank[$a] ?? PHP_INT_MAX;
        $rb = $rank[$b] ?? PHP_INT_MAX;
        if ($ra !== $rb) {
            return $ra <=> $rb;
        }

        return strcmp($a, $b);
    });

    return $frequencies;
}

/**
 * Load OurAirports frequencies cache from disk (memoized per request).
 *
 * @return array<string, array<string, string>>|null airport_ident => role map
 */
function loadOurAirportsFrequenciesCache(): ?array
{
    if (is_array($GLOBALS['_ourairports_frequencies_cache_memo'])) {
        return $GLOBALS['_ourairports_frequencies_cache_memo'];
    }

    if (ourAirportsFrequenciesCacheIsStale() && is_readable(CACHE_OURAIRPORTS_FREQUENCIES_CSV)) {
        require_once __DIR__ . '/ourairports/ingest-airports.php';
        ingestOurAirportsFrequenciesFromDisk();
    }

    if (!is_readable(CACHE_OURAIRPORTS_FREQUENCIES_FILE)) {
        $GLOBALS['_ourairports_frequencies_cache_memo'] = null;
        return null;
    }

    $decoded = json_decode((string) file_get_contents(CACHE_OURAIRPORTS_FREQUENCIES_FILE), true);
    if (!is_array($decoded) || !isset($decoded['airports']) || !is_array($decoded['airports'])) {
        $GLOBALS['_ourairports_frequencies_cache_memo'] = null;
        return null;
    }

    $GLOBALS['_ourairports_frequencies_cache_memo'] = $decoded['airports'];

    return $decoded['airports'];
}

/**
 * Resolve OurAirports frequency map for an airport config row.
 *
 * @param string $airportId Config slug
 * @param array $airport Airport configuration
 * @return array<string, string>
 */
function getOurAirportsFrequenciesForAirport(string $airportId, array $airport): array
{
    $cache = loadOurAirportsFrequenciesCache();
    if ($cache === null) {
        return [];
    }

    require_once __DIR__ . '/airport-ourairports.php';
    $idents = ourAirportsCacheLookupIdentsForAirport($airportId, $airport);

    foreach ($idents as $ident) {
        if (isset($cache[$ident]) && is_array($cache[$ident]) && $cache[$ident] !== []) {
            return $cache[$ident];
        }
    }

    return [];
}

/**
 * Build merged airport frequencies for dashboard and API consumers.
 *
 * @param string $airportId Config slug
 * @param array $airport Airport configuration
 * @return array<string, string> Role => MHz
 */
function getMergedAirportFrequencies(string $airportId, array $airport): array
{
    $configFreqs = [];
    if (isset($airport['frequencies']) && is_array($airport['frequencies'])) {
        foreach ($airport['frequencies'] as $role => $mhz) {
            if (!is_string($role) || $role === '') {
                continue;
            }
            $value = normalizeAviationFrequencyMhz($mhz);
            if ($value !== null) {
                $configFreqs[$role] = $value;
            }
        }
    }

    $nasrFreqs = getNasrFrequenciesForConfig($airport);
    $ourAirportsFreqs = getOurAirportsFrequenciesForAirport($airportId, $airport);

    return mergeAirportFrequencySources([$configFreqs, $nasrFreqs, $ourAirportsFreqs]);
}

/**
 * Parse OurAirports airport-frequencies.csv into cache payload.
 *
 * @param string $csv Raw CSV body
 * @return array<string, array<string, string>>
 */
function parseOurAirportsFrequenciesCsv(string $csv): array
{
    $handle = fopen('php://memory', 'rb+');
    if ($handle === false) {
        return [];
    }

    fwrite($handle, $csv);
    rewind($handle);

    $header = fgetcsv($handle, 0, ',', '"', '\\');
    if ($header === false) {
        fclose($handle);
        return [];
    }

    $header = array_map(static fn ($col) => trim((string) $col), $header);
    $airports = [];

    while (($data = fgetcsv($handle, 0, ',', '"', '\\')) !== false) {
        if ($data === [null] || $data === false) {
            continue;
        }

        $row = [];
        foreach ($header as $i => $col) {
            $row[$col] = $data[$i] ?? '';
        }

        $ident = strtoupper(trim((string) ($row['airport_ident'] ?? '')));
        if ($ident === '') {
            continue;
        }

        $role = mapOurAirportsFrequencyTypeToRole((string) ($row['type'] ?? ''));
        if ($role === null) {
            continue;
        }

        $mhz = normalizeAviationFrequencyMhz($row['frequency_mhz'] ?? null);
        if ($mhz === null) {
            continue;
        }

        if (!isset($airports[$ident])) {
            $airports[$ident] = [];
        }

        if (!isset($airports[$ident][$role])) {
            $airports[$ident][$role] = $mhz;
        }
    }

    fclose($handle);

    foreach ($airports as $ident => $roles) {
        $airports[$ident] = collapseDuplicateAirportFrequencyRoles($roles);
    }

    return $airports;
}

/**
 * Clear in-request OurAirports frequencies memo (testing).
 */
function resetOurAirportsFrequenciesCacheMemo(): void
{
    $GLOBALS['_ourairports_frequencies_cache_memo'] = null;
}

/**
 * Inject OurAirports frequencies cache for unit tests.
 *
 * @param array<string, array<string, string>>|null $airports
 */
function setOurAirportsFrequenciesCacheForTesting(?array $airports): void
{
    $GLOBALS['_ourairports_frequencies_cache_memo'] = $airports;
}
