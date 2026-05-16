<?php
/**
 * Access control for GET /api/notam-map.php (airports directory map only).
 *
 * Intended for browser use from the airport network map UI. Production rejects
 * cross-site fetches, direct tab opens without trusted Referer, and unmarked
 * scripted clients (no Sec-Fetch-Site). Non-production and test mode stay open
 * for local Docker, PHPUnit, and curl debugging.
 */

require_once __DIR__ . '/../config.php';

/**
 * Hostnames that may serve GET /api/notam-map.php in production (airports map origin only).
 *
 * @param string $httpHost Raw HTTP Host header (may include port)
 * @param string $baseDomain Lowercase base domain from {@see getBaseDomain()}
 * @return bool True when Host is the airports directory site or local dev
 */
function notamMapLayerApiRequestHostIsAllowedForMapLayerJson(string $httpHost, string $baseDomain): bool {
    $h = strtolower(preg_replace('/:\d+$/', '', $httpHost));
    $base = strtolower($baseDomain);
    if ($h === 'airports.' . $base) {
        return true;
    }
    if ($h === $base || $h === 'www.' . $base) {
        return true;
    }
    if ($h === 'localhost' || $h === '127.0.0.1' || $h === '[::1]') {
        return true;
    }

    return false;
}

/**
 * True when Referer identifies the airport map page on an allowed host.
 *
 * @param string $refererRaw Raw HTTP Referer header
 * @param string $baseDomain Lowercase base domain from {@see getBaseDomain()}
 * @return bool True when Referer is a map page URL we trust
 */
function notamMapLayerApiRefererIsTrustedMapPage(string $refererRaw, string $baseDomain): bool {
    if ($refererRaw === '') {
        return false;
    }
    $parsed = parse_url($refererRaw);
    if ($parsed === false || !isset($parsed['scheme'], $parsed['host'])) {
        return false;
    }
    $scheme = strtolower((string)$parsed['scheme']);
    if ($scheme !== 'http' && $scheme !== 'https') {
        return false;
    }
    $host = strtolower(preg_replace('/:\d+$/', '', (string)$parsed['host']));
    $path = isset($parsed['path']) && $parsed['path'] !== '' ? (string)$parsed['path'] : '/';
    $base = strtolower($baseDomain);

    if ($host === 'airports.' . $base) {
        return true;
    }
    if (($host === $base || $host === 'www.' . $base) && str_starts_with($path, '/airports')) {
        return true;
    }
    if (($host === 'localhost' || $host === '127.0.0.1' || $host === '[::1]') && str_starts_with($path, '/airports')) {
        return true;
    }

    return false;
}

/**
 * Whether the current HTTP request may load the internal NOTAM map layer JSON.
 *
 * @return bool True when the request should receive GeoJSON; false for 403
 */
function notamMapLayerApiRequestIsAllowed(): bool {
    if (isTestMode()) {
        return true;
    }
    if (!isProduction()) {
        return true;
    }

    $baseDomain = strtolower(getBaseDomain());
    $requestHost = (string)($_SERVER['HTTP_HOST'] ?? '');
    if (!notamMapLayerApiRequestHostIsAllowedForMapLayerJson($requestHost, $baseDomain)) {
        return false;
    }

    $fetchSite = strtolower(trim((string)($_SERVER['HTTP_SEC_FETCH_SITE'] ?? '')));
    if ($fetchSite === 'same-origin' || $fetchSite === 'same-site') {
        return true;
    }

    $referer = (string)($_SERVER['HTTP_REFERER'] ?? '');

    return notamMapLayerApiRefererIsTrustedMapPage($referer, $baseDomain);
}
