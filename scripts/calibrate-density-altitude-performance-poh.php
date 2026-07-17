<?php
/**
 * POH-grounded calibration of density altitude performance thresholds.
 *
 * Uses production weather snapshot + NASR to compare chart distances,
 * stress curve, and tier mapping against pilot-meaningful margins.
 */

declare(strict_types=1);

define('CACHE_BASE_DIR', getenv('CACHE_BASE_DIR') ?: dirname(__DIR__) . '/cache');

require_once __DIR__ . '/../lib/config.php';
require_once __DIR__ . '/../lib/cache-paths.php';
require_once __DIR__ . '/../lib/constants.php';
require_once __DIR__ . '/../lib/weather/density-altitude-performance.php';
require_once __DIR__ . '/../lib/weather/poh-takeoff.php';
require_once __DIR__ . '/../lib/nasr/cache.php';
require_once __DIR__ . '/../lib/nasr/runway-selection.php';

/**
 * @return array<string, mixed>
 */
function pohCalibrationRow(
    string $label,
    array $table,
    float $pressureAltitudeFt,
    float $tempC,
    int $runwayFt,
    bool $nonPaved,
    ?float $obstHgtFt = null,
    ?float $obstDistFt = null,
    ?float $obstClncSlope = null
): array {
    $chartTotal = pohLookupChartTotalFt($table, $pressureAltitudeFt, $tempC);
    $groundRoll = pohLookupChartGroundRollFt($table, $pressureAltitudeFt, $tempC);
    $surfaceTotal = pohChartSurfaceTotalFt($table, $pressureAltitudeFt, $tempC, $nonPaved);
    $stress = pohComputeDepartureEndStress(
        $table,
        $pressureAltitudeFt,
        $tempC,
        $nonPaved,
        $runwayFt,
        $obstHgtFt,
        $obstDistFt,
        $obstClncSlope
    );
    $requiredTotal = $runwayFt > 0 ? (int) round($stress * $runwayFt) : null;
    $risk = $runwayFt > 0 ? calculatePerformanceProfileRiskFromStress($stress) : null;
    $marginFt = ($runwayFt > 0 && $requiredTotal !== null) ? $runwayFt - $requiredTotal : null;
    $marginPct = ($runwayFt > 0 && $marginFt !== null) ? round(100.0 * $marginFt / $runwayFt, 1) : null;
    $effectiveObstHgt = ($obstHgtFt !== null && $obstDistFt !== null)
        ? pohEffectiveObstacleHeightForChart($obstHgtFt, $obstDistFt, $obstClncSlope)
        : null;
    $heightRatio = ($effectiveObstHgt !== null && $effectiveObstHgt > 0)
        ? pohObstacleHeightRatio($effectiveObstHgt)
        : null;

    return [
        'label' => $label,
        'chart_total_paved_ft' => $chartTotal,
        'ground_roll_ft' => $groundRoll,
        'grass_add_ft' => $nonPaved ? (int) round($groundRoll * POH_GRASS_GROUND_ROLL_FACTOR) : 0,
        'surface_total_ft' => $surfaceTotal,
        'obst_hgt_ft' => $obstHgtFt,
        'obst_dist_ft' => $obstDistFt,
        'obst_clnc_slope' => $obstClncSlope,
        'obst_effective_hgt_ft' => $effectiveObstHgt,
        'obst_height_ratio' => $heightRatio !== null ? round($heightRatio, 3) : null,
        'required_total_ft' => $requiredTotal,
        'runway_ft' => $runwayFt,
        'stress' => $stress !== null ? round($stress, 3) : null,
        'risk' => $risk !== null ? round($risk, 3) : null,
        'margin_ft' => $marginFt,
        'margin_pct' => $marginPct,
        'ground_roll_stress' => $runwayFt > 0 ? round($groundRoll / $runwayFt, 3) : null,
    ];
}

/**
 * @return array<string, mixed>|null
 */
function fetchProductionWeather(string $airportId): ?array
{
    $url = 'https://aviationwx.org/api/weather.php?airport=' . rawurlencode($airportId);
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 30,
        CURLOPT_USERAGENT => 'AviationWX-POH-Calibration/1.0',
    ]);
    $body = curl_exec($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if ($body === false || $code !== 200) {
        return null;
    }
    $decoded = json_decode($body, true);
    if (!is_array($decoded['weather'] ?? null)) {
        return null;
    }
    $weather = $decoded['weather'];
    if (isset($weather['temperature_f']) && !isset($weather['temperature'])) {
        $weather['temperature'] = ((float) $weather['temperature_f'] - 32) * 5 / 9;
    }
    return $weather;
}

/**
 * @return array<string, mixed>
 */
function analyzeAirportPoh(string $airportId, array $airport, array $weather): array
{
    $tables = loadPohTakeoffTables();
    $pa = (float) $weather['pressure_altitude'];
    $tempC = (float) $weather['temperature'];
    $da = (int) round((float) $weather['density_altitude']);

    $nasr = getNasrAirportForConfig($airport);
    $elev = getEffectiveFieldElevationFt($airport, $nasr);
    $runway = nasrSelectLongestActiveLandRunway($nasr);
    if ($runway === null) {
        return [
            'id' => $airportId,
            'name' => $airport['name'] ?? '',
            'status' => 'no_runway',
            'da_ft' => $da,
            'elev_ft' => $elev,
        ];
    }

    $runwayFt = (int) $runway['length_ft'];
    $nonPaved = nasrIsNonPavedSurface((string) ($runway['surface'] ?? ''));
    $ends = $runway['ends'] ?? [];
    if ($ends === []) {
        $ends = [['end_id' => null, 'obstruction' => []]];
    }

    $endRows = [];
    $models = [];
    foreach (['c152' => 'C152M', 'c172' => 'C172N', 'c182' => 'C182T'] as $key => $label) {
        $models[$key] = [];
    }

    $worstTotalRisk = 0.0;
    $bestTotalRisk = 3.0;
    foreach ($ends as $end) {
        if (!is_array($end)) {
            continue;
        }
        $obst = is_array($end['obstruction'] ?? null) ? $end['obstruction'] : [];
        $obstHgt = isset($obst['hgt_ft']) ? (float) $obst['hgt_ft'] : null;
        $obstDist = isset($obst['dist_ft']) ? (float) $obst['dist_ft'] : null;
        $obstSlope = isset($obst['slope']) && is_numeric($obst['slope']) && (float) $obst['slope'] > 0
            ? (float) $obst['slope']
            : null;
        $endDetail = [
            'end_id' => $end['end_id'] ?? null,
            'obst_hgt_ft' => $obstHgt,
            'obst_dist_ft' => $obstDist,
            'obst_clnc_slope' => $obstSlope,
            'models' => [],
        ];
        $r152 = $r172 = $r182 = 0.0;
        foreach (['c152' => 'C152M', 'c172' => 'C172N', 'c182' => 'C182T'] as $key => $modelLabel) {
            $row = pohCalibrationRow(
                $modelLabel,
                $tables[$key],
                $pa,
                $tempC,
                $runwayFt,
                $nonPaved,
                $obstHgt,
                $obstDist,
                $obstSlope
            );
            $endDetail['models'][$key] = $row;
            if ($key === 'c152') {
                $r152 = (float) $row['risk'];
            } elseif ($key === 'c172') {
                $r172 = (float) $row['risk'];
            } else {
                $r182 = (float) $row['risk'];
            }
        }
        $totalRisk = calculateSummedPerformanceRisk($r152, $r172, $r182);
        $endDetail['total_risk'] = round($totalRisk, 3);
        $endDetail['tier'] = densityAltitudePerformanceTierFromScoredEnd($totalRisk);
        $endRows[] = $endDetail;
        $worstTotalRisk = max($worstTotalRisk, $totalRisk);
        $bestTotalRisk = min($bestTotalRisk, $totalRisk);
    }

    $noObst = [];
    foreach (['c152' => 'C152M', 'c172' => 'C172N', 'c182' => 'C182T'] as $key => $modelLabel) {
        $noObst[$key] = pohCalibrationRow($modelLabel, $tables[$key], $pa, $tempC, $runwayFt, $nonPaved);
    }
    $noObstTotalRisk = calculateSummedPerformanceRisk(
        (float) $noObst['c152']['risk'],
        (float) $noObst['c172']['risk'],
        (float) $noObst['c182']['risk']
    );

    $pavedNoGrass = [];
    if ($nonPaved) {
        foreach (['c152' => 'C152M', 'c172' => 'C172N', 'c182' => 'C182T'] as $key => $modelLabel) {
            $pavedNoGrass[$key] = pohCalibrationRow($modelLabel, $tables[$key], $pa, $tempC, $runwayFt, false);
        }
    }

    $built = computeDensityAltitudePerformance($weather, $airport);
    $fallback = assessFallbackDensityAltitudePerformance($da, $elev);

    return [
        'id' => $airportId,
        'name' => $airport['name'] ?? '',
        'da_ft' => $da,
        'elev_ft' => $elev,
        'da_delta_ft' => $elev !== null ? $da - $elev : null,
        'pa_ft' => (int) round($pa),
        'temp_c' => round($tempC, 1),
        'runway_ft' => $runwayFt,
        'runway_surface' => $runway['surface'] ?? '',
        'non_paved' => $nonPaved,
        'current_tier' => $built['tier'] ?? 'normal',
        'current_risk' => $built['best_end']['total_risk'] ?? null,
        'fallback_tier' => $fallback['tier'] ?? 'normal',
        'best_end_total_risk' => round($bestTotalRisk, 3),
        'worst_end_total_risk' => round($worstTotalRisk, 3),
        'no_obstruction' => $noObst,
        'no_obstruction_total_risk' => round($noObstTotalRisk, 3),
        'paved_chart_only' => $pavedNoGrass,
        'ends' => $endRows,
    ];
}

/**
 * Simulate alternate tier mapping from stress values.
 *
 * @param list<float> $risks
 */
function tierFromRisks(array $risks, float $cautionAt, float $warningAt, bool $use152172Only = false): string
{
    if ($risks === []) {
        return 'normal';
    }
    if ($use152172Only) {
        $score = $risks[0] + $risks[1];
    } else {
        $score = calculateSummedPerformanceRisk($risks[0], $risks[1], $risks[2] ?? 0.0);
    }
    if ($score >= $warningAt) {
        return 'warning';
    }
    if ($score >= $cautionAt) {
        return 'caution';
    }
    return 'normal';
}

/**
 * @param callable(float): float $riskFromStress
 */
function sweepStressCurve(callable $riskFromStress): array
{
    $rows = [];
    foreach ([0.5, 0.67, 0.8, 0.9, 1.0, 1.1, 1.2, 1.33, 1.5, 1.7, 2.0] as $stress) {
        $risk = $riskFromStress($stress);
        $tier = densityAltitudePerformanceTierFromScoredEnd($risk);
        $rows[] = ['stress' => $stress, 'risk' => round($risk, 3), 'tier' => $tier];
    }
    return $rows;
}

$config = loadConfig();
if ($config === null) {
    fwrite(STDERR, "config failed\n");
    exit(1);
}

$focusIds = [
  // Intended warning
  '12id', '7or0', 'id76', 'c53', '02id', 's81',
  // Obstruction false positives
  '4s9', '42b', 'kvkx', 's68', '7s9',
  // Control / long paved
  'khio', 'kida', '69v', 'kboi', 'keul', 's33',
  // Near caution
  'u60', '1xs0', '28u',
];

$airports = [];
foreach ($focusIds as $id) {
    if (!isset($config['airports'][$id])) {
        continue;
    }
    $weather = fetchProductionWeather($id);
    if ($weather === null) {
        $airports[] = ['id' => $id, 'status' => 'weather_fetch_failed'];
        usleep(200000);
        continue;
    }
    $airports[] = analyzeAirportPoh($id, $config['airports'][$id], $weather);
    usleep(200000);
}

$currentRiskFn = static function (float $stress): float {
    $span = PERFORMANCE_STRESS_HIGH - PERFORMANCE_STRESS_LOW;
    return max(0.0, min(1.0, ($stress - PERFORMANCE_STRESS_LOW) / $span));
};

$altRiskFn = static function (float $stress): float {
    $low = 0.75;
    $high = 1.50;
    return max(0.0, min(1.0, ($stress - $low) / ($high - $low)));
};

$report = [
    'generated_at' => gmdate('c'),
    'stress_curve_current' => sweepStressCurve($currentRiskFn),
    'stress_curve_proposed_wide' => sweepStressCurve($altRiskFn),
    'poh_meaning' => [
        'stress_0_67' => 'Chart required distance is 67% of runway (33% length margin)',
        'stress_1_00' => 'Chart required equals runway length (zero margin on POH total-to-50ft basis)',
        'stress_1_33' => 'Chart required is 133% of runway (current risk=1.0 point)',
        'note' => 'POH totals are to clear 50 ft obstacle on paved level dry runway at max gross; grass adds 15% of ground roll.',
    ],
    'airports' => $airports,
];

echo json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
