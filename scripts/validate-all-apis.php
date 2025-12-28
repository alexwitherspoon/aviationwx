#!/usr/bin/env php
<?php
/**
 * API Validation Script
 * 
 * Queries all configured weather APIs and validates their responses.
 * Reports any data that appears unreasonable or out of expected bounds.
 * 
 * Usage: php scripts/validate-all-apis.php
 * 
 * Exit codes:
 *   0 = All APIs returned reasonable data
 *   1 = Some APIs returned warnings (data may be stale or edge cases)
 *   2 = Some APIs returned errors (invalid data detected)
 */

// Setup
error_reporting(E_ALL);
ini_set('display_errors', '1');

// Load dependencies
require_once __DIR__ . '/../lib/config.php';
require_once __DIR__ . '/../lib/weather/validation.php';
require_once __DIR__ . '/../lib/weather/UnifiedFetcher.php';
require_once __DIR__ . '/../lib/constants.php';

// ANSI colors for terminal output
define('COLOR_RED', "\033[31m");
define('COLOR_GREEN', "\033[32m");
define('COLOR_YELLOW', "\033[33m");
define('COLOR_BLUE', "\033[34m");
define('COLOR_RESET', "\033[0m");
define('COLOR_BOLD', "\033[1m");

/**
 * Print a colored line
 */
function printLine($color, $prefix, $message) {
    echo $color . $prefix . COLOR_RESET . " " . $message . PHP_EOL;
}

/**
 * Validate a single airport's weather data
 */
function validateAirportWeather(string $airportId, array $airport): array {
    $issues = [];
    $warnings = [];
    
    echo PHP_EOL . COLOR_BOLD . "=== {$airportId} ===" . COLOR_RESET . PHP_EOL;
    
    // Get source info
    $sourceType = $airport['weather_source']['type'] ?? 'unknown';
    $metarStation = $airport['metar_station'] ?? 'none';
    echo "  Source: {$sourceType}, METAR: {$metarStation}" . PHP_EOL;
    
    // Fetch weather
    $startTime = microtime(true);
    try {
        $weather = fetchWeatherUnified($airport, $airportId);
    } catch (Exception $e) {
        $issues[] = "Fetch failed: " . $e->getMessage();
        printLine(COLOR_RED, "  [ERROR]", "Failed to fetch: " . $e->getMessage());
        return ['issues' => $issues, 'warnings' => $warnings];
    }
    $fetchTime = round((microtime(true) - $startTime) * 1000);
    echo "  Fetch time: {$fetchTime}ms" . PHP_EOL;
    
    // Check if we got data
    if (empty($weather) || !isset($weather['temperature'])) {
        $issues[] = "No weather data returned";
        printLine(COLOR_RED, "  [ERROR]", "No weather data returned");
        return ['issues' => $issues, 'warnings' => $warnings];
    }
    
    // Check for validation issues recorded by the system
    if (!empty($weather['_validation_issues'])) {
        foreach ($weather['_validation_issues'] as $issue) {
            $issues[] = "{$issue['field']}: {$issue['value']} ({$issue['reason']})";
            printLine(COLOR_RED, "  [INVALID]", "{$issue['field']} = {$issue['value']} ({$issue['reason']})");
        }
    }
    
    // Validate key fields
    $fieldsToCheck = [
        'temperature' => ['min' => CLIMATE_TEMP_MIN_C, 'max' => CLIMATE_TEMP_MAX_C, 'unit' => '°C'],
        'pressure' => ['min' => CLIMATE_PRESSURE_MIN_INHG, 'max' => CLIMATE_PRESSURE_MAX_INHG, 'unit' => 'inHg'],
        'humidity' => ['min' => CLIMATE_HUMIDITY_MIN, 'max' => CLIMATE_HUMIDITY_MAX, 'unit' => '%'],
        'wind_speed' => ['min' => 0, 'max' => CLIMATE_WIND_SPEED_MAX_KTS, 'unit' => 'kts'],
        'pressure_altitude' => ['min' => CLIMATE_PRESSURE_ALTITUDE_MIN_FT, 'max' => CLIMATE_PRESSURE_ALTITUDE_MAX_FT, 'unit' => 'ft'],
        'density_altitude' => ['min' => CLIMATE_DENSITY_ALTITUDE_MIN_FT, 'max' => CLIMATE_DENSITY_ALTITUDE_MAX_FT, 'unit' => 'ft'],
        'visibility' => ['min' => 0, 'max' => 15, 'unit' => 'SM', 'sentinels' => [UNLIMITED_VISIBILITY_SM]],
        'ceiling' => ['min' => 0, 'max' => 60000, 'unit' => 'ft', 'sentinels' => [UNLIMITED_CEILING_FT]],
    ];
    
    foreach ($fieldsToCheck as $field => $bounds) {
        $value = $weather[$field] ?? null;
        
        if ($value === null) {
            $warnings[] = "{$field}: null";
            printLine(COLOR_YELLOW, "  [WARN]", "{$field} is null");
            continue;
        }
        
        if (!is_numeric($value)) {
            $issues[] = "{$field}: non-numeric ({$value})";
            printLine(COLOR_RED, "  [ERROR]", "{$field} is non-numeric: {$value}");
            continue;
        }
        
        $numValue = (float)$value;
        
        // Check for sentinel values (e.g., 99999 for unlimited ceiling)
        $sentinels = $bounds['sentinels'] ?? [];
        foreach ($sentinels as $sentinel) {
            if (abs($numValue - $sentinel) < 0.01) {
                printLine(COLOR_GREEN, "  [OK]", "{$field} = unlimited (sentinel)");
                continue 2; // Continue outer foreach
            }
        }
        
        if ($numValue < $bounds['min'] || $numValue > $bounds['max']) {
            $issues[] = "{$field}: {$numValue} {$bounds['unit']} (out of bounds: {$bounds['min']}-{$bounds['max']})";
            printLine(COLOR_RED, "  [INVALID]", "{$field} = {$numValue} {$bounds['unit']} (bounds: {$bounds['min']}-{$bounds['max']})");
        } else {
            printLine(COLOR_GREEN, "  [OK]", "{$field} = " . round($numValue, 2) . " {$bounds['unit']}");
        }
    }
    
    // Check observation time (staleness)
    $obsTime = $weather['last_updated'] ?? 0;
    $age = time() - $obsTime;
    $ageMinutes = round($age / 60, 1);
    
    if ($age > 3600) { // > 1 hour
        $warnings[] = "Data is {$ageMinutes} minutes old";
        printLine(COLOR_YELLOW, "  [STALE]", "Data is {$ageMinutes} minutes old");
    } else {
        printLine(COLOR_GREEN, "  [FRESH]", "Data is {$ageMinutes} minutes old");
    }
    
    // Print key values summary
    echo "  Summary: T=" . round($weather['temperature'] ?? 0, 1) . "°C, ";
    echo "P=" . round($weather['pressure'] ?? 0, 2) . "inHg, ";
    echo "PA=" . ($weather['pressure_altitude'] ?? 'null') . "ft, ";
    echo "DA=" . ($weather['density_altitude'] ?? 'null') . "ft" . PHP_EOL;
    
    return ['issues' => $issues, 'warnings' => $warnings];
}

// Main execution
echo COLOR_BOLD . "AviationWX API Validation Script" . COLOR_RESET . PHP_EOL;
echo "=================================" . PHP_EOL;

// Load config
$config = loadConfig();
if (!$config || empty($config['airports'])) {
    printLine(COLOR_RED, "[FATAL]", "Could not load configuration");
    exit(2);
}

$airports = $config['airports'];
$totalAirports = count($airports);
echo "Found {$totalAirports} airports to validate" . PHP_EOL;

// Track results
$allIssues = [];
$allWarnings = [];
$successCount = 0;
$warningCount = 0;
$errorCount = 0;

// Validate each airport
foreach ($airports as $airportId => $airport) {
    $result = validateAirportWeather($airportId, $airport);
    
    if (!empty($result['issues'])) {
        $errorCount++;
        $allIssues[$airportId] = $result['issues'];
    } elseif (!empty($result['warnings'])) {
        $warningCount++;
        $allWarnings[$airportId] = $result['warnings'];
    } else {
        $successCount++;
    }
    
    // Small delay between requests to avoid rate limiting
    usleep(500000); // 500ms
}

// Summary
echo PHP_EOL . COLOR_BOLD . "=== SUMMARY ===" . COLOR_RESET . PHP_EOL;
echo "Total airports: {$totalAirports}" . PHP_EOL;
printLine(COLOR_GREEN, "  OK:", "{$successCount} airports with valid data");
printLine(COLOR_YELLOW, "  WARNINGS:", "{$warningCount} airports with warnings");
printLine(COLOR_RED, "  ERRORS:", "{$errorCount} airports with invalid data");

// Detail errors
if (!empty($allIssues)) {
    echo PHP_EOL . COLOR_RED . "Airports with Invalid Data:" . COLOR_RESET . PHP_EOL;
    foreach ($allIssues as $airportId => $issues) {
        echo "  {$airportId}:" . PHP_EOL;
        foreach ($issues as $issue) {
            echo "    - {$issue}" . PHP_EOL;
        }
    }
}

// Detail warnings
if (!empty($allWarnings)) {
    echo PHP_EOL . COLOR_YELLOW . "Airports with Warnings:" . COLOR_RESET . PHP_EOL;
    foreach ($allWarnings as $airportId => $warnings) {
        echo "  {$airportId}:" . PHP_EOL;
        foreach ($warnings as $warning) {
            echo "    - {$warning}" . PHP_EOL;
        }
    }
}

// Exit code
if ($errorCount > 0) {
    exit(2);
} elseif ($warningCount > 0) {
    exit(1);
} else {
    exit(0);
}

