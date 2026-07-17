<?php

/**
 * Operator-configured runway context for density altitude performance.
 *
 * Extends runway_length_ft / runway_surface with optional per-end threshold data
 * (NASR-aligned approach-side obstruction filing) when NASR and OurAirports
 * have no usable runway row.
 */

require_once __DIR__ . '/logger.php';
require_once __DIR__ . '/runway-end-ident.php';

/**
 * Config override for runway length when explicitly set by operator.
 *
 * @param array $airport Airport configuration
 * @return int|null Positive length in feet or null when unset
 */
function getConfigRunwayLengthOverrideFt(array $airport): ?int
{
    if (!isset($airport['runway_length_ft']) || !is_numeric($airport['runway_length_ft'])) {
        return null;
    }

    $len = (int) round((float) $airport['runway_length_ft']);

    return $len > 0 ? $len : null;
}

/**
 * Config override for runway surface code.
 *
 * @param array $airport Airport configuration
 * @return string|null Uppercase NASR-style surface code or null when unset
 */
function getConfigRunwaySurfaceOverride(array $airport): ?string
{
    if (!isset($airport['runway_surface']) || !is_string($airport['runway_surface'])) {
        return null;
    }

    $surface = strtoupper(trim($airport['runway_surface']));

    return $surface !== '' ? $surface : null;
}

/**
 * Whether a config obstruction block has usable height and distance for POH stress.
 *
 * Obstructions are filed on the approach side of the tagged threshold (NASR-aligned).
 *
 * @param array $obstruction Parsed obstruction row
 */
function configRunwayObstructionIsUsable(array $obstruction): bool
{
    if ($obstruction === []) {
        return false;
    }

    $hgt = $obstruction['hgt_ft'] ?? null;
    $dist = $obstruction['dist_ft'] ?? null;

    return is_numeric($hgt) && is_numeric($dist) && (float) $hgt > 0 && (float) $dist > 0;
}

/**
 * Resolve a log label for an airport row when parsing config runway ends.
 *
 * @param array $airport Airport configuration
 */
function configRunwayAirportLogLabel(array $airport): string
{
    foreach (['icao', 'id', 'faa', 'iata'] as $key) {
        if (!empty($airport[$key]) && is_string($airport[$key])) {
            return $airport[$key];
        }
    }

    return 'unknown';
}

/**
 * Parse maintainer runway_ends[] into NASR-shaped end rows (approach-side obstruction filing).
 *
 * @param array $airport Airport configuration
 * @return list<array<string, mixed>>
 */
function parseConfigRunwayEnds(array $airport): array
{
    if (!isset($airport['runway_ends']) || !is_array($airport['runway_ends'])) {
        return [];
    }

    $airportLabel = configRunwayAirportLogLabel($airport);
    $ends = [];
    $seenEndIds = [];
    foreach ($airport['runway_ends'] as $index => $row) {
        if (!is_array($row)) {
            aviationwx_log('warning', 'config runway_ends row skipped: not an object', [
                'airport' => $airportLabel,
                'index' => $index,
            ], 'app');
            continue;
        }

        $endId = isset($row['end_id']) && is_string($row['end_id']) ? strtoupper(trim($row['end_id'])) : '';
        if ($endId === '') {
            aviationwx_log('warning', 'config runway_ends row skipped: missing end_id', [
                'airport' => $airportLabel,
                'index' => $index,
            ], 'app');
            continue;
        }

        $canonicalEndId = canonicalizeRunwayEndIdent($endId);
        if ($canonicalEndId === null) {
            aviationwx_log('warning', 'config runway_ends row skipped: invalid end_id', [
                'airport' => $airportLabel,
                'index' => $index,
                'end_id' => $endId,
            ], 'app');
            continue;
        }

        if (isset($seenEndIds[$canonicalEndId])) {
            aviationwx_log('warning', 'config runway_ends row skipped: duplicate end_id', [
                'airport' => $airportLabel,
                'index' => $index,
                'end_id' => $canonicalEndId,
            ], 'app');
            continue;
        }
        $seenEndIds[$canonicalEndId] = true;

        $end = [
            'end_id' => $canonicalEndId,
            'obstruction' => [],
        ];

        if (isset($row['displaced_thr_len']) && is_numeric($row['displaced_thr_len'])) {
            $displaced = (int) round((float) $row['displaced_thr_len']);
            if ($displaced > 0) {
                $end['displaced_thr_len'] = $displaced;
            }
        }

        if (isset($row['tkof_dist_avbl']) && is_numeric($row['tkof_dist_avbl'])) {
            $tkof = (int) round((float) $row['tkof_dist_avbl']);
            if ($tkof > 0) {
                $end['tkof_dist_avbl'] = $tkof;
            }
        }

        if (isset($row['obstruction']) && is_array($row['obstruction'])) {
            $obst = [];
            $raw = $row['obstruction'];
            if (isset($raw['type']) && is_string($raw['type']) && trim($raw['type']) !== '') {
                $obst['type'] = strtoupper(trim($raw['type']));
            }
            if (isset($raw['hgt_ft']) && is_numeric($raw['hgt_ft'])) {
                $obst['hgt_ft'] = (float) $raw['hgt_ft'];
            }
            if (isset($raw['dist_ft']) && is_numeric($raw['dist_ft'])) {
                $obst['dist_ft'] = (float) $raw['dist_ft'];
            }
            if (isset($raw['slope']) && is_numeric($raw['slope']) && (float) $raw['slope'] > 0) {
                $obst['slope'] = (float) $raw['slope'];
            }
            $end['obstruction'] = $obst;
        }

        $ends[] = $end;
    }

    return $ends;
}

/**
 * Build operator runway row for density altitude performance when runway_length_ft is set.
 *
 * @param array $airport Airport configuration
 * @return array<string, mixed>|null NASR-shaped runway or null when length override absent
 */
function buildConfigRunwayForDensityAltitude(array $airport): ?array
{
    $length = getConfigRunwayLengthOverrideFt($airport);
    if ($length === null) {
        return null;
    }

    $runway = [
        'rwy_id' => 'config',
        'length_ft' => $length,
        'surface' => getConfigRunwaySurfaceOverride($airport) ?? 'ASPH',
        'ends' => parseConfigRunwayEnds($airport),
    ];

    if (!empty($airport['runways']) && is_array($airport['runways'])) {
        $manual = $airport['runways'][0] ?? null;
        if (is_array($manual)) {
            if (!empty($manual['name']) && is_string($manual['name'])) {
                $runway['rwy_id'] = trim($manual['name']);
            }
            if (isset($manual['heading_1']) && is_numeric($manual['heading_1'])) {
                $runway['heading_1'] = (float) $manual['heading_1'];
            }
            if (isset($manual['heading_2']) && is_numeric($manual['heading_2'])) {
                $runway['heading_2'] = (float) $manual['heading_2'];
            }
        }
    }

    return $runway;
}

/**
 * Whether a config runway includes approach-side obstruction data on any end.
 *
 * @param array $runway Runway row from buildConfigRunwayForDensityAltitude()
 */
function configRunwayHasDepartureObstructionData(array $runway): bool
{
    $validEnds = 0;
    $hasUsableObstruction = false;

    foreach ($runway['ends'] ?? [] as $end) {
        if (!is_array($end)) {
            continue;
        }

        $endId = isset($end['end_id']) ? trim((string) $end['end_id']) : '';
        if ($endId === '') {
            continue;
        }

        $validEnds++;

        $obst = is_array($end['obstruction'] ?? null) ? $end['obstruction'] : [];
        if (configRunwayObstructionIsUsable($obst)) {
            $hasUsableObstruction = true;
        }
    }

    return $validEnds === 2 && $hasUsableObstruction;
}

/**
 * Validate optional DA runway override fields on one airport row.
 *
 * @param string $airportCode Config airport key
 * @param array $airport Airport configuration
 * @param list<string> $errors Collected validation errors
 * @param list<string> $warnings Collected validation warnings
 */
function validateConfigRunwayFields(string $airportCode, array $airport, array &$errors, array &$warnings): void
{
    if (array_key_exists('runway_length_ft', $airport) && $airport['runway_length_ft'] !== null) {
        if (!is_numeric($airport['runway_length_ft'])) {
            $errors[] = "Airport '{$airportCode}' runway_length_ft must be a positive number";
        } elseif ((int) round((float) $airport['runway_length_ft']) <= 0) {
            $errors[] = "Airport '{$airportCode}' runway_length_ft must be greater than zero";
        }
    }

    if (array_key_exists('runway_surface', $airport) && $airport['runway_surface'] !== null) {
        if (!is_string($airport['runway_surface']) || trim($airport['runway_surface']) === '') {
            $errors[] = "Airport '{$airportCode}' runway_surface must be a non-empty string when set";
        } elseif (getConfigRunwayLengthOverrideFt($airport) === null) {
            $warnings[] = "Airport '{$airportCode}' runway_surface is set without runway_length_ft";
        }
    }

    if (!array_key_exists('runway_ends', $airport)) {
        return;
    }

    $ends = $airport['runway_ends'];
    if ($ends === null) {
        return;
    }

    if (!is_array($ends)) {
        $errors[] = "Airport '{$airportCode}' runway_ends must be an array";

        return;
    }

    if ($ends === []) {
        return;
    }

    if (!array_key_exists('runway_length_ft', $airport) || $airport['runway_length_ft'] === null) {
        $errors[] = "Airport '{$airportCode}' runway_ends requires runway_length_ft";
    }

    $seenEndIds = [];
    $hasUsableObstruction = false;
    foreach ($ends as $index => $row) {
        $label = "runway_ends[{$index}]";
        if (!is_array($row)) {
            $errors[] = "Airport '{$airportCode}' {$label} must be an object";
            continue;
        }

        $endId = $row['end_id'] ?? null;
        if (!is_string($endId) || trim($endId) === '') {
            $errors[] = "Airport '{$airportCode}' {$label} requires end_id";
            continue;
        }

        $normalizedEndId = strtoupper(trim($endId));
        $canonicalEndId = canonicalizeRunwayEndIdent($normalizedEndId);
        if ($canonicalEndId === null) {
            $errors[] = "Airport '{$airportCode}' {$label} has invalid end_id '{$endId}'";
            continue;
        }

        if (isset($seenEndIds[$canonicalEndId])) {
            $errors[] = "Airport '{$airportCode}' {$label} duplicates end_id '{$canonicalEndId}'";
            continue;
        }
        $seenEndIds[$canonicalEndId] = true;

        foreach (['displaced_thr_len', 'tkof_dist_avbl'] as $field) {
            if (!array_key_exists($field, $row) || $row[$field] === null) {
                continue;
            }
            if (!is_numeric($row[$field]) || (int) round((float) $row[$field]) <= 0) {
                $errors[] = "Airport '{$airportCode}' {$label}.{$field} must be a positive number";
                continue;
            }

            if ($field === 'displaced_thr_len') {
                $runwayLen = getConfigRunwayLengthOverrideFt($airport);
                $displaced = (int) round((float) $row[$field]);
                if ($runwayLen !== null && $displaced >= $runwayLen) {
                    $errors[] = "Airport '{$airportCode}' {$label}.displaced_thr_len must be less than runway_length_ft";
                }
            }
        }

        if (!isset($row['obstruction']) || !is_array($row['obstruction'])) {
            continue;
        }

        $obst = $row['obstruction'];
        $hasHgt = array_key_exists('hgt_ft', $obst) && $obst['hgt_ft'] !== null;
        $hasDist = array_key_exists('dist_ft', $obst) && $obst['dist_ft'] !== null;
        if ($hasHgt xor $hasDist) {
            $errors[] = "Airport '{$airportCode}' {$label}.obstruction.hgt_ft and obstruction.dist_ft must both be set when either is provided";
        }

        if (configRunwayObstructionIsUsable($obst)) {
            $hasUsableObstruction = true;
        }
        foreach (['hgt_ft', 'dist_ft'] as $field) {
            if (!array_key_exists($field, $obst) || $obst[$field] === null) {
                continue;
            }
            if (!is_numeric($obst[$field]) || (float) $obst[$field] <= 0) {
                $errors[] = "Airport '{$airportCode}' {$label}.obstruction.{$field} must be a positive number";
            }
        }

        if (array_key_exists('slope', $obst) && $obst['slope'] !== null) {
            if (!is_numeric($obst['slope']) || (float) $obst['slope'] <= 0) {
                $errors[] = "Airport '{$airportCode}' {$label}.obstruction.slope must be a positive number";
            }
        }
    }

    if (count($seenEndIds) > 2) {
        $errors[] = "Airport '{$airportCode}' runway_ends must contain at most two entries";
    }

    if ($hasUsableObstruction && count($seenEndIds) < 2) {
        $errors[] = "Airport '{$airportCode}' runway_ends obstruction requires two ends";
    }
}
