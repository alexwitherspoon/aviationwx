<?php
/**
 * Record-level airspace merge: one shape per norm_number bucket.
 *
 * Unlike weather field-level aggregation, this emits a single AirspaceRecord
 * per effective restriction and tracks per-field provenance in field_sources.
 */

declare(strict_types=1);

namespace AviationWX\Notam\Airspace;

require_once __DIR__ . '/identity.php';
require_once __DIR__ . '/capabilities.php';
require_once __DIR__ . '/adapter/FaaTfrWfsAdapter.php';

use AviationWX\Notam\Airspace\Adapter\FaaTfrWfsAdapter;

/**
 * Merge NMS and WFS (and future) AirspaceRecord candidates.
 */
final class AirspaceAggregator
{
    /** Bump when merge preference or capability recompute rules change. */
    public const MERGE_LOGIC_VERSION = NOTAM_AIRSPACE_MERGE_LOGIC_VERSION;

    /**
     * Merge candidate records into a map keyed by store identity.
     *
     * Policy when NMS and WFS share a norm_number: WFS geometry, NMS schedule/text.
     *
     * @param list<array<string, mixed>> $candidates
     * @return array<string, array<string, mixed>>
     */
    public static function merge(array $candidates): array
    {
        /** @var array<string, list<array<string, mixed>>> $buckets */
        $buckets = [];

        foreach ($candidates as $record) {
            if (!is_array($record)) {
                continue;
            }
            $key = self::bucketKey($record);
            if ($key === null) {
                continue;
            }
            $buckets[$key][] = $record;
        }

        $merged = [];
        foreach ($buckets as $key => $group) {
            $record = self::mergeBucket($group);
            if ($record !== null) {
                $merged[$key] = $record;
            }
        }

        return self::dedupeUnmatchedByGeometryKey($merged);
    }

    /**
     * Store / merge bucket key.
     */
    public static function bucketKey(array $record): ?string
    {
        $norm = $record['norm_number'] ?? null;
        if (is_string($norm) && $norm !== '') {
            return $norm;
        }

        $notamId = trim((string) ($record['notam_id'] ?? ''));
        if ($notamId === '') {
            return null;
        }

        return 'ID:' . $notamId;
    }

    /**
     * @param list<array<string, mixed>> $group
     * @return array<string, mixed>|null
     */
    private static function mergeBucket(array $group): ?array
    {
        if ($group === []) {
            return null;
        }

        if (count($group) === 1) {
            $only = $group[0];
            $only['capabilities'] = self::recomputeCapabilities($only);

            return $only;
        }

        $nms = null;
        $wfs = null;
        $others = [];

        foreach ($group as $record) {
            $sources = $record['record_sources'] ?? [];
            if (!is_array($sources)) {
                $sources = [];
            }
            $hasNms = in_array(NOTAM_AIRSPACE_SOURCE_NMS, $sources, true)
                || (isset($record['notam']) && is_array($record['notam']));
            $hasWfs = in_array(FaaTfrWfsAdapter::SOURCE_TYPE, $sources, true)
                || (($record['field_sources']['geometry'] ?? null) === FaaTfrWfsAdapter::SOURCE_TYPE);

            if ($hasNms && $nms === null) {
                $nms = $record;
            } elseif ($hasWfs && $wfs === null) {
                $wfs = $record;
            } else {
                $others[] = $record;
            }
        }

        if ($nms !== null && $wfs !== null) {
            return self::blendNmsAndWfs($nms, $wfs);
        }

        if ($nms !== null) {
            $nms['capabilities'] = self::recomputeCapabilities($nms);

            return $nms;
        }
        if ($wfs !== null) {
            $wfs['capabilities'] = self::recomputeCapabilities($wfs);

            return $wfs;
        }

        $first = $others[0] ?? $group[0];
        $first['capabilities'] = self::recomputeCapabilities($first);

        return $first;
    }

    /**
     * @param array<string, mixed> $nms
     * @param array<string, mixed> $wfs
     * @return array<string, mixed>
     */
    private static function blendNmsAndWfs(array $nms, array $wfs): array
    {
        $merged = $nms;

        // Prefer NMS native circles over coarse WFS polygon approximations (Disney, VIP rings).
        $nmsIsCircle = (($nms['geometry_kind'] ?? null) === 'circle')
            && isset($nms['geometry']['type'])
            && $nms['geometry']['type'] === 'Point'
            && isset($nms['radius_nm'])
            && is_numeric($nms['radius_nm'])
            && (float) $nms['radius_nm'] > 0.0;

        if (!$nmsIsCircle && isset($wfs['geometry']) && is_array($wfs['geometry'])) {
            $merged['geometry'] = $wfs['geometry'];
            $merged['geometry_kind'] = $wfs['geometry_kind'] ?? $merged['geometry_kind'] ?? null;
            unset($merged['radius_nm']);
        }

        if (!empty($wfs['wfs_title'])) {
            $merged['wfs_title'] = $wfs['wfs_title'];
        }
        if (!empty($wfs['wfs_legal'])) {
            $merged['wfs_legal'] = $wfs['wfs_legal'];
        }

        if (empty($merged['restriction_kind']) && !empty($wfs['restriction_kind'])) {
            $merged['restriction_kind'] = $wfs['restriction_kind'];
        }

        $recordSources = [];
        foreach ([$nms, $wfs] as $side) {
            $src = $side['record_sources'] ?? [];
            if (!is_array($src)) {
                continue;
            }
            foreach ($src as $s) {
                if (is_string($s) && $s !== '' && !in_array($s, $recordSources, true)) {
                    $recordSources[] = $s;
                }
            }
        }
        $merged['record_sources'] = $recordSources;

        $fieldSources = [];
        $nmsFields = is_array($nms['field_sources'] ?? null) ? $nms['field_sources'] : [];
        $wfsFields = is_array($wfs['field_sources'] ?? null) ? $wfs['field_sources'] : [];

        foreach ($nmsFields as $field => $source) {
            if (is_string($field) && is_string($source)) {
                $fieldSources[$field] = $source;
            }
        }
        foreach (['geometry', 'wfs_title', 'wfs_legal'] as $field) {
            if ($field === 'geometry' && $nmsIsCircle) {
                continue;
            }
            if (isset($wfsFields[$field]) && is_string($wfsFields[$field])) {
                $fieldSources[$field] = $wfsFields[$field];
            } elseif ($field === 'geometry' && isset($wfs['geometry'])) {
                $fieldSources['geometry'] = FaaTfrWfsAdapter::SOURCE_TYPE;
            }
        }
        if (!isset($fieldSources['restriction_kind']) && isset($wfsFields['restriction_kind'])) {
            $fieldSources['restriction_kind'] = $wfsFields['restriction_kind'];
        }

        $merged['field_sources'] = $fieldSources;
        $merged['merged_at'] = gmdate('c');
        $merged['upserted_at'] = time();
        $merged['capabilities'] = self::recomputeCapabilities($merged);

        return $merged;
    }

    /**
     * @param array<string, mixed> $record
     * @return array{map: bool, banner: bool, runway_closure: bool}
     */
    public static function recomputeCapabilities(array $record): array
    {
        $hasGeometry = isset($record['geometry']) && is_array($record['geometry']);
        $kind = (string) ($record['geometry_kind'] ?? '');
        $mapOk = $hasGeometry && in_array($kind, ['polygon', 'circle', 'multipolygon'], true);

        $notam = $record['notam'] ?? null;
        $banner = false;
        $runway = false;
        if (is_array($notam)) {
            $banner = notamAirspaceRecordBannerCapable($notam);
            $runway = notamAirspaceRecordRunwayClosureCapable($notam);
        }

        $sources = $record['record_sources'] ?? [];
        $onlyWfs = is_array($sources) && $sources === [FaaTfrWfsAdapter::SOURCE_TYPE];
        if ($onlyWfs || !is_array($notam)) {
            $banner = false;
            $runway = false;
        }

        return [
            'map' => $mapOk,
            'banner' => $banner,
            'runway_closure' => $runway,
        ];
    }

    /**
     * @param array<string, array<string, mixed>> $records
     * @return array<string, array<string, mixed>>
     */
    private static function dedupeUnmatchedByGeometryKey(array $records): array
    {
        /** @var array<string, string> $geomKeyToStoreKey */
        $geomKeyToStoreKey = [];
        $out = [];

        foreach ($records as $storeKey => $record) {
            $geomKey = self::geometryDedupKey($record);
            if ($geomKey === null) {
                $out[$storeKey] = $record;
                continue;
            }

            if (!isset($geomKeyToStoreKey[$geomKey])) {
                $geomKeyToStoreKey[$geomKey] = $storeKey;
                $out[$storeKey] = $record;
                continue;
            }

            $existingKey = $geomKeyToStoreKey[$geomKey];
            $existing = $out[$existingKey];
            $winner = self::preferRicherRecord($existing, $record);
            if ($winner === $record) {
                unset($out[$existingKey]);
                $geomKeyToStoreKey[$geomKey] = $storeKey;
                $out[$storeKey] = $record;
            }
        }

        return $out;
    }

    /**
     * @param array<string, mixed> $record
     */
    private static function geometryDedupKey(array $record): ?string
    {
        $geometry = $record['geometry'] ?? null;
        if (!is_array($geometry)) {
            return null;
        }

        try {
            return sha1(json_encode($geometry, JSON_THROW_ON_ERROR));
        } catch (\JsonException $e) {
            return null;
        }
    }

    /**
     * @param array<string, mixed> $a
     * @param array<string, mixed> $b
     * @return array<string, mixed>
     */
    private static function preferRicherRecord(array $a, array $b): array
    {
        $score = static function (array $r): int {
            $s = 0;
            if (is_array($r['notam'] ?? null)) {
                $s += 100;
            }
            $sources = $r['record_sources'] ?? [];
            if (is_array($sources)) {
                $s += count($sources) * 10;
                if (in_array(NOTAM_AIRSPACE_SOURCE_NMS, $sources, true)) {
                    $s += 50;
                }
            }
            if (($r['capabilities']['banner'] ?? false) === true) {
                $s += 20;
            }

            return $s;
        };

        return $score($b) > $score($a) ? $b : $a;
    }
}
