<?php
/**
 * DyaconLive API bearer token lifecycle (OAuth2 password grant).
 */

require_once __DIR__ . '/../constants.php';
require_once __DIR__ . '/../logger.php';
require_once __DIR__ . '/../test-mocks.php';

/**
 * Obtain bearer token for DyaconLive API (APCu-cached per username).
 *
 * @param string $username DyaconLive login email
 * @param string $password DyaconLive password
 * @return string|null Bearer token or null on failure
 */
function dyaconliveGetBearerToken(string $username, string $password): ?string
{
    if (isset($GLOBALS['dyaconliveTestBearerToken'])
        && is_string($GLOBALS['dyaconliveTestBearerToken'])
        && $GLOBALS['dyaconliveTestBearerToken'] !== ''
    ) {
        return $GLOBALS['dyaconliveTestBearerToken'];
    }

    $username = trim($username);
    if ($username === '' || $password === '') {
        aviationwx_log('error', 'dyaconlive auth: missing credentials', [], 'app');
        return null;
    }

    $url = rtrim(DYACONLIVE_API_BASE_URL, '/') . '/token';
    if (function_exists('isTestMode') && isTestMode() && function_exists('getMockHttpResponse')
        && !isset($GLOBALS['dyaconliveTestBearerToken'])
    ) {
        $mockBody = getMockHttpResponse($url);
        if (is_string($mockBody) && $mockBody !== '') {
            $data = json_decode($mockBody, true);
            if (is_array($data) && !empty($data['access_token']) && is_string($data['access_token'])) {
                return $data['access_token'];
            }
        }
    }

    $cacheKey = dyaconliveBearerTokenCacheKey($username);
    if (function_exists('apcu_fetch')) {
        $cached = @apcu_fetch($cacheKey);
        if ($cached !== false && is_array($cached)) {
            $token = $cached['token'] ?? null;
            $expiresAt = $cached['expires_at'] ?? 0;
            if (is_string($token) && $token !== '' && $expiresAt > (time() + DYACONLIVE_TOKEN_EXPIRY_BUFFER)) {
                return $token;
            }
        }
    }

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => http_build_query([
            'username' => $username,
            'password' => $password,
            'grant_type' => 'password',
        ]),
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 15,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_HTTPHEADER => [
            'Content-Type: application/x-www-form-urlencoded',
            'Accept: application/json',
        ],
    ]);

    $response = curl_exec($ch);
    $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlError = curl_error($ch);

    if ($httpCode !== 200 || !is_string($response)) {
        aviationwx_log('error', 'dyaconlive auth: token request failed', [
            'http_code' => $httpCode,
            'error' => $curlError !== '' ? $curlError : null,
        ], 'app');
        return null;
    }

    $data = json_decode($response, true);
    if (!is_array($data) || empty($data['access_token']) || !is_string($data['access_token'])) {
        aviationwx_log('error', 'dyaconlive auth: invalid token response', [
            'http_code' => $httpCode,
        ], 'app');
        return null;
    }

    $token = $data['access_token'];
    $expiresIn = dyaconliveTokenExpiresInSeconds($token, $data);
    $expiresAt = time() + $expiresIn;

    if (function_exists('apcu_store')) {
        @apcu_store($cacheKey, [
            'token' => $token,
            'expires_at' => $expiresAt,
        ], $expiresIn);
    }

    return $token;
}

/**
 * @param array<string, mixed> $tokenBody Decoded /token JSON
 */
function dyaconliveTokenExpiresInSeconds(string $accessToken, array $tokenBody): int
{
    if (isset($tokenBody['expires_in']) && is_numeric($tokenBody['expires_in'])) {
        return max(60, (int) $tokenBody['expires_in']);
    }

    $parts = explode('.', $accessToken);
    if (count($parts) >= 2) {
        $payloadJson = base64_decode(strtr($parts[1], '-_', '+/'), true);
        if (is_string($payloadJson)) {
            $payload = json_decode($payloadJson, true);
            if (is_array($payload) && isset($payload['exp']) && is_numeric($payload['exp'])) {
                $ttl = (int) $payload['exp'] - time();
                if ($ttl > 60) {
                    return $ttl;
                }
            }
        }
    }

    return DYACONLIVE_TOKEN_DEFAULT_TTL_SECONDS;
}

/**
 * Drop cached bearer token so the next request fetches a new one (e.g. after HTTP 401).
 */
function dyaconliveInvalidateBearerTokenCache(string $username): void
{
    $username = trim($username);
    if ($username === '') {
        return;
    }

    $cacheKey = dyaconliveBearerTokenCacheKey($username);
    if (function_exists('apcu_delete')) {
        @apcu_delete($cacheKey);
    }
}

/**
 * APCu cache key for a DyaconLive username.
 */
function dyaconliveBearerTokenCacheKey(string $username): string
{
    return 'dyaconlive_token_' . md5(trim($username));
}
