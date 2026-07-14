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
 * Required takeoff distance for one reference model at PA/temp with surface and obstruction.
 *
 * @param array $table POH fixture table
 */
function pohRequiredTakeoffDistanceFt(
    array $table,
    float $pressureAltitudeFt,
    float $tempC,
    bool $nonPavedSurface,
    float $obstructionMultiplier
): int {
    $chartTotal = pohLookupChartTotalFt($table, $pressureAltitudeFt, $tempC);
    if ($nonPavedSurface) {
        $groundRoll = pohLookupChartGroundRollFt($table, $pressureAltitudeFt, $tempC);
        $chartTotal = pohApplyGrassCorrection($chartTotal, $groundRoll);
    }

    return (int) round($chartTotal * max(1.0, $obstructionMultiplier));
}
