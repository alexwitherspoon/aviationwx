<?php
/**
 * Helpers for {@see scripts/production-health-check.php}: pick random listed airports to sample.
 *
 * @package AviationWX
 */

declare(strict_types=1);

/**
 * Pick airport ids to sample for Public API weather and webcams probes.
 *
 * Prefers listed airports with both weather and webcams configured. Pads with repeats when the
 * pool is smaller than $count.
 *
 * @param array<string, mixed>|null $listJson Decoded JSON from a successful GET /v1/airports response
 * @param string $fallbackId Lowercase airport id when the list is missing or unusable
 * @param int $count Number of samples (each later gets weather + webcams requests)
 * @return array<int, string> Lowercase ids, length exactly max(1, $count)
 */
function production_health_check_pick_sample_airports(?array $listJson, string $fallbackId, int $count): array
{
    $fallbackId = strtolower(trim($fallbackId));
    if ($fallbackId === '') {
        $fallbackId = 'kspb';
    }
    if ($count < 1) {
        $count = 1;
    }
    $rows = null;
    if (is_array($listJson) && isset($listJson['airports']) && is_array($listJson['airports'])) {
        $rows = $listJson['airports'];
    }
    if ($rows === null || $rows === []) {
        return array_fill(0, $count, $fallbackId);
    }
    $both = [];
    $weatherOnly = [];
    foreach ($rows as $row) {
        if (!is_array($row) || empty($row['id']) || !is_string($row['id'])) {
            continue;
        }
        $id = strtolower($row['id']);
        $hw = !empty($row['has_weather']);
        $wc = !empty($row['has_webcams']);
        if ($hw && $wc) {
            $both[] = $id;
        } elseif ($hw) {
            $weatherOnly[] = $id;
        }
    }
    $both = array_values(array_unique($both));
    $weatherOnly = array_values(array_unique($weatherOnly));
    $pool = count($both) >= $count ? $both : array_merge($both, array_values(array_diff($weatherOnly, $both)));
    $pool = array_values(array_unique($pool));
    if ($pool === []) {
        $ids = [];
        foreach ($rows as $row) {
            if (is_array($row) && !empty($row['id']) && is_string($row['id'])) {
                $ids[] = strtolower($row['id']);
            }
        }
        $pool = array_values(array_unique($ids));
    }
    if ($pool === []) {
        return array_fill(0, $count, $fallbackId);
    }
    shuffle($pool);
    $picked = array_slice($pool, 0, min($count, count($pool)));
    while (count($picked) < $count) {
        $picked[] = $picked[random_int(0, count($picked) - 1)];
    }

    return $picked;
}
