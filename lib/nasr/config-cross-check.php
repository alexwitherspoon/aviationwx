<?php
/**
 * NASR cross-check warnings for airports.json metadata (config-check only).
 *
 * Compares per-airport elevation_ft and magnetic_declination against NASR when a row
 * exists. Warnings only — never mutates config or API responses.
 */

require_once __DIR__ . '/../constants.php';
require_once __DIR__ . '/cache.php';

/**
 * Cross-check configured airport metadata against NASR APT cache.
 *
 * @param array $config Loaded airports.json config
 * @return array{
 *   warnings: list<string>,
 *   summary: array{
 *     checked: int,
 *     skipped_no_nasr: int,
 *     skipped_no_config_field: int,
 *     elevation_warnings: int,
 *     magnetic_warnings: int
 *   }
 * }
 */
function nasrCrossCheckAirportConfig(array $config): array
{
    $warnings = [];
    $summary = [
        'checked' => 0,
        'skipped_no_nasr' => 0,
        'skipped_no_config_field' => 0,
        'elevation_warnings' => 0,
        'magnetic_warnings' => 0,
    ];

    if (loadNasrAptCache() === null) {
        return ['warnings' => $warnings, 'summary' => $summary];
    }

    foreach ($config['airports'] ?? [] as $airportKey => $airport) {
        if (!is_array($airport)) {
            continue;
        }

        $row = $airport;
        if (empty($row['id']) && is_string($airportKey) && $airportKey !== '') {
            $row['id'] = $airportKey;
        }

        $nasrRecord = getNasrAirportForConfig($row);
        if ($nasrRecord === null) {
            $summary['skipped_no_nasr']++;
            continue;
        }

        $summary['checked']++;
        $label = nasrCrossCheckAirportLabel($airportKey, $row);

        $elevationWarning = nasrCrossCheckElevationFt($label, $row, $nasrRecord);
        if ($elevationWarning !== null) {
            $warnings[] = $elevationWarning;
            $summary['elevation_warnings']++;
        } elseif (!isset($row['elevation_ft']) || !is_numeric($row['elevation_ft'])) {
            $summary['skipped_no_config_field']++;
        }

        $magneticWarning = nasrCrossCheckMagneticDeclination($label, $row, $nasrRecord);
        if ($magneticWarning !== null) {
            $warnings[] = $magneticWarning;
            $summary['magnetic_warnings']++;
        } elseif (!array_key_exists('magnetic_declination', $row) || !is_numeric($row['magnetic_declination'])) {
            $summary['skipped_no_config_field']++;
        }
    }

    return ['warnings' => $warnings, 'summary' => $summary];
}

/**
 * @param array<string, mixed> $airport
 */
function nasrCrossCheckAirportLabel(string $airportKey, array $airport): string
{
    $faa = $airport['faa'] ?? null;
    if (is_string($faa) && $faa !== '') {
        return "Airport '{$airportKey}' ({$faa})";
    }

    $icao = $airport['icao'] ?? null;
    if (is_string($icao) && $icao !== '') {
        return "Airport '{$airportKey}' ({$icao})";
    }

    return "Airport '{$airportKey}'";
}

/**
 * @param array<string, mixed> $airport
 * @param array<string, mixed> $nasrRecord
 */
function nasrCrossCheckHasElevationComparison(array $airport, array $nasrRecord): bool
{
    return isset($airport['elevation_ft'])
        && is_numeric($airport['elevation_ft'])
        && isset($nasrRecord['elev_ft'])
        && is_numeric($nasrRecord['elev_ft']);
}

/**
 * @param array<string, mixed> $airport
 * @param array<string, mixed> $nasrRecord
 */
function nasrCrossCheckHasMagneticComparison(array $airport, array $nasrRecord): bool
{
    return array_key_exists('magnetic_declination', $airport)
        && is_numeric($airport['magnetic_declination'])
        && isset($nasrRecord['mag_declination_deg'])
        && is_numeric($nasrRecord['mag_declination_deg']);
}

/**
 * @param array<string, mixed> $airport
 * @param array<string, mixed> $nasrRecord
 */
function nasrCrossCheckElevationFt(string $label, array $airport, array $nasrRecord): ?string
{
    if (!nasrCrossCheckHasElevationComparison($airport, $nasrRecord)) {
        return null;
    }

    $configFt = (int) round((float) $airport['elevation_ft']);
    $nasrFt = (int) $nasrRecord['elev_ft'];
    $delta = abs($configFt - $nasrFt);
    $tolerance = NASR_CONFIG_ELEVATION_TOLERANCE_FT;

    if ($delta <= $tolerance) {
        return null;
    }

    return "{$label}: elevation_ft {$configFt} differs from NASR {$nasrFt} by {$delta} ft (tolerance ±{$tolerance} ft)";
}

/**
 * @param array<string, mixed> $airport
 * @param array<string, mixed> $nasrRecord
 */
function nasrCrossCheckMagneticDeclination(string $label, array $airport, array $nasrRecord): ?string
{
    if (!nasrCrossCheckHasMagneticComparison($airport, $nasrRecord)) {
        return null;
    }

    $configDeg = (float) $airport['magnetic_declination'];
    $nasrDeg = (float) $nasrRecord['mag_declination_deg'];
    $delta = abs($configDeg - $nasrDeg);
    $tolerance = NASR_CONFIG_MAGNETIC_TOLERANCE_DEG;

    if ($delta < $tolerance) {
        return null;
    }

    $nasrLabel = nasrFormatMagneticDeclinationLabel($nasrDeg, $nasrRecord['mag_declination_year'] ?? null);
    $deltaFormatted = rtrim(rtrim(number_format($delta, 1, '.', ''), '0'), '.');

    return "{$label}: magnetic_declination {$configDeg} differs from NASR {$nasrLabel} by {$deltaFormatted}° (tolerance ±{$tolerance}°)";
}

/**
 * @param mixed $year
 */
function nasrFormatMagneticDeclinationLabel(float $degrees, $year): string
{
    $hemisphere = $degrees >= 0 ? 'E' : 'W';
    $abs = rtrim(rtrim(number_format(abs($degrees), 1, '.', ''), '0'), '.');
    $label = "{$abs}°{$hemisphere}";
    if ($year !== null && $year !== '' && is_numeric($year)) {
        $label .= ' (year ' . (int) $year . ')';
    }

    return $label;
}
