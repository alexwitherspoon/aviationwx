<?php
/**
 * Validate and normalize spill JSON payloads before merging into hourly aggregates.
 */

declare(strict_types=1);

require_once __DIR__ . '/constants.php';
require_once __DIR__ . '/metrics-apply-counters.php';

/**
 * Validate decoded spill JSON and return normalized counter keys for hourly merge.
 *
 * Used by the spill aggregator after reading a shard file; rejects schema mismatch, hour mismatch,
 * non-numeric counter values, empty counter maps, unreadable keys, or any counter name not recognized
 * by {@see metrics_flat_counter_key_is_recognized()} so corrupt or forward-incompatible shards are not merged
 * and are left on disk for inspection.
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
            return null;
        }
        if (!is_int($v) && !is_float($v)) {
            return null;
        }
        if (!metrics_flat_counter_key_is_recognized($k)) {
            return null;
        }
        $flat[$k] = (int) $v;
    }

    if ($flat === []) {
        return null;
    }

    return ['counters' => $flat];
}
