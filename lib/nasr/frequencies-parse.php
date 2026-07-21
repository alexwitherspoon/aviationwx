<?php
/**
 * Parse FAA NASR FRQ.csv into pilot-facing airport frequency roles.
 */

require_once __DIR__ . '/parse.php';

/** @var int Primary NASR FREQ_USE mappings (LCL/P, APCH/P, CD/P, ASOS/AWOS patterns, ...) */
const NASR_FREQ_MAP_TIER_PRIMARY = 0;

/** @var int Secondary NASR FREQ_USE mappings (LCL/S, GND/S, CD PRE *, ...) */
const NASR_FREQ_MAP_TIER_SECONDARY = 1;

/**
 * Map NASR FRQ FREQ_USE to platform frequency roles and mapping tier.
 *
 * Primary rows win over secondary rows for the same role. Instrument-approach backup
 * rows (FREQ_USE ending in " IC") and non-pilot-facing uses are ignored.
 *
 * @param string $freqUse NASR FREQ_USE column
 * @return array{roles: list<string>, tier: int}|null
 */
function nasrDescribeFreqUseMapping(string $freqUse): ?array
{
    $use = strtoupper(trim($freqUse));
    if ($use === '') {
        return null;
    }

    if (preg_match('/\sIC$/', $use) === 1) {
        return null;
    }

    $primary = NASR_FREQ_MAP_TIER_PRIMARY;
    $secondary = NASR_FREQ_MAP_TIER_SECONDARY;

    $exact = match ($use) {
        'CTAF' => ['roles' => ['ctaf'], 'tier' => $primary],
        'UNICOM' => ['roles' => ['unicom'], 'tier' => $primary],
        'LCL/P' => ['roles' => ['tower'], 'tier' => $primary],
        'GND/P' => ['roles' => ['ground'], 'tier' => $primary],
        'ATIS', 'D-ATIS' => ['roles' => ['atis'], 'tier' => $primary],
        'APCH/P DEP/P' => ['roles' => ['approach', 'departure'], 'tier' => $primary],
        'APCH/P' => ['roles' => ['approach'], 'tier' => $primary],
        'DEP/P' => ['roles' => ['departure'], 'tier' => $primary],
        'CD/P' => ['roles' => ['clearance'], 'tier' => $primary],
        'LCL/S' => ['roles' => ['tower'], 'tier' => $secondary],
        'GND/S' => ['roles' => ['ground'], 'tier' => $secondary],
        'APCH/S DEP/S' => ['roles' => ['approach', 'departure'], 'tier' => $secondary],
        'APCH/S' => ['roles' => ['approach'], 'tier' => $secondary],
        'DEP/S' => ['roles' => ['departure'], 'tier' => $secondary],
        'CD PRE TAXI CLNC', 'CD PRE DEP CLNC' => ['roles' => ['clearance'], 'tier' => $secondary],
        default => null,
    };

    if ($exact !== null) {
        return $exact;
    }

    if (preg_match('/\bASOS\b/', $use) === 1) {
        return ['roles' => ['asos'], 'tier' => $primary];
    }

    if (str_contains($use, 'AWOS')) {
        return ['roles' => ['awos'], 'tier' => $primary];
    }

    return null;
}

/**
 * Whether a MHz value is a pilot-usable VHF comm frequency for GA display.
 *
 * @param mixed $mhz
 */
function nasrFrequencyMhzIsDisplayableVhf($mhz): bool
{
    if ($mhz === null || $mhz === '') {
        return false;
    }

    $value = (float) $mhz;

    return $value >= 118.0 && $value <= 136.975;
}

/**
 * Format NASR FREQ column as a MHz string for display and merge.
 *
 * @param mixed $mhz
 */
function nasrFormatFrequencyMhz($mhz): ?string
{
    if (!nasrFrequencyMhzIsDisplayableVhf($mhz)) {
        return null;
    }

    $value = round((float) $mhz, 3);
    $formatted = number_format($value, 3, '.', '');
    $formatted = rtrim(rtrim($formatted, '0'), '.');

    return $formatted !== '' ? $formatted : null;
}

/**
 * Apply one FRQ.csv row to the airports map when its mapping tier is allowed.
 *
 * @param array<string, array<string, string>> $airports
 * @param array<string, string> $row
 */
function nasrApplyFrqRowToAirports(array &$airports, array $row, int $maxTier): void
{
    $arptId = strtoupper(trim((string) ($row['SERVICED_FACILITY'] ?? '')));
    if ($arptId === '') {
        return;
    }

    $mapping = nasrDescribeFreqUseMapping((string) ($row['FREQ_USE'] ?? ''));
    if ($mapping === null || $mapping['tier'] > $maxTier) {
        return;
    }

    $mhz = nasrFormatFrequencyMhz($row['FREQ'] ?? null);
    if ($mhz === null) {
        return;
    }

    if (!isset($airports[$arptId])) {
        $airports[$arptId] = [];
    }

    foreach ($mapping['roles'] as $role) {
        if (!isset($airports[$arptId][$role])) {
            $airports[$arptId][$role] = $mhz;
        }
    }
}

/**
 * Parse NASR FRQ.csv into airports keyed by SERVICED_FACILITY (ARPT_ID).
 *
 * @param string $csvPath Path to FRQ.csv
 * @return array{airports: array<string, array<string, string>>, effective_date: ?string}
 */
function nasrParseFrqCsvFile(string $csvPath): array
{
    if (!is_readable($csvPath)) {
        throw new RuntimeException('NASR FRQ CSV missing or unreadable: ' . $csvPath);
    }

    $effectiveDate = null;
    $airports = [];

    foreach (nasrIterateCsvFile($csvPath) as $row) {
        $effectiveDate = $effectiveDate ?? nasrNormalizeEffectiveDate($row['EFF_DATE'] ?? null);
        nasrApplyFrqRowToAirports($airports, $row, NASR_FREQ_MAP_TIER_PRIMARY);
    }

    foreach (nasrIterateCsvFile($csvPath) as $row) {
        nasrApplyFrqRowToAirports($airports, $row, NASR_FREQ_MAP_TIER_SECONDARY);
    }

    return [
        'airports' => $airports,
        'effective_date' => $effectiveDate,
    ];
}
