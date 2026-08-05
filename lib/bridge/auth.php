<?php
/**
 * Bridge API authentication (X-Api-Key to airport_id + bridge_id).
 *
 * Identity Option B: the key is sole authority. Body bridge_id / source ids may disagree;
 * callers should accept, attribute to the key binding, and log a warning.
 */

require_once __DIR__ . '/keys.php';
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/../logger.php';

/**
 * Extract bridge API key from the current request (header only; no query string).
 *
 * Accepts X-Api-Key / X-API-Key (CGI normalizes to HTTP_X_API_KEY).
 *
 * @return string|null Key or null if missing
 */
function getBridgeApiKeyFromRequest(): ?string
{
    if (!empty($_SERVER['HTTP_X_API_KEY']) && is_string($_SERVER['HTTP_X_API_KEY'])) {
        $key = trim($_SERVER['HTTP_X_API_KEY']);
        return $key !== '' ? $key : null;
    }
    return null;
}

/**
 * Resolve a bridge API key to its config binding.
 *
 * @param string $apiKey Raw key from the request
 * @param array|null $config Optional root config (tests)
 * @return array{airport_id: string, bridge_id: string, label: string|null, bridge: array}|null
 */
function resolveBridgeApiKey(string $apiKey, ?array $config = null): ?array
{
    if ($apiKey === '' || !isValidBridgeApiKeyShape($apiKey)) {
        return null;
    }
    $index = indexBridgeApiKeys($config);
    return $index[$apiKey] ?? null;
}

/**
 * If the request body includes bridge_id that disagrees with the key binding, log a warning.
 * Does not change attribution - key binding always wins.
 *
 * @param array $binding Resolved binding from resolveBridgeApiKey()
 * @param string|null $bodyBridgeId bridge_id from JSON body, if any
 * @return void
 */
function warnIfBridgeBodyIdMismatch(array $binding, ?string $bodyBridgeId): void
{
    if ($bodyBridgeId === null || $bodyBridgeId === '') {
        return;
    }
    if ($bodyBridgeId === ($binding['bridge_id'] ?? null)) {
        return;
    }
    aviationwx_log('warning', 'bridge body bridge_id mismatch; attributing to API key binding', [
        'airport_id' => $binding['airport_id'] ?? null,
        'key_bridge_id' => $binding['bridge_id'] ?? null,
        'body_bridge_id' => $bodyBridgeId,
    ], 'bridge');
}
