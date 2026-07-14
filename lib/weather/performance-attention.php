<?php
/**
 * Density altitude performance attention assessment.
 */

require_once __DIR__ . '/../constants.php';
require_once __DIR__ . '/../nasr/cache.php';
require_once __DIR__ . '/../nasr/runway-selection.php';
require_once __DIR__ . '/poh-takeoff.php';

/**
 * Departure obstruction multiplier from NASR runway-end data.
 */
function calculateDepartureObstructionMultiplier(?float $hgtFt, ?float $distFt, int $availableFt): float
{
    if ($hgtFt === null || $distFt === null || $hgtFt <= 0 || $distFt <= 0 || $availableFt <= 0) {
        return 1.0;
    }
    if ($distFt > $availableFt || $hgtFt <= 50) {
        return 1.0;
    }

    $heightFactor = $hgtFt / 50.0;
    $distanceFactor = min(max(1000.0 / max($distFt, 200.0), 1.0), 2.5);

    return min($heightFactor * $distanceFactor, PERFORMANCE_OBST_MAX_MULT);
}

/**
 * Map required/available runway stress to 0-1 profile risk.
 */
function calculatePerformanceProfileRisk(float $requiredFt, int $availableFt): float
{
    if ($availableFt <= 0) {
        return 1.0;
    }

    $stress = $requiredFt / $availableFt;
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
 * @deprecated Use calculateSummedPerformanceRisk()
 */
function calculateCompositePerformanceRisk(float $risk152, float $risk172, float $risk182): float
{
    return calculateSummedPerformanceRisk($risk152, $risk172, $risk182);
}

/**
 * Map summed profile risk to attention tier (single-end / legacy helper).
 */
function performanceAttentionTierForRisk(float $totalRisk): string
{
    return performanceAttentionTierFromEndRisks($totalRisk, $totalRisk);
}

/**
 * Asymmetric tier: conservative caution on worst end, optimistic strong on best end.
 */
function performanceAttentionTierFromEndRisks(float $worstTotalRisk, float $bestTotalRisk): string
{
    if ($bestTotalRisk >= PERFORMANCE_ATTENTION_TIER_STRONG) {
        return 'strong';
    }
    if ($worstTotalRisk >= PERFORMANCE_ATTENTION_TIER_CAUTION) {
        return 'caution';
    }
    return 'none';
}

/**
 * Risk factor reported with tier: best-end sum for strong, worst-end sum for caution.
 */
function performanceAttentionRiskFactorForTier(string $tier, float $worstTotalRisk, float $bestTotalRisk): float
{
    if ($tier === 'strong') {
        return $bestTotalRisk;
    }
    if ($tier === 'caution') {
        return $worstTotalRisk;
    }

    return max($worstTotalRisk, $bestTotalRisk);
}

/**
 * Score one runway departure end.
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
    $mult = calculateDepartureObstructionMultiplier(
        isset($obst['hgt_ft']) ? (float) $obst['hgt_ft'] : null,
        isset($obst['dist_ft']) ? (float) $obst['dist_ft'] : null,
        $availableFt
    );

    $req152 = pohRequiredTakeoffDistanceFt($tables['c152'], $pressureAltitudeFt, $tempC, $nonPaved, $mult);
    $req172 = pohRequiredTakeoffDistanceFt($tables['c172'], $pressureAltitudeFt, $tempC, $nonPaved, $mult);
    $req182 = pohRequiredTakeoffDistanceFt($tables['c182'], $pressureAltitudeFt, $tempC, $nonPaved, $mult);

    $risk152 = calculatePerformanceProfileRisk((float) $req152, $availableFt);
    $risk172 = calculatePerformanceProfileRisk((float) $req172, $availableFt);
    $risk182 = calculatePerformanceProfileRisk((float) $req182, $availableFt);

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
    $availableFt = (int) ($runway['length_ft'] ?? 0);
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
 * Weather-only fallback tier when runway data is unavailable.
 *
 * @return array{tier: string, risk_factor: null, fallback: true, reason: string, reference: string}|null
 */
function assessFallbackPerformanceAttention(?int $densityAltitudeFt, ?int $fieldElevationFt): ?array
{
    if ($densityAltitudeFt === null || $fieldElevationFt === null) {
        return null;
    }

    $delta = $densityAltitudeFt - $fieldElevationFt;
    $tier = 'none';

    if ($densityAltitudeFt >= 9000 || $delta >= 3500) {
        $tier = 'strong';
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

    if ($tier === 'none') {
        return null;
    }

    return [
        'tier' => $tier,
        'risk_factor' => null,
        'fallback' => true,
        'reason' => 'density_altitude_only',
        'reference' => PERFORMANCE_ATTENTION_REFERENCE,
    ];
}

/**
 * Evaluate worst runway end on the selected runway.
 *
 * @param array $runway Selected runway with ends[]
 * @param array{c152: array, c172: array, c182: array} $tables
 * @return array{total_risk: float, end_id: ?string}
 */
function evaluateWorstRunwayEndPerformance(
    array $runway,
    float $pressureAltitudeFt,
    float $tempC,
    array $tables
): array {
    $range = evaluateRunwayEndPerformanceRange($runway, $pressureAltitudeFt, $tempC, $tables);

    return [
        'total_risk' => $range['worst']['total_risk'],
        'end_id' => $range['worst']['end_id'],
    ];
}

/**
 * Build performance attention payload for weather API consumers.
 *
 * Returns null when DA is missing/stale-suppressed or tier is none.
 *
 * @param array $weather Cached weather row
 * @param array $airport Airport configuration
 * @return array<string, mixed>|null
 */
function buildPerformanceAttention(array $weather, array $airport): ?array
{
    $densityAltitude = $weather['density_altitude'] ?? null;
    if (!is_numeric($densityAltitude)) {
        return null;
    }
    $densityAltitudeFt = (int) round((float) $densityAltitude);

    $pressureAltitude = $weather['pressure_altitude'] ?? null;
    $temperature = $weather['temperature'] ?? null;
    if (!is_numeric($pressureAltitude) || !is_numeric($temperature)) {
        return null;
    }

    $nasrRecord = getNasrAirportForConfig($airport);
    $fieldElevationFt = getEffectiveFieldElevationFt($airport, $nasrRecord);

    $configLength = getConfigRunwayLengthOverrideFt($airport);
    $selectedRunway = null;

    if ($configLength !== null) {
        $selectedRunway = [
            'rwy_id' => 'config',
            'length_ft' => $configLength,
            'surface' => getConfigRunwaySurfaceOverride($airport) ?? 'ASPH',
            'ends' => [],
        ];
    } elseif ($nasrRecord !== null) {
        $selectedRunway = nasrSelectLongestActiveLandRunway($nasrRecord);
    }

    if ($selectedRunway === null) {
        return assessFallbackPerformanceAttention($densityAltitudeFt, $fieldElevationFt);
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
    $tier = performanceAttentionTierFromEndRisks($worstTotalRisk, $bestTotalRisk);
    if ($tier === 'none') {
        return null;
    }

    $riskFactor = performanceAttentionRiskFactorForTier($tier, $worstTotalRisk, $bestTotalRisk);

    return [
        'tier' => $tier,
        'risk_factor' => round($riskFactor, 3),
        'fallback' => false,
        'reason' => 'reference_models',
        'reference' => PERFORMANCE_ATTENTION_REFERENCE,
    ];
}

/**
 * Attach performance_attention when tier is caution or strong.
 *
 * @param array $weather Weather array (not modified)
 * @param array $airport Airport configuration
 * @return array Weather array with optional performance_attention
 */
function attachPerformanceAttention(array $weather, array $airport): array
{
    $attention = buildPerformanceAttention($weather, $airport);
    if ($attention !== null) {
        $weather['performance_attention'] = $attention;
    }
    return $weather;
}
