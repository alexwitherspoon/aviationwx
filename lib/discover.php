<?php
/**
 * Discover kiosk deck selection.
 *
 * Pure deck-building for the discover booth tour: given each airport's current
 * health status, return the eligible entries in stable config order. The health
 * lookup itself (checkAirportHealth) stays with the caller so this stays unit
 * testable without filesystem or network fixtures.
 */

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/status-checks.php';
require_once __DIR__ . '/cache-paths.php';

/**
 * Select the airports eligible for the discover tour.
 *
 * An airport is eligible only when its current status is operational. Missing
 * status entries fail closed (excluded), so a failed health read can never put
 * an airport on the booth screen.
 *
 * @param array $airports Airport config entries keyed by airport id
 * @param array $statusById Map of airport id to health status string
 * @return array List of ['id' => string, 'primary' => string, 'name' => string]
 */
function discoverSelectOperationalAirports(array $airports, array $statusById): array
{
    $deck = [];
    foreach ($airports as $airportId => $airport) {
        if (($statusById[$airportId] ?? null) !== 'operational') {
            continue;
        }
        $deck[] = [
            'id' => $airportId,
            'primary' => getPrimaryIdentifier($airportId, $airport),
            'name' => is_string($airport['name'] ?? null) ? $airport['name'] : (string) $airportId,
        ];
    }
    return $deck;
}

/**
 * Build the current operational deck from a loaded config.
 *
 * Runs the full per-airport health scan once. Callers that need it at the cost
 * of the status page (the discover HTML embed and the ?pool=1 refresh) use this;
 * the per-transition re-check uses checkAirportHealth directly for a single id.
 *
 * The deck is listed airports only: getListedAirports drops unlisted (hidden)
 * airports, and the selector below drops any status other than operational,
 * which leaves maintenance, degraded, and down airports out as well.
 *
 * @param array|null $config Loaded config; an empty deck results when null
 * @return array List of eligible airports in config order (caller shuffles)
 */
function discoverBuildOperationalDeck(?array $config): array
{
    if ($config === null) {
        return [];
    }

    $airports = getListedAirports($config);
    $statusById = [];
    foreach ($airports as $airportId => $airport) {
        $statusById[$airportId] = checkAirportHealth($airportId, $airport)['status'] ?? 'down';
    }
    return discoverSelectOperationalAirports($airports, $statusById);
}

/**
 * The current operational deck, cached for a short time.
 *
 * discoverBuildOperationalDeck runs the full per-airport health scan, which the
 * booth does not need on every request. A 30 second cache (APCu first, then a
 * small file) bounds that cost while staying well inside the per-transition
 * window. The show-time ?health re-check stays uncached, so a stale deck entry
 * is still caught before it reaches the screen.
 *
 * @param array|null $config Loaded config; an empty deck results when null
 * @param int $ttlSeconds Cache lifetime in seconds
 * @return array List of eligible airports
 */
function discoverGetCachedOperationalDeck(?array $config, int $ttlSeconds = 30): array
{
    // A null config has no deck, and caching the resulting empty list would
    // turn a transient config failure into a 30 second idle for the booth.
    // Return without reading or writing either cache so the failure clears on
    // the next call.
    if ($config === null) {
        return [];
    }

    $cacheKey = 'discover_operational_deck';
    $cacheFile = CACHE_BASE_DIR . '/discover_operational_deck.json';

    if (function_exists('apcu_fetch')) {
        $cached = @apcu_fetch($cacheKey, $success);
        if ($success && is_array($cached)) {
            return $cached;
        }
    }

    if (file_exists($cacheFile)) {
        $content = @file_get_contents($cacheFile);
        if ($content !== false) {
            $envelope = @json_decode($content, true);
            if (is_array($envelope)
                && isset($envelope['cached_at'], $envelope['data'])
                && is_array($envelope['data'])
                && (time() - (int) $envelope['cached_at']) < $ttlSeconds
            ) {
                if (function_exists('apcu_store')) {
                    @apcu_store($cacheKey, $envelope['data'], $ttlSeconds);
                }
                return $envelope['data'];
            }
        }
    }

    $deck = discoverBuildOperationalDeck($config);

    if (function_exists('apcu_store')) {
        @apcu_store($cacheKey, $deck, $ttlSeconds);
    }
    $encoded = json_encode(['cached_at' => time(), 'data' => $deck]);
    if ($encoded !== false) {
        // Write to a temp file and rename over the cache so a concurrent
        // reader never sees a partial JSON body (it just recomputes instead).
        $tmpFile = @tempnam(CACHE_BASE_DIR, 'discover_deck_');
        if ($tmpFile !== false && @file_put_contents($tmpFile, $encoded) === strlen($encoded)) {
            @rename($tmpFile, $cacheFile);
        } elseif ($tmpFile !== false) {
            @unlink($tmpFile);
        }
    }

    return $deck;
}
