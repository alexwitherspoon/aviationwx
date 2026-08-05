<?php
/**
 * Bridge API key generation and shape validation.
 *
 * Keys are plaintext in airports.json (same risk class as push camera passwords).
 * Emit only via generateBridgeApiKey(); never invent sample keys that look real.
 */

if (!defined('BRIDGE_API_KEY_PREFIX')) {
    define('BRIDGE_API_KEY_PREFIX', 'awxb_');
}

/** Characters allowed in the secret portion after the prefix. */
if (!defined('BRIDGE_API_KEY_ALPHABET')) {
    define('BRIDGE_API_KEY_ALPHABET', 'ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789');
}

/** Length of the secret portion (after awxb_). 48 chars ≈ 285 bits before encoding loss. */
if (!defined('BRIDGE_API_KEY_SECRET_LENGTH')) {
    define('BRIDGE_API_KEY_SECRET_LENGTH', 48);
}

/**
 * Return true when $apiKey matches the bridge key shape (prefix + alphabet + length).
 *
 * @param string $apiKey Candidate key
 * @return bool
 */
function isValidBridgeApiKeyShape(string $apiKey): bool
{
    $prefix = BRIDGE_API_KEY_PREFIX;
    $len = BRIDGE_API_KEY_SECRET_LENGTH;
    $pattern = '/^' . preg_quote($prefix, '/') . '[A-Za-z0-9]{' . $len . '}$/';
    return (bool) preg_match($pattern, $apiKey);
}

/**
 * Generate a cryptographically random bridge API key (awxb_ + secret).
 *
 * Uses rejection sampling to avoid modulo bias against BRIDGE_API_KEY_ALPHABET.
 *
 * @return string Full key including prefix
 */
function generateBridgeApiKey(): string
{
    $alphabet = BRIDGE_API_KEY_ALPHABET;
    $alphabetLen = strlen($alphabet);
    $secretLen = BRIDGE_API_KEY_SECRET_LENGTH;
    // Largest multiple of alphabetLen that fits in a byte
    $limit = intdiv(256, $alphabetLen) * $alphabetLen;

    $secret = '';
    while (strlen($secret) < $secretLen) {
        $byte = ord(random_bytes(1));
        if ($byte >= $limit) {
            continue;
        }
        $secret .= $alphabet[$byte % $alphabetLen];
    }

    return BRIDGE_API_KEY_PREFIX . $secret;
}
