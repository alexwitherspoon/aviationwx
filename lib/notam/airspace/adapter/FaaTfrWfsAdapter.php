<?php
/**
 * FAA TFR WFS (V_TFR_LOC) adapter → AirspaceRecord rows.
 *
 * Public GeoJSON GetFeature; no NMS credential. Geometry-first thin records.
 * Banner and runway_closure capabilities stay false until NMS merge supplies schedule/text.
 */

declare(strict_types=1);

namespace AviationWX\Notam\Airspace\Adapter;

require_once __DIR__ . '/../../../logger.php';
require_once __DIR__ . '/../../../cache-paths.php';
require_once __DIR__ . '/../identity.php';
require_once __DIR__ . '/../classification.php';
require_once __DIR__ . '/NotamSourceAdapter.php';

/**
 * Parse and classify FAA TFR WFS GeoJSON into canonical airspace records.
 */
final class FaaTfrWfsAdapter implements NotamSourceAdapter
{
    public const SOURCE_TYPE = 'faa_tfr_wfs';

    public const DEFAULT_WFS_URL =
        'https://tfr.faa.gov/geoserver/TFR/ows'
        . '?service=WFS&version=1.1.0&request=GetFeature'
        . '&typeName=TFR:V_TFR_LOC&maxFeatures=500&outputFormat=application/json';

    /**
     * Source type string for field_sources / record_sources.
     */
    public static function getSourceType(): string
    {
        return self::SOURCE_TYPE;
    }

    public static function getTypicalUpdateFrequency(): int
    {
        return defined('FAA_TFR_WFS_REFRESH_INTERVAL_SECONDS')
            ? (int) FAA_TFR_WFS_REFRESH_INTERVAL_SECONDS
            : 900;
    }

    public static function getMaxAcceptableAge(): int
    {
        return self::getTypicalUpdateFrequency();
    }

    /**
     * Build the public WFS GetFeature URL.
     *
     * @param array<string, mixed> $config Optional overrides (url, max_features)
     */
    public static function buildUrl(array $config = []): ?string
    {
        $url = trim((string) ($config['url'] ?? ''));
        if ($url !== '') {
            return $url;
        }

        $maxFeatures = (int) ($config['max_features'] ?? 500);
        if ($maxFeatures < 1) {
            $maxFeatures = 500;
        }

        return 'https://tfr.faa.gov/geoserver/TFR/ows'
            . '?service=WFS&version=1.1.0&request=GetFeature'
            . '&typeName=TFR:V_TFR_LOC&maxFeatures=' . $maxFeatures
            . '&outputFormat=application/json';
    }

    /**
     * Parse a WFS GeoJSON FeatureCollection into AirspaceRecord rows.
     *
     * Multiple polygons sharing one NOTAM_KEY become a single MultiPolygon record.
     *
     * @param string $response Raw GeoJSON body
     * @param array<string, mixed> $config Unused; reserved for future filters
     * @return list<array<string, mixed>>|null Null when payload is unusable
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
            aviationwx_log('warning', 'faa tfr wfs: invalid JSON', [
                'error' => $e->getMessage(),
            ], 'app');

            return null;
        }

        if (!is_array($decoded) || ($decoded['type'] ?? '') !== 'FeatureCollection') {
            return null;
        }

        $features = $decoded['features'] ?? null;
        if (!is_array($features)) {
            return null;
        }

        /** @var array<string, array{geometries: list<array<string, mixed>>, props: array<string, mixed>}> $groups */
        $groups = [];

        foreach ($features as $feature) {
            if (!is_array($feature)) {
                continue;
            }

            $geometry = $feature['geometry'] ?? null;
            if (!is_array($geometry) || !self::isDrawableGeometry($geometry)) {
                continue;
            }

            $props = $feature['properties'] ?? [];
            if (!is_array($props)) {
                $props = [];
            }

            $notamKey = trim((string) ($props['NOTAM_KEY'] ?? ''));
            $groupKey = $notamKey !== '' ? $notamKey : ('gid:' . trim((string) ($props['GID'] ?? uniqid('wfs', true))));

            if (!isset($groups[$groupKey])) {
                $groups[$groupKey] = [
                    'geometries' => [],
                    'props' => $props,
                ];
            }

            $groups[$groupKey]['geometries'][] = $geometry;
        }

        $records = [];
        foreach ($groups as $group) {
            $record = self::recordFromGroupedFeature($group['props'], $group['geometries']);
            if ($record !== null) {
                $records[] = $record;
            }
        }

        return $records;
    }

    /**
     * HTTP GET for WFS (test-injectable).
     *
     * @return array{ok: bool, body: string, http_code: int, error: string}
     */
    public static function httpGet(string $url): array
    {
        if (isset($GLOBALS['faaTfrWfsHttpHandler']) && is_callable($GLOBALS['faaTfrWfsHttpHandler'])) {
            $result = ($GLOBALS['faaTfrWfsHttpHandler'])($url);
            if (is_array($result)) {
                return [
                    'ok' => (bool) ($result['ok'] ?? false),
                    'body' => (string) ($result['body'] ?? ''),
                    'http_code' => (int) ($result['http_code'] ?? 0),
                    'error' => (string) ($result['error'] ?? ''),
                ];
            }
        }

        $ch = curl_init($url);
        if ($ch === false) {
            return ['ok' => false, 'body' => '', 'http_code' => 0, 'error' => 'curl_init failed'];
        }

        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_TIMEOUT => 45,
            CURLOPT_USERAGENT => 'AviationWX/1.0 (+https://aviationwx.org)',
        ]);

        $body = curl_exec($ch);
        $errno = curl_errno($ch);
        $error = curl_error($ch);
        $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($errno !== 0 || !is_string($body)) {
            return [
                'ok' => false,
                'body' => '',
                'http_code' => $httpCode,
                'error' => $error !== '' ? $error : 'curl error ' . $errno,
            ];
        }

        return [
            'ok' => $httpCode >= 200 && $httpCode < 300,
            'body' => $body,
            'http_code' => $httpCode,
            'error' => $httpCode >= 200 && $httpCode < 300 ? '' : 'HTTP ' . $httpCode,
        ];
    }

    /**
     * Fetch WFS, optionally persist raw body, and parse records.
     *
     * @param array<string, mixed> $config Adapter config
     * @return array{
     *   ok: bool,
     *   records: list<array<string, mixed>>,
     *   error: string,
     *   http_code: int
     * }
     */
    public static function fetchAndParse(array $config = []): array
    {
        $url = self::buildUrl($config);
        $http = self::httpGet($url);
        if (!$http['ok']) {
            return [
                'ok' => false,
                'records' => [],
                'error' => $http['error'] !== '' ? $http['error'] : 'WFS fetch failed',
                'http_code' => $http['http_code'],
            ];
        }

        if (!empty($config['persist_raw'])) {
            self::writeRawCache($http['body']);
        }

        $records = self::parseResponse($http['body'], $config);
        if ($records === null) {
            return [
                'ok' => false,
                'records' => [],
                'error' => 'WFS parse failed',
                'http_code' => $http['http_code'],
            ];
        }

        return [
            'ok' => true,
            'records' => $records,
            'error' => '',
            'http_code' => $http['http_code'],
        ];
    }

    /**
     * Persist raw WFS payload for restart survival / ops inspection.
     */
    public static function writeRawCache(string $body): bool
    {
        $path = getNotamFaaTfrWfsCachePath();
        $dir = dirname($path);
        if (!is_dir($dir) && !@mkdir($dir, 0755, true) && !is_dir($dir)) {
            return false;
        }

        $tmp = $path . '.tmp.' . bin2hex(random_bytes(4));
        if (@file_put_contents($tmp, $body, LOCK_EX) === false) {
            return false;
        }

        if (!@rename($tmp, $path)) {
            $ok = @file_put_contents($path, $body, LOCK_EX) !== false;
            @unlink($tmp);

            return $ok;
        }

        return true;
    }

    /**
     * Read last cached raw WFS body.
     */
    public static function readRawCache(): ?string
    {
        $path = getNotamFaaTfrWfsCachePath();
        if (!is_readable($path)) {
            return null;
        }

        $raw = @file_get_contents($path);

        return is_string($raw) && $raw !== '' ? $raw : null;
    }

    /**
     * Map WFS LEGAL / title into restriction_kind.
     */
    public static function restrictionKindFromWfs(array $props): string
    {
        return notamAirspaceRestrictionKindFromHints([
            'legal' => $props['LEGAL'] ?? null,
            'title' => $props['TITLE'] ?? null,
        ]);
    }

    /**
     * Extract display id and norm_number from WFS NOTAM_KEY.
     *
     * @return array{notam_id: string, norm_number: string|null}
     */
    public static function identityFromNotamKey(string $notamKey): array
    {
        $notamKey = trim($notamKey);
        if ($notamKey === '') {
            return ['notam_id' => '', 'norm_number' => null];
        }

        // 6/0543-1-FDC-F → display 6/0543, number 543
        if (preg_match('/^(\d+)\/(\d+)(?:-|$)/', $notamKey, $matches) === 1) {
            $display = $matches[1] . '/' . $matches[2];
            $norm = 'N:' . (int) $matches[2];

            return ['notam_id' => $display, 'norm_number' => $norm];
        }

        $norm = notamAirspaceNormNumberFromId($notamKey);

        return ['notam_id' => $notamKey, 'norm_number' => $norm];
    }

    /**
     * @param array<string, mixed> $props
     * @param list<array<string, mixed>> $geometries
     * @return array<string, mixed>|null
     */
    private static function recordFromGroupedFeature(array $props, array $geometries): ?array
    {
        if ($geometries === []) {
            return null;
        }

        $identity = self::identityFromNotamKey((string) ($props['NOTAM_KEY'] ?? ''));
        $notamId = $identity['notam_id'];
        if ($notamId === '') {
            $gid = trim((string) ($props['GID'] ?? ''));
            if ($gid === '') {
                return null;
            }
            $notamId = 'WFS:' . $gid;
        }

        $geometry = self::coalesceGeometries($geometries);
        if ($geometry === null) {
            return null;
        }

        $geometryKind = strtolower((string) ($geometry['type'] ?? ''));
        if ($geometryKind === 'multipolygon') {
            $storeKind = 'multipolygon';
        } elseif ($geometryKind === 'polygon') {
            $storeKind = 'polygon';
        } else {
            return null;
        }

        $restrictionKind = self::restrictionKindFromWfs($props);
        $title = trim((string) ($props['TITLE'] ?? ''));
        $source = self::SOURCE_TYPE;

        $fieldSources = [
            'notam_id' => $source,
            'geometry' => $source,
            'restriction_kind' => $source,
        ];
        if ($title !== '') {
            $fieldSources['wfs_title'] = $source;
        }

        return [
            'notam_id' => $notamId,
            'norm_number' => $identity['norm_number'],
            'restriction_kind' => $restrictionKind,
            'geometry' => $geometry,
            'geometry_kind' => $storeKind,
            'wfs_title' => $title !== '' ? $title : null,
            'wfs_legal' => trim((string) ($props['LEGAL'] ?? '')) ?: null,
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

    /**
     * @param list<array<string, mixed>> $geometries
     * @return array<string, mixed>|null
     */
    private static function coalesceGeometries(array $geometries): ?array
    {
        if (count($geometries) === 1) {
            $g = $geometries[0];
            $type = strtolower((string) ($g['type'] ?? ''));
            if ($type === 'polygon' || $type === 'multipolygon') {
                return $g;
            }

            return null;
        }

        $polygons = [];
        foreach ($geometries as $g) {
            $type = strtolower((string) ($g['type'] ?? ''));
            if ($type === 'polygon') {
                $coords = $g['coordinates'] ?? null;
                if (is_array($coords)) {
                    $polygons[] = $coords;
                }
            } elseif ($type === 'multipolygon') {
                $coords = $g['coordinates'] ?? null;
                if (is_array($coords)) {
                    foreach ($coords as $poly) {
                        if (is_array($poly)) {
                            $polygons[] = $poly;
                        }
                    }
                }
            }
        }

        if ($polygons === []) {
            return null;
        }

        if (count($polygons) === 1) {
            return [
                'type' => 'Polygon',
                'coordinates' => $polygons[0],
            ];
        }

        return [
            'type' => 'MultiPolygon',
            'coordinates' => $polygons,
        ];
    }

    /**
     * @param array<string, mixed> $geometry
     */
    private static function isDrawableGeometry(array $geometry): bool
    {
        $type = strtolower((string) ($geometry['type'] ?? ''));
        if ($type !== 'polygon' && $type !== 'multipolygon') {
            return false;
        }

        $coords = $geometry['coordinates'] ?? null;

        return is_array($coords) && $coords !== [];
    }
}
