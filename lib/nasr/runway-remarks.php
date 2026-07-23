<?php
/**
 * NASR APT_RMK parsing for runway display (calm wind designations).
 */

require_once __DIR__ . '/../runway-end-ident.php';

const NASR_CALM_WIND_RWY_TOKEN = 'R(?:WY|Y)';

const NASR_CALM_WIND_IDENT_CAPTURE = '(\d{1,2}[LRC]?)';

/**
 * Regex patterns for single-end calm-wind remarks (arrival and departure same runway).
 *
 * @return list<string>
 */
function nasrCalmWindSingleEndBothPatterns(): array
{
    static $patterns = null;
    if ($patterns === null) {
        $rwy = NASR_CALM_WIND_RWY_TOKEN;
        $id = NASR_CALM_WIND_IDENT_CAPTURE;
        $patterns = [
            '/' . $rwy . '\s+' . $id . '\s+CALM\s+WIND\s+' . $rwy . '\b/',
            '/' . $rwy . '\s+' . $id . '\s+IS\s+CALM\s+WIND\s+' . $rwy . '\b/',
            '/' . $rwy . '\s+' . $id . '\s+DSGND\s+CALM\s+WIND\s+' . $rwy . '\b/',
            '/' . $rwy . '\s+' . $id . '\s+DESIGNATED\s+CALM\s+WIND\s+' . $rwy . '\b/',
            '/' . $rwy . '\s+' . $id . '\s+DESIGNATED\s+AS\s+CALM\s+WIND\s+' . $rwy . '\b/',
            '/' . $rwy . '\s+' . $id . '\s+PREFERRED\s+CALM\s+WIND\s+' . $rwy . '\b/',
            '/' . $rwy . '\s+' . $id . '\s+WILL\s+BE\s+THE\s+DESIGNATED\s+CALM\s+WIND\s+' . $rwy . '\b/',
            '/' . $rwy . '\s+' . $id . '\s+PREFERRED\s+CALM\s+WIND\s+RUNWAY\b/',
            '/RWY\s+' . $id . '\s+PREF\s+CALM\s+WIND\s+RWY\b/',
            '/RWY\s+' . $id . '\s+CALM\s+WIND\b/',
        ];
    }

    return $patterns;
}

/**
 * Regex patterns for calm-wind remarks that lead with CALM WIND before the runway ident.
 *
 * @return list<string>
 */
function nasrCalmWindLeadingPatterns(): array
{
    static $patterns = null;
    if ($patterns === null) {
        $rwy = NASR_CALM_WIND_RWY_TOKEN;
        $id = NASR_CALM_WIND_IDENT_CAPTURE;
        $patterns = [
            '/CALM\s+WIND\s+' . $rwy . '\s+' . $id . '\b/',
            '/CALM\s+WIND\s+USE\s+' . $rwy . '\s+' . $id . '\b/',
            '/CALM\s+WIND\s+RWY\s+IS\s+RWY\s+' . $id . '\b/',
            '/PREFERRED\s+CALM\s+WIND\s+RWY\s+' . $id . '\b/',
            '/CALM\s+WIND\s+LESS\s+THAN\s+\d+\s+KNOTS?\s+USE\s+RWY\s+' . $id . '\b/',
            '/CALM\s+WIND\s+PREFERRED\s+DRCTN\s+IS\s+RWY\s+' . $id . '\b/',
            '/PREF\s+CALM\s+WIND\s+RWY\s+USE\s+RWY\s+' . $id . '\b/',
            '/DRG\s+CALM\s+WINDS?\s+USE\s+RWY\s+' . $id . '\b/',
        ];
    }

    return $patterns;
}

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

        $parsed = nasrParseCalmWindDesignationFromAptRmkRow($row);
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
 * Parse calm wind designation from one APT_RMK.csv row (remark text plus NASR context).
 *
 * @param array<string, mixed> $row
 * @return array{arrival?: string, departure?: string}|null
 */
function nasrParseCalmWindDesignationFromAptRmkRow(array $row): ?array
{
    $remark = trim((string) ($row['REMARK'] ?? ''));
    if ($remark === '') {
        return null;
    }

    return nasrParseCalmWindDesignationFromRemark($remark, nasrCalmWindContextFromAptRmkRow($row));
}

/**
 * Runway-end context for bare APT_RMK remarks (for example "CALM WIND RWY.").
 *
 * FAA places the threshold ident in ELEMENT when REF_COL_NAME is RWY_END_ID, not REF_COL_SEQ_NO.
 *
 * @param array<string, mixed> $row
 * @return array{runway_end?: string}
 */
function nasrCalmWindContextFromAptRmkRow(array $row): array
{
    $refCol = strtoupper(trim((string) ($row['REF_COL_NAME'] ?? '')));
    if ($refCol !== 'RWY_END_ID') {
        return [];
    }

    $element = trim((string) ($row['ELEMENT'] ?? ''));
    if ($element === '') {
        return [];
    }

    $runwayEnd = canonicalizeRunwayEndIdent($element);
    if ($runwayEnd === null) {
        return [];
    }

    return ['runway_end' => $runwayEnd];
}

/**
 * Parse a NASR remark for calm wind runway designation.
 *
 * Returns null when the text is ambiguous or low confidence.
 *
 * @param array{runway_end?: string} $context Optional NASR row context
 * @return array{arrival?: string, departure?: string}|null
 */
function nasrParseCalmWindDesignationFromRemark(string $remark, array $context = []): ?array
{
    $text = strtoupper(preg_replace('/\s+/', ' ', trim($remark)) ?? '');
    if ($text === '' || !str_contains($text, 'CALM WIND')) {
        return null;
    }

    if (nasrCalmWindRemarkIsAmbiguous($text)) {
        return null;
    }

    $rwy = NASR_CALM_WIND_RWY_TOKEN;
    $id = NASR_CALM_WIND_IDENT_CAPTURE;

    if (preg_match(
        '/CALM\s+WIND\s+(?:ARR(?:IVAL)?|ARR)\s+RWY\s+' . $id . '\s*;\s*CALM\s+WIND\s+(?:DEP(?:ARTURE)?|DEP)\s+RWY\s+' . $id . '/',
        $text,
        $m
    )) {
        return nasrNormalizeCalmWindDesignation($m[1], $m[2]);
    }

    if (preg_match(
        '/CALM\s+WIND\s+RWY\s+' . $id . '\s+FOR\s+ARR(?:IVAL)?S?\s*;\s*RWY\s+' . $id . '\s+FOR\s+DEP(?:ARTURE)?S?/',
        $text,
        $m
    )) {
        return nasrNormalizeCalmWindDesignation($m[1], $m[2]);
    }

    if (preg_match(
        '/RWY\s+' . $id . '\s+FOR\s+ARR(?:IVAL)?S?\s*;\s*RWY\s+' . $id . '\s+FOR\s+DEP(?:ARTURE)?S?.*CALM\s+WIND/',
        $text,
        $m
    )) {
        return nasrNormalizeCalmWindDesignation($m[1], $m[2]);
    }

    if (preg_match(
        '/CALM\s+WIND\s+TKOF\s+RWY\s+' . $id . '\s*;\s*LND\s+RWY\s+' . $id . '/',
        $text,
        $m
    )) {
        // TKOF maps to departure; LND maps to arrival.
        return nasrNormalizeCalmWindDesignation($m[2], $m[1]);
    }

    if (preg_match(
        '/RWY\s+' . $id . '\s+CALM\s+WIND\s+RWY\s+FOR\s+LNDG\s*;\s*RWY\s+' . $id . '\s+FOR\s+TKOFF/',
        $text,
        $m
    )) {
        return nasrNormalizeCalmWindDesignation($m[1], $m[2]);
    }

    if (preg_match(
        '/RWY\s+' . $id . '\s+DSGND\s+CALM\s+WIND\s+RWY\s+FOR\s+ARRS?\b.*?RWY\s+' . $id . '\s+DSGND\s+CALM\s+WIND\s+RWY\s+FOR\s+DEPS?\b/',
        $text,
        $m
    )) {
        return nasrNormalizeCalmWindDesignation($m[1], $m[2]);
    }

    if (preg_match(
        '/RWY\s+' . $id . '\s+DSGND\s+CALM\s+WIND\s+RWY\s+FOR\s+ARRS?\s+AND\s+DEPS?\b/',
        $text,
        $m
    )) {
        return nasrNormalizeCalmWindDesignation($m[1], $m[1]);
    }

    if (preg_match('/CALM\s+WIND\s+LNDG\s+RWY\s+' . $id . '\b/', $text, $m)) {
        return nasrNormalizeCalmWindDesignation($m[1], null);
    }

    if (preg_match('/PREF(?:ERRED)?\s+CALM\s+WIND\s+RWY\s+' . $id . '\s+FOR\s+TKOF\b/', $text, $m)) {
        return nasrNormalizeCalmWindDesignation(null, $m[1]);
    }

    if (preg_match('/^PREF\s+DEP\s+CALM\s+WIND\s+RWY\.?$/', $text)) {
        $runwayEnd = $context['runway_end'] ?? null;
        if (is_string($runwayEnd) && $runwayEnd !== '') {
            return nasrNormalizeCalmWindDesignation(null, $runwayEnd);
        }

        return null;
    }

    if (preg_match(
        '/CALM\s+WIND\s+RWY\s+' . $id . '\s+FOR\s+ARR(?:IVAL)?S?.*RWY\s+' . $id . '\s+FOR\s+DEP(?:ARTURE)?S?/',
        $text,
        $m
    )) {
        return nasrNormalizeCalmWindDesignation($m[1], $m[2]);
    }

    if (preg_match(
        '/RWY\s+' . $id . '\s+(?:AND\s+)?' . $id . '?\s*(?:IS\s+)?DESIGNATED\s+CALM\s+WIND/',
        $text,
        $m
    )) {
        if (!empty($m[2])) {
            return null;
        }

        return nasrNormalizeCalmWindDesignation($m[1], $m[1]);
    }

    foreach (nasrCalmWindSingleEndBothPatterns() as $pattern) {
        if (preg_match($pattern, $text, $m)) {
            return nasrNormalizeCalmWindDesignation($m[1], $m[1]);
        }
    }

    if (preg_match('/CALM\s+WIND\s+RWY\s+' . $id . '(?:\s*\/\s*' . $id . ')?\b/', $text, $m)) {
        if (!empty($m[2])) {
            return null;
        }

        return nasrNormalizeCalmWindDesignation($m[1], $m[1]);
    }

    foreach (nasrCalmWindLeadingPatterns() as $pattern) {
        if (preg_match($pattern, $text, $m)) {
            return nasrNormalizeCalmWindDesignation($m[1], $m[1]);
        }
    }

    if (preg_match('/CALM\s+WIND\s+(?:ARR(?:IVAL)?|ARR)\s+RWY\s+' . $id . '/', $text, $m)) {
        return nasrNormalizeCalmWindDesignation($m[1], null);
    }

    if (preg_match('/CALM\s+WIND\s+(?:DEP(?:ARTURE)?|DEP)\s+RWY\s+' . $id . '/', $text, $m)) {
        return nasrNormalizeCalmWindDesignation(null, $m[1]);
    }

    if (preg_match('/^CALM\s+WIND\s+' . $rwy . '\.?$/', $text)) {
        $runwayEnd = $context['runway_end'] ?? null;
        if (is_string($runwayEnd) && $runwayEnd !== '') {
            return nasrNormalizeCalmWindDesignation($runwayEnd, $runwayEnd);
        }
    }

    return null;
}

/**
 * Whether a remark contains parallel-runway calm-wind wording too ambiguous to parse.
 */
function nasrCalmWindRemarkIsAmbiguous(string $text): bool
{
    $id = NASR_CALM_WIND_IDENT_CAPTURE;

    if (preg_match('/\b' . $id . '\s*\/\s*' . $id . '\s+CALM\s+WIND/i', $text)) {
        return true;
    }

    if (preg_match('/\bRWY\s+' . $id . '\s*&\s*' . $id . '\s+CALM\s+WIND/i', $text)) {
        return true;
    }

    if (preg_match('/\bCALM\s+WIND\s+PREFERRED\s+TKOF\/LNDG\s+TO\s+THE\b/i', $text)) {
        return true;
    }

    if (preg_match('/\bDURING\s+CALM\s+WIND\/CROSSWIND\b/i', $text)) {
        return true;
    }

    if (preg_match('/\bPREFERRED\s+DEP\s+RWY\s+DURG\s+CALM\s+WINDS?\b/i', $text)) {
        return true;
    }

    if (preg_match('/\bIS\s+THE\s+PREFERRED\s+RWY\s+IN\s+CALM\s+WIND\s+CONDITIONS\b/i', $text)) {
        return true;
    }

    return false;
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
