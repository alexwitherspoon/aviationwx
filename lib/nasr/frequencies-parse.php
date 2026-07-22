<?php
/**
 * Parse FAA NASR FRQ.csv into pilot-facing airport frequency roles.
 */

require_once __DIR__ . '/parse.php';

/** @var int Primary NASR FREQ_USE mappings (LCL/P, APCH/P, CD/P, ASOS/AWOS patterns, ...) */
const NASR_FREQ_MAP_TIER_PRIMARY = 0;

/** @var int Initial-contact NASR rows (LCL/P IC, APCH/P IC, ...) fill roles still empty after primary */
const NASR_FREQ_MAP_TIER_IC_FALLBACK = 1;

/** @var int Secondary NASR FREQ_USE mappings (LCL/S, GND/S, CD PRE *, ...) */
const NASR_FREQ_MAP_TIER_SECONDARY = 2;

/**
 * Map NASR FRQ FREQ_USE to platform frequency roles and mapping tier.
 *
 * Primary (/P) rows apply first, then Initial Contact (IC) fallbacks, then secondary (/S).
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

    $primary = NASR_FREQ_MAP_TIER_PRIMARY;
    $icFallback = NASR_FREQ_MAP_TIER_IC_FALLBACK;
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
        'LCL/P IC' => ['roles' => ['tower'], 'tier' => $icFallback],
        'APCH/P DEP/P IC' => ['roles' => ['approach', 'departure'], 'tier' => $icFallback],
        'APCH/P IC' => ['roles' => ['approach'], 'tier' => $icFallback],
        'DEP/P IC' => ['roles' => ['departure'], 'tier' => $icFallback],
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
 * @param array<string, array{primary: ?string, ic: ?string}> $approachDepartureSources
 */
function nasrRecordApproachDepartureSource(
    array &$approachDepartureSources,
    string $arptId,
    string $role,
    string $mhz,
    int $tier
): void {
    if ($role !== 'approach' && $role !== 'departure') {
        return;
    }

    if (!isset($approachDepartureSources[$arptId][$role])) {
        $approachDepartureSources[$arptId][$role] = ['primary' => null, 'ic' => null];
    }

    if ($tier === NASR_FREQ_MAP_TIER_PRIMARY) {
        $approachDepartureSources[$arptId][$role]['primary'] = $mhz;
    } elseif ($tier === NASR_FREQ_MAP_TIER_IC_FALLBACK) {
        $approachDepartureSources[$arptId][$role]['ic'] = $mhz;
    }
}

/**
 * Apply one FRQ.csv row to the airports map when its mapping tier is allowed.
 *
 * @param array<string, array<string, string>> $airports
 * @param array<string, array{primary: ?string, ic: ?string}> $approachDepartureSources
 * @param array<string, string> $row
 */
function nasrApplyFrqRowToAirports(
    array &$airports,
    array &$approachDepartureSources,
    array $row,
    int $maxTier
): void {
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
        nasrRecordApproachDepartureSource($approachDepartureSources, $arptId, $role, $mhz, $mapping['tier']);

        if (!isset($airports[$arptId][$role])) {
            $airports[$arptId][$role] = $mhz;
        }
    }
}

/**
 * Prefer Initial Contact MHz for approach/departure when both IC and primary rows exist.
 *
 * @param array<string, array<string, string>> $airports
 * @param array<string, array<string, array{primary: ?string, ic: ?string}>> $approachDepartureSources
 */
function nasrApplyApproachDepartureInitialContactPreference(
    array &$airports,
    array $approachDepartureSources
): void {
    foreach ($approachDepartureSources as $arptId => $roles) {
        foreach ($roles as $role => $sources) {
            $primary = $sources['primary'] ?? null;
            $ic = $sources['ic'] ?? null;
            if ($primary === null || $ic === null || $primary === $ic) {
                continue;
            }

            if (!isset($airports[$arptId])) {
                $airports[$arptId] = [];
            }

            $airports[$arptId][$role] = $ic;
        }
    }
}

/**
 * Build NASR pairing metadata for merge-time companion-role suppression.
 *
 * @param array<string, string> $roles
 * @return array<string, string>
 */
function nasrBuildFrequencyPairingMetadata(array $roles): array
{
    $pairing = [];

    if (isset($roles['ctaf'], $roles['unicom']) && $roles['ctaf'] === $roles['unicom']) {
        $pairing['ctaf_unicom_mhz'] = $roles['ctaf'];
    }

    if (isset($roles['tower'], $roles['ctaf']) && $roles['tower'] === $roles['ctaf']) {
        $pairing['tower_ctaf_mhz'] = $roles['tower'];
    }

    return $pairing;
}

/**
 * @param array<string, array<string, string>> $airports
 * @return array<string, array<string, string>>
 */
function nasrBuildFrequencyPairingIndex(array $airports): array
{
    $pairing = [];

    foreach ($airports as $arptId => $roles) {
        $meta = nasrBuildFrequencyPairingMetadata($roles);
        if ($meta !== []) {
            $pairing[$arptId] = $meta;
        }
    }

    return $pairing;
}

/**
 * Parse NASR FRQ.csv into airports keyed by SERVICED_FACILITY (ARPT_ID).
 *
 * @param string $csvPath Path to FRQ.csv
 * @return array{
 *   airports: array<string, array<string, string>>,
 *   pairing: array<string, array<string, string>>,
 *   effective_date: ?string
 * }
 */
function nasrParseFrqCsvFile(string $csvPath): array
{
    if (!is_readable($csvPath)) {
        throw new RuntimeException('NASR FRQ CSV missing or unreadable: ' . $csvPath);
    }

    $effectiveDate = null;
    $airports = [];
    $approachDepartureSources = [];

    foreach (nasrIterateCsvFile($csvPath) as $row) {
        $effectiveDate = $effectiveDate ?? nasrNormalizeEffectiveDate($row['EFF_DATE'] ?? null);
        nasrApplyFrqRowToAirports($airports, $approachDepartureSources, $row, NASR_FREQ_MAP_TIER_PRIMARY);
    }

    foreach (nasrIterateCsvFile($csvPath) as $row) {
        nasrApplyFrqRowToAirports($airports, $approachDepartureSources, $row, NASR_FREQ_MAP_TIER_IC_FALLBACK);
    }

    foreach (nasrIterateCsvFile($csvPath) as $row) {
        nasrApplyFrqRowToAirports($airports, $approachDepartureSources, $row, NASR_FREQ_MAP_TIER_SECONDARY);
    }

    nasrApplyApproachDepartureInitialContactPreference($airports, $approachDepartureSources);

    return [
        'airports' => $airports,
        'pairing' => nasrBuildFrequencyPairingIndex($airports),
        'effective_date' => $effectiveDate,
    ];
}
