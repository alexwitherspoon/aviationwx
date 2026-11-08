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
