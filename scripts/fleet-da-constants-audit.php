#!/usr/bin/env php
<?php
/**
 * Fleet audit: density altitude performance tiers using production API weather.
 *
 * Usage:
 *   CONFIG_PATH=/path/to/airports.json php scripts/fleet-da-constants-audit.php
 *   php scripts/fleet-da-constants-audit.php --format=json
 */

declare(strict_types=1);

error_reporting(E_ALL & ~E_DEPRECATED);

require_once __DIR__ . '/../lib/config.php';
require_once __DIR__ . '/../lib/embed-helpers.php';
require_once __DIR__ . '/../lib/weather/density-altitude-performance.php';

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
 * Normalize Public API bulk weather rows for local computeDensityAltitudePerformance().
 *
 * @param array<string, mixed> $weather
 * @return array<string, mixed>
 */
function fleetDaAuditNormalizeWeatherRow(array $weather): array
{
    if (isset($weather['wind_direction']) && is_array($weather['wind_direction'])) {
        return convertPublicApiToInternalFormat($weather);
    }

    return $weather;
}

$options = getopt('', ['format:', 'help']);
if (isset($options['help'])) {
    echo "Fleet DA performance audit\n\n";
    echo "Usage: CONFIG_PATH=... php scripts/fleet-da-constants-audit.php [--format=json]\n";
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

$results = [];
foreach ($ids as $airportId) {
    $weather = $bulk[$airportId] ?? null;
    if (!is_array($weather)) {
        continue;
    }

    $ap = $enabled[$airportId];
    $ap['id'] = $airportId;
    $normalizedWeather = fleetDaAuditNormalizeWeatherRow($weather);
    $perf = computeDensityAltitudePerformance($normalizedWeather, $ap, $airportId);

    $results[] = [
        'id' => $airportId,
        'da' => $weather['density_altitude'] ?? null,
        'pa' => $weather['pressure_altitude'] ?? null,
        'tier' => $perf['tier'] ?? 'normal',
        'selection_basis' => $perf['selection_basis'] ?? '-',
        'best_end_id' => $perf['best_end']['end_id'] ?? '-',
        'best_rwy_id' => $perf['best_end']['rwy_id'] ?? '-',
        'best_total_risk' => $perf['best_end']['total_risk'] ?? null,
        'worst_end_id' => $perf['worst_end']['end_id'] ?? '-',
        'worst_total_risk' => $perf['worst_end']['total_risk'] ?? null,
    ];
}

if (($options['format'] ?? '') === 'json') {
    echo json_encode($results, JSON_PRETTY_PRINT);
    exit(0);
}

$alerts = count(array_filter($results, static fn (array $r): bool => ($r['tier'] ?? 'normal') !== 'normal'));
echo "FLEET DA PERFORMANCE AUDIT\n";
echo str_repeat('=', 72) . "\n";
echo sprintf("  Alerting airports: %d / %d\n\n", $alerts, count($results));

$focus = ['or81', 'kpfc', '69v', '7s9', '05s', '12id', '1xs0', 'khio', 's81', 'hio'];
echo "FOCUS AIRPORTS (tier from best_end only)\n";
printf(
    "%-12s %6s | %-7s %-8s %-12s %-8s %-8s %-8s\n",
    'id',
    'DA',
    'tier',
    'basis',
    'best_end',
    'best_risk',
    'worst_end',
    'worst_risk'
);
foreach ($results as $r) {
    if (!in_array($r['id'], $focus, true)) {
        continue;
    }
    printf(
        "%-12s %6s | %-7s %-8s %-12s %-8s %-8s %-8s\n",
        $r['id'],
        (string) ($r['da'] ?? '-'),
        $r['tier'],
        $r['selection_basis'],
        $r['best_end_id'],
        $r['best_total_risk'] ?? '-',
        $r['worst_end_id'],
        $r['worst_total_risk'] ?? '-'
    );
}
