<?php
/**
 * Fixture-backed example international airspace adapter.
 *
 * Demonstrates the plugin contract for future authority sources. Not enabled
 * in production config; used in tests and as a copy-paste template.
 */

declare(strict_types=1);

namespace AviationWX\Notam\Airspace\Adapter;

require_once __DIR__ . '/NotamSourceAdapter.php';
require_once __DIR__ . '/../identity.php';
require_once __DIR__ . '/../classification.php';

/**
 * Example international adapter (fixture / offline only).
 */
final class ExampleInternationalAirspaceAdapter implements NotamSourceAdapter
{
    public const SOURCE_TYPE = 'example_international';

    public static function getSourceType(): string
    {
        return self::SOURCE_TYPE;
    }

    public static function getTypicalUpdateFrequency(): int
    {
        return 3600;
    }

    public static function getMaxAcceptableAge(): int
    {
        return 3600;
    }

    /**
     * @param array<string, mixed> $config
     */
    public static function buildUrl(array $config = []): ?string
    {
        $url = trim((string) ($config['url'] ?? ''));

        return $url !== '' ? $url : null;
    }

    /**
     * Parse a minimal GeoJSON FeatureCollection into thin AirspaceRecords.
     *
     * @param array<string, mixed> $config
     * @return list<array<string, mixed>>|null
     */
    public static function parseResponse(string $response, array $config = []): ?array
    {
        unset($config);
        $response = trim($response);
        if ($response === '') {
            return null;
        }

        try {
            $decoded = json_decode($response, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            return null;
        }

        if (!is_array($decoded) || ($decoded['type'] ?? '') !== 'FeatureCollection') {
            return null;
        }

        $features = $decoded['features'] ?? null;
        if (!is_array($features)) {
            return null;
        }

        $source = self::SOURCE_TYPE;
        $records = [];
        foreach ($features as $feature) {
            if (!is_array($feature)) {
                continue;
            }
            $geometry = $feature['geometry'] ?? null;
            $props = $feature['properties'] ?? [];
            if (!is_array($geometry) || !is_array($props)) {
                continue;
            }
            $type = strtolower((string) ($geometry['type'] ?? ''));
            if ($type !== 'polygon' && $type !== 'multipolygon') {
                continue;
            }

            $notamId = trim((string) ($props['notam_id'] ?? $props['id'] ?? ''));
            if ($notamId === '') {
                continue;
            }

            $kind = notamAirspaceRestrictionKindFromHints([
                'restriction_kind' => $props['restriction_kind'] ?? null,
                'legal' => $props['legal'] ?? null,
                'title' => $props['title'] ?? null,
                'text' => $props['text'] ?? null,
            ]);

            $norm = notamAirspaceNormNumberFromId($notamId);
            $title = trim((string) ($props['title'] ?? ''));
            $fieldSources = [
                'notam_id' => $source,
                'geometry' => $source,
                'restriction_kind' => $source,
            ];
            if ($title !== '') {
                $fieldSources['wfs_title'] = $source;
            }

            $records[] = [
                'notam_id' => $notamId,
                'norm_number' => $norm,
                'restriction_kind' => $kind,
                'geometry' => $geometry,
                'geometry_kind' => $type,
                'wfs_title' => $title !== '' ? $title : null,
                'authority' => trim((string) ($props['authority'] ?? 'example')),
                'official_search_url' => trim((string) ($props['official_search_url'] ?? '')),
                'notam' => null,
                'timezone' => 'UTC',
                'source_airport_id' => '',
                'upserted_at' => time(),
                'capabilities' => [
                    'map' => true,
                    'banner' => false,
                    'runway_closure' => false,
                ],
                'record_sources' => [$source],
                'field_sources' => $fieldSources,
                'merged_at' => null,
            ];
        }

        return $records;
    }
}
