<?php
/**
 * AFM takeoff distance table lookup for reference Cessna models.
 */

require_once __DIR__ . '/../constants.php';

/**
 * @var array<string, array>|null
 */
$GLOBALS['_poh_takeoff_tables'] = null;

/**
 * Load POH takeoff fixture tables (memoized).
 *
 * @return array<string, array>
 */
function loadPohTakeoffTables(): array
{
    if (is_array($GLOBALS['_poh_takeoff_tables'])) {
        return $GLOBALS['_poh_takeoff_tables'];
    }

    $base = dirname(__DIR__, 2) . '/data/poh';
    $files = [
        'c152' => $base . '/c152m-takeoff.json',
        'c172' => $base . '/c172n-takeoff.json',
        'c182' => $base . '/c182t-takeoff.json',
    ];

    $tables = [];
    foreach ($files as $key => $path) {
        if (!is_readable($path)) {
            throw new RuntimeException('POH takeoff fixture missing: ' . $path);
        }
        $decoded = json_decode((string) file_get_contents($path), true);
        if (!is_array($decoded)) {
            throw new RuntimeException('POH takeoff fixture invalid JSON: ' . $path);
        }
        $tables[$key] = $decoded;
    }

    $GLOBALS['_poh_takeoff_tables'] = $tables;
    return $tables;
}

/**
 * Override POH fixture directory (testing).
 */
function setPohTakeoffFixtureDirectory(string $directory): void
{
    $base = rtrim($directory, '/');
    $GLOBALS['_poh_takeoff_tables'] = [
        'c152' => json_decode((string) file_get_contents($base . '/c152m-takeoff.json'), true),
        'c172' => json_decode((string) file_get_contents($base . '/c172n-takeoff.json'), true),
        'c182' => json_decode((string) file_get_contents($base . '/c182t-takeoff.json'), true),
    ];
}

/**
 * Reset POH table memo (testing).
 */
function resetPohTakeoffTables(): void
{
    $GLOBALS['_poh_takeoff_tables'] = null;
}

/**
 * Round up to the next chart bin (or top bin when above range).
 *
 * @param list<int|float> $bins
 */
function pohRoundUpBin(float $value, array $bins): int
{
    foreach ($bins as $bin) {
        if ($value <= (float) $bin) {
            return (int) $bin;
        }
    }
    return (int) end($bins);
}

/**
 * Lookup chart total distance to clear 50 ft obstacle.
 *
 * @param array $table POH fixture table
 */
function pohLookupChartTotalFt(array $table, float $pressureAltitudeFt, float $tempC): int
{
    $paBins = $table['pressure_altitude_ft'] ?? [];
    $tempBins = $table['temperature_c'] ?? [];
    if ($paBins === [] || $tempBins === []) {
        throw new InvalidArgumentException('POH table missing altitude or temperature bins');
    }

    $paKey = (string) pohRoundUpBin($pressureAltitudeFt, $paBins);
    $tempKey = (string) pohRoundUpBin($tempC, $tempBins);

    $total = $table['total_ft'][$paKey][$tempKey] ?? null;
    if (!is_numeric($total)) {
        throw new RuntimeException("POH lookup miss for PA {$paKey} temp {$tempKey}");
    }

    return (int) $total;
}

/**
 * Lookup chart ground roll for grass correction.
 *
 * @param array $table POH fixture table
 */
function pohLookupChartGroundRollFt(array $table, float $pressureAltitudeFt, float $tempC): int
{
    $paBins = $table['pressure_altitude_ft'] ?? [];
    $tempBins = $table['temperature_c'] ?? [];
    $paKey = (string) pohRoundUpBin($pressureAltitudeFt, $paBins);
    $tempKey = (string) pohRoundUpBin($tempC, $tempBins);

    $ground = $table['ground_roll_ft'][$paKey][$tempKey] ?? null;
    if (!is_numeric($ground)) {
        throw new RuntimeException("POH ground roll lookup miss for PA {$paKey} temp {$tempKey}");
    }

    return (int) $ground;
}

/**
 * Apply POH note 4 grass correction: total + 15% of ground roll.
 */
function pohApplyGrassCorrection(int $chartTotalFt, int $groundRollFt): int
{
    return (int) round($chartTotalFt + ($groundRollFt * POH_GRASS_GROUND_ROLL_FACTOR));
}

/**
 * Chart total distance (with grass correction when applicable) for PA/temp.
 *
 * @param array $table POH fixture table
 */
function pohChartSurfaceTotalFt(
    array $table,
    float $pressureAltitudeFt,
    float $tempC,
    bool $nonPavedSurface
): int {
    $chartTotal = pohLookupChartTotalFt($table, $pressureAltitudeFt, $tempC);
    if ($nonPavedSurface) {
        $groundRoll = pohLookupChartGroundRollFt($table, $pressureAltitudeFt, $tempC);
        $chartTotal = pohApplyGrassCorrection($chartTotal, $groundRoll);
    }

    return $chartTotal;
}

/**
 * Scale POH 50 ft obstacle chart distance for a departure obstacle height.
 *
 * Linear for all heights: chart distance scales by obst_hgt / 50 (including above 50 ft).
 */
function pohObstacleHeightRatio(float $obstHgtFt): float
{
    return $obstHgtFt / (float) POH_OBSTACLE_REFERENCE_HEIGHT_FT;
}

/**
 * Obstacle height used for POH linear scaling after NASR clearance-surface check.
 *
 * NASR OBSTN_CLNC_SLOPE is horizontal feet per vertical foot (e.g. 40 = 40:1).
 * When the obstacle lies on or below the published surface at its distance, only
 * runway-length stress applies. When it penetrates, use full obstacle height.
 *
 * @param float $obstHgtFt NASR OBSTN_HGT
 * @param float $obstDistFt NASR DIST_FROM_THR
 * @param float|null $obstClncSlope NASR OBSTN_CLNC_SLOPE when published and positive
 * @return float Effective height for linear scaling (0 when on clearance surface)
 */
function pohEffectiveObstacleHeightForChart(
    float $obstHgtFt,
    float $obstDistFt,
    ?float $obstClncSlope
): float {
    if ($obstClncSlope === null || $obstClncSlope <= 0) {
        return $obstHgtFt;
    }

    $allowedHgtAtDist = $obstDistFt / $obstClncSlope;
    if ($obstHgtFt <= $allowedHgtAtDist) {
        return 0.0;
    }

    return $obstHgtFt;
}

/**
 * Takeoff stress from POH chart distance vs runway and departure obstruction.
 *
 * Chart total is distance to clear a 50 ft obstacle (POH standard). When a departure
 * obstruction lies at dist_ft with hgt_ft, required distance scales linearly by
 * obst_hgt/50 (including above 50 ft). When NASR publishes OBSTN_CLNC_SLOPE, obstacles
 * on or below that clearance surface do not add obstacle stress beyond runway roll.
 *
 * @param array $table POH fixture table
 * @param float|null $obstHgtFt NASR OBSTN_HGT
 * @param float|null $obstDistFt NASR DIST_FROM_THR
 * @param float|null $obstClncSlope NASR OBSTN_CLNC_SLOPE when published and positive
 */
function pohComputeDepartureEndStress(
    array $table,
    float $pressureAltitudeFt,
    float $tempC,
    bool $nonPavedSurface,
    int $runwayLengthFt,
    ?float $obstHgtFt,
    ?float $obstDistFt,
    ?float $obstClncSlope = null
): float {
    if ($runwayLengthFt <= 0) {
        return (float) PERFORMANCE_STRESS_HIGH;
    }

    $chartTotal = pohChartSurfaceTotalFt($table, $pressureAltitudeFt, $tempC, $nonPavedSurface);
    $stressRunway = $chartTotal / $runwayLengthFt;

    if ($obstHgtFt === null || $obstDistFt === null || $obstHgtFt <= 0 || $obstDistFt <= 0) {
        return $stressRunway;
    }
    if ($obstDistFt > $runwayLengthFt) {
        return $stressRunway;
    }

    $effectiveObstHgt = pohEffectiveObstacleHeightForChart($obstHgtFt, $obstDistFt, $obstClncSlope);
    if ($effectiveObstHgt <= 0) {
        return $stressRunway;
    }

    $heightRatio = pohObstacleHeightRatio($effectiveObstHgt);
    $requiredToClearObstacle = $chartTotal * $heightRatio;
    $stressObstacle = $requiredToClearObstacle / $obstDistFt;

    return max($stressRunway, $stressObstacle);
}

/**
 * Required takeoff distance for one reference model at PA/temp with surface and obstruction.
 *
 * @param array $table POH fixture table
 */
function pohRequiredTakeoffDistanceFt(
    array $table,
    float $pressureAltitudeFt,
    float $tempC,
    bool $nonPavedSurface,
    ?float $obstHgtFt = null,
    ?float $obstDistFt = null,
    int $runwayLengthFt = 0,
    ?float $obstClncSlope = null
): int {
    $stress = pohComputeDepartureEndStress(
        $table,
        $pressureAltitudeFt,
        $tempC,
        $nonPavedSurface,
        $runwayLengthFt > 0 ? $runwayLengthFt : 1,
        $obstHgtFt,
        $obstDistFt,
        $obstClncSlope
    );

    $available = $runwayLengthFt > 0 ? $runwayLengthFt : 1;

    return (int) round($stress * $available);
}
