<?php
/**
 * NMS AIXM adapter: parsed NOTAM rows → AirspaceRecord candidates.
 *
 * Per-airport NMS HTTP remains in lib/notam/fetcher.php (auth, rate limit,
 * geo queries). This adapter normalizes already-parsed rows (or AIXM XML
 * arrays) into the canonical airspace record shape for the unified store.
 */

declare(strict_types=1);

namespace AviationWX\Notam\Airspace\Adapter;

require_once __DIR__ . '/NotamSourceAdapter.php';
require_once __DIR__ . '/../../parser.php';
require_once __DIR__ . '/../identity.php';
require_once __DIR__ . '/../../../constants.php';
require_once __DIR__ . '/../../../config.php';

/**
 * FAA NMS AIXM → AirspaceRecord adapter.
 */
final class NmsAixmAdapter implements NotamSourceAdapter
{
    public const SOURCE_TYPE = NOTAM_AIRSPACE_SOURCE_NMS;

    public static function getSourceType(): string
    {
        return self::SOURCE_TYPE;
    }

    public static function getTypicalUpdateFrequency(): int
    {
        return defined('NOTAM_CACHE_TTL_DEFAULT') ? (int) NOTAM_CACHE_TTL_DEFAULT : 3600;
    }

    public static function getMaxAcceptableAge(): int
    {
        return self::getTypicalUpdateFrequency();
    }

    /**
     * NMS URLs are query-specific (location / geo); built by the fetcher, not here.
     *
     * @param array<string, mixed> $config
     */
    public static function buildUrl(array $config = []): ?string
    {
        $url = trim((string) ($config['url'] ?? ''));

        return $url !== '' ? $url : null;
    }

    /**
     * Parse AIXM XML strings (newline-joined or JSON list) into AirspaceRecords.
     *
     * Prefer {@see recordsFromParsedNotams()} when rows are already parsed.
     *
     * @param string $response Raw AIXM XML, or JSON array of XML strings
     * @param array<string, mixed> $config Must include airport_id; optional timezone, airport
     * @return list<array<string, mixed>>|null
     */
    public static function parseResponse(string $response, array $config = []): ?array
    {
        $response = trim($response);
        if ($response === '') {
            return null;
        }

        $xmlStrings = [];
        if ($response[0] === '[') {
            try {
                $decoded = json_decode($response, true, 512, JSON_THROW_ON_ERROR);
            } catch (\JsonException $e) {
                return null;
            }
            if (!is_array($decoded)) {
                return null;
            }
            foreach ($decoded as $item) {
                if (is_string($item) && trim($item) !== '') {
                    $xmlStrings[] = $item;
                }
            }
        } else {
            $xmlStrings[] = $response;
        }

        if ($xmlStrings === []) {
            return null;
        }

        $parsed = parseNotamXmlArray($xmlStrings);

        return self::recordsFromParsedNotams($parsed, $config);
    }

    /**
     * Convert already-parsed NMS rows into map-capable AirspaceRecords.
     *
     * @param list<array<string, mixed>> $notams
     * @param array<string, mixed> $config airport_id, timezone, optional airport array
     * @return list<array<string, mixed>>
     */
    public static function recordsFromParsedNotams(array $notams, array $config = []): array
    {
        require_once __DIR__ . '/../../map-aggregate-cache.php';

        $airportId = trim((string) ($config['airport_id'] ?? $config['source_airport_id'] ?? ''));
        $timezone = trim((string) ($config['timezone'] ?? ''));
        if ($timezone === '' && isset($config['airport']) && is_array($config['airport'])) {
            $timezone = getAirportTimezone($config['airport']);
        }
        if ($timezone === '') {
            $timezone = 'UTC';
        }

        $records = [];
        foreach ($notams as $notam) {
            if (!is_array($notam)) {
                continue;
            }
            $record = notamAirspaceRecordFromNotam($notam, $airportId !== '' ? $airportId : 'unknown', $timezone);
            if ($record !== null) {
                $records[] = $record;
            }
        }

        return $records;
    }
}
