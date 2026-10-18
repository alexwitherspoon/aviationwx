<?php
/**
 * Public API - WiFiLogger direct upload ingest
 *
 * POST /v1/upload/weather/wifilogger/{token}
 *
 * Accepts `type: rtd` current-conditions and `type: registration` first-contact
 * messages from a WiFiLogger pointed at this URL. Raw payload is stored verbatim
 * under provider_meta.raw. Identity is pinned by wid + MAC in config; the URL is
 * the only credential the device can send.
 */

require_once __DIR__ . '/../../lib/upload/middleware.php';
require_once __DIR__ . '/../../lib/upload/ingest.php';
require_once __DIR__ . '/../../lib/upload/store.php';
require_once __DIR__ . '/../../lib/upload/reject-log.php';
require_once __DIR__ . '/../../lib/logger.php';

/**
 * Handle POST /v1/upload/weather/wifilogger/{token}
 *
 * @param array $params Unused path params (token is resolved in middleware)
 * @param array $context Upload auth context (airport_id, wid, mac)
 * @return void
 */
function handleWifiLoggerUpload(array $params, array $context): void
{
    unset($params);
    $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
    if ($method !== 'POST') {
        sendUploadJsonEnvelope(uploadEnvelope(1, 'error', 'Use POST for WiFiLogger ingest', 0), 405);
        return;
    }

    $parsed = uploadReadJsonBody(131072);
    if (!$parsed['ok']) {
        sendUploadJsonEnvelope(
            uploadEnvelope(1, 'error', $parsed['error'] ?? 'Invalid body', 0),
            400
        );
        return;
    }
    $body = $parsed['body'];

    $macHeader = getWifiLoggerMacHeader();
    $prepared = uploadPrepareWifiLoggerIngest($body, $context, $macHeader);
    if (!$prepared['ok']) {
        uploadRecordReject(
            $prepared['code'] ?? 'UNKNOWN',
            is_string($body['wid'] ?? null) ? $body['wid'] : null,
            $macHeader,
            $context['token'] ?? null,
            $context['airport_id'] ?? null,
            $context['wid'] ?? null,
            $context['ip'] ?? null,
            $body
        );
        aviationwx_log('warning', 'wifilogger ingest reject', [
            'airport_id' => $context['airport_id'],
            'wid' => $context['wid'],
            'code' => $prepared['code'] ?? 'UNKNOWN',
        ], 'upload');
        $httpCode = in_array(
            $prepared['code'] ?? null,
            [UPLOAD_INGEST_ERROR_WID_MISMATCH, UPLOAD_INGEST_ERROR_MAC_MISMATCH],
            true
        ) ? 403 : 400;
        sendUploadJsonEnvelope(
            uploadEnvelope(1, 'error', $prepared['error'] ?? 'Invalid payload', 0),
            $httpCode
        );
        return;
    }

    if ($prepared['kind'] === 'rtd') {
        if (!uploadStoreWeatherObservation($context['airport_id'], $context['wid'], $prepared['record'])) {
            sendUploadJsonEnvelope(uploadEnvelope(1, 'error', 'Failed to persist observation', 0), 503);
            return;
        }
        sendUploadJsonEnvelope(uploadEnvelope(0, 'rtd_ok', null, 1), 200);
        return;
    }

    if (!uploadStoreRegistration($context['airport_id'], $context['wid'], $prepared['registration'])) {
        sendUploadJsonEnvelope(uploadEnvelope(1, 'error', 'Failed to persist registration', 0), 503);
        return;
    }
    sendUploadJsonEnvelope(uploadEnvelope(0, 'reg_ok', null, 1), 200);
}