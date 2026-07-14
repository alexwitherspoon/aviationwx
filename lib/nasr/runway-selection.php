<?php
/**
 * Longest active land runway selection for performance attention.
 */

/**
 * Surface codes treated as non-paved for POH grass correction.
 */
function nasrIsNonPavedSurface(string $surface): bool
{
    $surface = strtoupper($surface);
    foreach (['TURF', 'DIRT', 'GRVL', 'GRASS', 'SOD', 'CLAY'] as $code) {
        if (str_contains($surface, $code)) {
            return true;
        }
    }
    return false;
}

/**
 * Whether a NASR runway row is eligible for performance runway selection.
 *
 * @param array $runway Parsed runway record
 */
function nasrRunwayIsSelectable(array $runway): bool
{
    $surface = strtoupper((string) ($runway['surface'] ?? ''));
    if ($surface !== '' && str_contains($surface, 'WATER')) {
        return false;
    }

    $condition = strtoupper((string) ($runway['condition'] ?? ''));
    if ($condition === 'FAILED') {
        return false;
    }

    $length = (int) ($runway['length_ft'] ?? 0);
    return $length > 0;
}

/**
 * Select the longest active land runway for an airport NASR record.
 *
 * @param array $airportRecord Parsed NASR airport record
 * @return array|null Selected runway or null when none qualify
 */
function nasrSelectLongestActiveLandRunway(array $airportRecord): ?array
{
    $best = null;
    $bestLen = 0;

    foreach ($airportRecord['runways'] ?? [] as $runway) {
        if (!is_array($runway) || !nasrRunwayIsSelectable($runway)) {
            continue;
        }
        $len = (int) ($runway['length_ft'] ?? 0);
        if ($len > $bestLen) {
            $bestLen = $len;
            $best = $runway;
        }
    }

    return $best;
}
