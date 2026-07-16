#!/usr/bin/env php
<?php
/**
 * Configuration check: environment summary and NASR cross-check warnings.
 *
 * Usage:
 *   php scripts/config-check.php
 *   CONFIG_PATH=/path/to/airports.json php scripts/config-check.php
 *
 * Exit codes:
 *   0 - Success (warnings do not fail)
 *   2 - Config load error
 */

declare(strict_types=1);

error_reporting(E_ALL & ~E_DEPRECATED);

require_once __DIR__ . '/../lib/config.php';
require_once __DIR__ . '/../lib/nasr/config-cross-check.php';

echo "Configuration Check\n";
echo "===================\n";

$configPath = getConfigFilePath();
echo 'Config file: ' . ($configPath ?: 'NOT FOUND') . PHP_EOL;
echo 'Test mode: ' . (isTestMode() ? 'YES' : 'NO') . PHP_EOL;
echo 'Mock mode: ' . (shouldMockExternalServices() ? 'YES (external services will be mocked)' : 'NO (real API calls)') . PHP_EOL;
echo 'Production: ' . (isProduction() ? 'YES' : 'NO') . PHP_EOL;

$config = loadConfig();
if ($config === null) {
    echo 'ERROR: Could not load config' . PHP_EOL;
    exit(2);
}

$airports = array_keys($config['airports'] ?? []);
$preview = implode(', ', array_slice($airports, 0, 5));
if (count($airports) > 5) {
    $preview .= '...';
}
echo 'Airports: ' . count($airports) . ' (' . $preview . ')' . PHP_EOL;

echo PHP_EOL . 'NASR cross-check (elevation_ft, magnetic_declination)' . PHP_EOL;
echo str_repeat('-', 55) . PHP_EOL;

if (loadNasrAptCache() === null) {
    echo 'NASR cache not present; skipping elevation/magnetic cross-check (run fetch-nasr-apt.php locally or wait for scheduler)' . PHP_EOL;
    exit(0);
}

$result = nasrCrossCheckAirportConfig($config);
foreach ($result['warnings'] as $warning) {
    echo '⚠️  ' . $warning . PHP_EOL;
}

$checked = $result['summary']['checked'];
$warningCount = count($result['warnings']);
echo PHP_EOL . "Checked {$checked} airports with NASR rows; {$warningCount} warning" . ($warningCount === 1 ? '' : 's') . PHP_EOL;

exit(0);
