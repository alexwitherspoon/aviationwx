<?php
/**
 * Normalize and validate bridge health POST bodies for fleet ops cache.
 *
 * When optional sections (subsystems, inventory, errors) are present, every
 * entry must be well-formed - malformed rows are rejected rather than dropped.
 */

require_once __DIR__ . '/store.php';
require_once __DIR__ . '/config.php';

if (!defined('BRIDGE_HEALTH_ERRORS_MAX')) {
    define('BRIDGE_HEALTH_ERRORS_MAX', 50);
}

if (!defined('BRIDGE_HEALTH_INVENTORY_MAX')) {
    define('BRIDGE_HEALTH_INVENTORY_MAX', 100);
}

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
        return [
            'ok' => false,
            'code' => 'INVALID_REQUEST',
            'error' => 'host.status must be operational|degraded|down|maintenance',
        ];
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
    if (array_key_exists('subsystems', $body)) {
        if (!is_array($body['subsystems'])) {
            return ['ok' => false, 'code' => 'INVALID_REQUEST', 'error' => 'subsystems must be an object'];
        }
        foreach ($body['subsystems'] as $name => $sub) {
            if (!is_string($name) || $name === '' || !is_array($sub)) {
                return [
                    'ok' => false,
                    'code' => 'INVALID_REQUEST',
                    'error' => 'subsystems entries must be named objects',
                ];
            }
            $subStatus = $sub['status'] ?? null;
            if (!is_string($subStatus) || !in_array($subStatus, bridgeAllowedStatusValues(), true)) {
                return [
                    'ok' => false,
                    'code' => 'INVALID_REQUEST',
                    'error' => "subsystems.{$name}.status must be operational|degraded|down|maintenance",
                ];
            }
            $entry = ['status' => $subStatus];
            if (array_key_exists('detail', $sub)) {
                if (!is_array($sub['detail'])) {
                    return [
                        'ok' => false,
                        'code' => 'INVALID_REQUEST',
                        'error' => "subsystems.{$name}.detail must be an object when set",
                    ];
                }
                $entry['detail'] = bridgeScrubValue($sub['detail']);
            }
            $subsystems[$name] = $entry;
        }
    }

    $inventory = ['cameras' => [], 'stations' => []];
    if (array_key_exists('inventory', $body)) {
        if (!is_array($body['inventory'])) {
            return ['ok' => false, 'code' => 'INVALID_REQUEST', 'error' => 'inventory must be an object'];
        }
        foreach (['cameras', 'stations'] as $kind) {
            if (!array_key_exists($kind, $body['inventory'])) {
                continue;
            }
            if (!is_array($body['inventory'][$kind])) {
                return [
                    'ok' => false,
                    'code' => 'INVALID_REQUEST',
                    'error' => "inventory.{$kind} must be an array",
                ];
            }
            if (count($body['inventory'][$kind]) > BRIDGE_HEALTH_INVENTORY_MAX) {
                return [
                    'ok' => false,
                    'code' => 'INVALID_REQUEST',
                    'error' => "inventory.{$kind} exceeds max of " . BRIDGE_HEALTH_INVENTORY_MAX,
                ];
            }
            foreach ($body['inventory'][$kind] as $idx => $item) {
                if (!is_array($item)) {
                    return [
                        'ok' => false,
                        'code' => 'INVALID_REQUEST',
                        'error' => "inventory.{$kind}[{$idx}] must be an object",
                    ];
                }
                if (!isset($item['id']) || !is_string($item['id']) || $item['id'] === '') {
                    return [
                        'ok' => false,
                        'code' => 'INVALID_REQUEST',
                        'error' => "inventory.{$kind}[{$idx}].id is required",
                    ];
                }
                if (!isValidBridgeResourceId($item['id'])) {
                    return [
                        'ok' => false,
                        'code' => 'INVALID_REQUEST',
                        'error' => "inventory.{$kind}[{$idx}].id is invalid",
                    ];
                }
                $row = [
                    'id' => $item['id'],
                    'name' => isset($item['name']) && is_string($item['name']) && $item['name'] !== ''
                        ? $item['name']
                        : $item['id'],
                    'enabled_on_bridge' => array_key_exists('enabled_on_bridge', $item)
                        ? (bool) $item['enabled_on_bridge']
                        : null,
                ];
                if ($kind === 'stations' && array_key_exists('type', $item)) {
                    if (!is_string($item['type']) || $item['type'] === '') {
                        return [
                            'ok' => false,
                            'code' => 'INVALID_REQUEST',
                            'error' => "inventory.stations[{$idx}].type must be a non-empty string when set",
                        ];
                    }
                    $row['type'] = $item['type'];
                }
                $inventory[$kind][] = $row;
            }
        }
    }

    $errors = [];
    if (array_key_exists('errors', $body)) {
        if (!is_array($body['errors'])) {
            return ['ok' => false, 'code' => 'INVALID_REQUEST', 'error' => 'errors must be an array'];
        }
        if (count($body['errors']) > BRIDGE_HEALTH_ERRORS_MAX) {
            return [
                'ok' => false,
                'code' => 'INVALID_REQUEST',
                'error' => 'errors exceeds max of ' . BRIDGE_HEALTH_ERRORS_MAX,
            ];
        }
        foreach ($body['errors'] as $idx => $err) {
            if (!is_array($err)) {
                return [
                    'ok' => false,
                    'code' => 'INVALID_REQUEST',
                    'error' => "errors[{$idx}] must be an object",
                ];
            }
            if (!isset($err['fingerprint']) || !is_string($err['fingerprint']) || $err['fingerprint'] === '') {
                return [
                    'ok' => false,
                    'code' => 'INVALID_REQUEST',
                    'error' => "errors[{$idx}].fingerprint is required",
                ];
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
