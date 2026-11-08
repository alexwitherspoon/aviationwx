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