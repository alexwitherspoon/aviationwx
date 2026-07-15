<?php
/**
 * Audit density_altitude_performance tiers using live weather from production API.
 *
 * Usage:
 *   CONFIG_PATH=/path/to/airports.json CACHE_BASE_DIR=/path/to/cache \
 *     php scripts/audit-density-altitude-performance.php [--base-url=https://aviationwx.org]
 *
 *   php scripts/audit-density-altitude-performance.php --spot-check=KLXV,KASE,KSTS,KICT
 *   php scripts/audit-density-altitude-performance.php --configured-only --format=table
 *   php scripts/audit-density-altitude-performance.php --no-configured --spot-check=KLXV,KASE
 */

declare(strict_types=1);

$cacheBase = getenv('CACHE_BASE_DIR');
if (is_string($cacheBase) && $cacheBase !== '') {
    define('CACHE_BASE_DIR', $cacheBase);
}

require_once __DIR__ . '/../lib/config.php';
require_once __DIR__ . '/../lib/cache-paths.php';
require_once __DIR__ . '/../lib/constants.php';
require_once __DIR__ . '/../lib/nasr/cache.php';
require_once __DIR__ . '/../lib/nasr/runway-selection.php';
require_once __DIR__ . '/../lib/weather/density-altitude-performance.php';
require_once __DIR__ . '/../lib/weather/poh-takeoff.php';
require_once __DIR__ . '/../lib/weather/calculator.php';
require_once __DIR__ . '/../lib/weather/adapter/metar-v1.php';
require_once __DIR__ . '/../lib/nasr/identifiers.php';

/**
 * @return array<string, mixed>|null
 */
function auditBuildPerformanceDetail(array $weather, array $airport): ?array
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
    if ($nasrRecord === null) {
        $nasrRecord = auditLookupNasrFromFullCache($airport);
    }
    $fieldElevationFt = getEffectiveFieldElevationFt($airport, $nasrRecord);
    $configLength = getConfigRunwayLengthOverrideFt($airport);
    $selectedRunway = null;
    $runwaySource = 'none';

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
        $runwaySource = $selectedRunway !== null ? 'nasr' : 'nasr_no_runway';
    }

    $fallback = assessFallbackDensityAltitudePerformance($densityAltitudeFt, $fieldElevationFt);
    $fallbackTier = $fallback['tier'] ?? 'normal';

    if ($selectedRunway === null) {
        return [
            'path' => 'fallback',
            'tier' => $fallbackTier,
            'risk_factor' => null,
            'fallback_tier' => $fallbackTier,
            'prod_density_altitude_performance' => $weather['_prod_density_altitude_performance'] ?? null,
            'field_elev_ft' => $fieldElevationFt,
            'da_ft' => $densityAltitudeFt,
            'da_delta_ft' => $fieldElevationFt !== null ? $densityAltitudeFt - $fieldElevationFt : null,
            'pa_ft' => (int) round((float) $pressureAltitude),
            'temp_c' => round((float) $temperature, 1),
            'runway_source' => $runwaySource,
            'runway_length_ft' => null,
            'runway_surface' => null,
            'total_risk' => null,
        ];
    }

    $tables = loadPohTakeoffTables();
    $availableFt = (int) ($selectedRunway['length_ft'] ?? 0);
    $surface = (string) ($selectedRunway['surface'] ?? '');
    $nonPaved = nasrIsNonPavedSurface($surface);

    $evaluation = evaluateRunwayEndPerformanceRange(
        $selectedRunway,
        (float) $pressureAltitude,
        (float) $temperature,
        $tables
    );

    $worstTotalRisk = $evaluation['worst']['total_risk'];
    $bestTotalRisk = $evaluation['best']['total_risk'];
    $tier = densityAltitudePerformanceTierFromEndRisks($worstTotalRisk, $bestTotalRisk);
    $riskFactor = densityAltitudePerformanceRiskFactorForTier($tier, $worstTotalRisk, $bestTotalRisk);

    $worstEnd = [
        'end_id' => $evaluation['worst']['end_id'],
        'obst_hgt_ft' => null,
        'obst_dist_ft' => null,
        'risk172' => round($evaluation['worst']['risk172'], 3),
        'total_risk' => round($worstTotalRisk, 3),
    ];

    $ends = $selectedRunway['ends'] ?? [];
    if ($ends === []) {
        $ends = [['end_id' => null, 'obstruction' => []]];
    }

    foreach ($ends as $end) {
        if (!is_array($end)) {
            continue;
        }
        if (($end['end_id'] ?? null) !== $evaluation['worst']['end_id']) {
            continue;
        }
        $obst = is_array($end['obstruction'] ?? null) ? $end['obstruction'] : [];
        $obstHgt = isset($obst['hgt_ft']) ? (float) $obst['hgt_ft'] : null;
        $obstDist = isset($obst['dist_ft']) ? (float) $obst['dist_ft'] : null;
        $obstSlope = isset($obst['slope']) && is_numeric($obst['slope']) && (float) $obst['slope'] > 0
            ? (float) $obst['slope']
            : null;
        $req172 = pohRequiredTakeoffDistanceFt(
            $tables['c172'],
            (float) $pressureAltitude,
            (float) $temperature,
            $nonPaved,
            $obstHgt,
            $obstDist,
            $availableFt,
            $obstSlope
        );
        $worstEnd['obst_hgt_ft'] = $obstHgt;
        $worstEnd['obst_dist_ft'] = $obstDist;
        $worstEnd['req172'] = $req172;
    }

    return [
        'path' => 'full',
        'tier' => $tier,
        'risk_factor' => round($riskFactor, 3),
        'fallback_tier' => $fallbackTier,
        'prod_density_altitude_performance' => $weather['_prod_density_altitude_performance'] ?? null,
        'field_elev_ft' => $fieldElevationFt,
        'da_ft' => $densityAltitudeFt,
        'da_delta_ft' => $fieldElevationFt !== null ? $densityAltitudeFt - $fieldElevationFt : null,
        'pa_ft' => (int) round((float) $pressureAltitude),
        'temp_c' => round((float) $temperature, 1),
        'runway_source' => $runwaySource,
        'runway_id' => $selectedRunway['rwy_id'] ?? null,
        'runway_length_ft' => $availableFt,
        'runway_surface' => $surface,
        'non_paved' => $nonPaved,
        'worst_end_id' => $evaluation['worst']['end_id'],
        'best_end_id' => $evaluation['best']['end_id'],
        'worst_obst_hgt_ft' => $worstEnd['obst_hgt_ft'] ?? null,
        'worst_obst_dist_ft' => $worstEnd['obst_dist_ft'] ?? null,
        'risk152' => round($evaluation['worst']['risk152'], 3),
        'risk172' => $worstEnd['risk172'] ?? null,
        'risk182' => round($evaluation['worst']['risk182'], 3),
        'worst_total_risk' => round($worstTotalRisk, 3),
        'best_total_risk' => round($bestTotalRisk, 3),
        'stress172' => $availableFt > 0 && isset($worstEnd['req172'])
            ? round($worstEnd['req172'] / $availableFt, 2) : null,
    ];
}

/**
 * @return array{ok: bool, weather: ?array, error: ?string, http_code: int}
 */
function fetchProductionWeather(string $baseUrl, string $airportId): array
{
    $url = rtrim($baseUrl, '/') . '/api/weather.php?airport=' . rawurlencode($airportId);
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 30,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_USERAGENT => 'AviationWX-PerformanceAudit/1.0 (+https://aviationwx.org)',
    ]);
    $body = curl_exec($ch);
    $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($body === false || $httpCode !== 200) {
        return ['ok' => false, 'weather' => null, 'error' => 'HTTP ' . $httpCode, 'http_code' => $httpCode];
    }

    $decoded = json_decode($body, true);
    if (!is_array($decoded) || empty($decoded['success']) || !is_array($decoded['weather'] ?? null)) {
        return ['ok' => false, 'weather' => null, 'error' => 'invalid_json', 'http_code' => $httpCode];
    }

    $weather = $decoded['weather'];
    if (isset($weather['temperature_f']) && !isset($weather['temperature']) && is_numeric($weather['temperature_f'])) {
        $weather['temperature'] = ((float) $weather['temperature_f'] - 32) * 5 / 9;
    }
    $weather['_prod_density_altitude_performance'] = $weather['density_altitude_performance'] ?? null;
    $weather['_last_updated_iso'] = $weather['last_updated_iso'] ?? null;

    return ['ok' => true, 'weather' => $weather, 'error' => null, 'http_code' => $httpCode];
}

/**
 * Lookup NASR record from full APT cache (not configured slice only).
 *
 * @return array<string, mixed>|null
 */
function auditLookupNasrFromFullCache(array $airport): ?array
{
    $cache = nasrReadFullAptCacheFromDisk();
    if ($cache === null) {
        return null;
    }
    $airports = $cache['airports'] ?? [];
    if (!is_array($airports)) {
        return null;
    }
    foreach (nasrCandidateArptIds($airport) as $candidate) {
        if (isset($airports[$candidate]) && is_array($airports[$candidate])) {
            return $airports[$candidate];
        }
    }

    return null;
}

/**
 * Build minimal airport config for METAR spot-check by ICAO.
 *
 * @return array<string, mixed>
 */
function auditSpotCheckAirportStub(string $icao): array
{
    $icao = strtoupper(trim($icao));
    $stub = [
        'id' => strtolower($icao),
        'icao' => $icao,
        'name' => $icao,
        'enabled' => true,
        'weather_sources' => [
            ['type' => 'metar', 'station' => $icao],
        ],
    ];
    $nasr = auditLookupNasrFromFullCache($stub);
    if ($nasr !== null && isset($nasr['elev_ft']) && is_numeric($nasr['elev_ft'])) {
        $stub['elevation_ft'] = (int) $nasr['elev_ft'];
    }

    return $stub;
}

/**
 * Fetch live METAR and compute PA/DA for spot-check airports.
 *
 * @return array{ok: bool, weather: ?array, error: ?string}
 */
function fetchSpotCheckWeather(array $airport): array
{
    $icao = strtoupper(trim((string) ($airport['icao'] ?? '')));
    if ($icao === '') {
        return ['ok' => false, 'weather' => null, 'error' => 'missing_icao'];
    }

    $metar = fetchMETARFromStation($icao, $airport);
    if (!is_array($metar)) {
        return ['ok' => false, 'weather' => null, 'error' => 'metar_failed'];
    }

    $weather = $metar;
    if (!isset($weather['temperature']) || !isset($weather['pressure'])) {
        return ['ok' => false, 'weather' => null, 'error' => 'metar_incomplete'];
    }

    $nasr = auditLookupNasrFromFullCache($airport);
    $elev = getEffectiveFieldElevationFt($airport, $nasr);
    if ($elev === null) {
        return ['ok' => false, 'weather' => null, 'error' => 'missing_elevation'];
    }
    $airport['elevation_ft'] = $elev;

    $weather['pressure_altitude'] = calculatePressureAltitude($weather, $airport);
    $weather['density_altitude'] = calculateDensityAltitude($weather, $airport);
    $weather['_metar_obs_time'] = $metar['obs_time'] ?? null;
    $weather['_metar_raw'] = $metar['raw_metar'] ?? ($metar['rawOb'] ?? null);

    return ['ok' => true, 'weather' => $weather, 'error' => null];
}

/**
 * @param array<string, mixed> $summary
 */
function auditRecordRow(array &$summary, array &$rows, string $id, array $airport, array $weather, ?string $source): void
{
    if (!isset($weather['density_altitude']) || !is_numeric($weather['density_altitude'])) {
        $summary['no_da']++;
        $rows[] = [
            'id' => $id,
            'name' => $airport['name'] ?? '',
            'status' => 'no_da',
            'source' => $source,
            'last_updated_iso' => $weather['_last_updated_iso'] ?? null,
        ];
        return;
    }

    $detail = auditBuildPerformanceDetail($weather, $airport);
    if ($detail === null) {
        $summary['no_da']++;
        return;
    }

    $tier = $detail['tier'] ?? 'normal';
    if ($tier === 'normal') {
        $summary['normal']++;
    } elseif ($tier === 'caution') {
        $summary['caution']++;
    } elseif ($tier === 'warning') {
        $summary['warning']++;
    }

    if (($detail['path'] ?? '') === 'full') {
        $summary['full']++;
    } else {
        $summary['fallback']++;
    }

    $fb = $detail['fallback_tier'] ?? 'normal';
    if ($fb !== $tier && ($fb !== 'normal' || $tier !== 'normal')) {
        $summary['fallback_would_differ']++;
    }

    $prodTier = is_array($detail['prod_density_altitude_performance'] ?? null)
        ? ($detail['prod_density_altitude_performance']['tier'] ?? 'normal')
        : 'normal';
    if ($prodTier !== $tier) {
        $summary['prod_mismatch']++;
    }

    $apiPayload = buildDensityAltitudePerformance($weather, $airport);
    $rows[] = array_merge([
        'id' => $id,
        'name' => $airport['name'] ?? '',
        'source' => $source,
        'last_updated_iso' => $weather['_last_updated_iso'] ?? null,
        'prod_tier' => $prodTier,
        'api_tier' => is_array($apiPayload) ? (string) ($apiPayload['tier'] ?? 'normal') : 'normal',
    ], $detail);
}

/**
 * @param array<string, mixed> $summary
 * @param list<array<string, mixed>> $rows
 */
function auditPrintTable(array $summary, array $rows): void
{
    echo "Density altitude performance audit @ {$summary['audited_at']}\n";
    echo "Base URL: {$summary['base_url']}\n";
    echo "Total: {$summary['total']} | warning: {$summary['warning']} | caution: {$summary['caution']} | normal: {$summary['normal']}";
    echo " | full model: {$summary['full']} | fallback: {$summary['fallback']}";
    if (($summary['prod_mismatch'] ?? 0) > 0) {
        echo " | prod mismatch: {$summary['prod_mismatch']}";
    }
    echo "\n\n";

    printf(
        "%-12s %-28s %-8s %-8s %-6s %5s %5s %4s %5s %-6s %4s %s\n",
        'ID',
        'Name',
        'Tier',
        'Prod',
        'Path',
        'DA',
        'Elev',
        'PA',
        'TempC',
        'RwyFt',
        'Surf',
        'Worst end / risks'
    );
    echo str_repeat('-', 120) . "\n";

    foreach ($rows as $row) {
        if (($row['status'] ?? '') === 'fetch_failed') {
            printf("%-12s %-28s FETCH FAILED (%s)\n", $row['id'] ?? '', $row['name'] ?? '', $row['error'] ?? '');
            continue;
        }
        if (($row['status'] ?? '') === 'no_da') {
            printf("%-12s %-28s NO DA\n", $row['id'] ?? '', $row['name'] ?? '');
            continue;
        }

        $risk = sprintf(
            'w=%.2f b=%.2f',
            (float) ($row['worst_total_risk'] ?? 0),
            (float) ($row['best_total_risk'] ?? 0)
        );
        $end = $row['worst_end_id'] ?? '';
        if ($end !== '') {
            $risk = $end . ' ' . $risk;
        }

        printf(
            "%-12s %-28s %-8s %-8s %-6s %5s %5s %4s %5s %-6s %4s %s\n",
            (string) ($row['id'] ?? ''),
            mb_strimwidth((string) ($row['name'] ?? ''), 0, 28, '…'),
            (string) ($row['tier'] ?? 'normal'),
            (string) ($row['prod_tier'] ?? '-'),
            (string) ($row['path'] ?? ''),
            isset($row['da_ft']) ? (string) $row['da_ft'] : '-',
            isset($row['field_elev_ft']) ? (string) $row['field_elev_ft'] : '-',
            isset($row['pa_ft']) ? (string) $row['pa_ft'] : '-',
            isset($row['temp_c']) ? (string) $row['temp_c'] : '-',
            isset($row['runway_length_ft']) ? (string) $row['runway_length_ft'] : '-',
            isset($row['runway_surface']) ? mb_strimwidth((string) $row['runway_surface'], 0, 4, '') : '-',
            $risk
        );
    }
}

$baseUrl = 'https://aviationwx.org';
$format = 'json';
$runConfigured = true;
$spotChecks = [];
foreach ($argv as $arg) {
    if (str_starts_with($arg, '--base-url=')) {
        $baseUrl = substr($arg, strlen('--base-url='));
    } elseif (str_starts_with($arg, '--format=')) {
        $format = substr($arg, strlen('--format='));
    } elseif (str_starts_with($arg, '--spot-check=')) {
        $raw = substr($arg, strlen('--spot-check='));
        foreach (explode(',', $raw) as $icao) {
            $icao = strtoupper(trim($icao));
            if ($icao !== '') {
                $spotChecks[] = $icao;
            }
        }
    } elseif ($arg === '--configured-only') {
        $runConfigured = true;
        $spotChecks = [];
    } elseif ($arg === '--no-configured') {
        $runConfigured = false;
    }
}

$config = loadConfig();
if ($config === null) {
    fwrite(STDERR, "Config load failed\n");
    exit(1);
}

$airports = $config['airports'] ?? [];
$rows = [];
$summary = [
    'base_url' => $baseUrl,
    'audited_at' => gmdate('c'),
    'total' => 0,
    'fetch_failed' => 0,
    'no_da' => 0,
    'normal' => 0,
    'caution' => 0,
    'warning' => 0,
    'full' => 0,
    'fallback' => 0,
    'fallback_would_differ' => 0,
    'prod_mismatch' => 0,
    'spot_check' => count($spotChecks),
];

if ($runConfigured) {
    foreach ($airports as $airportId => $airport) {
        if (!is_array($airport) || empty($airport['enabled'])) {
            continue;
        }

        $summary['total']++;
        $fetch = fetchProductionWeather($baseUrl, (string) $airportId);
        if (!$fetch['ok']) {
            $summary['fetch_failed']++;
            $rows[] = [
                'id' => $airportId,
                'name' => $airport['name'] ?? '',
                'status' => 'fetch_failed',
                'error' => $fetch['error'],
                'source' => 'configured',
            ];
            usleep(150000);
            continue;
        }

        auditRecordRow($summary, $rows, (string) $airportId, $airport, $fetch['weather'], 'configured');
        usleep(150000);
    }
}

foreach ($spotChecks as $icao) {
    $summary['total']++;
    $airport = auditSpotCheckAirportStub($icao);
    $fetch = fetchSpotCheckWeather($airport);
    if (!$fetch['ok']) {
        $summary['fetch_failed']++;
        $rows[] = [
            'id' => strtolower($icao),
            'name' => $icao,
            'status' => 'fetch_failed',
            'error' => $fetch['error'],
            'source' => 'spot_check',
        ];
        usleep(200000);
        continue;
    }

    auditRecordRow($summary, $rows, strtolower($icao), $airport, $fetch['weather'], 'spot_check');
    usleep(200000);
}

if ($summary['total'] === 0) {
    fwrite(STDERR, "No airports to audit. Use default configured scan or --spot-check=KASE,KLXV\n");
    exit(1);
}

usort($rows, static function (array $a, array $b): int {
    $tierOrder = ['warning' => 0, 'caution' => 1, 'normal' => 2];
    $ta = $tierOrder[$a['tier'] ?? 'normal'] ?? 3;
    $tb = $tierOrder[$b['tier'] ?? 'normal'] ?? 3;
    if ($ta !== $tb) {
        return $ta <=> $tb;
    }
    return strcmp((string) ($a['id'] ?? ''), (string) ($b['id'] ?? ''));
});

if ($format === 'table' || $format === 'text') {
    auditPrintTable($summary, $rows);
    exit(0);
}

echo json_encode(['summary' => $summary, 'airports' => $rows], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
