<?php
/**
 * SAFETY-CRITICAL: Density altitude performance assessment from reference AFM takeoff charts.
 */

require_once __DIR__ . '/../constants.php';
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../nasr/cache.php';
require_once __DIR__ . '/../nasr/runway-selection.php';
require_once __DIR__ . '/../runways.php';
require_once __DIR__ . '/cache-utils.php';
require_once __DIR__ . '/da-performance-departure-obstruction.php';
require_once __DIR__ . '/da-performance-notam-closures.php';
require_once __DIR__ . '/poh-takeoff.php';
require_once __DIR__ . '/history.php';
require_once __DIR__ . '/utils.php';
require_once __DIR__ . '/../heading-conversion.php';

/** @var list<string> */
const DENSITY_ALTITUDE_PERFORMANCE_MODEL_KEYS = ['c152', 'c172', 'c182'];

/**
 * Map stress ratio directly to 0-1 profile risk.
 */
function calculatePerformanceProfileRiskFromStress(float $stress): float
{
    $span = PERFORMANCE_STRESS_HIGH - PERFORMANCE_STRESS_LOW;
    if ($span <= 0) {
        return 1.0;
    }

    $risk = ($stress - PERFORMANCE_STRESS_LOW) / $span;

    return max(0.0, min(1.0, $risk));
}

/**
 * Sum unweighted profile risks across C152/C172/C182 reference models (range 0-3).
 */
function calculateSummedPerformanceRisk(float $risk152, float $risk172, float $risk182): float
{
    $total = $risk152 + $risk172 + $risk182;

    return max(0.0, min(3.0, $total));
}

/**
 * Map summed profile risk (0-3) to caution/warning tier from one scored departure end.
 *
 * Thresholds: caution >= {@see DENSITY_ALTITUDE_PERFORMANCE_TIER_CAUTION},
 * warning >= {@see DENSITY_ALTITUDE_PERFORMANCE_TIER_WARNING}.
 */
function densityAltitudePerformanceTierFromScoredEnd(float $scoredRisk): string
{
    if ($scoredRisk >= DENSITY_ALTITUDE_PERFORMANCE_TIER_WARNING) {
        return 'warning';
    }
    if ($scoredRisk >= DENSITY_ALTITUDE_PERFORMANCE_TIER_CAUTION) {
        return 'caution';
    }
    return 'normal';
}

/**
 * Attach runway id and alignment to a scored departure end row.
 *
 * @param array $scored Scored end row
 * @param array $runway Parent runway row
 * @param array $end Runway end row scored for departure
 * @return array{
 *     total_risk: float,
 *     end_id: ?string,
 *     rwy_id: ?string,
 *     true_alignment: ?int,
 *     risk152: float,
 *     risk172: float,
 *     risk182: float
 * }
 */
function annotateScoredRunwayEnd(array $scored, array $runway, array $end): array
{
    $scored['rwy_id'] = isset($runway['rwy_id']) ? (string) $runway['rwy_id'] : null;
    if (isset($end['true_alignment']) && is_numeric($end['true_alignment'])) {
        $scored['true_alignment'] = (int) round((float) $end['true_alignment']);
    } else {
        $scored['true_alignment'] = null;
    }

    return $scored;
}

/**
 * Resolve wind from the current weather row for runway tie-breaking fallback.
 *
 * @return array{direction: ?float, speed: ?float}
 */
function resolveDensityAltitudePerformanceSnapshotWind(array $weather): array
{
    $windDirection = $weather['wind_direction'] ?? null;
    $isVariable = ($weather['wind_direction_text'] ?? '') === 'VRB';

    if (is_array($windDirection)) {
        $isVariable = $isVariable || !empty($windDirection['variable']);
        $direction = $windDirection['true_north'] ?? null;
    } elseif (is_string($windDirection) && strtoupper($windDirection) === 'VRB') {
        $isVariable = true;
        $direction = null;
    } else {
        $direction = is_numeric($windDirection) ? (float) $windDirection : null;
    }

    if ($isVariable) {
        $direction = null;
    }

    $speed = isset($weather['wind_speed']) && is_numeric($weather['wind_speed'])
        ? (float) $weather['wind_speed']
        : null;

    return [
        'direction' => $direction,
        'speed' => $speed,
    ];
}

/**
 * Resolve wind for equal-risk runway tie-breaking only (not POH scoring).
 *
 * Prefers vector mean wind from weather history over the wind rose window
 * ({@see computeWindowMeanWind()}). Falls back to the current observation when
 * history is disabled or quality gates fail.
 *
 * @return array{direction: ?float, speed: ?float}
 */
function resolveDensityAltitudePerformanceWind(
    array $weather,
    array $airport = [],
    ?string $airportId = null
): array {
    $resolvedAirportId = $airportId ?? (string) ($airport['id'] ?? $airport['icao'] ?? '');
    if ($resolvedAirportId !== '') {
        $mean = computeWindowMeanWind($resolvedAirportId, $airport);
        if ($mean !== null) {
            $declination = getMagneticDeclination($airport);

            return [
                'direction' => convertMagneticToTrue($mean['direction_magnetic'], $declination),
                'speed' => $mean['speed_kts'],
            ];
        }
    }

    return resolveDensityAltitudePerformanceSnapshotWind($weather);
}

/**
 * Crosswind component in knots for a runway heading (wind direction is FROM).
 */
function densityAltitudePerformanceCrosswindKts(
    ?float $windFromDeg,
    ?float $windSpeedKts,
    ?int $runwayHeadingDeg
): ?float {
    if ($windFromDeg === null || $windSpeedKts === null || $runwayHeadingDeg === null || $windSpeedKts <= 0) {
        return null;
    }

    $delta = deg2rad($runwayHeadingDeg - $windFromDeg);

    return abs($windSpeedKts * sin($delta));
}

/**
 * Headwind component in knots for a runway heading (positive = headwind).
 */
function densityAltitudePerformanceHeadwindKts(
    ?float $windFromDeg,
    ?float $windSpeedKts,
    ?int $runwayHeadingDeg
): ?float {
    if ($windFromDeg === null || $windSpeedKts === null || $runwayHeadingDeg === null) {
        return null;
    }

    $delta = deg2rad($runwayHeadingDeg - $windFromDeg);

    return $windSpeedKts * cos($delta);
}

/**
 * Compare two scored ends for global best selection (lower risk wins).
 */
function compareScoredEndsForBestDeparture(
    array $left,
    array $right,
    ?float $windFromDeg,
    ?float $windSpeedKts
): int {
    $riskCmp = $left['total_risk'] <=> $right['total_risk'];
    if ($riskCmp !== 0) {
        return $riskCmp;
    }

    $leftCross = densityAltitudePerformanceCrosswindKts(
        $windFromDeg,
        $windSpeedKts,
        $left['true_alignment'] ?? null
    );
    $rightCross = densityAltitudePerformanceCrosswindKts(
        $windFromDeg,
        $windSpeedKts,
        $right['true_alignment'] ?? null
    );
    if ($leftCross !== null && $rightCross !== null) {
        $crossCmp = $leftCross <=> $rightCross;
        if ($crossCmp !== 0) {
            return $crossCmp;
        }

        $leftHead = densityAltitudePerformanceHeadwindKts(
            $windFromDeg,
            $windSpeedKts,
            $left['true_alignment'] ?? null
        );
        $rightHead = densityAltitudePerformanceHeadwindKts(
            $windFromDeg,
            $windSpeedKts,
            $right['true_alignment'] ?? null
        );
        if ($leftHead !== null && $rightHead !== null) {
            $headCmp = $rightHead <=> $leftHead;
            if ($headCmp !== 0) {
                return $headCmp;
            }
        }
    }

    $rwyCmp = strcmp((string) ($left['rwy_id'] ?? ''), (string) ($right['rwy_id'] ?? ''));
    if ($rwyCmp !== 0) {
        return $rwyCmp;
    }

    return strcmp((string) ($left['end_id'] ?? ''), (string) ($right['end_id'] ?? ''));
}

/**
 * Compare two scored ends for global worst selection (higher risk wins).
 */
function compareScoredEndsForWorstDeparture(
    array $left,
    array $right,
    ?float $windFromDeg,
    ?float $windSpeedKts
): int {
    $riskCmp = $right['total_risk'] <=> $left['total_risk'];
    if ($riskCmp !== 0) {
        return $riskCmp;
    }

    $leftCross = densityAltitudePerformanceCrosswindKts(
        $windFromDeg,
        $windSpeedKts,
        $left['true_alignment'] ?? null
    );
    $rightCross = densityAltitudePerformanceCrosswindKts(
        $windFromDeg,
        $windSpeedKts,
        $right['true_alignment'] ?? null
    );
    if ($leftCross !== null && $rightCross !== null) {
        $crossCmp = $rightCross <=> $leftCross;
        if ($crossCmp !== 0) {
            return $crossCmp;
        }
    }

    $rwyCmp = strcmp((string) ($right['rwy_id'] ?? ''), (string) ($left['rwy_id'] ?? ''));
    if ($rwyCmp !== 0) {
        return $rwyCmp;
    }

    return strcmp((string) ($right['end_id'] ?? ''), (string) ($left['end_id'] ?? ''));
}

/**
 * Score one runway departure end using POH chart distances.
 *
 * Obstructions are resolved from the reciprocal end's approach-side NASR/config filing.
 *
 * @param array $end Runway end row scored for departure
 * @param array $runway Selected runway with length_ft and ends[]
 * @param array{c152: array, c172: array, c182: array} $tables
 * @return array{total_risk: float, end_id: ?string, risk152: float, risk172: float, risk182: float}
 */
function evaluateSingleRunwayEndPerformance(
    array $end,
    array $runway,
    int $availableFt,
    bool $nonPaved,
    float $pressureAltitudeFt,
    float $tempC,
    array $tables
): array {
    $resolved = resolveDepartureObstructionForEnd($end, $runway);
    $obstHgt = $resolved['hgt_ft'];
    $obstDist = $resolved['dist_ft'];
    $obstSlope = $resolved['slope'];

    $risk152 = 0.0;
    $risk172 = 0.0;
    $risk182 = 0.0;

    foreach (DENSITY_ALTITUDE_PERFORMANCE_MODEL_KEYS as $modelKey) {
        $stress = pohComputeDepartureEndStress(
            $tables[$modelKey],
            $pressureAltitudeFt,
            $tempC,
            $nonPaved,
            $availableFt,
            $obstHgt,
            $obstDist,
            $obstSlope
        );
        $risk = calculatePerformanceProfileRiskFromStress($stress);
        if ($modelKey === 'c152') {
            $risk152 = $risk;
        } elseif ($modelKey === 'c172') {
            $risk172 = $risk;
        } else {
            $risk182 = $risk;
        }
    }

    return [
        'total_risk' => calculateSummedPerformanceRisk($risk152, $risk172, $risk182),
        'end_id' => $end['end_id'] ?? null,
        'risk152' => $risk152,
        'risk172' => $risk172,
        'risk182' => $risk182,
    ];
}

/**
 * Evaluate best and worst departure ends on one runway.
 *
 * @param array $runway Selected runway with ends[]
 * @param array{c152: array, c172: array, c182: array} $tables
 * @return array{
 *     worst: array{total_risk: float, end_id: ?string, rwy_id: ?string, true_alignment: ?int, risk152: float, risk172: float, risk182: float},
 *     best: array{total_risk: float, end_id: ?string, rwy_id: ?string, true_alignment: ?int, risk152: float, risk172: float, risk182: float}
 * }
 */
function evaluateRunwayEndPerformanceRange(
    array $runway,
    float $pressureAltitudeFt,
    float $tempC,
    array $tables,
    ?float $windFromDeg = null,
    ?float $windSpeedKts = null
): array {
    return pickBestWorstScoredEnds(
        scoreAllRunwayEndsForPerformance([$runway], $pressureAltitudeFt, $tempC, $tables),
        $windFromDeg,
        $windSpeedKts
    );
}

/**
 * Score every departure end on the selected performance runways.
 *
 * @param list<array> $performanceRunways Selected runways with ends[]
 * @param array{c152: array, c172: array, c182: array} $tables
 * @return list<array{
 *     total_risk: float,
 *     end_id: ?string,
 *     rwy_id: ?string,
 *     true_alignment: ?int,
 *     risk152: float,
 *     risk172: float,
 *     risk182: float
 * }>
 */
function scoreAllRunwayEndsForPerformance(
    array $performanceRunways,
    float $pressureAltitudeFt,
    float $tempC,
    array $tables
): array {
    $scoredEnds = [];

    foreach ($performanceRunways as $runway) {
        if (!is_array($runway)) {
            continue;
        }

        $runwayLengthFt = (int) ($runway['length_ft'] ?? 0);
        $surface = (string) ($runway['surface'] ?? '');
        $nonPaved = nasrIsNonPavedSurface($surface);

        $ends = $runway['ends'] ?? [];
        if ($ends === []) {
            // Length/surface stress only when NASR or OurAirports rows lack per-end data.
            $ends = [['end_id' => null, 'obstruction' => []]];
        }

        foreach ($ends as $end) {
            if (!is_array($end)) {
                continue;
            }

            $availableFt = nasrEffectiveDepartureLengthFt($end, $runwayLengthFt);
            $scored = evaluateSingleRunwayEndPerformance(
                $end,
                $runway,
                $availableFt,
                $nonPaved,
                $pressureAltitudeFt,
                $tempC,
                $tables
            );
            $scoredEnds[] = annotateScoredRunwayEnd($scored, $runway, $end);
        }
    }

    return $scoredEnds;
}

/**
 * Pick global best and worst scored departure ends from a flat score list.
 *
 * Equal-risk ties on the best end prefer the lowest crosswind, then the strongest headwind,
 * then stable runway/end id ordering.
 *
 * @param list<array{
 *     total_risk: float,
 *     end_id: ?string,
 *     rwy_id: ?string,
 *     true_alignment: ?int,
 *     risk152: float,
 *     risk172: float,
 *     risk182: float
 * }> $scoredEnds
 * @return array{
 *     worst: array{total_risk: float, end_id: ?string, rwy_id: ?string, true_alignment: ?int, risk152: float, risk172: float, risk182: float},
 *     best: array{total_risk: float, end_id: ?string, rwy_id: ?string, true_alignment: ?int, risk152: float, risk172: float, risk182: float}
 * }
 */
function pickBestWorstScoredEnds(
    array $scoredEnds,
    ?float $windFromDeg = null,
    ?float $windSpeedKts = null
): array {
    if ($scoredEnds === []) {
        $empty = [
            'total_risk' => 0.0,
            'end_id' => null,
            'rwy_id' => null,
            'true_alignment' => null,
            'risk152' => 0.0,
            'risk172' => 0.0,
            'risk182' => 0.0,
        ];

        return ['worst' => $empty, 'best' => $empty];
    }

    $best = $scoredEnds[0];
    $worst = $scoredEnds[0];

    foreach ($scoredEnds as $scored) {
        if (compareScoredEndsForBestDeparture($scored, $best, $windFromDeg, $windSpeedKts) < 0) {
            $best = $scored;
        }
        if (compareScoredEndsForWorstDeparture($scored, $worst, $windFromDeg, $windSpeedKts) < 0) {
            $worst = $scored;
        }
    }

    return [
        'worst' => $worst,
        'best' => $best,
    ];
}

/**
 * Global best and worst departure ends across all open performance runways.
 *
 * Tier uses the best end only; worst is retained for API transparency.
 *
 * @param list<array> $performanceRunways Selected runways with ends[]
 * @param array{c152: array, c172: array, c182: array} $tables
 * @return array{
 *     worst: array{total_risk: float, end_id: ?string, rwy_id: ?string, true_alignment: ?int, risk152: float, risk172: float, risk182: float},
 *     best: array{total_risk: float, end_id: ?string, rwy_id: ?string, true_alignment: ?int, risk152: float, risk172: float, risk182: float}
 * }
 */
function evaluateAirportRunwayEndPerformanceRange(
    array $performanceRunways,
    float $pressureAltitudeFt,
    float $tempC,
    array $tables,
    ?float $windFromDeg = null,
    ?float $windSpeedKts = null
): array {
    return pickBestWorstScoredEnds(
        scoreAllRunwayEndsForPerformance($performanceRunways, $pressureAltitudeFt, $tempC, $tables),
        $windFromDeg,
        $windSpeedKts
    );
}

/**
 * Downgrade warning when departure obstruction height/distance are unavailable.
 *
 * OurAirports and length-only config cannot justify a warning-tier tree cue.
 */
function densityAltitudePerformanceCapTierWithoutObstructions(string $tier): string
{
    if ($tier === 'warning') {
        return 'caution';
    }

    return $tier;
}

/**
 * Whether warning tier is allowed for the current runway source and rows.
 *
 * @param list<array> $performanceRunways Runways used for scoring
 */
function densityAltitudePerformanceAllowsWarningTier(string $runwaySource, array $performanceRunways): bool
{
    if ($runwaySource === 'ourairports') {
        return false;
    }

    if ($runwaySource === 'config') {
        $configRunway = $performanceRunways[0] ?? null;

        return is_array($configRunway) && configRunwayHasDepartureObstructionData($configRunway);
    }

    return true;
}

/**
 * Whether caution/warning tiers may be surfaced from current weather freshness.
 *
 * @param array<string, mixed> $payload Computed performance payload
 */
function densityAltitudePerformanceMaySurfaceAlert(
    array $weather,
    array $airport,
    array $payload
): bool {
    $isMetarOnly = airportIsMetarOnly($airport);

    if (isWeatherFieldFailclosedStale($weather, 'density_altitude', $airport, $isMetarOnly)) {
        return false;
    }

    foreach (['temperature', 'pressure'] as $field) {
        if (isWeatherFieldFailclosedStale($weather, $field, $airport, $isMetarOnly)) {
            return false;
        }
    }

    if (!empty($payload['fallback'])) {
        return true;
    }

    foreach (['pressure_altitude', 'temperature'] as $field) {
        if (isWeatherFieldFailclosedStale($weather, $field, $airport, $isMetarOnly)) {
            return false;
        }
    }

    return true;
}

/**
 * Weather-only fallback tier when runway data is unavailable.
 *
 * @return array{tier: string, fallback: true, reason: string, reference: string}|null
 */
function assessFallbackDensityAltitudePerformance(?int $densityAltitudeFt, ?int $fieldElevationFt): ?array
{
    if ($densityAltitudeFt === null || $fieldElevationFt === null) {
        return null;
    }

    $delta = $densityAltitudeFt - $fieldElevationFt;
    $tier = 'normal';

    if ($densityAltitudeFt >= 9000 || $delta >= 3500) {
        $tier = 'warning';
    } elseif ($fieldElevationFt < 2500) {
        if ($delta >= 2000) {
            $tier = 'caution';
        }
    } elseif ($fieldElevationFt < 5000) {
        if (($densityAltitudeFt >= 7000 && $delta >= 1800) || $delta >= 2200) {
            $tier = 'caution';
        }
    } elseif ($densityAltitudeFt >= 8500 && $delta >= 1500) {
        $tier = 'caution';
    }

    if ($tier === 'normal') {
        return null;
    }

    return [
        'tier' => $tier,
        'fallback' => true,
        'reason' => 'density_altitude_only',
        'reference' => DENSITY_ALTITUDE_PERFORMANCE_REFERENCE_FALLBACK,
    ];
}

/**
 * Resolve runway context for density altitude performance scoring.
 *
 * @param array $weather Cached weather row
 * @param array $airport Airport configuration
 * @param string|null $airportId Optional config airport id (defaults to airport id/icao)
 * @return array{
 *     density_altitude_ft: int,
 *     field_elevation_ft: ?int,
 *     performance_runways: list<array>,
 *     runway_source: ?string,
 *     resolved_airport_id: string
 * }|null null when density altitude is missing or non-numeric
 */
function resolveDensityAltitudePerformanceContext(
    array $weather,
    array $airport,
    ?string $airportId = null
): ?array {
    $resolvedAirportId = $airportId ?? (string) ($airport['id'] ?? $airport['icao'] ?? '');

    $densityAltitude = $weather['density_altitude'] ?? null;
    if (!is_numeric($densityAltitude)) {
        return null;
    }

    $nasrRecord = getNasrAirportForConfig($airport);
    $fieldElevationFt = getEffectiveFieldElevationFt($airport, $nasrRecord);

    $performanceRunways = [];
    $runwaySource = null;

    $configRunway = buildConfigRunwayForDensityAltitude($airport);
    if ($configRunway !== null) {
        $performanceRunways = [$configRunway];
        $runwaySource = 'config';
    } elseif ($nasrRecord !== null) {
        $performanceRunways = nasrSelectActiveLandRunwaysForPerformance($nasrRecord);
        if ($performanceRunways !== []) {
            $runwaySource = 'nasr';
        }
    } else {
        $ourAirportsId = $resolvedAirportId !== '' ? $resolvedAirportId : (string) ($airport['icao'] ?? '');
        $performanceRunways = getOurAirportsPerformanceRunwaysForAirport($ourAirportsId, $airport);
        if ($performanceRunways !== []) {
            $runwaySource = 'ourairports';
        }
    }

    if ($resolvedAirportId !== '' && $runwaySource !== 'config') {
        // Operator runway override is explicit; NOTAM closure filtering applies to AIS rows only.
        $performanceRunways = filterPerformanceRunwaysForActiveNotamClosures(
            $performanceRunways,
            $airport,
            $resolvedAirportId
        );
    }

    return [
        'density_altitude_ft' => (int) round((float) $densityAltitude),
        'field_elevation_ft' => $fieldElevationFt,
        'performance_runways' => $performanceRunways,
        'runway_source' => $runwaySource,
        'resolved_airport_id' => $resolvedAirportId,
    ];
}

/**
 * Reference copy for runway scoring payloads.
 *
 * @return array{reason: string, reference: string}
 */
function densityAltitudePerformanceReferenceForRunwaySource(?string $runwaySource): array
{
    $reason = 'reference_models';
    $reference = DENSITY_ALTITUDE_PERFORMANCE_REFERENCE;
    if ($runwaySource === 'config') {
        $reference = DENSITY_ALTITUDE_PERFORMANCE_REFERENCE_CONFIG;
    } elseif ($runwaySource === 'ourairports') {
        $reason = 'reference_models_ourairports';
        $reference = DENSITY_ALTITUDE_PERFORMANCE_REFERENCE_OURAIRPORTS;
    }

    return [
        'reason' => $reason,
        'reference' => $reference,
    ];
}

/**
 * Format one scored departure end for API runway score lists.
 *
 * @param array{
 *     total_risk: float,
 *     end_id: ?string,
 *     rwy_id: ?string,
 *     risk152: float,
 *     risk172: float,
 *     risk182: float
 * } $scoredEnd
 * @param list<array> $performanceRunways Runways used for scoring
 * @return array<string, mixed>
 */
function formatDensityAltitudeRunwayEndScoreForApi(
    array $scoredEnd,
    string $runwaySource,
    array $performanceRunways
): array {
    $tier = densityAltitudePerformanceTierFromScoredEnd($scoredEnd['total_risk']);
    if (!densityAltitudePerformanceAllowsWarningTier($runwaySource, $performanceRunways)) {
        $tier = densityAltitudePerformanceCapTierWithoutObstructions($tier);
    }

    $entry = [
        'total_risk' => round($scoredEnd['total_risk'], 3),
        'tier' => $tier,
    ];

    if ($scoredEnd['end_id'] !== null && $scoredEnd['end_id'] !== '') {
        $entry['end_id'] = (string) $scoredEnd['end_id'];
    }
    if (isset($scoredEnd['rwy_id']) && $scoredEnd['rwy_id'] !== null && $scoredEnd['rwy_id'] !== '') {
        $entry['rwy_id'] = (string) $scoredEnd['rwy_id'];
    }

    return $entry;
}

/**
 * Build the full-model density_altitude_performance payload.
 *
 * @param list<array{
 *     total_risk: float,
 *     end_id: ?string,
 *     rwy_id: ?string,
 *     risk152: float,
 *     risk172: float,
 *     risk182: float
 * }> $scoredEnds
 * @param array{
 *     worst: array{total_risk: float, end_id: ?string, rwy_id: ?string, risk152: float, risk172: float, risk182: float},
 *     best: array{total_risk: float, end_id: ?string, rwy_id: ?string, risk152: float, risk172: float, risk182: float}
 * } $evaluation
 * @param list<array> $performanceRunways Runways used for scoring
 * @return array<string, mixed>
 */
function buildFullModelDensityAltitudePerformancePayload(
    array $scoredEnds,
    array $evaluation,
    string $runwaySource,
    array $performanceRunways,
    string $tier
): array {
    $reference = densityAltitudePerformanceReferenceForRunwaySource($runwaySource);

    $ends = [];
    foreach ($scoredEnds as $scoredEnd) {
        $ends[] = formatDensityAltitudeRunwayEndScoreForApi($scoredEnd, $runwaySource, $performanceRunways);
    }

    usort(
        $ends,
        static function (array $left, array $right): int {
            return $left['total_risk'] <=> $right['total_risk'];
        }
    );

    return [
        'tier' => $tier,
        'fallback' => false,
        'selection_basis' => 'best_performance',
        'reason' => $reference['reason'],
        'reference' => $reference['reference'],
        'runway_source' => $runwaySource,
        'best_end' => formatDensityAltitudeRunwayEndScoreForApi(
            $evaluation['best'],
            $runwaySource,
            $performanceRunways
        ),
        'worst_end' => formatDensityAltitudeRunwayEndScoreForApi(
            $evaluation['worst'],
            $runwaySource,
            $performanceRunways
        ),
        'ends' => $ends,
    ];
}

/**
 * Compute density_altitude_performance for weather API consumers.
 *
 * Returns null when density altitude is missing, or when runway scoring cannot run
 * and the weather-only fallback does not elevate the tier above normal. Otherwise
 * returns the full-model payload (including normal tier) or a caution/warning
 * weather-only fallback payload.
 *
 * Runway selection precedence:
 * 1. `runway_length_ft` / `runway_surface` / optional `runway_ends` in airport config
 * 2. NASR active land runways (full obstruction model)
 * 3. OurAirports active land runways when NASR absent
 * 4. Active NOTAM full closures removed from NASR/OurAirports rows (trustworthy cache only)
 * 5. Weather-only fallback (`assessFallbackDensityAltitudePerformance`)
 *
 * Airport tier maps from the global best-performing departure end.
 *
 * @param array $weather Cached weather row
 * @param array $airport Airport configuration
 * @param string|null $airportId Optional config airport id (defaults to airport id/icao)
 * @return array<string, mixed>|null
 */
function computeDensityAltitudePerformance(array $weather, array $airport, ?string $airportId = null): ?array
{
    $context = resolveDensityAltitudePerformanceContext($weather, $airport, $airportId);
    if ($context === null) {
        return null;
    }

    $performanceRunways = $context['performance_runways'];
    $runwaySource = $context['runway_source'];
    $densityAltitudeFt = $context['density_altitude_ft'];
    $fieldElevationFt = $context['field_elevation_ft'];
    $wind = resolveDensityAltitudePerformanceWind($weather, $airport, $context['resolved_airport_id']);

    if ($performanceRunways === []) {
        return assessFallbackDensityAltitudePerformance($densityAltitudeFt, $fieldElevationFt);
    }

    $pressureAltitude = $weather['pressure_altitude'] ?? null;
    $temperature = $weather['temperature'] ?? null;
    if (!is_numeric($pressureAltitude) || !is_numeric($temperature)) {
        return assessFallbackDensityAltitudePerformance($densityAltitudeFt, $fieldElevationFt);
    }

    $tables = loadPohTakeoffTables();
    $scoredEnds = scoreAllRunwayEndsForPerformance(
        $performanceRunways,
        (float) $pressureAltitude,
        (float) $temperature,
        $tables
    );
    $evaluation = pickBestWorstScoredEnds(
        $scoredEnds,
        $wind['direction'],
        $wind['speed']
    );

    $bestTotalRisk = $evaluation['best']['total_risk'];
    $tier = densityAltitudePerformanceTierFromScoredEnd($bestTotalRisk);
    if (!densityAltitudePerformanceAllowsWarningTier((string) $runwaySource, $performanceRunways)) {
        $tier = densityAltitudePerformanceCapTierWithoutObstructions($tier);
    }

    return buildFullModelDensityAltitudePerformancePayload(
        $scoredEnds,
        $evaluation,
        (string) $runwaySource,
        $performanceRunways,
        $tier
    );
}

/**
 * Attach density_altitude_performance when the model can run.
 *
 * Caution and warning tiers are omitted when supporting weather fields are fail-closed stale.
 *
 * @param array $weather Weather array (not modified)
 * @param array $airport Airport configuration
 * @param string|null $airportId Optional config airport id
 * @return array Weather array with optional density_altitude_performance
 */
function attachDensityAltitudePerformance(array $weather, array $airport, ?string $airportId = null): array
{
    unset($weather['density_altitude_performance']);

    $payload = computeDensityAltitudePerformance($weather, $airport, $airportId);
    if ($payload === null) {
        return $weather;
    }

    $tier = (string) ($payload['tier'] ?? 'normal');
    if (
        in_array($tier, ['caution', 'warning'], true)
        && !densityAltitudePerformanceMaySurfaceAlert($weather, $airport, $payload)
    ) {
        return $weather;
    }

    $weather['density_altitude_performance'] = $payload;

    return $weather;
}
