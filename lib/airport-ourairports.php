<?php

/**
 * OurAirports identifier helpers for internal open-data joins.
 *
 * Pilot-facing labels use ICAO > IATA > FAA ({@see getBestIdentifierForLinks()}).
 * These helpers resolve config airports to OurAirports cache keys only.
 */

/**
 * Configured OurAirports text ident (e.g. US-4027, CYAV, ID35).
 *
 * @param array $airport Airport configuration
 * @return string|null Uppercase ident or null when unset
 */
function getOurAirportsIdentFromAirportConfig(array $airport): ?string
{
    if (!isset($airport['ourairports_ident']) || !is_string($airport['ourairports_ident'])) {
        return null;
    }

    $ident = strtoupper(trim($airport['ourairports_ident']));

    return $ident !== '' ? $ident : null;
}

/**
 * Optional stable OurAirports integer row id from airports.csv.
 *
 * @param array $airport Airport configuration
 * @return int|null Positive integer or null when unset
 */
function getOurAirportsNumericIdFromAirportConfig(array $airport): ?int
{
    if (!isset($airport['ourairports_id'])) {
        return null;
    }

    $raw = $airport['ourairports_id'];
    if (is_int($raw)) {
        return $raw > 0 ? $raw : null;
    }

    $validated = filter_var($raw, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
    if ($validated === false) {
        return null;
    }

    return (int) $validated;
}

/**
 * Whether a string is a valid OurAirports ident for config validation.
 *
 * @param string $ident Candidate ident
 * @return bool
 */
function isValidOurAirportsIdentFormat(string $ident): bool
{
    $ident = strtoupper(trim($ident));

    if ($ident === '' || strlen($ident) > 15) {
        return false;
    }

    return preg_match('/^[A-Z0-9]([A-Z0-9-]*[A-Z0-9])?$/', $ident) === 1;
}

/**
 * Ordered OurAirports runway-cache keys to try (first match wins).
 *
 * Precedence: explicit ourairports_ident, ICAO, FAA, config slug.
 *
 * @param string $configAirportId Config key (e.g. 45ranch)
 * @param array $airport Airport configuration
 * @return list<string> Unique uppercase idents
 */
function ourAirportsCacheLookupIdentsForAirport(string $configAirportId, array $airport): array
{
    $candidates = [];

    $push = static function (?string $ident) use (&$candidates): void {
        if ($ident === null) {
            return;
        }
        $upper = strtoupper(trim($ident));
        if ($upper === '' || in_array($upper, $candidates, true)) {
            return;
        }
        $candidates[] = $upper;
    };

    $push(getOurAirportsIdentFromAirportConfig($airport));
    foreach (['icao', 'faa'] as $field) {
        if (!isset($airport[$field])) {
            continue;
        }
        $value = $airport[$field];
        if (is_string($value) || is_int($value) || is_float($value)) {
            $push((string) $value);
        }
    }
    $push($configAirportId);

    return $candidates;
}
