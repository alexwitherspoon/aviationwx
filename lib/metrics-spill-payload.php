<?php
/**
 * Validate and normalize spill JSON payloads before merging into hourly aggregates.
 */

declare(strict_types=1);

require_once __DIR__ . '/constants.php';

/**
 * Validate decoded spill JSON and return normalized counter keys for hourly merge.
 *
 * Used by the spill aggregator after reading a shard file; rejects schema mismatch or hour mismatch
 * so corrupt or cross-hour shards are never applied.
 *
 * @param array<string, mixed> $data Decoded spill JSON object
 * @param string $expectedHourId UTC hour bucket id (must match directory and payload hour_id)
 * @return array{counters: array<string, int>}|null Null when payload cannot be merged safely
 */
function metrics_parse_spill_payload_for_merge(array $data, string $expectedHourId): ?array
{
    $schema = $data['schema_version'] ?? null;
    if ($schema !== METRICS_SPILL_FILE_SCHEMA_VERSION) {
        return null;
    }

    $hourId = $data['hour_id'] ?? null;
    if (!is_string($hourId) || $hourId !== $expectedHourId) {
        return null;
    }

    $counters = $data['counters'] ?? null;
    if (!is_array($counters)) {
        return null;
    }

    $flat = [];
    foreach ($counters as $k => $v) {
        if (!is_string($k)) {
            continue;
        }
        if (!is_int($v) && !is_float($v)) {
            continue;
        }
        $flat[$k] = (int) $v;
    }

    return ['counters' => $flat];
}
