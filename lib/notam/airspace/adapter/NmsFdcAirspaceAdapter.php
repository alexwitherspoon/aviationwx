<?php
/**
 * NMS FDC + AIRSPACE bulk adapter for national map completeness.
 *
 * Uses the same credential and AIXM parser stack as per-airport NMS fetch.
 * Records are NMS-sourced for merge correlation; source_status tracks
 * `nms_fdc_bulk` separately for ops visibility.
 */

declare(strict_types=1);

namespace AviationWX\Notam\Airspace\Adapter;

require_once __DIR__ . '/NotamSourceAdapter.php';
require_once __DIR__ . '/NmsAixmAdapter.php';
require_once __DIR__ . '/../../fetcher.php';
require_once __DIR__ . '/../../../constants.php';

/**
 * FAA NMS FDC airspace bulk → AirspaceRecord adapter.
 */
final class NmsFdcAirspaceAdapter implements NotamSourceAdapter
{
    public const SOURCE_TYPE = 'nms_fdc_bulk';

    public static function getSourceType(): string
    {
        return self::SOURCE_TYPE;
    }

    public static function getTypicalUpdateFrequency(): int
    {
        return defined('NMS_FDC_AIRSPACE_REFRESH_INTERVAL_SECONDS')
            ? (int) NMS_FDC_AIRSPACE_REFRESH_INTERVAL_SECONDS
            : 1800;
    }

    public static function getMaxAcceptableAge(): int
    {
        return self::getTypicalUpdateFrequency();
    }

    /**
     * @param array<string, mixed> $config Optional url override
     */
    public static function buildUrl(array $config = []): ?string
    {
        $url = trim((string) ($config['url'] ?? ''));
        if ($url !== '') {
            return $url;
        }

        $baseUrl = function_exists('getNotamApiBaseUrl') ? getNotamApiBaseUrl() : '';
        if ($baseUrl === '') {
            return null;
        }

        return rtrim($baseUrl, '/') . '/nmsapi/v1/notams?' . http_build_query(
            notamBuildFdcAirspaceQueryParams()
        );
    }

    /**
     * Parse NMS JSON envelope (or JSON array of AIXM strings) into AirspaceRecords.
     *
     * @param array<string, mixed> $config Optional timezone
     * @return list<array<string, mixed>>|null
     */
    public static function parseResponse(string $response, array $config = []): ?array
    {
        $response = trim($response);
        if ($response === '') {
            return null;
        }

        $data = notamDecodeNmsJsonResponse($response);
        $aixmRows = notamExtractAixmRowsFromNmsResponse($data);
        if ($aixmRows === null) {
            // Allow raw JSON array of AIXM strings for fixtures.
            try {
                $decoded = json_decode($response, true, 512, JSON_THROW_ON_ERROR);
            } catch (\JsonException $e) {
                return null;
            }
            if (!is_array($decoded)) {
                return null;
            }
            $aixmRows = [];
            foreach ($decoded as $item) {
                if (is_string($item) && trim($item) !== '') {
                    $aixmRows[] = $item;
                }
            }
        }

        $parsed = parseNotamXmlArray($aixmRows);
        $parsed = deduplicateNotams($parsed);

        $records = NmsAixmAdapter::recordsFromParsedNotams($parsed, array_merge([
            'airport_id' => 'fdc_bulk',
            'timezone' => 'UTC',
        ], $config));

        // Tag ingest path without changing NMS field_sources / merge identity.
        foreach ($records as &$record) {
            $record['ingest_path'] = self::SOURCE_TYPE;
            $sources = $record['record_sources'] ?? [NOTAM_AIRSPACE_SOURCE_NMS];
            if (!is_array($sources)) {
                $sources = [NOTAM_AIRSPACE_SOURCE_NMS];
            }
            if (!in_array(self::SOURCE_TYPE, $sources, true)) {
                $sources[] = self::SOURCE_TYPE;
            }
            $record['record_sources'] = $sources;
        }
        unset($record);

        return $records;
    }

    /**
     * Live fetch via shared NMS rate limiter + parse.
     *
     * @param array<string, mixed> $config
     * @return array{ok: bool, records: list<array<string, mixed>>, error: string, http_code: int}
     */
    public static function fetchAndParse(array $config = []): array
    {
        if (isset($GLOBALS['nmsFdcAirspaceHttpHandler']) && is_callable($GLOBALS['nmsFdcAirspaceHttpHandler'])) {
            $handled = ($GLOBALS['nmsFdcAirspaceHttpHandler'])();
            if (is_array($handled)) {
                if (!($handled['ok'] ?? false)) {
                    return [
                        'ok' => false,
                        'records' => [],
                        'error' => (string) ($handled['error'] ?? 'FDC bulk fetch failed'),
                        'http_code' => (int) ($handled['http_code'] ?? 0),
                    ];
                }
                $records = self::parseResponse((string) ($handled['body'] ?? ''), $config);
                if ($records === null) {
                    return [
                        'ok' => false,
                        'records' => [],
                        'error' => 'FDC bulk parse failed',
                        'http_code' => (int) ($handled['http_code'] ?? 200),
                    ];
                }

                return [
                    'ok' => true,
                    'records' => $records,
                    'error' => '',
                    'http_code' => (int) ($handled['http_code'] ?? 200),
                ];
            }
        }

        $lastRequestTime = 0.0;
        $succeeded = null;
        $aixmRows = queryNotamsFdcAirspace($lastRequestTime, $succeeded);
        if ($succeeded !== true) {
            return [
                'ok' => false,
                'records' => [],
                'error' => 'FDC airspace NMS query failed',
                'http_code' => 0,
            ];
        }

        $parsed = parseNotamXmlArray($aixmRows);
        $parsed = deduplicateNotams($parsed);
        $records = NmsAixmAdapter::recordsFromParsedNotams($parsed, array_merge([
            'airport_id' => 'fdc_bulk',
            'timezone' => 'UTC',
        ], $config));

        foreach ($records as &$record) {
            $record['ingest_path'] = self::SOURCE_TYPE;
            $sources = $record['record_sources'] ?? [NOTAM_AIRSPACE_SOURCE_NMS];
            if (!is_array($sources)) {
                $sources = [NOTAM_AIRSPACE_SOURCE_NMS];
            }
            if (!in_array(self::SOURCE_TYPE, $sources, true)) {
                $sources[] = self::SOURCE_TYPE;
            }
            $record['record_sources'] = $sources;
        }
        unset($record);

        return [
            'ok' => true,
            'records' => $records,
            'error' => '',
            'http_code' => 200,
        ];
    }
}
