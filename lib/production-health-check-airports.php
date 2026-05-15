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
 * Prefers listed airports with both weather and webcams configured: all available both-capable ids
 * are taken first (in random order), then remaining slots use other weather-capable ids (weather-only
 * or both). Rows without has_weather are never sampled because /weather probes require weather.
 * Pads with repeats from already picked weather-capable ids when the pool is smaller than $count.
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
    $weatherCapablePool = array_values(array_unique(array_merge($both, $weatherOnly)));

    $picked = [];
    if ($both !== []) {
        $shuffledBoth = $both;
        shuffle($shuffledBoth);
        $picked = array_slice($shuffledBoth, 0, min($count, count($shuffledBoth)));
    }

    $need = $count - count($picked);
    if ($need > 0 && $weatherOnly !== []) {
        $fill = array_values(array_diff($weatherOnly, $picked));
        shuffle($fill);
        foreach (array_slice($fill, 0, $need) as $id) {
            $picked[] = $id;
        }
        $need = $count - count($picked);
    }

    if ($need > 0 && $weatherCapablePool !== []) {
        $fill = array_values(array_diff($weatherCapablePool, $picked));
        shuffle($fill);
        foreach (array_slice($fill, 0, $need) as $id) {
            $picked[] = $id;
        }
        $need = $count - count($picked);
    }

    if ($picked === []) {
        return array_fill(0, $count, $fallbackId);
    }
    while (count($picked) < $count) {
        $picked[] = $picked[random_int(0, count($picked) - 1)];
    }

    return $picked;
}
