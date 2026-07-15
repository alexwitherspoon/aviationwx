<?php
/**
 * SAFETY-CRITICAL: Density altitude performance assessment from reference AFM takeoff charts.
 */

require_once __DIR__ . '/../constants.php';
require_once __DIR__ . '/../nasr/cache.php';
require_once __DIR__ . '/../nasr/runway-selection.php';
require_once __DIR__ . '/../runways.php';
require_once __DIR__ . '/da-performance-runway-end.php';
require_once __DIR__ . '/history.php';
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
 * Map summed profile risk to tier (single-end / legacy helper).
 */
function densityAltitudePerformanceTierForRisk(float $totalRisk): string
{
    return densityAltitudePerformanceTierFromEndRisks($totalRisk, $totalRisk);
}

/**
 * Asymmetric tier: conservative caution on worst end, optimistic warning on best end.
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
 * Risk factor reported with tier: best-end sum for warning, worst-end sum for caution.
 */
function densityAltitudePerformanceRiskFactorForTier(string $tier, float $worstTotalRisk, float $bestTotalRisk): float
{
    if ($tier === 'warning') {
        return $bestTotalRisk;
    }
    if ($tier === 'caution') {
        return $worstTotalRisk;
    }

    return max($worstTotalRisk, $bestTotalRisk);
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
 * Look up or compute performance scoring for a specific runway end.
 *
 * @param array $evaluation Result from evaluateRunwayEndPerformanceRange()
 * @param array $end Runway end row
 * @param array $runway Selected runway
 * @param float $pressureAltitudeFt Pressure altitude in feet
 * @param float $tempC Temperature Celsius
 * @param array{c152: array, c172: array, c182: array} $tables
 * @return array{total_risk: float, end_id: ?string, risk152: float, risk172: float, risk182: float}
 */
function lookupEvaluationForRunwayEnd(
    array $evaluation,
    array $end,
    array $runway,
    float $pressureAltitudeFt,
    float $tempC,
    array $tables
): array {
    $endId = $end['end_id'] ?? null;
    if ($endId !== null) {
        if (($evaluation['worst']['end_id'] ?? null) === $endId) {
            return $evaluation['worst'];
        }
        if (($evaluation['best']['end_id'] ?? null) === $endId) {
            return $evaluation['best'];
        }
    }

    $runwayLengthFt = (int) ($runway['length_ft'] ?? 0);
    $nonPaved = nasrIsNonPavedSurface((string) ($runway['surface'] ?? ''));
    $availableFt = nasrEffectiveDepartureLengthFt($end, $runwayLengthFt);

    return evaluateSingleRunwayEndPerformance(
        $end,
        $availableFt,
        $nonPaved,
        $pressureAltitudeFt,
        $tempC,
        $tables
    );
}

/**
 * Choose operational departure end and scoring basis for DA performance tier.
 *
 * @param array $evaluation Result from evaluateRunwayEndPerformanceRange()
 * @param array $runway Selected runway with ends[]
 * @param array $airport Airport configuration
 * @param string|null $airportId Config airport id for weather history lookup
 * @param float $pressureAltitudeFt Pressure altitude in feet
 * @param float $tempC Temperature Celsius
 * @param array{c152: array, c172: array, c182: array} $tables POH tables
 * @param string $runwaySource nasr|ourairports|config
 * @return array{
 *     selection_basis: string,
 *     operational_end_id: ?string,
 *     scored_end: ?array,
 *     wind_basis: ?array
 * }
 */
function resolveDensityAltitudePerformanceEndSelection(
    array $evaluation,
    array $runway,
    array $airport,
    ?string $airportId,
    float $pressureAltitudeFt,
    float $tempC,
    array $tables,
    string $runwaySource
): array {
    $worst = $evaluation['worst'];
    $best = $evaluation['best'];

    $bothEndsResult = [
        'selection_basis' => 'both_ends',
        'operational_end_id' => null,
        'scored_end' => null,
        'wind_basis' => null,
    ];

    if ($runwaySource === 'config') {
        return $bothEndsResult;
    }

    $ends = $runway['ends'] ?? [];
    if ($ends === []) {
        return $bothEndsResult;
    }

    $hasResolvableHeading = false;
    foreach ($ends as $end) {
        if (!is_array($end)) {
            continue;
        }
        if (resolveRunwayEndMagneticHeading($end, $runway, $airport) !== null) {
            $hasResolvableHeading = true;
            break;
        }
    }

    if ($airportId !== null && $airportId !== '' && $hasResolvableHeading) {
        $windBasis = computeWindowMeanWind($airportId, $airport);
        if ($windBasis !== null) {
            $picked = pickDepartureEndByWindFromMagnetic(
                $runway,
                $airport,
                (float) $windBasis['direction_magnetic']
            );
            if ($picked !== null) {
                return [
                    'selection_basis' => 'window_mean_wind',
                    'operational_end_id' => $picked['end_id'] ?? null,
                    'scored_end' => lookupEvaluationForRunwayEnd(
                        $evaluation,
                        $picked,
                        $runway,
                        $pressureAltitudeFt,
                        $tempC,
                        $tables
                    ),
                    'wind_basis' => $windBasis,
                ];
            }
        }
    }

    $spread = $worst['total_risk'] - $best['total_risk'];
    if ($spread >= DA_PERF_ASYMMETRIC_SPREAD) {
        return [
            'selection_basis' => 'asymmetric_heuristic',
            'operational_end_id' => $best['end_id'],
            'scored_end' => $best,
            'wind_basis' => null,
        ];
    }

    return $bothEndsResult;
}

/**
 * Score one runway departure end using POH chart distances.
 *
 * @param array $end Runway end with optional obstruction[]
 * @param array{c152: array, c172: array, c182: array} $tables
 * @return array{total_risk: float, end_id: ?string, risk152: float, risk172: float, risk182: float}
 */
function evaluateSingleRunwayEndPerformance(
    array $end,
    int $availableFt,
    bool $nonPaved,
    float $pressureAltitudeFt,
    float $tempC,
    array $tables
): array {
    $obst = is_array($end['obstruction'] ?? null) ? $end['obstruction'] : [];
    $obstHgt = isset($obst['hgt_ft']) ? (float) $obst['hgt_ft'] : null;
    $obstDist = isset($obst['dist_ft']) ? (float) $obst['dist_ft'] : null;
    $obstSlope = isset($obst['slope']) && is_numeric($obst['slope']) && (float) $obst['slope'] > 0
        ? (float) $obst['slope']
        : null;

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
 * Evaluate best and worst departure ends on the selected runway.
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
 * Cap tier when departure obstruction data is absent (OurAirports / config override).
 */
function densityAltitudePerformanceCapTierWithoutObstructions(string $tier): string
{
    if ($tier === 'warning') {
        return 'caution';
    }

    return $tier;
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
 * 1. `runway_length_ft` / `runway_surface` in airport config (synthetic runway, empty ends)
 * 2. NASR longest active land runway (full obstruction model)
 * 3. OurAirports longest land runway when NASR absent (length/surface; per-end displaced
 *    thresholds when published; no departure obstructions or TODA)
 * 4. Weather-only fallback (`assessFallbackDensityAltitudePerformance`)
 *
 * @param array $weather Cached weather row
 * @param array $airport Airport configuration
 * @param string|null $airportId Optional config airport id for weather history (defaults to airport id/icao)
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

    $configLength = getConfigRunwayLengthOverrideFt($airport);
    $selectedRunway = null;
    $runwaySource = null;

    if ($configLength !== null) {
        $selectedRunway = [
            'rwy_id' => 'config',
            'length_ft' => $configLength,
            'surface' => getConfigRunwaySurfaceOverride($airport) ?? 'ASPH',
            'ends' => [],
        ];
        $runwaySource = 'config';
    } elseif ($nasrRecord !== null) {
        $selectedRunway = nasrSelectLongestActiveLandRunway($nasrRecord);
        if ($selectedRunway !== null) {
            $runwaySource = 'nasr';
        }
    } else {
        $ourAirportsId = (string) ($airport['id'] ?? $airport['icao'] ?? '');
        $selectedRunway = getOurAirportsPerformanceRunwayForAirport($ourAirportsId, $airport);
        if ($selectedRunway !== null) {
            $runwaySource = 'ourairports';
        }
    }

    if ($selectedRunway === null) {
        return assessFallbackDensityAltitudePerformance($densityAltitudeFt, $fieldElevationFt);
    }

    $pressureAltitude = $weather['pressure_altitude'] ?? null;
    $temperature = $weather['temperature'] ?? null;
    if (!is_numeric($pressureAltitude) || !is_numeric($temperature)) {
        return assessFallbackDensityAltitudePerformance($densityAltitudeFt, $fieldElevationFt);
    }

    $tables = loadPohTakeoffTables();
    $evaluation = evaluateRunwayEndPerformanceRange(
        $selectedRunway,
        (float) $pressureAltitude,
        (float) $temperature,
        $tables
    );

    $worstTotalRisk = $evaluation['worst']['total_risk'];
    $bestTotalRisk = $evaluation['best']['total_risk'];

    $resolvedAirportId = $airportId ?? (string) ($airport['id'] ?? $airport['icao'] ?? '');
    if ($resolvedAirportId === '') {
        $resolvedAirportId = null;
    }

    $selection = resolveDensityAltitudePerformanceEndSelection(
        $evaluation,
        $selectedRunway,
        $airport,
        $resolvedAirportId,
        (float) $pressureAltitude,
        (float) $temperature,
        $tables,
        (string) $runwaySource
    );

    if ($selection['selection_basis'] === 'both_ends') {
        $tier = densityAltitudePerformanceTierFromEndRisks($worstTotalRisk, $bestTotalRisk);
        $riskFactor = densityAltitudePerformanceRiskFactorForTier($tier, $worstTotalRisk, $bestTotalRisk);
    } else {
        $scoredRisk = (float) ($selection['scored_end']['total_risk'] ?? 0.0);
        $tier = densityAltitudePerformanceTierFromScoredEnd($scoredRisk);
        $riskFactor = $scoredRisk;
    }

    $reason = 'reference_models';
    $reference = DENSITY_ALTITUDE_PERFORMANCE_REFERENCE;
    if ($runwaySource === 'config' || $runwaySource === 'ourairports') {
        $tier = densityAltitudePerformanceCapTierWithoutObstructions($tier);
    }
    if ($runwaySource === 'config') {
        $reference = DENSITY_ALTITUDE_PERFORMANCE_REFERENCE_CONFIG;
    } elseif ($runwaySource === 'ourairports') {
        $reason = 'reference_models_ourairports';
        $reference = DENSITY_ALTITUDE_PERFORMANCE_REFERENCE_OURAIRPORTS;
    }

    if ($tier === 'normal') {
        return null;
    }

    $payload = [
        'tier' => $tier,
        'risk_factor' => round($riskFactor, 3),
        'worst_end_risk' => round($worstTotalRisk, 3),
        'best_end_risk' => round($bestTotalRisk, 3),
        'selection_basis' => $selection['selection_basis'],
        'fallback' => false,
        'reason' => $reason,
        'reference' => $reference,
    ];

    if ($selection['operational_end_id'] !== null) {
        $payload['operational_end_id'] = $selection['operational_end_id'];
    }
    if ($selection['scored_end'] !== null) {
        $payload['scored_end_risk'] = round((float) $selection['scored_end']['total_risk'], 3);
    }
    if ($selection['wind_basis'] !== null) {
        $payload['wind_basis'] = $selection['wind_basis'];
    }

    return $payload;
}

/**
 * Attach density_altitude_performance when tier is caution or warning.
 *
 * @param array $weather Weather array (not modified)
 * @param array $airport Airport configuration
 * @param string|null $airportId Optional config airport id for weather history lookup
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
