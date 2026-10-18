<?php
/**
 * WiFiLogger upload config helpers and schema validation (cache-backed weather rows).
 *
 * A wifilogger weather_sources row carries its own upload_token (awxu_ path credential),
 * a pinned wid (Davis station id, the source key), and a pinned mac (second factor).
 * Unlike bridges[], the token lives on the source row itself, indexed here.
 */

require_once __DIR__ . '/keys.php';

/**
 * weather_sources types that read upload cache (WiFiLogger direct push, no upstream HTTP).
 *
 * @return list<string>
 */
function uploadCacheBackedWeatherSourceTypes(): array
{
    return ['wifilogger'];
}

/**
 * @param string|null $type weather_sources type
 * @return bool
 */
function isUploadCacheBackedWeatherSourceType(?string $type): bool
{
    return is_string($type) && in_array($type, uploadCacheBackedWeatherSourceTypes(), true);
}

/**
 * Whether a wid is a valid Davis station id / cache path segment.
 *
 * wid is the source key and becomes a cache path segment, so it must be
 * filesystem-safe (alphanumeric, hyphens, underscores) and bounded.
 *
 * @param string $wid Candidate wid
 * @return bool
 */
function isValidWifiLoggerWid(string $wid): bool
{
    if ($wid === '' || strlen($wid) > 64) {
        return false;
    }
    return (bool) preg_match('/^[a-zA-Z0-9]([a-zA-Z0-9_-]*[a-zA-Z0-9])?$/', $wid);
}

/**
 * Normalize a station MAC to uppercase colon form, or null when malformed.
 *
 * Accepts 6 octets colon- or dash-separated, or 12 bare hex digits.
 *
 * @param string $mac Raw MAC string
 * @return string|null Normalized AA:BB:CC:DD:EE:FF form
 */
function normalizeWifiLoggerMac(string $mac): ?string
{
    $m = strtoupper(trim($mac));
    if (preg_match('/^[0-9A-F]{12}$/', $m)) {
        return implode(':', str_split($m, 2));
    }
    if (preg_match('/^[0-9A-F]{2}([:-][0-9A-F]{2}){5}$/', $m)) {
        return str_replace('-', ':', $m);
    }
    return null;
}

/**
 * Collect all wifilogger upload tokens across airports: upload_token => binding metadata.
 *
 * @param array|null $config Root config (defaults to loadConfig())
 * @return array<string, array{airport_id: string, source_id: string, wid: string, mac: string, source: array}>
 */
function indexUploadApiKeys(?array $config = null): array
{
    if ($config === null) {
        require_once __DIR__ . '/../config.php';
        $config = loadConfig();
    }
    if ($config === null || !isset($config['airports']) || !is_array($config['airports'])) {
        return [];
    }

    $index = [];
    foreach ($config['airports'] as $airportId => $airport) {
        if (!is_array($airport) || !isset($airport['weather_sources']) || !is_array($airport['weather_sources'])) {
            continue;
        }
        foreach ($airport['weather_sources'] as $ws) {
            if (!is_array($ws) || !isUploadCacheBackedWeatherSourceType($ws['type'] ?? null)) {
                continue;
            }
            if (!isset($ws['upload_token']) || !is_string($ws['upload_token'])) {
                continue;
            }
            $wid = isset($ws['wid']) && is_string($ws['wid']) ? $ws['wid'] : '';
            $mac = isset($ws['mac']) && is_string($ws['mac']) ? (normalizeWifiLoggerMac($ws['mac']) ?? '') : '';
            $index[$ws['upload_token']] = [
                'airport_id' => (string) $airportId,
                'source_id' => $wid,
                'wid' => $wid,
                'mac' => $mac,
                'source' => $ws,
            ];
        }
    }

    return $index;
}

/**
 * Resolve an upload token to its config binding, or null.
 *
 * @param string $token Raw token from the request path
 * @param array|null $config Optional root config (tests)
 * @return array{airport_id: string, source_id: string, wid: string, mac: string, source: array}|null
 */
function resolveUploadApiKey(string $token, ?array $config = null): ?array
{
    if ($token === '' || !isValidUploadApiKeyShape($token)) {
        return null;
    }
    $index = indexUploadApiKeys($config);
    return $index[$token] ?? null;
}

/**
 * Validate wifilogger weather_sources rows across the config.
 *
 * @param array $config Root airports.json object
 * @return array{errors: list<string>, warnings: list<string>}
 */
function validateUploadConfig(array $config): array
{
    $errors = [];
    $warnings = [];

    if (!isset($config['airports']) || !is_array($config['airports'])) {
        return ['errors' => $errors, 'warnings' => $warnings];
    }

    $seenTokens = [];

    foreach ($config['airports'] as $airportCode => $airport) {
        if (!is_array($airport) || !isset($airport['weather_sources']) || !is_array($airport['weather_sources'])) {
            continue;
        }

        foreach ($airport['weather_sources'] as $idx => $ws) {
            if (!is_array($ws) || !isUploadCacheBackedWeatherSourceType($ws['type'] ?? null)) {
                continue;
            }

            $type = (string) $ws['type'];
            $label = "weather_sources[{$idx}]";

            foreach (['bridge_id', 'bridge_source_id', 'txid', 'api_key', 'api_secret'] as $forbidden) {
                if (array_key_exists($forbidden, $ws)) {
                    $errors[] = "Airport '{$airportCode}' {$label} ({$type}) must not set '{$forbidden}'; "
                        . 'WiFiLogger rows use upload_token + wid + mac.';
                }
            }

            if (array_key_exists('wind_reference', $ws)) {
                $errors[] = "Airport '{$airportCode}' {$label} ({$type}) must not set wind_reference; "
                    . 'install the vane to true north (core does not convert magnetic wind)';
            }

            if (!isset($ws['upload_token']) || !is_string($ws['upload_token']) || $ws['upload_token'] === '') {
                $errors[] = "Airport '{$airportCode}' {$label} ({$type}) missing required string 'upload_token'";
            } elseif (!isValidUploadApiKeyShape($ws['upload_token'])) {
                $errors[] = "Airport '{$airportCode}' {$label} ({$type}) upload_token must match shape "
                    . UPLOAD_API_KEY_PREFIX . '+ ' . UPLOAD_API_KEY_SECRET_LENGTH
                    . ' alphanumeric chars (use scripts/generate-upload-api-token.php)';
            } else {
                if (isset($seenTokens[$ws['upload_token']])) {
                    $prev = $seenTokens[$ws['upload_token']];
                    $errors[] = "Airport '{$airportCode}' {$label} ({$type}) upload_token is duplicated "
                        . "(also used by airport '{$prev['airport']}' source '{$prev['source']}')";
                } else {
                    $seenTokens[$ws['upload_token']] = [
                        'airport' => (string) $airportCode,
                        'source' => (string) ($ws['wid'] ?? $idx),
                    ];
                }
            }

            if (!isset($ws['wid']) || !is_string($ws['wid']) || $ws['wid'] === '') {
                $errors[] = "Airport '{$airportCode}' {$label} ({$type}) missing required string 'wid'";
            } elseif (!isValidWifiLoggerWid($ws['wid'])) {
                $errors[] = "Airport '{$airportCode}' {$label} ({$type}) invalid 'wid' "
                    . '(alphanumeric, hyphens/underscores, 1-64 chars)';
            }

            if (!isset($ws['mac']) || !is_string($ws['mac']) || $ws['mac'] === '') {
                $errors[] = "Airport '{$airportCode}' {$label} ({$type}) missing required string 'mac'";
            } elseif (normalizeWifiLoggerMac($ws['mac']) === null) {
                $errors[] = "Airport '{$airportCode}' {$label} ({$type}) invalid 'mac' "
                    . '(must be 6 hex octets, e.g. aa:bb:cc:dd:ee:ff)';
            }

            if (isset($ws['station_id']) && (!is_string($ws['station_id']) || $ws['station_id'] === '')) {
                $errors[] = "Airport '{$airportCode}' {$label} ({$type}) station_id must be a non-empty string when set";
            }
        }
    }

    return ['errors' => $errors, 'warnings' => $warnings];
}