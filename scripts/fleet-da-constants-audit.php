#!/usr/bin/env php
<?php
/**
 * Fleet audit: DA performance tier under policy variants (production API weather + history).
 *
 * Usage:
 *   CONFIG_PATH=/path/to/airports.json php scripts/fleet-da-constants-audit.php
 *   php scripts/fleet-da-constants-audit.php --variant legacy|shipped|wind3|spread_best|both
 */

declare(strict_types=1);

error_reporting(E_ALL & ~E_DEPRECATED);

require_once __DIR__ . '/../lib/config.php';
require_once __DIR__ . '/../lib/nasr/cache.php';
require_once __DIR__ . '/../lib/nasr/runway-selection.php';
require_once __DIR__ . '/../lib/weather/density-altitude-performance.php';
require_once __DIR__ . '/../lib/weather/da-performance-runway-end.php';
require_once __DIR__ . '/../lib/weather/history.php';
require_once __DIR__ . '/../lib/heading-conversion.php';
require_once __DIR__ . '/../lib/public-api/config.php';

/**
 * @return array<string, array{min_mean_kts: float, asymmetric_best_always: bool, use_legacy_tier: bool, label: string}>
 */
function fleetDaAuditVariants(): array
{
    return [
        'legacy' => [
            'label' => 'Pre-#213 worst-end tier',
            'min_mean_kts' => DA_PERF_WIND_MIN_MEAN_KTS,
            'asymmetric_best_always' => false,
            'use_legacy_tier' => true,
        ],
        'shipped' => [
            'label' => 'Shipped (#213)',
            'min_mean_kts' => DA_PERF_WIND_MIN_MEAN_KTS,
            'asymmetric_best_always' => false,
            'use_legacy_tier' => false,
        ],
        'wind3' => [
            'label' => 'MIN_MEAN_WIND 3 kt',
            'min_mean_kts' => 3.0,
            'asymmetric_best_always' => false,
            'use_legacy_tier' => false,
        ],
        'spread_best' => [
            'label' => 'Spread>=1.5 tier from best end (shipped after tweak)',
            'min_mean_kts' => DA_PERF_WIND_MIN_MEAN_KTS,
            'asymmetric_best_always' => true,
            'use_legacy_tier' => false,
        ],
        'both' => [
            'label' => 'Wind 3 kt + spread best',
            'min_mean_kts' => 3.0,
            'asymmetric_best_always' => true,
            'use_legacy_tier' => false,
        ],
    ];
}

function fleetDaAuditApiGet(string $url): ?array
{
    $ctx = stream_context_create([
        'http' => ['timeout' => 45, 'header' => "Accept: application/json\r\n"],
    ]);
    $raw = @file_get_contents($url, false, $ctx);
    if ($raw === false) {
        return null;
    }
    $decoded = json_decode($raw, true);

    return is_array($decoded) ? $decoded : null;
}

/**
 * @param list<array<string, mixed>> $observations API history observations
 * @return array<string, mixed>|null
 */
function fleetDaAuditWindowMeanWind(array $observations, ?array $airport, float $minMeanKts): ?array
{
    $windowHours = getPublicApiWindRoseWindowHours();
    $cutoff = time() - ($windowHours * 3600);
    $declination = ($airport !== null) ? getMagneticDeclination($airport) : 0.0;
    $uSum = 0.0;
    $vSum = 0.0;
    $scalarSum = 0.0;
    $count = 0;

    foreach ($observations as $obs) {
        if (!is_array($obs)) {
            continue;
        }
        $ts = $obs['obs_time'] ?? null;
        if (!is_numeric($ts) || (int) $ts < $cutoff) {
            $iso = $obs['obs_time_iso'] ?? null;
            if (is_string($iso)) {
                $parsed = strtotime($iso);
                if ($parsed === false || $parsed < $cutoff) {
                    continue;
                }
            } else {
                continue;
            }
        }
        $speed = $obs['wind_speed'] ?? null;
        if ($speed === null || !is_numeric($speed) || (float) $speed < CALM_WIND_THRESHOLD_KTS) {
            continue;
        }
        $speed = (float) $speed;
        $dirMag = resolveHistoryObservationWindDirectionMagnetic($obs, $declination);
        if ($dirMag === null) {
            continue;
        }
        $rad = deg2rad($dirMag);
        $uSum += -$speed * sin($rad);
        $vSum += -$speed * cos($rad);
        $scalarSum += $speed;
        $count++;
    }

    if ($count < DA_PERF_WIND_MIN_OBS) {
        return null;
    }

    $uMean = $uSum / $count;
    $vMean = $vSum / $count;
    $vectorSpeed = sqrt(($uMean * $uMean) + ($vMean * $vMean));
    if ($vectorSpeed < $minMeanKts) {
        return null;
    }

    $scalarMean = $scalarSum / $count;
    $dispersionRatio = $scalarMean / max($vectorSpeed, 0.001);
    if ($dispersionRatio > DA_PERF_VARIABLE_WIND_RATIO) {
        return null;
    }

    $direction = rad2deg(atan2(-$uMean, -$vMean));
    if ($direction < 0) {
        $direction += 360.0;
    }

    return [
        'direction_magnetic' => $direction,
        'speed_kts' => $vectorSpeed,
        'observation_count' => $count,
        'window_hours' => $windowHours,
        'dispersion_ratio' => $dispersionRatio,
    ];
}

/**
 * @param array<string, mixed> $variant
 * @return array<string, mixed>|null
 */
function fleetDaAuditBuildPerformance(
    array $weather,
    array $airport,
    string $airportId,
    array $historyObs,
    array $variant
): ?array {
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

    if (!empty($variant['use_legacy_tier'])) {
        $tier = densityAltitudePerformanceTierFromEndRisks($worstTotalRisk, $bestTotalRisk);
        if ($runwaySource === 'config' || $runwaySource === 'ourairports') {
            $tier = densityAltitudePerformanceCapTierWithoutObstructions($tier);
        }
        if ($tier === 'normal') {
            return null;
        }

        return [
            'tier' => $tier,
            'selection_basis' => 'both_ends',
            'operational_end_id' => null,
            'scored_end_risk' => null,
            'worst_end_risk' => round($worstTotalRisk, 3),
            'best_end_risk' => round($bestTotalRisk, 3),
        ];
    }

    $selection = [
        'selection_basis' => 'both_ends',
        'operational_end_id' => null,
        'scored_end' => null,
        'wind_basis' => null,
    ];

    if ($runwaySource !== 'config' && ($selectedRunway['ends'] ?? []) !== []) {
        $windBasis = fleetDaAuditWindowMeanWind($historyObs, $airport, (float) $variant['min_mean_kts']);
        if ($windBasis !== null) {
            $picked = pickDepartureEndByWindFromMagnetic(
                $selectedRunway,
                $airport,
                (float) $windBasis['direction_magnetic']
            );
            if ($picked !== null) {
                $selection = [
                    'selection_basis' => 'window_mean_wind',
                    'operational_end_id' => $picked['end_id'] ?? null,
                    'scored_end' => lookupEvaluationForRunwayEnd(
                        $evaluation,
                        $picked,
                        $selectedRunway,
                        (float) $pressureAltitude,
                        (float) $temperature,
                        $tables
                    ),
                    'wind_basis' => $windBasis,
                ];
            }
        }
    }

    if ($selection['selection_basis'] === 'both_ends') {
        $spread = $worstTotalRisk - $bestTotalRisk;
        $asymmetricOk = $spread >= DA_PERF_ASYMMETRIC_SPREAD
            && ($variant['asymmetric_best_always'] || $bestTotalRisk < DENSITY_ALTITUDE_PERFORMANCE_TIER_CAUTION);
        if ($asymmetricOk) {
            $selection = [
                'selection_basis' => 'asymmetric_heuristic',
                'operational_end_id' => $evaluation['best']['end_id'],
                'scored_end' => $evaluation['best'],
                'wind_basis' => null,
            ];
        }
    }

    if ($selection['selection_basis'] === 'both_ends') {
        $tier = densityAltitudePerformanceTierFromEndRisks($worstTotalRisk, $bestTotalRisk);
        $scoredRisk = null;
    } else {
        $scoredRisk = (float) ($selection['scored_end']['total_risk'] ?? 0.0);
        $tier = densityAltitudePerformanceTierFromScoredEnd($scoredRisk);
    }

    if ($runwaySource === 'config' || $runwaySource === 'ourairports') {
        $tier = densityAltitudePerformanceCapTierWithoutObstructions($tier);
    }

    if ($tier === 'normal') {
        return null;
    }

    return [
        'tier' => $tier,
        'selection_basis' => $selection['selection_basis'],
        'operational_end_id' => $selection['operational_end_id'],
        'scored_end_risk' => $scoredRisk !== null ? round($scoredRisk, 3) : null,
        'worst_end_risk' => round($worstTotalRisk, 3),
        'best_end_risk' => round($bestTotalRisk, 3),
        'wind_speed_kts' => $selection['wind_basis']['speed_kts'] ?? null,
    ];
}

$options = getopt('', ['variant:', 'help']);
if (isset($options['help'])) {
    echo "Fleet DA constants audit\n\n";
    echo "Usage: CONFIG_PATH=... php scripts/fleet-da-constants-audit.php [--variant legacy|shipped|wind3|spread_best|both]\n";
    exit(0);
}

$config = loadConfig();
$enabled = [];
foreach ($config['airports'] ?? [] as $id => $ap) {
    if ($ap['enabled'] ?? true) {
        $enabled[$id] = $ap;
    }
}
ksort($enabled);

$variants = fleetDaAuditVariants();
$onlyVariant = $options['variant'] ?? null;
if ($onlyVariant !== null && !isset($variants[$onlyVariant])) {
    fwrite(STDERR, "Unknown variant: $onlyVariant\n");
    exit(1);
}

$ids = array_keys($enabled);
$bulk = [];
foreach (array_chunk($ids, 10) as $chunk) {
    $url = 'https://api.aviationwx.org/v1/weather/bulk?airports=' . implode(',', $chunk);
    $resp = fleetDaAuditApiGet($url);
    if ($resp === null) {
        continue;
    }
    foreach (($resp['weather'] ?? []) as $aid => $w) {
        $bulk[$aid] = $w;
    }
    usleep(200000);
}

$runVariants = $onlyVariant !== null ? [$onlyVariant => $variants[$onlyVariant]] : $variants;
$results = [];

foreach ($ids as $airportId) {
    $weather = $bulk[$airportId] ?? null;
    if ($weather === null) {
        continue;
    }

    $historyUrl = 'https://api.aviationwx.org/v1/airports/' . rawurlencode($airportId) . '/weather/history?hours=1';
    $historyResp = fleetDaAuditApiGet($historyUrl);
    $historyObs = $historyResp['observations'] ?? [];
    usleep(100000);

    $ap = $enabled[$airportId];
    $ap['id'] = $airportId;
    $row = [
        'id' => $airportId,
        'da' => $weather['density_altitude'] ?? null,
        'pa' => $weather['pressure_altitude'] ?? null,
    ];

    foreach ($runVariants as $key => $variant) {
        $perf = fleetDaAuditBuildPerformance($weather, $ap, $airportId, $historyObs, $variant);
        $row[$key] = $perf['tier'] ?? 'normal';
        $row[$key . '_basis'] = $perf['selection_basis'] ?? '-';
        $row[$key . '_end'] = $perf['operational_end_id'] ?? '-';
        $row[$key . '_scored'] = $perf['scored_end_risk'] ?? null;
        $row[$key . '_worst'] = $perf['worst_end_risk'] ?? null;
        $row[$key . '_best'] = $perf['best_end_risk'] ?? null;
    }

    $results[] = $row;
}

if ($onlyVariant === null) {
    echo "FLEET DA POLICY COMPARISON\n";
    echo str_repeat('=', 72) . "\n";
    foreach (['legacy', 'shipped', 'wind3', 'spread_best', 'both'] as $vk) {
        $alerts = count(array_filter($results, static fn ($r) => ($r[$vk] ?? 'normal') !== 'normal'));
        echo sprintf("  %-14s %s: %d alerting\n", $vk, $variants[$vk]['label'], $alerts);
    }
    echo "\n";

    $focus = ['or81', 'kpfc', '69v', '7s9', '05s', '12id', '1xs0', 'khio', 's81'];
    echo "FOCUS AIRPORTS\n";
    printf("%-12s %6s | %-7s %-7s %-7s %-7s %-7s\n", 'id', 'DA', 'legacy', 'shipped', 'wind3', 'spread', 'both');
    foreach ($results as $r) {
        if (!in_array($r['id'], $focus, true)) {
            continue;
        }
        printf(
            "%-12s %6s | %-7s %-7s %-7s %-7s %-7s\n",
            $r['id'],
            (string) ($r['da'] ?? '-'),
            $r['legacy'] ?? '-',
            $r['shipped'] ?? '-',
            $r['wind3'] ?? '-',
            $r['spread_best'] ?? '-',
            $r['both'] ?? '-'
        );
        if (($r['shipped'] ?? 'normal') !== ($r['both'] ?? 'normal') || $r['id'] === 'or81') {
            echo sprintf(
                "    shipped: basis=%s end=%s scored=%s worst=%s best=%s\n",
                $r['shipped_basis'] ?? '-',
                $r['shipped_end'] ?? '-',
                $r['shipped_scored'] ?? '-',
                $r['shipped_worst'] ?? '-',
                $r['shipped_best'] ?? '-'
            );
            echo sprintf(
                "    both:    basis=%s end=%s scored=%s worst=%s best=%s\n",
                $r['both_basis'] ?? '-',
                $r['both_end'] ?? '-',
                $r['both_scored'] ?? '-',
                $r['both_worst'] ?? '-',
                $r['both_best'] ?? '-'
            );
        }
    }

    echo "\nSHIPPED -> BOTH CHANGES\n";
    foreach ($results as $r) {
        if (($r['shipped'] ?? 'normal') === ($r['both'] ?? 'normal')) {
            continue;
        }
        printf(
            "  %-16s %s -> %s  (shipped %s/%s, both %s/%s)\n",
            $r['id'],
            $r['shipped'],
            $r['both'],
            $r['shipped_basis'],
            $r['shipped_end'],
            $r['both_basis'],
            $r['both_end']
        );
    }
} else {
    echo json_encode($results, JSON_PRETTY_PRINT);
}
