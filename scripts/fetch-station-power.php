<?php
/**
 * Station power fetcher (scheduler worker). Writes canonical cache per airport.
 *
 * Usage: php fetch-station-power.php --worker <airport_id>
 */

declare(strict_types=1);

require_once __DIR__ . '/../lib/config.php';
require_once __DIR__ . '/../lib/logger.php';
require_once __DIR__ . '/../lib/cache-paths.php';
require_once __DIR__ . '/../lib/station-power/StationPowerRegistry.php';
require_once __DIR__ . '/../lib/station-power/station-power-cache.php';

$isWorkerMode = false;
$workerAirportId = null;

if (PHP_SAPI === 'cli' && isset($argv) && is_array($argv)) {
    if (isset($argv[1]) && $argv[1] === '--worker' && isset($argv[2])) {
        $isWorkerMode = true;
        $workerAirportId = $argv[2];
    }
}

/**
 * Fetch station power for one airport and write cache on success.
 *
 * @return bool True on success or intentional skip
 */
function processAirportStationPower(string $airportId, string $invocationId, bool $expectFailures = false): bool
{
    $config = loadConfig();
    if ($config === null || !isset($config['airports'][$airportId])) {
        aviationwx_log('error', 'station_power fetch: airport not found', [
            'invocation_id' => $invocationId,
            'airport' => $airportId,
        ], 'app');
        return false;
    }

    $airport = $config['airports'][$airportId];
    if (!is_array($airport)) {
        return false;
    }

    if (!isAirportEnabled($airport) || isAirportInMaintenance($airport)) {
        return true;
    }

    if (!isAirportLimitedAvailability($airport) || !isAirportStationPowerConfigured($airport)) {
        return true;
    }

    $stationPower = $airport['station_power'];
    if (!is_array($stationPower)) {
        return true;
    }

    $canonical = StationPowerRegistry::fetchCanonical($stationPower);
    if ($canonical === null) {
        $level = $expectFailures ? 'info' : 'warning';
        aviationwx_log($level, 'station_power fetch: upstream failed; retaining prior cache if any', [
            'invocation_id' => $invocationId,
            'airport' => $airportId,
        ], 'app');
        return false;
    }

    if (!saveStationPowerCache($airportId, $canonical)) {
        $level = $expectFailures ? 'info' : 'error';
        aviationwx_log($level, 'station_power fetch: failed to write cache', [
            'invocation_id' => $invocationId,
            'airport' => $airportId,
        ], 'app');
        return false;
    }

    aviationwx_log('info', 'station_power fetch: success', [
        'invocation_id' => $invocationId,
        'airport' => $airportId,
    ], 'app');

    return true;
}

if ($isWorkerMode) {
    if ($workerAirportId === null || $workerAirportId === '' || !validateAirportId($workerAirportId)) {
        aviationwx_log('error', 'station_power fetch: invalid airport ID', [
            'airport' => $workerAirportId,
        ], 'app');
        exit(1);
    }

    $config = loadConfig(false);
    $expectFailures = false;
    if ($config && isset($config['airports'][$workerAirportId]) && is_array($config['airports'][$workerAirportId])) {
        $expectFailures = isAirportUnlisted($config['airports'][$workerAirportId]);
    }

    require_once __DIR__ . '/../lib/worker-timeout.php';
    initWorkerTimeout(null, "station_power_{$workerAirportId}");

    $invocationId = aviationwx_get_invocation_id();
    $success = processAirportStationPower($workerAirportId, $invocationId, $expectFailures);
    exit($success ? 0 : ($expectFailures ? 2 : 1));
}

aviationwx_log('info', 'station_power fetch: invoke with --worker <airport_id>', [], 'app');
exit(0);
