<?php
/**
 * Quick script to check if push cameras are configured
 */

require_once __DIR__ . '/../lib/config.php';

$config = loadConfig(false);

if (!$config) {
    echo "ERROR: Config load failed\n";
    exit(1);
}

$found = false;
foreach ($config['airports'] ?? [] as $airportId => $airport) {
    foreach ($airport['webcams'] ?? [] as $idx => $cam) {
        $isPush = (isset($cam['type']) && $cam['type'] === 'push') || isset($cam['push_config']);
        
        if ($isPush) {
            $found = true;
            echo "✓ Found push camera: {$airportId} camera {$idx}\n";
            echo "  Protocol: " . ($cam['push_config']['protocol'] ?? 'not set') . "\n";
            echo "  Username: " . ($cam['push_config']['username'] ?? 'not set') . "\n";
            echo "  Password: " . (isset($cam['push_config']['password']) ? '[SET]' : 'not set') . "\n";
            echo "\n";
        }
    }
}

if (!$found) {
    echo "✗ No push cameras found in configuration\n";
    echo "You need to add a push camera with push_config in your airports.json\n";
    exit(1);
}

echo "✓ Push cameras found. Run sync to create users.\n";

