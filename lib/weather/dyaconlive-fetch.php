<?php
/**
 * DyaconLive HTTP fetch (token auth, 401 refresh-and-retry).
 */

require_once __DIR__ . '/../constants.php';
require_once __DIR__ . '/../circuit-breaker.php';
require_once __DIR__ . '/../logger.php';
require_once __DIR__ . '/../test-mocks.php';
require_once __DIR__ . '/adapter/dyaconlive-v1.php';
require_once __DIR__ . '/dyaconlive-auth.php';
require_once __DIR__ . '/dyaconlive-state.php';

/**
 * Fetch DyaconLive /data response body for a configured source.
 *
 * @param array<string, mixed> $source weather_sources entry
 * @param array<string, mixed> $airport Airport config
 * @return array{body: string|null, http_code: int|null, response_headers: array<string, string>}
 */
function dyaconliveFetchDataResponse(array $source, array $airport): array
{
    $url = DyaconLiveAdapter::buildUrl($source, dyaconliveResolveTimezone($source, $airport));
    if ($url === null) {
        return ['body' => null, 'http_code' => null, 'response_headers' => []];
    }

    if (function_exists('isTestMode') && isTestMode() && function_exists('getMockHttpResponse')
        && !isset($GLOBALS['dyaconliveTestHttpGetCallback'])
    ) {
        $mockBody = getMockHttpResponse($url);
        if (is_string($mockBody) && $mockBody !== '') {
            return ['body' => $mockBody, 'http_code' => 200, 'response_headers' => []];
        }
    }

    $username = isset($source['username']) && is_string($source['username']) ? trim($source['username']) : '';
    $password = isset($source['password']) && is_string($source['password']) ? $source['password'] : '';
    if ($username === '' || $password === '') {
        return ['body' => null, 'http_code' => null, 'response_headers' => []];
    }

    for ($attempt = 0; $attempt < 2; $attempt++) {
        $token = dyaconliveGetBearerToken($username, $password);
        if ($token === null) {
            return ['body' => null, 'http_code' => null, 'response_headers' => []];
        }

        $result = dyaconliveHttpGet($url, $token);
        $httpCode = $result['http_code'];
        if ($httpCode !== 401 || $attempt === 1) {
            return $result;
        }

        aviationwx_log('info', 'dyaconlive data 401: refreshing token and retrying', [
            'station_id' => $source['station_id'] ?? null,
        ], 'app');
        dyaconliveInvalidateBearerTokenCache($username);
    }

    return ['body' => null, 'http_code' => null, 'response_headers' => []];
}

/**
 * @return array{body: string|false|null, http_code: int|null, response_headers: array<string, string>}
 */
function dyaconliveHttpGet(string $url, string $token): array
{
    if (isset($GLOBALS['dyaconliveTestHttpGetCallback'])
        && is_callable($GLOBALS['dyaconliveTestHttpGetCallback'])
    ) {
        return $GLOBALS['dyaconliveTestHttpGetCallback']($url, $token);
    }

    $responseHeaders = [];
    $headerCollector = function ($ch, string $line) use (&$responseHeaders): int {
        return circuitBreakerCollectCurlHeaderLine($responseHeaders, $line);
    };

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => defined('CURL_TIMEOUT') ? CURL_TIMEOUT : 30,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_USERAGENT => 'AviationWX/2.0',
        CURLOPT_FAILONERROR => false,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_MAXREDIRS => 3,
        CURLOPT_HTTPHEADER => [
            'Accept: application/json',
            'Authorization: Bearer ' . $token,
        ],
        CURLOPT_HEADERFUNCTION => $headerCollector,
    ]);

    $body = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);

    return [
        'body' => is_string($body) ? $body : null,
        'http_code' => is_int($httpCode) ? $httpCode : null,
        'response_headers' => $responseHeaders,
    ];
}
