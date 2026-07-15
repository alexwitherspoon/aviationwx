<?php
/**
 * SAFETY-CRITICAL: Density altitude performance assessment from reference AFM takeoff charts.
 */

require_once __DIR__ . '/../constants.php';
require_once __DIR__ . '/../nasr/cache.php';
require_once __DIR__ . '/../nasr/runway-selection.php';
require_once __DIR__ . '/../runways.php';
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
        'reference' => DENSITY_ALTITUDE_PERFORMANCE_REFERENCE,
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
 * 3. OurAirports longest land runway when NASR absent (length/surface only; empty ends)
 * 4. Weather-only fallback (`assessFallbackDensityAltitudePerformance`)
 *
 * @param array $weather Cached weather row
 * @param array $airport Airport configuration
 * @return array<string, mixed>|null
 */
function buildDensityAltitudePerformance(array $weather, array $airport): ?array
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
        $airportId = (string) ($airport['id'] ?? $airport['icao'] ?? '');
        $selectedRunway = getOurAirportsPerformanceRunwayForAirport($airportId, $airport);
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
    $tier = densityAltitudePerformanceTierFromEndRisks($worstTotalRisk, $bestTotalRisk);

    $reason = 'reference_models';
    $reference = DENSITY_ALTITUDE_PERFORMANCE_REFERENCE;
    if ($runwaySource === 'config' || $runwaySource === 'ourairports') {
        $tier = densityAltitudePerformanceCapTierWithoutObstructions($tier);
    }
    if ($runwaySource === 'ourairports') {
        $reason = 'reference_models_ourairports';
        $reference = DENSITY_ALTITUDE_PERFORMANCE_REFERENCE_OURAIRPORTS;
    }

    if ($tier === 'normal') {
        return null;
    }

    $riskFactor = densityAltitudePerformanceRiskFactorForTier($tier, $worstTotalRisk, $bestTotalRisk);

    return [
        'tier' => $tier,
        'risk_factor' => round($riskFactor, 3),
        'worst_end_risk' => round($worstTotalRisk, 3),
        'best_end_risk' => round($bestTotalRisk, 3),
        'fallback' => false,
        'reason' => $reason,
        'reference' => $reference,
    ];
}

/**
 * Attach density_altitude_performance when tier is caution or warning.
 *
 * @param array $weather Weather array (not modified)
 * @param array $airport Airport configuration
 * @return array Weather array with optional density_altitude_performance
 */
function attachDensityAltitudePerformance(array $weather, array $airport): array
{
    $performance = buildDensityAltitudePerformance($weather, $airport);
    if ($performance !== null) {
        $weather['density_altitude_performance'] = $performance;
    } else {
        unset($weather['density_altitude_performance']);
    }
    return $weather;
}
