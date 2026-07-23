<?php
/**
 * NASR APT_RMK parsing for runway display (calm wind designations).
 */

require_once __DIR__ . '/../runway-end-ident.php';

/**
 * Merge high-confidence calm wind designations from APT_RMK.csv into airport records.
 *
 * @param array<string, array<string, mixed>> $airports Parsed airports keyed by ARPT_ID (by reference)
 */
function nasrAttachCalmWindRemarksFromAptRmk(array &$airports, string $rmkPath): void
{
    foreach (nasrIterateCsvFile($rmkPath) as $row) {
        $arptId = strtoupper(trim((string) ($row['ARPT_ID'] ?? '')));
        if ($arptId === '' || !isset($airports[$arptId])) {
            continue;
        }

        $remark = trim((string) ($row['REMARK'] ?? ''));
        if ($remark === '') {
            continue;
        }

        $parsed = nasrParseCalmWindDesignationFromRemark($remark);
        if ($parsed === null) {
            continue;
        }

        $existing = $airports[$arptId]['calm_wind'] ?? [];
        if (!is_array($existing)) {
            $existing = [];
        }

        if (isset($parsed['arrival']) && !isset($existing['arrival'])) {
            $existing['arrival'] = $parsed['arrival'];
        }
        if (isset($parsed['departure']) && !isset($existing['departure'])) {
            $existing['departure'] = $parsed['departure'];
        }

        if ($existing !== []) {
            $airports[$arptId]['calm_wind'] = $existing;
        }
    }
}

/**
 * Parse a NASR remark for calm wind runway designation.
 *
 * Returns null when the text is ambiguous or low confidence.
 *
 * @return array{arrival?: string, departure?: string}|null
 */
function nasrParseCalmWindDesignationFromRemark(string $remark): ?array
{
    $text = strtoupper(preg_replace('/\s+/', ' ', trim($remark)) ?? '');
    if ($text === '' || !str_contains($text, 'CALM WIND')) {
        return null;
    }

    if (preg_match(
        '/CALM\s+WIND\s+(?:ARR(?:IVAL)?|ARR)\s+RWY\s+(\d{1,2}[LRC]?)\s*;\s*CALM\s+WIND\s+(?:DEP(?:ARTURE)?|DEP)\s+RWY\s+(\d{1,2}[LRC]?)/',
        $text,
        $m
    )) {
        return nasrNormalizeCalmWindDesignation($m[1], $m[2]);
    }

    if (preg_match(
        '/CALM\s+WIND\s+RWY\s+(\d{1,2}[LRC]?)\s+FOR\s+ARR(?:IVAL)?S?\s*;\s*RWY\s+(\d{1,2}[LRC]?)\s+FOR\s+DEP(?:ARTURE)?S?/',
        $text,
        $m
    )) {
        return nasrNormalizeCalmWindDesignation($m[1], $m[2]);
    }

    if (preg_match(
        '/RWY\s+(\d{1,2}[LRC]?)\s+FOR\s+ARR(?:IVAL)?S?\s*;\s*RWY\s+(\d{1,2}[LRC]?)\s+FOR\s+DEP(?:ARTURE)?S?.*CALM\s+WIND/',
        $text,
        $m
    )) {
        return nasrNormalizeCalmWindDesignation($m[1], $m[2]);
    }

    if (preg_match(
        '/CALM\s+WIND\s+RWY\s+(\d{1,2}[LRC]?)\s+FOR\s+ARR(?:IVAL)?S?.*RWY\s+(\d{1,2}[LRC]?)\s+FOR\s+DEP(?:ARTURE)?S?/',
        $text,
        $m
    )) {
        return nasrNormalizeCalmWindDesignation($m[1], $m[2]);
    }

    if (preg_match(
        '/RWY\s+(\d{1,2}[LRC]?)\s+(?:AND\s+)?(\d{1,2}[LRC]?)?\s*(?:IS\s+)?DESIGNATED\s+CALM\s+WIND/',
        $text,
        $m
    )) {
        if (!empty($m[2])) {
            return null;
        }

        return nasrNormalizeCalmWindDesignation($m[1], $m[1]);
    }

    if (preg_match('/CALM\s+WIND\s+RWY\s+(\d{1,2}[LRC]?)(?:\s*\/\s*(\d{1,2}[LRC]?))?/', $text, $m)) {
        if (!empty($m[2])) {
            return null;
        }

        return nasrNormalizeCalmWindDesignation($m[1], $m[1]);
    }

    if (preg_match('/CALM\s+WIND\s+(?:ARR(?:IVAL)?|ARR)\s+RWY\s+(\d{1,2}[LRC]?)/', $text, $m)) {
        return nasrNormalizeCalmWindDesignation($m[1], null);
    }

    if (preg_match('/CALM\s+WIND\s+(?:DEP(?:ARTURE)?|DEP)\s+RWY\s+(\d{1,2}[LRC]?)/', $text, $m)) {
        return nasrNormalizeCalmWindDesignation(null, $m[1]);
    }

    return null;
}

/**
 * @return array{arrival?: string, departure?: string}|null
 */
function nasrNormalizeCalmWindDesignation(?string $arrival, ?string $departure): ?array
{
    $result = [];
    if ($arrival !== null && $arrival !== '') {
        $canonical = canonicalizeRunwayEndIdent($arrival);
        if ($canonical === null) {
            return null;
        }
        $result['arrival'] = $canonical;
    }
    if ($departure !== null && $departure !== '') {
        $canonical = canonicalizeRunwayEndIdent($departure);
        if ($canonical === null) {
            return null;
        }
        $result['departure'] = $canonical;
    }

    return $result === [] ? null : $result;
}

/**
 * Map NASR RWY_LGT_CODE to a pilot-facing lights label.
 */
function nasrRunwayLightsLabel(?string $code): ?string
{
    if ($code === null || $code === '') {
        return null;
    }

    return match (strtoupper($code)) {
        'HIGH' => 'Full time',
        'MED' => 'Full time',
        'LOW' => 'Full time',
        'PERI' => 'On request',
        'NSTD' => 'Non-standard',
        default => null,
    };
}
