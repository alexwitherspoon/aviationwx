<?php
/**
 * Parse FAA NASR FRQ.csv into pilot-facing airport frequency roles.
 */

require_once __DIR__ . '/parse.php';

/**
 * Map NASR FRQ FREQ_USE to platform frequency role keys.
 *
 * @param string $freqUse NASR FREQ_USE column
 * @return string|null Role key (ctaf, tower, ground, atis, unicom) or null when not surfaced
 */
function nasrMapFreqUseToRole(string $freqUse): ?string
{
    $use = strtoupper(trim($freqUse));

    return match ($use) {
        'CTAF' => 'ctaf',
        'UNICOM' => 'unicom',
        'LCL/P' => 'tower',
        'GND/P' => 'ground',
        'ATIS', 'D-ATIS' => 'atis',
        default => null,
    };
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

        $arptId = strtoupper(trim((string) ($row['SERVICED_FACILITY'] ?? '')));
        if ($arptId === '') {
            continue;
        }

        $role = nasrMapFreqUseToRole((string) ($row['FREQ_USE'] ?? ''));
        if ($role === null) {
            continue;
        }

        $mhz = nasrFormatFrequencyMhz($row['FREQ'] ?? null);
        if ($mhz === null) {
            continue;
        }

        if (!isset($airports[$arptId])) {
            $airports[$arptId] = [];
        }

        // First VHF row per role wins (e.g. skip secondary LCL/S when LCL/P already set).
        if (!isset($airports[$arptId][$role])) {
            $airports[$arptId][$role] = $mhz;
        }
    }

    return [
        'airports' => $airports,
        'effective_date' => $effectiveDate,
    ];
}
