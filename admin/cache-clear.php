<?php
/**
 * Clear Configuration Cache
 * Clears APCu cache for airports.json configuration
 */

require_once __DIR__ . '/../lib/config.php';
require_once __DIR__ . '/../lib/webcam-metadata.php';

header('Content-Type: application/json');

// Clear the config cache
clearConfigCache();

// Also clear webcam metadata cache (includes cam names from config)
clearWebcamMetadataCache();

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

