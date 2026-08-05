#!/usr/bin/env php
<?php
/**
 * List bridge inventory from fleet ops cache and weather enable state (Option B).
 *
 * Usage:
 *   php scripts/bridge-inventory.php --airport kspb
 *   php scripts/bridge-inventory.php --airport kspb --bridge bridge-spb-1
 *
 * Exit codes:
 *   0 - Success
 *   1 - Usage / not found
 *   2 - Config load error
 */

declare(strict_types=1);

require_once __DIR__ . '/../lib/config.php';
require_once __DIR__ . '/../lib/bridge/store.php';
require_once __DIR__ . '/../lib/bridge/config.php';
require_once __DIR__ . '/../lib/bridge/status.php';

$airportId = null;
$bridgeFilter = null;

for ($i = 1; $i < $argc; $i++) {
    $arg = $argv[$i];
    if (($arg === '--airport' || $arg === '-a') && isset($argv[$i + 1])) {
        $airportId = strtolower((string) $argv[++$i]);
        continue;
    }
    if (($arg === '--bridge' || $arg === '-b') && isset($argv[$i + 1])) {
        $bridgeFilter = (string) $argv[++$i];
        continue;
    }
    if ($arg === '--help' || $arg === '-h') {
        echo "Usage: php scripts/bridge-inventory.php --airport ID [--bridge BRIDGE_ID]\n";
        exit(0);
    }
    fwrite(STDERR, "Unknown argument: {$arg}\n");
    exit(1);
}

if ($airportId === null || $airportId === '') {
    fwrite(STDERR, "ERROR: --airport is required\n");
    exit(1);
}

$config = loadConfig();
if ($config === null) {
    fwrite(STDERR, "ERROR: Could not load config\n");
    exit(2);
}

$airport = $config['airports'][$airportId] ?? null;
if (!is_array($airport)) {
    fwrite(STDERR, "ERROR: Airport '{$airportId}' not found in config\n");
    exit(1);
}

$bridges = $airport['bridges'] ?? [];
if (!is_array($bridges) || $bridges === []) {
    echo "No bridges configured for {$airportId}\n";
    exit(0);
}

foreach ($bridges as $bridge) {
    if (!is_array($bridge) || !isset($bridge['id'])) {
        continue;
    }
    $bridgeId = (string) $bridge['id'];
    if ($bridgeFilter !== null && $bridgeFilter !== $bridgeId) {
        continue;
    }

    $eval = evaluateBridgeHostHealth($airportId, $bridge, $airport);
    echo "=== {$airportId} / {$bridgeId} ===\n";
    echo 'Label: ' . ($bridge['label'] ?? '(none)') . "\n";
    echo 'Status: ' . ($eval['status'] ?? 'unknown') . "\n";
    echo 'Message: ' . ($eval['message'] ?? '') . "\n";

    $health = bridgeLoadHealth($airportId, $bridgeId);
    $stations = $health['inventory']['stations'] ?? [];
    $cameras = $health['inventory']['cameras'] ?? [];

    echo "Stations:\n";
    if (!is_array($stations) || $stations === []) {
        echo "  (none in last heartbeat)\n";
    } else {
        foreach ($stations as $station) {
            if (!is_array($station)) {
                continue;
            }
            $sid = (string) ($station['id'] ?? '');
            $enabled = isBridgeWeatherSourceEnabled($airport, $bridgeId, $sid);
            echo sprintf(
                "  - %s (%s) type=%s enabled_on_bridge=%s weather_sources=%s\n",
                $sid,
                (string) ($station['name'] ?? ''),
                (string) ($station['type'] ?? ''),
                isset($station['enabled_on_bridge']) ? ($station['enabled_on_bridge'] ? 'true' : 'false') : 'n/a',
                $enabled ? 'ENABLED' : 'not-enabled'
            );
        }
    }

    echo "Cameras:\n";
    if (!is_array($cameras) || $cameras === []) {
        echo "  (none in last heartbeat)\n";
    } else {
        foreach ($cameras as $camera) {
            if (!is_array($camera)) {
                continue;
            }
            echo sprintf(
                "  - %s (%s) enabled_on_bridge=%s\n",
                (string) ($camera['id'] ?? ''),
                (string) ($camera['name'] ?? ''),
                isset($camera['enabled_on_bridge']) ? ($camera['enabled_on_bridge'] ? 'true' : 'false') : 'n/a'
            );
        }
    }
    echo "\n";
}

exit(0);
