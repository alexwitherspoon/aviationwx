<?php
/**
 * Embed API Value-Diff Cache
 *
 * Stores previous embed payload in APCu for differential updates (refresh=1).
 * Topic-level diff: weather, airport. Includes explicit observed_at for staleness.
 * Same TTL for current and previous; ages out when idle.
 */

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/cache-paths.php';

/** APCu key prefix for embed diff */
define('EMBED_DIFF_PREFIX', 'aviationwx_embed_');

/** TTL seconds - match weather cache staleness (2x refresh cycle) */
define('EMBED_DIFF_TTL', 120);

/**
 * Format weather_sources for embed (public fields only: type for isMetarOnly check)
 *
 * @param array $sources Raw weather_sources from config
 * @return array Formatted sources (no credentials)
 */
function formatEmbedWeatherSources(array $sources): array
{
    if (!is_array($sources)) {
        return [];
    }
    return array_map(function ($source) {
        return [
            'type' => $source['type'] ?? '',
        ];
    }, $sources);
}

/**
 * Build full embed payload by topic (weather, airport)
 *
 * @param string $airportId Airport ID
 * @param array|null $weatherData Raw weather from cache (null if unavailable)
 * @param array $airport Airport config
 * @return array{weather: array, weather_observed_at: string|null, airport: array, airport_observed_at: string}
 */
function buildEmbedPayloadByTopic(string $airportId, ?array $weatherData, array $airport): array
{
    $weatherTopic = [
        'weather' => $weatherData ?? [],
        'weather_observed_at' => null,
    ];
    if ($weatherData !== null && isset($weatherData['obs_time_primary'])) {
        $weatherTopic['weather_observed_at'] = gmdate('c', $weatherData['obs_time_primary']);
    }

    $airportTopic = [
        'airport' => [
            'id' => $airportId,
            'name' => $airport['name'] ?? '',
            'icao' => $airport['icao'] ?? null,
            'lat' => $airport['lat'] ?? null,
            'lon' => $airport['lon'] ?? null,
            'elevation_ft' => $airport['elevation_ft'] ?? null,
            'timezone' => $airport['timezone'] ?? 'UTC',
            'webcams' => $airport['webcams'] ?? [],
            'runways' => $airport['runways'] ?? [],
            'weather_sources' => formatEmbedWeatherSources($airport['weather_sources'] ?? []),
        ],
        'airport_observed_at' => gmdate('c', time()),
    ];

    return array_merge($weatherTopic, $airportTopic);
}

/**
 * Compute topic-level diff: include topic if values or observed_at changed
 *
 * @param array $current Current payload (from buildEmbedPayloadByTopic)
 * @param array $previous Previous payload from APCu
 * @return array Only changed topics with observed_at
 */
function computeEmbedTopicDiff(array $current, array $previous): array
{
    $diff = [];

    $weatherChanged = ($current['weather'] ?? []) !== ($previous['weather'] ?? [])
        || ($current['weather_observed_at'] ?? null) !== ($previous['weather_observed_at'] ?? null);
    if ($weatherChanged) {
        $diff['weather'] = $current['weather'] ?? [];
        $diff['weather_observed_at'] = $current['weather_observed_at'] ?? null;
    }

    $airportChanged = ($current['airport'] ?? []) !== ($previous['airport'] ?? [])
        || ($current['airport_observed_at'] ?? null) !== ($previous['airport_observed_at'] ?? null);
    if ($airportChanged) {
        $diff['airport'] = $current['airport'] ?? [];
        $diff['airport_observed_at'] = $current['airport_observed_at'] ?? null;
    }

    return $diff;
}

/**
 * Update embed diff cache after weather cache write
 *
 * Rotates prev←current, current←new. Invoked after successful weather cache write.
 * No-op when APCu extension is disabled.
 *
 * @param string $airportId Airport ID
 * @param array $weatherData Weather data just written to cache
 * @param array $airport Airport config
 * @return void
 */
function updateEmbedDiffCache(string $airportId, array $weatherData, array $airport): void
{
    if (!function_exists('apcu_store')) {
        return;
    }

    $keyCurrent = EMBED_DIFF_PREFIX . 'current_' . $airportId;
    $keyPrev = EMBED_DIFF_PREFIX . 'prev_' . $airportId;

    $newPayload = buildEmbedPayloadByTopic($airportId, $weatherData, $airport);
    $oldCurrent = apcu_fetch($keyCurrent);

    if ($oldCurrent !== false && is_array($oldCurrent)) {
        apcu_store($keyPrev, $oldCurrent, EMBED_DIFF_TTL);
    }
    apcu_store($keyCurrent, $newPayload, EMBED_DIFF_TTL);
}

/**
 * Get differential payload for refresh=1, or null if no previous (return full)
 *
 * @param string $airportId Airport ID
 * @param array $currentPayload Current full payload from buildEmbedPayloadByTopic
 * @return array|null Diff array (only changed topics) or null if no prev (use full)
 */
function getEmbedDiffPayload(string $airportId, array $currentPayload): ?array
{
    if (!function_exists('apcu_fetch')) {
        return null;
    }

    $keyPrev = EMBED_DIFF_PREFIX . 'prev_' . $airportId;
    $prev = apcu_fetch($keyPrev);

    if ($prev === false || !is_array($prev)) {
        return null;
    }

    $diff = computeEmbedTopicDiff($currentPayload, $prev);
    return empty($diff) ? [] : $diff;
}
