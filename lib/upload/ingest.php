<?php
/**
 * WiFiLogger payload validation and normalization for ingest.
 *
 * The device sends either a `type: rtd` current-conditions object or a
 * `type: registration` first-contact object. Both are keyed by wid. Identity is
 * fail-closed: the path token binds to an airport, wid and mac in config must match
 * the payload wid and the X-WiFiLogger-Sta-MAC header before anything is stored.
 */

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/../bridge/store.php';

if (!defined('UPLOAD_WEATHER_FUTURE_SKEW_SECONDS')) {
    define('UPLOAD_WEATHER_FUTURE_SKEW_SECONDS', 60);
}

if (!defined('UPLOAD_INGEST_ERROR_INVALID_REQUEST')) {
    define('UPLOAD_INGEST_ERROR_INVALID_REQUEST', 'INVALID_REQUEST');
}
if (!defined('UPLOAD_INGEST_ERROR_WID_MISMATCH')) {
    define('UPLOAD_INGEST_ERROR_WID_MISMATCH', 'WID_MISMATCH');
}
if (!defined('UPLOAD_INGEST_ERROR_MAC_MISMATCH')) {
    define('UPLOAD_INGEST_ERROR_MAC_MISMATCH', 'MAC_MISMATCH');
}
if (!defined('UPLOAD_INGEST_ERROR_MISSING_STATION_TIME')) {
    define('UPLOAD_INGEST_ERROR_MISSING_STATION_TIME', 'MISSING_STATION_TIME');
}
if (!defined('UPLOAD_INGEST_ERROR_FUTURE_STATION_TIME')) {
    define('UPLOAD_INGEST_ERROR_FUTURE_STATION_TIME', 'FUTURE_STATION_TIME');
}

/**
 * Read the station MAC header from the current request, if present.
 *
 * @return string|null Raw header value
 */
function getWifiLoggerMacHeader(): ?string
{
    $mac = $_SERVER['HTTP_X_WIFILOGGER_STA_MAC'] ?? null;
    return is_string($mac) && trim($mac) !== '' ? trim($mac) : null;
}

/**
 * Build the vendor reply envelope.
 *
 * @param int $error Non-zero indicates failure
 * @param string $status Vendor status token (rtd_ok, reg_ok, error)
 * @param string|null $content Optional detail
 * @param int $results Number of records accepted
 * @return array
 */
function uploadEnvelope(int $error, string $status, ?string $content, int $results): array
{
    return [
        'error' => $error,
        'status' => $status,
        'content' => $content,
        'results' => $results,
    ];
}

/**
 * Prepare one WiFiLogger POST into a store action, or a reject.
 *
 * @param array $body Decoded JSON body
 * @param array $binding Resolved upload token binding (airport_id, wid, mac)
 * @param string|null $macHeader Normalized or raw station MAC header, or null
 * @param int|null $now Unix time for future-skew checks (tests)
 * @return array{
 *   ok: bool,
 *   kind?: 'rtd'|'registration',
 *   record?: array,
 *   registration?: array,
 *   code?: string,
 *   error?: string
 * }
 */
function uploadPrepareWifiLoggerIngest(array $body, array $binding, ?string $macHeader, ?int $now = null): array
{
    $now = $now ?? time();

    $type = $body['type'] ?? null;
    if ($type !== 'rtd' && $type !== 'registration') {
        return [
            'ok' => false,
            'code' => UPLOAD_INGEST_ERROR_INVALID_REQUEST,
            'error' => 'type must be rtd or registration',
        ];
    }

    if (!isset($body['wid']) || !is_string($body['wid']) || $body['wid'] === '') {
        return [
            'ok' => false,
            'code' => UPLOAD_INGEST_ERROR_INVALID_REQUEST,
            'error' => 'wid is required',
        ];
    }
    if ($body['wid'] !== ($binding['wid'] ?? null)) {
        return [
            'ok' => false,
            'code' => UPLOAD_INGEST_ERROR_WID_MISMATCH,
            'error' => 'wid does not match the upload token binding',
        ];
    }

    $mac = $macHeader !== null ? normalizeWifiLoggerMac($macHeader) : null;
    $expectedMac = $binding['mac'] ?? '';
    if ($mac === null || $mac !== $expectedMac) {
        return [
            'ok' => false,
            'code' => UPLOAD_INGEST_ERROR_MAC_MISMATCH,
            'error' => 'station MAC does not match the upload token binding',
        ];
    }

    if ($type === 'rtd') {
        $tutc = $body['tutc'] ?? null;
        if (!is_numeric($tutc) || (int) $tutc <= 0) {
            return [
                'ok' => false,
                'code' => UPLOAD_INGEST_ERROR_MISSING_STATION_TIME,
                'error' => 'tutc must be a positive unix timestamp',
            ];
        }
        $obsTime = (int) $tutc;
        // Future station time would read as fresh (negative age) and bypass staleness.
        if ($obsTime > $now + UPLOAD_WEATHER_FUTURE_SKEW_SECONDS) {
            return [
                'ok' => false,
                'code' => UPLOAD_INGEST_ERROR_FUTURE_STATION_TIME,
                'error' => 'tutc is too far in the future',
            ];
        }

        $record = [
            'observed_at' => gmdate('c', $obsTime),
            'observed_unix' => $obsTime,
            'source_id' => $binding['wid'],
            'provider' => 'wifilogger',
            'provider_meta' => [
                'raw' => bridgeScrubValue($body),
                'mac' => $mac,
            ],
        ];
        return ['ok' => true, 'kind' => 'rtd', 'record' => $record];
    }

    $registration = [
        'type' => 'registration',
        'wid' => $binding['wid'],
        'mac' => $mac,
        'received_at' => gmdate('c', $now),
        'payload' => bridgeScrubValue($body),
    ];
    return ['ok' => true, 'kind' => 'registration', 'registration' => $registration];
}