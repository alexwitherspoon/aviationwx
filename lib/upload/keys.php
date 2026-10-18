<?php
/**
 * WiFiLogger upload token generation and shape validation.
 *
 * Upload tokens are the URL-path credential for the one-way WiFiLogger push
 * namespace (awxu_ prefix), a sibling to bridge keys (awxb_). Tokens are plaintext
 * in airports.json (same risk class as bridge keys and push camera passwords).
 * Emit only via generateUploadApiKey(); never invent sample tokens that look real.
 */

if (!defined('UPLOAD_API_KEY_PREFIX')) {
    define('UPLOAD_API_KEY_PREFIX', 'awxu_');
}

/** Characters allowed in the secret portion after the prefix. */
if (!defined('UPLOAD_API_KEY_ALPHABET')) {
    define('UPLOAD_API_KEY_ALPHABET', 'ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789');
}

/** Length of the secret portion (after awxu_). 48 chars matches bridge keys. */
if (!defined('UPLOAD_API_KEY_SECRET_LENGTH')) {
    define('UPLOAD_API_KEY_SECRET_LENGTH', 48);
}

/**
 * Return true when $apiKey matches the upload token shape (prefix + alphabet + length).
 *
 * @param string $apiKey Candidate token
 * @return bool
 */
function isValidUploadApiKeyShape(string $apiKey): bool
{
    $prefix = UPLOAD_API_KEY_PREFIX;
    $len = UPLOAD_API_KEY_SECRET_LENGTH;
    $pattern = '/^' . preg_quote($prefix, '/') . '[A-Za-z0-9]{' . $len . '}$/';
    return (bool) preg_match($pattern, $apiKey);
}

/**
 * Generate a cryptographically random upload token (awxu_ + secret).
 *
 * Uses rejection sampling to avoid modulo bias against UPLOAD_API_KEY_ALPHABET.
 *
 * @return string Full token including prefix
 */
function generateUploadApiKey(): string
{
    $alphabet = UPLOAD_API_KEY_ALPHABET;
    $alphabetLen = strlen($alphabet);
    $secretLen = UPLOAD_API_KEY_SECRET_LENGTH;
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

    return UPLOAD_API_KEY_PREFIX . $secret;
}