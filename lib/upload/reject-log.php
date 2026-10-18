<?php
/**
 * Per-unit rejected-upload log for the WiFiLogger upload namespace.
 *
 * One entry per unique unit (keyed by normalized MAC, then wid, then token hash,
 * then IP), holding only that unit's latest rejected attempt. This self-throttles:
 * a noisy unit keeps overwriting its own slot and cannot crowd other units out of
 * the index. Retention is bounded by a per-IP write throttle, a unit cap, and
 * per-entry payload truncation.
 *
 * The MAC header and payload wid are client-supplied and forgeable. This log is
 * diagnostic only; it never changes the auth boundary (pinned wid + MAC in config).
 *
 * It stores the full upload token so a mis-addressed or typo'd upload can be
 * diagnosed. That makes it a credential store, so it must never be web-accessible
 * (see the cache/ deny rules in .htaccess and nginx).
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
 * Short token digest for the unit-key fallback.
 *
 * Bounded and opaque so a long or malformed token cannot bloat a key. The full
 * token is stored in the entry's last_token field for diagnosis.
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
    if ($wid !== null && $wid !== '' && isValidWifiLoggerWid($wid)) {
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
    // ASCII-only encode so the byte-based preview substr never splits a multibyte char.
    $json = json_encode($scrubbed, JSON_UNESCAPED_SLASHES);
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
 * @param string|null $token Path token (null when missing); stored in full as last_token
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
    // Bound the diagnostic wid: an unknown-token request can carry an oversized wid
    // inside the body limit, and it must never become a key or last_wid intact.
    if ($wid !== null && $wid !== '' && !isValidWifiLoggerWid($wid)) {
        $wid = null;
    }
    $mac = $macHeader !== null && trim($macHeader) !== '' ? normalizeWifiLoggerMac($macHeader) : null;
    $unitKey = uploadRejectUnitKey($macHeader, $wid, $token, $ip);
    $storedPayload = uploadTruncatePayload($payload);

    if ($ip !== null && $ip !== '' && !uploadRejectWriteAllowed($ip)) {
        return false;
    }

    $path = getUploadRejectsPath();
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
        $token,
        $airport,
        $expectedWid,
        $ip,
        $storedPayload
    ): array {
        $units = isset($data['units']) && is_array($data['units']) ? $data['units'] : [];

        $existing = isset($units[$unitKey]) && is_array($units[$unitKey]) ? $units[$unitKey] : null;
        if ($existing === null) {
            $max = uploadRejectUnitsMax();
            if (count($units) >= $max) {
                // Evict the least-recently-seen unit so stale attempts age out before active ones.
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
        }

        // first_seen_at and attempts are cumulative; every other field describes the
        // latest attempt, so absent fields overwrite (and can clear) the prior value.
        $entry = [
            'first_seen_at' => is_array($existing) && isset($existing['first_seen_at']) ? $existing['first_seen_at'] : $nowIso,
            'last_seen_at' => $nowIso,
            'last_seen_unix' => $receivedAt,
            'attempts' => (is_array($existing) ? (int) ($existing['attempts'] ?? 0) : 0) + 1,
            'last_reason' => $reason,
            'mac' => $mac,
            'last_wid' => $wid !== null && $wid !== '' ? $wid : null,
            'last_token' => $token !== null && $token !== '' ? $token : null,
            'last_airport' => $airport !== null && $airport !== '' ? $airport : null,
            'expected_wid' => $expectedWid !== null && $expectedWid !== '' ? $expectedWid : null,
            'last_ip' => $ip !== null && $ip !== '' ? $ip : null,
            'last_payload' => $storedPayload,
        ];

        $units[$unitKey] = $entry;

        return ['units' => $units];
    });
}