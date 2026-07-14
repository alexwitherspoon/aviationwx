<?php
/**
 * Audit performance attention tiers using live weather from production API.
 *
 * Usage:
 *   CONFIG_PATH=/path/to/airports.json CACHE_BASE_DIR=/path/to/cache \
 *     php scripts/audit-performance-attention.php [--base-url=https://aviationwx.org]
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
require_once __DIR__ . '/../lib/weather/performance-attention.php';
require_once __DIR__ . '/../lib/weather/poh-takeoff.php';

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

    $fallback = assessFallbackPerformanceAttention($densityAltitudeFt, $fieldElevationFt);
    $fallbackTier = $fallback['tier'] ?? 'none';

    if ($selectedRunway === null) {
        return [
            'path' => 'fallback',
            'tier' => $fallbackTier,
            'risk_factor' => null,
            'fallback_tier' => $fallbackTier,
            'prod_attention' => $weather['_prod_attention'] ?? null,
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
    $tier = performanceAttentionTierFromEndRisks($worstTotalRisk, $bestTotalRisk);
    $riskFactor = performanceAttentionRiskFactorForTier($tier, $worstTotalRisk, $bestTotalRisk);

    $worstEnd = [
        'end_id' => $evaluation['worst']['end_id'],
        'obst_mult' => null,
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
        $mult = calculateDepartureObstructionMultiplier(
            isset($obst['hgt_ft']) ? (float) $obst['hgt_ft'] : null,
            isset($obst['dist_ft']) ? (float) $obst['dist_ft'] : null,
            $availableFt
        );
        $req172 = pohRequiredTakeoffDistanceFt(
            $tables['c172'],
            (float) $pressureAltitude,
            (float) $temperature,
            $nonPaved,
            $mult
        );
        $worstEnd['obst_mult'] = round($mult, 2);
        $worstEnd['req172'] = $req172;
    }

    return [
        'path' => 'full',
        'tier' => $tier,
        'risk_factor' => round($riskFactor, 3),
        'fallback_tier' => $fallbackTier,
        'prod_attention' => $weather['_prod_attention'] ?? null,
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
        'worst_obst_mult' => $worstEnd['obst_mult'] ?? null,
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
    $weather['_prod_attention'] = $weather['performance_attention'] ?? null;
    $weather['_last_updated_iso'] = $weather['last_updated_iso'] ?? null;

    return ['ok' => true, 'weather' => $weather, 'error' => null, 'http_code' => $httpCode];
}

$baseUrl = 'https://aviationwx.org';
foreach ($argv as $arg) {
    if (str_starts_with($arg, '--base-url=')) {
        $baseUrl = substr($arg, strlen('--base-url='));
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
    'none' => 0,
    'caution' => 0,
    'strong' => 0,
    'full' => 0,
    'fallback' => 0,
    'fallback_would_differ' => 0,
    'prod_mismatch' => 0,
];

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
        ];
        usleep(150000);
        continue;
    }

    $weather = $fetch['weather'];
    if (!isset($weather['density_altitude']) || !is_numeric($weather['density_altitude'])) {
        $summary['no_da']++;
        $rows[] = [
            'id' => $airportId,
            'name' => $airport['name'] ?? '',
            'status' => 'no_da',
            'last_updated_iso' => $weather['_last_updated_iso'] ?? null,
        ];
        usleep(150000);
        continue;
    }

    $detail = auditBuildPerformanceDetail($weather, $airport);
    if ($detail === null) {
        $summary['no_da']++;
        continue;
    }

    $tier = $detail['tier'] ?? 'none';
    if ($tier === 'none') {
        $summary['none']++;
    } elseif ($tier === 'caution') {
        $summary['caution']++;
    } elseif ($tier === 'strong') {
        $summary['strong']++;
    }

    if (($detail['path'] ?? '') === 'full') {
        $summary['full']++;
    } else {
        $summary['fallback']++;
    }

    $fb = $detail['fallback_tier'] ?? 'none';
    if ($fb !== $tier && ($fb !== 'none' || $tier !== 'none')) {
        $summary['fallback_would_differ']++;
    }

    $prodTier = is_array($detail['prod_attention'] ?? null)
        ? ($detail['prod_attention']['tier'] ?? 'none')
        : 'none';
    if ($prodTier !== $tier) {
        $summary['prod_mismatch']++;
    }

    $rows[] = array_merge([
        'id' => $airportId,
        'name' => $airport['name'] ?? '',
        'last_updated_iso' => $weather['_last_updated_iso'] ?? null,
        'prod_tier' => $prodTier,
    ], $detail);

    usleep(150000);
}

usort($rows, static function (array $a, array $b): int {
    $tierOrder = ['strong' => 0, 'caution' => 1, 'none' => 2];
    $ta = $tierOrder[$a['tier'] ?? 'none'] ?? 3;
    $tb = $tierOrder[$b['tier'] ?? 'none'] ?? 3;
    if ($ta !== $tb) {
        return $ta <=> $tb;
    }
    return strcmp((string) ($a['id'] ?? ''), (string) ($b['id'] ?? ''));
});

echo json_encode(['summary' => $summary, 'airports' => $rows], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
