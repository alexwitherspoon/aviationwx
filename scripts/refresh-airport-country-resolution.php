#!/usr/bin/env php
<?php
/**
 * Build airport_country_resolution.json from airports.json lat/lon and bundled Admin-0 polygons.
 *
 * Run by the scheduler (first eligible loop iteration after startup, then at most hourly evaluation).
 * Rebuild when aggregate is missing, invalid, config SHA mismatch, schema mismatch, or aggregate file
 * age exceeds COUNTRY_RESOLUTION_AGGREGATE_MAX_AGE_SECONDS (default 30 days). Writes an atomic JSON
 * aggregate under CACHE_BASE_DIR for loadConfig() to merge as `_country_resolution_geo_iso`.
 *
 * Usage:
 *   php scripts/refresh-airport-country-resolution.php
 *
 * Exit codes: 0 success, 1 failure
 */

if (php_sapi_name() !== 'cli') {
    fwrite(STDERR, "This script must be run from CLI.\n");
    exit(1);
}

chdir(__DIR__ . '/..');

define('AVIATIONWX_SKIP_COUNTRY_RESOLUTION_MERGE', true);

require_once __DIR__ . '/../lib/logger.php';
require_once __DIR__ . '/../lib/constants.php';
require_once __DIR__ . '/../lib/cache-paths.php';
require_once __DIR__ . '/../lib/country-resolution.php';
require_once __DIR__ . '/../lib/config.php';

$config = loadConfig();
if ($config === null || !isset($config['airports']) || !is_array($config['airports'])) {
    fwrite(STDERR, "refresh-airport-country-resolution: loadConfig() failed or no airports\n");
    exit(1);
}

$configPath = $GLOBALS['AVIATIONWX_CONFIG_FILE_PATH'] ?? null;
if (!is_string($configPath) || $configPath === '' || !is_readable($configPath)) {
    fwrite(STDERR, "refresh-airport-country-resolution: resolved config path unavailable\n");
    exit(1);
}
$configRaw = @file_get_contents($configPath);
if ($configRaw === false) {
    fwrite(STDERR, "refresh-airport-country-resolution: cannot read config file\n");
    exit(1);
}
$configSha = hash('sha256', $configRaw);

$geoPath = countryResolutionGetBundledAdmin0GeoJsonPath();
if (!is_readable($geoPath)) {
    fwrite(STDERR, "refresh-airport-country-resolution: boundary GeoJSON missing: {$geoPath}\n");
    exit(1);
}

$features = countryResolutionLoadAdmin0FeaturesFromGeoJson($geoPath);
if ($features === []) {
    fwrite(STDERR, "refresh-airport-country-resolution: no polygon features loaded from {$geoPath}\n");
    exit(1);
}

$airportsOut = [];
foreach ($config['airports'] as $aid => $ap) {
    if (!is_string($aid) || $aid === '') {
        continue;
    }
    $aidLower = strtolower($aid);
    if (!is_array($ap)) {
        $airportsOut[$aidLower] = ['iso_country' => null];
        continue;
    }
    $lat = isset($ap['lat']) && is_numeric($ap['lat']) ? (float) $ap['lat'] : null;
    $lon = isset($ap['lon']) && is_numeric($ap['lon']) ? (float) $ap['lon'] : null;
    if ($lat === null || $lon === null) {
        $airportsOut[$aidLower] = ['iso_country' => null];
        continue;
    }
    $iso = countryResolutionFindIsoAlpha2AtLatLon($lat, $lon, $features);
    $airportsOut[$aidLower] = ['iso_country' => $iso];
}

$payload = [
    'version' => COUNTRY_RESOLUTION_AGGREGATE_SCHEMA_VERSION,
    'generated_at' => gmdate('c'),
    'config_sha256' => $configSha,
    'boundary_dataset' => COUNTRY_RESOLUTION_BOUNDARY_DATASET_ID,
    'airports' => $airportsOut,
];

$target = CACHE_AIRPORT_COUNTRY_RESOLUTION_FILE;
$dir = dirname($target);
if (!is_dir($dir)) {
    if (!@mkdir($dir, 0755, true) && !is_dir($dir)) {
        fwrite(STDERR, "refresh-airport-country-resolution: cannot create directory {$dir}\n");
        exit(1);
    }
}

$json = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
if ($json === false) {
    fwrite(STDERR, "refresh-airport-country-resolution: json_encode failed\n");
    exit(1);
}

$tmp = $target . '.tmp.' . getmypid();
if (@file_put_contents($tmp, $json, LOCK_EX) === false) {
    fwrite(STDERR, "refresh-airport-country-resolution: cannot write temp file\n");
    exit(1);
}
if (!@rename($tmp, $target)) {
    @unlink($tmp);
    fwrite(STDERR, "refresh-airport-country-resolution: cannot rename temp to {$target}\n");
    exit(1);
}

aviationwx_log('info', 'refresh-airport-country-resolution: wrote aggregate', [
    'path' => $target,
    'airport_count' => count($airportsOut),
], 'app');

exit(0);
