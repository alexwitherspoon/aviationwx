#!/usr/bin/env php
<?php
/**
 * Validate ICAO Airport Codes
 * 
 * Standalone script to validate ICAO codes in airports.json against the official
 * airport list from GitHub (lxndrblz/Airports).
 * 
 * This script can be used in CI/CD pipelines (including the secrets repo) to
 * catch invalid airport codes before deployment.
 * 
 * Usage:
 *   php scripts/validate-icao-codes.php [path/to/airports.json]
 * 
 * Exit codes:
 *   0 - All ICAO codes are valid
 *   1 - Validation errors found
 *   2 - Script errors (file not found, etc.)
 */

// Get config file path from command line or use default
$configPath = $argv[1] ?? 'config/airports.json';
$scriptDir = __DIR__;
$projectRoot = dirname($scriptDir);

// If relative path, make it relative to project root
if (!file_exists($configPath) && file_exists($projectRoot . '/' . $configPath)) {
    $configPath = $projectRoot . '/' . $configPath;
}

// Check if config file exists
if (!file_exists($configPath)) {
    fwrite(STDERR, "ERROR: Config file not found: {$configPath}\n");
    fwrite(STDERR, "Usage: php scripts/validate-icao-codes.php [path/to/airports.json]\n");
    exit(2);
}

// Load required functions (minimal dependencies)
require_once $projectRoot . '/lib/logger.php';
require_once $projectRoot . '/lib/config.php';

// Load config
$configContent = @file_get_contents($configPath);
if ($configContent === false) {
    fwrite(STDERR, "ERROR: Could not read config file: {$configPath}\n");
    exit(2);
}

$config = json_decode($configContent, true);
if (json_last_error() !== JSON_ERROR_NONE) {
    fwrite(STDERR, "ERROR: Invalid JSON in config file: " . json_last_error_msg() . "\n");
    exit(2);
}

if (!isset($config['airports']) || !is_array($config['airports'])) {
    fwrite(STDERR, "ERROR: No airports found in configuration\n");
    exit(2);
}

echo "Validating ICAO codes in: {$configPath}\n";
echo str_repeat('=', 60) . "\n";

// Validate ICAO codes
$result = validateAirportsIcaoCodes($config);

// Show warnings if any
if (isset($result['warnings']) && !empty($result['warnings'])) {
    foreach ($result['warnings'] as $warning) {
        echo "WARNING: {$warning}\n";
    }
    echo "\n";
}

// Show errors if any
if (!$result['valid']) {
    echo "❌ Validation FAILED - Found " . count($result['errors']) . " error(s):\n\n";
    foreach ($result['errors'] as $error) {
        echo "  • {$error}\n";
    }
    echo "\n";
    echo "Please fix these errors before deploying.\n";
    exit(1);
}

// Success
$airportCount = count($config['airports']);
echo "✅ Validation PASSED - All {$airportCount} airport ICAO code(s) are valid\n";
exit(0);

