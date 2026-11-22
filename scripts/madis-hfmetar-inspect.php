<?php
/**
 * Inspect a MADIS HFMETAR netCDF file
 *
 * Development/diagnostic tool for the experiment branch. Reads a classic
 * netCDF HFMETAR file (gzipped or raw) and prints either a station's
 * observations or a structural summary of the file.
 *
 * Usage:
 *   php scripts/madis-hfmetar-inspect.php <file.gz|file.nc> <stationId>
 *   php scripts/madis-hfmetar-inspect.php <file> --header
 */

require_once __DIR__ . '/../lib/madis-hfmetar/netcdf-classic.php';
require_once __DIR__ . '/../lib/madis-hfmetar/madis-hfmetar.php';

use AviationWX\Madis\NetcdfClassic;

function inspectUsage(): void
{
    fwrite(STDERR, "usage: php scripts/madis-hfmetar-inspect.php <file> <stationId>|--header\n");
    exit(1);
}

$file = $argv[1] ?? null;
$station = $argv[2] ?? null;
if ($file === null || $station === null) {
    inspectUsage();
}

$bytes = @file_get_contents($file);
if ($bytes === false) {
    fwrite(STDERR, "cannot read {$file}\n");
    exit(1);
}
if (substr($bytes, 0, 2) === "\x1f\x8b") {
    $bytes = gzdecode($bytes);
    if ($bytes === false) {
        fwrite(STDERR, "gzip decompression failed for {$file}\n");
        exit(1);
    }
}

try {
    $netcdf = new NetcdfClassic($bytes);
} catch (AviationWX\Madis\NetcdfFormatException $e) {
    fwrite(STDERR, 'netCDF parse error: ' . $e->getMessage() . "\n");
    exit(1);
}

if ($station === '--header') {
    printf("records: %d\n", $netcdf->recordCount());
    printf("dims: %s\n", implode(', ', array_keys($netcdf->dims())));
    printf("variables: %s\n", implode(', ', array_keys($netcdf->variables())));
    exit(0);
}

$decoded = madisHfmetarDecodeBytes($bytes, strtoupper($station));
if ($decoded === null) {
    fwrite(STDERR, "no observations found for {$station}\n");
    exit(1);
}

printf("station %s: %d observations\n", strtoupper($station), count($decoded));
printf(
    "%-8s %-8s %7s %6s %7s %7s %10s %s\n",
    'time',
    'type',
    'tempC',
    'dir',
    'spdKts',
    'gustKts',
    'altInHg',
    'raw'
);
foreach ($decoded as $obs) {
    printf(
        "%-8s %-8s %7.1f %6d %7.1f %7.1f %10.2f %s\n",
        gmdate('H:i', $obs['obs_time']),
        $obs['metar_type'] === MADIS_HFMETAR_TYPE_FEDERAL_ASOS ? 'ASOS' : 'other',
        $obs['temperature_c'] ?? NAN,
        $obs['wind_direction_deg'] ?? -1,
        $obs['wind_speed_kts'] ?? NAN,
        $obs['gust_speed_kts'] ?? NAN,
        $obs['altimeter_inhg'] ?? NAN,
        (string)substr((string)$obs['raw'], 0, 60)
    );
}
