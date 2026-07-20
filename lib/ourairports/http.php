<?php

/**
 * HTTP helpers for OurAirports upstream CSV probes and downloads.
 */

require_once __DIR__ . '/meta.php';

const OURAIRPORTS_HTTP_USER_AGENT = 'AviationWX OurAirports Fetcher/1.0';

/**
 * Parse response headers from a curl header line callback buffer.
 *
 * @param array<int, string> $headerLines
 * @return array<string, string> Lowercase header name => value
 */
function ourAirportsParseResponseHeaders(array $headerLines): array
{
    $headers = [];
    foreach ($headerLines as $line) {
        $line = trim($line);
        if ($line === '' || !str_contains($line, ':')) {
            continue;
        }
        [$name, $value] = explode(':', $line, 2);
        $headers[strtolower(trim($name))] = trim($value);
    }

    return $headers;
}

/**
 * Issue HTTP HEAD for a URL.
 *
 * @return array{
 *   ok: bool,
 *   http_code: int,
 *   etag: ?string,
 *   last_modified: ?string,
 *   error: ?string
 * }
 */
function ourAirportsHttpHead(string $url): array
{
    $headerLines = [];
    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL => $url,
        CURLOPT_NOBODY => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 60,
        CURLOPT_CONNECTTIMEOUT => 15,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_USERAGENT => OURAIRPORTS_HTTP_USER_AGENT,
        CURLOPT_HEADERFUNCTION => static function ($curl, string $line) use (&$headerLines): int {
            $headerLines[] = $line;
            return strlen($line);
        },
    ]);

    curl_exec($ch);
    $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlError = curl_error($ch);
    curl_close($ch);

    $headers = ourAirportsParseResponseHeaders($headerLines);
    $ok = $httpCode >= 200 && $httpCode < 300;

    return [
        'ok' => $ok,
        'http_code' => $httpCode,
        'etag' => ourAirportsNormalizeEtag($headers['etag'] ?? null),
        'last_modified' => isset($headers['last-modified']) ? trim($headers['last-modified']) : null,
        'error' => $ok ? null : ($curlError !== '' ? $curlError : 'HTTP ' . $httpCode),
    ];
}

/**
 * Download a URL (unconditional GET).
 *
 * Bulk fetch always uses a full GET when policy requires a download so local bytes
 * cannot drift from upstream after a probe marks a file changed.
 *
 * @return array{
 *   ok: bool,
 *   http_code: int,
 *   body: ?string,
 *   etag: ?string,
 *   last_modified: ?string,
 *   error: ?string
 * }
 */
function ourAirportsHttpGet(string $url): array
{
    $headerLines = [];
    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL => $url,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 120,
        CURLOPT_CONNECTTIMEOUT => 15,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_USERAGENT => OURAIRPORTS_HTTP_USER_AGENT,
        CURLOPT_HEADERFUNCTION => static function ($curl, string $line) use (&$headerLines): int {
            $headerLines[] = $line;
            return strlen($line);
        },
    ]);

    $body = curl_exec($ch);
    $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlError = curl_error($ch);
    curl_close($ch);

    $responseHeaders = ourAirportsParseResponseHeaders($headerLines);
    $ok = $httpCode === 200 && is_string($body) && $body !== '';

    return [
        'ok' => $ok,
        'http_code' => $httpCode,
        'body' => $ok ? $body : null,
        'etag' => ourAirportsNormalizeEtag($responseHeaders['etag'] ?? null),
        'last_modified' => isset($responseHeaders['last-modified']) ? trim($responseHeaders['last-modified']) : null,
        'error' => $ok ? null : ($curlError !== '' ? $curlError : 'HTTP ' . $httpCode),
    ];
}
