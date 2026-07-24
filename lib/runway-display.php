<?php
/**
 * Runway facts for the airport dashboard (display resolver).
 *
 * Precedence per field: airports.json → NASR → OurAirports.
 */

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/config-runway.php';
require_once __DIR__ . '/heading-conversion.php';
require_once __DIR__ . '/nasr/cache.php';
require_once __DIR__ . '/nasr/runway-remarks.php';
require_once __DIR__ . '/nasr/runway-selection.php';
require_once __DIR__ . '/runway-end-ident.php';
require_once __DIR__ . '/runways.php';
require_once __DIR__ . '/weather/da-performance-notam-closures.php';

/**
 * Pilot-facing surface label from a NASR or OurAirports surface code.
 */
function runwayDisplaySurfaceLabel(?string $code): ?string
{
    if ($code === null || trim($code) === '') {
        return null;
    }

    $code = strtoupper(trim($code));
    $labels = [
        'ASPH' => 'Asphalt',
        'ASP' => 'Asphalt',
        'CONC' => 'Concrete',
        'CON' => 'Concrete',
        'GRVL' => 'Gravel',
        'GRAVEL' => 'Gravel',
        'TURF' => 'Turf',
        'GRS' => 'Turf',
        'GRE' => 'Turf',
        'GRASS' => 'Turf',
        'DIRT' => 'Dirt',
        'SOD' => 'Turf',
        'CLAY' => 'Clay',
        'PEM' => 'Paved',
        'BIT' => 'Asphalt',
    ];

    if (isset($labels[$code])) {
        return $labels[$code];
    }

    foreach ($labels as $needle => $label) {
        if (str_contains($code, $needle)) {
            return $label;
        }
    }

    return ucfirst(strtolower($code));
}

/**
 * Lights label from OurAirports lighted flag.
 */
function runwayDisplayOurAirportsLightsLabel(?bool $lighted): ?string
{
    if ($lighted === null) {
        return null;
    }

    return $lighted ? 'Lighted' : 'None';
}

/**
 * Build a traffic pattern note for non-standard pattern ends.
 *
 * @param list<array<string, mixed>> $ends
 */
function runwayDisplayTrafficNote(array $ends): ?string
{
    $notes = [];
    foreach ($ends as $end) {
        if (!is_array($end) || empty($end['right_hand_traffic'])) {
            continue;
        }
        $endId = (string) ($end['end_id'] ?? '');
        if ($endId !== '') {
            $notes[] = 'RWY ' . $endId . ': Right traffic';
        }
    }

    return $notes === [] ? null : implode('; ', $notes);
}

/**
 * Index config runway_facts[] overrides by normalized rwy_id.
 *
 * @param array $airport Airport configuration
 * @return array<string, array<string, mixed>>
 */
function runwayDisplayConfigFactsByRunwayId(array $airport): array
{
    if (!isset($airport['runway_facts']) || !is_array($airport['runway_facts'])) {
        return [];
    }

    $indexed = [];
    foreach ($airport['runway_facts'] as $row) {
        if (!is_array($row)) {
            continue;
        }
        $rwyId = isset($row['rwy_id']) ? trim((string) $row['rwy_id']) : '';
        if ($rwyId === '') {
            continue;
        }
        $indexed[densityAltitudePerformanceNormalizeRunwayPairKey($rwyId)] = $row;
    }

    return $indexed;
}

/**
 * Resolve one field with config → source precedence.
 *
 * @param array<string, mixed> $configRow
 * @param mixed $sourceValue
 * @param mixed $configValue
 * @return array{0: mixed, 1: ?string}
 */
function runwayDisplayResolveField(array $configRow, mixed $sourceValue, mixed $configValue, string $sourceName): array
{
    if ($configValue !== null && $configValue !== '') {
        return [$configValue, 'config'];
    }
    if ($sourceValue !== null && $sourceValue !== '') {
        return [$sourceValue, $sourceName];
    }

    return [null, null];
}

/**
 * Resolve one field with config precedence, then primary source, then OurAirports fallback.
 *
 * @param array<string, mixed> $configRow
 * @return array{0: mixed, 1: ?string}
 */
function runwayDisplayResolveFieldWithFallback(
    array $configRow,
    mixed $primaryValue,
    mixed $fallbackValue,
    mixed $configValue,
    string $primarySource,
    string $fallbackSource = 'ourairports'
): array {
    if ($configValue !== null && $configValue !== '') {
        return [$configValue, 'config'];
    }
    if ($primaryValue !== null && $primaryValue !== '') {
        return [$primaryValue, $primarySource];
    }
    if ($fallbackValue !== null && $fallbackValue !== '') {
        return [$fallbackValue, $fallbackSource];
    }

    return [null, null];
}

/**
 * Magnetic heading for a runway end.
 */
function runwayDisplayMagneticHeadingForEnd(array $end, ?float $declinationDeg): ?int
{
    if (isset($end['heading_mag']) && is_numeric($end['heading_mag'])) {
        return (int) round((float) $end['heading_mag']);
    }

    $trueAlignment = $end['true_alignment'] ?? null;
    if (!is_numeric($trueAlignment)) {
        $endId = (string) ($end['end_id'] ?? '');
        $parsed = $endId !== '' ? parseRunwayEndIdentMagneticHeading($endId) : null;
        if ($parsed !== null) {
            return $parsed;
        }

        return null;
    }

    if ($declinationDeg === null) {
        $endId = (string) ($end['end_id'] ?? '');
        $parsed = $endId !== '' ? parseRunwayEndIdentMagneticHeading($endId) : null;
        if ($parsed !== null) {
            return $parsed;
        }

        return (int) round((float) $trueAlignment);
    }

    return (int) round(convertTrueToMagnetic((float) $trueAlignment, $declinationDeg));
}

/**
 * Build display ends with magnetic headings and calm wind flags.
 *
 * @param list<array<string, mixed>> $sourceEnds
 * @param array<string, mixed> $configRow
 * @param array<string, mixed> $calmWind
 * @return list<array<string, mixed>>
 */
function runwayDisplayFormatEnds(
    array $sourceEnds,
    array $configRow,
    array $calmWind,
    ?float $declinationDeg
): array {
    $formatted = [];
    foreach ($sourceEnds as $end) {
        if (!is_array($end)) {
            continue;
        }
        $endId = (string) ($end['end_id'] ?? '');
        if ($endId === '') {
            continue;
        }
        $canonical = canonicalizeRunwayEndIdent($endId) ?? strtoupper($endId);
        $headingMag = runwayDisplayMagneticHeadingForEnd($end, $declinationDeg);

        $rightHand = $end['right_hand_traffic'] ?? null;
        if (isset($configRow['ends']) && is_array($configRow['ends'])) {
            foreach ($configRow['ends'] as $configEnd) {
                if (!is_array($configEnd)) {
                    continue;
                }
                $configEndId = canonicalizeRunwayEndIdent((string) ($configEnd['end_id'] ?? ''));
                if ($configEndId === $canonical) {
                    if (isset($configEnd['right_hand_traffic'])) {
                        $rightHand = (bool) $configEnd['right_hand_traffic'];
                    }
                    break;
                }
            }
        }

        $formatted[] = [
            'end_id' => $canonical,
            'heading_mag' => $headingMag,
            'right_hand_traffic' => $rightHand === true,
            'calm_wind_arrival' => ($calmWind['arrival'] ?? null) === $canonical,
            'calm_wind_departure' => ($calmWind['departure'] ?? null) === $canonical,
        ];
    }

    if ($formatted === [] && isset($configRow['ends']) && is_array($configRow['ends'])) {
        foreach ($configRow['ends'] as $configEnd) {
            if (!is_array($configEnd)) {
                continue;
            }
            $endId = canonicalizeRunwayEndIdent((string) ($configEnd['end_id'] ?? ''));
            if ($endId === null) {
                continue;
            }
            $formatted[] = [
                'end_id' => $endId,
                'heading_mag' => isset($configEnd['heading_mag']) && is_numeric($configEnd['heading_mag'])
                    ? (int) round((float) $configEnd['heading_mag'])
                    : parseRunwayEndIdentMagneticHeading($endId),
                'right_hand_traffic' => !empty($configEnd['right_hand_traffic']),
                'calm_wind_arrival' => ($calmWind['arrival'] ?? null) === $endId,
                'calm_wind_departure' => ($calmWind['departure'] ?? null) === $endId,
            ];
        }
    }

    return $formatted;
}

/**
 * Resolve calm wind designation for a runway pair.
 *
 * @param array<string, mixed> $configRow
 * @param array<string, mixed> $airportCalmWind NASR airport-level calm_wind
 * @param array<string, mixed> $oaRow OurAirports row calm_wind if any
 * @return array{arrival: ?string, departure: ?string}
 */
function runwayDisplayResolveCalmWind(array $configRow, array $airportCalmWind, array $oaRow): array
{
    $arrival = null;
    $departure = null;

    if (!empty($configRow['calm_wind_arrival'])) {
        $arrival = canonicalizeRunwayEndIdent((string) $configRow['calm_wind_arrival']);
    } elseif (!empty($airportCalmWind['arrival'])) {
        $arrival = canonicalizeRunwayEndIdent((string) $airportCalmWind['arrival']);
    } elseif (!empty($oaRow['calm_wind_arrival'])) {
        $arrival = canonicalizeRunwayEndIdent((string) $oaRow['calm_wind_arrival']);
    }

    if (!empty($configRow['calm_wind_departure'])) {
        $departure = canonicalizeRunwayEndIdent((string) $configRow['calm_wind_departure']);
    } elseif (!empty($airportCalmWind['departure'])) {
        $departure = canonicalizeRunwayEndIdent((string) $airportCalmWind['departure']);
    } elseif (!empty($oaRow['calm_wind_departure'])) {
        $departure = canonicalizeRunwayEndIdent((string) $oaRow['calm_wind_departure']);
    }

    return ['arrival' => $arrival, 'departure' => $departure];
}

/**
 * Whether a runway row is a helicopter landing pad (FAA NASR designator H1, H2, etc.).
 *
 * Helipads have no published runway heading; per-end headwind and crosswind are not
 * meaningful and must not be shown on dashboard cards.
 */
function runwayDisplayIsHelipad(string $rwyId): bool
{
    return (bool) preg_match('/^H\d+$/i', trim($rwyId));
}

/**
 * Whether a runway card should show closed from active NOTAM full closures.
 *
 * Matches density altitude performance NOTAM semantics: aerodrome closure,
 * full pair closure, or every published end closed by single-end NOTAMs.
 *
 * @param list<array<string, mixed>> $ends
 * @param array{aerodrome_closed: bool, closed_pair_designators: list<string>, closed_end_idents: list<string>} $notamClosures
 */
function runwayDisplayRunwayClosedFromNotam(string $rwyId, array $ends, array $notamClosures): bool
{
    if ($notamClosures['aerodrome_closed'] ?? false) {
        return true;
    }

    foreach ($notamClosures['closed_pair_designators'] ?? [] as $pair) {
        if (densityAltitudePerformanceRunwayPairMatchesDesignator($rwyId, $pair)) {
            return true;
        }
    }

    $closedEndIdents = $notamClosures['closed_end_idents'] ?? [];
    if ($ends === [] || $closedEndIdents === []) {
        return false;
    }

    $hasEnd = false;
    foreach ($ends as $end) {
        if (!is_array($end)) {
            continue;
        }
        $endIdent = canonicalizeRunwayEndIdent((string) ($end['end_id'] ?? ''));
        if ($endIdent === null) {
            continue;
        }
        $hasEnd = true;
        if (!in_array($endIdent, $closedEndIdents, true)) {
            return false;
        }
    }

    return $hasEnd;
}

/**
 * Format one runway row for API/dashboard display.
 *
 * @param array<string, mixed> $sourceRow
 * @param array<string, mixed> $configRow
 * @param array<string, mixed> $airportCalmWind
 * @param array<string, mixed> $oaRow
 * @param array{aerodrome_closed: bool, closed_pair_designators: list<string>, closed_end_idents: list<string>} $notamClosures
 */
function runwayDisplayFormatRunwayRow(
    array $sourceRow,
    array $configRow,
    array $airportCalmWind,
    array $oaRow,
    array $notamClosures,
    string $sourceName,
    ?float $declinationDeg
): ?array {
    $rwyId = (string) ($sourceRow['rwy_id'] ?? '');
    if ($rwyId === '') {
        return null;
    }

    [$lengthFt, $lengthSource] = runwayDisplayResolveField(
        $configRow,
        $sourceRow['length_ft'] ?? null,
        $configRow['length_ft'] ?? null,
        $sourceName
    );
    if (!is_numeric($lengthFt) || (int) $lengthFt <= 0) {
        return null;
    }
    $lengthFt = (int) $lengthFt;

    [$widthFt, $widthSource] = runwayDisplayResolveFieldWithFallback(
        $configRow,
        $sourceRow['width_ft'] ?? null,
        $oaRow['width_ft'] ?? null,
        $configRow['width_ft'] ?? null,
        $sourceName
    );
    $widthFt = is_numeric($widthFt) ? (int) $widthFt : null;

    [$surfaceCode, $surfaceSource] = runwayDisplayResolveFieldWithFallback(
        $configRow,
        $sourceRow['surface'] ?? null,
        $oaRow['surface'] ?? null,
        $configRow['surface'] ?? null,
        $sourceName
    );
    $surfaceCode = is_string($surfaceCode) ? strtoupper(trim($surfaceCode)) : null;
    $surfaceLabel = runwayDisplaySurfaceLabel($surfaceCode);

    $nasrLights = nasrRunwayLightsLabel($sourceRow['lights_code'] ?? null);
    $oaLights = runwayDisplayOurAirportsLightsLabel(
        isset($oaRow['lighted']) ? (bool) $oaRow['lighted'] : null
    );
    [$lights, $lightsSource] = runwayDisplayResolveFieldWithFallback(
        $configRow,
        $nasrLights,
        $oaLights,
        $configRow['lights'] ?? null,
        $sourceName
    );

    $sourceEnds = is_array($sourceRow['ends'] ?? null) ? $sourceRow['ends'] : [];
    if ($sourceEnds === [] && str_contains($rwyId, '/')) {
        [$le, $he] = explode('/', $rwyId, 2);
        if ($le !== '') {
            $sourceEnds[] = [
                'end_id' => trim($le),
                'heading_mag' => $sourceRow['heading_1'] ?? null,
            ];
        }
        if ($he !== '') {
            $sourceEnds[] = [
                'end_id' => trim($he),
                'heading_mag' => $sourceRow['heading_2'] ?? null,
            ];
        }
    }
    $calmWind = runwayDisplayResolveCalmWind($configRow, $airportCalmWind, $oaRow);
    $ends = runwayDisplayFormatEnds($sourceEnds, $configRow, $calmWind, $declinationDeg);

    $closed = nasrRunwayIsClosedInSource($sourceRow) || !empty($oaRow['closed']);
    if (isset($configRow['closed'])) {
        $closed = (bool) $configRow['closed'];
    }
    if (runwayDisplayRunwayClosedFromNotam($rwyId, $ends, $notamClosures)) {
        $closed = true;
    }

    $traffic = $configRow['traffic'] ?? runwayDisplayTrafficNote($sourceEnds);
    if ($traffic === null && $ends !== []) {
        $traffic = runwayDisplayTrafficNote($ends);
    }

    $fieldSources = array_filter([
        'length_ft' => $lengthSource,
        'width_ft' => $widthSource,
        'surface' => $surfaceSource,
        'lights' => $lightsSource,
    ]);

    return [
        'rwy_id' => $rwyId,
        'length_ft' => $lengthFt,
        'width_ft' => $widthFt,
        'surface' => $surfaceLabel,
        'surface_code' => $surfaceCode,
        'lights' => $lights,
        'traffic' => $traffic,
        'closed' => $closed,
        'is_helipad' => runwayDisplayIsHelipad($rwyId),
        'ends' => $ends,
        'field_sources' => $fieldSources,
        'row_source' => $sourceName,
    ];
}

/**
 * Magnetic declination for runway heading conversion when known.
 *
 * Returns null when no config/global override and no lat/lon for WMM, so
 * runwayDisplayMagneticHeadingForEnd() can prefer end-ident parsing over
 * treating true_alignment as magnetic with a zero default.
 */
function runwayDisplayMagneticDeclinationDeg(?array $airport): ?float
{
    if ($airport !== null && isset($airport['magnetic_declination']) && is_numeric($airport['magnetic_declination'])) {
        return (float) $airport['magnetic_declination'];
    }
    if (($global = getGlobalConfig('magnetic_declination')) !== null && is_numeric($global)) {
        return (float) $global;
    }
    if (
        $airport !== null
        && isset($airport['lat'], $airport['lon'])
        && is_numeric($airport['lat'])
        && is_numeric($airport['lon'])
    ) {
        return (float) getMagneticDeclination($airport);
    }

    return null;
}

/**
 * Build runway display payload for an airport.
 *
 * @param array<string, mixed> $airport
 * @param array{include_notam_closures?: bool, notam_closures?: array{aerodrome_closed: bool, closed_pair_designators: list<string>, closed_end_idents: list<string>}} $options
 * @return array{
 *   runway_source: ?string,
 *   source_reference: ?string,
 *   effective_date: ?string,
 *   runways: list<array<string, mixed>>
 * }|null
 */
function getRunwayDisplayForAirport(array $airport, ?string $airportId = null, array $options = []): ?array
{
    $includeNotamClosures = ($options['include_notam_closures'] ?? true) === true;
    $resolvedAirportId = $airportId ?? (string) ($airport['id'] ?? $airport['icao'] ?? '');
    $configFacts = runwayDisplayConfigFactsByRunwayId($airport);
    $nasrRecord = getNasrAirportForConfig($airport);
    $declination = runwayDisplayMagneticDeclinationDeg($airport);

    $notamClosures = [
        'aerodrome_closed' => false,
        'closed_pair_designators' => [],
        'closed_end_idents' => [],
    ];
    if ($includeNotamClosures && $resolvedAirportId !== '') {
        if (isset($options['notam_closures']) && is_array($options['notam_closures'])) {
            $notamClosures = $options['notam_closures'];
        } else {
            $notamClosures = getActiveRunwayNotamClosuresForAirport($resolvedAirportId, $airport);
        }
    }

    $sourceRunways = [];
    $runwaySource = null;
    $sourceReference = null;
    $effectiveDate = null;

    if ($nasrRecord !== null) {
        $sourceRunways = nasrSelectRunwaysForDisplay($nasrRecord);
        if ($sourceRunways !== []) {
            $runwaySource = 'nasr';
            $sourceReference = 'FAA NASR';
            $effectiveDate = $nasrRecord['effective_date'] ?? null;
        }
    }

    $oaDisplayRunways = null;
    if ($resolvedAirportId !== '') {
        $oaDisplayRunways = loadOurAirportsDisplayRunwaysFromFileCache($resolvedAirportId, $airport);
    }

    if ($sourceRunways === [] && $oaDisplayRunways !== null && $oaDisplayRunways !== []) {
        $sourceRunways = $oaDisplayRunways;
        $runwaySource = 'ourairports';
        $sourceReference = 'OurAirports';
    }

    $configRunway = buildConfigRunwayForDensityAltitude($airport);
    if ($sourceRunways === [] && $configRunway !== null) {
        $sourceRunways = [$configRunway];
        $runwaySource = 'config';
        $sourceReference = 'Operator configuration';
    }

    if ($sourceRunways === []) {
        return null;
    }

    $oaById = [];
    if (is_array($oaDisplayRunways)) {
        foreach ($oaDisplayRunways as $oaRow) {
            if (!is_array($oaRow)) {
                continue;
            }
            $key = densityAltitudePerformanceNormalizeRunwayPairKey((string) ($oaRow['rwy_id'] ?? ''));
            if ($key !== '') {
                $oaById[$key] = $oaRow;
            }
        }
    }

    $airportCalmWind = is_array($nasrRecord) && is_array($nasrRecord['calm_wind'] ?? null)
        ? $nasrRecord['calm_wind']
        : [];
    $formatted = [];
    foreach ($sourceRunways as $sourceRow) {
        if (!is_array($sourceRow)) {
            continue;
        }
        $rwyKey = densityAltitudePerformanceNormalizeRunwayPairKey((string) ($sourceRow['rwy_id'] ?? ''));
        $configRow = $configFacts[$rwyKey] ?? [];
        $oaRow = $oaById[$rwyKey] ?? [];
        $rowSource = $runwaySource ?? 'unknown';

        $row = runwayDisplayFormatRunwayRow(
            $sourceRow,
            $configRow,
            $airportCalmWind,
            $oaRow,
            $notamClosures,
            $rowSource,
            $declination
        );
        if ($row !== null) {
            $formatted[] = $row;
        }
    }

    if ($formatted === []) {
        return null;
    }

    return [
        'runway_source' => $runwaySource,
        'source_reference' => $sourceReference,
        'effective_date' => $effectiveDate,
        'runways' => $formatted,
    ];
}

/**
 * Strip one resolved runway row to static airport-metadata facts (no wind or NOTAM context).
 *
 * @param array<string, mixed> $row Resolved row from runwayDisplayFormatRunwayRow()
 * @return array<string, mixed>
 */
function runwayDisplayFormatRunwayFactsRow(array $row): array
{
    $facts = [
        'rwy_id' => $row['rwy_id'],
        'length_ft' => $row['length_ft'],
        'surface' => $row['surface'],
        'surface_code' => $row['surface_code'],
        'lights' => $row['lights'],
        'closed' => $row['closed'],
        'is_helipad' => !empty($row['is_helipad']),
        'field_sources' => $row['field_sources'] ?? [],
        'ends' => [],
    ];

    if (isset($row['width_ft']) && is_numeric($row['width_ft'])) {
        $facts['width_ft'] = (int) $row['width_ft'];
    }

    foreach ($row['ends'] ?? [] as $end) {
        if (!is_array($end)) {
            continue;
        }
        $endId = $end['end_id'] ?? null;
        if (!is_string($endId) || $endId === '') {
            continue;
        }
        $endFacts = ['end_id' => $endId];
        if (isset($end['heading_mag']) && is_numeric($end['heading_mag'])) {
            $endFacts['heading_mag'] = (int) $end['heading_mag'];
        }
        $facts['ends'][] = $endFacts;
    }

    return $facts;
}

/**
 * Resolved static runway inventory for Public API airport metadata.
 *
 * Omits calm-wind designation, traffic notes, and NOTAM-derived closure (metadata
 * is cached longer than live weather). Source-record closed flags remain.
 *
 * @param array<string, mixed> $airport
 * @return array{
 *   runway_source: ?string,
 *   source_reference: ?string,
 *   effective_date: ?string,
 *   runways: list<array<string, mixed>>
 * }|null
 */
function formatRunwayFactsForAirportApi(array $airport, ?string $airportId = null): ?array
{
    $display = getRunwayDisplayForAirport($airport, $airportId, ['include_notam_closures' => false]);
    if ($display === null) {
        return null;
    }

    $runways = [];
    foreach ($display['runways'] as $row) {
        if (!is_array($row)) {
            continue;
        }
        $runways[] = runwayDisplayFormatRunwayFactsRow($row);
    }

    if ($runways === []) {
        return null;
    }

    return [
        'runway_source' => $display['runway_source'],
        'source_reference' => $display['source_reference'],
        'effective_date' => $display['effective_date'],
        'runways' => $runways,
    ];
}

/**
 * Attach runway_display to a weather API payload.
 *
 * @param array<string, mixed> $weather
 * @param array<string, mixed> $airport
 */
function attachRunwayDisplay(array $weather, array $airport, ?string $airportId = null): array
{
    $resolvedAirportId = $airportId ?? (string) ($airport['id'] ?? $airport['icao'] ?? '');
    $options = [];
    if ($resolvedAirportId !== '') {
        $options['notam_closures'] = getActiveRunwayNotamClosuresForAirport($resolvedAirportId, $airport);
    }

    $display = getRunwayDisplayForAirport($airport, $airportId, $options);
    if ($display !== null) {
        $weather['runway_display'] = $display;
    }

    return $weather;
}
