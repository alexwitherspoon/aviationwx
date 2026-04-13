<?php
/**
 * VRM API provider: fetches stats + optional BatterySummary, normalizes to canonical station-power JSON.
 *
 * User-visible strings are not produced here; airport template uses neutral labels.
 */

declare(strict_types=1);

require_once __DIR__ . '/../../constants.php';
require_once __DIR__ . '/../../logger.php';

/**
 * VRM REST API (v2) integration for station power metrics.
 */
final class VrmStationPowerProvider
{
    /** @see https://vrmapi.victronenergy.com/v2 */
    private const API_BASE = 'https://vrmapi.victronenergy.com/v2';

    private const STATS_INTERVAL = '15mins';

    /** Minimum gap between stats GET and BatterySummary GET (Node-RED convention). */
    private const MIN_REQUEST_SPACING_SECONDS = 5;

    /** Stats query window must stay within API max for 15mins interval (31 days). */
    private const STATS_WINDOW_SECONDS = 172800; // 48 hours; enough for latest bucket

    /** BatterySummary widget meta keys (VRM). */
    private const META_VOLTAGE = 47;

    private const META_TTG = 52;

    /**
     * Normalize decoded stats JSON (+ optional widget JSON) into canonical station-power fields.
     *
     * @param array<string,mixed>|null $statsJson Decoded /stats response
     * @param array<string,mixed>|null $widgetJson Decoded BatterySummary widget response
     * @param int $fetchedAt Unix time when HTTP completed successfully
     * @return array<string,mixed> Canonical cache row
     */
    public static function normalizeFromStatsResponse(?array $statsJson, ?array $widgetJson, int $fetchedAt): array
    {
        $records = is_array($statsJson) && isset($statsJson['records']) && is_array($statsJson['records'])
            ? $statsJson['records']
            : [];

        $soc = self::lastRowIndexOne($records['bs'] ?? null);
        $load = self::lastRowIndexOne($records['consumption'] ?? null);
        $pc = self::lastRowIndexOne($records['Pc'] ?? null);
        $pb = self::lastRowIndexOne($records['Pb'] ?? null);

        $tsMs = self::maxSampleTimeMs($records);

        $volts = null;
        $ttg = null;
        if (is_array($widgetJson) && isset($widgetJson['records']['data']) && is_array($widgetJson['records']['data'])) {
            $data = $widgetJson['records']['data'];
            $volts = self::widgetNumericValue($data, (string) self::META_VOLTAGE);
            $ttgStr = self::widgetFormattedValue($data, (string) self::META_TTG);
            $ttg = $ttgStr;
        }

        return [
            'provider' => 'vrm',
            'fetched_at' => $fetchedAt,
            'sample_time_ms' => $tsMs,
            'battery_soc_percent' => $soc,
            'load_watts' => $load,
            'solar_pc_watts' => $pc,
            'solar_pb_watts' => $pb,
            'battery_volts' => $volts,
            'time_to_go_display' => $ttg,
            'stats_interval' => self::STATS_INTERVAL,
        ];
    }

    /**
     * Fetch from VRM and return canonical array, or null on hard failure (caller retains prior cache).
     *
     * @param array<string,mixed> $vrmConfig Keys: installation_id (int), access_token (string)
     * @return array<string,mixed>|null
     */
    public static function fetchCanonical(array $vrmConfig): ?array
    {
        $id = isset($vrmConfig['installation_id']) ? (int) $vrmConfig['installation_id'] : 0;
        $token = isset($vrmConfig['access_token']) && is_string($vrmConfig['access_token'])
            ? $vrmConfig['access_token']
            : '';
        if ($id <= 0 || $token === '') {
            aviationwx_log('warning', 'station_power vrm: missing installation_id or access_token', [], 'app');
            return null;
        }

        $end = time();
        $start = $end - self::STATS_WINDOW_SECONDS;
        $statsUrl = self::buildStatsUrl($id, $start, $end);
        [$code, $body] = self::httpGet($statsUrl, $token);
        if ($code !== HTTP_STATUS_OK || $body === null || $body === '') {
            aviationwx_log('warning', 'station_power vrm: stats request failed', [
                'http_code' => $code,
                'installation_id' => $id,
            ], 'app');
            return null;
        }
        $statsJson = json_decode($body, true);
        if (!is_array($statsJson)) {
            aviationwx_log('warning', 'station_power vrm: stats JSON decode failed', ['installation_id' => $id], 'app');
            return null;
        }

        sleep(self::MIN_REQUEST_SPACING_SECONDS);

        $widgetUrl = self::API_BASE . '/installations/' . $id . '/widgets/BatterySummary';
        [$wCode, $wBody] = self::httpGet($widgetUrl, $token);
        $widgetJson = null;
        if ($wCode === HTTP_STATUS_OK && $wBody !== null && $wBody !== '') {
            $decoded = json_decode($wBody, true);
            $widgetJson = is_array($decoded) ? $decoded : null;
        }

        $fetchedAt = time();
        return self::normalizeFromStatsResponse($statsJson, $widgetJson, $fetchedAt);
    }

    /**
     * @param array<int,mixed>|null $rows Series rows from VRM stats
     */
    private static function lastRowIndexOne($rows): ?float
    {
        if (!is_array($rows) || $rows === []) {
            return null;
        }
        $last = $rows[count($rows) - 1];
        if (!is_array($last) || count($last) < 2) {
            return null;
        }
        $v = $last[1];
        if (!is_numeric($v)) {
            return null;
        }
        return (float) $v;
    }

    /**
     * @param array<string,mixed> $records records.* from stats
     */
    private static function maxSampleTimeMs(array $records): ?int
    {
        $max = null;
        foreach (['bs', 'consumption', 'Pc', 'Pb'] as $key) {
            if (!isset($records[$key]) || !is_array($records[$key])) {
                continue;
            }
            $rows = $records[$key];
            if ($rows === []) {
                continue;
            }
            $last = $rows[count($rows) - 1];
            if (!is_array($last) || !isset($last[0]) || !is_numeric($last[0])) {
                continue;
            }
            $t = (int) $last[0];
            if ($max === null || $t > $max) {
                $max = $t;
            }
        }
        return $max;
    }

    /**
     * @param array<string,mixed> $data Widget records.data map
     */
    private static function widgetNumericValue(array $data, string $key): ?float
    {
        if (!isset($data[$key]) || !is_array($data[$key])) {
            return null;
        }
        $v = $data[$key]['value'] ?? null;
        if (!is_numeric($v)) {
            return null;
        }
        return (float) $v;
    }

    /**
     * @param array<string,mixed> $data Widget records.data map
     */
    private static function widgetFormattedValue(array $data, string $key): ?string
    {
        if (!isset($data[$key]) || !is_array($data[$key])) {
            return null;
        }
        $fv = $data[$key]['formattedValue'] ?? null;
        if (is_string($fv) && $fv !== '') {
            return $fv;
        }
        $v = $data[$key]['value'] ?? null;
        if (is_numeric($v)) {
            return (string) $v;
        }
        return null;
    }

    private static function buildStatsUrl(int $installationId, int $start, int $end): string
    {
        $base = self::API_BASE . '/installations/' . $installationId . '/stats';
        $parts = [
            'type' => 'custom',
            'interval' => self::STATS_INTERVAL,
            'start' => (string) $start,
            'end' => (string) $end,
        ];
        $q = http_build_query($parts, '', '&', PHP_QUERY_RFC3986);
        foreach (['bs', 'consumption', 'Pc', 'Pb'] as $code) {
            $q .= '&attributeCodes%5B%5D=' . rawurlencode($code);
        }
        return $base . '?' . $q;
    }

    /**
     * @return array{0:int,1:string|null} HTTP status code and body
     */
    private static function httpGet(string $url, string $token): array
    {
        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => CURL_CONNECT_TIMEOUT,
            CURLOPT_TIMEOUT => CURL_TIMEOUT,
            CURLOPT_HTTPHEADER => [
                'Accept: application/json',
                'X-Authorization: Token ' . $token,
            ],
        ]);
        $body = curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        if ($body === false) {
            $body = null;
        }
        curl_close($ch);
        return [$code, $body];
    }
}
