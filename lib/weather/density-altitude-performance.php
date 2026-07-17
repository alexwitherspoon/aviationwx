<?php
/**
 * SAFETY-CRITICAL: Density altitude performance assessment from reference AFM takeoff charts.
 */

require_once __DIR__ . '/../constants.php';
require_once __DIR__ . '/../nasr/cache.php';
require_once __DIR__ . '/../nasr/runway-selection.php';
require_once __DIR__ . '/../runways.php';
require_once __DIR__ . '/da-performance-runway-end.php';
require_once __DIR__ . '/poh-takeoff.php';

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
 * Map summed profile risk to tier when worst and best ends are identical.
 */
function densityAltitudePerformanceTierForRisk(float $totalRisk): string
{
    return densityAltitudePerformanceTierFromScoredEnd($totalRisk);
}

/**
 * Legacy tier mapping: caution from worst end, warning from best end.
 *
 * Used only by fleet audit comparison tooling, not production weather responses.
 */
function densityAltitudePerformanceTierFromEndRisks(float $worstTotalRisk, float $bestTotalRisk): string
{
    if ($bestTotalRisk >= DENSITY_ALTITUDE_PERFORMANCE_TIER_WARNING) {
        return 'warning';
    }
    if ($worstTotalRisk >= DENSITY_ALTITUDE_PERFORMANCE_TIER_CAUTION) {
        return 'caution';
    }
    return 'normal';
}

/**
 * Map a single scored departure end risk to tier.
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
 * Attach runway id to a scored departure end row.
 *
 * @param array $scored Scored end row
 * @param array $runway Parent runway row
 * @return array{total_risk: float, end_id: ?string, rwy_id: ?string, risk152: float, risk172: float, risk182: float}
 */
function annotateScoredRunwayEnd(array $scored, array $runway): array
{
    $scored['rwy_id'] = isset($runway['rwy_id']) ? (string) $runway['rwy_id'] : null;

    return $scored;
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
 *     worst: array{total_risk: float, end_id: ?string, risk152: float, risk172: float, risk182: float},
 *     best: array{total_risk: float, end_id: ?string, risk152: float, risk172: float, risk182: float}
 * }
 */
function evaluateRunwayEndPerformanceRange(
    array $runway,
    float $pressureAltitudeFt,
    float $tempC,
    array $tables
): array {
    $runwayLengthFt = (int) ($runway['length_ft'] ?? 0);
    $surface = (string) ($runway['surface'] ?? '');
    $nonPaved = nasrIsNonPavedSurface($surface);

    $ends = $runway['ends'] ?? [];
    if ($ends === []) {
        $ends = [['end_id' => null, 'obstruction' => []]];
    }

    $worst = null;
    $best = null;

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

        if ($worst === null || $scored['total_risk'] >= $worst['total_risk']) {
            $worst = $scored;
        }
        if ($best === null || $scored['total_risk'] < $best['total_risk']) {
            $best = $scored;
        }
    }

    if ($worst === null || $best === null) {
        $empty = [
            'total_risk' => 0.0,
            'end_id' => null,
            'risk152' => 0.0,
            'risk172' => 0.0,
            'risk182' => 0.0,
        ];
        return ['worst' => $empty, 'best' => $empty];
    }

    return [
        'worst' => $worst,
        'best' => $best,
    ];
}

/**
 * Evaluate global best and worst departure ends across all performance runways.
 *
 * @param list<array> $performanceRunways Selected runways with ends[]
 * @param array{c152: array, c172: array, c182: array} $tables
 * @return array{
 *     worst: array{total_risk: float, end_id: ?string, rwy_id: ?string, risk152: float, risk172: float, risk182: float},
 *     best: array{total_risk: float, end_id: ?string, rwy_id: ?string, risk152: float, risk172: float, risk182: float}
 * }
 */
function evaluateAirportRunwayEndPerformanceRange(
    array $performanceRunways,
    float $pressureAltitudeFt,
    float $tempC,
    array $tables
): array {
    $worst = null;
    $best = null;

    foreach ($performanceRunways as $runway) {
        if (!is_array($runway)) {
            continue;
        }

        $range = evaluateRunwayEndPerformanceRange($runway, $pressureAltitudeFt, $tempC, $tables);
        foreach ([$range['worst'], $range['best']] as $scored) {
            $annotated = annotateScoredRunwayEnd($scored, $runway);
            if ($worst === null || $annotated['total_risk'] >= $worst['total_risk']) {
                $worst = $annotated;
            }
            if ($best === null || $annotated['total_risk'] < $best['total_risk']) {
                $best = $annotated;
            }
        }
    }

    if ($worst === null || $best === null) {
        $empty = [
            'total_risk' => 0.0,
            'end_id' => null,
            'rwy_id' => null,
            'risk152' => 0.0,
            'risk172' => 0.0,
            'risk182' => 0.0,
        ];
        return ['worst' => $empty, 'best' => $empty];
    }

    return [
        'worst' => $worst,
        'best' => $best,
    ];
}

/**
 * Cap tier when departure obstruction data is absent (OurAirports / config without runway_ends obstructions).
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
 * Weather-only fallback tier when runway data is unavailable.
 *
 * @return array{tier: string, risk_factor: null, fallback: true, reason: string, reference: string}|null
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
        'risk_factor' => null,
        'fallback' => true,
        'reason' => 'density_altitude_only',
        'reference' => DENSITY_ALTITUDE_PERFORMANCE_REFERENCE_FALLBACK,
    ];
}

/**
 * Build density_altitude_performance payload for weather API consumers.
 *
 * Returns null when DA is missing/stale-suppressed or tier is normal.
 *
 * Runway selection precedence:
 * 1. `runway_length_ft` / `runway_surface` / optional `runway_ends` in airport config
 * 2. NASR active land runways (full obstruction model)
 * 3. OurAirports active land runways when NASR absent
 * 4. Weather-only fallback (`assessFallbackDensityAltitudePerformance`)
 *
 * Tier maps from the global best-performing departure end across all selected runways.
 *
 * @param array $weather Cached weather row
 * @param array $airport Airport configuration
 * @param string|null $airportId Optional config airport id (defaults to airport id/icao)
 * @return array<string, mixed>|null
 */
function buildDensityAltitudePerformance(array $weather, array $airport, ?string $airportId = null): ?array
{
    $densityAltitude = $weather['density_altitude'] ?? null;
    if (!is_numeric($densityAltitude)) {
        return null;
    }
    $densityAltitudeFt = (int) round((float) $densityAltitude);

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
        $ourAirportsId = $airportId ?? (string) ($airport['id'] ?? $airport['icao'] ?? '');
        $performanceRunways = getOurAirportsPerformanceRunwaysForAirport($ourAirportsId, $airport);
        if ($performanceRunways !== []) {
            $runwaySource = 'ourairports';
        }
    }

    if ($performanceRunways === []) {
        return assessFallbackDensityAltitudePerformance($densityAltitudeFt, $fieldElevationFt);
    }

    $pressureAltitude = $weather['pressure_altitude'] ?? null;
    $temperature = $weather['temperature'] ?? null;
    if (!is_numeric($pressureAltitude) || !is_numeric($temperature)) {
        return assessFallbackDensityAltitudePerformance($densityAltitudeFt, $fieldElevationFt);
    }

    $tables = loadPohTakeoffTables();
    $evaluation = evaluateAirportRunwayEndPerformanceRange(
        $performanceRunways,
        (float) $pressureAltitude,
        (float) $temperature,
        $tables
    );

    $best = $evaluation['best'];
    $worstTotalRisk = $evaluation['worst']['total_risk'];
    $bestTotalRisk = $best['total_risk'];

    $tier = densityAltitudePerformanceTierFromScoredEnd($bestTotalRisk);
    if (!densityAltitudePerformanceAllowsWarningTier((string) $runwaySource, $performanceRunways)) {
        $tier = densityAltitudePerformanceCapTierWithoutObstructions($tier);
    }

    if ($tier === 'normal') {
        return null;
    }

    $reason = 'reference_models';
    $reference = DENSITY_ALTITUDE_PERFORMANCE_REFERENCE;
    if ($runwaySource === 'config') {
        $reference = DENSITY_ALTITUDE_PERFORMANCE_REFERENCE_CONFIG;
    } elseif ($runwaySource === 'ourairports') {
        $reason = 'reference_models_ourairports';
        $reference = DENSITY_ALTITUDE_PERFORMANCE_REFERENCE_OURAIRPORTS;
    }

    $payload = [
        'tier' => $tier,
        'risk_factor' => round($bestTotalRisk, 3),
        'worst_end_risk' => round($worstTotalRisk, 3),
        'best_end_risk' => round($bestTotalRisk, 3),
        'scored_end_risk' => round($bestTotalRisk, 3),
        'selection_basis' => 'best_performance',
        'fallback' => false,
        'reason' => $reason,
        'reference' => $reference,
    ];

    if ($best['end_id'] !== null) {
        $payload['operational_end_id'] = $best['end_id'];
    }
    if (isset($best['rwy_id']) && $best['rwy_id'] !== null && $best['rwy_id'] !== '') {
        $payload['operational_rwy_id'] = $best['rwy_id'];
    }

    return $payload;
}

/**
 * Attach density_altitude_performance when tier is caution or warning.
 *
 * @param array $weather Weather array (not modified)
 * @param array $airport Airport configuration
 * @param string|null $airportId Optional config airport id
 * @return array Weather array with optional density_altitude_performance
 */
function attachDensityAltitudePerformance(array $weather, array $airport, ?string $airportId = null): array
{
    $performance = buildDensityAltitudePerformance($weather, $airport, $airportId);
    if ($performance !== null) {
        $weather['density_altitude_performance'] = $performance;
    } else {
        unset($weather['density_altitude_performance']);
    }
    return $weather;
}
