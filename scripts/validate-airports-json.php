#!/usr/bin/env php
<?php
/**
 * Master Validation Script for airports.json
 * 
 * This script runs all validation checks on airports.json, including:
 * - ICAO code validation against official airport list
 * - Future validations can be added here
 * 
 * This script is designed to be called from CI/CD workflows in both
 * the main repo and the secrets repo, ensuring consistent validation.
 * 
 * Usage:
 *   php scripts/validate-airports-json.php [path/to/airports.json]
 * 
 * Exit codes:
 *   0 - All validations passed
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
    fwrite(STDERR, "Usage: php scripts/validate-airports-json.php [path/to/airports.json]\n");
    exit(2);
}

// Load required functions
require_once $projectRoot . '/lib/logger.php';
require_once $projectRoot . '/lib/config.php';

echo "Validating airports.json: {$configPath}\n";
echo str_repeat('=', 60) . "\n";

// Load config from the specified path
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

$allValid = true;
$errors = [];
$warnings = [];

// Validation 1: JSON Structure Validation
echo "\n1. Validating airports.json structure...\n";
$structureResult = validateAirportsJsonStructure($config);

if (!$structureResult['valid']) {
    $allValid = false;
    $errors = array_merge($errors, $structureResult['errors']);
    foreach ($structureResult['errors'] as $error) {
        echo "  ❌ {$error}\n";
    }
} else {
    echo "  ✓ Structure is valid\n";
    if (isset($structureResult['warnings']) && !empty($structureResult['warnings'])) {
        $warnings = array_merge($warnings, $structureResult['warnings']);
        foreach ($structureResult['warnings'] as $warning) {
            echo "  ⚠️  {$warning}\n";
        }
    }
}

// Validation 2: ICAO Code Validation (against real airport list)
echo "\n2. Validating ICAO codes against official airport list...\n";
$icaoResult = validateAirportsIcaoCodes($config);

if (!$icaoResult['valid']) {
    $allValid = false;
    $errors = array_merge($errors, $icaoResult['errors']);
    foreach ($icaoResult['errors'] as $error) {
        echo "  ❌ {$error}\n";
    }
} else {
    if (isset($icaoResult['warnings']) && !empty($icaoResult['warnings'])) {
        $warnings = array_merge($warnings, $icaoResult['warnings']);
        foreach ($icaoResult['warnings'] as $warning) {
            echo "  ⚠️  {$warning}\n";
        }
    }
    echo "  ✓ All ICAO codes are valid\n";
}

// Add more validations here as needed
// Example:
// echo "\n3. Validating weather sources...\n";
// $weatherResult = validateWeatherSources($config);
// ...

// Summary
echo "\n" . str_repeat('=', 60) . "\n";
if ($allValid) {
    $airportCount = isset($config['airports']) ? count($config['airports']) : 0;
    echo "✅ All validations PASSED - {$airportCount} airport(s) validated\n";
    if (!empty($warnings)) {
        echo "\n⚠️  Warnings: " . count($warnings) . " warning(s) (non-blocking)\n";
    }
    exit(0);
} else {
    echo "❌ Validation FAILED - Found " . count($errors) . " error(s)\n";
    if (!empty($warnings)) {
        echo "⚠️  Warnings: " . count($warnings) . " warning(s)\n";
    }
    echo "\nPlease fix these errors before deploying.\n";
    exit(1);
}

