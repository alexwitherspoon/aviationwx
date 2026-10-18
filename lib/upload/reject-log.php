<?php
/**
 * Per-unit rejected-upload log for the WiFiLogger upload namespace.
 *
 * One entry per unique unit (keyed by normalized MAC, then wid, then token hash,
 * then IP), holding only that unit's latest rejected attempt. This self-throttles:
 * a noisy unit keeps overwriting its own slot and cannot crowd other units out of
 * the index. Retention is bounded by a unit cap plus per-entry payload truncation.
 *
 * The MAC header and payload wid are client-supplied and forgeable. This log is
 * diagnostic only; it never changes the auth boundary (pinned wid + MAC in config).
 */

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/../bridge/store.php';
require_once __DIR__ . '/../public-api/rate-limit.php';
require_once __DIR__ . '/../cache-paths.php';

if (!defined('UPLOAD_REJECT_UNITS_MAX')) {
    define('UPLOAD_REJECT_UNITS_MAX', 500);
}

if (!defined('UPLOAD_REJECT_PAYLOAD_MAX_BYTES')) {
    define('UPLOAD_REJECT_PAYLOAD_MAX_BYTES', 2048);
}

if (!defined('UPLOAD_REJECT_WRITES_MINUTE_MAX')) {
    define('UPLOAD_REJECT_WRITES_MINUTE_MAX', 120);
}

/**
 * Cap on distinct rejected units (overrideable in tests via $GLOBALS['uploadRejectUnitsMax']).
 *
 * @return int
 */
function uploadRejectUnitsMax(): int
{
    if (isset($GLOBALS['uploadRejectUnitsMax']) && is_int($GLOBALS['uploadRejectUnitsMax']) && $GLOBALS['uploadRejectUnitsMax'] > 0) {
        return $GLOBALS['uploadRejectUnitsMax'];
    }
    return UPLOAD_REJECT_UNITS_MAX;
}

/**
 * Per-entry payload byte ceiling (overrideable in tests via $GLOBALS['uploadRejectPayloadMaxBytes']).
 *
 * @return int
 */
function uploadRejectPayloadMaxBytes(): int
{
    if (isset($GLOBALS['uploadRejectPayloadMaxBytes']) && is_int($GLOBALS['uploadRejectPayloadMaxBytes']) && $GLOBALS['uploadRejectPayloadMaxBytes'] > 0) {
        return $GLOBALS['uploadRejectPayloadMaxBytes'];
    }
    return UPLOAD_REJECT_PAYLOAD_MAX_BYTES;
}

/**
 * Per-IP reject-write ceiling (overrideable in tests via $GLOBALS['uploadRejectWritesMinuteMax']).
 *
 * @return int
 */
function uploadRejectWritesMinuteMax(): int
{
    if (isset($GLOBALS['uploadRejectWritesMinuteMax']) && is_int($GLOBALS['uploadRejectWritesMinuteMax']) && $GLOBALS['uploadRejectWritesMinuteMax'] > 0) {
        return $GLOBALS['uploadRejectWritesMinuteMax'];
    }
    return UPLOAD_REJECT_WRITES_MINUTE_MAX;
}

/**
 * Whether a reject write from this IP is allowed this minute.
 *
 * Keeps unknown-token spammers from churning the reject file; legitimate
 * misconfigured units post far below the ceiling. Consumes one unit of budget
 * per check.
 *
 * @param string $ip Client IP
 * @return bool
 */
function uploadRejectWriteAllowed(string $ip): bool
{
    $result = checkAndIncrementWindow(
        'upload_reject:ip:' . $ip,
        'minute',
        uploadRejectWritesMinuteMax(),
        60
    );
    return $result['allowed'];
}

/**
 * Short token digest for correlation without storing the token itself.
 *
 * @param string $token Raw token
 * @return string First 16 hex chars of sha256
 */
function uploadTokenHash(string $token): string
{
    return substr(hash('sha256', $token), 0, 16);
}

/**
 * Resolve the diagnostic unit key for a rejected attempt.
 *
 * @param string|null $macHeader Raw station MAC header
 * @param string|null $wid Payload wid
 * @param string|null $token Path token
 * @param string|null $ip Client IP
 * @return string Stable key for this unit
 */
function uploadRejectUnitKey(?string $macHeader, ?string $wid, ?string $token, ?string $ip): string
{
    $mac = $macHeader !== null && trim($macHeader) !== '' ? normalizeWifiLoggerMac($macHeader) : null;
    if ($mac !== null) {
        return $mac;
    }
    if ($wid !== null && $wid !== '') {
        return 'wid:' . $wid;
    }
    if ($token !== null && $token !== '') {
        return 'token:' . uploadTokenHash($token);
    }
    if ($ip !== null && $ip !== '') {
        return 'ip:' . $ip;
    }
    return '_unidentified';
}

/**
 * Scrub and bound a payload for storage.
 *
 * @param array|null $payload Decoded payload, or null when none was read
 * @return mixed Scrubbed array, truncation object, or null
 */
function uploadTruncatePayload(?array $payload): mixed
{
    if ($payload === null) {
        return null;
    }
    $scrubbed = bridgeScrubValue($payload);
    $json = json_encode($scrubbed, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    if ($json === false || strlen($json) <= uploadRejectPayloadMaxBytes()) {
        return $scrubbed;
    }
    return [
        '_truncated' => true,
        '_preview' => substr($json, 0, uploadRejectPayloadMaxBytes()),
    ];
}

/**
 * Record one rejected upload attempt against its unit's single slot.
 *
 * @param string $reason Stable reject code (e.g. MAC_MISMATCH)
 * @param string|null $wid Payload wid (observed, may differ from config)
 * @param string|null $macHeader Raw station MAC header
 * @param string|null $token Path token (null when missing)
 * @param string|null $airport Resolved airport id, when the token resolved
 * @param string|null $expectedWid Config wid, when the token resolved
 * @param string|null $ip Client IP
 * @param array|null $payload Decoded payload, or null
 * @param int|null $receivedAt Unix receive time (tests)
 * @return bool
 */
function uploadRecordReject(
    string $reason,
    ?string $wid,
    ?string $macHeader,
    ?string $token,
    ?string $airport,
    ?string $expectedWid,
    ?string $ip,
    ?array $payload,
    ?int $receivedAt = null
): bool {
    $receivedAt = $receivedAt ?? time();
    $nowIso = gmdate('c', $receivedAt);
    $mac = $macHeader !== null && trim($macHeader) !== '' ? normalizeWifiLoggerMac($macHeader) : null;
    $unitKey = uploadRejectUnitKey($macHeader, $wid, $token, $ip);
    $tokenHash = $token !== null && $token !== '' ? uploadTokenHash($token) : null;
    $storedPayload = uploadTruncatePayload($payload);

    if ($ip !== null && $ip !== '' && !uploadRejectWriteAllowed($ip)) {
        return false;
    }

    $path = getUploadRejectUnitsPath();
    if ($path === '') {
        return false;
    }

    return bridgeUpdateJsonFileLocked($path, static function (?array $data) use (
        $unitKey,
        $nowIso,
        $receivedAt,
        $reason,
        $wid,
        $mac,
        $tokenHash,
        $airport,
        $expectedWid,
        $ip,
        $storedPayload
    ): array {
        $units = isset($data['units']) && is_array($data['units']) ? $data['units'] : [];

        if (isset($units[$unitKey]) && is_array($units[$unitKey])) {
            $entry = $units[$unitKey];
            $entry['last_seen_at'] = $nowIso;
            $entry['last_seen_unix'] = $receivedAt;
            $entry['attempts'] = (int) ($entry['attempts'] ?? 0) + 1;
            $entry['last_reason'] = $reason;
        } else {
            $max = uploadRejectUnitsMax();
            if (count($units) >= $max) {
                $evict = null;
                $evictSeen = null;
                foreach ($units as $k => $u) {
                    $seen = is_array($u) && isset($u['last_seen_unix']) && is_numeric($u['last_seen_unix'])
                        ? (int) $u['last_seen_unix']
                        : 0;
                    if ($evictSeen === null || $seen < $evictSeen) {
                        $evictSeen = $seen;
                        $evict = $k;
                    }
                }
                if ($evict !== null) {
                    unset($units[$evict]);
                }
            }
            $entry = [
                'first_seen_at' => $nowIso,
                'last_seen_at' => $nowIso,
                'last_seen_unix' => $receivedAt,
                'attempts' => 1,
                'last_reason' => $reason,
            ];
        }

        if ($mac !== null) {
            $entry['mac'] = $mac;
        }
        if ($wid !== null && $wid !== '') {
            $entry['last_wid'] = $wid;
        }
        if ($tokenHash !== null) {
            $entry['last_token_hash'] = $tokenHash;
        }
        if ($airport !== null && $airport !== '') {
            $entry['last_airport'] = $airport;
        }
        if ($expectedWid !== null && $expectedWid !== '') {
            $entry['expected_wid'] = $expectedWid;
        }
        if ($ip !== null && $ip !== '') {
            $entry['last_ip'] = $ip;
        }
        $entry['last_payload'] = $storedPayload;

        $units[$unitKey] = $entry;

        return ['units' => $units];
    });
}