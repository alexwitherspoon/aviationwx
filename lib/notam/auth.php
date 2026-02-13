<?php
/**
 * NOTAM API Authentication Manager
 * 
 * Manages OAuth2 token lifecycle for NMS API
 */

require_once __DIR__ . '/../logger.php';
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../constants.php';

/**
 * Get OAuth2 bearer token for NMS API
 * 
 * Retrieves a cached token if valid, or fetches a new token if expired.
 * Tokens are cached in APCu with a 29-minute TTL (tokens expire in 30 minutes).
 * 
 * @return string|null Bearer token on success, null on failure
 */
function getNotamBearerToken(): ?string {
    $clientId = getNotamApiClientId();
    $clientSecret = getNotamApiClientSecret();
    $baseUrl = getNotamApiBaseUrl();
    
    if (empty($clientId) || empty($clientSecret)) {
        aviationwx_log('error', 'notam auth: missing credentials', [], 'app', true);
        return null;
    }
    
    $authUrl = rtrim($baseUrl, '/') . '/v1/auth/token';
    $cacheKey = 'notam_token_' . md5($clientId . $baseUrl);
    
    // Try to get cached token from APCu
    if (function_exists('apcu_fetch')) {
        $cached = @apcu_fetch($cacheKey);
        if ($cached !== false && is_array($cached)) {
            $token = $cached['token'] ?? null;
            $expiresAt = $cached['expires_at'] ?? 0;
            
            // If token is still valid (with 1-minute buffer), return it
            if ($token && $expiresAt > (time() + NOTAM_TOKEN_EXPIRY_BUFFER)) {
                return $token;
            }
        }
    }
    
    // Fetch new token
    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL => $authUrl,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => 'grant_type=client_credentials',
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 10,
        CURLOPT_CONNECTTIMEOUT => 5,
        CURLOPT_HTTPHEADER => [
            'Content-Type: application/x-www-form-urlencoded',
            'Authorization: Basic ' . base64_encode($clientId . ':' . $clientSecret)
        ],
    ]);
    
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error = curl_error($ch);
    
    if ($httpCode !== 200 || $response === false) {
        aviationwx_log('error', 'notam auth: failed to get token', [
            'http_code' => $httpCode,
            'error' => $error
        ], 'app', true);
        return null;
    }
    
    $data = json_decode($response, true);
    if (!isset($data['access_token'])) {
        aviationwx_log('error', 'notam auth: invalid token response', [
            'response' => substr($response, 0, 200)
        ], 'app', true);
        return null;
    }
    
    $token = $data['access_token'];
    $expiresIn = isset($data['expires_in']) ? (int)$data['expires_in'] : 1799; // Default 30 minutes
    $expiresAt = time() + $expiresIn;
    
    // Cache token in APCu (with 1-minute buffer before expiry)
    if (function_exists('apcu_store')) {
        @apcu_store($cacheKey, [
            'token' => $token,
            'expires_at' => $expiresAt
        ], $expiresIn - NOTAM_TOKEN_EXPIRY_BUFFER);
    }
    
    aviationwx_log('info', 'notam auth: token obtained', [
        'expires_in' => $expiresIn
    ], 'app');
    
    return $token;
}
