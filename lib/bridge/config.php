<?php
/**
 * Bridge config helpers and schema validation (bridges[] + cache-backed weather rows).
 */

require_once __DIR__ . '/keys.php';

/**
 * Allowed top-level fields on an airport bridges[] entry.
 *
 * Publish enable is NOT on the bridge row - use a cache-backed weather_sources type.
 *
 * @return list<string>
 */
function bridgeConfigAllowedFields(): array
{
    return ['id', 'api_key', 'label'];
}

/**
 * weather_sources types that read bridge upload cache (no upstream HTTP).
 *
 * @return list<string>
 */
function bridgeCacheBackedWeatherSourceTypes(): array
{
    return ['davis_weatherlink_live'];
}

/**
 * @param string|null $type weather_sources type
 * @return bool
 */
function isBridgeCacheBackedWeatherSourceType(?string $type): bool
{
    return is_string($type) && in_array($type, bridgeCacheBackedWeatherSourceTypes(), true);
}

/**
 * Validate bridge_id / bridge_source_id style identifiers.
 *
 * @param string $id Candidate id
 * @return bool
 */
function isValidBridgeResourceId(string $id): bool
{
    if ($id === '' || strlen($id) > 64) {
        return false;
    }
    return (bool) preg_match('/^[a-zA-Z0-9]([a-zA-Z0-9_-]*[a-zA-Z0-9])?$/', $id);
}

/**
 * Collect all bridges across airports: api_key => binding metadata.
 *
 * @param array|null $config Root config (defaults to loadConfig())
 * @return array<string, array{airport_id: string, bridge_id: string, label: string|null, bridge: array}>
 */
function indexBridgeApiKeys(?array $config = null): array
{
    if ($config === null) {
        require_once __DIR__ . '/../config.php';
        $config = loadConfig();
    }
    if ($config === null || !isset($config['airports']) || !is_array($config['airports'])) {
        return [];
    }

    $index = [];
    foreach ($config['airports'] as $airportId => $airport) {
        if (!is_array($airport) || !isset($airport['bridges']) || !is_array($airport['bridges'])) {
            continue;
        }
        foreach ($airport['bridges'] as $bridge) {
            if (!is_array($bridge) || !isset($bridge['api_key'], $bridge['id'])) {
                continue;
            }
            if (!is_string($bridge['api_key']) || !is_string($bridge['id'])) {
                continue;
            }
            $index[$bridge['api_key']] = [
                'airport_id' => (string) $airportId,
                'bridge_id' => $bridge['id'],
                'label' => isset($bridge['label']) && is_string($bridge['label']) ? $bridge['label'] : null,
                'bridge' => $bridge,
            ];
        }
    }

    return $index;
}

/**
 * Find a bridge row on an airport by id.
 *
 * @param array $airport Airport config object
 * @param string $bridgeId Bridge id
 * @return array|null Bridge row or null
 */
function findAirportBridgeById(array $airport, string $bridgeId): ?array
{
    if (!isset($airport['bridges']) || !is_array($airport['bridges'])) {
        return null;
    }
    foreach ($airport['bridges'] as $bridge) {
        if (is_array($bridge) && isset($bridge['id']) && $bridge['id'] === $bridgeId) {
            return $bridge;
        }
    }
    return null;
}

/**
 * List cache-backed bridge weather_sources rows for an airport.
 *
 * @param array $airport Airport config
 * @param string|null $bridgeId When set, only rows for this bridge
 * @return list<array>
 */
function listBridgeCacheWeatherSources(array $airport, ?string $bridgeId = null): array
{
    if (!isset($airport['weather_sources']) || !is_array($airport['weather_sources'])) {
        return [];
    }
    $out = [];
    foreach ($airport['weather_sources'] as $ws) {
        if (!is_array($ws) || !isBridgeCacheBackedWeatherSourceType($ws['type'] ?? null)) {
            continue;
        }
        if ($bridgeId !== null && ($ws['bridge_id'] ?? null) !== $bridgeId) {
            continue;
        }
        $out[] = $ws;
    }
    return $out;
}

/**
 * Return true when weather_sources enables this bridge station for public weather.
 *
 * @param array $airport Airport config
 * @param string $bridgeId Bridge id
 * @param string $bridgeSourceId Bridge-local station id
 * @return bool
 */
function isBridgeWeatherSourceEnabled(array $airport, string $bridgeId, string $bridgeSourceId): bool
{
    foreach (listBridgeCacheWeatherSources($airport, $bridgeId) as $ws) {
        if (($ws['bridge_source_id'] ?? null) === $bridgeSourceId) {
            return true;
        }
    }
    return false;
}

/**
 * Validate bridges[] and cache-backed weather_sources across the config.
 *
 * @param array $config Root airports.json object
 * @return array{errors: list<string>, warnings: list<string>}
 */
function validateBridgeConfig(array $config): array
{
    $errors = [];
    $warnings = [];

    if (!isset($config['airports']) || !is_array($config['airports'])) {
        return ['errors' => $errors, 'warnings' => $warnings];
    }

    $seenKeys = [];
    $bridgeIdsByAirport = [];

    foreach ($config['airports'] as $airportCode => $airport) {
        if (!is_array($airport)) {
            continue;
        }

        $bridgeIdsByAirport[$airportCode] = [];

        if (!isset($airport['bridges'])) {
            continue;
        }
        if (!is_array($airport['bridges'])) {
            $errors[] = "Airport '{$airportCode}' bridges must be an array";
            continue;
        }

        $seenIdsOnAirport = [];
        foreach ($airport['bridges'] as $idx => $bridge) {
            $label = "bridges[{$idx}]";
            if (!is_array($bridge)) {
                $errors[] = "Airport '{$airportCode}' {$label} must be an object";
                continue;
            }

            foreach (array_keys($bridge) as $field) {
                if (!in_array($field, bridgeConfigAllowedFields(), true)) {
                    $errors[] = "Airport '{$airportCode}' {$label} has unknown field '{$field}'. "
                        . 'Allowed fields: ' . implode(', ', bridgeConfigAllowedFields())
                        . '. Enable weather publish via weather_sources (e.g. davis_weatherlink_live).';
                }
            }

            if (!isset($bridge['id']) || !is_string($bridge['id']) || $bridge['id'] === '') {
                $errors[] = "Airport '{$airportCode}' {$label} missing required string 'id'";
            } elseif (!isValidBridgeResourceId($bridge['id'])) {
                $errors[] = "Airport '{$airportCode}' {$label} has invalid 'id' "
                    . '(alphanumeric, hyphens/underscores, 1-64 chars)';
            } else {
                if (isset($seenIdsOnAirport[$bridge['id']])) {
                    $errors[] = "Airport '{$airportCode}' duplicate bridges id '{$bridge['id']}'";
                }
                $seenIdsOnAirport[$bridge['id']] = true;
                $bridgeIdsByAirport[$airportCode][$bridge['id']] = true;
            }

            if (!isset($bridge['api_key']) || !is_string($bridge['api_key']) || $bridge['api_key'] === '') {
                $errors[] = "Airport '{$airportCode}' {$label} missing required string 'api_key'";
            } elseif (!isValidBridgeApiKeyShape($bridge['api_key'])) {
                $errors[] = "Airport '{$airportCode}' {$label} api_key must match shape "
                    . BRIDGE_API_KEY_PREFIX . '+ ' . BRIDGE_API_KEY_SECRET_LENGTH
                    . ' alphanumeric chars (use scripts/generate-bridge-api-key.php)';
            } else {
                if (isset($seenKeys[$bridge['api_key']])) {
                    $prev = $seenKeys[$bridge['api_key']];
                    $errors[] = "Airport '{$airportCode}' {$label} api_key is duplicated "
                        . "(also used by airport '{$prev['airport']}' bridge '{$prev['bridge']}')";
                } else {
                    $seenKeys[$bridge['api_key']] = [
                        'airport' => (string) $airportCode,
                        'bridge' => (string) ($bridge['id'] ?? $idx),
                    ];
                }
            }

            if (isset($bridge['label']) && !is_string($bridge['label'])) {
                $errors[] = "Airport '{$airportCode}' {$label} label must be a string";
            }
        }
    }

    // Cache-backed weather_sources must reference a local bridges[].id
    foreach ($config['airports'] as $airportCode => $airport) {
        if (!is_array($airport) || !isset($airport['weather_sources']) || !is_array($airport['weather_sources'])) {
            continue;
        }
        $knownBridges = $bridgeIdsByAirport[$airportCode] ?? [];
        foreach ($airport['weather_sources'] as $idx => $ws) {
            if (!is_array($ws) || !isBridgeCacheBackedWeatherSourceType($ws['type'] ?? null)) {
                continue;
            }
            $type = (string) $ws['type'];
            $label = "weather_sources[{$idx}]";
            if (!isset($ws['bridge_id']) || !is_string($ws['bridge_id']) || $ws['bridge_id'] === '') {
                $errors[] = "Airport '{$airportCode}' {$label} ({$type}) missing 'bridge_id'";
            } elseif (!isset($knownBridges[$ws['bridge_id']])) {
                $errors[] = "Airport '{$airportCode}' {$label} ({$type}) bridge_id "
                    . "'{$ws['bridge_id']}' does not match any bridges[].id on this airport";
            }
            if (!isset($ws['bridge_source_id']) || !is_string($ws['bridge_source_id']) || $ws['bridge_source_id'] === '') {
                $errors[] = "Airport '{$airportCode}' {$label} ({$type}) missing 'bridge_source_id'";
            } elseif (!isValidBridgeResourceId($ws['bridge_source_id'])) {
                $errors[] = "Airport '{$airportCode}' {$label} ({$type}) invalid 'bridge_source_id'";
            }
            if (isset($ws['station_id']) && (!is_string($ws['station_id']) || $ws['station_id'] === '')) {
                $errors[] = "Airport '{$airportCode}' {$label} ({$type}) station_id must be a non-empty string when set";
            }
            if (isset($ws['txid']) && !is_numeric($ws['txid'])) {
                $errors[] = "Airport '{$airportCode}' {$label} ({$type}) txid must be numeric when set";
            }
            if (array_key_exists('wind_reference', $ws)) {
                $errors[] = "Airport '{$airportCode}' {$label} ({$type}) must not set wind_reference; "
                    . 'install the vane to true north (core does not convert magnetic wind)';
            }
        }
    }

    return ['errors' => $errors, 'warnings' => $warnings];
}
