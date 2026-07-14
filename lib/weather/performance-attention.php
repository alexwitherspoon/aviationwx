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
 * Composite profile risk across C152/C172/C182 reference models.
 */
function calculateCompositePerformanceRisk(float $risk152, float $risk172, float $risk182): float
{
    $base = (0.45 * $risk152) + (0.40 * $risk172) + (0.15 * $risk182);
    $escalation = max($risk152, $risk172, $risk182);
    $composite = (0.6 * $base) + (0.4 * $escalation);

    return max(0.0, min(1.0, $composite));
}

/**
 * Map composite risk to attention tier.
 */
function performanceAttentionTierForRisk(float $risk): string
{
    if ($risk >= PERFORMANCE_ATTENTION_TIER_STRONG) {
        return 'strong';
    }
    if ($risk >= PERFORMANCE_ATTENTION_TIER_CAUTION) {
        return 'caution';
    }
    return 'none';
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
 * @return array{composite: float, end_id: ?string}
 */
function evaluateWorstRunwayEndPerformance(
    array $runway,
    float $pressureAltitudeFt,
    float $tempC,
    array $tables
): array {
    $availableFt = (int) ($runway['length_ft'] ?? 0);
    $surface = (string) ($runway['surface'] ?? '');
    $nonPaved = nasrIsNonPavedSurface($surface);

    $worstComposite = 0.0;
    $worstEndId = null;

    $ends = $runway['ends'] ?? [];
    if ($ends === []) {
        $ends = [['end_id' => null, 'obstruction' => []]];
    }

    foreach ($ends as $end) {
        if (!is_array($end)) {
            continue;
        }
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
        $composite = calculateCompositePerformanceRisk($risk152, $risk172, $risk182);

        if ($composite >= $worstComposite) {
            $worstComposite = $composite;
            $worstEndId = $end['end_id'] ?? null;
        }
    }

    return [
        'composite' => $worstComposite,
        'end_id' => $worstEndId,
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
    $evaluation = evaluateWorstRunwayEndPerformance(
        $selectedRunway,
        (float) $pressureAltitude,
        (float) $temperature,
        $tables
    );

    $composite = $evaluation['composite'];
    $tier = performanceAttentionTierForRisk($composite);
    if ($tier === 'none') {
        return null;
    }

    return [
        'tier' => $tier,
        'risk_factor' => round($composite, 3),
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
