#!/usr/bin/env php
<?php
/**
 * Print a weather_sources JSON snippet to enable a bridge station (Option B).
 *
 * Does not modify airports.json. Validates bridge_source_id against last inventory
 * when a health heartbeat exists.
 *
 * Usage:
 *   php scripts/bridge-enable-weather.php \
 *     --airport kspb \
 *     --bridge bridge-spb-1 \
 *     --source station-scappoose-davis \
 *     [--station-id wx-spb-bridge-davis]
 *
 * Exit codes:
 *   0 - Success
 *   1 - Usage / validation error
 *   2 - Config load error
 */

declare(strict_types=1);

require_once __DIR__ . '/../lib/config.php';
require_once __DIR__ . '/../lib/bridge/store.php';
require_once __DIR__ . '/../lib/bridge/config.php';

$airportId = null;
$bridgeId = null;
$sourceId = null;
$stationId = null;

for ($i = 1; $i < $argc; $i++) {
    $arg = $argv[$i];
    if (($arg === '--airport' || $arg === '-a') && isset($argv[$i + 1])) {
        $airportId = strtolower((string) $argv[++$i]);
        continue;
    }
    if (($arg === '--bridge' || $arg === '-b') && isset($argv[$i + 1])) {
        $bridgeId = (string) $argv[++$i];
        continue;
    }
    if (($arg === '--source' || $arg === '-s') && isset($argv[$i + 1])) {
        $sourceId = (string) $argv[++$i];
        continue;
    }
    if (($arg === '--station-id') && isset($argv[$i + 1])) {
        $stationId = (string) $argv[++$i];
        continue;
    }
    if ($arg === '--help' || $arg === '-h') {
        echo "Usage: php scripts/bridge-enable-weather.php --airport ID --bridge BRIDGE_ID --source SOURCE_ID [--station-id STATION_ID]\n";
        echo "Prints a weather_sources entry (type aviationwx_bridge). Paste into airports.json.\n";
        exit(0);
    }
    fwrite(STDERR, "Unknown argument: {$arg}\n");
    exit(1);
}

if ($airportId === null || $bridgeId === null || $sourceId === null) {
    fwrite(STDERR, "ERROR: --airport, --bridge, and --source are required\n");
    exit(1);
}

if (!isValidBridgeResourceId($bridgeId) || !isValidBridgeResourceId($sourceId)) {
    fwrite(STDERR, "ERROR: invalid bridge or source id format\n");
    exit(1);
}

$config = loadConfig();
if ($config === null) {
    fwrite(STDERR, "ERROR: Could not load config\n");
    exit(2);
}

$airport = $config['airports'][$airportId] ?? null;
if (!is_array($airport)) {
    fwrite(STDERR, "ERROR: Airport '{$airportId}' not found\n");
    exit(1);
}

$bridge = findAirportBridgeById($airport, $bridgeId);
if ($bridge === null) {
    fwrite(STDERR, "ERROR: bridges[].id '{$bridgeId}' not found on airport '{$airportId}'\n");
    exit(1);
}

if (isBridgeWeatherSourceEnabled($airport, $bridgeId, $sourceId)) {
    fwrite(STDERR, "NOTE: source is already enabled via weather_sources\n");
}

$health = bridgeLoadHealth($airportId, $bridgeId);
if ($health !== null) {
    $found = false;
    foreach ($health['inventory']['stations'] ?? [] as $station) {
        if (is_array($station) && ($station['id'] ?? null) === $sourceId) {
            $found = true;
            break;
        }
    }
    if (!$found) {
        fwrite(STDERR, "WARNING: source_id '{$sourceId}' was not in the last heartbeat inventory\n");
    }
} else {
    fwrite(STDERR, "WARNING: no health heartbeat cached yet; cannot verify inventory\n");
}

$row = [
    'type' => 'aviationwx_bridge',
    'bridge_id' => $bridgeId,
    'bridge_source_id' => $sourceId,
];
if ($stationId !== null && $stationId !== '') {
    $row['station_id'] = $stationId;
}

echo json_encode($row, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";
echo "\n# Paste into airports.{$airportId}.weather_sources[] then reload config.\n";
exit(0);
