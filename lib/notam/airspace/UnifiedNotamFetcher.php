<?php
/**
 * Unified NOTAM / airspace fetch entry point.
 *
 * Dispatches known source adapters (NMS AIXM, FAA TFR WFS, future plugins),
 * returning AirspaceRecord lists for AirspaceAggregator.
 */

declare(strict_types=1);

namespace AviationWX\Notam\Airspace;

require_once __DIR__ . '/adapter/NotamSourceAdapter.php';
require_once __DIR__ . '/adapter/FaaTfrWfsAdapter.php';
require_once __DIR__ . '/adapter/NmsAixmAdapter.php';
require_once __DIR__ . '/adapter/NmsFdcAirspaceAdapter.php';

use AviationWX\Notam\Airspace\Adapter\FaaTfrWfsAdapter;
use AviationWX\Notam\Airspace\Adapter\NmsAixmAdapter;
use AviationWX\Notam\Airspace\Adapter\NmsFdcAirspaceAdapter;
use AviationWX\Notam\Airspace\Adapter\NotamSourceAdapter;

/**
 * Adapter registry and fetch helpers for airspace ingest.
 */
final class UnifiedNotamFetcher
{
    /**
     * @return array<string, class-string<NotamSourceAdapter>>
     */
    public static function adapterMap(): array
    {
        $map = [
            NmsAixmAdapter::SOURCE_TYPE => NmsAixmAdapter::class,
            FaaTfrWfsAdapter::SOURCE_TYPE => FaaTfrWfsAdapter::class,
            NmsFdcAirspaceAdapter::SOURCE_TYPE => NmsFdcAirspaceAdapter::class,
        ];

        // Optional plugin adapters (tests / future config). Values are FQCN strings.
        $plugins = $GLOBALS['aviationwxAirspaceAdapterPlugins'] ?? null;
        if (is_array($plugins)) {
            foreach ($plugins as $type => $className) {
                if (!is_string($type) || $type === '' || !is_string($className) || $className === '') {
                    continue;
                }
                if (!class_exists($className)) {
                    continue;
                }
                if (!is_a($className, NotamSourceAdapter::class, true)) {
                    continue;
                }
                $map[$type] = $className;
            }
        }

        return $map;
    }

    /**
     * Resolve adapter class for a source type.
     *
     * @return class-string<NotamSourceAdapter>|null
     */
    public static function adapterFor(string $sourceType): ?string
    {
        $map = self::adapterMap();

        return $map[$sourceType] ?? null;
    }

    /**
     * Fetch and parse one source into AirspaceRecord rows.
     *
     * @param array<string, mixed> $config
     * @return array{
     *   ok: bool,
     *   source: string,
     *   records: list<array<string, mixed>>,
     *   error: string,
     *   http_code: int
     * }
     */
    public static function fetchSource(string $sourceType, array $config = []): array
    {
        $sourceType = trim($sourceType);
        $adapter = self::adapterFor($sourceType);
        if ($adapter === null) {
            return [
                'ok' => false,
                'source' => $sourceType,
                'records' => [],
                'error' => 'Unknown airspace source type: ' . $sourceType,
                'http_code' => 0,
            ];
        }

        if ($sourceType === FaaTfrWfsAdapter::SOURCE_TYPE) {
            $result = FaaTfrWfsAdapter::fetchAndParse($config);

            return [
                'ok' => $result['ok'],
                'source' => $sourceType,
                'records' => $result['records'],
                'error' => $result['error'],
                'http_code' => $result['http_code'],
            ];
        }

        if ($sourceType === NmsFdcAirspaceAdapter::SOURCE_TYPE) {
            $result = NmsFdcAirspaceAdapter::fetchAndParse($config);

            return [
                'ok' => $result['ok'],
                'source' => $sourceType,
                'records' => $result['records'],
                'error' => $result['error'],
                'http_code' => $result['http_code'],
            ];
        }

        // NMS per-airport and future adapters that only expose parse + URL (HTTP owned elsewhere).
        $url = $adapter::buildUrl($config);
        if ($url === null || $url === '') {
            return [
                'ok' => false,
                'source' => $sourceType,
                'records' => [],
                'error' => 'Missing URL for source ' . $sourceType,
                'http_code' => 0,
            ];
        }

        return [
            'ok' => false,
            'source' => $sourceType,
            'records' => [],
            'error' => 'HTTP fetch for source ' . $sourceType . ' is owned by the NMS fetcher; use parseResponse or recordsFromParsedNotams',
            'http_code' => 0,
        ];
    }

    /**
     * Parse a raw payload with the named adapter.
     *
     * @param array<string, mixed> $config
     * @return list<array<string, mixed>>|null
     */
    public static function parseSource(string $sourceType, string $response, array $config = []): ?array
    {
        $adapter = self::adapterFor($sourceType);
        if ($adapter === null) {
            return null;
        }

        return $adapter::parseResponse($response, $config);
    }

    /**
     * Convert already-parsed NMS rows through the NMS adapter.
     *
     * @param list<array<string, mixed>> $notams
     * @param array<string, mixed> $config
     * @return list<array<string, mixed>>
     */
    public static function recordsFromNmsNotams(array $notams, array $config = []): array
    {
        return NmsAixmAdapter::recordsFromParsedNotams($notams, $config);
    }
}
