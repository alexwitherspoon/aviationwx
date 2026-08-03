<?php
/**
 * Bridge host status evaluation for the status page (G/Y/R via operational/degraded/down).
 */

require_once __DIR__ . '/store.php';
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/../config.php';

/**
 * Evaluate one bridge host line from latest health cache + stale tiers + NTP rules.
 *
 * @param string $airportId Airport id
 * @param array $bridge Bridge config row (id, label, and related fields)
 * @param array|null $airport Airport config (for stale thresholds)
 * @return array{name: string, status: string, message: string, lastChanged: int, bridge_id: string, inventory?: array}
 */
function evaluateBridgeHostHealth(string $airportId, array $bridge, ?array $airport = null): array
{
    $bridgeId = (string) ($bridge['id'] ?? '');
    $name = 'Bridge host';
    if (isset($bridge['label']) && is_string($bridge['label']) && $bridge['label'] !== '') {
        $name = 'Bridge host (' . $bridge['label'] . ')';
    } elseif ($bridgeId !== '') {
        $name = 'Bridge host (' . $bridgeId . ')';
    }

    $warningSeconds = getStaleWarningSeconds($airport);
    $errorSeconds = getStaleErrorSeconds($airport);
    $failclosedSeconds = getStaleFailclosedSeconds($airport);

    $health = $bridgeId !== '' ? bridgeLoadHealth($airportId, $bridgeId) : null;
    if ($health === null) {
        return [
            'name' => $name,
            'bridge_id' => $bridgeId,
            'status' => 'down',
            'message' => 'No heartbeat received',
            'lastChanged' => 0,
            'inventory' => ['cameras' => [], 'stations' => []],
        ];
    }

    $receivedAt = isset($health['received_at']) ? strtotime((string) $health['received_at']) : false;
    if ($receivedAt === false) {
        $receivedAt = 0;
    }
    $age = $receivedAt > 0 ? max(0, time() - $receivedAt) : PHP_INT_MAX;

    $hostStatus = $health['host']['status'] ?? 'down';
    if (!in_array($hostStatus, ['operational', 'degraded', 'down', 'maintenance'], true)) {
        $hostStatus = 'down';
    }

    $ntpOk = $health['host']['ntp_ok'] ?? null;
    $ntpFailureSeconds = isset($health['host']['ntp_failure_seconds'])
        ? (int) $health['host']['ntp_failure_seconds']
        : null;

    // Brief NTP failure: degraded; long-lived (~error tier): down
    if ($ntpOk === false) {
        if ($ntpFailureSeconds !== null && $ntpFailureSeconds >= $errorSeconds) {
            $hostStatus = 'down';
        } elseif ($hostStatus === 'operational') {
            $hostStatus = 'degraded';
        }
    }

    $status = $hostStatus;
    $messageParts = [];

    if ($age >= $failclosedSeconds) {
        $status = 'down';
        $messageParts[] = 'Heartbeat fail-closed (' . $age . 's old)';
    } elseif ($age >= $errorSeconds) {
        if ($status !== 'down') {
            $status = 'degraded';
        }
        $messageParts[] = 'Heartbeat stale (' . $age . 's old)';
    } elseif ($age >= $warningSeconds) {
        if ($status === 'operational') {
            $status = 'degraded';
        }
        $messageParts[] = 'Heartbeat aging (' . $age . 's old)';
    } else {
        $messageParts[] = 'Heartbeat ' . $age . 's ago';
    }

    if ($ntpOk === false) {
        $messageParts[] = 'NTP not ok'
            . ($ntpFailureSeconds !== null ? ' (' . $ntpFailureSeconds . 's)' : '');
    }

    $inventory = $health['inventory'] ?? ['cameras' => [], 'stations' => []];
    $stationCount = isset($inventory['stations']) && is_array($inventory['stations'])
        ? count($inventory['stations'])
        : 0;
    $cameraCount = isset($inventory['cameras']) && is_array($inventory['cameras'])
        ? count($inventory['cameras'])
        : 0;
    $messageParts[] = $stationCount . ' station(s), ' . $cameraCount . ' camera(s) advertised';

    if (is_array($airport)) {
        $enabledNotes = [];
        foreach (($inventory['stations'] ?? []) as $station) {
            if (!is_array($station) || !isset($station['id'])) {
                continue;
            }
            $enabled = isBridgeWeatherSourceEnabled($airport, $bridgeId, (string) $station['id']);
            $enabledNotes[] = $station['id'] . ($enabled ? '=enabled' : '=not-enabled');
        }
        if ($enabledNotes !== []) {
            $messageParts[] = 'Weather: ' . implode(', ', $enabledNotes);
        }
    }

    return [
        'name' => $name,
        'bridge_id' => $bridgeId,
        'status' => $status,
        'message' => implode('; ', $messageParts),
        'lastChanged' => $receivedAt,
        'inventory' => $inventory,
        'host' => $health['host'] ?? null,
        'subsystems' => $health['subsystems'] ?? [],
        'errors' => $health['errors'] ?? [],
    ];
}

/**
 * Severity rank for bridge host status (higher = worse for component rollup).
 *
 * Unknown statuses rank as down so a bad payload cannot look healthier than peers.
 *
 * @param string $status operational|degraded|down|maintenance|other
 * @return int
 */
function bridgeHostStatusSeverityRank(string $status): int
{
    return match ($status) {
        'down' => 3,
        'degraded' => 2,
        'maintenance' => 1,
        'operational' => 0,
        default => 3,
    };
}

/**
 * Pick the worse host status for Bridge Hosts rollup
 * (down > degraded > maintenance > operational).
 *
 * @param string $current Current aggregate
 * @param string $candidate Candidate host status
 * @return string
 */
function bridgeHostStatusWorse(string $current, string $candidate): string
{
    return bridgeHostStatusSeverityRank($candidate) > bridgeHostStatusSeverityRank($current)
        ? $candidate
        : $current;
}

/**
 * Build Bridge Hosts component for checkAirportHealth().
 *
 * @param string $airportId Airport id
 * @param array $airport Airport config
 * @return array|null Component array or null when no bridges configured
 */
function buildAirportBridgeHostsComponent(string $airportId, array $airport): ?array
{
    if (!isset($airport['bridges']) || !is_array($airport['bridges']) || $airport['bridges'] === []) {
        return null;
    }

    $hosts = [];
    $worst = 'operational';
    $lastChanged = 0;
    foreach ($airport['bridges'] as $bridge) {
        if (!is_array($bridge) || !isset($bridge['id'])) {
            continue;
        }
        $row = evaluateBridgeHostHealth($airportId, $bridge, $airport);
        $hosts[] = $row;
        if (($row['lastChanged'] ?? 0) > $lastChanged) {
            $lastChanged = (int) $row['lastChanged'];
        }
        $worst = bridgeHostStatusWorse($worst, (string) ($row['status'] ?? 'down'));
    }

    if ($hosts === []) {
        return null;
    }

    return [
        'name' => 'Bridge Hosts',
        'status' => $worst,
        'message' => count($hosts) . ' bridge(s)',
        'lastChanged' => $lastChanged,
        'hosts' => $hosts,
    ];
}
