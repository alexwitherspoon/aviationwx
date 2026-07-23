<?php

declare(strict_types=1);

/**
 * Subject-aware parsing for runway, taxiway, and aerodrome closure phrases in NOTAM text.
 */

require_once __DIR__ . '/../runway-end-ident.php';

const NOTAM_RWY_DESIGNATOR_CAPTURE = '(\d{1,2}[LRC]?(?:\s*\/\s*\d{1,2}[LRC]?)?)';

/**
 * Uppercase NOTAM prose with collapsed whitespace.
 */
function notamNormalizeProse(string $text): string
{
    return strtoupper(preg_replace('/\s+/', ' ', trim($text)) ?? '');
}

/**
 * Whether prose contains a closure keyword.
 */
function notamProseHasClosureKeyword(string $upper): bool
{
    return str_contains($upper, 'CLSD') || str_contains($upper, 'CLOSED');
}

/**
 * Regex matching RWY immediately followed by a designator and CLSD/CLOSED.
 *
 * @return string PCRE pattern with delimiters
 */
function notamDirectRunwayClosureRegex(): string
{
    return '/\bRWY\s+' . NOTAM_RWY_DESIGNATOR_CAPTURE . '\s+(?:CLSD|CLOSED)\b/';
}

/**
 * Whether RWY {designator} is the closed subject (not a taxiway landmark).
 */
function notamTextIndicatesDirectRunwayClosure(string $text): bool
{
    $upper = notamNormalizeProse($text);
    if ($upper === '' || !notamProseHasClosureKeyword($upper)) {
        return false;
    }

    if (preg_match(notamDirectRunwayClosureRegex(), $upper, $matches, PREG_OFFSET_CAPTURE) !== 1) {
        if (preg_match(
            '/\bRWY\s+' . NOTAM_RWY_DESIGNATOR_CAPTURE . '\s+INTERSECTION\s+TWY\s+[A-Z0-9]{1,3}\s+(?:CLSD|CLOSED)\b/',
            $upper
        ) !== 1) {
            return false;
        }

        return true;
    }

    $matchStart = $matches[0][1];
    $prefix = substr($upper, 0, $matchStart);
    if (preg_match('/\b(OBST|CRANE|HCRANE|HIRTA)\b/', $prefix) === 1) {
        return false;
    }

    return true;
}

/**
 * Whether prose closes a taxiway segment without closing the runway itself.
 */
function notamTextIndicatesTaxiwayOnlyClosure(string $text): bool
{
    $upper = notamNormalizeProse($text);
    if ($upper === '' || !notamProseHasClosureKeyword($upper)) {
        return false;
    }

    if (notamTextIndicatesDirectRunwayClosure($text)) {
        return false;
    }

    if (preg_match('/\bTWY\b/', $upper) !== 1) {
        return false;
    }

    return preg_match(
        '/\bTWY\s+(?:[A-Z0-9]{1,3}\b|.+?\b(?:CLSD|CLOSED)\b)/',
        $upper
    ) === 1 && preg_match('/\b(?:CLSD|CLOSED)\b/', $upper) === 1;
}

/**
 * Whether NOTAM prose indicates aerodrome-level closure (AD AP / airport).
 */
function notamTextIndicatesAerodromeClosurePhrase(string $text): bool
{
    $upper = notamNormalizeProse($text);
    if ($upper === '' || !notamProseHasClosureKeyword($upper)) {
        return false;
    }

    if (preg_match('/\bAD\s+AP\s+(?:CLSD|CLOSED)\b/', $upper) === 1) {
        return true;
    }

    return preg_match('/\b(?:ARPT|AIRPORT)\s+(?:CLSD|CLOSED)\b/', $upper) === 1
        || preg_match('/\b(?:ARPT|AIRPORT)\b.+\b(?:CLSD|CLOSED)\b/', $upper) === 1;
}

/**
 * Whether NOTAM prose indicates a runway or aerodrome closure when Q-code is absent.
 */
function notamTextIndicatesRunwayOrAerodromeClosure(string $text): bool
{
    return notamTextIndicatesDirectRunwayClosure($text)
        || notamTextIndicatesAerodromeClosurePhrase($text);
}

/**
 * Whether closure text limits aircraft (wingspan, weight) rather than full closure.
 */
function notamTextIndicatesPartialRunwayRestriction(string $text): bool
{
    if ($text === '') {
        return false;
    }

    $upper = notamNormalizeProse($text);
    if (str_contains($upper, 'WINGSPAN')) {
        return true;
    }
    if (preg_match('/\bCLSD\s+TO\b/', $upper) === 1) {
        return true;
    }

    return preg_match('/\bTO\s+ACFT\b/', $upper) === 1 && str_contains($upper, 'CLSD');
}

/**
 * Whether a partial restriction materially affects runway operations (banner-worthy).
 */
function notamTextIndicatesRunwayAffectingPartialRestriction(string $text): bool
{
    if (!notamTextIndicatesPartialRunwayRestriction($text)) {
        return false;
    }

    if (notamTextIndicatesDirectRunwayClosure($text)) {
        return true;
    }

    $upper = notamNormalizeProse($text);

    if (preg_match(
        '/\b(?:APCH|DEP)\s+END\s+RWY\s+' . NOTAM_RWY_DESIGNATOR_CAPTURE . '\b/',
        $upper
    ) === 1) {
        return true;
    }

    return preg_match(
        '/\b(?:TKOF|TKOFF|LNDG|LND)\s+RWY\s+' . NOTAM_RWY_DESIGNATOR_CAPTURE . '\b/',
        $upper
    ) === 1;
}

/**
 * Normalize a captured runway designator token for display and pair matching.
 */
function notamNormalizeRunwayDesignatorToken(string $raw): ?string
{
    $normalized = strtoupper(str_replace(' ', '', trim($raw)));
    if ($normalized === '') {
        return null;
    }

    if (!str_contains($normalized, '/')) {
        return canonicalizeRunwayEndIdent($normalized) ?? $normalized;
    }

    [$endA, $endB] = explode('/', $normalized, 2);
    $canonicalA = canonicalizeRunwayEndIdent($endA) ?? strtoupper($endA);
    $canonicalB = canonicalizeRunwayEndIdent($endB) ?? strtoupper($endB);

    return $canonicalA . '/' . $canonicalB;
}

/**
 * Runway designator for closure cards and banner headlines from NOTAM prose.
 */
function notamExtractRunwayDesignatorForDisplay(string $text): ?string
{
    $upper = notamNormalizeProse($text);

    if (preg_match(notamDirectRunwayClosureRegex(), $upper, $matches) === 1) {
        return notamNormalizeRunwayDesignatorToken($matches[1]);
    }

    if (preg_match(
        '/\b(?:APCH|DEP)\s+END\s+RWY\s+' . NOTAM_RWY_DESIGNATOR_CAPTURE . '\b/',
        $upper,
        $matches
    ) === 1) {
        return notamNormalizeRunwayDesignatorToken($matches[1]);
    }

    if (preg_match(
        '/\b(?:TKOF|TKOFF|LNDG|LND)\s+RWY\s+' . NOTAM_RWY_DESIGNATOR_CAPTURE . '\b/',
        $upper,
        $matches
    ) === 1) {
        return notamNormalizeRunwayDesignatorToken($matches[1]);
    }

    return null;
}

/**
 * Whether a QMR-coded NOTAM prose contradicts runway closure (taxiway-only wording).
 */
function notamQmrCodeContradictsRunwayClosureScope(string $text): bool
{
    return notamTextIndicatesTaxiwayOnlyClosure($text)
        && !notamTextIndicatesDirectRunwayClosure($text);
}
