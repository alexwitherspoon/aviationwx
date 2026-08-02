<?php
/**
 * Normalize and validate bridge health POST bodies for fleet ops cache.
 */

require_once __DIR__ . '/store.php';
require_once __DIR__ . '/config.php';

/**
 * Allowed host.status / subsystem status values.
 *
 * @return list<string>
 */
function bridgeAllowedStatusValues(): array
{
    return ['operational', 'degraded', 'down', 'maintenance'];
}

/**
 * Normalize a health POST body into a storeable document.
 *
 * @param array $body Decoded JSON body
 * @return array{ok: bool, health?: array, error?: string, code?: string}
 */
function bridgeNormalizeHealthPayload(array $body): array
{
    if (!isset($body['observed_at']) || !is_string($body['observed_at']) || $body['observed_at'] === '') {
        return ['ok' => false, 'code' => 'INVALID_REQUEST', 'error' => 'observed_at is required (ISO-8601)'];
    }
    $observedTs = strtotime($body['observed_at']);
    if ($observedTs === false) {
        return ['ok' => false, 'code' => 'INVALID_REQUEST', 'error' => 'observed_at must be a valid timestamp'];
    }

    if (!isset($body['host']) || !is_array($body['host'])) {
        return ['ok' => false, 'code' => 'INVALID_REQUEST', 'error' => 'host object is required'];
    }
    $host = $body['host'];
    $status = $host['status'] ?? null;
    if (!is_string($status) || !in_array($status, bridgeAllowedStatusValues(), true)) {
        return ['ok' => false, 'code' => 'INVALID_REQUEST', 'error' => 'host.status must be operational|degraded|down|maintenance'];
    }

    $normalizedHost = [
        'status' => $status,
        'ntp_ok' => array_key_exists('ntp_ok', $host) ? (bool) $host['ntp_ok'] : null,
        'ntp_failure_seconds' => isset($host['ntp_failure_seconds']) && is_numeric($host['ntp_failure_seconds'])
            ? max(0, (int) $host['ntp_failure_seconds'])
            : null,
    ];
    if (isset($host['build']) && is_array($host['build'])) {
        $normalizedHost['build'] = bridgeScrubValue($host['build']);
    }
    if (isset($host['resources']) && is_array($host['resources'])) {
        $normalizedHost['resources'] = bridgeScrubValue($host['resources']);
    }

    $subsystems = [];
    if (isset($body['subsystems']) && is_array($body['subsystems'])) {
        foreach ($body['subsystems'] as $name => $sub) {
            if (!is_string($name) || !is_array($sub)) {
                continue;
            }
            $subStatus = $sub['status'] ?? null;
            if (!is_string($subStatus) || !in_array($subStatus, bridgeAllowedStatusValues(), true)) {
                continue;
            }
            $entry = ['status' => $subStatus];
            if (isset($sub['detail']) && is_array($sub['detail'])) {
                $entry['detail'] = bridgeScrubValue($sub['detail']);
            }
            $subsystems[$name] = $entry;
        }
    }

    $inventory = ['cameras' => [], 'stations' => []];
    if (isset($body['inventory']) && is_array($body['inventory'])) {
        foreach (['cameras', 'stations'] as $kind) {
            if (!isset($body['inventory'][$kind]) || !is_array($body['inventory'][$kind])) {
                continue;
            }
            foreach ($body['inventory'][$kind] as $item) {
                if (!is_array($item) || !isset($item['id']) || !is_string($item['id']) || $item['id'] === '') {
                    continue;
                }
                if (!isValidBridgeResourceId($item['id'])) {
                    continue;
                }
                $row = [
                    'id' => $item['id'],
                    'name' => isset($item['name']) && is_string($item['name']) ? $item['name'] : $item['id'],
                    'enabled_on_bridge' => array_key_exists('enabled_on_bridge', $item)
                        ? (bool) $item['enabled_on_bridge']
                        : null,
                ];
                if ($kind === 'stations' && isset($item['type']) && is_string($item['type'])) {
                    $row['type'] = $item['type'];
                }
                $inventory[$kind][] = $row;
            }
        }
    }

    $errors = [];
    if (isset($body['errors']) && is_array($body['errors'])) {
        $cap = 50;
        foreach ($body['errors'] as $err) {
            if (count($errors) >= $cap) {
                break;
            }
            if (!is_array($err) || !isset($err['fingerprint']) || !is_string($err['fingerprint'])) {
                continue;
            }
            $errors[] = bridgeScrubValue([
                'fingerprint' => substr($err['fingerprint'], 0, 128),
                'count' => isset($err['count']) && is_numeric($err['count']) ? (int) $err['count'] : 1,
                'last_message' => isset($err['last_message']) && is_string($err['last_message'])
                    ? substr($err['last_message'], 0, 500)
                    : null,
                'subsystem' => isset($err['subsystem']) && is_string($err['subsystem'])
                    ? $err['subsystem']
                    : null,
            ]);
        }
    }

    $health = [
        'observed_at' => gmdate('c', $observedTs),
        'host' => $normalizedHost,
        'subsystems' => $subsystems,
        'inventory' => $inventory,
        'errors' => $errors,
    ];
    if (isset($body['bridge_id']) && is_string($body['bridge_id'])) {
        $health['body_bridge_id'] = $body['bridge_id'];
    }

    return ['ok' => true, 'health' => $health];
}
