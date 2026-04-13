<?php
/**
 * Resolves station_power.provider to a fetch implementation.
 */

declare(strict_types=1);

require_once __DIR__ . '/provider/VrmStationPowerProvider.php';

final class StationPowerRegistry
{
    public const PROVIDER_VRM = 'vrm';

    /**
     * Fetch canonical station power snapshot for the given airport config block.
     *
     * @param array<string,mixed> $stationPower Airport `station_power` object (provider + config)
     * @param string|null $airportTimezone PHP timezone id (e.g. from airport `timezone`) for local-day energy
     * @return array<string,mixed>|null
     */
    public static function fetchCanonical(array $stationPower, ?string $airportTimezone = null): ?array
    {
        $provider = isset($stationPower['provider']) && is_string($stationPower['provider'])
            ? $stationPower['provider']
            : '';
        $config = isset($stationPower['config']) && is_array($stationPower['config'])
            ? $stationPower['config']
            : [];

        if ($provider === self::PROVIDER_VRM) {
            return VrmStationPowerProvider::fetchCanonical($config, $airportTimezone);
        }

        return null;
    }
}
