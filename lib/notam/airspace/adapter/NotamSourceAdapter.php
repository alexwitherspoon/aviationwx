<?php
/**
 * NOTAM / airspace source adapter contract.
 *
 * Parallel to WeatherSourceAdapter: each upstream (NMS AIXM, FAA TFR WFS,
 * future international APIs) parses into AirspaceRecord rows with provenance.
 */

declare(strict_types=1);

namespace AviationWX\Notam\Airspace\Adapter;

/**
 * Source adapter for normalized airspace ingest.
 */
interface NotamSourceAdapter
{
    /**
     * Source type string used in record_sources / field_sources / config.
     */
    public static function getSourceType(): string;

    /**
     * Typical upstream refresh interval in seconds.
     */
    public static function getTypicalUpdateFrequency(): int;

    /**
     * Maximum acceptable age for a cached raw payload before refresh is required.
     */
    public static function getMaxAcceptableAge(): int;

    /**
     * Build the upstream URL for this source, or null when config is incomplete.
     *
     * @param array<string, mixed> $config Source configuration
     */
    public static function buildUrl(array $config = []): ?string;

    /**
     * Parse a raw upstream response into AirspaceRecord rows.
     *
     * @param string $response Raw response body
     * @param array<string, mixed> $config Source / airport context
     * @return list<array<string, mixed>>|null Null when the payload is unusable
     */
    public static function parseResponse(string $response, array $config = []): ?array;
}
