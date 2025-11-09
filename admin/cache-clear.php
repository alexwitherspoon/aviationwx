<?php
/**
 * Clear Configuration Cache
 * Clears APCu cache for airports.json configuration
 */

require_once __DIR__ . '/../lib/config.php';

header('Content-Type: application/json');

// Clear the cache
clearConfigCache();

// Reload config to verify it works
$config = loadConfig(true);

if ($config !== null) {
    $response = [
        'success' => true,
        'message' => 'Configuration cache cleared successfully',
        'config_reloaded' => true,
        'airport_count' => isset($config['airports']) ? count($config['airports']) : 0
    ];
} else {
    $response = [
        'success' => false,
        'message' => 'Cache cleared but failed to reload config. Check configuration file.',
        'config_reloaded' => false
    ];
}

echo json_encode($response, JSON_PRETTY_PRINT);

